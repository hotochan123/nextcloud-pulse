<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\Progress;
use OCA\Pulse\Service\PaceStateService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Golden bytes of both self-paced CSV views (PaceStateService::exportCsv),
 * the counterpart of ModeratedCsvGoldenTest: a race over all seven quiz
 * types with someone who finished, someone halfway with an answer being
 * checked, someone who never started and someone still inside the
 * correction window, whose name looks like a formula.
 */
#[CoversClass(PaceStateService::class)]
class SelfCsvGoldenTest extends PaceStateTestCase {

    protected function setUp(): void {
        if (!class_exists(\NumberFormatter::class)) {
            $this->markTestSkipped('intl missing: numbers would come out unformatted');
        }
        parent::setUp();
        $this->room->setDeckOrder('[11,12,13,14,15,16,17]');
        $this->polls[14] = $this->extraPoll(14, 'truefalse', 'Pluto is a planet.', [['id' => 'T', 'label' => 'True'], ['id' => 'F', 'label' => 'False']]);
        $this->polls[14]->setCorrectOption('F');
        $this->polls[15] = $this->extraPoll(15, 'multi', 'Gas giants?', [['id' => 'JU', 'label' => 'Jupiter'], ['id' => 'MA', 'label' => 'Mars'], ['id' => 'SA', 'label' => 'Saturn']]);
        $this->polls[16] = $this->extraPoll(16, 'number', 'Year of the moon landing?', []);
        $this->polls[17] = $this->extraPoll(17, 'match', 'Planet and colour', [
            'items' => [['id' => 'P1', 'label' => 'Mars'], ['id' => 'P2', 'label' => 'Neptune']],
            'targets' => [['id' => 'C1', 'label' => 'red'], ['id' => 'C2', 'label' => 'blue']],
        ]);
        $this->players[] = $this->player(34, 'tok-eve', '=Eve');

        // Anna: through all seven, the last row still open but answered.
        foreach ([11, 12, 13, 14, 15, 16] as $seq => $pollId) {
            $this->progress($pollId, $seq, 'tok-anna', self::NOW - 95 + 10 * $seq, self::NOW - 86 + 10 * $seq);
        }
        $this->progress(17, 6, 'tok-anna', self::NOW - 35);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 90, elapsed: 4);
        $this->vote(12, 'tok-anna', ['JU01', 'SA02', 'NE03'], 1000, true, self::NOW - 80, elapsed: 20_000, limit: 0);
        $this->vote(13, 'tok-anna', 'jupiter', 800, true, self::NOW - 70, elapsed: 8);
        $this->vote(14, 'tok-anna', 'F', 950, true, self::NOW - 60, elapsed: 2);
        $this->vote(15, 'tok-anna', ['JU', 'MA'], 0, false, self::NOW - 50, elapsed: 6);
        $this->vote(16, 'tok-anna', 1969.5, 0, false, self::NOW - 40, elapsed: 3);
        $this->vote(17, 'tok-anna', ['P1' => 'C1', 'P2' => 'C2'], 700, true, self::NOW - 30, elapsed: 12);

        // Ben: wrong, skipped one, free text being checked on the open row.
        $this->progress(11, 0, 'tok-ben', self::NOW - 90, self::NOW - 80);
        $this->progress(12, 1, 'tok-ben', self::NOW - 80, self::NOW - 60);
        $this->progress(13, 2, 'tok-ben', self::NOW - 60);
        $this->vote(11, 'tok-ben', 'BB', 0, false, self::NOW - 85, elapsed: 5);
        $this->vote(13, 'tok-ben', 'Saturn', 0, false, self::NOW - 20, elapsed: 9, pending: true);

        // Cem never started. Eve answered a second ago: still correctable.
        $this->progress(11, 0, 'tok-eve', self::NOW - 10);
        $this->vote(11, 'tok-eve', 'AA', 950, true, self::NOW - 1, elapsed: 7);
    }

    public function testPlayers(): void {
        $expected = <<<'CSV'
            Rank;Name;Score;Correct;Answered;Reached;Finished;Started;"Last activity";"Time (s)"
            1;Anna;4350;5;7;"7 / 7";yes;T1799999905;T1799999970;35
            2;'=Eve;0;0;1;"1 / 7";no;T1799999990;T1799999999;
            2;Ben;0;0;2;"3 / 7";no;T1799999910;T1799999980;14
            2;Cem;0;0;0;"0 / 7";no;;;
            CSV;
        $this->assertSame("\xEF\xBB\xBF" . $expected . "\n", $this->service->exportCsv($this->room, 'players'));
    }

    public function testAnswers(): void {
        $expected = <<<'CSV'
            Name;No.;Question;Type;Answer;Result;Points;"Time (s)";"Answered at"
            '=Eve;1;"Hauptstadt von Frankreich?";"Multiple choice";Paris;—;;7;T1799999999
            Anna;1;"Hauptstadt von Frankreich?";"Multiple choice";Paris;Correct;900;4;T1799999910
            Ben;1;"Hauptstadt von Frankreich?";"Multiple choice";Berlin;Wrong;0;5;T1799999915
            Anna;2;"Planeten nach Größe";Ranking;"Jupiter > Saturn > Neptun";Correct;1000;;T1799999920
            Anna;3;"Größter Planet?";"Free text";jupiter;Correct;800;8;T1799999930
            Ben;3;"Größter Planet?";"Free text";Saturn;Pending;0;9;T1799999980
            Anna;4;"Pluto is a planet.";True/False;False;Correct;950;2;T1799999940
            Anna;5;"Gas giants?";"Multiple answers";"Jupiter, Mars";Wrong;0;6;T1799999950
            Anna;6;"Year of the moon landing?";"Number guess";1,969.5;Wrong;0;3;T1799999960
            Anna;7;"Planet and colour";Matching;"Mars → red; Neptune → blue";Correct;700;12;T1799999970
            CSV;
        $this->assertSame("\xEF\xBB\xBF" . $expected . "\n", $this->service->exportCsv($this->room, 'answers'));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function extraPoll(int $id, string $type, string $question, array $options): Poll {
        $poll = new Poll();
        $poll->setId($id);
        $poll->setRoomId(5);
        $poll->setType($type);
        $poll->setQuestion($question);
        $poll->setOptions(json_encode($options));
        $poll->setStatus('active');
        $poll->setPosition($id - 11);
        $poll->setTimeLimit(20);
        return $poll;
    }

    /** Progress row with an explicit seq (row() only knows the fixture's three questions). */
    private function progress(int $pollId, int $seq, string $token, int $startedAt, int $leftAt = 0): void {
        $row = new Progress();
        $row->setRoomId(5);
        $row->setPollId($pollId);
        $row->setVoterToken($token);
        $row->setSeq($seq);
        $row->setStartedAt($startedAt);
        $row->setLeftAt($leftAt);
        $this->rows[] = $row;
    }
}
