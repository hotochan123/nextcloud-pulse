<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Player;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\PresenceMapper;
use OCA\Pulse\Db\Progress;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\RoomService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * /next im eigenen Tempo — die einzige Stelle, an der eine Uhr startet.
 *
 * Geprüft wird das Protokoll gegen Doppeltipps, parallele Tabs und Abbrüche:
 * ein veraltetes `after` tut nichts, das Compare-and-set entscheidet den
 * Wettlauf, und ohne offene Zeile heilt `after` = zuletzt verlassene Frage.
 * Dazu die Vorschau-Sperre: mit Timer darf eine unbeantwortete Frage erst nach
 * Zeitablauf verlassen werden — sonst blättert ein Wegwerf-Spieler gratis durch
 * das ganze Deck. Und: wird die Person entfernt, während /next unterwegs ist,
 * bleibt keine Zeile ohne Spieler zurück (PaceService::assertStillJoined).
 */
#[CoversClass(PaceService::class)]
class PaceNextTest extends TestCase {

    private const NOW = 1_800_000_000;
    private const TOK = 'tok-anna';
    private const LIMIT = 20;

    private PaceService $service;
    private ProgressMapper&MockObject $progress;
    private VoteMapper&MockObject $votes;
    private PlayerMapper&MockObject $players;
    private RoomMapper&MockObject $rooms;

    /** @var Progress[] Zeilen der Person, wie findByRoomAndToken sie liefert */
    private array $rows = [];
    /** @var Poll[] aktuelles Deck */
    private array $deck = [];
    /** @var array<int, true> Fragen, die die Person beantwortet hat */
    private array $answered = [];
    private bool $hasPlayer = true;
    /** Findet die sperrende Nachprüfung nach start() den Spieler noch? */
    private bool $stillJoined = true;

