<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Controller\RoomApiController;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\DemoService;
use OCA\Pulse\Service\FilesExportException;
use OCA\Pulse\Service\FilesExportService;
use OCA\Pulse\Service\Limits;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\PaceStateService;
use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\RoomService;
use OCA\Pulse\Service\StateService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IAppConfig;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\RateLimiting\ILimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * "Save in Files" on the summary: the results CSV goes into the owner's
 * folder "Pulse" (created when missing) as "<room name> – Results
 * <date>.csv", never over an existing file, with the same content as the
 * summary's download; a Files problem is a message (409), never a 500.
 */
#[CoversClass(FilesExportService::class)]
#[CoversClass(RoomApiController::class)]
class FilesExportTest extends TestCase {

    /** 2026-11-05 23:30 UTC = 2026-11-06 00:30 in Berlin. */
    private const NOW = 1793921400;
    private const CSV = "\u{FEFF}No.;Question\r\n1;\"'=1+1\"\r\n";

    private Room $room;
    private Folder&MockObject $home;
    private Folder&MockObject $pulse;
    private bool $pulseExists = true;
    /** What sits at "Pulse" when it exists: the folder, or a file in the way. */
    private bool $pulseIsFile = false;
    /** Thrown by newFile, if anything. */
    private ?\Throwable $writeFails = null;
    /** @var list<array{0: string, 1: string}> [name, content] per newFile */
    private array $written = [];
    /** @var list<string> names asked for at getNonExistingName */
    private array $asked = [];
    /** What getNonExistingName answers; null = the name as asked. */
    private ?string $nonExisting = null;
    private int $foldersCreated = 0;

    protected function setUp(): void {
        $this->room = new Room();
        $this->room->setId(5);
        $this->room->setCode('ABCDEF');
        $this->room->setOwnerUid('alice');
        $this->room->setTitle('Quiz');

        $this->pulse = $this->createMock(Folder::class);
        $this->pulse->method('getNonExistingName')->willReturnCallback(function (string $name): string {
            $this->asked[] = $name;
            return $this->nonExisting ?? $name;
        });
        $this->pulse->method('newFile')->willReturnCallback(function (string $name, $content = null): File {
            if ($this->writeFails !== null) {
                throw $this->writeFails;
            }
            $this->written[] = [$name, (string)$content];
            $file = $this->createMock(File::class);
            $file->method('getId')->willReturn(42);
            $file->method('getName')->willReturn($name);
            return $file;
        });

        $this->home = $this->createMock(Folder::class);
        $this->home->method('nodeExists')->willReturnCallback(fn (string $p): bool => $p === 'Pulse' && $this->pulseExists);
        $this->home->method('get')->willReturnCallback(fn (string $p) => $this->pulseIsFile ? $this->createMock(File::class) : $this->pulse);
        $this->home->method('newFolder')->willReturnCallback(function (string $p): Folder {
            $this->assertSame('Pulse', $p);
            $this->foldersCreated++;
            return $this->pulse;
        });
    }

    // ── Folder ─────────────────────────────────────────────────────────────

    public function testMissingFolderIsCreatedAndTheFileWrittenIntoIt(): void {
        $this->pulseExists = false;

        $result = $this->service()->saveResults($this->room, 'alice');

        $this->assertSame(1, $this->foldersCreated);
        $this->assertSame([['Quiz – Results 2026-11-06.csv', self::CSV]], $this->written);
        $this->assertSame(['fileId' => 42, 'name' => 'Quiz – Results 2026-11-06.csv', 'path' => '/Pulse/Quiz – Results 2026-11-06.csv'], $result);
    }

    public function testExistingFolderIsUsed(): void {
        $this->service()->saveResults($this->room, 'alice');

        $this->assertSame(0, $this->foldersCreated);
        $this->assertCount(1, $this->written);
    }

    public function testAFileNamedPulseInTheWayIsAMessage(): void {
        $this->pulseIsFile = true;

        try {
            $this->service()->saveResults($this->room, 'alice');
            $this->fail('no exception');
        } catch (FilesExportException $e) {
            $this->assertSame('A file named “Pulse” is in the way in your Files.', $e->getMessage());
        }
        $this->assertSame([], $this->written);
        $this->assertSame(0, $this->foldersCreated);
    }

