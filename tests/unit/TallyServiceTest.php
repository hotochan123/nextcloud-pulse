<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Service\TallyService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tallying for all question types. Takes Poll + Vote[], no DB.
 *
 * Section references (§…) point to the design notes of the redesign, which are
 * not in the public repository (see "References in code comments" in the
 * README).
 */
#[CoversClass(TallyService::class)]
class TallyServiceTest extends TestCase {

    private TallyService $service;

    protected function setUp(): void {
        $this->service = new TallyService();
    }

    // ── normalizeText: ONE source for grading and grouping ──────────────────

    public function testNormalizeTextCollapsesWhitespaceAndLowercases(): void {
        $this->assertSame('hallo welt', TallyService::normalizeText("  Hallo \n\t WELT  "));
    }

    public function testNormalizeTextIsMultibyteSafe(): void {
        $this->assertSame('grüße über äpfel', TallyService::normalizeText('Grüße   Über  Äpfel'));
    }

    public function testNormalizeTextOnEmptyStringStaysEmpty(): void {
        $this->assertSame('', TallyService::normalizeText("   \n "));
    }

    // ── choice / truefalse ─────────────────────────────────────────────────

    public function testChoiceCountsPerOptionAndKeepsTheOrder(): void {
        $poll = $this->poll('choice', [['id' => 'AA', 'label' => 'Ja'], ['id' => 'BB', 'label' => 'Nein']]);
        $tally = $this->service->tally($poll, [$this->vote('AA'), $this->vote('BB'), $this->vote('AA')]);

        $this->assertSame('choice', $tally['type']);
        $this->assertSame(3, $tally['total']);
        $this->assertSame([
            ['id' => 'AA', 'label' => 'Ja', 'count' => 2],
            ['id' => 'BB', 'label' => 'Nein', 'count' => 1],
        ], $tally['results']);
    }

    public function testChoiceIgnoresUnknownOptionIdsButCountsThemInTotal(): void {
        // total = votes cast, not the sum of the bars (old votes point into
        // nothing after a question has been edited).
        $poll = $this->poll('choice', [['id' => 'AA', 'label' => 'Ja']]);
        $tally = $this->service->tally($poll, [$this->vote('AA'), $this->vote('WEG')]);

        $this->assertSame(2, $tally['total']);
        $this->assertSame(1, $tally['results'][0]['count']);
    }

    public function testWithoutVotesAllOptionsStandAtZero(): void {
        $poll = $this->poll('choice', [['id' => 'AA', 'label' => 'Ja'], ['id' => 'BB', 'label' => 'Nein']]);
        $tally = $this->service->tally($poll, []);

        $this->assertSame(0, $tally['total']);
        $this->assertSame([0, 0], array_column($tally['results'], 'count'));
    }

    // ── words ──────────────────────────────────────────────────────────────

    public function testWordCloudCountsLowercasedByFrequency(): void {
        $poll = $this->poll('words', []);
        $tally = $this->service->tally($poll, [
            $this->vote(['Kaffee', 'Tee']),
            $this->vote(['kaffee', '  KAFFEE ']),
        ]);

        $this->assertSame('words', $tally['type']);
        $this->assertSame(2, $tally['total'], 'total zählt Personen, nicht Wörter');
        // The second person wrote "kaffee" and "KAFFEE" — that is ONE word,
        // so they count once for it, not twice.
        $this->assertSame([['word' => 'kaffee', 'count' => 2], ['word' => 'tee', 'count' => 1]], $tally['results']);
    }

    public function testWordCloudSkipsEmptyAndNonStrings(): void {
        $poll = $this->poll('words', []);
        $tally = $this->service->tally($poll, [$this->vote(['', '   ', 42, 'Tee'])]);

        $this->assertSame([['word' => 'tee', 'count' => 1]], $tally['results']);
    }

    // ── scale: single ──────────────────────────────────────────────────────

