<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Player;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Progress;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\QuizService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\ICacheFactory;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * /vote im eigenen Tempo (VoteService::recordSelfVote).
 *
 * Statt des Cursors zählt die offene Fortschrittszeile der Person, statt
 * poll.startedAt ihre persönliche Uhr. Der Payload trägt `limit` und das
 * Korrekturfenster `fw` der ERSTEN Antwort — damit ist der Korrektur-Exploit
 * zu (erst tippen, Urteil nach 3 s ansehen, dann per keyboard:true mit 6 s
 * korrigieren). Freitext, den weder die Akzeptanz- noch die Ablehnliste
 * kennt, ist `pending` und bringt 0 Punkte, bis der Moderator bewertet.
 * Nach dem Speichern wird der Spieler nachgeprüft (mitten in der Anfrage
 * entfernt?) und bei Freitext der Schlüssel sperrend nachgelesen (mitten in
 * einer Bewertung gerechnet?); eine Freitext-Korrektur läuft wie das Bewerten
 * unter der Raumsperre. Der moderierte Pfad fasst die neuen Abhängigkeiten nie an.
 */
#[CoversClass(VoteService::class)]
class SelfVoteTest extends TestCase {

    private const NOW = 1_800_000_000;
    private const TOK = 'tok-anna';

    private VoteService $service;
    private VoteMapper&MockObject $votes;
    private PaceService&MockObject $pace;
    private int $now = self::NOW;

    /** Offene Zeile der Person, wie PaceService::openRow sie liefert. */
    private ?Progress $open = null;
    /** @var array<int, Poll> */
    private array $polls = [];
    private bool $hasPlayer = true;
    /** Vorhandene Stimme — dann scheitert insert() am Unique-Index. */
    private ?Vote $existing = null;
    private ?Vote $inserted = null;
    private ?Vote $updated = null;
    /**
     * Antwortschlüssel, den eine Bewertung direkt nach dem ersten Lesen der
     * Frage committet — spätere Lesezugriffe sehen ihn, das erste nicht.
     */
    private ?string $keyAfterRead = null;
    private int $locks = 0;

