<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Retention (RoomService::cleanupStaleRooms): a room stays as long as its
 * latest sign of life is no older than the retention period — participant
 * heartbeat, and for self-paced rooms also opening, deadline, closing, release
 * and owner visit.
 *
 * The case this is about: a homework with a deadline that the teacher only
 * grades weeks later must not vanish together with its results. And for
 * moderated rooms (all window fields 0) the decision must stay exactly the
 * old one: keep ⇔ a heartbeat since the cutoff date (formerly
 * countActive(id, cutoff) > 0, i.e. last_seen >= cutoff).
 */
#[CoversClass(RoomService::class)]
class CleanupRetentionTest extends TestCase {

    private const NOW = 1_800_000_000;
    private const DAY = 86_400;
    private const MAX_AGE = 30 * self::DAY; // CleanupStaleRoomsJob::RETENTION_DAYS
    private const CUTOFF = self::NOW - self::MAX_AGE;

    private RoomService $service;
    /** @var list<Room> candidates that findOlderThan returns */
    private array $rooms = [];
    /** @var array<int,int> room ID -> MAX(last_seen), 0 = no presence */
    private array $lastSeen = [];
    /** @var list<int> IDs that roomMapper->delete received */
    private array $deletedRooms = [];
    /** @var list<int> IDs whose progress was deleted */
    private array $deletedProgress = [];

