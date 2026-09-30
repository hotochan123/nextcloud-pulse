<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Service\ConflictException;
use OCA\Pulse\Service\Limits;
use OCA\Pulse\Service\PollImageService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Question images and the storage they take (security review, task D:
 * M4 image budget, L9 orphaned files).
 *
 * - Every account has an image budget: the stored (re-encoded) files of all
 *   its rooms. An upload or a copy that would exceed it is refused before
 *   anything is written — the upload even before the GD run.
 * - Parallel uploads of one account each checked the budget before any of
 *   them wrote; an upload counts again once its image is referenced and
 *   goes back to the previous image when the account is over.
 * - The `image` column is switched with a compare-and-set: of two uploads
 *   racing for one question, the loser deletes its own file, so no file is
 *   left that no row references.
 * - What still ends up unreferenced is swept by the daily job, but only
 *   once it is older than the grace period (an upload writes its file a
 *   moment before its row).
 */
#[CoversClass(PollImageService::class)]
#[CoversClass(Limits::class)]
class ImageStorageTest extends TestCase {
    use OwnerStorageFakes;

    private const NOW = 1_800_000_000;
    private const MB = 1024 * 1024;

    /** App config the Limits read: key => value. */
    private array $config = [];
    private int $names = 0;
    private int $updates = 0;
    /** @var list<string> temporary upload files */
    private array $tmp = [];

    protected function setUp(): void {
        // alice owns rooms 1 and 2, bob room 3.
        $this->fakeRooms = [1 => 'alice', 2 => 'alice', 3 => 'bob'];
    }

    protected function tearDown(): void {
        foreach ($this->tmp as $file) {
            @unlink($file);
        }
    }

    // ── Budget ──────────────────────────────────────────────────────────────

    public function testUploadWithinBudgetIsStoredAndReferenced(): void {
        $poll = $this->poll(10, room: 1);

        $stored = $this->service()->store($poll, $this->upload());

        $name = $stored->getImage();
        $this->assertMatchesRegularExpression('/^[a-z0-9]{16}\.png$/', $name);
        $this->assertSame($name, $this->fakePolls[10]['image'], 'row switched over');
        $this->assertSame(['10-' . $name], array_keys($this->fakeFiles));
        $this->assertSame([], $stored->getUpdatedFields(), 'nothing left for a later mapper update');
    }

    public function testFullBudgetRefusesBeforeReencodingAndWritesNothing(): void {
        $this->config[Limits::IMAGE_BYTES_PER_OWNER] = 1000;
        // alice's other room already holds 1000 bytes of images.
        $this->storedImage(20, room: 2, bytes: 1000);
        $poll = $this->poll(10, room: 1);
        // Header intact, pixel data cut off: GD would fail on it ("could not be
        // read") — so the budget message proves GD never ran.
        $truncated = substr($this->png(64), 0, 40);
        $file = tempnam(sys_get_temp_dir(), 'pulse-img');
        file_put_contents($file, $truncated);
        $this->tmp[] = $file;

        try {
            $this->service()->store($poll, ['tmp_name' => $file, 'size' => strlen($truncated), 'error' => UPLOAD_ERR_OK]);
            $this->fail('budget exceeded, yet stored');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('storage limit', $e->getMessage());
        }
        $this->assertCount(1, $this->fakeFiles, 'no new file');
        $this->assertSame([], $this->fakeLog, 'no row written');
    }

