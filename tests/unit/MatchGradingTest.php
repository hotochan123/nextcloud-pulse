<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Service\QuizService;
use OCA\Pulse\Service\VoteService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Grading of matching in a quiz: all-or-nothing, as with multiple choice and
 * ranking. Partial points would water down "correct" in the leaderboard.
 *
 * quizPayload() computes without a DB; the instance is therefore created without a constructor
 * (newInstanceWithoutConstructor), only the points calculation is injected afterwards.
 */
#[CoversClass(VoteService::class)]
class MatchGradingTest extends TestCase {

    private VoteService $service;

    protected function setUp(): void {
        $this->service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        $property = new ReflectionProperty(VoteService::class, 'quizService');
        $property->setValue($this->service, new QuizService());
    }

    public function testVollstaendigRichtigeZuordnungGibtPunkte(): void {
        $payload = $this->service->quizPayload($this->poll(), ['I1' => 'T1', 'I2' => 'T2'], 0);

        $this->assertTrue($payload['correct']);
        $this->assertSame(QuizService::BASE_POINTS, $payload['points']);
    }

    public function testReihenfolgeDerZeilenIstEgal(): void {
        // The client sends the rows in its own (shuffled) display order.
        $payload = $this->service->quizPayload($this->poll(), ['I2' => 'T2', 'I1' => 'T1'], 0);

        $this->assertTrue($payload['correct']);
    }

    public function testEineFalscheZeileKostetDenGanzenPunkt(): void {
        $payload = $this->service->quizPayload($this->poll(), ['I1' => 'T2', 'I2' => 'T2'], 0);

        $this->assertFalse($payload['correct']);
        $this->assertSame(0, $payload['points']);
    }

    public function testUnvollstaendigeZuordnungIstNichtRichtig(): void {
        $payload = $this->service->quizPayload($this->poll(), ['I1' => 'T1'], 0);

        $this->assertFalse($payload['correct']);
    }

    public function testOhneLoesungGibtEsKeinenPunkt(): void {
        // A poll-mode matching has no answerKey — an empty solution must not
        // accidentally match an empty answer.
        $poll = $this->poll();
        $poll->setAnswerKey(null);

        $this->assertFalse($this->service->quizPayload($poll, [], 0)['correct']);
    }

    public function testSpaeteAntwortBekommtWenigerPunkte(): void {
        $poll = $this->poll();
        $poll->setTimeLimit(20);
        $fast = $this->service->quizPayload($poll, ['I1' => 'T1', 'I2' => 'T2'], 0);
        $slow = $this->service->quizPayload($poll, ['I1' => 'T1', 'I2' => 'T2'], 20);

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
