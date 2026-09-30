<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Service\PaceStateService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Self-paced CSV views (PaceStateService::exportCsv).
 *
 * Without a timer, elapsed holds the whole idle time between /next and the answer —
 * hours for homework. The time column stays empty there instead of summing up
 * nonsense. "Finished" follows PaceService::isFinished: anyone who answered the last
 * question but never tapped "I’m done" counts as finished once the quiz is closed.
 */
#[CoversClass(PaceStateService::class)]
class SelfCsvTest extends PaceStateTestCase {

    public function testAnswersTimeOnlyWithLimit(): void {
        $this->row(11, 'tok-anna', self::NOW - 90, self::NOW - 60);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 80, elapsed: 10);
        $this->row(12, 'tok-anna', self::NOW - 60, self::NOW - 30);
        $this->vote(12, 'tok-anna', ['JU01', 'SA02', 'NE03'], 1000, true, self::NOW - 40, elapsed: 20_000, limit: 0);

        $lines = $this->csv('answers');

        $this->assertSame(['Name', 'No.', 'Question', 'Type', 'Answer', 'Result', 'Points', 'Time (s)', 'Answered at'], $lines[0]);
        $this->assertSame(['Anna', '1', 'Hauptstadt von Frankreich?', 'Multiple choice', 'Paris', 'Correct', '900', '10', 'T' . (self::NOW - 80)], $lines[1]);
        $this->assertSame(['Anna', '2', 'Planeten nach Größe', 'Ranking', 'Jupiter > Saturn > Neptun', 'Correct', '1000', '', 'T' . (self::NOW - 40)], $lines[2]);
        $this->assertCount(3, $lines, 'eine Zeile je Stimme');
    }

    public function testAnswersByQuestionThenNameAndResult(): void {
        $this->row(11, 'tok-cem', self::NOW - 90);
        $this->vote(11, 'tok-cem', 'BB', 0, false, self::NOW - 80);
        $this->row(11, 'tok-anna', self::NOW - 90);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 2); // still within the correction window
        $this->row(13, 'tok-ben', self::NOW - 30);
        $this->vote(13, 'tok-ben', 'Saturn', 0, false, self::NOW - 20, pending: true);

        $lines = $this->csv('answers');

        $this->assertSame([['Anna', '1', '—', ''], ['Cem', '1', 'Wrong', '0'], ['Ben', '3', 'Pending', '0']], array_map(
            static fn (array $l): array => [$l[0], $l[1], $l[5], $l[6]],
            array_slice($lines, 1),
        ));
    }

    public function testAnswersReadable(): void {
        $this->room->setDeckOrder('[11,12,13,14,15]');
        $multi = $this->choicePoll();
        $multi->setId(14);
        $multi->setType('multi');
        $this->polls[14] = $multi;
        $number = $this->choicePoll();
        $number->setId(15);
        $number->setType('number');
        $this->polls[15] = $number;
        $this->vote(14, 'tok-anna', ['AA', 'BB'], 0, false, self::NOW - 50);
        $this->vote(15, 'tok-anna', 1969.5, 0, false, self::NOW - 50);
        $this->vote(13, 'tok-anna', '=HYPERLINK("x")', 0, false, self::NOW - 50);

        $answers = array_column(array_slice($this->csv('answers'), 1), 4, 1);

        $this->assertSame('Paris, Berlin', $answers['4']);
        $this->assertSame('1,969.5', $answers['5'], 'Zahl in der Sprache des Exports');
        $this->assertSame('\'=HYPERLINK("x")', $answers['3'], 'Freitext aus dem Publikum wird keine Formel');
    }

    public function testPlayersWithoutTimerTimeEmpty(): void {
        $this->room->setTimed(false);
        $this->row(11, 'tok-anna', self::NOW - 90_000, self::NOW - 80_000);
        $this->vote(11, 'tok-anna', 'AA', 1000, true, self::NOW - 85_000, elapsed: 5000, limit: 0);

        $anna = $this->playerLine('Anna');

        $this->assertSame('', $anna[9]);
        $this->assertSame('1000', $anna[2]);
    }

    public function testPlayersWithTimerTimeIsSumOfFinalVotes(): void {
        $this->row(11, 'tok-anna', self::NOW - 90, self::NOW - 60);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 80, elapsed: 10);
        $this->row(12, 'tok-anna', self::NOW - 60);
        $this->vote(12, 'tok-anna', ['JU01', 'SA02', 'NE03'], 800, true, self::NOW - 2, elapsed: 7); // not final yet

        $anna = $this->playerLine('Anna');

        $this->assertSame(['1', 'Anna', '900', '1', '2', '2 / 3', 'no', 'T' . (self::NOW - 90), 'T' . (self::NOW - 2), '10'], $anna);
    }

    public function testFinishedFollowsIsFinished(): void {
        // Answered the last question, never tapped "I’m done", window closed -> finished.
        $this->row(11, 'tok-anna', self::NOW - 90, self::NOW - 60);
        $this->row(12, 'tok-anna', self::NOW - 60, self::NOW - 30);
        $this->row(13, 'tok-anna', self::NOW - 30);
        $this->vote(13, 'tok-anna', 'Jupiter', 1000, true, self::NOW - 20);
        // Ben reached the last question but did not answer it.
        $this->row(11, 'tok-ben', self::NOW - 90, self::NOW - 60);
        $this->row(12, 'tok-ben', self::NOW - 60, self::NOW - 30);
        $this->row(13, 'tok-ben', self::NOW - 30);

        $this->assertSame('yes', $this->playerLine('Anna')[6], 'beantwortet, offen');
        $this->assertSame('no', $this->playerLine('Ben')[6], 'unbeantwortet, offen');

        $this->close();
        $this->assertSame('yes', $this->playerLine('Anna')[6]);
        $this->assertSame('yes', $this->playerLine('Ben')[6], 'Fenster zu');
        $this->assertSame('no', $this->playerLine('Cem')[6], 'nie gestartet');
    }

    public function testPlayersInLeaderboardOrderWithBom(): void {
        $this->row(11, 'tok-cem', self::NOW - 90, self::NOW - 60);
        $this->vote(11, 'tok-cem', 'AA', 900, true, self::NOW - 80);

        $raw = $this->service->exportCsv($this->room, 'players');
        $lines = $this->csv('players');

        $this->assertStringStartsWith("\xEF\xBB\xBF", $raw);
        $this->assertSame(['Rank', 'Name', 'Score', 'Correct', 'Answered', 'Reached', 'Finished', 'Started', 'Last activity', 'Time (s)'], $lines[0]);
        $this->assertSame(['Cem', 'Anna', 'Ben'], array_column(array_slice($lines, 1), 1));
        $this->assertSame(['', ''], [$lines[2][7], $lines[2][8]], 'nie gestartet: leere Zeitpunkte');
        $this->assertSame('0 / 3', $lines[2][5]);
    }

    public function testUnknownView(): void {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->exportCsv($this->room, 'results');
    }

    /** @return list<list<string>> */
    private function csv(string $view): array {
        $raw = substr($this->service->exportCsv($this->room, $view), 3);
        $lines = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            $lines[] = str_getcsv($line, ';', '"', '');
        }
        return $lines;
    }

    /** @return list<string> */
    private function playerLine(string $name): array {
        foreach (array_slice($this->csv('players'), 1) as $line) {
            if ($line[1] === $name) {
                return $line;
            }
        }
        $this->fail('keine Zeile für ' . $name);
    }
}
