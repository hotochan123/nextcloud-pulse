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
use OCA\Pulse\Service\ConflictException;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\RoomService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Wächter der bestehenden Moderator-Routen im eigenen Tempo und das
 * Umschalten des Tempos.
 *
 * Die Gegenrichtung ist genauso wichtig: bei `pace='live'` ist jeder Wächter
 * ein No-op — der moderierte Betrieb bleibt verhaltensgleich.
 */
#[CoversClass(PaceService::class)]
class PaceGuardTest extends TestCase {

    private const NOW = 1_800_000_000;

    private PaceService $service;
    private RoomMapper&MockObject $rooms;
    private RoomService&MockObject $roomService;
    private Room $locked;
    private int $locks = 0;
    /** @var list<string> Transaktionsfolge */
    private array $tx = [];

    protected function setUp(): void {
        $this->locked = $this->room();

        $this->rooms = $this->createMock(RoomMapper::class);
        $this->rooms->method('lockForUpdate')->willReturnCallback(function (): Room {
            $this->locks++;
            return $this->locked;
        });
        $this->rooms->method('findByCode')->willReturnCallback(fn (): Room => $this->locked);

        $this->roomService = $this->createMock(RoomService::class);

        $db = $this->createMock(IDBConnection::class);
        foreach (['beginTransaction', 'commit', 'rollBack'] as $step) {
            $db->method($step)->willReturnCallback(function () use ($step): void {
                $this->tx[] = $step;
            });
        }

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->service = (new \ReflectionClass(PaceService::class))->newInstanceWithoutConstructor();
        foreach ([
            'roomMapper' => $this->rooms,
            'pollMapper' => $this->createMock(PollMapper::class),
            'playerMapper' => $this->createMock(PlayerMapper::class),
            'progressMapper' => $this->createMock(ProgressMapper::class),
            'voteMapper' => $this->createMock(VoteMapper::class),
            'presenceMapper' => $this->createMock(PresenceMapper::class),
            'roomService' => $this->roomService,
            'db' => $db,
            'timeFactory' => $time,
            'l10n' => $l10n,
        ] as $name => $value) {
            (new ReflectionProperty(PaceService::class, $name))->setValue($this->service, $value);
        }
    }

    /** Fensterzustände: [openedAt, closesAt, closedAt, releasedAt] */
    public static function zustaende(): array {
        return [
            'Entwurf' => ['draft', [0, 0, 0, 0]],
            'offen' => ['open', [self::NOW - 100, 0, 0, 0]],
            'offen mit Frist' => ['open', [self::NOW - 100, self::NOW + 60, 0, 0]],
            'geschlossen' => ['closed', [self::NOW - 100, 0, self::NOW - 1, 0]],
            'Frist abgelaufen' => ['closed', [self::NOW - 100, self::NOW, 0, 0]],
            'freigegeben' => ['released', [self::NOW - 100, 0, self::NOW - 1, self::NOW - 1]],
        ];
    }

    // ── assertDeckEditable ─────────────────────────────────────────────────

    #[DataProvider('zustaende')]
    public function testDeckNurImEntwurfBearbeitbar(string $state, array $window): void {
        $room = $this->room(...$window);
        $this->assertSame($state, PaceService::deriveState($room, self::NOW));
        if ($state === 'draft') {
            $this->service->assertDeckEditable($room);
            $this->addToAssertionCount(1);
            return;
        }
        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('The questions are locked since the quiz was opened. Reset the room to edit them.');
        $this->service->assertDeckEditable($room);
    }

    #[DataProvider('zustaende')]
    public function testModeriertIstDasDeckImmerBearbeitbar(string $state, array $window): void {
        $room = $this->room(...$window);
        $room->setPace('live');
        $this->service->assertDeckEditable($room);
        $this->service->assertLiveControl($room);
        $this->service->assertNotOpen($room);
        // Kein Raum wird dafür gesperrt — die Wächter laufen in der Sperre des Aufrufers.
        $this->assertSame(0, $this->locks);
    }

    // ── assertLiveControl ──────────────────────────────────────────────────

    #[DataProvider('zustaende')]
    public function testCursorSteuerungGibtEsImEigenenTempoNie(string $state, array $window): void {
        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('Not available while the quiz runs at its own pace.');
        $this->service->assertLiveControl($this->room(...$window));
    }

    // ── assertNotOpen ──────────────────────────────────────────────────────

