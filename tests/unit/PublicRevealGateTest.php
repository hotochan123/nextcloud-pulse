<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\RoomService;
use OCA\Pulse\Service\StateService;
use OCA\Pulse\Service\TallyService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Freigabe-Schranken der öffentlichen Sichten, zweite Runde.
 *
 * - Rangliste der Gesamtauswertung ohne die laufende, noch verdeckte Frage
 *   (sonst zeigt der Punktestand nach dem Antippen, ob es richtig war).
 * - „Quiz vorbei" gilt nur, solange nichts Neues läuft: eine liegengebliebene
 *   'ended'-Frage aus einem früheren Lauf löst nichts mehr auf.
 * - Alte Umfrage-Räume (vor dem Startzeitpunkt): Stimmen belegen „gezeigt".
 * - Verdeckte Quizfragen: der Status bleibt echt ('locked' bleibt 'locked'),
 *   ob aufgelöst ist, sagt allein `revealed`. Die Folge von Rangfolge-Optionen
 *   und Zuordnungs-Zielen hängt nur an Geheimnis + Frage + Option — nicht an
 *   der gespeicherten Folge, also nicht an der Lösung.
 */
#[CoversClass(StateService::class)]
class PublicRevealGateTest extends TestCase {

    private PollMapper&MockObject $polls;
    private VoteService&MockObject $voteService;
    private StateService $service;
    /** Die Frage, die publicState über find() bekommt. */
    private ?Poll $current = null;
    /** @var array<int,int> Stimmen je Frage (countByPoll) */
    private array $voteCounts = [];
    /** @var array<string,Vote> eigene Stimme je Token (findByPollAndToken) */
    private array $myVotes = [];

    protected function setUp(): void {
        $this->polls = $this->createMock(PollMapper::class);
        $this->polls->method('find')->willReturnCallback(fn (): Poll => $this->current);

        $votes = $this->createMock(VoteMapper::class);
        $votes->method('findByPoll')->willReturn([]);
        $votes->method('findByPollAndToken')->willReturnCallback(
            fn (int $pollId, string $token): Vote => $this->myVotes[$token] ?? throw new DoesNotExistException('keine Stimme'),
        );
        $votes->method('countByPoll')->willReturnCallback(fn (int $id): int => $this->voteCounts[$id] ?? 0);

        $this->voteService = $this->createMock(VoteService::class);
        $this->voteService->method('playerNickname')->willReturn(null);

        $roomService = $this->createMock(RoomService::class);
        $roomService->method('presentCount')->willReturn(0);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1000);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->willReturnCallback(
            static fn (string $key, string $default = ''): string => $key === 'secret' ? 'test-secret' : $default,
        );