    public function testScaleSingleReturnsDistributionAndAverage(): void {
        $poll = $this->poll('scale', ['mode' => 'single', 'min' => 1, 'max' => 3, 'minLabel' => 'kalt', 'maxLabel' => 'heiß']);
        $tally = $this->service->tally($poll, [$this->vote(1), $this->vote(3), $this->vote(3)]);

        $this->assertSame('single', $tally['mode']);
        $this->assertSame(2.33, $tally['average']);
        $this->assertSame([
            ['value' => 1, 'count' => 1],
            ['value' => 2, 'count' => 0],
            ['value' => 3, 'count' => 2],
        ], $tally['results']);
        $this->assertSame('kalt', $tally['minLabel']);
    }

    public function testScaleSingleIgnoresValuesOutsideTheScale(): void {
        $poll = $this->poll('scale', ['mode' => 'single', 'min' => 1, 'max' => 3]);
        $tally = $this->service->tally($poll, [$this->vote(2), $this->vote(9), $this->vote('quatsch')]);

        $this->assertSame(2.0, $tally['average']);
        $this->assertSame(3, $tally['total']);
    }

    public function testScaleWithoutVotesHasAverageZero(): void {
        $poll = $this->poll('scale', ['mode' => 'single', 'min' => 1, 'max' => 5]);
        $this->assertSame(0.0, $this->service->tally($poll, [])['average']);
    }

    // ── scale: spectrum ────────────────────────────────────────────────────

    public function testSpectrumComputesAverageMinMaxPerAspect(): void {
        $poll = $this->poll('scale', [
            'mode' => 'spectrum',
            'min' => 0,
            'max' => 6,
            'aspects' => [
                ['id' => 'a1', 'label' => 'Tempo', 'poleLow' => 'langsam', 'poleHigh' => 'schnell'],
                ['id' => 'a2', 'label' => 'Klarheit', 'poleLow' => 'unklar', 'poleHigh' => 'klar'],
            ],
        ]);
        $tally = $this->service->tally($poll, [
            $this->vote(['a1' => 2, 'a2' => 6]),
            $this->vote(['a1' => 5, 'a2' => 4]),
        ]);

        $this->assertSame('spectrum', $tally['mode']);
        $this->assertSame(
            [['average' => 3.5, 'min' => 2, 'max' => 5, 'n' => 2], ['average' => 5.0, 'min' => 4, 'max' => 6, 'n' => 2]],
            array_map(static fn (array $r): array => [
                'average' => $r['average'], 'min' => $r['min'], 'max' => $r['max'], 'n' => $r['n'],
            ], $tally['results']),
        );
    }

    public function testSpectrumSkipsMissingAndOutOfRangeAspects(): void {
        $poll = $this->poll('scale', [
            'mode' => 'spectrum', 'min' => 0, 'max' => 4,
            'aspects' => [['id' => 'a1', 'label' => 'Tempo', 'poleLow' => '', 'poleHigh' => '']],
        ]);
        $tally = $this->service->tally($poll, [
            $this->vote(['a1' => 3]),
            $this->vote(['a1' => 99]),   // out of range
            $this->vote([]),             // aspect missing
        ]);

        $this->assertSame(1, $tally['results'][0]['n']);
        $this->assertSame(3.0, $tally['results'][0]['average']);
        $this->assertSame(3, $tally['total'], 'total bleibt die Zahl der Personen');
    }

    // ── scale: compass ─────────────────────────────────────────────────────

    public function testCompassReturnsPointsAndCentroid(): void {
        $poll = $this->poll('scale', ['mode' => 'compass', 'range' => 5, 'heatmapThreshold' => 40]);
        $tally = $this->service->tally($poll, [
            $this->vote(['x' => 2, 'y' => -2]),
            $this->vote(['x' => 4, 'y' => 0]),
        ]);

        $this->assertSame('compass', $tally['mode']);
        $this->assertSame([['x' => 2, 'y' => -2], ['x' => 4, 'y' => 0]], $tally['points']);
        $this->assertSame(['x' => 3.0, 'y' => -1.0], $tally['centroid']);
    }