    protected function setUp(): void {
        $roomMapper = $this->createMock(RoomMapper::class);
        $roomMapper->method('findOlderThan')->willReturnCallback(function (int $ts): array {
            $this->assertSame(self::CUTOFF, $ts, 'Kandidaten wie bisher: vor dem Stichtag angelegt');
            return $this->rooms;
        });
        $roomMapper->method('delete')->willReturnCallback(function (Room $room): Room {
            $this->deletedRooms[] = $room->getId();
            return $room;
        });

        $presence = $this->createMock(PresenceMapper::class);
        $presence->method('lastSeen')->willReturnCallback(fn (int $id): int => $this->lastSeen[$id] ?? 0);

        $progress = $this->createMock(ProgressMapper::class);
        $progress->method('deleteByRoom')->willReturnCallback(function (int $id): void {
            $this->deletedProgress[] = $id;
        });

        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturn([]);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        $this->service = (new \ReflectionClass(RoomService::class))->newInstanceWithoutConstructor();
        foreach ([
            'roomMapper' => $roomMapper,
            'pollMapper' => $polls,
            'voteMapper' => $this->createMock(VoteMapper::class),
            'presenceMapper' => $presence,
            'playerMapper' => $this->createMock(PlayerMapper::class),
            'progressMapper' => $progress,
            'imageService' => $this->createMock(PollImageService::class),
            'timeFactory' => $time,
        ] as $name => $value) {
            (new ReflectionProperty(RoomService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── self-paced ─────────────────────────────────────────────────────────

    public function testHausaufgabeMitFristInZwanzigTagenBleibt(): void {
        // Created and opened 60 days ago, nobody there since — but the
        // deadline is still in the future.
        $this->selfRoom(1, opened: -59, closes: +20);
        $this->lastSeen[1] = $this->ago(59);

        $this->assertSame(0, $this->cleanup());
        $this->assertSame([], $this->deletedRooms);
    }

    public function testVorVierzigTagenGeschlossenOhneNeuerePraesenzWirdGeloescht(): void {
        // Race: closing without a deadline = releasing (both 40 days ago).
        $this->selfRoom(2, opened: -41, closed: -40, released: -40);
        $this->lastSeen[2] = $this->ago(40);

        $this->assertSame(1, $this->cleanup());
        $this->assertSame([2], $this->deletedRooms);
        $this->assertSame([2], $this->deletedProgress, 'der Fortschritt geht mit dem Raum');
    }

    public function testSpaeteFreigabeHaeltDenRaum(): void {
        // Deadline expired 40 days ago, released only 5 days ago
        // (releaseWindow pins closed_at = closes_at in the process).
        $this->selfRoom(3, opened: -50, closes: -40, closed: -40, released: -5);
        $this->lastSeen[3] = $this->ago(40);

        $this->assertSame(0, $this->cleanup());
    }

    public function testBesitzerbesuchHaeltDenRaum(): void {
        // Deadline 40 days ago, never released — but the teacher looked at
        // the progress just 5 days ago.
        $this->selfRoom(4, opened: -50, closes: -40, touched: -5);
        $this->lastSeen[4] = $this->ago(41);

        $this->assertSame(0, $this->cleanup());
    }

    public function testEntwurfOhneJedesLebenszeichenWirdGeloescht(): void {
        // Switched to "Self-paced", never opened, never visited.
        $this->selfRoom(5);

        $this->assertSame(1, $this->cleanup());
        $this->assertSame([5], $this->deletedRooms);
    }

    // ── moderated: equivalence to the old rule ─────────────────────────────

    public function testModerierterRaumMitPraesenzVorZehnTagenBleibt(): void {
        $this->liveRoom(10);
        $this->lastSeen[10] = $this->ago(10);

        $this->assertSame(0, $this->cleanup());
    }

    public function testModerierterRaumOhnePraesenzWirdGeloescht(): void {
        $this->liveRoom(11);

        $this->assertSame(1, $this->cleanup());
        $this->assertSame([11], $this->deletedRooms);
    }

    public static function praesenzAlter(): array {
        return [
            'gestern' => [self::NOW - self::DAY],
            'genau am Stichtag' => [self::CUTOFF],
            'eine Sekunde vor dem Stichtag' => [self::CUTOFF - 1],
            'vor 31 Tagen' => [self::NOW - 31 * self::DAY],
            'nie' => [0],
        ];
    }

    #[DataProvider('praesenzAlter')]
    public function testModeriertEntscheidetWieCountActiveSeitStichtag(int $lastSeen): void {
        $this->liveRoom(12);
        $this->lastSeen[12] = $lastSeen;

        // Old rule: countActive(id, cutoff) > 0 ⇔ a last_seen >= cutoff.
        $keptBefore = $lastSeen > 0 && $lastSeen >= self::CUTOFF;

        $this->cleanup();
        $this->assertSame($keptBefore, $this->deletedRooms === []);
    }

    public function testGemischteKandidatenWerdenEinzelnEntschieden(): void {
        $this->liveRoom(20);
        $this->lastSeen[20] = $this->ago(3);
        $this->liveRoom(21);
        $this->selfRoom(22, opened: -45, closes: -35, released: -35);
        $this->selfRoom(23, opened: -45, closes: +2);

        $this->assertSame(2, $this->cleanup());
        $this->assertSame([21, 22], $this->deletedRooms);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function cleanup(): int {
        return $this->service->cleanupStaleRooms(self::MAX_AGE);
    }

    private function ago(int $days): int {
        return self::NOW - $days * self::DAY;
    }

    private function liveRoom(int $id): Room {
        $room = new Room();
        $room->setId($id);
        $room->setMode('quiz');
        $room->setCreatedAt($this->ago(60));
        $this->rooms[] = $room;
        return $room;
    }

    /** Days relative to NOW (negative = past), 0 = field not set. */
    private function selfRoom(int $id, int $opened = 0, int $closes = 0, int $closed = 0, int $released = 0, int $touched = 0): Room {
        $room = $this->liveRoom($id);
        $room->setPace('self');
        $at = static fn (int $days): int => $days === 0 ? 0 : self::NOW + $days * self::DAY;
        $room->setOpenedAt($at($opened));
        $room->setClosesAt($at($closes));
        $room->setClosedAt($at($closed));
        $room->setReleasedAt($at($released));
        $room->setTouchedAt($at($touched));
        return $room;
    }
}
