<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Player;
use OCA\Pulse\Service\QuizService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Scoring + leaderboard. Pure calculation logic without a DB; this is where the rules
 * live that become visible during a talk (speed points, ties on the podium).
 */
#[CoversClass(QuizService::class)]
class QuizServiceTest extends TestCase {

    private QuizService $service;

    protected function setUp(): void {
        $this->service = new QuizService();
    }

    // ── points() ────────────────────────────────────────────────────────────

    public function testImmediatelyCorrectGivesFullScore(): void {
        $this->assertSame(1000, $this->service->points(0, 20));
    }

    public function testAtTheTimeLimitGivesHalfScore(): void {
        $this->assertSame(500, $this->service->points(20, 20));
    }

    public function testHalfTheTimeGivesThreeQuarterPoints(): void {
        $this->assertSame(750, $this->service->points(10, 20));
    }

    public function testWithoutTimeLimitFlatFullScore(): void {
        $this->assertSame(1000, $this->service->points(99, 0));
    }

    public function testOverrunTimeDoesNotFallBelowHalf(): void {
        // The fraction is capped at 1.0, so it never costs more than half.
        $this->assertSame(500, $this->service->points(999, 20));
    }

    public function testNegativeTimeCountsAsImmediate(): void {
        // Clock skew must not produce points above BASE.
        $this->assertSame(1000, $this->service->points(-5, 20));
    }

    // ── leaderboard() ───────────────────────────────────────────────────────

    public function testSortsByPoints(): void {
        $rows = $this->service->leaderboard(
            [$this->player('Ada'), $this->player('Bob')],
            ['t_Ada' => 3000, 't_Bob' => 2000],
            [],
            ['t_Ada' => 10, 't_Bob' => 20],
        );
        $this->assertSame(['Ada#1', 'Bob#2'], $this->ranks($rows));
    }

    public function testOnAPointsTieTheShorterTimeWins(): void {
        $rows = $this->service->leaderboard(
            [$this->player('Ada'), $this->player('Bob')],
            ['t_Ada' => 2000, 't_Bob' => 2000],
            [],
            ['t_Ada' => 15, 't_Bob' => 9],
        );
        $this->assertSame(['Bob#1', 'Ada#2'], $this->ranks($rows));
    }

    public function testEqualPointsAndTimeShareTheRankAndTheNextSkips(): void {
        $rows = $this->service->leaderboard(
            [$this->player('Ada'), $this->player('Bea'), $this->player('Cid'), $this->player('Dan')],
            ['t_Ada' => 3000, 't_Bea' => 2000, 't_Cid' => 2000, 't_Dan' => 1000],
            [],
            ['t_Ada' => 5, 't_Bea' => 12, 't_Cid' => 12, 't_Dan' => 30],
        );
        // 1-2-2-4: the shared rank 2 uses up place 3.
        $this->assertSame(['Ada#1', 'Bea#2', 'Cid#2', 'Dan#4'], $this->ranks($rows));
    }

    public function testPlayersWithoutPointsShareTheRankRegardlessOfTime(): void {
        // Someone who never answered (time 0) must not rank ahead of someone who
        // answered wrongly, but quickly. Names deliberately chosen so that
        // the time order (Zeno 0 before Ada 9) contradicts the alphabetical one:
        // otherwise the test would not notice the points condition being dropped.
        $rows = $this->service->leaderboard(
            [$this->player('Zeno'), $this->player('Ada')],
            ['t_Zeno' => 0, 't_Ada' => 0],
            [],
            ['t_Zeno' => 0, 't_Ada' => 9],
        );
        $this->assertSame(['Ada#1', 'Zeno#1'], $this->ranks($rows));
    }

    public function testPlayersWithoutPointsRankBehindEveryScorer(): void {
        $rows = $this->service->leaderboard(
            [$this->player('Ada'), $this->player('Nix'), $this->player('Schnell')],
            ['t_Ada' => 500, 't_Nix' => 0, 't_Schnell' => 0],
            [],
            ['t_Ada' => 8, 't_Nix' => 0, 't_Schnell' => 4],
        );
        $this->assertSame(['Ada#1', 'Nix#2', 'Schnell#2'], $this->ranks($rows));
    }

    public function testWithoutTimesTheRankIsSharedAlphabetically(): void {
        $rows = $this->service->leaderboard(
            [$this->player('Bob'), $this->player('Ada')],
            ['t_Ada' => 100, 't_Bob' => 100],
            ['t_Ada' => 1, 't_Bob' => 1],
        );
        $this->assertSame(['Ada#1', 'Bob#1'], $this->ranks($rows));
    }

    public function testCorrectAnswersArePassedThrough(): void {
        $rows = $this->service->leaderboard(
            [$this->player('Ada')],
            ['t_Ada' => 2000],
            ['t_Ada' => 2],
            ['t_Ada' => 7],
        );
        $this->assertSame(2, $rows[0]['correct']);
        $this->assertSame(2000, $rows[0]['score']);
    }

    public function testPersonWithoutVotesGetsZeros(): void {
        $rows = $this->service->leaderboard([$this->player('Leer')], [], [], []);
        $this->assertSame(['score' => 0, 'correct' => 0, 'time' => 0, 'rank' => 1], [
            'score' => $rows[0]['score'],
            'correct' => $rows[0]['correct'],
            'time' => $rows[0]['time'],
            'rank' => $rows[0]['rank'],
        ]);
    }

    public function testRowCarriesTheTokenInternally(): void {
        // The caller (VoteService::leaderboardFor) MUST remove it before responding;
        // this only records that it comes along at all.
        $rows = $this->service->leaderboard([$this->player('Ada')], [], [], []);
        $this->assertSame('t_Ada', $rows[0]['token']);
    }

    public function testEmptyLeaderboardStaysEmpty(): void {
        $this->assertSame([], $this->service->leaderboard([], [], [], []));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function player(string $nickname): Player {
        $p = new Player();
        $p->setVoterToken('t_' . $nickname);
        $p->setNickname($nickname);
        return $p;
    }

    /**
     * @param list<array{nickname:string, rank:int}> $rows
     * @return list<string> "Name#Rank" in result order
     */
    private function ranks(array $rows): array {
        return array_map(static fn (array $r): string => $r['nickname'] . '#' . $r['rank'], $rows);
    }
}
