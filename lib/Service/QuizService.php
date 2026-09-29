<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\Player;

/**
 * Quiz scoring (Kahoot-like): a correct answer earns points, a faster one more.
 * Pure computation without a DB — testable and easy to tune.
 */
class QuizService {
    /** Full score for an immediately correct answer. */
    public const BASE_POINTS = 1000;

    /**
     * Speed points for a CORRECT answer (the caller checks correctness per
     * question type). Immediately → BASE, at the time limit → BASE/2. Without a time limit, a flat BASE.
     *
     * @param int $elapsed    seconds elapsed since the question started
     * @param int $timeLimit  time limit of the question (0 = none)
     */
    public function points(int $elapsed, int $timeLimit): int {
        if ($timeLimit <= 0) {
            return self::BASE_POINTS;
        }
        $frac = max(0.0, min(1.0, $elapsed / $timeLimit));
        return (int)round(self::BASE_POINTS * (1 - 0.5 * $frac));
    }

    /**
     * Build the leaderboard. Ties on points are broken by the shorter total
     * answer time (summed elapsed) -> real ties are rare. Only when
     * points AND time are exactly equal is the rank shared (1,2,2,4 …); the
     * next rank skips accordingly. The rest is sorted alphabetically. Every row
     * internally carries token + time; the token MUST be removed before it is
     * sent out (someone else's cookie secret), the time is purely internal.
     *
     * @param Player[]            $players
     * @param array<string,int>   $pointsByToken  point total per token
     * @param array<string,int>   $correctByToken number of correct answers per token
     * @param array<string,int>   $timeByToken    summed answer time (elapsed) per token
     * @return list<array{token:string, rank:int, nickname:string, score:int, correct:int, time:int}>
     */
    public function leaderboard(array $players, array $pointsByToken, array $correctByToken, array $timeByToken = []): array {
        $rows = [];
        foreach ($players as $p) {
            $token = $p->getVoterToken();
            $rows[] = [
                'token' => $token,
                'nickname' => $p->getNickname(),
                'score' => $pointsByToken[$token] ?? 0,
                'correct' => $correctByToken[$token] ?? 0,
                'time' => $timeByToken[$token] ?? 0,
            ];
        }
        // Points descending, then shorter total time, then name. The time counts ONLY
        // with points > 0: otherwise someone who never answered (time 0) would rank
        // ahead of someone who answered wrongly — but quickly. All players without
        // points therefore share one rank.
        usort($rows, static function (array $a, array $b): int {
            $ta = $a['score'] > 0 ? $a['time'] : 0;
            $tb = $b['score'] > 0 ? $b['time'] : 0;
            return ($b['score'] <=> $a['score'])
                ?: ($ta <=> $tb)
                ?: strcasecmp($a['nickname'], $b['nickname']);
        });
        // Shared rank ONLY for exactly equal (points, time) -> the next one skips.
        $rank = 0;
        $prevKey = null;
        foreach ($rows as $i => &$row) {
            $key = $row['score'] . ':' . ($row['score'] > 0 ? $row['time'] : 0);
            if ($key !== $prevKey) {
                $rank = $i + 1;
                $prevKey = $key;
            }
            $row['rank'] = $rank;
        }
        unset($row);
        return $rows;
    }
}