    // ── Name ───────────────────────────────────────────────────────────────

    public function testNameClashGetsANumberAndNothingIsOverwritten(): void {
        $this->nonExisting = 'Quiz – Results 2026-11-06 (2).csv';

        $result = $this->service()->saveResults($this->room, 'alice');

        $this->assertSame(['Quiz – Results 2026-11-06.csv'], $this->asked);
        $this->assertSame([['Quiz – Results 2026-11-06 (2).csv', self::CSV]], $this->written);
        $this->assertSame('Quiz – Results 2026-11-06 (2).csv', $result['name']);
    }

    public function testSummaryIsSavedAsMarkdownWithTheSameNameRules(): void {
        $this->room->setTitle('../Quiz');
        $this->nonExisting = 'Quiz – Summary 2026-11-06 (2).md';

        $result = $this->service()->saveSummary($this->room, 'alice', "# Edited\n");

        $this->assertSame(['Quiz – Summary 2026-11-06.md'], $this->asked);
        $this->assertSame([['Quiz – Summary 2026-11-06 (2).md', "# Edited\n"]], $this->written);
        $this->assertSame('/Pulse/Quiz – Summary 2026-11-06 (2).md', $result['path']);
        $this->room->setTitle('');
        $this->assertSame('ABCDEF – Summary 2026-11-06.md', $this->service()->summaryFileName($this->room));
    }

    public function testSummaryFilesProblemIsAMessage(): void {
        $this->writeFails = new NotPermittedException('ro');

        $this->expectException(FilesExportException::class);
        $this->expectExceptionMessage('You cannot save files in your Files.');
        $this->service()->saveSummary($this->room, 'alice', 'x');
    }

    public function testDateIsTodayInTheUsersTimeZone(): void {
        $this->assertSame('Quiz – Results 2026-11-05.csv', $this->service('America/New_York')->fileName($this->room));
        $this->assertSame('Quiz – Results 2026-11-06.csv', $this->service('Europe/Berlin')->fileName($this->room));
    }

    public static function titles(): array {
        return [
            'empty: the code' => ['', 'ABCDEF – Results 2026-11-06.csv'],
            'only blanks: the code' => ['   ', 'ABCDEF – Results 2026-11-06.csv'],
            'only dots: the code' => ['...', 'ABCDEF – Results 2026-11-06.csv'],
            'only slashes: the code' => ['//', 'ABCDEF – Results 2026-11-06.csv'],
            'traversal' => ['../../etc/passwd', 'etc passwd – Results 2026-11-06.csv'],
            'slash' => ['a/b', 'a b – Results 2026-11-06.csv'],
            'backslash' => ['a\\b', 'a b – Results 2026-11-06.csv'],
            'control character' => ["a\x01\nb", 'a b – Results 2026-11-06.csv'],
            'hidden file' => ['.htaccess', 'htaccess – Results 2026-11-06.csv'],
            'umlaut and emoji' => ['Größe 🎉', 'Größe 🎉 – Results 2026-11-06.csv'],
        ];
    }

    #[DataProvider('titles')]
    public function testTitleBecomesOnePlainFileName(string $title, string $expected): void {
        $this->room->setTitle($title);

        $this->service()->saveResults($this->room, 'alice');

        $name = $this->written[0][0];
        $this->assertSame($expected, $name);
        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('\\', $name);
        $this->assertStringStartsNotWith('.', $name);
    }

    public function testTitleTheInstanceRejectsFallsBackToTheCode(): void {
        $this->room->setTitle('forbidden');

        $this->assertSame('ABCDEF – Results 2026-11-06.csv', $this->service()->fileName($this->room));
    }

    // ── Errors ─────────────────────────────────────────────────────────────

