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
 * Self-paced public state (PaceStateService::publicState).
 *
 * What phone and projector must NOT get before the release: the solution
 * (correctOption/answerKey), the distribution (results), the unshuffled order of
 * ranking questions, a verdict before "final", and with feedback "At the
 * end" any verdict at all. What they need: the personal clock, no limit
 * without a timer, and `progress.after` — without it a reloaded phone would
 * never get any further after an abort between closing and starting.
 */
#[CoversClass(PaceStateService::class)]
class SelfStateGateTest extends PaceStateTestCase {

    // ── No solution, no distribution ───────────────────────────────────────

    public static function unreleasedStates(): array {
        return ['Entwurf' => ['draft'], 'offen' => ['open'], 'geschlossen' => ['closed']];
    }

    #[DataProvider('unreleasedStates')]
    public function testBeforeReleaseNoSolutionAndNoDistribution(string $state): void {
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

    public function testRankingShuffledAsInPublicView(): void {
        $this->row(11, 'tok-anna', self::NOW - 50, self::NOW - 30);
        $this->row(12, 'tok-anna', self::NOW - 10);

        $served = $this->phone()['poll']['options'];

        $this->assertSame(PublicView::poll($this->room, $this->polls[12], false, self::SECRET)['options'], $served);
    }

    // ── Personal clock ─────────────────────────────────────────────────────

    public function testPersonalClockAndPosition(): void {
        // poll.startedAt is meaningless when self-paced — the clock runs from /next.
        $this->polls[12]->setStartedAt(self::NOW - 5000);
        $this->row(11, 'tok-anna', self::NOW - 50, self::NOW - 30);
        $this->row(12, 'tok-anna', self::NOW - 7);

        $poll = $this->phone()['poll'];

        $this->assertSame(self::NOW - 7, $poll['startedAt']);
        $this->assertSame(20, $poll['timeLimit']);
        $this->assertSame(1, $poll['position']);
        $this->assertSame('active', $poll['status']);
    }

    public function testWithoutTimerNoLimit(): void {
        $this->room->setTimed(false);
        $this->row(11, 'tok-anna', self::NOW - 50_000);

        $data = $this->phone();

        $this->assertSame(0, $data['poll']['timeLimit'], 'ohne Timer zeigt der Client keinen Countdown');
        $this->assertFalse($data['progress']['timeUp'], 'und die Zeit läuft nie ab');
    }

    // ── Verdict ────────────────────────────────────────────────────────────

    public function testPerQuestionNoVerdictBeforeFinal(): void {
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

    public function testPerQuestionFinalWithVerdictAndPoints(): void {
        $this->row(11, 'tok-anna', self::NOW - 10);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 4);

        $data = $this->phone();

        $this->assertSame(
            ['answered' => true, 'final' => true, 'verdict' => 'correct', 'correct' => true, 'points' => 900],
            $data['myResult'],
        );
        $this->assertSame(900, $data['myScore']);
    }

    public function testWrongAnswerZeroPoints(): void {
        $this->row(11, 'tok-anna', self::NOW - 10);
        $this->vote(11, 'tok-anna', 'BB', 0, false, self::NOW - 4);

        $this->assertSame(
            ['answered' => true, 'final' => true, 'verdict' => 'wrong', 'correct' => false, 'points' => 0],
            $this->phone()['myResult'],
        );
    }

    public function testFeedbackAtEndOnlySaved(): void {
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

    public function testFeedbackAtEndWithPointsAfterRelease(): void {
        $this->room->setFeedback('end');
        $this->row(11, 'tok-anna', self::NOW - 20);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 15);
        $this->release();

        $data = $this->phone();

        $this->assertSame(900, $data['myScore']);
        $this->assertNull($data['poll'], 'Rückblick nur über /summary');
    }

    public function testPracticeRunGivesVerdictDespiteFeedbackAtEnd(): void {
        $this->room->setPractice(true);
        $this->room->setFeedback('end');
        $this->row(11, 'tok-anna', self::NOW - 10);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 4);

        $this->assertSame('correct', $this->phone()['myResult']['verdict']);
    }