    protected function setUp(): void {
        $this->deck = [$this->poll(11), $this->poll(12), $this->poll(13)];

        $this->progress = $this->createMock(ProgressMapper::class);
        $this->progress->method('findByRoomAndToken')->willReturnCallback(fn (): array => $this->rows);

        $this->votes = $this->createMock(VoteMapper::class);
        $this->votes->method('findByPollAndToken')->willReturnCallback(
            fn (int $pollId, string $token): Vote => isset($this->answered[$pollId]) ? new Vote() : throw new DoesNotExistException('keine Stimme'),
        );

        $this->players = $this->createMock(PlayerMapper::class);
        $this->players->method('findByRoomAndToken')->willReturnCallback(
            fn (): Player => $this->hasPlayer ? new Player() : throw new DoesNotExistException('kein Spieler'),
        );
        $this->players->method('existsForUpdate')->willReturnCallback(fn (): bool => $this->stillJoined);
        $this->rooms = $this->createMock(RoomMapper::class);

        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturnCallback(fn (): array => $this->deck);

        // /next sperrt den Raum nicht — alles läuft je Token über CAS und Unique-Index.
        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->never())->method('beginTransaction');

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->service = (new \ReflectionClass(PaceService::class))->newInstanceWithoutConstructor();
        foreach ([
            'roomMapper' => $this->rooms,
            'pollMapper' => $polls,
            'playerMapper' => $this->players,
            'progressMapper' => $this->progress,
            'voteMapper' => $this->votes,
            'presenceMapper' => $this->createMock(PresenceMapper::class),
            'roomService' => $this->createMock(RoomService::class),
            'db' => $db,
            'timeFactory' => $time,
            'l10n' => $l10n,
        ] as $name => $value) {
            (new ReflectionProperty(PaceService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── Protokoll ──────────────────────────────────────────────────────────

    public function testErstesNextStartetFrageEins(): void {
        $this->progress->expects($this->never())->method('closeIfOpen');
        $this->progress->expects($this->once())->method('start')
            ->with(5, 11, self::TOK, 0, self::NOW)->willReturn(true);

        $this->service->next($this->room(), self::TOK, 0);
    }

    public function testDoppeltesNextNullBeiOffenerErsterFrageTutNichts(): void {
        $this->rows = [$this->row(1, 11, 0, self::NOW - 2)];
        $this->progress->expects($this->never())->method('closeIfOpen');
        $this->progress->expects($this->never())->method('start');

        $this->service->next($this->room(), self::TOK, 0);
    }

    public function testBeantworteteOffeneFrageSchliesstDannStartetDieNaechste(): void {
        $this->rows = [$this->row(1, 11, 0, self::NOW - 5)];
        $this->answered[11] = true;
        $calls = [];
        $this->progress->expects($this->once())->method('closeIfOpen')->with(1, self::NOW)
            ->willReturnCallback(function () use (&$calls): bool {
                $calls[] = 'close';
                return true;
            });
        $this->progress->expects($this->once())->method('start')->with(5, 12, self::TOK, 1, self::NOW)
            ->willReturnCallback(function () use (&$calls): bool {
                $calls[] = 'start';
                return true;
            });

        $this->service->next($this->room(), self::TOK, 11);

        $this->assertSame(['close', 'start'], $calls);
    }

    public function testVerlorenesCompareAndSetStartetNichts(): void {
        // Ein anderer Tab hat die Zeile gerade geschlossen und startet selbst.
        $this->rows = [$this->row(1, 11, 0, self::NOW - 5)];
        $this->answered[11] = true;
        $this->progress->expects($this->once())->method('closeIfOpen')->willReturn(false);
        $this->progress->expects($this->never())->method('start');

        $this->service->next($this->room(), self::TOK, 11);
    }

    public function testSelbstheilungOhneOffeneZeileStartetDenNachfolger(): void {
        // Abbruch zwischen Schließen (Q1) und Starten (Q2).
        $this->rows = [$this->row(1, 11, 0, self::NOW - 30, self::NOW - 10)];
        $this->progress->expects($this->never())->method('closeIfOpen');
        $this->progress->expects($this->once())->method('start')
            ->with(5, 12, self::TOK, 1, self::NOW)->willReturn(true);

        $this->service->next($this->room(), self::TOK, 11);
    }

    public function testVeraltetesAfterOhneOffeneZeileTutNichts(): void {
        $this->rows = [
            $this->row(1, 11, 0, self::NOW - 30, self::NOW - 20),
            $this->row(2, 12, 1, self::NOW - 20, self::NOW - 10),
        ];
        $this->progress->expects($this->never())->method('closeIfOpen');
        $this->progress->expects($this->never())->method('start');

        $this->service->next($this->room(), self::TOK, 11); // nicht die zuletzt verlassene
        $this->service->next($this->room(), self::TOK, 0);  // „von vorn" gibt es nicht
        $this->service->next($this->room(), self::TOK, 13); // Zukunft
    }

    public function testVeraltetesAfterBeiOffenerZeileTutNichts(): void {
        $this->rows = [
            $this->row(1, 11, 0, self::NOW - 30, self::NOW - 20),
            $this->row(2, 12, 1, self::NOW - 20),
        ];
        $this->answered[12] = true;
        $this->progress->expects($this->never())->method('closeIfOpen');
        $this->progress->expects($this->never())->method('start');

        $this->service->next($this->room(), self::TOK, 11);
        $this->service->next($this->room(), self::TOK, 13);
    }

    public function testLetzteFrageWirdNurGeschlossen(): void {
        $this->rows = [
            $this->row(1, 11, 0, self::NOW - 30, self::NOW - 20),
            $this->row(2, 12, 1, self::NOW - 20, self::NOW - 10),
            $this->row(3, 13, 2, self::NOW - 10),
        ];
        $this->answered[13] = true;
        $this->progress->expects($this->once())->method('closeIfOpen')->with(3, self::NOW)->willReturn(true);
        $this->progress->expects($this->never())->method('start');

        $this->service->next($this->room(), self::TOK, 13);
    }

    public function testUniqueVerstossBeimStartenWirdGeschluckt(): void {
        // Paralleler Tab hat Q1 schon gestartet: start() meldet false, kein Fehler.
        $this->progress->expects($this->once())->method('start')->willReturn(false);

        $this->service->next($this->room(), self::TOK, 0);
    }

    // ── Abweisungen ────────────────────────────────────────────────────────

    public static function geschlosseneFenster(): array {
        return [
            'Entwurf' => [0, 0, 0, 0, 'The quiz has not started yet.'],
            'manuell geschlossen' => [self::NOW - 100, 0, self::NOW - 1, 0, 'The quiz is closed.'],
            'Frist abgelaufen' => [self::NOW - 100, self::NOW, 0, 0, 'The quiz is closed.'],
            'freigegeben' => [self::NOW - 100, 0, self::NOW - 1, self::NOW - 1, 'The quiz is closed.'],
        ];
    }

    #[DataProvider('geschlosseneFenster')]
    public function testNurBeiOffenemFenster(int $openedAt, int $closesAt, int $closedAt, int $releasedAt, string $message): void {
        $room = $this->room();
        $room->setOpenedAt($openedAt);
        $room->setClosesAt($closesAt);
        $room->setClosedAt($closedAt);
        $room->setReleasedAt($releasedAt);
        $this->progress->expects($this->never())->method('start');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->service->next($room, self::TOK, 0);
    }

    public function testOhneNamenKeinStart(): void {
        $this->hasPlayer = false;
        $this->progress->expects($this->never())->method('start');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please choose a name first.');
        $this->service->next($this->room(), self::TOK, 0);
    }

    public function testNegativesAfterIstUngueltig(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid request.');
        $this->service->next($this->room(), self::TOK, -1);
    }

    public function testModerierterRaumKenntKeinNext(): void {
        $room = $this->room();
        $room->setPace('live');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This room is not self-paced.');
        $this->service->next($room, self::TOK, 0);
    }

    // ── Vorschau-Sperre ────────────────────────────────────────────────────

    public function testMitTimerUnbeantwortetBisZumZeitablaufGesperrt(): void {
        // Grenze wie /vote: bei elapsed = limit läuft die Zeit noch.
        $this->rows = [$this->row(1, 11, 0, self::NOW - self::LIMIT)];
        $this->progress->expects($this->never())->method('closeIfOpen');
        $this->progress->expects($this->never())->method('start');

        $this->service->next($this->room(), self::TOK, 11);
    }

    public function testMitTimerUnbeantwortetNachZeitablaufWeiter(): void {
        $this->rows = [$this->row(1, 11, 0, self::NOW - self::LIMIT - 1)];
        $this->progress->expects($this->once())->method('closeIfOpen')->with(1, self::NOW)->willReturn(true);
        $this->progress->expects($this->once())->method('start')->with(5, 12, self::TOK, 1, self::NOW)->willReturn(true);

        $this->service->next($this->room(), self::TOK, 11);
    }

    public function testMitTimerBeantwortetSofortWeiter(): void {
        $this->rows = [$this->row(1, 11, 0, self::NOW)];
        $this->answered[11] = true;
        $this->progress->expects($this->once())->method('closeIfOpen')->willReturn(true);
        $this->progress->expects($this->once())->method('start')->with(5, 12, self::TOK, 1, self::NOW)->willReturn(true);

        $this->service->next($this->room(), self::TOK, 11);
    }

    public function testOhneTimerUnbeantwortetSofortWeiter(): void {
        $room = $this->room();
        $room->setTimed(false);
        $this->rows = [$this->row(1, 11, 0, self::NOW)];
        $this->progress->expects($this->once())->method('closeIfOpen')->willReturn(true);
        $this->progress->expects($this->once())->method('start')->with(5, 12, self::TOK, 1, self::NOW)->willReturn(true);

        $this->service->next($room, self::TOK, 11);
    }

    public function testGeloeschteOffeneFrageWirdOhneSperreVerlassen(): void {
        // Abwehr: die Frage der offenen Zeile gibt es nicht mehr — niemand bleibt hängen.
        $this->deck = [$this->poll(12), $this->poll(13)];
        $this->rows = [$this->row(1, 11, 0, self::NOW)];
        $this->progress->expects($this->once())->method('closeIfOpen')->with(1, self::NOW)->willReturn(true);
        $this->progress->expects($this->once())->method('start')->with(5, 12, self::TOK, 1, self::NOW)->willReturn(true);

        $this->service->next($this->room(), self::TOK, 11);
    }

    // ── Entfernt, während /next unterwegs war ──────────────────────────────

    public function testGestarteteZeileWirdGegenDasEntfernenGeprueft(): void {
        // Sperrend nachgelesen, aber ohne Raumsperre (der Normalfall bleibt je Token).
        $this->progress->method('start')->willReturn(true);
        $this->players->expects($this->once())->method('existsForUpdate')->with(5, self::TOK);
        $this->progress->expects($this->never())->method('deleteByRoomAndToken');

        $this->service->next($this->room(), self::TOK, 0);
    }

    public function testOhneNeueZeileKeineNachpruefung(): void {
        $this->progress->method('start')->willReturn(false);
        $this->players->expects($this->never())->method('existsForUpdate');

        $this->service->next($this->room(), self::TOK, 0);
    }

    public function testEntferntWaehrendDesStartsWirdDieZeileWiederGeloescht(): void {
        // removePlayer committete zwischen Spielerprüfung und start(): sonst
        // bliebe eine Zeile ohne Spieler, und das Cookie begänne nach dem
        // erneuten Beitritt mitten im Deck mit alter Uhr.
        $this->stillJoined = false;
        $this->progress->method('start')->willReturn(true);
        $this->allowLock();
        $this->progress->expects($this->once())->method('deleteByRoomAndToken')->with(5, self::TOK);
        $this->votes->expects($this->once())->method('deleteByPollsAndToken')->with([11, 12, 13], self::TOK);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please choose a name first.');
        $this->service->next($this->room(), self::TOK, 0);
    }

    // ── Helfer ─────────────────────────────────────────────────────────────

    /** Nur fürs Aufräumen nach dem Entfernen: Transaktion und Raumsperre zulassen. */
    private function allowLock(): void {
        (new ReflectionProperty(PaceService::class, 'db'))->setValue($this->service, $this->createMock(IDBConnection::class));
        $this->rooms->method('lockForUpdate')->willReturnCallback(fn (): Room => $this->room());
    }

    private function room(): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('ABCDEF');
        $room->setMode('quiz');
        $room->setPace('self');
        $room->setTimed(true);
        $room->setOpenedAt(self::NOW - 600);
        $room->setDeckOrder('[11,12,13]');
        return $room;
    }

    private function poll(int $id): Poll {
        $poll = new Poll();
        $poll->setId($id);
        $poll->setRoomId(5);
        $poll->setType('choice');
        $poll->setTimeLimit(self::LIMIT);
        return $poll;
    }

    private function row(int $id, int $pollId, int $seq, int $startedAt, int $leftAt = 0): Progress {
        $row = new Progress();
        $row->setId($id);
        $row->setRoomId(5);
        $row->setPollId($pollId);
        $row->setVoterToken(self::TOK);
        $row->setSeq($seq);
        $row->setStartedAt($startedAt);
        $row->setLeftAt($leftAt);
        return $row;
    }
}