    public static function writeErrors(): array {
        return [
            'not permitted' => [new NotPermittedException('ro'), 'You cannot save files in your Files.'],
            'invalid path' => [new \OCP\Files\InvalidPathException('x'), 'Could not save the file in your Files.'],
            'locked' => [new \OCP\Lock\LockedException('x'), 'Could not save the file in your Files.'],
            'anything else' => [new \RuntimeException('quota'), 'Could not save the file in your Files.'],
        ];
    }

    #[DataProvider('writeErrors')]
    public function testFilesProblemIsAMessage(\Throwable $error, string $message): void {
        $this->writeFails = $error;

        try {
            $this->service()->saveResults($this->room, 'alice');
            $this->fail('no exception');
        } catch (FilesExportException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    // ── Route ──────────────────────────────────────────────────────────────

    public function testRouteSavesTheSameContentAsTheDownloadAnd201(): void {
        $controller = $this->controller($this->service());

        $download = $controller->exportCsv('ABCDEF');
        $saved = $controller->saveCsvToFiles('ABCDEF');

        $this->assertInstanceOf(DataDownloadResponse::class, $download);
        $this->assertSame($download->render(), $this->written[0][1]);
        $this->assertInstanceOf(JSONResponse::class, $saved);
        $this->assertSame(Http::STATUS_CREATED, $saved->getStatus());
        $this->assertSame(42, $saved->getData()['fileId']);
    }

    public function testRouteAnswersAFilesProblemWith409AndTheMessage(): void {
        $this->writeFails = new NotPermittedException('ro');

        $response = $this->controller($this->service())->saveCsvToFiles('ABCDEF');

        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $this->assertSame(['message' => 'You cannot save files in your Files.'], $response->getData());
    }

    public function testRouteIsLoginOnlyWithCsrf(): void {
        $m = new \ReflectionMethod(RoomApiController::class, 'saveCsvToFiles');
        $this->assertCount(1, $m->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class));
        $this->assertCount(0, $m->getAttributes(\OCP\AppFramework\Http\Attribute\NoCSRFRequired::class));
        $this->assertCount(0, $m->getAttributes(\OCP\AppFramework\Http\Attribute\PublicPage::class));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function state(): StateService {
        $state = $this->createMock(StateService::class);
        $state->method('exportCsv')->willReturn(self::CSV);
        return $state;
    }

    private function service(string $tz = 'Europe/Berlin'): FilesExportService {
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(function (string $uid): Folder {
            $this->assertSame('alice', $uid);
            return $this->home;
        });

        // Like the instance's validator: '/' never survives, plus one name it rejects.
        $validator = $this->createMock(IFilenameValidator::class);
        $validator->method('sanitizeFilename')->willReturnCallback(fn (string $n): string => str_replace('/', '_', $n));
        $validator->method('isFilenameValid')->willReturnCallback(fn (string $n): bool => $n !== '' && !str_contains($n, '/') && !str_starts_with($n, 'forbidden'));

        $zone = $this->createMock(IDateTimeZone::class);
        $zone->method('getTimeZone')->willReturn(new \DateTimeZone($tz));

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getDateTime')->willReturnCallback(
            fn (string $when = 'now', ?\DateTimeZone $z = null): \DateTime => (new \DateTime('@' . self::NOW))->setTimezone($z ?? new \DateTimeZone('UTC')),
        );

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        return new FilesExportService($root, $this->state(), $validator, $zone, $time, $l10n, $this->createMock(LoggerInterface::class));
    }

    private function controller(FilesExportService $files): RoomApiController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => $default);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $rooms = $this->createMock(RoomService::class);
        $rooms->method('getOwnedRoom')->willReturn($this->room);

        return new RoomApiController(
            $request,
            $session,
            $rooms,
            $this->createMock(DeckService::class),
            $this->state(),
            $this->createMock(VoteService::class),
            $this->createMock(DemoService::class),
            $this->createMock(PollImageService::class),
            $l10n,
            $this->createMock(PaceService::class),
            $this->createMock(PaceStateService::class),
            $this->createMock(RoomMapper::class),
            $this->createMock(ITimeFactory::class),
            $this->createMock(ILimiter::class),
            new Limits($this->createMock(IAppConfig::class)),
            $files,
        );
    }
}