    public function testExactSizeAfterReencodingIsChecked(): void {
        // Some room left, but less than the re-encoded PNG needs (a PNG
        // has more than 20 bytes of headers alone).
        $this->config[Limits::IMAGE_BYTES_PER_OWNER] = 170;
        $this->storedImage(20, room: 2, bytes: 150);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->service()->store($this->poll(10, room: 1), $this->upload(64));
        } finally {
            $this->assertCount(1, $this->fakeFiles);
        }
    }

    public function testParallelUploadOverTheBudgetIsUndone(): void {
        $this->config[Limits::IMAGE_BYTES_PER_OWNER] = 1000;
        $poll = $this->storedImage(10, room: 1, bytes: 100);
        $old = $poll->getImage();
        // While this upload was being re-encoded, a parallel one stored 990
        // bytes for another question of alice's.
        $this->beforeUpdate = function (): void {
            $this->beforeUpdate = null;
            $this->storedImage(20, room: 2, bytes: 990);
        };

        try {
            $this->service()->store($poll, $this->upload());
            $this->fail('over the budget, yet stored');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('storage limit', $e->getMessage());
        }
        $this->assertSame($old, $this->fakePolls[10]['image'], 'the previous image is back');
        $this->assertSame(['10-' . $old, '20-' . $this->fakePolls[20]['image']], array_keys($this->fakeFiles), 'the new file is gone, the old one kept');
    }

    public function testParallelUploadWithinTheBudgetStays(): void {
        $this->config[Limits::IMAGE_BYTES_PER_OWNER] = 5000;
        $poll = $this->poll(10, room: 1);
        $this->beforeUpdate = function (): void {
            $this->beforeUpdate = null;
            $this->storedImage(20, room: 2, bytes: 990);
        };

        $stored = $this->service()->store($poll, $this->upload());

        $this->assertSame($stored->getImage(), $this->fakePolls[10]['image']);
        $this->assertCount(2, $this->fakeFiles);
    }

    public function testOtherAccountsImagesDoNotCount(): void {
        $this->config[Limits::IMAGE_BYTES_PER_OWNER] = 5000;
        $this->storedImage(30, room: 3, bytes: 5000); // bob's

        $this->service()->store($this->poll(10, room: 1), $this->upload());

        $this->assertCount(2, $this->fakeFiles);
    }

    public function testReplacingAnImageFreesItsOwnSpace(): void {
        // The only image alice has is the one being replaced: 1000 of 1050
        // bytes are in use, but they are freed by the replacement.
        $this->config[Limits::IMAGE_BYTES_PER_OWNER] = 1050;
        $poll = $this->storedImage(10, room: 1, bytes: 1000);
        $old = $poll->getImage();

        $stored = $this->service()->store($poll, $this->upload(8));

        $this->assertNotSame($old, $stored->getImage());
        $this->assertSame(['10-' . $stored->getImage()], array_keys($this->fakeFiles), 'old file removed');
    }

    public function testRemainingBudgetAndSizeOf(): void {
        $this->config[Limits::IMAGE_BYTES_PER_OWNER] = 10_000;
        $a = $this->storedImage(10, room: 1, bytes: 1200);
        $this->storedImage(11, room: 2, bytes: 800);
        $this->storedImage(30, room: 3, bytes: 5000);
        // A file nobody references does not count against anyone.
        $this->fakeFiles['99-zzzzzzzzzzzzzzzz.png'] = ['content' => str_repeat('x', 3000), 'mtime' => 0];

        $service = $this->service();

        $this->assertSame(8000, $service->remainingBudget('alice'));
        $this->assertSame(5000, $service->remainingBudget('bob'));
        $this->assertSame(10_000, $service->remainingBudget('nobody'));
        $this->assertSame(1200, $service->sizeOf($a));
        $this->assertSame(0, $service->sizeOf($this->poll(12, room: 1)));
    }

    public function testDefaultBudgetIs200Megabytes(): void {
        $this->assertSame(200 * self::MB, $this->service()->remainingBudget('alice'));
    }

    public function testBudgetLookupCreatesNoFolder(): void {
        $this->fakeFolderExists = false;

        $this->assertSame(200 * self::MB, $this->service()->remainingBudget('alice'));
        $this->assertFalse($this->fakeFolderExists, 'reading the budget must not write');
    }

    // ── Compare-and-set ─────────────────────────────────────────────────────

    public function testConcurrentUploadLosesAndDeletesItsFile(): void {
        $poll = $this->poll(10, room: 1);
        // Between our read of the question and our UPDATE, another upload
        // switched the row to its own file.
        $this->beforeUpdate = function (): void {
            $this->beforeUpdate = null;
            $this->fakeFiles['10-otheruploadaaaaa.png'] = ['content' => 'theirs', 'mtime' => self::NOW];
            $this->fakePolls[10]['image'] = 'otheruploadaaaaa.png';
        };

        try {
            $this->service()->store($poll, $this->upload());
            $this->fail('lost the race, yet reported success');
        } catch (ConflictException) {
        }
        $this->assertSame('otheruploadaaaaa.png', $this->fakePolls[10]['image'], 'the winner stays');
        $this->assertSame(['10-otheruploadaaaaa.png'], array_keys($this->fakeFiles), 'our file is gone again');
    }

    public function testEightParallelUploadsLeaveExactlyOneFile(): void {
        // The audit's reproduction: 8 uploads to one question, each read the
        // row before any of them wrote it. Before: 8 files, 1 referenced.
        $service = $this->service();
        $snapshots = [];
        for ($i = 0; $i < 8; $i++) {
            $snapshots[] = $this->poll(10, room: 1, image: $this->fakePolls[10]['image'] ?? '');
        }
        $ok = 0;
        foreach ($snapshots as $poll) {
            try {
                $service->store($poll, $this->upload());
                $ok++;
            } catch (ConflictException) {
            }
        }

        $this->assertSame(1, $ok);
        $this->assertSame(['10-' . $this->fakePolls[10]['image']], array_keys($this->fakeFiles));
    }

    public function testNullColumnCountsAsNoImage(): void {
        $poll = $this->poll(10, room: 1);
        $this->fakePolls[10]['image'] = null;
        $poll->setImage(null);
        $poll->resetUpdatedFields();

        $stored = $this->service()->store($poll, $this->upload());

        $this->assertSame($stored->getImage(), $this->fakePolls[10]['image']);
    }

    public function testRemoveSwitchesTheRowFirstAndKeepsAConcurrentUpload(): void {
        $poll = $this->storedImage(10, room: 1, bytes: 100);
        $mine = '10-' . $poll->getImage();
        $this->beforeUpdate = function (): void {
            $this->beforeUpdate = null;
            $this->fakeFiles['10-newuploadaaaaaaa.png'] = ['content' => 'new', 'mtime' => self::NOW];
            $this->fakePolls[10]['image'] = 'newuploadaaaaaaa.png';
        };

        $this->service()->remove($poll);

        $this->assertSame('newuploadaaaaaaa.png', $this->fakePolls[10]['image'], 'the new upload is not unset');
        $this->assertArrayHasKey('10-newuploadaaaaaaa.png', $this->fakeFiles);
        $this->assertArrayHasKey($mine, $this->fakeFiles, 'left for the upload (or the sweep) to delete');
    }

    public function testRemoveDeletesFileAndRow(): void {
        $poll = $this->storedImage(10, room: 1, bytes: 100);

        $out = $this->service()->remove($poll);

        $this->assertSame('', $out->getImage());
        $this->assertSame('', $this->fakePolls[10]['image']);
        $this->assertSame([], $this->fakeFiles);
    }

    // ── Copy ────────────────────────────────────────────────────────────────

    public function testCopyWritesOwnFileAndReportsBytes(): void {
        $src = $this->storedImage(10, room: 1, bytes: 300);
        $dst = $this->poll(40, room: 2);

        $bytes = $this->service()->copy($src, $dst, 1000);

        $this->assertSame(300, $bytes);
        $this->assertNotSame($src->getImage(), $dst->getImage());
        $this->assertSame($dst->getImage(), $this->fakePolls[40]['image']);
        $this->assertCount(2, $this->fakeFiles);
    }

    public function testCopyOverBudgetThrowsBeforeWriting(): void {
        $src = $this->storedImage(10, room: 1, bytes: 300);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->service()->copy($src, $this->poll(40, room: 2), 299);
        } finally {
            $this->assertCount(1, $this->fakeFiles);
        }
    }

    public function testCopyWithoutGivenBudgetComputesTheTargetOwners(): void {
        $this->config[Limits::IMAGE_BYTES_PER_OWNER] = 500;
        $src = $this->storedImage(30, room: 3, bytes: 300);   // bob's image …
        $this->storedImage(11, room: 2, bytes: 300);          // … into alice's room, who has 200 left

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->copy($src, $this->poll(40, room: 1));
    }

    public function testCopyToAVanishedQuestionLeavesNoFile(): void {
        $src = $this->storedImage(10, room: 1, bytes: 300);
        $dst = $this->poll(40, room: 2);
        $this->beforeUpdate = function (): void {
            $this->beforeUpdate = null;
            unset($this->fakePolls[40]); // deleted meanwhile
        };

        $this->assertSame(0, $this->service()->copy($src, $dst, 1000));
        $this->assertSame(['10-' . $src->getImage()], array_keys($this->fakeFiles));
    }

    // ── Sweep ───────────────────────────────────────────────────────────────

    public function testSweepDeletesOnlyOldUnreferencedFiles(): void {
        $kept = $this->storedImage(10, room: 1, bytes: 10, mtime: self::NOW - 90 * 86_400);
        $this->fakeFiles['10-orphanoldaaaaaaa.png'] = ['content' => 'x', 'mtime' => self::NOW - 3601];
        $this->fakeFiles['11-orphanyoungaaaaa.png'] = ['content' => 'x', 'mtime' => self::NOW - 3599];
        $this->fakeFiles['77-questiongoneaaaa.jpg'] = ['content' => 'x', 'mtime' => self::NOW - 86_400];
        $this->fakeFiles['stray.txt'] = ['content' => 'x', 'mtime' => self::NOW - 86_400];

        $deleted = $this->service()->sweepOrphans(3600);

        $this->assertSame(3, $deleted);
        $this->assertSame(['10-' . $kept->getImage(), '11-orphanyoungaaaaa.png'], array_keys($this->fakeFiles));
    }

    public function testSweepWithoutFolderDoesNothing(): void {
        $this->fakeFolderExists = false;

        $this->assertSame(0, $this->service()->sweepOrphans(3600));
        $this->assertFalse($this->fakeFolderExists);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function service(): PollImageService {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf($text, $p));

        $random = $this->createMock(ISecureRandom::class);
        $random->method('generate')->willReturnCallback(fn (): string => substr(str_pad('img' . ++$this->names, 16, 'a'), 0, 16));

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default): int => $this->config[$key] ?? $default);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        return new PollImageService(
            $this->createMock(PollMapper::class),
            $this->fakeAppData(self::NOW),
            $random,
            $l10n,
            $this->fakeDb(),
            new Limits($appConfig),
            $time,
        );
    }

    /** A question row + entity (the entity as the controller read it). */
    private function poll(int $id, int $room, string $image = ''): Poll {
        $this->fakePolls[$id] ??= ['room_id' => $room, 'image' => $image];
        $poll = new Poll();
        $poll->setId($id);
        $poll->setRoomId($room);
        $poll->setImage($image);
        $poll->resetUpdatedFields();
        return $poll;
    }

    /** A question with an already stored image of $bytes bytes. */
    private function storedImage(int $id, int $room, int $bytes, int $mtime = self::NOW): Poll {
        $name = substr(str_pad('have' . $id, 16, 'b'), 0, 16) . '.png';
        $this->fakeFiles[$id . '-' . $name] = ['content' => str_repeat('x', $bytes), 'mtime' => $mtime];
        return $this->poll($id, $room, $name);
    }

    private function png(int $edge): string {
        // Solid colour: tiny after compression, well below every budget used here.
        $img = imagecreatetruecolor($edge, $edge);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagepng($img, null, 6);
        return (string)ob_get_clean();
    }

    /** A $_FILES entry with a real (tiny) PNG. */
    private function upload(int $edge = 8): array {
        $file = tempnam(sys_get_temp_dir(), 'pulse-img');
        file_put_contents($file, $this->png($edge));
        $this->tmp[] = $file;
        return ['tmp_name' => $file, 'size' => filesize($file), 'error' => UPLOAD_ERR_OK];
    }
}
