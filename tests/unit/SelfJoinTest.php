<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Player;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Progress;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Beitritt im eigenen Tempo (VoteService::quizJoin -> selfJoin).
 *
 * Läuft unter der Raumsperre (PaceService::locked) und prüft an der frisch
 * gesperrten Zeile: nach dem Schluss kein Beitritt mehr (die nächste Gruppe
 * käme sonst an Endstand und Lösungen), „Beitritt sperren" und der Deckel von
 * 300 treffen nur neue Tokens, und nach dem Start ist der Name fest — sonst
 * schlüpfte ein Token nach dem Antworten in einen frei gewordenen Namen.
 * Ein neues Token beginnt leer: Reste eines entfernten Spielers mit demselben
 * Cookie erbt es nicht. Der moderierte Beitritt fasst PaceService nie an.
 */
#[CoversClass(VoteService::class)]
class SelfJoinTest extends TestCase {

    private const NOW = 1_800_000_000;

    private VoteService $service;
    private PlayerMapper&MockObject $players;
    private PaceService&MockObject $pace;

    /** Was PaceService::locked an den Rückruf gibt — die maßgebliche Zeile. */
    private Room $locked;
    /** @var Player[] */
    private array $roster = [];
    /** @var array<string, Progress[]> Fortschritt je Token */
    private array $progress = [];
    private int $lockCalls = 0;