    #[DataProvider('zustaende')]
    public function testLeerenNurBeiNichtOffenemFenster(string $state, array $window): void {
        $room = $this->room(...$window);
        if ($state !== 'open') {
            $this->service->assertNotOpen($room);
            $this->addToAssertionCount(1);
            return;
        }
        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('Close the quiz first.');
        $this->service->assertNotOpen($room);
    }

    // ── setPace ────────────────────────────────────────────────────────────

    public function testGleichesTempoLeertNichts(): void {
        $this->roomService->expects($this->never())->method('resetRoom');
        $this->rooms->expects($this->never())->method('update');

        $result = $this->service->setPace($this->room(), 'self');

        $this->assertSame($this->locked, $result);
        $this->assertSame(1, $this->locks);
        $this->assertSame(['beginTransaction', 'commit'], $this->tx);
    }

    public function testAnderesTempoSchreibtUndLeertDenGesperrtenRaum(): void {
        $this->locked = $this->room(self::NOW - 100, 0, self::NOW - 1, self::NOW - 1);
        $calls = [];
        $this->rooms->expects($this->once())->method('update')->with($this->identicalTo($this->locked))
            ->willReturnCallback(function (Room $r) use (&$calls): Room {
                $calls[] = 'update:' . $r->getPace();
                return $r;
            });
        $this->roomService->expects($this->once())->method('resetRoom')->with($this->identicalTo($this->locked))
            ->willReturnCallback(function () use (&$calls): void {
                $calls[] = 'reset';
            });

        // Der übergebene Raum ist veraltet (noch „live") — maßgeblich ist die gesperrte Zeile.
        $stale = $this->room();
        $stale->setPace('live');
        $this->service->setPace($stale, 'live');

        $this->assertSame(['update:live', 'reset'], $calls);
        $this->assertSame(['beginTransaction', 'commit'], $this->tx);
    }

    public function testAufEigenesTempoUmschaltenLeertAuch(): void {
        $this->locked->setPace('live');
        $this->locked->resetUpdatedFields();
        $this->rooms->expects($this->once())->method('update')->willReturnArgument(0);
        $this->roomService->expects($this->once())->method('resetRoom');

        $this->service->setPace($this->room(), 'self');

        $this->assertSame('self', $this->locked->getPace());
    }

    public function testUmfrageRaumKannNichtImEigenenTempoLaufen(): void {
        $this->locked->setMode('poll');
        $this->locked->setPace('live');
        $this->roomService->expects($this->never())->method('resetRoom');

        try {
            $this->service->setPace($this->room(), 'self');
            $this->fail('InvalidArgumentException erwartet');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Only quiz rooms can run at their own pace.', $e->getMessage());
        }
        $this->assertSame(['beginTransaction', 'rollBack'], $this->tx);
    }

    public function testUnbekanntesTempo(): void {
        $this->roomService->expects($this->never())->method('resetRoom');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown pace.');
        $this->service->setPace($this->room(), 'turbo');
    }

    public static function zielTempo(): array {
        return [
            'auf live' => ['live'],
            'gleiches Tempo' => ['self'],
        ];
    }

    #[DataProvider('zielTempo')]
    public function testBeiOffenemFensterKeinUmschalten(string $pace): void {
        // Auch derselbe Wert: der Zustand „offen" entscheidet (Tabelle §3.3), nicht der Wert.
        $this->locked = $this->room(self::NOW - 100);
        $this->roomService->expects($this->never())->method('resetRoom');
        $this->rooms->expects($this->never())->method('update');

        try {
            $this->service->setPace($this->room(), $pace);
            $this->fail('ConflictException erwartet');
        } catch (ConflictException $e) {
            $this->assertSame('Close the quiz first.', $e->getMessage());
        }
        $this->assertSame(['beginTransaction', 'rollBack'], $this->tx);
    }

    // ── Helfer ─────────────────────────────────────────────────────────────

    private function room(int $openedAt = 0, int $closesAt = 0, int $closedAt = 0, int $releasedAt = 0): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('ABCDEF');
        $room->setMode('quiz');
        $room->setPace('self');
        $room->setOpenedAt($openedAt);
        $room->setClosesAt($closesAt);
        $room->setClosedAt($closedAt);
        $room->setReleasedAt($releasedAt);
        $room->resetUpdatedFields();
        return $room;
    }
}