    public function testCompassDiscardsPointsOutsideTheField(): void {
        $poll = $this->poll('scale', ['mode' => 'compass', 'range' => 3]);
        $tally = $this->service->tally($poll, [
            $this->vote(['x' => 1, 'y' => 1]),
            $this->vote(['x' => 9, 'y' => 0]),
            $this->vote(['x' => 0]),
        ]);

        $this->assertCount(1, $tally['points']);
    }

    public function testCompassWithoutVotesHasNoCentroid(): void {
        $poll = $this->poll('scale', ['mode' => 'compass', 'range' => 5]);
        $this->assertNull($this->service->tally($poll, [])['centroid']);
    }

    // ── multi ──────────────────────────────────────────────────────────────

    public function testMultipleAnswersCountsEveryTickedOption(): void {
        $poll = $this->poll('multi', [
            ['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B'], ['id' => 'CC', 'label' => 'C'],
        ]);
        $tally = $this->service->tally($poll, [
            $this->vote(['AA', 'BB']),
            $this->vote(['BB']),
        ]);

        $this->assertSame([1, 2, 0], array_column($tally['results'], 'count'));
        $this->assertSame(2, $tally['total'], 'total zählt Personen, nicht Kreuze');
    }

    // ── rank ───────────────────────────────────────────────────────────────

    public function testRankingSortsByAveragePlace(): void {
        $poll = $this->poll('rank', [
            ['id' => 'AA', 'label' => 'Tempo'], ['id' => 'BB', 'label' => 'Preis'], ['id' => 'CC', 'label' => 'Qualität'],
        ]);
        $tally = $this->service->tally($poll, [
            $this->vote(['CC', 'BB', 'AA']),
            $this->vote(['CC', 'AA', 'BB']),
            $this->vote(['BB', 'CC', 'AA']),
        ]);

        $this->assertSame('rank', $tally['type']);
        $this->assertSame(3, $tally['total']);
        // 'Qualität' 1,1,2 -> 1.33 · 'Preis' 2,3,1 -> 2.0 · 'Tempo' 3,2,3 -> 2.67
        $this->assertSame(['Qualität', 'Preis', 'Tempo'], array_column($tally['results'], 'label'));
        $this->assertSame([1.33, 2.0, 2.67], array_column($tally['results'], 'average'));
    }

    public function testRankingReturnsThePlaceDistribution(): void {
        $poll = $this->poll('rank', [
            ['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B'], ['id' => 'CC', 'label' => 'C'],
        ]);
        // A polarizes: twice at the very top, twice at the very bottom. The average
        // (2.0) hides that — the distribution shows it (§7.5).
        $tally = $this->service->tally($poll, [
            $this->vote(['AA', 'BB', 'CC']),
            $this->vote(['AA', 'BB', 'CC']),
            $this->vote(['CC', 'BB', 'AA']),
            $this->vote(['CC', 'BB', 'AA']),
        ]);

        $rows = array_column($tally['results'], 'places', 'label');
        $this->assertSame([2, 0, 2], $rows['A'], 'zweimal Platz 1, zweimal Platz 3');
        $this->assertSame([0, 4, 0], $rows['B'], 'immer in der Mitte');
        $this->assertSame([2, 0, 2], $rows['C']);
        // The sum per row is the number of votes for this item.
        foreach ($tally['results'] as $row) {
            $this->assertSame($row['n'], array_sum($row['places']));
        }
    }

    public function testRankingCountsFirstPlaces(): void {
        $poll = $this->poll('rank', [['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B']]);
        $tally = $this->service->tally($poll, [
            $this->vote(['AA', 'BB']), $this->vote(['AA', 'BB']), $this->vote(['BB', 'AA']),
        ]);

        $this->assertSame([2, 1], array_column($tally['results'], 'first'));
    }

    public function testRankingOnEqualAverageFirstPlacesDecide(): void {
        $poll = $this->poll('rank', [
            ['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B'], ['id' => 'CC', 'label' => 'C'],
        ]);
        // All three average out at 2.0: A and C each twice at the very top and
        // twice at the very bottom, B always in the middle. Whoever was ranked first more
        // often comes first; A before C is then decided by the editor position.
        $tally = $this->service->tally($poll, [
            $this->vote(['AA', 'BB', 'CC']),
            $this->vote(['CC', 'BB', 'AA']),
            $this->vote(['AA', 'BB', 'CC']),
            $this->vote(['CC', 'BB', 'AA']),
        ]);

        $this->assertSame([2.0, 2.0, 2.0], array_column($tally['results'], 'average'));
        $this->assertSame(['A', 'C', 'B'], array_column($tally['results'], 'label'), 'mehr Erstplätze zuerst');
        $this->assertSame([2, 2, 0], array_column($tally['results'], 'first'));
    }

