<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use OC\DB\Exceptions\DbalException;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\PresenceMapper;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\CodeGenerator;
use OCA\Pulse\Service\DemoService;
use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\RoomService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Reset, copy, delete and demo cleanup know about self-paced mode:
 * progress, window, frozen order and join lock go along with them.
 *
 * The opposite direction is just as important: a moderated room gets no
 * additional write to the room row — reset writes it only when the
 * cursor is running (and then only active_poll_id), the copy only with
 * "Reveal at the end", exactly as before.
 */
#[CoversClass(RoomService::class)]
#[CoversClass(DemoService::class)]
class RoomPaceLifecycleTest extends TestCase {

    private const NOW = 1_800_000_000;

    private RoomService $service;
    private RoomMapper&MockObject $rooms;
    private ProgressMapper&MockObject $progress;
    /** @var list<string> begin/commit/rollBack of deleteRoom's transaction, in order */
    private array $tx = [];

    protected function setUp(): void {
        $this->rooms = $this->createMock(RoomMapper::class);
        $this->rooms->method('insert')->willReturnCallback(function (Room $room): Room {
            $room->setId(99);
            return $room;
        });
        $this->progress = $this->createMock(ProgressMapper::class);

        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturn([]);

        $codes = $this->createMock(CodeGenerator::class);
        $codes->method('uniqueRoomCode')->willReturn('NEWCODE');

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $db = $this->createMock(IDBConnection::class);
        foreach (['beginTransaction' => 'begin', 'commit' => 'commit', 'rollBack' => 'rollBack'] as $method => $what) {
            $db->method($method)->willReturnCallback(function () use ($what): bool {
                $this->tx[] = $what;
                return true;
            });
        }

        $this->service = (new \ReflectionClass(RoomService::class))->newInstanceWithoutConstructor();
        foreach ([
            'roomMapper' => $this->rooms,
            'pollMapper' => $polls,
            'voteMapper' => $this->createMock(VoteMapper::class),
            'presenceMapper' => $this->createMock(PresenceMapper::class),
            'playerMapper' => $this->createMock(PlayerMapper::class),
            'progressMapper' => $this->progress,
            'codeGenerator' => $codes,
            'imageService' => $this->createMock(PollImageService::class),
            'timeFactory' => $time,
            'l10n' => $l10n,
            'db' => $db,
        ] as $name => $value) {
            (new ReflectionProperty(RoomService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── resetRoom ──────────────────────────────────────────────────────────

    public function testResetModeriertOhneCursorSchreibtDenRaumNicht(): void {
        $room = $this->room();
        $this->rooms->expects($this->never())->method('update');
        $this->progress->expects($this->once())->method('deleteByRoom')->with(5);

        $this->service->resetRoom($room);
    }

    public function testResetModeriertMitCursorSchreibtNurDenCursor(): void {
        $room = $this->room();
        $room->setActivePollId(7);
        $room->resetUpdatedFields();

        $this->rooms->expects($this->once())->method('update')->willReturnCallback(function (Room $r): Room {
            // The same UPDATE as before self-paced mode: only active_poll_id.
            $this->assertSame(['activePollId' => true], $r->getUpdatedFields());
            return $r;
        });

        $this->service->resetRoom($room);
        $this->assertSame(0, $room->getActivePollId());
    }

    public function testResetImEigenenTempoLeertFensterUndBehaeltFormat(): void {
        $room = $this->room();
        $room->setPace('self');
        $room->setTimed(false);
        $room->setFeedback('end');
        $room->setOpenedAt(self::NOW - 500);
        $room->setClosesAt(self::NOW + 3600);
        $room->setClosedAt(self::NOW - 10);
        $room->setReleasedAt(self::NOW - 10);
        $room->setDeckOrder('[3,1,2]');
        $room->setJoinsLocked(true);
        $room->setTouchedAt(self::NOW - 100);
        $room->resetUpdatedFields();

        $this->rooms->expects($this->once())->method('update')->willReturnArgument(0);
        $this->progress->expects($this->once())->method('deleteByRoom')->with(5);

        $this->service->resetRoom($room);

        $this->assertSame(0, $room->getOpenedAt());
        $this->assertSame(0, $room->getClosesAt());
        $this->assertSame(0, $room->getClosedAt());
        $this->assertSame(0, $room->getReleasedAt());
        $this->assertNull($room->getDeckOrder());
        $this->assertFalse($room->getJoinsLocked());
        // The run's format and retention stay.
        $this->assertSame('self', $room->getPace());
        $this->assertFalse($room->getTimed());
        $this->assertSame('end', $room->getFeedback());
        $this->assertSame(self::NOW - 100, $room->getTouchedAt());
    }

    public function testResetImEntwurfOhneFensterSchreibtDenRaumNicht(): void {
        $room = $this->room();
        $room->setPace('self');
        $room->setTouchedAt(self::NOW - 100);
        $room->resetUpdatedFields();

        $this->rooms->expects($this->never())->method('update');

        $this->service->resetRoom($room);
    }

    public function testResetHebtAuchEineAlleinigeBeitrittssperreAuf(): void {
        $room = $this->room();
        $room->setPace('self');
        $room->setJoinsLocked(true);
        $room->resetUpdatedFields();

        $this->rooms->expects($this->once())->method('update')->willReturnArgument(0);

        $this->service->resetRoom($room);
        $this->assertFalse($room->getJoinsLocked());
    }

    // ── duplicateRoom ──────────────────────────────────────────────────────

    public function testKopieEinesModeriertenRaumsOhneExtraUpdate(): void {
        $this->rooms->expects($this->never())->method('update');

        $copy = $this->service->duplicateRoom($this->room(), 'alice');

        $this->assertSame('live', $copy->getPace());
    }

    public function testKopieMitAufloesungAmEndeSchreibtWieBisherEinmal(): void {
        $src = $this->room();
        $src->setRevealAtEnd(true);
        $this->rooms->expects($this->once())->method('update')->willReturnArgument(0);

        $copy = $this->service->duplicateRoom($src, 'alice');

        $this->assertTrue($copy->getRevealAtEnd());
        $this->assertSame('live', $copy->getPace());
    }

    public function testKopieImEigenenTempoNimmtNurDasFormatMit(): void {
        $src = $this->room();
        $src->setPace('self');
        $src->setTimed(false);
        $src->setFeedback('end');
        $src->setOpenedAt(self::NOW - 500);
        $src->setClosesAt(self::NOW + 3600);
        $src->setClosedAt(self::NOW - 10);
        $src->setReleasedAt(self::NOW - 10);
        $src->setDeckOrder('[3,1,2]');
        $src->setJoinsLocked(true);
        $src->setTouchedAt(self::NOW - 100);

        $this->rooms->expects($this->once())->method('update')->willReturnArgument(0);

        $copy = $this->service->duplicateRoom($src, 'alice');

        $this->assertSame('self', $copy->getPace());
        $this->assertFalse($copy->getTimed());
        $this->assertSame('end', $copy->getFeedback());
        // The copy is a fresh draft.
        $this->assertSame(0, $copy->getOpenedAt());
        $this->assertSame(0, $copy->getClosesAt());
        $this->assertSame(0, $copy->getClosedAt());
        $this->assertSame(0, $copy->getReleasedAt());
        $this->assertNull($copy->getDeckOrder());
        $this->assertFalse($copy->getJoinsLocked());
        $this->assertSame(0, $copy->getTouchedAt());
    }

    // ── deleteRoom ─────────────────────────────────────────────────────────

    public function testLoeschenNimmtDenFortschrittMit(): void {
        $room = $this->room();
        $this->progress->expects($this->once())->method('deleteByRoom')->with(5);
        $this->rooms->expects($this->once())->method('delete')->with($room)->willReturnArgument(0);

        $this->service->deleteRoom($room);
    }

    public function testLoeschenInDerReihenfolgeGegenWettlaeufe(): void {
        // Room row first (a self-paced join under the room lock finishes first
        // and is then deleted along with it, a later one gets 404), players before
        // votes and progress (/vote and /next re-check with a lock afterwards,
        // see PaceService::assertStillJoined), all rows in one transaction,
        // image files last — after the commit.
        $this->wireDelete();

        $this->service->deleteRoom($this->room());

        $this->assertSame(
            ['begin', 'room', 'presence', 'players', 'findPolls', 'votes', 'poll', 'progress', 'commit', 'image'],
            $this->tx,
        );
    }

    public function testFehlschlagMittendrinLaesstDenRaumGanz(): void {
        // A statement after the room row fails: everything is rolled back, so
        // the room is still complete and can be deleted again — instead of the
        // room row being gone and questions, votes and progress staying behind
        // for good. No image file goes either.
        $this->wireDelete(function (): void {
            throw new DbException('disk full');
        });

        try {
            $this->service->deleteRoom($this->room());
            $this->fail('the error must reach the caller');
        } catch (DbException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        $this->assertSame(['begin', 'room', 'presence', 'players', 'findPolls', 'votes', 'rollBack'], $this->tx);
    }

    public function testDeadlockWirdWiederholt(): void {
        // A participant votes while the room is deleted, and the database
        // resolves a deadlock by aborting the deletion: rolled back and tried
        // again, the second attempt deletes the room completely.
        $attempts = 0;
        $this->wireDelete(function () use (&$attempts): void {
            if (++$attempts === 1) {
                throw DbalException::wrap(new DeadlockException(
                    new class('Deadlock found when trying to get lock') extends AbstractException {
                    },
                    null,
                ));
            }
        });

        $this->service->deleteRoom($this->room());

        $this->assertSame([
            'begin', 'room', 'presence', 'players', 'findPolls', 'votes', 'rollBack',
            'begin', 'room', 'presence', 'players', 'findPolls', 'votes', 'poll', 'progress', 'commit',
            'image',
        ], $this->tx);
    }

    // ── DemoService::clearDemoVotes ────────────────────────────────────────

    public function testDemoRaeumenNimmtDenDemoFortschrittMit(): void {
        $poll = new Poll();
        $poll->setId(41);
        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturn([$poll]);
        $votes = $this->createMock(VoteMapper::class);
        $votes->expects($this->once())->method('deleteDemoByPoll')->with(41)->willReturn(3);
        $players = $this->createMock(PlayerMapper::class);
        $players->expects($this->once())->method('deleteDemoByRoom')->with(5);
        $this->progress->expects($this->once())->method('deleteDemoByRoom')->with(5);
        $this->progress->expects($this->never())->method('deleteByRoom');

        $demo = (new \ReflectionClass(DemoService::class))->newInstanceWithoutConstructor();
        foreach ([
            'pollMapper' => $polls,
            'voteMapper' => $votes,
            'playerMapper' => $players,
            'progressMapper' => $this->progress,
        ] as $name => $value) {
            (new ReflectionProperty(DemoService::class, $name))->setValue($demo, $value);
        }

        $this->assertSame(['removed' => 3], $demo->clearDemoVotes($this->room()));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Mappers and image service for deleteRoom that append to $this->tx, next
     * to the transaction calls. The room holds one question (41) with an image.
     * $onVotes runs inside VoteMapper::deleteByPoll (to make it fail).
     */
    private function wireDelete(?\Closure $onVotes = null): void {
        $log = function (string $what): \Closure {
            return function (...$args) use ($what) {
                $this->tx[] = $what;
                return $args[0] ?? null;
            };
        };
        $poll = new Poll();
        $poll->setId(41);
        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturnCallback(function () use ($poll): array {
            $this->tx[] = 'findPolls';
            return [$poll];
        });
        $polls->method('delete')->willReturnCallback($log('poll'));
        $votes = $this->createMock(VoteMapper::class);
        $votes->method('deleteByPoll')->willReturnCallback(function (int $pollId) use ($onVotes): void {
            $this->tx[] = 'votes';
            if ($onVotes !== null) {
                $onVotes();
            }
        });
        $presence = $this->createMock(PresenceMapper::class);
        $presence->method('deleteByRoom')->willReturnCallback($log('presence'));
        $players = $this->createMock(PlayerMapper::class);
        $players->method('deleteByRoom')->willReturnCallback($log('players'));
        $images = $this->createMock(PollImageService::class);
        $images->method('discard')->willReturnCallback($log('image'));
        $this->progress->method('deleteByRoom')->willReturnCallback($log('progress'));
        $this->rooms->method('delete')->willReturnCallback($log('room'));
        foreach (['pollMapper' => $polls, 'voteMapper' => $votes, 'presenceMapper' => $presence, 'playerMapper' => $players, 'imageService' => $images] as $name => $value) {
            (new ReflectionProperty(RoomService::class, $name))->setValue($this->service, $value);
        }
    }

    private function room(): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('ABCDEF');
        $room->setTitle('');
        $room->setOwnerUid('alice');
        $room->setMode('quiz');
        $room->setCreatedAt(self::NOW - 1000);
        return $room;
    }
}
