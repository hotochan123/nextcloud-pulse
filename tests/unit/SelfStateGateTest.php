<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\AppInfo\Application;
use OCA\Pulse\Service\PaceStateService;
use OCA\Pulse\Service\PublicView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Öffentlicher Zustand im eigenen Tempo (PaceStateService::publicState).
 *
 * Was Handy und Beamer vor der Freigabe NICHT bekommen dürfen: Lösung
 * (correctOption/answerKey), Verteilung (results), die ungemischte Folge bei
 * Reihenfolge-Fragen, ein Urteil vor „endgültig" und bei „Rückmeldung am
 * Ende" überhaupt eines. Was sie brauchen: die persönliche Uhr, ohne Timer
 * kein Limit, und `progress.after` — ohne den fände ein neu geladenes Handy
 * nach einem Abbruch zwischen Schließen und Starten nie weiter.
 */
#[CoversClass(PaceStateService::class)]
class SelfStateGateTest extends PaceStateTestCase {

    // ── Keine Lösung, keine Verteilung ─────────────────────────────────────

    public static function unreleasedStates(): array {
        return ['Entwurf' => ['draft'], 'offen' => ['open'], 'geschlossen' => ['closed']];
    }

    #[DataProvider('unreleasedStates')]
    public function testVorDerFreigabeKeineLoesungUndKeineVerteilung(string $state): void {
        $this->row(11, 'tok-anna', self::NOW - 50, self::NOW - 30);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 40);
        $this->row(12, 'tok-anna', self::NOW - 10);
        if ($state === 'draft') {
            $this->room->setOpenedAt(0);
            $this->room->setDeckOrder(null);
            $this->rows = [];
            $this->votes = [];
        } elseif ($state === 'closed') {
            $this->close();
        }

