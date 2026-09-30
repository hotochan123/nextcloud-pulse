<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Service\PublicPayload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Size caps of the public payloads (PublicPayload), task M3 of the security
 * review: anyone with the room code can stuff votes and players, and every
 * phone and the projector refetch the state after each of them. The lists
 * keep their names and row shapes (old bundles), only shorter; the true
 * sizes and the viewer's own row come in additional fields.
 */
#[CoversClass(PublicPayload::class)]
class PublicPayloadTest extends TestCase {

    // ── Leaderboard ────────────────────────────────────────────────────────

    public function testOhneRanglisteBleibtNullMitLeerenZusatzfeldern(): void {
        $out = PublicPayload::withLeaderboard(['x' => 1, 'leaderboard' => ['alt']], null);

        $this->assertSame(['x' => 1, 'leaderboard' => null, 'leaderboardTotal' => 0, 'leaderboardMe' => null, 'leaderboardAround' => []], $out);
    }

    public function testLeereRanglisteBleibtLeer(): void {
        $out = PublicPayload::withLeaderboard([], []);

        $this->assertSame([], $out['leaderboard'], '[] bleibt [] — "noch keine Punkte" ist etwas anderes als "keine Rangliste"');
        $this->assertSame(0, $out['leaderboardTotal']);
        $this->assertNull($out['leaderboardMe']);
        $this->assertSame([], $out['leaderboardAround']);
    }

    public function testKurzeRanglisteBleibtVollstaendig(): void {
        $rows = self::rows(4, me: 2);

        $out = PublicPayload::withLeaderboard([], $rows);

        $this->assertSame(['P1', 'P2', 'P3', 'P4'], array_column($out['leaderboard'], 'nickname'));
        $this->assertSame(4, $out['leaderboardTotal']);
        $this->assertSame('P3', $out['leaderboardMe']['nickname']);
        $this->assertTrue($out['leaderboardMe']['me']);
    }

    public function testLangeRanglisteNurDieErstenZehnPlusEigeneZeile(): void {
        $rows = self::rows(20000, me: 12345);

        $out = PublicPayload::withLeaderboard([], $rows);

        $this->assertCount(10, $out['leaderboard']);
        $this->assertSame(range(1, 10), array_column($out['leaderboard'], 'rank'));
        $this->assertSame(20000, $out['leaderboardTotal']);
        $this->assertSame(['rank' => 12346, 'nickname' => 'P12346', 'score' => 20000 - 12345, 'correct' => 0, 'me' => true, 'shared' => false], $out['leaderboardMe']);
        $this->assertSame(['P12344', 'P12345', 'P12346', 'P12347', 'P12348'], array_column($out['leaderboardAround'], 'nickname'), 'zwei davor, zwei danach');
        $this->assertLessThan(3000, strlen((string)json_encode($out)), 'ein paar hundert Bytes statt Megabytes');
    }

    public function testUmgebungAmRandAbgeschnitten(): void {
        $first = PublicPayload::withLeaderboard([], self::rows(30, me: 0));
        $this->assertSame(['P1', 'P2', 'P3'], array_column($first['leaderboardAround'], 'nickname'));

        $last = PublicPayload::withLeaderboard([], self::rows(30, me: 29));
        $this->assertSame(['P28', 'P29', 'P30'], array_column($last['leaderboardAround'], 'nickname'));
    }

    public function testGeteilterRangUeberDieSchnittkanteHinweg(): void {
        // Places 10 and 11 share rank 10: the 10th row is "shared" although
        // its twin is cut off — the client could no longer see that.
        $rows = self::rows(15);
        $rows[10]['rank'] = 10;

        $out = PublicPayload::withLeaderboard([], $rows);

        $this->assertTrue($out['leaderboard'][9]['shared']);
        $this->assertFalse($out['leaderboard'][8]['shared']);
    }

    public function testBeamerOhneEigeneZeileUndEigenerSchnitt(): void {
        $out = PublicPayload::withLeaderboard([], self::rows(12), 8);

        $this->assertCount(8, $out['leaderboard']);
        $this->assertSame(12, $out['leaderboardTotal']);
        $this->assertNull($out['leaderboardMe']);
        $this->assertSame([], $out['leaderboardAround']);
    }

    // ── Tallies ────────────────────────────────────────────────────────────

    public function testWortwolkeDieHaeufigstenHundertMitSummen(): void {
        $results = [];
        for ($i = 0; $i < 5000; $i++) {
            $results[] = ['word' => 'w' . $i, 'count' => 5000 - $i];
        }
        $tally = ['type' => 'words', 'total' => 777, 'results' => $results];

        $out = PublicPayload::tally($tally);

        $this->assertCount(PublicPayload::LIST_TOP, $out['results']);
        $this->assertSame(array_slice($results, 0, 100), $out['results'], 'die Spitze, in der Reihenfolge der Auszählung');
        $this->assertSame(5000, $out['resultsTotal']);
        $this->assertSame(array_sum(array_column($results, 'count')), $out['mentions']);
        $this->assertSame(777, $out['total'], 'Personen bleiben ungekürzt');
    }

