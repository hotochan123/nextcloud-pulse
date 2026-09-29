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
 * Wertung der Zuordnung im Quiz: alles-oder-nichts, wie bei Mehrfachauswahl und
 * Reihenfolge. Teilpunkte würden „richtig" in der Rangliste verwässern.
 *
 * quizPayload() rechnet DB-frei; die Instanz entsteht daher ohne Konstruktor
 * (newInstanceWithoutConstructor), nur die Punkte-Rechnung wird nachgereicht.
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
        // Der Client schickt die Zeilen in seiner (gemischten) Anzeigereihenfolge.
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
        // Umfrage-Zuordnung hat keinen answerKey — eine leere Lösung darf nicht
        // versehentlich auf eine leere Antwort passen.
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