        $this->service = (new \ReflectionClass(StateService::class))->newInstanceWithoutConstructor();
        foreach ([
            'pollMapper' => $this->polls,
            'voteMapper' => $votes,
            'tallyService' => new TallyService(),
            'voteService' => $this->voteService,
            'roomService' => $roomService,
            'timeFactory' => $time,
            'l10n' => $l10n,
            'config' => $config,
        ] as $name => $value) {
            (new ReflectionProperty(StateService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── S2: Rangliste ohne die laufende, verdeckte Frage ───────────────────

    public function testSummaryRanglisteLaesstLaufendeVerdeckteFrageAus(): void {
        // Je Frage: 1 aufgelöst, 2 läuft (noch nicht aufgelöst).
        $room = $this->room('quiz', active: 2);
        $this->deck(
            $this->poll(1, 'choice', 'locked', 900),
            $this->poll(2, 'choice', 'active', 950),
        );
        $this->voteService->expects($this->once())->method('leaderboardFor')
            ->with($room, 'tok-a', [2])
            ->willReturn([]);

        $this->assertSame([], $this->service->publicSummary($room, 'tok-a')['leaderboard']);
    }

    public function testSummaryRanglisteMitAufgeloesterLaufenderFrageVollstaendig(): void {
        $room = $this->room('quiz', active: 2);
        $this->deck(
            $this->poll(1, 'choice', 'locked', 900),
            $this->poll(2, 'choice', 'locked', 950),
        );
        $this->voteService->expects($this->once())->method('leaderboardFor')
            ->with($room, 'tok-a', [])
            ->willReturn([]);

        $this->service->publicSummary($room, 'tok-a');
    }

    public function testSummaryRanglisteInDerLobbyOhneUebersprungeneFrage(): void {
        // Frage 2 lief, wurde aber nie aufgelöst (übersprungen), dann Lobby:
        // ihre Punkte verrieten sonst richtig/falsch.
        $room = $this->room('quiz', active: 0);
        $this->deck(
            $this->poll(1, 'choice', 'locked', 900),
            $this->poll(2, 'choice', 'active', 950),
        );
        $this->voteService->expects($this->once())->method('leaderboardFor')
            ->with($room, null, [2])
            ->willReturn([]);

        $this->service->publicSummary($room, null);
    }

    public function testSummaryRanglisteUebersprungeneFrageNebenLaufenderFrage(): void {
        // Frage 2 übersprungen (nie aufgelöst), Frage 3 läuft aufgelöst:
        // beide verdeckten Fragen fehlen, nicht nur die laufende.
        $room = $this->room('quiz', active: 3);
        $this->deck(
            $this->poll(1, 'choice', 'locked', 900),
            $this->poll(2, 'choice', 'active', 950),
            $this->poll(3, 'choice', 'active', 990),
        );
        $this->voteService->expects($this->once())->method('leaderboardFor')
            ->with($room, null, [2, 3])
            ->willReturn([]);

        $this->service->publicSummary($room, null);
    }

    public function testSummaryRanglisteNachEndeJeFrageZaehltAlles(): void {
        // Endstand je Frage: die letzte Frage ist beendet, der Cursor steht in
        // der Lobby. Auch eine übersprungene Frage zählt jetzt — wie beim
        // Moderator und auf dem Beamer.
        $room = $this->room('quiz', active: 0);
        $this->deck(
            $this->poll(1, 'choice', 'locked', 900),
            $this->poll(2, 'choice', 'active', 950),
            $this->poll(3, 'choice', 'ended', 990),
        );
        $this->voteService->expects($this->once())->method('leaderboardFor')
            ->with($room, null, [])
            ->willReturn([]);

        $this->service->publicSummary($room, null);
    }

    public function testSummaryRanglisteAmEndeVollstaendig(): void {
        // „Auflösung am Ende" nach /end: die laufende Frage ist die beendete,
        // alles ist aufgelöst — nichts auszulassen.
        $room = $this->room('quiz', active: 2, revealAtEnd: true);
        $this->deck(
            $this->poll(1, 'choice', 'locked', 900),
            $this->poll(2, 'choice', 'ended', 950),
        );
        $this->voteService->expects($this->once())->method('leaderboardFor')
            ->with($room, null, [])
            ->willReturn([]);

        $this->service->publicSummary($room, null);
    }

    public function testSummaryOhneAufgeloesteFrageFragtKeineRanglisteAb(): void {
        $room = $this->room('quiz', active: 1);
        $this->deck($this->poll(1, 'choice', 'active', 900));
        $this->voteService->expects($this->never())->method('leaderboardFor');

        $summary = $this->service->publicSummary($room, null);

        $this->assertFalse($summary['available']);
        $this->assertNull($summary['leaderboard']);
    }

    // ── S2: liegengebliebenes 'ended' ──────────────────────────────────────

    public function testLiegengebliebenesEndeLoestBeiNeuemLaufNichtsAuf(): void {
        // Erster Lauf endete auf Frage 1 ('ended'); der zweite Lauf steht auf
        // Frage 2. Die Gesamtauswertung darf deshalb NICHT das ganze Deck samt
        // Lösungen freigeben.
        $room = $this->room('quiz', active: 2, revealAtEnd: true);
        $this->deck(
            $this->poll(1, 'choice', 'ended', 900, [['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B']]),
            $this->poll(2, 'choice', 'active', 950),
        );
        $this->voteService->expects($this->never())->method('leaderboardFor');

        $summary = $this->service->publicSummary($room, null);

        $this->assertFalse($summary['available']);
        $this->assertNull($summary['leaderboard']);
        foreach ($summary['items'] as $item) {
            $this->assertFalse($item['revealed'], 'Frage ' . $item['poll']['id']);
            $this->assertFalse($item['poll']['revealed'], 'auch im Poll selbst, Frage ' . $item['poll']['id']);
            $this->assertNull($item['results']);
            $this->assertArrayNotHasKey('answerKey', $item['poll']);
            $this->assertArrayNotHasKey('correctOption', $item['poll']);
        }
    }

    public function testEndeGiltInDerLobby(): void {
        // Nach /end und „Zurück ins Deck" (Cursor 0) bleibt der Endstand sichtbar.
        $room = $this->room('quiz', active: 0, revealAtEnd: true);
        $this->deck(
            $this->poll(1, 'choice', 'locked', 900),
            $this->poll(2, 'choice', 'ended', 950),
        );
        $this->voteService->method('leaderboardFor')->willReturn([]);

        $summary = $this->service->publicSummary($room, null);

        $this->assertTrue($summary['available']);
        $this->assertSame([true, true], array_column($summary['items'], 'revealed'));
    }

    // ── S3: alte Umfrage-Räume ─────────────────────────────────────────────

    public function testUmfrageFrageMitStimmenOhneStartzeitpunktIstGezeigt(): void {
        // Vor v0.18.x setzte die Umfrage keinen Startzeitpunkt. Stimmen gibt es
        // nur auf einer gezeigten Frage — also gehört Frage 1 hinein, Frage 3
        // (ohne Stimmen, nie dran) nicht.
        $room = $this->room('poll', active: 2);
        $this->deck(
            $this->poll(1, 'choice', 'active', 0),
            $this->poll(2, 'choice', 'active', 0),
            $this->poll(3, 'choice', 'active', 0),
        );
        $this->voteCounts = [1 => 4];

        $summary = $this->service->publicSummary($room, null);

        $this->assertSame([1, 2], array_map(static fn (array $i): int => $i['poll']['id'], $summary['items']));
    }

    public function testQuizFrageMitStimmenOhneStartzeitpunktBleibtDraussen(): void {
        // Im Quiz setzt setCurrent den Zeitpunkt schon immer — dort sind Stimmen
        // kein Beleg (und werden gar nicht erst gezählt).
        $room = $this->room('quiz', active: 2);
        $this->deck(
            $this->poll(1, 'choice', 'active', 0),
            $this->poll(2, 'choice', 'active', 950),
        );
        $this->voteCounts = [1 => 4];
        $this->voteService->method('leaderboardFor')->willReturn([]);

        $summary = $this->service->publicSummary($room, null);

        $this->assertSame([2], array_map(static fn (array $i): int => $i['poll']['id'], $summary['items']));
    }

    // ── S4: verdeckt heißt verdeckt ────────────────────────────────────────

    public function testRangfolgeHaengtNichtVonDerLoesungAb(): void {
        // Dieselbe Frage (ID 7, dieselben Options-IDs) mit drei verschiedenen
        // Lösungen gespeichert: ausgeliefert wird jedes Mal dieselbe Folge. Die
        // Anzeige trägt also keine Information über die Lösung — auch dann
        // nicht, wenn sie (wie bei der dritten) zufällig die Lösung trifft.
        $room = $this->room('quiz', active: 7);
        $erster = ['id' => 'AA01', 'label' => 'Erster'];
        $zweiter = ['id' => 'BB02', 'label' => 'Zweiter'];
        $dritter = ['id' => 'CC03', 'label' => 'Dritter'];

        $served = [];
        foreach ([[$erster, $zweiter, $dritter], [$dritter, $erster, $zweiter], [$zweiter, $dritter, $erster]] as $loesung) {
            $served[] = $this->stateFor($room, $this->poll(7, 'rank', 'active', 900, $loesung))['poll']['options'];
        }

        $this->assertSame($served[0], $served[1]);
        $this->assertSame($served[0], $served[2]);
        $this->assertSame(['BB02', 'CC03', 'AA01'], array_column($served[0], 'id'));
    }

    public function testZweiOptionenVerratenNichts(): void {
        // Früher kam bei zwei Optionen immer genau die Umkehrung heraus — die
        // Lösung stand damit fest. Jetzt ist die Anzeige für beide möglichen
        // Lösungen dieselbe: eine von beiden wird in Lösungsfolge gezeigt.
        $room = $this->room('quiz', active: 7);
        $a = ['id' => 'AA01', 'label' => 'Erster'];
        $b = ['id' => 'BB02', 'label' => 'Zweiter'];

        $ab = $this->stateFor($room, $this->poll(7, 'rank', 'active', 900, [$a, $b]))['poll']['options'];
        $ba = $this->stateFor($room, $this->poll(7, 'rank', 'active', 900, [$b, $a]))['poll']['options'];

        $this->assertSame($ab, $ba);
        $this->assertContains(array_column($ab, 'id'), [['AA01', 'BB02'], ['BB02', 'AA01']]);
    }

    public function testZuordnungZieleHaengenNichtVonDerLoesungAb(): void {
        // Zwei Lösungen für dieselben Items/Ziele (Ziel i gehört zu Item i):
        // die Ziele kommen gleich heraus, die Items behalten jeweils ihre Folge.
        $room = $this->room('quiz', active: 7);
        $items = [['id' => 'IT01', 'label' => 'Hund'], ['id' => 'IT02', 'label' => 'Katze'], ['id' => 'IT03', 'label' => 'Kuh']];
        $bellt = ['id' => 'TA01', 'label' => 'bellt'];
        $miaut = ['id' => 'TB02', 'label' => 'miaut'];
        $muht = ['id' => 'TC03', 'label' => 'muht'];

        $richtig = $this->stateFor($room, $this->poll(7, 'match', 'active', 900, ['items' => $items, 'targets' => [$bellt, $miaut, $muht]]));
        $anders = $this->stateFor($room, $this->poll(7, 'match', 'active', 900, ['items' => $items, 'targets' => [$miaut, $muht, $bellt]]));

        $this->assertSame($richtig['poll']['match']['targets'], $anders['poll']['match']['targets']);
        $this->assertSame(['IT01', 'IT02', 'IT03'], array_column($richtig['poll']['match']['items'], 'id'), 'Items bleiben');
        $this->assertSame(['IT01', 'IT02', 'IT03'], array_column($anders['poll']['match']['items'], 'id'), 'Items bleiben');
    }

    /** @return array<string, array{bool, string}> [revealAtEnd, Status] */
    public static function aufgeloesteStaende(): array {
        return [
            'je Frage, gesperrt' => [false, 'locked'],
            'je Frage, beendet' => [false, 'ended'],
            'am Ende, beendet' => [true, 'ended'],
        ];
    }

    #[DataProvider('aufgeloesteStaende')]
    public function testAufgeloestKommtInGespeicherterFolge(bool $revealAtEnd, string $status): void {
        // Gewählt so, dass die gemischte Folge NICHT die gespeicherte ist —
        // sonst bewiese der Test nichts.
        $this->voteService->method('leaderboardFor')->willReturn([]);
        $room = $this->room('quiz', active: 7, revealAtEnd: $revealAtEnd);

        $rank = $this->stateFor($room, $this->poll(7, 'rank', $status, 900, [
            ['id' => 'AA01', 'label' => 'Erster'],
            ['id' => 'BB02', 'label' => 'Zweiter'],
            ['id' => 'CC03', 'label' => 'Dritter'],
        ]));
        $this->assertTrue($rank['poll']['revealed']);
        $this->assertSame(['AA01', 'BB02', 'CC03'], array_column($rank['poll']['options'], 'id'));

        $match = $this->stateFor($room, $this->poll(7, 'match', $status, 900, [
            'items' => [['id' => 'IT01', 'label' => 'Hund'], ['id' => 'IT02', 'label' => 'Katze'], ['id' => 'IT03', 'label' => 'Kuh']],
            'targets' => [['id' => 'TC03', 'label' => 'bellt'], ['id' => 'TA01', 'label' => 'miaut'], ['id' => 'TB02', 'label' => 'muht']],
        ]));
        $this->assertSame(['TC03', 'TA01', 'TB02'], array_column($match['poll']['match']['targets'], 'id'));
    }

    public function testAmEndeGesperrtBleibtGemischt(): void {
        // Gegenstück: dieselben Fragen, „Auflösung am Ende", nur gesperrt.
        $room = $this->room('quiz', active: 7, revealAtEnd: true);

        $rank = $this->stateFor($room, $this->poll(7, 'rank', 'locked', 900, [
            ['id' => 'AA01', 'label' => 'Erster'],
            ['id' => 'BB02', 'label' => 'Zweiter'],
            ['id' => 'CC03', 'label' => 'Dritter'],
        ]));
        $this->assertSame(['BB02', 'CC03', 'AA01'], array_column($rank['poll']['options'], 'id'));

        $match = $this->stateFor($room, $this->poll(7, 'match', 'locked', 900, [
            'items' => [['id' => 'IT01', 'label' => 'Hund'], ['id' => 'IT02', 'label' => 'Katze'], ['id' => 'IT03', 'label' => 'Kuh']],
            'targets' => [['id' => 'TC03', 'label' => 'bellt'], ['id' => 'TA01', 'label' => 'miaut'], ['id' => 'TB02', 'label' => 'muht']],
        ]));
        $this->assertSame(['TA01', 'TB02', 'TC03'], array_column($match['poll']['match']['targets'], 'id'));
    }

    public function testUmfrageRangfolgeWirdNieUmsortiert(): void {
        $poll = $this->poll(7, 'rank', 'active', 900, [
            ['id' => 'AA01', 'label' => 'Erster'],
            ['id' => 'BB02', 'label' => 'Zweiter'],
        ]);

        $state = $this->stateFor($this->room('poll', active: 7), $poll);

        $this->assertSame(['AA01', 'BB02'], array_column($state['poll']['options'], 'id'));
    }

    public function testAufloesungAmEndeLockedBleibtLockedAberVerdeckt(): void {
        // „Auflösung am Ende": 'locked' heißt nur „nach dem Umlegen des
        // Schalters liegengeblieben". Der Status bleibt echt, aufgelöst ist
        // trotzdem nichts — das sagt `revealed`, danach richten sich Handy
        // und Beamer.
        $this->myVotes['tok-a'] = $this->vote(7, 'tok-a', ['value' => 'AA', 'correct' => true, 'points' => 950]);
        $this->current = $this->poll(7, 'choice', 'locked', 900);

        $state = $this->service->publicState($this->room('quiz', active: 7, revealAtEnd: true), 'tok-a');

        $this->assertSame('locked', $state['poll']['status']);
        $this->assertFalse($state['poll']['revealed']);
        $this->assertNull($state['results']);
        $this->assertNull($state['leaderboard']);
        $this->assertArrayNotHasKey('correctOption', $state['poll']);
        $this->assertArrayNotHasKey('answerKey', $state['poll']);
        $this->assertTrue($state['hasVoted']);
        $this->assertSame(['answered' => true, 'correct' => null, 'points' => null], $state['myResult']);
    }

    public function testAufloesungAmEndeEndedBleibtEnded(): void {
        $this->voteService->method('leaderboardFor')->willReturn([]);
        $state = $this->stateFor($this->room('quiz', active: 7, revealAtEnd: true), $this->poll(7, 'choice', 'ended', 900));

        $this->assertSame('ended', $state['poll']['status']);
        $this->assertTrue($state['poll']['revealed']);
        $this->assertSame('AA', $state['poll']['correctOption']);
    }

    public function testJeFrageLockedBleibtLocked(): void {
        // Je-Frage-Auflösung: 'locked' IST aufgelöst — der Status bleibt echt.
        $this->voteService->method('leaderboardFor')->willReturn([]);
        $state = $this->stateFor($this->room('quiz', active: 7), $this->poll(7, 'choice', 'locked', 900));

        $this->assertSame('locked', $state['poll']['status']);
        $this->assertTrue($state['poll']['revealed']);
    }

    public function testLaufendeQuizfrageIstNichtAufgeloest(): void {
        $state = $this->stateFor($this->room('quiz', active: 7), $this->poll(7, 'choice', 'active', 900));

        $this->assertSame('active', $state['poll']['status']);
        $this->assertFalse($state['poll']['revealed']);
        $this->assertArrayNotHasKey('correctOption', $state['poll']);
    }

    public function testUmfrageLockedBleibtLocked(): void {
        // Umfrage: 'locked' = pausiert, keine Lösung zu verstecken.
        $state = $this->stateFor($this->room('poll', active: 7, revealAtEnd: true), $this->poll(7, 'choice', 'locked', 900));

        $this->assertSame('locked', $state['poll']['status']);
    }

    public function testSummaryVerdecktesLockedBleibtLocked(): void {
        // Dieselbe Regel in der Gesamtauswertung: Status echt, `revealed` false,
        // keine Lösung.
        $room = $this->room('quiz', active: 2, revealAtEnd: true);
        $this->deck(
            $this->poll(1, 'choice', 'locked', 900),
            $this->poll(2, 'choice', 'active', 950),
        );

        $summary = $this->service->publicSummary($room, null);

        $this->assertSame(['locked', 'active'], array_map(static fn (array $i): string => $i['poll']['status'], $summary['items']));
        $this->assertSame([false, false], array_map(static fn (array $i): bool => $i['poll']['revealed'], $summary['items']));
        $this->assertSame([false, false], array_column($summary['items'], 'revealed'));
        foreach ($summary['items'] as $item) {
            $this->assertArrayNotHasKey('correctOption', $item['poll']);
        }
    }

    // ── Hilfen ─────────────────────────────────────────────────────────────

    private function deck(Poll ...$polls): void {
        $this->polls->method('findByRoom')->willReturn($polls);
    }

    private function stateFor(Room $room, Poll $poll): array {
        $this->current = $poll;
        return $this->service->publicState($room, null);
    }

    private function room(string $mode, int $active, bool $revealAtEnd = false): Room {
        $room = new Room();
        $room->setId(1);
        $room->setCode('ABCDEF');
        $room->setMode($mode);
        $room->setActivePollId($active);
        $room->setRevealAtEnd($revealAtEnd);
        return $room;
    }

    private function poll(int $id, string $type, string $status, int $startedAt, array $options = [['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B']]): Poll {
        $poll = new Poll();
        $poll->setId($id);
        $poll->setRoomId(1);
        $poll->setType($type);
        $poll->setQuestion('Frage ' . $id);
        $poll->setOptions(json_encode($options));
        $poll->setStatus($status);
        $poll->setStartedAt($startedAt);
        $poll->setCorrectOption('AA');
        return $poll;
    }

    private function vote(int $pollId, string $token, array $payload): Vote {
        $vote = new Vote();
        $vote->setPollId($pollId);
        $vote->setVoterToken($token);
        $vote->setPayload(json_encode($payload));
        return $vote;
    }
}