    public function testKleineWortwolkeUnveraendertPlusSummen(): void {
        $tally = ['type' => 'words', 'total' => 2, 'results' => [['word' => 'a', 'count' => 2], ['word' => 'b', 'count' => 1]]];

        $out = PublicPayload::tally($tally);

        $this->assertSame($tally['results'], $out['results']);
        $this->assertSame(2, $out['resultsTotal']);
        $this->assertSame(3, $out['mentions']);
    }

    public function testFreitextDieHaeufigstenHundertGruppen(): void {
        $answers = [];
        for ($i = 0; $i < 300; $i++) {
            $answers[] = ['norm' => 'a' . $i, 'sample' => 'A' . $i, 'count' => 300 - $i, 'status' => 'pending'];
        }
        $tally = ['type' => 'text', 'total' => 900, 'answers' => $answers, 'accepted' => ['A0']];

        $out = PublicPayload::tally($tally);

        $this->assertSame(array_slice($answers, 0, 100), $out['answers']);
        $this->assertSame(300, $out['answersTotal']);
        $this->assertSame(['A0'], $out['accepted']);
        $this->assertSame(900, $out['total']);
    }

    public function testKompassStichprobeBehaeltVerteilungUndSchwerpunkt(): void {
        // 20,000 points: 50 % at (1,1), 30 % at (-2,3), 20 % at (0,0).
        $points = [];
        for ($i = 0; $i < 20000; $i++) {
            $r = $i % 10;
            $points[] = $r < 5 ? ['x' => 1, 'y' => 1] : ($r < 8 ? ['x' => -2, 'y' => 3] : ['x' => 0, 'y' => 0]);
        }
        $tally = ['type' => 'scale', 'mode' => 'compass', 'total' => 20003, 'centroid' => ['x' => 0.1, 'y' => 1.4], 'points' => $points, 'results' => []];

        $out = PublicPayload::tally($tally);

        $this->assertCount(PublicPayload::COMPASS_SAMPLE, $out['points']);
        $this->assertSame(20000, $out['pointsTotal']);
        $this->assertSame(20003, $out['total']);
        $this->assertSame(['x' => 0.1, 'y' => 1.4], $out['centroid'], 'Schwerpunkt aus allen Stimmen');
        $per = array_count_values(array_map(static fn (array $p): string => $p['x'] . ':' . $p['y'], $out['points']));
        $this->assertSame(['1:1' => 250, '-2:3' => 150, '0:0' => 100], $per);
        $this->assertSame($out['points'], PublicPayload::tally($tally)['points'], 'deterministisch — der Beamer flackert nicht');
    }

    public function testKompassStichprobeGroessterRestUndSeltenePosition(): void {
        // 3 positions with 1001/1000/999 of 3000 points, cap 10: 3.34/3.33/3.33
        // -> 3/3/3 plus one to the largest remainder (the first).
        $points = array_merge(
            array_fill(0, 1001, ['x' => 1, 'y' => 0]),
            array_fill(0, 1000, ['x' => 0, 'y' => 1]),
            array_fill(0, 999, ['x' => -1, 'y' => -1]),
        );

        $sample = PublicPayload::compassSample($points, 10);

        $this->assertCount(10, $sample);
        $this->assertSame(['1:0' => 4, '0:1' => 3, '-1:-1' => 3], array_count_values(array_map(static fn (array $p): string => $p['x'] . ':' . $p['y'], $sample)));
    }

    public function testKleinerKompassUnveraendert(): void {
        $points = [['x' => 1, 'y' => 2], ['x' => -3, 'y' => 0]];
        $tally = ['type' => 'scale', 'mode' => 'compass', 'total' => 2, 'points' => $points, 'results' => []];

        $out = PublicPayload::tally($tally);

        $this->assertSame($points, $out['points']);
        $this->assertSame(2, $out['pointsTotal']);
    }

    public function testAndereTypenUnberuehrt(): void {
        foreach ([
            ['type' => 'choice', 'total' => 3, 'results' => [['id' => 'AA', 'label' => 'A', 'count' => 3]]],
            ['type' => 'scale', 'mode' => 'single', 'total' => 1, 'results' => [['value' => 1, 'count' => 1]]],
            ['type' => 'number', 'total' => 1, 'correct' => 0, 'results' => [['value' => 4, 'count' => 1]]],
            ['type' => 'rank', 'total' => 0, 'results' => []],
        ] as $tally) {
            $this->assertSame($tally, PublicPayload::tally($tally), $tally['type']);
        }
        $this->assertNull(PublicPayload::tally(null));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** $n leaderboard rows with distinct ranks 1..n, row $me (index) is the viewer's. */
    private static function rows(int $n, ?int $me = null): array {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['rank' => $i + 1, 'nickname' => 'P' . ($i + 1), 'score' => $n - $i, 'correct' => 0, 'me' => $i === $me];
        }
        return $rows;
    }
}