    public function testFreeTextWithoutGradingIsBeingChecked(): void {
        $this->row(11, 'tok-anna', self::NOW - 50, self::NOW - 40);
        $this->row(12, 'tok-anna', self::NOW - 40, self::NOW - 30);
        $this->row(13, 'tok-anna', self::NOW - 30);
        $this->vote(13, 'tok-anna', 'Saturn', 0, false, self::NOW - 20, pending: true);

        $this->assertSame(
            ['answered' => true, 'final' => true, 'verdict' => 'pending', 'correct' => null, 'points' => null],
            $this->phone()['myResult'],
        );
    }

    // ── Leaderboard ────────────────────────────────────────────────────────

    public function testPracticeRunNoLeaderboardAfterRelease(): void {
        $this->room->setPractice(true);
        $this->row(11, 'tok-anna', self::NOW - 20);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 15);
        $this->release();

        $this->assertNull($this->phone()['leaderboard']);
        $this->assertNull($this->phone('tok-fremd')['leaderboard']);
    }

    public function testReleasedWithoutPlayerOnlyTheLeaderboard(): void {
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

    public function testBeforeReleaseNoLeaderboardOnThePhone(): void {
        $this->row(11, 'tok-anna', self::NOW - 20);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 15);

        $this->assertNull($this->phone()['leaderboard']);
    }

    // ── Progress ───────────────────────────────────────────────────────────

    public function testClosedNoPollButProgress(): void {
        $this->row(11, 'tok-anna', self::NOW - 50, self::NOW - 30);
        $this->row(12, 'tok-anna', self::NOW - 10);
        $this->close();

        $data = $this->phone();

        $this->assertNull($data['poll']);
        $this->assertSame(2, $data['progress']['k']);
        $this->assertSame(12, $data['progress']['currentPollId']);
    }

    public function testAfterIsTheOpenQuestion(): void {
        $this->row(11, 'tok-anna', self::NOW - 50, self::NOW - 30);
        $this->row(12, 'tok-anna', self::NOW - 10);

        $progress = $this->phone()['progress'];

        $this->assertSame(['k' => 2, 'n' => 3, 'started' => true, 'finished' => false, 'timeUp' => false, 'currentPollId' => 12, 'after' => 12], $progress);
    }

    public function testAbortWithoutOpenRowPointsAfterToTheLeftRow(): void {
        // /next closed Q11 and aborted before starting Q12.
        $this->row(11, 'tok-anna', self::NOW - 50, self::NOW - 30);

        $data = $this->phone();

        $this->assertNull($data['poll']);
        $this->assertSame(0, $data['progress']['currentPollId']);
        $this->assertSame(11, $data['progress']['after'], 'damit heilt das nächste /next');
        $this->assertSame(1, $data['progress']['k']);
    }

    public function testDeletedQuestionOfTheOpenRow(): void {
        $this->row(11, 'tok-anna', self::NOW - 50, self::NOW - 30);
        $this->row(12, 'tok-anna', self::NOW - 10);
        unset($this->polls[12]);

        $data = $this->phone();

        $this->assertNull($data['poll']);
        $this->assertSame(12, $data['progress']['currentPollId']);
        $this->assertSame(12, $data['progress']['after'], '/next mit dieser Frage geht weiter');
    }

    public function testBeforeTheStartNoQuestionYet(): void {
        $data = $this->phone();

        $this->assertSame('Anna', $data['nickname']);
        $this->assertSame(['k' => 0, 'n' => 3, 'started' => false, 'finished' => false, 'timeUp' => false, 'currentPollId' => 0, 'after' => 0], $data['progress']);
        $this->assertNull($data['poll']);
    }

    public function testFinishedFollowsIsFinished(): void {
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

    public function testTimeUpFlipsAtLimitPlusOne(): void {
        $this->row(11, 'tok-anna', self::NOW - 20);
        $this->assertFalse($this->phone()['progress']['timeUp'], 'Grenze wie /vote: elapsed > limit');

        $this->now = self::NOW + 1;
        $this->assertTrue($this->phone()['progress']['timeUp']);
    }

    public function testPresenceOnlyInDraft(): void {
        $this->present = 7;
        $this->row(11, 'tok-anna', self::NOW - 20);
        $this->assertSame(0, $this->phone()['present'], 'offen: sonst trieben Heartbeats die Version');

        $this->room->setOpenedAt(0);
        $this->room->setDeckOrder(null);
        $this->rows = [];
        $this->assertSame(7, $this->phone()['present'], 'Wartezustand: „N dabei"');
    }

    public function testHeaderFields(): void {
        $data = $this->phone();

        $this->assertSame(['code' => 'AB12CD', 'title' => 'Planeten', 'mode' => 'quiz', 'pace' => 'self'], $data['room']);
        $this->assertSame(Application::PROTOCOL, $data['protocol']);
        $this->assertSame(self::NOW, $data['serverNow']);
        $this->assertSame('open', $data['window']['state']);
        $this->assertSame(3, $data['window']['total']);
        $this->assertSame(0, $data['answered']);
    }

    // ── Projector ──────────────────────────────────────────────────────────

    public function testProjectorWithoutQuestionsAndOptions(): void {
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

    public function testProjectorRaceInNumbers(): void {
        $this->raceWithThreePlayers();

        $race = $this->beamer()['race'];

        // Anna on Q3, Ben done (answered Q3, did not tap "I’m done" — counts only as done), Cem on Q1.
        $this->assertSame(['n' => 3, 'joined' => 3, 'started' => 3, 'finished' => 1, 'onQuestion' => [1, 0, 1]], $race);
        $this->assertSame($race['joined'], $race['joined'] - $race['started'] + array_sum($race['onQuestion']) + $race['finished'], 'jede Person genau einmal');
    }

    public function testProjectorLeaderboardOnlyWithFeedbackPerQuestion(): void {
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
        // Released: the top 10 of the final standings plus their true length
        // (public payloads are capped, see PublicPayloadTest).
        $final = $this->beamer();
        $this->assertCount(10, $final['leaderboard'], 'freigegeben: die ersten zehn des Endstands');
        $this->assertSame(12, $final['leaderboardTotal'], 'freigegeben: wie viele es insgesamt sind');

        $this->cacheStore = [];
        $this->room->setPractice(true);
        $this->assertNull($this->beamer()['leaderboard'], 'Probelauf: nie');
    }

    public function testProjectorShowsThosePresent(): void {
        $this->present = 12;

        $this->assertSame(12, $this->beamer()['present']);
    }

    public function testProjectorPointsEqualPhonePoints(): void {
        // All views count only final votes.
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
     * Every person who started is in exactly one row of the race — done
     * or on the question that phone and progress view also name. Not
     * started + per question + done = joined, in every window state.
     */
    #[DataProvider('raceStates')]
    public function testProjectorCountsEveryPersonExactlyOnce(string $state, array $onQuestion, int $finished): void {
        $this->raceWithSixPlayers();
        if ($state === 'closed') {
            $this->close();
        } elseif ($state === 'released') {
            $this->release();
        }

        $race = $this->beamer()['race'];

        $this->assertSame(['n' => 3, 'joined' => 6, 'started' => 5, 'finished' => $finished, 'onQuestion' => $onQuestion], $race);
        $this->assertSame(6, 6 - $race['started'] + array_sum($race['onQuestion']) + $race['finished'], 'Zeilen teilen die Beigetretenen auf');
        // The same split as the progress view: done, otherwise question k.
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
     * Anna: Q1 correct (left), Q2 wrong (left), is on Q3.
     * Ben: answered all three, Q3 still within the correction window (does not count yet).
     * Cem: is on Q1, answered just now (not final).
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
     * Six joined, five started (deck 11, 12, 13):
     * Anna on Q3, answered, did not tap "I’m done" -> already done while open.
     * Ben has left Q3 ("I’m done") -> done.
     * Cem is on Q1.
     * Dora on Q3, unanswered -> on Q3 while open, done after closing.
     * Emil has left Q1, never started Q2 (abort in /next) -> counts on Q1.
     * Fay has joined but not started.
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
