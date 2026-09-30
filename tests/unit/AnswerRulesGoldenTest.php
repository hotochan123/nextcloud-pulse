<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Service\QuizService;
use OCA\Pulse\Service\VoteService;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Golden answers of the per-type vote rules, as VoteService has them today:
 *
 * - normalizeValue: what it returns for every question type and scale mode
 *   (exact value, type and key order) and the exact message it rejects
 *   with. The order of the checks decides which message a phone shows, so
 *   inputs that fail two checks are in here too.
 * - quizPayload: the payload per quiz type, keys in their stored order —
 *   VoteMapper::contentStamp hashes it.
 * - the free-text verdict: accepted, being checked, rejected.
 *
 * An unknown type is treated like a word cloud; that fall-through is pinned
 * as well. The three helpers at the end are the one place to point at the
 * rules' new home when they move.
 */
#[CoversClass(VoteService::class)]
class AnswerRulesGoldenTest extends TestCase {

    private VoteService $service;

    protected function setUp(): void {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(VoteService::class, 'l10n'))->setValue($this->service, $l10n);
        (new ReflectionProperty(VoteService::class, 'quizService'))->setValue($this->service, new QuizService());
    }

    // ── normalizeValue: accepted ──────────────────────────────────────────

    public static function accepted(): array {
        return [
            'choice' => ['choice', 'BB', 'BB'],
            'truefalse' => ['truefalse', 'T', 'T'],
            'multi: deduplicated, unknown dropped, sorted' => ['multi', ['C3', 'A1', 'C3', 'ZZ', 5], ['A1', 'C3']],
            'rank: a full permutation, as sent' => ['rank', ['R3', 'R1', 'R2'], ['R3', 'R1', 'R2']],
            'match: item order, extra keys dropped, a target twice' => ['match', ['I2' => 'T1', 'X' => 'T2', 'I1' => 'T1'], ['I1' => 'T1', 'I2' => 'T1']],
            'number: int' => ['number', 12, 12],
            'number: digits' => ['number', '1969', 1969],
            'number: exponent text is a float' => ['number', '1e3', 1000.0],
            'number: float' => ['number', -0.5, -0.5],
            'text: whitespace collapsed and trimmed' => ['text', "  Hello \n\t world  ", 'Hello world'],
            'text: cut to 100 characters' => ['text', str_repeat('é', 150), str_repeat('é', 100)],
            'text: NUL kept' => ['text', "a\0b", "a\0b"],
            'scale: digits' => ['single', '3', 3],
            'scale: decimals truncated' => ['single', 4.9, 4],
            'spectrum: aspect order, extra keys dropped' => ['spectrum', ['S2' => '7', 'X' => 1, 'S1' => 0], ['S1' => 0, 'S2' => 7]],
            'compass: key order, truncated' => ['compass', ['y' => 2.7, 'x' => '-5'], ['x' => -5, 'y' => 2]],
            'words: one word as text' => ['words', 'Coffee', ['Coffee']],
            'words: first spelling wins, stops at maxWords' => ['words', ['Coffee', 'COFFEE', 'tea', 'juice', 'water'], ['Coffee', 'tea', 'juice']],
            'words: lists and invisible entries skipped, numbers as text' => ['words', [['x'], "\u{200B}", 'tea', 42], ['tea', '42']],
            'words: cut to 40 characters' => ['words', [str_repeat('x', 50)], [str_repeat('x', 40)]],
            'words: twenty entries are still a vote' => ['words', array_fill(0, 20, 'a'), ['a']],
            'unknown type: like a word cloud' => ['unknown', 'Coffee', ['Coffee']],
        ];
    }

    #[DataProvider('accepted')]
    public function testNormalizeValueReturns(string $kind, mixed $value, mixed $expected): void {
        $this->assertSame($expected, $this->normalize(self::poll($kind), $value));
    }

    // ── normalizeValue: rejected ──────────────────────────────────────────

    public static function rejected(): array {
        return [
            'choice: not text' => ['choice', 5, 'Invalid selection.'],
            'choice: a list' => ['choice', ['AA'], 'Invalid selection.'],
            'choice: unknown option' => ['choice', 'ZZ', 'No such option.'],
            'truefalse: missing' => ['truefalse', null, 'Invalid selection.'],
            'truefalse: unknown option' => ['truefalse', 'X', 'No such option.'],
            'multi: not a list' => ['multi', 'A1', 'Invalid selection.'],
            'multi: nothing valid' => ['multi', ['ZZ', 5], 'Please choose at least one option.'],
            'multi: empty' => ['multi', [], 'Please choose at least one option.'],
            'rank: not a list' => ['rank', 'R1', 'Invalid order.'],
            'rank: duplicate' => ['rank', ['R1', 'R1', 'R2'], 'Invalid order.'],
            'rank: not text' => ['rank', ['R1', 5, 'R2'], 'Invalid order.'],
            'rank: unknown option before the length check' => ['rank', ['R1', 'R2', 'R3', 'R4'], 'Invalid order.'],
            'rank: incomplete' => ['rank', ['R1', 'R2'], 'Please sort all answers.'],
            'match: not a list' => ['match', 'T1', 'Invalid assignment.'],
            'match: an item left open' => ['match', ['I1' => 'T1'], 'Please assign every item.'],
            'match: unknown target' => ['match', ['I1' => 'T9', 'I2' => 'T1'], 'Please assign every item.'],
            'match: no items at all' => ['match-empty', [], 'Invalid assignment.'],
            'number: text' => ['number', 'abc', 'Please enter a number.'],
            'number: infinite' => ['number', '1e999', 'Please enter a number.'],
            'number: bool' => ['number', true, 'Please enter a number.'],
            'number: a list' => ['number', [1], 'Please enter a number.'],
            'text: not text' => ['text', 5, 'Invalid answer.'],
            'text: blank' => ['text', " \n ", 'Please enter an answer.'],
            'scale: text' => ['single', 'x', 'Invalid value.'],
            'scale: a list' => ['single', [3], 'Invalid value.'],
            'scale: above' => ['single', 6, 'Value is outside the scale.'],
            'scale: below' => ['single', 0, 'Value is outside the scale.'],
            'spectrum: not a map' => ['spectrum', 'x', 'Invalid answer.'],
            'spectrum: an aspect missing' => ['spectrum', ['S1' => 1], 'Invalid value.'],
            'spectrum: outside' => ['spectrum', ['S1' => 11, 'S2' => 0], 'Value is outside the scale.'],
            'spectrum: first aspect decides' => ['spectrum', ['S1' => 'x', 'S2' => 11], 'Invalid value.'],
            'compass: not a map' => ['compass', 'x', 'Invalid position.'],
            'compass: y missing' => ['compass', ['x' => 1], 'Invalid position.'],
            'compass: outside' => ['compass', ['x' => 6, 'y' => 0], 'Position is outside the field.'],
            'compass: invalid before outside' => ['compass', ['x' => 'a', 'y' => 9], 'Invalid position.'],
            'words: a number' => ['words', 42, 'Invalid words.'],
            'words: missing' => ['words', null, 'Invalid words.'],
            'words: too many entries' => ['words', array_fill(0, 21, 'a'), 'Invalid words.'],
            'words: nothing visible' => ['words', ['', ' ', "\u{200B}"], 'Please enter at least one word.'],
            'unknown type: a number' => ['unknown', 42, 'Invalid words.'],
            'unknown type: empty' => ['unknown', [], 'Please enter at least one word.'],
        ];
    }

    #[DataProvider('rejected')]
    public function testNormalizeValueRejectsWithTheExactMessage(string $kind, mixed $value, string $message): void {
        try {
            $this->normalize(self::poll($kind), $value);
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
            return;
        }
        $this->fail('InvalidArgumentException expected');
    }

    // ── quizPayload ───────────────────────────────────────────────────────

    /** Time limit of every question: 20 s, so 5 s earn 875 points and 20 s or more 500. */
    public static function payloads(): array {
        return [
            'choice: correct' => ['choice', 'AA', 5, null, ['value' => 'AA', 'points' => 875, 'correct' => true, 'elapsed' => 5]],
            'choice: wrong' => ['choice', 'BB', 5, null, ['value' => 'BB', 'points' => 0, 'correct' => false, 'elapsed' => 5]],
            'choice: no correct option set' => ['choice-open', '', 5, null, ['value' => '', 'points' => 0, 'correct' => false, 'elapsed' => 5]],
            'truefalse: correct at once' => ['truefalse', 'F', 0, null, ['value' => 'F', 'points' => 1000, 'correct' => true, 'elapsed' => 0]],
            'multi: all, in any order' => ['multi', ['C3', 'A1'], 10, null, ['value' => ['C3', 'A1'], 'points' => 750, 'correct' => true, 'elapsed' => 10]],
            'multi: a subset is wrong' => ['multi', ['A1'], 10, null, ['value' => ['A1'], 'points' => 0, 'correct' => false, 'elapsed' => 10]],
            'rank: exact order' => ['rank', ['R1', 'R2', 'R3'], 20, null, ['value' => ['R1', 'R2', 'R3'], 'points' => 500, 'correct' => true, 'elapsed' => 20]],
            'rank: other order' => ['rank', ['R2', 'R1', 'R3'], 20, null, ['value' => ['R2', 'R1', 'R3'], 'points' => 0, 'correct' => false, 'elapsed' => 20]],
            'match: every pair, late' => ['match', ['I2' => 'T2', 'I1' => 'T1'], 40, null, ['value' => ['I2' => 'T2', 'I1' => 'T1'], 'points' => 500, 'correct' => true, 'elapsed' => 40]],
            'match: one pair wrong' => ['match', ['I1' => 'T1', 'I2' => 'T3'], 5, null, ['value' => ['I1' => 'T1', 'I2' => 'T3'], 'points' => 0, 'correct' => false, 'elapsed' => 5]],
            'number: inside the tolerance' => ['number', 1970, 5, null, ['value' => 1970, 'points' => 875, 'correct' => true, 'elapsed' => 5]],
            'number: outside the tolerance' => ['number', 1971.5, 5, null, ['value' => 1971.5, 'points' => 0, 'correct' => false, 'elapsed' => 5]],
            'text: accepted' => ['text', ' JUPITER ', 5, null, ['value' => ' JUPITER ', 'points' => 875, 'correct' => true, 'elapsed' => 5, 'norm' => 'jupiter']],
            'text: rejected' => ['text', 'Mars', 5, null, ['value' => 'Mars', 'points' => 0, 'correct' => false, 'elapsed' => 5, 'norm' => 'mars']],
            'text: not graded yet' => ['text', 'Saturn', 5, null, ['value' => 'Saturn', 'points' => 0, 'correct' => false, 'elapsed' => 5, 'norm' => 'saturn']],
            'self-paced without timer: flat points' => ['choice', 'AA', 500, 0, ['value' => 'AA', 'points' => 1000, 'correct' => true, 'elapsed' => 500]],
            'self-paced with its own limit' => ['choice', 'AA', 10, 40, ['value' => 'AA', 'points' => 875, 'correct' => true, 'elapsed' => 10]],
            'unknown type: never correct' => ['unknown', 'Coffee', 5, null, ['value' => 'Coffee', 'points' => 0, 'correct' => false, 'elapsed' => 5]],
        ];
    }

    #[DataProvider('payloads')]
    public function testQuizPayload(string $kind, mixed $normalized, int $elapsed, ?int $limit, array $expected): void {
        $this->assertSame($expected, $this->payload(self::poll($kind), $normalized, $elapsed, $limit));
    }

    // ── Free-text verdict ─────────────────────────────────────────────────

    public static function verdicts(): array {
        return [
            // normalised form => [correct, being checked]
            'accepted' => ['jupiter', [true, false]],
            'not graded yet' => ['saturn', [false, true]],
            'rejected' => ['mars', [false, false]],
        ];
    }

    #[DataProvider('verdicts')]
    public function testTextVerdict(string $norm, array $expected): void {
        $this->assertSame($expected, $this->verdict(self::poll('text'), $norm));
    }

    // ── Questions ─────────────────────────────────────────────────────────

    private static function poll(string $kind): Poll {
        $choice = [['id' => 'AA', 'label' => 'Paris'], ['id' => 'BB', 'label' => 'Berlin']];
        $targets = [['id' => 'T1', 'label' => 'Paris'], ['id' => 'T2', 'label' => 'Rome'], ['id' => 'T3', 'label' => 'Madrid']];
        [$type, $options, $key, $correct] = match ($kind) {
            'choice' => ['choice', $choice, null, 'AA'],
            'choice-open' => ['choice', $choice, null, ''],
            'truefalse' => ['truefalse', [['id' => 'T', 'label' => 'True'], ['id' => 'F', 'label' => 'False']], null, 'F'],
            'multi' => ['multi', [['id' => 'A1', 'label' => 'Mars'], ['id' => 'B2', 'label' => 'Moon'], ['id' => 'C3', 'label' => 'Venus']], ['correct' => ['A1', 'C3']], ''],
            'rank' => ['rank', [['id' => 'R1', 'label' => 'Jupiter'], ['id' => 'R2', 'label' => 'Saturn'], ['id' => 'R3', 'label' => 'Neptune']], ['order' => ['R1', 'R2', 'R3']], ''],
            'match' => ['match', ['items' => [['id' => 'I1', 'label' => 'France'], ['id' => 'I2', 'label' => 'Italy']], 'targets' => $targets], ['map' => ['I1' => 'T1', 'I2' => 'T2']], ''],
            'match-empty' => ['match', ['items' => [], 'targets' => $targets], null, ''],
            'number' => ['number', [], ['target' => 1969, 'tolerance' => 1], ''],
            'text' => ['text', [], ['accepted' => ['Jupiter'], 'rejected' => [' MARS ']], ''],
            'single' => ['scale', ['mode' => 'single', 'min' => 1, 'max' => 5], null, ''],
            'spectrum' => ['scale', ['mode' => 'spectrum', 'min' => 0, 'max' => 10, 'aspects' => [['id' => 'S1', 'label' => 'Content'], ['id' => 'S2', 'label' => 'Delivery']]], null, ''],
            'compass' => ['scale', ['mode' => 'compass', 'range' => 5], null, ''],
            'words' => ['words', [], null, ''],
            'unknown' => ['bogus', [], null, ''],
        };
        $poll = new Poll();
        $poll->setId(1);
        $poll->setRoomId(5);
        $poll->setType($type);
        $poll->setOptions(json_encode($options));
        $poll->setMaxWords(3);
        $poll->setTimeLimit(20);
        $poll->setCorrectOption($correct);
        if ($key !== null) {
            $poll->setAnswerKey(json_encode($key));
        }
        return $poll;
    }

    // ── The rules under test ──────────────────────────────────────────────

    private function normalize(Poll $poll, mixed $value): mixed {
        return $this->service->normalizeValue($poll, $value);
    }

    private function payload(Poll $poll, mixed $normalized, int $elapsed, ?int $limit): array {
        return $this->service->quizPayload($poll, $normalized, $elapsed, $limit);
    }

    /** @return array{0: bool, 1: bool} [correct, being checked] */
    private function verdict(Poll $poll, string $norm): array {
        return (new \ReflectionMethod(VoteService::class, 'textVerdict'))->invoke($this->service, $poll, $norm);
    }
}