    public function testRankingWithoutVotesKeepsTheEditorOrder(): void {
        $poll = $this->poll('rank', [['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B']]);
        $tally = $this->service->tally($poll, []);

        $this->assertSame(['A', 'B'], array_column($tally['results'], 'label'));
        $this->assertSame([0.0, 0.0], array_column($tally['results'], 'average'));
        $this->assertSame([0, 0], array_column($tally['results'], 'n'));
    }

    public function testRankingPutsUnrankedAnswersLast(): void {
        // Answer C was added only after the votes (theoretically) — without a placement
        // it must not slide to the top with average 0.
        $poll = $this->poll('rank', [
            ['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B'], ['id' => 'CC', 'label' => 'C'],
        ]);
        $tally = $this->service->tally($poll, [$this->vote(['BB', 'AA'])]);

        $this->assertSame(['B', 'A', 'C'], array_column($tally['results'], 'label'));
        $this->assertSame(0, $tally['results'][2]['n']);
    }

    public function testRankingIgnoresForeignIdsInTheVote(): void {
        $poll = $this->poll('rank', [['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B']]);
        $tally = $this->service->tally($poll, [$this->vote(['AA', 'WEG', 'BB'])]);

        // The unknown ID still takes place 2 — which pushes B down to place 3.
        $this->assertSame(1.0, $tally['results'][0]['average']);
        $this->assertSame(3.0, $tally['results'][1]['average']);
    }

    // ── number ─────────────────────────────────────────────────────────────

    public function testNumberGuessCountsWithinTheToleranceBandAndSortsAscending(): void {
        $poll = $this->poll('number', []);
        $poll->setAnswerKey(json_encode(['target' => 100, 'tolerance' => 5]));
        $tally = $this->service->tally($poll, [
            $this->vote(120), $this->vote(98), $this->vote(105), $this->vote(98),
        ]);

        $this->assertSame(3, $tally['correct'], '98, 98 und 105 liegen im Band');
        $this->assertSame([98, 105, 120], array_column($tally['results'], 'value'));
        $this->assertSame([2, 1, 1], array_column($tally['results'], 'count'));
    }

    public function testNumberGuessWithoutToleranceRequiresTheExactValue(): void {
        $poll = $this->poll('number', []);
        $poll->setAnswerKey(json_encode(['target' => 42, 'tolerance' => 0]));
        $tally = $this->service->tally($poll, [$this->vote(42), $this->vote(43)]);

        $this->assertSame(1, $tally['correct']);
    }

    // ── text ───────────────────────────────────────────────────────────────

    public function testFreeTextGroupsByNormalFormAndKnowsTheStatus(): void {
        $poll = $this->poll('text', []);
        $poll->setAnswerKey(json_encode(['accepted' => ['Paris'], 'rejected' => ['Berlin']]));
        $tally = $this->service->tally($poll, [
            $this->vote('Paris'), $this->vote('  paris '), $this->vote('Berlin'), $this->vote('Lyon'),
        ]);

        $byNorm = [];
        foreach ($tally['answers'] as $a) {
            $byNorm[$a['norm']] = $a;
        }
        $this->assertSame(2, $byNorm['paris']['count']);
        $this->assertSame('accepted', $byNorm['paris']['status']);
        $this->assertSame('rejected', $byNorm['berlin']['status']);
        $this->assertSame('pending', $byNorm['lyon']['status'], 'unbewertet bis der Moderator entscheidet');
        $this->assertSame('Paris', $byNorm['paris']['sample'], 'Rohtext der ersten Nennung als Beispiel');
    }