    protected function setUp(): void {
        $this->locked = $this->room(self::NOW - 100);
        $this->roster = [$this->player('tok-anna', 'Anna'), $this->player('tok-ben', 'Ben')];

        $this->pace = $this->createMock(PaceService::class);
        $this->pace->method('locked')->willReturnCallback(function (Room $room, callable $fn): mixed {
            $this->lockCalls++;
            return $fn($this->locked);
        });

        $this->players = $this->createMock(PlayerMapper::class);
        $this->players->method('findByRoom')->willReturnCallback(fn (): array => $this->roster);

        $progress = $this->createMock(ProgressMapper::class);
        $progress->method('findByRoomAndToken')->willReturnCallback(
            fn (int $roomId, string $token): array => $this->progress[$token] ?? [],
        );

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        foreach ([
            'playerMapper' => $this->players,
            'timeFactory' => $time,
            'l10n' => $l10n,
            'paceService' => $this->pace,
            'progressMapper' => $progress,
            'cacheFactory' => $this->createMock(ICacheFactory::class),
        ] as $name => $value) {
            (new ReflectionProperty(VoteService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── Fensterzustand ─────────────────────────────────────────────────────

    public static function zu(): array {
        return [
            // closesAt, closedAt, releasedAt
            'manuell geschlossen' => [0, self::NOW - 1, 0],
            'Frist abgelaufen' => [self::NOW, 0, 0],
            'freigegeben' => [0, self::NOW - 1, self::NOW - 1],
        ];
    }

    #[DataProvider('zu')]
    public function testNachDemSchlussKeinBeitritt(int $closesAt, int $closedAt, int $releasedAt): void {
        $this->locked = $this->room(self::NOW - 100, $closesAt, $closedAt, $releasedAt);

        $this->assertRejected('The quiz is closed.', 'tok-neu', 'Cem');
    }

    public function testBekannteSpielerNachDemSchlussAuchNicht(): void {
        $this->locked = $this->room(self::NOW - 100, 0, self::NOW - 1);

        $this->assertRejected('The quiz is closed.', 'tok-anna', 'Anna');
    }

    public function testImEntwurfErlaubt(): void {
        $this->locked = $this->room(0);
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');
    }

    public function testImOffenenFensterErlaubt(): void {
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');
    }

    public function testZustandZaehltAnDerGesperrtenZeile(): void {
        // Der übergebene Raum ist veraltet (offen); gesperrt gelesen ist er schon zu.
        $this->locked = $this->room(self::NOW - 100, 0, self::NOW - 1);
        $this->players->expects($this->never())->method('register');

        try {
            $this->service->quizJoin($this->room(self::NOW - 100), 'tok-neu', 'Cem');
            $this->fail('InvalidArgumentException erwartet');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('The quiz is closed.', $e->getMessage());
        }
        $this->assertSame(1, $this->lockCalls);
    }

    public function testLaeuftUnterDerRaumsperre(): void {
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');

        $this->assertSame(1, $this->lockCalls);
    }

    // ── Beitritt sperren, Deckel ───────────────────────────────────────────

    public function testGesperrtNeuesTokenWirdAbgewiesen(): void {
        $this->locked->setJoinsLocked(true);

        $this->assertRejected('Joining is closed for this quiz.', 'tok-neu', 'Cem');
    }

    public function testGesperrtBekanntesTokenKommtWeiterRein(): void {
        // Handy neu geladen, Name erneut bestätigt.
        $this->locked->setJoinsLocked(true);
        $this->expectRegister('tok-anna', 'Anna');

        $this->join('tok-anna', 'Anna');
    }

    public function testGesperrtVorDerNamenspruefung(): void {
        // Ein Wegwerf-Token erfährt nicht einmal, welche Namen vergeben sind.
        $this->locked->setJoinsLocked(true);

        $this->assertRejected('Joining is closed for this quiz.', 'tok-neu', 'anna');
    }

    public function testVollerRaumWeistNeueTokensAb(): void {
        $this->roster = $this->manyPlayers(PaceService::MAX_PLAYERS);

        $this->assertRejected('This quiz is full.', 'tok-neu', 'Cem');
    }

    public function testEinPlatzFreiGehtNoch(): void {
        $this->roster = $this->manyPlayers(PaceService::MAX_PLAYERS - 1);
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');
    }

    public function testVollerRaumLaesstBekannteSpielerRein(): void {
        $this->roster = $this->manyPlayers(PaceService::MAX_PLAYERS);
        $this->expectRegister('tok-7', 'P7');

        $this->join('tok-7', 'P7');
    }

    // ── Name nach dem Start ────────────────────────────────────────────────

    public function testUmbenennenNachDemStartWirdAbgelehnt(): void {
        $this->progress['tok-anna'] = [$this->progressRow('tok-anna')];

        $this->assertRejected('You can\'t change your name after starting.', 'tok-anna', 'Anna2');
    }

    public function testUmbenennenVorDemStartIstErlaubt(): void {
        $this->expectRegister('tok-anna', 'Annika');

        $this->join('tok-anna', 'Annika');
    }

    public function testGrossKleinAmEigenenNamenBleibtNachDemStartAenderbar(): void {
        $this->progress['tok-anna'] = [$this->progressRow('tok-anna')];
        $this->expectRegister('tok-anna', 'ANNA');

        $this->join('tok-anna', 'ANNA');
    }

    public function testNamensdubletteWirdAbgelehnt(): void {
        $this->assertRejected('This name is already taken. Please choose another one.', 'tok-neu', ' anna ');
    }

    public function testUmbenennenAufFremdenNamenWirdAbgelehnt(): void {
        $this->assertRejected('This name is already taken. Please choose another one.', 'tok-ben', 'ANNA');
    }

    public function testLeererNameWirdAbgelehnt(): void {
        $this->assertRejected('Please enter a name.', 'tok-neu', "\u{200B} ");
    }

    // ── Neue Identität beginnt leer ────────────────────────────────────────

    public function testNeuesTokenImOffenenFensterBeginntLeer(): void {
        // Etwa das Cookie einer entfernten Person: was von ihr noch liegt, weg —
        // unter der Sperre und vor dem Eintragen, Frage 1 mit neuer Uhr.
        $calls = [];
        $this->pace->expects($this->once())->method('forgetToken')
            ->with($this->identicalTo($this->locked), 'tok-neu')
            ->willReturnCallback(function () use (&$calls): void {
                $calls[] = 'forget';
            });
        $this->players->expects($this->once())->method('register')
            ->willReturnCallback(function () use (&$calls): Player {
                $calls[] = 'register';
                return $this->player('tok-neu', 'Cem');
            });

        $this->join('tok-neu', 'Cem');

        $this->assertSame(['forget', 'register'], $calls);
    }

    public function testBekanntesTokenBehaeltSeinenStand(): void {
        $this->pace->expects($this->never())->method('forgetToken');
        $this->expectRegister('tok-anna', 'Anna');

        $this->join('tok-anna', 'Anna');
    }

    public function testImEntwurfGibtEsNichtsZuVergessen(): void {
        $this->locked = $this->room(0);
        $this->pace->expects($this->never())->method('forgetToken');
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');
    }

    public function testAbgewiesenVergisstNichts(): void {
        $this->locked->setJoinsLocked(true);
        $this->pace->expects($this->never())->method('forgetToken');

        $this->assertRejected('Joining is closed for this quiz.', 'tok-neu', 'Cem');
    }

    // ── Moderiert unverändert ──────────────────────────────────────────────

    public function testModeriertFasstPaceServiceNieAn(): void {
        $pace = $this->createMock(PaceService::class);
        $pace->expects($this->never())->method($this->anything());
        $progress = $this->createMock(ProgressMapper::class);
        $progress->expects($this->never())->method($this->anything());
        (new ReflectionProperty(VoteService::class, 'paceService'))->setValue($this->service, $pace);
        (new ReflectionProperty(VoteService::class, 'progressMapper'))->setValue($this->service, $progress);
        // Moderiert gibt es weder Deckel noch Sperre: auch 300 Spieler + Flag lassen neue rein.
        $this->roster = $this->manyPlayers(PaceService::MAX_PLAYERS);
        $room = $this->room(0);
        $room->setPace('live');
        $room->setJoinsLocked(true);
        $this->expectRegister('tok-neu', 'Cem');

        $this->service->quizJoin($room, 'tok-neu', 'Cem');
    }

    // ── Helfer ─────────────────────────────────────────────────────────────

    private function join(string $token, string $nickname): Player {
        return $this->service->quizJoin($this->room(self::NOW - 100), $token, $nickname);
    }

    private function assertRejected(string $message, string $token, string $nickname): void {
        $this->players->expects($this->never())->method('register');
        try {
            $this->join($token, $nickname);
            $this->fail('InvalidArgumentException erwartet: ' . $message);
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function expectRegister(string $token, string $nickname): void {
        $this->players->expects($this->once())->method('register')
            ->with(5, $token, $nickname, self::NOW)
            ->willReturn($this->player($token, $nickname));
    }

    private function room(int $openedAt, int $closesAt = 0, int $closedAt = 0, int $releasedAt = 0): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('AB12CD');
        $room->setMode('quiz');
        $room->setPace('self');
        $room->setOpenedAt($openedAt);
        $room->setClosesAt($closesAt);
        $room->setClosedAt($closedAt);
        $room->setReleasedAt($releasedAt);
        if ($openedAt > 0) {
            $room->setDeckOrder('[11,12]');
        }
        return $room;
    }

    /** @return Player[] */
    private function manyPlayers(int $n): array {
        return array_map(fn (int $i): Player => $this->player('tok-' . $i, 'P' . $i), range(1, $n));
    }

    private function player(string $token, string $nickname): Player {
        $player = new Player();
        $player->setRoomId(5);
        $player->setVoterToken($token);
        $player->setNickname($nickname);
        return $player;
    }

    private function progressRow(string $token): Progress {
        $row = new Progress();
        $row->setRoomId(5);
        $row->setPollId(11);
        $row->setVoterToken($token);
        $row->setStartedAt(self::NOW - 10);
        return $row;
    }
}
