<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Player;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\QuizService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Correction window for the quiz answer (§8.0 of the redesign handoff;
 * design notes, not in the public repository).
 *
 * The rule lives on the server, not in the browser: the voting endpoint is
 * public, so a client could "correct" as often as it likes. That is why it is
 * tested here — exactly one correction, only within the window, and with a
 * new timestamp, so the time gained is forfeited.
 */
#[CoversClass(VoteService::class)]
class QuizFixWindowTest extends TestCase {

    private VoteMapper $votes;
    private VoteService $service;
    private int $now = 1000;

    protected function setUp(): void {
        $this->votes = $this->createMock(VoteMapper::class);

        $players = $this->createMock(PlayerMapper::class);
        $players->method('findByRoomAndToken')->willReturn(new Player());

        $polls = $this->createMock(PollMapper::class);
        $polls->method('find')->willReturn($this->poll());

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        foreach ([
            'voteMapper' => $this->votes,
            'playerMapper' => $players,
            'pollMapper' => $polls,
            'quizService' => new QuizService(),
            'timeFactory' => $time,
            'l10n' => $l10n,
        ] as $name => $value) {
            (new ReflectionProperty(VoteService::class, $name))->setValue($this->service, $value);
        }
    }

    public function testCorrectionInTheWindowReplacesTheAnswer(): void {
        $old = $this->existingVote(['value' => 'AA', 'points' => 900, 'correct' => false, 'elapsed' => 1], at: 998);
        $this->votes->method('insert')->willThrowException($this->uniqueViolation());
        $this->votes->method('findByPollAndToken')->willReturn($old);
        $saved = null;
        $this->votes->expects($this->once())->method('update')
            ->willReturnCallback(function (Vote $vote) use (&$saved) {
                $saved = $vote;
                return $vote;
            });

        $this->service->recordVote($this->room(), 'tok', 'BB');

        $payload = json_decode($saved->getPayload(), true);
        $this->assertSame('BB', $payload['value'], 'die korrigierte Antwort zählt');
        $this->assertTrue($payload['fixed'], 'die Korrektur ist als solche vermerkt');
        $this->assertSame($this->now, $saved->getCreatedAt(), 'der Zeitstempel wird neu gesetzt');
        // A new timestamp means: the two-second head start is gone.
        $this->assertSame(10, $payload['elapsed']);
    }

    public function testSecondCorrectionIsRejected(): void {
        $old = $this->existingVote(['value' => 'AA', 'points' => 0, 'correct' => false, 'elapsed' => 1, 'fixed' => true], at: 999);
        $this->votes->method('insert')->willThrowException($this->uniqueViolation());
        $this->votes->method('findByPollAndToken')->willReturn($old);
        $this->votes->expects($this->never())->method('update');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordVote($this->room(), 'tok', 'BB');
    }

    public function testCorrectionAfterTheWindowIsRejected(): void {
        $old = $this->existingVote(['value' => 'AA', 'points' => 0, 'correct' => false, 'elapsed' => 1], at: 995);
        $this->votes->method('insert')->willThrowException($this->uniqueViolation());
        $this->votes->method('findByPollAndToken')->willReturn($old);
        $this->votes->expects($this->never())->method('update');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordVote($this->room(), 'tok', 'BB');
    }

    public function testKeyboardUseHasTheLongerWindow(): void {
        // The same five seconds as in the test above — allowed via keyboard.
        $old = $this->existingVote(['value' => 'AA', 'points' => 0, 'correct' => false, 'elapsed' => 1], at: 995);
        $this->votes->method('insert')->willThrowException($this->uniqueViolation());
        $this->votes->method('findByPollAndToken')->willReturn($old);
        $this->votes->expects($this->once())->method('update')->willReturnArgument(0);

        $this->service->recordVote($this->room(), 'tok', 'BB', keyboard: true);
    }

    private function room(): Room {
        $room = new Room();
        $room->setId(1);
        $room->setMode('quiz');
        $room->setActivePollId(7);
        return $room;
    }

    private function poll(): Poll {
        $poll = new Poll();
        $poll->setId(7);
        $poll->setType('choice');
        $poll->setStatus('active');
        $poll->setStartedAt($this->now - 10);
        $poll->setTimeLimit(60);
        $poll->setOptions(json_encode([['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B']]));
        $poll->setCorrectOption('BB');
        return $poll;
    }

    private function existingVote(array $payload, int $at): Vote {
        $vote = new Vote();
        $vote->setPollId(7);
        $vote->setVoterToken('tok');
        $vote->setPayload(json_encode($payload));
        $vote->setCreatedAt($at);
        return $vote;
    }

    /** The UNIQUE violation with which the database rejects the second answer. */
    private function uniqueViolation(): Exception {
        $e = $this->createMock(Exception::class);
        $e->method('getReason')->willReturn(Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
        return $e;
    }
}