        foreach (['Handy' => $this->phone(), 'Beamer' => $this->beamer()] as $who => $data) {
            $this->assertArrayHasKey('results', $data);
            $this->assertNull($data['results'], $who);
            $json = json_encode($data);
            $this->assertStringNotContainsString('correctOption', $json, $who);
            $this->assertStringNotContainsString('answerKey', $json, $who);
            if ($data['poll'] !== null) {
                $this->assertArrayNotHasKey('correctOption', $data['poll']);
                $this->assertArrayNotHasKey('answerKey', $data['poll']);
                $this->assertFalse($data['poll']['revealed']);
            }
        }
        if ($state === 'open') {
            $this->assertSame(12, $this->phone()['poll']['id'], 'die offene Frage steht auf dem Handy');
        }
    }

    public function testRangfolgeGemischtWieInPublicView(): void {
        $this->row(11, 'tok-anna', self::NOW - 50, self::NOW - 30);
        $this->row(12, 'tok-anna', self::NOW - 10);

        $served = $this->phone()['poll']['options'];

        $this->assertSame(PublicView::poll($this->room, $this->polls[12], false, self::SECRET)['options'], $served);
    }

    // ── Persönliche Uhr ────────────────────────────────────────────────────

    public function testPersoenlicheUhrUndPosition(): void {
        // poll.startedAt ist im eigenen Tempo bedeutungslos — die Uhr läuft ab /next.
        $this->polls[12]->setStartedAt(self::NOW - 5000);
        $this->row(11, 'tok-anna', self::NOW - 50, self::NOW - 30);
        $this->row(12, 'tok-anna', self::NOW - 7);

        $poll = $this->phone()['poll'];

        $this->assertSame(self::NOW - 7, $poll['startedAt']);
        $this->assertSame(20, $poll['timeLimit']);
        $this->assertSame(1, $poll['position']);
        $this->assertSame('active', $poll['status']);
    }

    public function testOhneTimerKeinLimit(): void {
        $this->room->setTimed(false);
        $this->row(11, 'tok-anna', self::NOW - 50_000);

        $data = $this->phone();

        $this->assertSame(0, $data['poll']['timeLimit'], 'ohne Timer zeigt der Client keinen Countdown');
        $this->assertFalse($data['progress']['timeUp'], 'und die Zeit läuft nie ab');
    }

    // ── Urteil ─────────────────────────────────────────────────────────────

    public function testJeFrageVorEndgueltigKeinUrteil(): void {
        $this->row(11, 'tok-anna', self::NOW - 10);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 3);

        $data = $this->phone();

        $this->assertTrue($data['hasVoted']);
        $this->assertSame('AA', $data['myValue']);
        $this->assertSame(
            ['answered' => true, 'final' => false, 'verdict' => null, 'correct' => null, 'points' => null],
            $data['myResult'],
            'in der Sekunde created+fw ist noch korrigierbar',
        );
        $this->assertSame(0, $data['myScore'], 'auch nicht über die eigenen Punkte');
    }

    public function testJeFrageEndgueltigMitUrteilUndPunkten(): void {
        $this->row(11, 'tok-anna', self::NOW - 10);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 4);

        $data = $this->phone();

        $this->assertSame(
            ['answered' => true, 'final' => true, 'verdict' => 'correct', 'correct' => true, 'points' => 900],
            $data['myResult'],
        );
        $this->assertSame(900, $data['myScore']);
    }

    public function testFalscheAntwortNullPunkte(): void {
        $this->row(11, 'tok-anna', self::NOW - 10);
        $this->vote(11, 'tok-anna', 'BB', 0, false, self::NOW - 4);

        $this->assertSame(
            ['answered' => true, 'final' => true, 'verdict' => 'wrong', 'correct' => false, 'points' => 0],
            $this->phone()['myResult'],
        );
    }

    public function testRueckmeldungAmEndeNurGespeichert(): void {
        $this->room->setFeedback('end');
        $this->row(11, 'tok-anna', self::NOW - 20, self::NOW - 12);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 15);
        $this->row(12, 'tok-anna', self::NOW - 12);
        $this->vote(12, 'tok-anna', ['JU01', 'SA02', 'NE03'], 800, true, self::NOW - 10);

        $data = $this->phone();

        $this->assertSame(
            ['answered' => true, 'final' => true, 'verdict' => 'saved', 'correct' => null, 'points' => null],
            $data['myResult'],
        );
        $this->assertNull($data['myScore'], 'auch die Summe verriete das Urteil');
        $this->assertNull($data['leaderboard']);
    }

    public function testRueckmeldungAmEndeNachDerFreigabeMitPunkten(): void {
        $this->room->setFeedback('end');
        $this->row(11, 'tok-anna', self::NOW - 20);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 15);
        $this->release();

        $data = $this->phone();

        $this->assertSame(900, $data['myScore']);
        $this->assertNull($data['poll'], 'Rückblick nur über /summary');
    }

    public function testProbelaufUrteiltTrotzRueckmeldungAmEnde(): void {
        $this->room->setPractice(true);
        $this->room->setFeedback('end');
        $this->row(11, 'tok-anna', self::NOW - 10);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 4);

        $this->assertSame('correct', $this->phone()['myResult']['verdict']);
    }

    public function testFreitextOhneBewertungWirdGeprueft(): void {
        $this->row(11, 'tok-anna', self::NOW - 50, self::NOW - 40);
        $this->row(12, 'tok-anna', self::NOW - 40, self::NOW - 30);
        $this->row(13, 'tok-anna', self::NOW - 30);
        $this->vote(13, 'tok-anna', 'Saturn', 0, false, self::NOW - 20, pending: true);

        $this->assertSame(
            ['answered' => true, 'final' => true, 'verdict' => 'pending', 'correct' => null, 'points' => null],
            $this->phone()['myResult'],
        );
    }

    // ── Rangliste ──────────────────────────────────────────────────────────

    public function testProbelaufNachDerFreigabeKeineRangliste(): void {
        $this->room->setPractice(true);
        $this->row(11, 'tok-anna', self::NOW - 20);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 15);
        $this->release();

        $this->assertNull($this->phone()['leaderboard']);
        $this->assertNull($this->phone('tok-fremd')['leaderboard']);
    }

    public function testFreigegebenOhneSpielerNurDieRangliste(): void {
        $this->row(11, 'tok-anna', self::NOW - 20);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 15);
        $this->release();

        $data = $this->phone('tok-fremd');

        $this->assertNull($data['nickname']);
        $this->assertNull($data['progress']);
        $this->assertNull($data['poll']);
        $this->assertSame(['Anna' => 900, 'Ben' => 0, 'Cem' => 0], self::column($data['leaderboard'], 'score'));
        $this->assertNotContains(true, array_column($data['leaderboard'], 'me'));
    }

    public function testVorDerFreigabeKeineRanglisteAufDemHandy(): void {
        $this->row(11, 'tok-anna', self::NOW - 20);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 15);

        $this->assertNull($this->phone()['leaderboard']);
    }

    // ── Fortschritt ────────────────────────────────────────────────────────

    public function testGeschlossenKeinPollAberFortschritt(): void {
        $this->row(11, 'tok-anna', self::NOW - 50, self::NOW - 30);
        $this->row(12, 'tok-anna', self::NOW - 10);
        $this->close();

        $data = $this->phone();

        $this->assertNull($data['poll']);
        $this->assertSame(2, $data['progress']['k']);
        $this->assertSame(12, $data['progress']['currentPollId']);
    }

    public function testAfterIstDieOffeneFrage(): void {
        $this->row(11, 'tok-anna', self::NOW - 50, self::NOW - 30);
        $this->row(12, 'tok-anna', self::NOW - 10);

        $progress = $this->phone()['progress'];

        $this->assertSame(['k' => 2, 'n' => 3, 'started' => true, 'finished' => false, 'timeUp' => false, 'currentPollId' => 12, 'after' => 12], $progress);
    }

    public function testNachAbbruchOhneOffeneZeileZeigtAfterAufDieVerlassene(): void {
        // /next schloss Q11 und brach vor dem Start von Q12 ab.
        $this->row(11, 'tok-anna', self::NOW - 50, self::NOW - 30);

        $data = $this->phone();

        $this->assertNull($data['poll']);
        $this->assertSame(0, $data['progress']['currentPollId']);
        $this->assertSame(11, $data['progress']['after'], 'damit heilt das nächste /next');
        $this->assertSame(1, $data['progress']['k']);
    }

    public function testGeloeschteFrageDerOffenenZeile(): void {
        $this->row(11, 'tok-anna', self::NOW - 50, self::NOW - 30);
        $this->row(12, 'tok-anna', self::NOW - 10);
        unset($this->polls[12]);

        $data = $this->phone();

        $this->assertNull($data['poll']);
        $this->assertSame(12, $data['progress']['currentPollId']);
        $this->assertSame(12, $data['progress']['after'], '/next mit dieser Frage geht weiter');
    }

    public function testVorDemStartNochKeineFrage(): void {
        $data = $this->phone();

        $this->assertSame('Anna', $data['nickname']);
        $this->assertSame(['k' => 0, 'n' => 3, 'started' => false, 'finished' => false, 'timeUp' => false, 'currentPollId' => 0, 'after' => 0], $data['progress']);
        $this->assertNull($data['poll']);
    }

    public function testFertigNachIsFinished(): void {
        $this->row(11, 'tok-anna', self::NOW - 50, self::NOW - 40);
        $this->row(12, 'tok-anna', self::NOW - 40, self::NOW - 30);
        $this->row(13, 'tok-anna', self::NOW - 30);
        $this->assertFalse($this->phone()['progress']['finished'], 'letzte Frage offen, unbeantwortet');

        $this->vote(13, 'tok-anna', 'Jupiter', 1000, true, self::NOW - 20);
        $this->assertTrue($this->phone()['progress']['finished'], 'letzte beantwortet, „Fertig" nie getippt');

        $this->votes = [];
        $this->close();
        $this->assertTrue($this->phone()['progress']['finished'], 'unbeantwortet, aber Fenster zu');
    }

    public function testZeitUmKipptBeiLimitPlusEins(): void {
        $this->row(11, 'tok-anna', self::NOW - 20);
        $this->assertFalse($this->phone()['progress']['timeUp'], 'Grenze wie /vote: elapsed > limit');

        $this->now = self::NOW + 1;
        $this->assertTrue($this->phone()['progress']['timeUp']);
    }

    public function testPraesenzNurImEntwurf(): void {
        $this->present = 7;
        $this->row(11, 'tok-anna', self::NOW - 20);
        $this->assertSame(0, $this->phone()['present'], 'offen: sonst trieben Heartbeats die Version');

        $this->room->setOpenedAt(0);
        $this->room->setDeckOrder(null);
        $this->rows = [];
        $this->assertSame(7, $this->phone()['present'], 'Wartezustand: „N dabei"');
    }

    public function testKopfFelder(): void {
        $data = $this->phone();

        $this->assertSame(['code' => 'AB12CD', 'title' => 'Planeten', 'mode' => 'quiz', 'pace' => 'self'], $data['room']);
        $this->assertSame(Application::PROTOCOL, $data['protocol']);
        $this->assertSame(self::NOW, $data['serverNow']);
        $this->assertSame('open', $data['window']['state']);
        $this->assertSame(3, $data['window']['total']);
        $this->assertSame(0, $data['answered']);
    }

    // ── Beamer ─────────────────────────────────────────────────────────────

    public function testBeamerOhneFragenUndOptionen(): void {
        $this->raceWithThreePlayers();

        $data = $this->beamer();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        $this->assertNull($data['poll']);
        $this->assertNull($data['results']);
        foreach (['Hauptstadt', 'Planeten nach', 'Größter Planet', 'Paris', 'Berlin', 'Jupiter', 'Saturn', 'Neptun'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertArrayNotHasKey('nickname', $data);
        $this->assertArrayNotHasKey('progress', $data);
    }

    public function testBeamerRennenInZahlen(): void {
        $this->raceWithThreePlayers();

        $race = $this->beamer()['race'];

        // Anna auf Q3, Ben fertig (Q3 beantwortet, „Fertig“ nicht getippt — zählt nur als fertig), Cem auf Q1.
        $this->assertSame(['n' => 3, 'joined' => 3, 'started' => 3, 'finished' => 1, 'onQuestion' => [1, 0, 1]], $race);
        $this->assertSame($race['joined'], $race['joined'] - $race['started'] + array_sum($race['onQuestion']) + $race['finished'], 'jede Person genau einmal');
    }

    public function testBeamerRanglisteNurBeiRueckmeldungJeFrage(): void {
        $this->raceWithThreePlayers();
        $this->players = array_merge($this->players, array_map(
            fn (int $i) => $this->player(40 + $i, 'tok-' . $i, 'Spieler ' . $i),
            range(1, 9),
        ));

        $this->assertCount(8, $this->beamer()['leaderboard'], 'offen: die Spitze');

        $this->cacheStore = [];
        $this->room->setFeedback('end');
        $this->assertNull($this->beamer()['leaderboard'], 'Rückmeldung am Ende: nichts');

        $this->cacheStore = [];
        $this->release();
        $this->assertCount(12, $this->beamer()['leaderboard'], 'freigegeben: der volle Endstand');

        $this->cacheStore = [];
        $this->room->setPractice(true);
        $this->assertNull($this->beamer()['leaderboard'], 'Probelauf: nie');
    }

    public function testBeamerZeigtAnwesende(): void {
        $this->present = 12;

        $this->assertSame(12, $this->beamer()['present']);
    }

    public function testBeamerPunkteGleichHandyPunkte(): void {
        // Alle Sichten zählen nur endgültige Stimmen.
        $this->raceWithThreePlayers();

        $board = self::column($this->beamer()['leaderboard'], 'score');

        $this->assertSame($this->phone('tok-anna')['myScore'], $board['Anna']);
        $this->assertSame($this->phone('tok-ben')['myScore'], $board['Ben']);
        $this->assertSame($this->phone('tok-cem')['myScore'], $board['Cem']);
    }

    public static function raceStates(): array {
        return [
            'offen' => ['open', [2, 0, 1], 2],
            'geschlossen' => ['closed', [2, 0, 0], 3],
            'freigegeben' => ['released', [2, 0, 0], 3],
        ];
    }

    /**
     * Jede gestartete Person steht in genau einer Zeile des Rennens — fertig
     * oder auf der Frage, die auch Handy und Laufansicht nennen. Nicht
     * gestartet + je Frage + fertig = beigetreten, in jedem Fensterzustand.
     */
    #[DataProvider('raceStates')]
    public function testBeamerZaehltJedePersonGenauEinmal(string $state, array $onQuestion, int $finished): void {
        $this->raceWithSixPlayers();
        if ($state === 'closed') {
            $this->close();
        } elseif ($state === 'released') {
            $this->release();
        }

        $race = $this->beamer()['race'];

        $this->assertSame(['n' => 3, 'joined' => 6, 'started' => 5, 'finished' => $finished, 'onQuestion' => $onQuestion], $race);
        $this->assertSame(6, 6 - $race['started'] + array_sum($race['onQuestion']) + $race['finished'], 'Zeilen teilen die Beigetretenen auf');
        // Dieselbe Aufteilung wie die Laufansicht: fertig, sonst Frage k.
        $on = array_fill(0, 3, 0);
        $done = 0;
        foreach ($this->service->progress($this->room)['players'] as $p) {
            if ($p['finished']) {
                $done++;
            } elseif ($p['started']) {
                $on[$p['k'] - 1]++;
            }
        }
        $this->assertSame([$on, $done], [$race['onQuestion'], $race['finished']], 'Beamer = Laufansicht');
    }

    /**
     * Anna: Q1 richtig (verlassen), Q2 falsch (verlassen), steht auf Q3.
     * Ben: alle drei beantwortet, Q3 noch im Korrekturfenster (zählt noch nicht).
     * Cem: steht auf Q1, Antwort gerade eben (nicht endgültig).
     */
    private function raceWithThreePlayers(): void {
        $this->row(11, 'tok-anna', self::NOW - 60, self::NOW - 50);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 55);
        $this->row(12, 'tok-anna', self::NOW - 50, self::NOW - 40);
        $this->vote(12, 'tok-anna', ['SA02', 'JU01', 'NE03'], 0, false, self::NOW - 45);
        $this->row(13, 'tok-anna', self::NOW - 40);

        $this->row(11, 'tok-ben', self::NOW - 60, self::NOW - 55);
        $this->vote(11, 'tok-ben', 'AA', 950, true, self::NOW - 58);
        $this->row(12, 'tok-ben', self::NOW - 55, self::NOW - 50);
        $this->vote(12, 'tok-ben', ['JU01', 'SA02', 'NE03'], 800, true, self::NOW - 52);
        $this->row(13, 'tok-ben', self::NOW - 50);
        $this->vote(13, 'tok-ben', 'Jupiter', 700, true, self::NOW - 2);

        $this->row(11, 'tok-cem', self::NOW - 5);
        $this->vote(11, 'tok-cem', 'AA', 990, true, self::NOW - 1);
    }

    /**
     * Sechs Beigetretene, fünf gestartet (Deck 11, 12, 13):
     * Anna auf Q3, beantwortet, „Fertig“ nicht getippt -> offen schon fertig.
     * Ben hat Q3 verlassen („Fertig“) -> fertig.
     * Cem steht auf Q1.
     * Dora auf Q3, unbeantwortet -> offen auf Q3, nach Schluss fertig.
     * Emil hat Q1 verlassen, Q2 nie gestartet (Abbruch in /next) -> zählt auf Q1.
     * Fay ist beigetreten, aber nicht gestartet.
     */
    private function raceWithSixPlayers(): void {
        $this->players = array_merge($this->players, [
            $this->player(34, 'tok-dora', 'Dora'),
            $this->player(35, 'tok-emil', 'Emil'),
            $this->player(36, 'tok-fay', 'Fay'),
        ]);
        $this->row(11, 'tok-anna', self::NOW - 60, self::NOW - 50);
        $this->row(12, 'tok-anna', self::NOW - 50, self::NOW - 40);
        $this->row(13, 'tok-anna', self::NOW - 40);
        $this->vote(13, 'tok-anna', 'Jupiter', 700, true, self::NOW - 30);
        $this->row(11, 'tok-ben', self::NOW - 60, self::NOW - 55);
        $this->row(12, 'tok-ben', self::NOW - 55, self::NOW - 50);
        $this->row(13, 'tok-ben', self::NOW - 50, self::NOW - 45);
        $this->row(11, 'tok-cem', self::NOW - 5);
        $this->row(11, 'tok-dora', self::NOW - 60, self::NOW - 50);
        $this->row(12, 'tok-dora', self::NOW - 50, self::NOW - 40);
        $this->row(13, 'tok-dora', self::NOW - 40);
        $this->row(11, 'tok-emil', self::NOW - 30, self::NOW - 20);
    }
}
