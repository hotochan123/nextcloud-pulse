<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Service\AnswerRules;
use OCA\Pulse\Service\QuizService;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Grading of matching in a quiz: all-or-nothing, as with multiple choice and
 * ranking. Partial points would water down "correct" in the leaderboard.
 *
 * quizPayload() computes without a DB, so AnswerRules is built directly with
 * the real points calculation.
 */
#[CoversClass(AnswerRules::class)]
class MatchGradingTest extends TestCase {

    private AnswerRules $rules;

    protected function setUp(): void {
        $this->rules = new AnswerRules($this->createMock(IL10N::class), new QuizService());
    }

    public function testFullyCorrectMatchingGivesPoints(): void {
        $payload = $this->rules->quizPayload($this->poll(), ['I1' => 'T1', 'I2' => 'T2'], 0);

        $this->assertTrue($payload['correct']);
        $this->assertSame(QuizService::BASE_POINTS, $payload['points']);
    }

    public function testOrderOfTheRowsDoesNotMatter(): void {
        // The client sends the rows in its own (shuffled) display order.
        $payload = $this->rules->quizPayload($this->poll(), ['I2' => 'T2', 'I1' => 'T1'], 0);

        $this->assertTrue($payload['correct']);
    }

    public function testOneWrongRowCostsAllThePoints(): void {
        $payload = $this->rules->quizPayload($this->poll(), ['I1' => 'T2', 'I2' => 'T2'], 0);

        $this->assertFalse($payload['correct']);
        $this->assertSame(0, $payload['points']);
    }

    public function testIncompleteMatchingIsNotCorrect(): void {
        $payload = $this->rules->quizPayload($this->poll(), ['I1' => 'T1'], 0);

        $this->assertFalse($payload['correct']);
    }

    public function testWithoutSolutionThereAreNoPoints(): void {
        // A poll-mode matching has no answerKey — an empty solution must not
        // accidentally match an empty answer.
        $poll = $this->poll();
        $poll->setAnswerKey(null);

        $this->assertFalse($this->rules->quizPayload($poll, [], 0)['correct']);
    }

    public function testLateAnswerGetsFewerPoints(): void {
        $poll = $this->poll();
        $poll->setTimeLimit(20);
        $fast = $this->rules->quizPayload($poll, ['I1' => 'T1', 'I2' => 'T2'], 0);
        $slow = $this->rules->quizPayload($poll, ['I1' => 'T1', 'I2' => 'T2'], 20);

        $this->assertSame(QuizService::BASE_POINTS, $fast['points']);
        $this->assertSame((int)(QuizService::BASE_POINTS / 2), $slow['points']);
    }

    private function poll(): Poll {
        $poll = new Poll();
        $poll->setType('match');
        $poll->setQuestion('Was passt?');
        $poll->setOptions(json_encode([
            'items' => [['id' => 'I1', 'label' => 'Frankreich'], ['id' => 'I2', 'label' => 'Italien']],
            'targets' => [['id' => 'T1', 'label' => 'Paris'], ['id' => 'T2', 'label' => 'Rom']],
        ]));
        $poll->setAnswerKey(json_encode(['map' => ['I1' => 'T1', 'I2' => 'T2']]));
        $poll->setTimeLimit(0);
        return $poll;
    }
}