    protected function setUp(): void {
        $this->polls = [
            11 => $this->choicePoll(11),
            13 => $this->textPoll(13),
        ];

        $this->pace = $this->createMock(PaceService::class);
        $this->pace->method('openRow')->willReturnCallback(fn (): ?Progress => $this->open);
        $this->pace->method('locked')->willReturnCallback(function (Room $room, callable $fn): mixed {
            $this->locks++;
            return $fn($room);
        });

        $this->votes = $this->createMock(VoteMapper::class);
        $this->votes->method('insert')->willReturnCallback(function (Vote $vote): Vote {
            if ($this->existing !== null) {
                throw $this->uniqueViolation();
            }
            $this->inserted = $vote;
            return $vote;
        });
        $this->votes->method('findByPollAndToken')->willReturnCallback(
            fn (): Vote => $this->existing ?? $this->inserted ?? throw new DoesNotExistException('keine Stimme'),
        );
        $this->votes->method('update')->willReturnCallback(function (Vote $vote): Vote {
            $this->updated = $vote;
            return $vote;
        });

        $players = $this->createMock(PlayerMapper::class);
        $players->method('findByRoomAndToken')->willReturnCallback(
            fn (): Player => $this->hasPlayer ? new Player() : throw new DoesNotExistException('kein Spieler'),
        );

        $pollMapper = $this->createMock(PollMapper::class);
        $pollMapper->method('find')->willReturnCallback(function (int $id): Poll {
            $poll = $this->polls[$id] ?? throw new DoesNotExistException('keine Frage');
            $read = clone $poll;
            if ($this->keyAfterRead !== null) {
                $poll->setAnswerKey($this->keyAfterRead);
                $this->keyAfterRead = null;
            }
            return $read;
        });
        $pollMapper->method('findForUpdate')->willReturnCallback(
            fn (int $id): Poll => clone ($this->polls[$id] ?? throw new DoesNotExistException('keine Frage')),
        );

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        foreach ([
            'pollMapper' => $pollMapper,
            'voteMapper' => $this->votes,
            'playerMapper' => $players,
            'quizService' => new QuizService(),
            'timeFactory' => $time,
            'l10n' => $l10n,
            'paceService' => $this->pace,
            'progressMapper' => $this->createMock(ProgressMapper::class),
            'cacheFactory' => $this->createMock(ICacheFactory::class),
        ] as $name => $value) {
            (new ReflectionProperty(VoteService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── Welche Frage, welche Uhr ───────────────────────────────────────────

    public function testOhnePollIdBitteNeuLaden(): void {
        // Altes Bundle: ohne Cursor wüsste der Server nicht, welche Frage gemeint ist.
        $this->open = $this->row(11, self::NOW - 5);

        $this->assertRejected('Please reload the page.', fn () => $this->vote('BB', pollId: null));
    }

    public function testAndereFrageAlsDieOffeneZeileIstZu(): void {
        $this->open = $this->row(11, self::NOW - 5);

        $this->assertRejected('This question is closed.', fn () => $this->vote('BB', pollId: 12));
    }

    public function testOhneOffeneZeileIstDieFrageZu(): void {
        // Die Person hat die Frage schon verlassen (/next) oder nie erreicht.
        $this->assertRejected('This question is closed.', fn () => $this->vote('BB', pollId: 11));
    }

    public function testGeloeschteFrageDerOffenenZeileIstZu(): void {
        $this->open = $this->row(12, self::NOW - 5);

        $this->assertRejected('This question is closed.', fn () => $this->vote('BB', pollId: 12));
    }

    public function testZeitLaeuftAbDemPersoenlichenStart(): void {
        // poll.startedAt ist im eigenen Tempo bedeutungslos (moderiert wäre die Zeit längst um).
        $this->polls[11]->setStartedAt(self::NOW - 1000);
        $this->open = $this->row(11, self::NOW - 5);

        $this->vote('BB');

        $payload = $this->payload($this->inserted);
        $this->assertSame(5, $payload['elapsed']);
        $this->assertSame((new QuizService())->points(5, 20), $payload['points']);
        $this->assertSame(self::NOW, $this->inserted->getCreatedAt());
    }

    public function testNachDemLimitIstDieZeitUm(): void {
        $this->open = $this->row(11, self::NOW - 21);

        $this->assertRejected('Time is up.', fn () => $this->vote('BB'));
    }

    public function testGenauAmLimitGehtEsNoch(): void {
        // Dieselbe Grenze wie moderiert: elapsed > limit.
        $this->open = $this->row(11, self::NOW - 20);

        $this->vote('BB');

        $this->assertSame((int)(QuizService::BASE_POINTS / 2), $this->payload($this->inserted)['points']);
    }

    public function testOhneTimerKeinLimitUndFlachePunkte(): void {
        // Hausaufgabe: zwischen /next und Antwort kann eine Nacht liegen.
        $this->open = $this->row(11, self::NOW - 50_000);

        $this->vote('BB', room: $this->room(timed: false));

        $payload = $this->payload($this->inserted);
        $this->assertSame(0, $payload['limit']);
        $this->assertSame(QuizService::BASE_POINTS, $payload['points']);
        $this->assertSame(50_000, $payload['elapsed']);
    }

    public function testPayloadTraegtLimitUndFensterInFesterReihenfolge(): void {
        $this->open = $this->row(11, self::NOW - 5);

        $this->vote('AA');

        $this->assertSame(
            ['value' => 'AA', 'points' => 0, 'correct' => false, 'elapsed' => 5, 'limit' => 20, 'fw' => VoteService::FIX_WINDOW],
            $this->payload($this->inserted),
        );
    }

    public function testTastaturbedienungSpeichertDasLaengereFenster(): void {
        $this->open = $this->row(11, self::NOW - 5);

        $this->vote('AA', keyboard: true);

        $this->assertSame(VoteService::FIX_WINDOW_KEYBOARD, $this->payload($this->inserted)['fw']);
    }

    public function testUngueltigerWertWirdNichtGespeichert(): void {
        $this->open = $this->row(11, self::NOW - 5);
        $this->votes->expects($this->never())->method('insert');

        $this->assertRejected('No such option.', fn () => $this->vote('ZZ'));
    }

    // ── Korrektur ──────────────────────────────────────────────────────────

    public function testExploitKorrekturMitTastaturflagNachDemErstenFenster(): void {
        // Erste Antwort per Tipp (fw 3), Urteil nach 3 s gesehen, dann mit
        // keyboard:true (6 s) „korrigieren" — das Fenster der ERSTEN Antwort zählt.
        $this->now = self::NOW + 4;
        $this->open = $this->row(11, self::NOW - 5);
        $this->existing = $this->vote11(['value' => 'AA', 'points' => 0, 'correct' => false, 'elapsed' => 5, 'limit' => 20, 'fw' => 3], self::NOW);
        $this->votes->expects($this->never())->method('update');

        $this->assertRejected('You have already answered this question.', fn () => $this->vote('BB', keyboard: true));
    }

    public function testKorrekturImFensterBehaeltDasErsteFenster(): void {
        $this->now = self::NOW + 3;
        $this->open = $this->row(11, self::NOW - 5);
        $this->existing = $this->vote11(['value' => 'AA', 'points' => 0, 'correct' => false, 'elapsed' => 5, 'limit' => 20, 'fw' => 3], self::NOW);

        $this->vote('BB', keyboard: true);

        $this->assertSame($this->existing, $this->updated);
        $this->assertSame([
            'value' => 'BB',
            'points' => (new QuizService())->points(8, 20),
            'correct' => true,
            'elapsed' => 8,
            'limit' => 20,
            'fw' => 3,
            'fixed' => true,
        ], $this->payload($this->updated), 'neue Zeit, Fenster der ersten Antwort, als Korrektur vermerkt');
        $this->assertSame(self::NOW + 3, $this->updated->getCreatedAt());
    }

    public function testKorrekturMitLaengeremErstenFensterGehtNochBeiSechsSekunden(): void {
        $this->now = self::NOW + 6;
        $this->open = $this->row(11, self::NOW - 5);
        $this->existing = $this->vote11(['value' => 'AA', 'points' => 0, 'correct' => false, 'elapsed' => 5, 'limit' => 20, 'fw' => 6], self::NOW);

        $this->vote('BB');

        $this->assertSame(6, $this->payload($this->updated)['fw'], 'das Flag der Korrektur (Tipp) verkürzt es nicht');
    }

    public function testZweiteKorrekturWirdAbgelehnt(): void {
        $this->now = self::NOW + 1;
        $this->open = $this->row(11, self::NOW - 5);
        $this->existing = $this->vote11(['value' => 'BB', 'points' => 900, 'correct' => true, 'elapsed' => 5, 'limit' => 20, 'fw' => 3, 'fixed' => true], self::NOW);
        $this->votes->expects($this->never())->method('update');

        $this->assertRejected('You have already answered this question.', fn () => $this->vote('AA'));
    }

    public function testFehlendesFensterZaehltAlsDreiSekunden(): void {
        $this->now = self::NOW + 4;
        $this->open = $this->row(11, self::NOW - 5);
        $this->existing = $this->vote11(['value' => 'AA', 'points' => 0, 'correct' => false, 'elapsed' => 5], self::NOW);

        $this->assertRejected('You have already answered this question.', fn () => $this->vote('BB', keyboard: true));
    }

    public function testAndererDatenbankfehlerFliegtWeiter(): void {
        $this->open = $this->row(11, self::NOW - 5);
        $error = $this->createMock(Exception::class);
        $error->method('getReason')->willReturn(Exception::REASON_CONNECTION_LOST);
        $votes = $this->createMock(VoteMapper::class);
        $votes->method('insert')->willThrowException($error);
        $votes->expects($this->never())->method('update');
        (new ReflectionProperty(VoteService::class, 'voteMapper'))->setValue($this->service, $votes);

        $this->expectExceptionObject($error);
        $this->vote('BB');
    }

    // ── Freitext ───────────────────────────────────────────────────────────

    public static function freitexte(): array {
        return [
            // Antwort, correct, pending, Punkte > 0
            'unbekannt: wird geprüft' => ['Saturn', false, true, false],
            'abgelehnt: falsch, nicht offen' => [' MARS ', false, false, false],
            'angenommen: richtig' => [' jupiter ', true, false, true],
        ];
    }

    #[DataProvider('freitexte')]
    public function testFreitextUrteil(string $answer, bool $correct, bool $pending, bool $points): void {
        $this->open = $this->row(13, self::NOW - 5);

        $this->vote($answer, pollId: 13);

        $payload = $this->payload($this->inserted);
        $this->assertSame(['value', 'points', 'correct', 'elapsed', 'norm', 'pending', 'limit', 'fw'], array_keys($payload));
        $this->assertSame($correct, $payload['correct']);
        $this->assertSame($pending, $payload['pending']);
        $this->assertSame($points, $payload['points'] > 0);
    }

    // ── Freitext gegen gleichzeitiges Bewerten ─────────────────────────────

    public function testFreitextOhneBewertungDazwischenOhneSperre(): void {
        $this->open = $this->row(13, self::NOW - 5);
        $this->votes->expects($this->never())->method('update');

        $this->vote('Saturn', pollId: 13);

        $this->assertTrue($this->payload($this->inserted)['pending']);
        $this->assertSame(0, $this->locks);
    }

    public function testFreitextMitAltemSchluesselWirdNachgezogen(): void {
        // Gerechnet vor, gespeichert nach dem Durchgang der Bewertung: ohne
        // Nachlesen bliebe „Saturn" auf „wird geprüft" mit 0 Punkten.
        $this->open = $this->row(13, self::NOW - 5);
        $this->keyAfterRead = json_encode(['accepted' => ['Jupiter', 'Saturn'], 'rejected' => ['Mars']]);

        $this->vote('Saturn', pollId: 13);

        $this->assertSame(1, $this->locks, 'nachgerechnet unter der Raumsperre');
        $this->assertSame($this->inserted, $this->updated);
        $payload = $this->payload($this->updated);
        $this->assertTrue($payload['correct']);
        $this->assertFalse($payload['pending']);
        $this->assertSame((new QuizService())->points(5, 20), $payload['points']);
    }

    public function testFreitextAbgelehntWaehrendDerStimme(): void {
        $this->open = $this->row(13, self::NOW - 5);
        $this->keyAfterRead = json_encode(['accepted' => ['Jupiter'], 'rejected' => ['Mars', 'saturn']]);

        $this->vote('Saturn', pollId: 13);

        $payload = $this->payload($this->updated);
        $this->assertFalse($payload['correct']);
        $this->assertFalse($payload['pending'], 'bewertet ist nicht mehr offen');
        $this->assertSame(0, $payload['points']);
    }

    public function testFreitextKorrekturUnterDerSperreMitFrischemSchluessel(): void {
        // Die Bewertung von „Venus" committet, während die Korrektur unterwegs
        // ist: die Korrektur rechnet unter der Sperre mit dem neuen Schlüssel.
        $this->now = self::NOW + 1;
        $this->open = $this->row(13, self::NOW - 5);
        $this->existing = $this->vote11(['value' => 'Saturn', 'points' => 0, 'correct' => false, 'elapsed' => 5, 'norm' => 'saturn', 'pending' => true, 'limit' => 20, 'fw' => 3], self::NOW);
        $this->existing->setPollId(13);
        $this->keyAfterRead = json_encode(['accepted' => ['Jupiter', 'Venus'], 'rejected' => ['Mars']]);
        $this->pace->expects($this->never())->method('assertStillJoined');

        $this->vote('Venus', pollId: 13);

        $this->assertSame(1, $this->locks);
        $payload = $this->payload($this->updated);
        $this->assertSame(['Venus', true, false, true], [$payload['value'], $payload['correct'], $payload['pending'], $payload['fixed']]);
    }

    public function testKorrekturOhneFreitextOhneSperre(): void {
        $this->now = self::NOW + 1;
        $this->open = $this->row(11, self::NOW - 5);
        $this->existing = $this->vote11(['value' => 'AA', 'points' => 0, 'correct' => false, 'elapsed' => 5, 'limit' => 20, 'fw' => 3], self::NOW);

        $this->vote('BB');

        $this->assertSame(0, $this->locks);
        $this->assertTrue($this->payload($this->updated)['fixed']);
    }

    // ── Fenster und Spieler ────────────────────────────────────────────────

    public static function fensterZu(): array {
        return [
            // openedAt, closesAt, closedAt, releasedAt, Meldung
            'Entwurf' => [0, 0, 0, 0, 'The quiz has not started yet.'],
            'manuell geschlossen' => [self::NOW - 100, 0, self::NOW - 1, 0, 'The quiz is closed.'],
            'Frist abgelaufen (Grenzsekunde)' => [self::NOW - 100, self::NOW, 0, 0, 'The quiz is closed.'],
            'freigegeben' => [self::NOW - 100, 0, self::NOW - 1, self::NOW - 1, 'The quiz is closed.'],
        ];
    }

    #[DataProvider('fensterZu')]
    public function testNurImOffenenFenster(int $openedAt, int $closesAt, int $closedAt, int $releasedAt, string $message): void {
        $this->open = $this->row(11, self::NOW - 5);
        $room = $this->room();
        $room->setOpenedAt($openedAt);
        $room->setClosesAt($closesAt);
        $room->setClosedAt($closedAt);
        $room->setReleasedAt($releasedAt);
        $this->votes->expects($this->never())->method('insert');

        $this->assertRejected($message, fn () => $this->vote('BB', room: $room));
    }

    public function testFristInDerZukunftIstOffen(): void {
        $this->open = $this->row(11, self::NOW - 5);
        $room = $this->room();
        $room->setClosesAt(self::NOW + 1);

        $this->vote('BB', room: $room);

        $this->assertNotNull($this->inserted);
    }

    public function testNachDemSpeichernWirdDerSpielerNachgeprueft(): void {
        $this->open = $this->row(11, self::NOW - 5);
        $this->pace->expects($this->once())->method('assertStillJoined')
            ->with($this->isInstanceOf(Room::class), self::TOK);

        $this->vote('BB');
    }

    public function testMittenInDerStimmeEntferntIstSieWiederWeg(): void {
        // assertStillJoined hat geräumt und meldet dasselbe wie eine Entfernung
        // Millisekunden früher.
        $this->open = $this->row(11, self::NOW - 5);
        $this->pace->method('assertStillJoined')
            ->willThrowException(new \InvalidArgumentException('Please choose a name first.'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please choose a name first.');
        $this->vote('BB');
    }

    public function testOhneNamenKeineStimme(): void {
        $this->hasPlayer = false;
        $this->open = $this->row(11, self::NOW - 5);

        $this->assertRejected('Please choose a name first.', fn () => $this->vote('BB'));
    }

    // ── Moderiert unverändert ──────────────────────────────────────────────

    public function testModeriertFasstDieNeuenAbhaengigkeitenNieAn(): void {
        $pace = $this->createMock(PaceService::class);
        $pace->expects($this->never())->method($this->anything());
        $progress = $this->createMock(ProgressMapper::class);
        $progress->expects($this->never())->method($this->anything());
        $cache = $this->createMock(ICacheFactory::class);
        $cache->expects($this->never())->method($this->anything());
        foreach (['paceService' => $pace, 'progressMapper' => $progress, 'cacheFactory' => $cache] as $name => $value) {
            (new ReflectionProperty(VoteService::class, $name))->setValue($this->service, $value);
        }
        $room = $this->room();
        $room->setPace('live');
        $room->setActivePollId(11);
        $this->polls[11]->setStatus('active');
        $this->polls[11]->setStartedAt(self::NOW - 5);

        $this->service->recordVote($room, self::TOK, 'BB', false, 11);

        // Moderierter Payload wie bisher: ohne limit/fw.
        $this->assertSame(
            ['value' => 'BB', 'points' => (new QuizService())->points(5, 20), 'correct' => true, 'elapsed' => 5],
            $this->payload($this->inserted),
        );
    }

    // ── Helfer ─────────────────────────────────────────────────────────────

    private function vote(mixed $value, ?int $pollId = 11, bool $keyboard = false, ?Room $room = null): void {
        $this->service->recordVote($room ?? $this->room(), self::TOK, $value, $keyboard, $pollId);
    }

    private function assertRejected(string $message, callable $fn): void {
        try {
            $fn();
            $this->fail('InvalidArgumentException erwartet: ' . $message);
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
        }
        $this->assertNull($this->inserted, 'nichts gespeichert');
    }

    private function payload(?Vote $vote): array {
        $this->assertNotNull($vote, 'Stimme gespeichert');
        return json_decode($vote->getPayload(), true);
    }

    private function room(bool $timed = true): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('AB12CD');
        $room->setMode('quiz');
        $room->setPace('self');
        $room->setOpenedAt(self::NOW - 100);
        $room->setTimed($timed);
        $room->setDeckOrder('[11,12,13]');
        return $room;
    }

    private function row(int $pollId, int $startedAt): Progress {
        $row = new Progress();
        $row->setId(1);
        $row->setRoomId(5);
        $row->setPollId($pollId);
        $row->setVoterToken(self::TOK);
        $row->setStartedAt($startedAt);
        return $row;
    }

    private function choicePoll(int $id): Poll {
        $poll = new Poll();
        $poll->setId($id);
        $poll->setRoomId(5);
        $poll->setType('choice');
        $poll->setStatus('draft');
        $poll->setTimeLimit(20);
        $poll->setOptions(json_encode([['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B']]));
        $poll->setCorrectOption('BB');
        return $poll;
    }

    private function textPoll(int $id): Poll {
        $poll = new Poll();
        $poll->setId($id);
        $poll->setRoomId(5);
        $poll->setType('text');
        $poll->setStatus('draft');
        $poll->setTimeLimit(20);
        $poll->setOptions('[]');
        $poll->setAnswerKey(json_encode(['accepted' => ['Jupiter'], 'rejected' => ['Mars']]));
        return $poll;
    }

    private function vote11(array $payload, int $createdAt): Vote {
        $vote = new Vote();
        $vote->setPollId(11);
        $vote->setVoterToken(self::TOK);
        $vote->setPayload(json_encode($payload));
        $vote->setCreatedAt($createdAt);
        return $vote;
    }

    /** Der UNIQUE-Verstoß, mit dem die Datenbank die zweite Antwort ablehnt. */
    private function uniqueViolation(): Exception {
        $e = $this->createMock(Exception::class);
        $e->method('getReason')->willReturn(Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
        return $e;
    }
}
