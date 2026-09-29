<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
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
 * Window actions in self-paced mode: open, close, extend, release.
 *
 * Every action runs under the room lock and checks the state on the freshly
 * locked row (lockForUpdate), NOT on the room passed in — that one may be stale
 * from a second tab. Every exception rolls the transaction back.
 * That is why every case here also checks the transaction sequence.
 */
#[CoversClass(PaceService::class)]
class PaceWindowTest extends TestCase {

    private const NOW = 1_800_000_000;
    private const DAY = 86400;

    private PaceService $service;
    private RoomMapper&MockObject $rooms;

    /** What lockForUpdate returns — the row that counts. */
    private Room $locked;
    /** What findByCode returns after the write (null = the locked row). */
    private ?Room $reloaded = null;
    /** @var Poll[] */
    private array $deck = [];
    /** @var list<string> transaction sequence */
    private array $tx = [];
    /** @var ?list<mixed> arguments of openIfDraft */
    private ?array $opened = null;
    private bool $openResult = true;

    protected function setUp(): void {
        $this->locked = $this->room();
        $this->deck = [$this->poll(11), $this->poll(12), $this->poll(13)];

        $this->rooms = $this->createMock(RoomMapper::class);
        $this->rooms->expects($this->once())->method('lockForUpdate')->with(5)
            ->willReturnCallback(fn (): Room => $this->locked);
        $this->rooms->method('findByCode')->willReturnCallback(fn (): Room => $this->reloaded ?? $this->locked);
        $this->rooms->method('openIfDraft')->willReturnCallback(function (...$args): bool {
            $this->opened = $args;
            return $this->openResult;
        });

        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturnCallback(fn (): array => $this->deck);

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
            'pollMapper' => $polls,
            'playerMapper' => $this->createMock(PlayerMapper::class),
            'progressMapper' => $this->createMock(ProgressMapper::class),
            'voteMapper' => $this->createMock(VoteMapper::class),
            'presenceMapper' => $this->createMock(PresenceMapper::class),
            'roomService' => $this->createMock(RoomService::class),
            'db' => $db,
            'timeFactory' => $time,
            'l10n' => $l10n,
        ] as $name => $value) {
            (new ReflectionProperty(PaceService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── Opening ────────────────────────────────────────────────────────────

    public function testOeffnenNurImEigenenTempo(): void {
        $this->locked->setPace('live');
        $this->conflict(fn () => $this->service->openWindow($this->room(), 0, null, null), 'This room is not self-paced.');
        $this->assertNull($this->opened);
    }

    public static function schonGeoeffnet(): array {
        return [
            'offen' => [self::NOW - 100, 0, 0, 0],
            'geschlossen' => [self::NOW - 100, 0, self::NOW - 1, 0],
            'Frist abgelaufen' => [self::NOW - 100, self::NOW - 1, 0, 0],
            'freigegeben' => [self::NOW - 100, 0, self::NOW - 1, self::NOW - 1],
        ];
    }

    #[DataProvider('schonGeoeffnet')]
    public function testOeffnenNurAusDemEntwurf(int $openedAt, int $closesAt, int $closedAt, int $releasedAt): void {
        $this->locked = $this->room($openedAt, $closesAt, $closedAt, $releasedAt);
        $this->conflict(
            fn () => $this->service->openWindow($this->room(), 0, null, null),
            'The quiz has already been opened. Reset the room to start over.',
        );
        $this->assertNull($this->opened);
    }

    public function testOeffnenMitLeeremDeckGehtNicht(): void {
        $this->deck = [];
        $this->invalid(fn () => $this->service->openWindow($this->room(), 0, null, null), 'Add at least one question first.');
        $this->assertNull($this->opened);
    }

    public static function fristen(): array {
        return [
            'eine Sekunde zu früh' => [self::NOW + 59, false],
            'genau eine Minute' => [self::NOW + 60, true],
            'genau 30 Tage' => [self::NOW + 30 * self::DAY, true],
            'eine Sekunde zu spät' => [self::NOW + 30 * self::DAY + 1, false],
            'Vergangenheit' => [self::NOW - 5, false],
            'negativ' => [-1, false],
        ];
    }

    #[DataProvider('fristen')]
    public function testFristgrenzenBeimOeffnen(int $closesAt, bool $ok): void {
        if (!$ok) {
            $this->invalid(
                fn () => $this->service->openWindow($this->room(), $closesAt, null, null),
                'The deadline must be between one minute and 30 days from now.',
            );
            $this->assertNull($this->opened);
            return;
        }
        $this->ok(fn () => $this->service->openWindow($this->room(), $closesAt, null, null));
        $this->assertSame($closesAt, $this->opened[3]);
    }

    public function testVorgabenRennen(): void {
        $this->ok(fn () => $this->service->openWindow($this->room(), 0, null, null));
        // Order = current deck, the cursor is reset in the same UPDATE.
        $this->assertSame([5, '[11,12,13]', self::NOW, 0, true, 'each'], $this->opened);
    }

    public function testVorgabenHausaufgabe(): void {
        $this->ok(fn () => $this->service->openWindow($this->room(), self::NOW + 3600, null, null));
        $this->assertSame([5, '[11,12,13]', self::NOW, self::NOW + 3600, false, 'end'], $this->opened);
    }

    public function testAusdruecklicheEinstellungenGewinnen(): void {
        $this->ok(fn () => $this->service->openWindow($this->room(), 0, false, 'end'));
        $this->assertSame([5, '[11,12,13]', self::NOW, 0, false, 'end'], $this->opened);
    }

    public function testProbelaufErzwingtSofortigeRueckmeldung(): void {
        $this->locked->setPractice(true);
        $this->ok(fn () => $this->service->openWindow($this->room(), self::NOW + 3600, null, 'end'));
        $this->assertSame('each', $this->opened[5]);
    }

    public function testUnbekannteRueckmeldungIstEinEingabefehler(): void {
        $this->invalid(fn () => $this->service->openWindow($this->room(), 0, null, 'later'), 'Unknown feedback setting.');
        $this->assertNull($this->opened);
    }

    public function testZweiterTabWarSchnellerIstEinKonflikt(): void {
        $this->openResult = false;
        $this->conflict(
            fn () => $this->service->openWindow($this->room(), 0, null, null),
            'The quiz has already been opened. Reset the room to start over.',
        );
    }

    public function testOeffnenLiefertDenFrischGeladenenRaum(): void {
        // openIfDraft writes past the entity — what comes back is the DB row.
        $this->reloaded = $this->room(self::NOW);
        $result = $this->ok(fn () => $this->service->openWindow($this->room(), 0, null, null));
        $this->assertSame($this->reloaded, $result);
    }

    // ── State on the locked row ────────────────────────────────────────────

    public function testZustandZaehltAnDerGesperrtenZeileNichtAmUebergebenenRaum(): void {
        // The room passed in says "draft", the locked row has long been open.
        $this->locked = $this->room(self::NOW - 100);
        $this->conflict(
            fn () => $this->service->openWindow($this->room(), 0, null, null),
            'The quiz has already been opened. Reset the room to start over.',
        );
    }

    public function testVeralteterUebergebenerRaumHindertNicht(): void {
        // The other way round: passed in "open" (stale), locked back in draft.
        $this->ok(fn () => $this->service->openWindow($this->room(self::NOW - 100), 0, null, null));
        $this->assertNotNull($this->opened);
    }

    public function testSchreibfehlerRolltZurueck(): void {
        $this->locked = $this->room(self::NOW - 100);
        $this->rooms->method('update')->willThrowException(new \RuntimeException('DB weg'));
        try {
            $this->service->closeWindow($this->room());
            $this->fail('Ausnahme erwartet');
        } catch (\RuntimeException $e) {
            $this->assertSame('DB weg', $e->getMessage());
        }
        $this->assertSame(['beginTransaction', 'rollBack'], $this->tx);
    }

    // ── Closing ────────────────────────────────────────────────────────────

    // (without `release`: old clients)
    public function testSchliessenOhneFristGibtZugleichFrei(): void {
        $this->locked = $this->room(self::NOW - 100);
        $this->expectUpdate();
        $this->ok(fn () => $this->service->closeWindow($this->room()));
        $this->assertSame(self::NOW, $this->locked->getClosedAt());
        $this->assertSame(self::NOW, $this->locked->getReleasedAt());
    }

    public function testSchliessenMitFristGibtNichtFrei(): void {
        $this->locked = $this->room(self::NOW - 100, self::NOW + 3600);
        $this->expectUpdate();
        $this->ok(fn () => $this->service->closeWindow($this->room()));
        $this->assertSame(self::NOW, $this->locked->getClosedAt());
        $this->assertSame(0, $this->locked->getReleasedAt());
        $this->assertSame(self::NOW + 3600, $this->locked->getClosesAt());
    }

    public function testSchliessenImGeschlossenenFensterIstNoOp(): void {
        $this->locked = $this->room(self::NOW - 100, self::NOW + 3600, self::NOW - 10);
        $this->rooms->expects($this->never())->method('update');
        $this->ok(fn () => $this->service->closeWindow($this->room()));
        $this->assertSame(self::NOW - 10, $this->locked->getClosedAt());
    }

    public function testSchliessenNachAbgelaufenerFristIstNoOp(): void {
        $this->locked = $this->room(self::NOW - 100, self::NOW - 1);
        $this->rooms->expects($this->never())->method('update');
        $this->ok(fn () => $this->service->closeWindow($this->room()));
    }

    public function testSchliessenImEntwurfIstEinKonflikt(): void {
        $this->conflict(fn () => $this->service->closeWindow($this->room()), 'The quiz is not open.');
    }

    public function testSchliessenNachFreigabeIstEinKonflikt(): void {
        $this->locked = $this->room(self::NOW - 100, 0, self::NOW - 1, self::NOW - 1);
        $this->conflict(fn () => $this->service->closeWindow($this->room()), 'Results have already been released.');
    }

    public function testSchliessenImModeriertenRaumIstEinKonflikt(): void {
        $this->locked->setPace('live');
        $this->conflict(fn () => $this->service->closeWindow($this->room()), 'This room is not self-paced.');
    }

    // ── Closing with an explicit choice (release) ──────────────────────────

    /** closesAt, release, releasedAt afterwards */
    public static function freigabeWahl(): array {
        return [
            'Rennen, release false' => [0, false, 0],
            'Rennen, release true' => [0, true, self::NOW],
            'Hausaufgabe, release false' => [self::NOW + 3600, false, 0],
            'Hausaufgabe, release true' => [self::NOW + 3600, true, self::NOW],
        ];
    }

    #[DataProvider('freigabeWahl')]
    public function testSchliessenMitAusdruecklicherWahl(int $closesAt, bool $release, int $releasedAt): void {
        $this->locked = $this->room(self::NOW - 100, $closesAt);
        $this->expectUpdate();
        $this->ok(fn () => $this->service->closeWindow($this->room(), $release));
        $this->assertSame(self::NOW, $this->locked->getClosedAt());
        $this->assertSame($releasedAt, $this->locked->getReleasedAt());
        // The deadline stays as it was — no more two-minute detour.
        $this->assertSame($closesAt, $this->locked->getClosesAt());
    }

    /** closesAt, release — the window is already closed */
    public static function schonGeschlossen(): array {
        return [
            'Rennen gestoppt, ohne Angabe' => [0, null],
            'Rennen gestoppt, release true' => [0, true],
            'Rennen gestoppt, release false' => [0, false],
            'Hausaufgabe geschlossen, release true' => [self::NOW + 3600, true],
        ];
    }

    #[DataProvider('schonGeschlossen')]
    public function testReleaseAendertEinGeschlossenesFensterNicht(int $closesAt, ?bool $release): void {
        $this->locked = $this->room(self::NOW - 100, $closesAt, self::NOW - 10);
        $this->rooms->expects($this->never())->method('update');
        $this->ok(fn () => $this->service->closeWindow($this->room(), $release));
        $this->assertSame(self::NOW - 10, $this->locked->getClosedAt());
        // Releasing here means `release`, not `close` with true.
        $this->assertSame(0, $this->locked->getReleasedAt());
    }

    public function testNachDemStoppenGibtFreigebenFrei(): void {
        $this->locked = $this->room(self::NOW - 100, 0, self::NOW - 10);
        $this->expectUpdate();
        $this->ok(fn () => $this->service->releaseWindow($this->room()));
        $this->assertSame(self::NOW - 10, $this->locked->getClosedAt());
        $this->assertSame(self::NOW, $this->locked->getReleasedAt());
    }

    public function testNachDemStoppenOeffnetVerlaengernWiederAlsRennen(): void {
        $this->locked = $this->room(self::NOW - 100, 0, self::NOW - 10);
        $this->expectUpdate();
        $this->ok(fn () => $this->service->extendWindow($this->room(), 0));
        $this->assertSame(0, $this->locked->getClosedAt());
        $this->assertSame(0, $this->locked->getClosesAt());
        $this->assertSame('open', PaceService::deriveState($this->locked, self::NOW));
    }

    // ── Extending ──────────────────────────────────────────────────────────

    public function testVerlaengernNachFreigabeIstEinKonflikt(): void {
        $this->locked = $this->room(self::NOW - 100, 0, self::NOW - 1, self::NOW - 1);
        $this->conflict(fn () => $this->service->extendWindow($this->room(), self::NOW + 3600), 'Results have already been released.');
    }

    public function testVerlaengernImEntwurfIstEinKonflikt(): void {
        $this->conflict(fn () => $this->service->extendWindow($this->room(), self::NOW + 3600), 'The quiz is not open.');
    }

    public function testVerlaengernOeffnetEinGeschlossenesFensterWieder(): void {
        $this->locked = $this->room(self::NOW - 100, self::NOW + 3600, self::NOW - 10);
        $this->locked->setTimed(false);
        $this->locked->setFeedback('end');
        $this->locked->setDeckOrder('[13,11,12]');
        $this->expectUpdate();

        $this->ok(fn () => $this->service->extendWindow($this->room(), self::NOW + 7200));

        $this->assertSame(0, $this->locked->getClosedAt());
        $this->assertSame(self::NOW + 7200, $this->locked->getClosesAt());
        $this->assertSame('open', PaceService::deriveState($this->locked, self::NOW));
        // Settings and order stay.
        $this->assertFalse($this->locked->getTimed());
        $this->assertSame('end', $this->locked->getFeedback());
        $this->assertSame('[13,11,12]', $this->locked->getDeckOrder());
    }

    public function testVerlaengernNachAbgelaufenerFrist(): void {
        $this->locked = $this->room(self::NOW - 100, self::NOW - 1);
        $this->expectUpdate();
        $this->ok(fn () => $this->service->extendWindow($this->room(), self::NOW + 120));
        $this->assertSame('open', PaceService::deriveState($this->locked, self::NOW));
    }

    public function testVerlaengernOhneFristHeisstOffenBisManuell(): void {
        $this->locked = $this->room(self::NOW - 100, self::NOW + 3600);
        $this->expectUpdate();
        $this->ok(fn () => $this->service->extendWindow($this->room(), 0));
        $this->assertSame(0, $this->locked->getClosesAt());
        $this->assertSame(0, $this->locked->getClosedAt());
    }

    public function testVerlaengernPrueftDieFristgrenzen(): void {
        $this->locked = $this->room(self::NOW - 100, self::NOW + 3600);
        $this->rooms->expects($this->never())->method('update');
        $this->invalid(
            fn () => $this->service->extendWindow($this->room(), self::NOW + 59),
            'The deadline must be between one minute and 30 days from now.',
        );
    }

    // ── Releasing ──────────────────────────────────────────────────────────

    public function testFreigebenAusDemOffenenFensterSchliesstZugleich(): void {
        $this->locked = $this->room(self::NOW - 100, self::NOW + 3600);
        $this->expectUpdate();
        $this->ok(fn () => $this->service->releaseWindow($this->room()));
        $this->assertSame(self::NOW, $this->locked->getClosedAt());
        $this->assertSame(self::NOW, $this->locked->getReleasedAt());
    }

    public function testFreigebenNachFristablaufSchreibtDieFristAlsSchluss(): void {
        $this->locked = $this->room(self::NOW - 100, self::NOW - 40);
        $this->expectUpdate();
        $this->ok(fn () => $this->service->releaseWindow($this->room()));
        $this->assertSame(self::NOW - 40, $this->locked->getClosedAt());
        $this->assertSame(self::NOW, $this->locked->getReleasedAt());
    }

    public function testFreigebenNachManuellemSchliessenBehaeltDenSchluss(): void {
        $this->locked = $this->room(self::NOW - 100, self::NOW + 3600, self::NOW - 10);
        $this->expectUpdate();
        $this->ok(fn () => $this->service->releaseWindow($this->room()));
        $this->assertSame(self::NOW - 10, $this->locked->getClosedAt());
        $this->assertSame(self::NOW, $this->locked->getReleasedAt());
    }

    public function testFreigebenIstIdempotent(): void {
        $this->locked = $this->room(self::NOW - 100, 0, self::NOW - 10, self::NOW - 10);
        $this->rooms->expects($this->never())->method('update');
        $this->ok(fn () => $this->service->releaseWindow($this->room()));
        $this->assertSame(self::NOW - 10, $this->locked->getReleasedAt());
    }

    public function testFreigebenImEntwurfIstEinKonflikt(): void {
        $this->conflict(fn () => $this->service->releaseWindow($this->room()), 'The quiz is not open.');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** The action must run through and commit. */
    private function ok(callable $action): Room {
        $result = $action();
        $this->assertSame(['beginTransaction', 'commit'], $this->tx);
        return $result;
    }

    /** The action must abort with ConflictException and roll back. */
    private function conflict(callable $action, string $message): void {
        try {
            $action();
            $this->fail('ConflictException erwartet');
        } catch (ConflictException $e) {
            $this->assertSame($message, $e->getMessage());
        }
        $this->assertSame(['beginTransaction', 'rollBack'], $this->tx);
    }

    /** The action must abort with an input error (400) and roll back. */
    private function invalid(callable $action, string $message): void {
        try {
            $action();
            $this->fail('InvalidArgumentException erwartet');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
        }
        $this->assertSame(['beginTransaction', 'rollBack'], $this->tx);
    }

    /** Exactly one UPDATE, and on the locked row. */
    private function expectUpdate(): void {
        $this->rooms->expects($this->once())->method('update')
            ->with($this->identicalTo($this->locked))->willReturnArgument(0);
    }

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
        if ($openedAt > 0) {
            $room->setDeckOrder('[11,12,13]');
        }
        $room->resetUpdatedFields();
        return $room;
    }

    private function poll(int $id): Poll {
        $poll = new Poll();
        $poll->setId($id);
        $poll->setRoomId(5);
        return $poll;
    }
}
