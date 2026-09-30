<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\BackgroundJob\CleanupStaleRoomsJob;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\PresenceMapper;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\RoomService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * The daily cleanup, owner side of the security review (task D: L8, L9).
 *
 * L8: rooms must not outlive their owner. Before, an account deleted while
 * Pulse was disabled left its rooms behind, a new account with the same user
 * ID inherited them, and any anonymous code holder kept a room alive for
 * ever just by polling it. Now
 * - participant heartbeats count for retention only while the owner has
 *   been in the room within PRESENCE_NEEDS_OWNER_DAYS (180);
 * - the rooms of an account that no longer exists are deleted whatever
 *   their presence — but "no longer exists" needs both "no backend knows
 *   the ID" and "no login record left", so a disabled OIDC/LDAP app never
 *   costs anyone their rooms.
 * L9 (robustness part): one room that cannot be deleted no longer stops the
 * run, and each of the job's steps runs even when an earlier one fails.
 */
#[CoversClass(RoomService::class)]
#[CoversClass(CleanupStaleRoomsJob::class)]
class CleanupOrphansTest extends TestCase {
    use OwnerStorageFakes;

    private const NOW = 1_800_000_000;
    private const DAY = 86_400;
    private const MAX_AGE = 30 * self::DAY;

    /** @var list<Room> */
    private array $candidates = [];
    /** @var array<int,int> room id => last heartbeat */
    private array $lastSeen = [];
    /** @var array<string, list<Room>> owner => rooms */
    private array $byOwner = [];
    /** @var list<int> */
    private array $deleted = [];
    /** @var list<int> room IDs whose deletion fails */
    private array $failing = [];
    /** @var list<string> logged warnings */
    private array $warnings = [];

    // ── Presence needs a present owner ─────────────────────────────────────

    public static function ownerAbsence(): array {
        return [
            'owner there 179 days ago' => [179 * self::DAY, true],
            'owner there exactly 180 days ago' => [180 * self::DAY, true],
            'owner there 180 days and a second ago' => [180 * self::DAY + 1, false],
            'owner there 400 days ago' => [400 * self::DAY, false],
        ];
    }

    #[DataProvider('ownerAbsence')]
    public function testPollingKeepsARoomOnlyWhileTheOwnerIsAround(int $ownerAgo, bool $kept): void {
        $room = $this->room(1, createdAgo: 500 * self::DAY);
        $room->setTouchedAt(self::NOW - $ownerAgo);
        $this->lastSeen[1] = self::NOW - self::DAY; // a phone polled yesterday

        $this->service()->cleanupStaleRooms(self::MAX_AGE);

        $this->assertSame($kept ? [] : [1], $this->deleted);
    }

    public function testCreationCountsAsTheOwnersVisit(): void {
        // Never touched since (touched_at = 0), created 100 days ago: the
        // heartbeats still count, as they did before.
        $this->room(2, createdAgo: 100 * self::DAY);
        $this->lastSeen[2] = self::NOW - self::DAY;

        $this->service()->cleanupStaleRooms(self::MAX_AGE);

        $this->assertSame([], $this->deleted);
    }

    public function testAbandonedRoomWithoutPollingGoesAsBefore(): void {
        $this->room(3, createdAgo: 100 * self::DAY);

        $this->assertSame(1, $this->service()->cleanupStaleRooms(self::MAX_AGE));
        $this->assertSame([3], $this->deleted);
    }

    public function testSelfPacedDeadlineStillCountsWithoutTheOwner(): void {
        // Window fields are owner decisions of their own — a far deadline
        // keeps the room, heartbeats or not.
        $room = $this->room(4, createdAgo: 400 * self::DAY);
        $room->setPace('self');
        $room->setClosesAt(self::NOW + 10 * self::DAY);

        $this->service()->cleanupStaleRooms(self::MAX_AGE);

        $this->assertSame([], $this->deleted);
    }

    public function testOneFailingRoomDoesNotStopTheRun(): void {
        $this->room(5, createdAgo: 100 * self::DAY);
        $this->room(6, createdAgo: 100 * self::DAY);
        $this->room(7, createdAgo: 100 * self::DAY);
        $this->failing = [6];

        $this->assertSame(2, $this->service()->cleanupStaleRooms(self::MAX_AGE));
        $this->assertSame([5, 7], $this->deleted);
        $this->assertCount(1, $this->warnings);
    }

    // ── Rooms of accounts that are gone ────────────────────────────────────

    public function testRoomsOfGoneOwnersAreDeletedWhateverTheirPresence(): void {
        $this->fakeRooms = [1 => 'alice', 2 => 'carol', 3 => 'carol', 4 => 'bob'];
        $this->byOwner = [
            'alice' => [$this->room(1)],
            'carol' => [$this->room(2), $this->room(3)],
            'bob' => [$this->room(4)],
        ];
        $this->lastSeen = [2 => self::NOW, 3 => self::NOW]; // polled right now
        $asked = [];

        $deleted = $this->service()->cleanupOrphanedRooms(static function (string $uid) use (&$asked): bool {
            $asked[] = $uid;
            return $uid === 'carol';
        });

        $this->assertSame(2, $deleted);
        $this->assertSame([2, 3], $this->deleted);
        sort($asked);
        $this->assertSame(['alice', 'bob', 'carol'], $asked, 'every owner asked once');
    }

    public function testOrphanSweepGoesOnAfterAFailure(): void {
        $this->fakeRooms = [1 => 'carol', 2 => 'carol'];
        $this->byOwner = ['carol' => [$this->room(1), $this->room(2)]];
        $this->failing = [1];

        $this->assertSame(1, $this->service()->cleanupOrphanedRooms(static fn (): bool => true));
        $this->assertSame([2], $this->deleted);
        $this->assertCount(1, $this->warnings);
    }