    public function testFreeTextSortsByFrequency(): void {
        $poll = $this->poll('text', []);
        $tally = $this->service->tally($poll, [
            $this->vote('selten'), $this->vote('oft'), $this->vote('oft'), $this->vote('oft'),
        ]);

        $this->assertSame(['oft', 'selten'], array_column($tally['answers'], 'norm'));
    }

    public function testFreeTextSkipsEmptyAnswers(): void {
        $poll = $this->poll('text', []);
        $tally = $this->service->tally($poll, [$this->vote(''), $this->vote('Paris')]);

        $this->assertCount(1, $tally['answers']);
        $this->assertSame(2, $tally['total'], 'total bleibt die Zahl der Stimmen');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** @param array $options option list (choice/multi) or scale config (scale) */
    // ── Matching ───────────────────────────────────────────────────────────

    public function testMatchingCountsPerRowAcrossTheTargets(): void {
        $poll = $this->matchPoll();
        $tally = $this->service->tally($poll, [
            $this->vote(['I1' => 'T1', 'I2' => 'T2']),
            $this->vote(['I1' => 'T1', 'I2' => 'T1']),
        ]);

        $this->assertSame('match', $tally['type']);
        $this->assertSame(2, $tally['total']);
        $this->assertSame(['I1', 'I2'], array_column($tally['results'], 'id'));
        $this->assertSame([2, 0], array_column($tally['results'][0]['targets'], 'count'));
        $this->assertSame([1, 1], array_column($tally['results'][1]['targets'], 'count'));
        $this->assertSame(2, $tally['results'][0]['n']);
    }

    public function testMatchingMarksTheMostChosenTargetAsConsensus(): void {
        $poll = $this->matchPoll();
        $tally = $this->service->tally($poll, [
            $this->vote(['I1' => 'T2', 'I2' => 'T2']),
            $this->vote(['I1' => 'T2', 'I2' => 'T1']),
        ]);

        $this->assertSame('T2', $tally['results'][0]['top']);
    }

    public function testMatchingWithoutConsensusLeavesTopEmpty(): void {
        // A tie is not an agreement — otherwise the editor order would win.
        $poll = $this->matchPoll();
        $tally = $this->service->tally($poll, [
            $this->vote(['I1' => 'T1']),
            $this->vote(['I1' => 'T2']),
        ]);

        $this->assertSame('', $tally['results'][0]['top']);
        $this->assertSame(2, $tally['results'][0]['n']);
    }

    public function testMatchingIgnoresUnknownIdsButCountsTheVote(): void {
        $poll = $this->matchPoll();
        $tally = $this->service->tally($poll, [$this->vote(['I1' => 'WEG', 'WEG' => 'T1'])]);

        $this->assertSame(1, $tally['total']);
        $this->assertSame(0, $tally['results'][0]['n']);
    }

    public function testMatchingWithoutVotesReturnsAllRowsWithZero(): void {
        $tally = $this->service->tally($this->matchPoll(), []);

        $this->assertSame(0, $tally['total']);
        $this->assertCount(2, $tally['results']);
        $this->assertSame([0, 0], array_column($tally['results'], 'n'));
        $this->assertSame(['', ''], array_column($tally['results'], 'top'));
    }

    /** Two items, two targets — the target order is the editor's. */
    private function matchPoll(): Poll {
        $poll = new Poll();
        $poll->setType('match');
        $poll->setQuestion('Was passt?');
        $poll->setOptions(json_encode([
            'items' => [['id' => 'I1', 'label' => 'Frankreich'], ['id' => 'I2', 'label' => 'Italien']],
            'targets' => [['id' => 'T1', 'label' => 'Paris'], ['id' => 'T2', 'label' => 'Rom']],
        ]));
        return $poll;
    }

    private function poll(string $type, array $options): Poll {
        $poll = new Poll();
        $poll->setType($type);
        $poll->setQuestion('Frage?');
        $poll->setOptions(json_encode($options));
        return $poll;
    }

    private function vote(mixed $value): Vote {
        $vote = new Vote();
        $vote->setPayload(json_encode(['value' => $value]));
        return $vote;
    }
}
