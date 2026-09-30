<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\CsvFormat;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\StateService;
use OCA\Pulse\Service\TallyService;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * CSV of the moderated room (StateService::exportCsv) with quiz questions.
 *
 * Free text counts under `answers`, not `results` — the general branch
 * ran into a TypeError on it (500 for every quiz with a free-text question). A
 * number guess carries its number in `value`, the answer column stayed empty. Free
 * text from the audience (free text, words) never becomes a formula, just as in
 * self-paced mode (CsvFormat::cell).
 */
#[CoversClass(StateService::class)]
#[CoversClass(CsvFormat::class)]
class QuizCsvTest extends TestCase {

    private StateService $service;
    /** @var Poll[] deck in order */
    private array $deck = [];
    /** @var array<int, Vote[]> votes per question */
    private array $votes = [];

    protected function setUp(): void {
        $deck = $this->createMock(DeckService::class);
        $deck->method('deck')->willReturnCallback(fn (): array => $this->deck);

        $voteMapper = $this->createMock(VoteMapper::class);
        $voteMapper->method('findByPoll')->willReturnCallback(fn (int $id): array => $this->votes[$id] ?? []);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $l10n->method('getLocaleCode')->willReturn('en');

        $this->service = (new \ReflectionClass(StateService::class))->newInstanceWithoutConstructor();
        foreach ([
            'deckService' => $deck,
            'voteMapper' => $voteMapper,
            'tallyService' => new TallyService(),
            'l10n' => $l10n,
        ] as $name => $value) {
            (new ReflectionProperty(StateService::class, $name))->setValue($this->service, $value);
        }
    }

    public function testFreeTextOneRowPerAnswerGroup(): void {
        $this->poll(1, 'text', ['accepted' => ['Jupiter'], 'rejected' => ['Saturn']], ['Jupiter', 'Saturn', 'jupiter', '=HYPERLINK("x")']);

        $lines = $this->csv();

        $this->assertSame(['No.', 'Question', 'Type', 'Answer', 'Votes', 'Share'], $lines[0]);
        $this->assertSame([
            ['1', 'Frage 1?', 'Free text', 'Jupiter', '2', '50%'],
            ['1', 'Frage 1?', 'Free text', 'Saturn', '1', '25%'],
            ['1', 'Frage 1?', 'Free text', '\'=HYPERLINK("x")', '1', '25%'],
        ], array_slice($lines, 1));
    }

    public function testFreeTextWithoutAnswersStaysVisible(): void {
        $this->poll(1, 'text', ['accepted' => ['Jupiter'], 'rejected' => []], []);

        $this->assertSame([['1', 'Frage 1?', 'Free text', '(no answers)', '0', '']], array_slice($this->csv(), 1));
    }

    public function testNumberGuessNamesTheNumber(): void {
        $this->poll(1, 'number', ['target' => 1969, 'tolerance' => 1], [1969, 1950.5, 1969]);

        $this->assertSame([
            ['1', 'Frage 1?', 'Number guess', CsvFormat::number(1950.5, 'en'), '1', '33%'],
            ['1', 'Frage 1?', 'Number guess', CsvFormat::number(1969, 'en'), '2', '67%'],
        ], array_slice($this->csv(), 1));
    }

    public function testWordFromTheAudienceBecomesNoFormula(): void {
        $this->poll(1, 'words', null, [['=cmd'], ['Kaffee']]);

        $answers = array_column(array_slice($this->csv(), 1), 3);

        $this->assertSame(["'=cmd", 'kaffee'], $answers);
    }

    public function testQuizWithFreeTextBetweenOtherQuestions(): void {
        // The reported case: a deck with free text aborted the whole export.
        $this->poll(1, 'truefalse', null, ['T'], [['id' => 'T', 'label' => 'True'], ['id' => 'F', 'label' => 'False']]);
        $this->poll(2, 'text', ['accepted' => ['Jupiter'], 'rejected' => []], ['Jupiter']);
        $this->poll(3, 'number', ['target' => 1969, 'tolerance' => 1], [1969]);

        $lines = $this->csv();

        $this->assertSame(['1', '1', '2', '3'], array_column(array_slice($lines, 1), 0));
        $this->assertSame(['True', 'False', 'Jupiter'], array_column(array_slice($lines, 1, 3), 3));
    }

    public function testQuotesPerRfc4180(): void {
        // No backslash escape: \" stays text; the character after the backslash is doubled.
        $this->poll(1, 'text', ['accepted' => [], 'rejected' => []], ['a\\"b']);

        $raw = $this->raw();

        $this->assertStringContainsString(';"a\\""b";', $raw);
        $this->assertSame('a\\"b', $this->csv()[1][3]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * @param ?array $key answer key (free text: accepted/rejected, number guess: target/tolerance)
     * @param list<mixed> $values stored vote values
     */
    private function poll(int $id, string $type, ?array $key, array $values, array $options = []): void {
        $poll = new Poll();
        $poll->setId($id);
        $poll->setRoomId(5);
        $poll->setType($type);
        $poll->setQuestion('Frage ' . $id . '?');
        $poll->setOptions(json_encode($options));
        if ($key !== null) {
            $poll->setAnswerKey(json_encode($key));
        }
        $this->deck[] = $poll;
        foreach ($values as $value) {
            $vote = new Vote();
            $vote->setPollId($id);
            $vote->setPayload(json_encode(['value' => $value]));
            $this->votes[$id][] = $vote;
        }
    }

    private function raw(): string {
        $room = new Room();
        $room->setMode('quiz');
        $csv = $this->service->exportCsv($room);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        return substr($csv, 3);
    }

    /** @return list<list<string>> */
    private function csv(): array {
        $lines = [];
        foreach (preg_split('/\r?\n/', trim($this->raw())) as $line) {
            $lines[] = str_getcsv($line, ';', '"', '');
        }
        return $lines;
    }
}
