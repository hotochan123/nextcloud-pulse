<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

/**
 * Size limits for everything the public views hand out: every phone and the
 * projector fetch it again after each vote, and anyone with the room code can
 * add votes (or players) without limit. Uncapped, a stuffed room turned every
 * poll into megabytes of tallies and leaderboard rows for every device.
 *
 * Only the public payloads are capped (StateService::publicState and
 * ::publicSummary, their self-paced twins in PaceStateService). The
 * moderator's results, the moderator summary and both CSV exports keep the
 * full data.
 *
 * Backward compatible with bundles built before the caps: every list keeps
 * its name and row shape and just gets shorter; everything new is an
 * additional field (the totals, the viewer's own row, the `shared` flag).
 */
final class PublicPayload {
    /** Leaderboard rows from the top. */
    public const LEADERBOARD_TOP = 10;
    /** Rows above and below the viewer's own row in leaderboardAround. */
    public const LEADERBOARD_AROUND = 2;
    /** Word cloud words and free-text answer groups, most frequent first. */
    public const LIST_TOP = 100;
    /** Compass points in the sample. */
    public const COMPASS_SAMPLE = 500;

    /**
     * The leaderboard fields of a public payload, written into $payload:
     * - `leaderboard`: the first $top rows (null stays null, [] stays []);
     * - `leaderboardTotal`: number of rows before the cut;
     * - `leaderboardMe`: the viewer's own row (`me: true`), wherever it is — or null;
     * - `leaderboardAround`: the own row with up to LEADERBOARD_AROUND rows
     *   above and below it ("Around you" on the phone) — [] without an own row.
     * Every row also gets `shared` (its rank is shared), computed on the
     * full list, because a tie can straddle the cut.
     *
     * @param ?list<array{rank:int, nickname:string, score:int, correct:int, me:bool}> $rows
     */
    public static function withLeaderboard(array $payload, ?array $rows, int $top = self::LEADERBOARD_TOP): array {
        if ($rows === null) {
            $payload['leaderboard'] = null;
            $payload['leaderboardTotal'] = 0;
            $payload['leaderboardMe'] = null;
            $payload['leaderboardAround'] = [];
            return $payload;
        }
        $rows = array_values($rows);
        $perRank = [];
        foreach ($rows as $row) {
            $perRank[$row['rank']] = ($perRank[$row['rank']] ?? 0) + 1;
        }
        $me = null;
        foreach ($rows as $i => $row) {
            $rows[$i]['shared'] = $perRank[$row['rank']] > 1;
            if ($me === null && !empty($row['me'])) {
                $me = $i;
            }
        }
        $payload['leaderboard'] = array_slice($rows, 0, max(0, $top));
        $payload['leaderboardTotal'] = count($rows);
        $payload['leaderboardMe'] = $me === null ? null : $rows[$me];
        if ($me === null) {
            $payload['leaderboardAround'] = [];
        } else {
            $from = max(0, $me - self::LEADERBOARD_AROUND);
            $payload['leaderboardAround'] = array_slice($rows, $from, $me + self::LEADERBOARD_AROUND + 1 - $from);
        }
        return $payload;
    }

    /**
     * A tally for a public payload. Unbounded lists are cut, their true size
     * goes into a new field; everything else stays as it is:
     * - word cloud: `results` = the LIST_TOP most frequent words (the tally is
     *   sorted by frequency), plus `resultsTotal` (distinct words) and
     *   `mentions` (all words counted);
     * - free text: `answers` = the LIST_TOP most frequent answer groups, plus
     *   `answersTotal`;
     * - compass: `points` = a sample of at most COMPASS_SAMPLE points (see
     *   compassSample), plus `pointsTotal`. total and centroid come from all votes.
     * The other types are bounded by their options already.
     */
    public static function tally(?array $tally): ?array {
        if ($tally === null) {
            return null;
        }
        $type = $tally['type'] ?? '';
        if ($type === 'words' && is_array($tally['results'] ?? null)) {
            $tally['resultsTotal'] = count($tally['results']);
            $tally['mentions'] = array_sum(array_map(static fn (array $r): int => (int)($r['count'] ?? 0), $tally['results']));
            $tally['results'] = array_slice($tally['results'], 0, self::LIST_TOP);
        } elseif ($type === 'text' && is_array($tally['answers'] ?? null)) {
            $tally['answersTotal'] = count($tally['answers']);
            $tally['answers'] = array_slice($tally['answers'], 0, self::LIST_TOP);
        } elseif ($type === 'scale' && ($tally['mode'] ?? '') === 'compass' && is_array($tally['points'] ?? null)) {
            $tally['pointsTotal'] = count($tally['points']);
            $tally['points'] = self::compassSample($tally['points'], self::COMPASS_SAMPLE);
        }
        return $tally;
    }

    /**
     * At most $max compass points with the same distribution. Points sit on
     * the integer grid (range at most 20, so at most 41×41 positions); each
     * position keeps its share of $max (largest remainder, ties to the
     * position seen first). Deterministic, so the projector does not flicker
     * between two polls, and the heat map keeps its shape (its colour is
     * relative to the densest cell).
     *
     * @param list<array{x:int, y:int}> $points
     * @return list<array{x:int, y:int}>
     */
    public static function compassSample(array $points, int $max): array {
        $n = count($points);
        if ($n <= $max) {
            return $points;
        }
        if ($max <= 0) {
            return [];
        }
        $count = [];
        $at = [];
        foreach ($points as $p) {
            $key = $p['x'] . ':' . $p['y'];
            if (!isset($count[$key])) {
                $count[$key] = 0;
                $at[$key] = ['x' => $p['x'], 'y' => $p['y']];
            }
            $count[$key]++;
        }
        $quota = [];
        $rest = [];
        $given = 0;
        $order = 0;
        foreach ($count as $key => $c) {
            $exact = $c * $max / $n;
            $quota[$key] = (int)floor($exact);
            $given += $quota[$key];
            $rest[] = [$exact - $quota[$key], $order++, $key];
        }
        usort($rest, static fn (array $a, array $b): int => ($b[0] <=> $a[0]) ?: ($a[1] <=> $b[1]));
        for ($i = 0; $given < $max && $i < count($rest); $i++, $given++) {
            $quota[$rest[$i][2]]++;
        }
        $out = [];
        foreach ($quota as $key => $q) {
            for ($i = 0; $i < $q; $i++) {
                $out[] = $at[$key];
            }
        }
        return $out;
    }
}