    public function testNoRoomsNoWork(): void {
        $this->assertSame(0, $this->service()->cleanupOrphanedRooms(function (): bool {
            $this->fail('nobody to ask about');
        }));
    }

    // ── Job: who counts as gone ────────────────────────────────────────────

    public static function accounts(): array {
        return [
            'exists' => [true, '1790000000', false],
            'exists, never logged in' => [true, '', false],
            // The user backend is unavailable (OIDC/LDAP app disabled): the
            // login record is still there, so the rooms stay.
            'backend missing, login record kept' => [false, '1790000000', false],
            // Deleted: Nextcloud wiped every preference of the account.
            'deleted' => [false, '', true],
        ];
    }

    #[DataProvider('accounts')]
    public function testOwnerGoneNeedsBothSigns(bool $exists, string $lastLogin, bool $gone): void {
        $users = $this->createMock(IUserManager::class);
        $users->method('userExists')->with('carol')->willReturn($exists);
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->with('carol', 'login', 'lastLogin', '')->willReturn($lastLogin);

        $this->assertSame($gone, $this->job(users: $users, config: $config)->ownerGone('carol'));
    }

    public function testEmptyOwnerIsGone(): void {
        $users = $this->createMock(IUserManager::class);
        $users->expects($this->never())->method('userExists');

        $this->assertTrue($this->job(users: $users)->ownerGone(''));
    }

    // ── Job: the three steps ───────────────────────────────────────────────

    public function testJobRunsAllThreeStepsWithTheirParameters(): void {
        $rooms = $this->createMock(RoomService::class);
        $rooms->expects($this->once())->method('cleanupStaleRooms')->with(30 * self::DAY)->willReturn(0);
        $rooms->expects($this->once())->method('cleanupOrphanedRooms')->willReturnCallback(static function (callable $gone): int {
            // The job hands in its own predicate.
            return $gone('') ? 1 : 0;
        });
        $images = $this->createMock(PollImageService::class);
        $images->expects($this->once())->method('sweepOrphans')->with(3600)->willReturn(0);

        $this->runJob($this->job(rooms: $rooms, images: $images));
    }

    public function testAFailingStepDoesNotStopTheOthers(): void {
        $rooms = $this->createMock(RoomService::class);
        $rooms->method('cleanupStaleRooms')->willThrowException(new \RuntimeException('db down'));
        $rooms->expects($this->once())->method('cleanupOrphanedRooms')->willThrowException(new \RuntimeException('again'));
        $images = $this->createMock(PollImageService::class);
        $images->expects($this->once())->method('sweepOrphans')->willReturn(2);

        $this->runJob($this->job(rooms: $rooms, images: $images));

        $this->assertCount(2, $this->warnings);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function service(): RoomService {
        $roomMapper = $this->createMock(RoomMapper::class);
        $roomMapper->method('findOlderThan')->willReturnCallback(fn (): array => $this->candidates);
        $roomMapper->method('findByOwner')->willReturnCallback(fn (string $uid): array => $this->byOwner[$uid] ?? []);
        $roomMapper->method('delete')->willReturnCallback(function (Room $room): Room {
            if (in_array($room->getId(), $this->failing, true)) {
                throw new \RuntimeException('lock timeout');
            }
            $this->deleted[] = $room->getId();
            return $room;
        });
        $presence = $this->createMock(PresenceMapper::class);
        $presence->method('lastSeen')->willReturnCallback(fn (int $id): int => $this->lastSeen[$id] ?? 0);
        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturn([]);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        $service = (new \ReflectionClass(RoomService::class))->newInstanceWithoutConstructor();
        foreach ([
            'roomMapper' => $roomMapper,
            'pollMapper' => $polls,
            'voteMapper' => $this->createMock(VoteMapper::class),
            'presenceMapper' => $presence,
            'playerMapper' => $this->createMock(PlayerMapper::class),
            'progressMapper' => $this->createMock(ProgressMapper::class),
            'imageService' => $this->createMock(PollImageService::class),
            'timeFactory' => $time,
            'db' => $this->fakeDb(),
            'logger' => $this->logger(),
        ] as $name => $value) {
            (new ReflectionProperty(RoomService::class, $name))->setValue($service, $value);
        }
        return $service;
    }

    private function job(?RoomService $rooms = null, ?PollImageService $images = null, ?IUserManager $users = null, ?IConfig $config = null): CleanupStaleRoomsJob {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        return new CleanupStaleRoomsJob(
            $time,
            $rooms ?? $this->createMock(RoomService::class),
            $images ?? $this->createMock(PollImageService::class),
            $users ?? $this->createMock(IUserManager::class),
            $config ?? $this->createMock(IConfig::class),
            $this->logger(),
        );
    }

    private function runJob(CleanupStaleRoomsJob $job): void {
        $run = new \ReflectionMethod(CleanupStaleRoomsJob::class, 'run');
        $run->invoke($job, null);
    }

    private function logger(): LoggerInterface {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message): void {
            $this->warnings[] = $message;
        });
        return $logger;
    }

    /** A moderated room; with $createdAgo it is also a cleanup candidate. */
    private function room(int $id, int $createdAgo = 0): Room {
        $room = new Room();
        $room->setId($id);
        $room->setCode('ROOM' . str_pad((string)$id, 2, '0', STR_PAD_LEFT));
        $room->setMode('poll');
        $room->setCreatedAt(self::NOW - $createdAgo);
        if ($createdAgo > 0) {
            $this->candidates[] = $room;
        }
        return $room;
    }
}
