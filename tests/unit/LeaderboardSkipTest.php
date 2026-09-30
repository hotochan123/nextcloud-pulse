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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Leaderboard without one question (VoteService::leaderboardFor, $skipPollIds).
 *
 * The public overall results leave the running, still hidden question out of
 * the scoring; otherwise the score would reveal right after tapping whether
 * the answer was correct. The points live in the votes (payload.points);
 * the leaderboard computes for real (QuizService), only the mappers are doubles.
 */
#[CoversClass(VoteService::class)]
class LeaderboardSkipTest extends TestCase {

    private VoteMapper&MockObject $votes;
    private VoteService $service;
    /** @var list<int> Questions whose votes were read */
    private array $read = [];

    protected function setUp(): void {
        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturn([$this->poll(1), $this->poll(2)]);

        // Question 1: Anna correct (800), Ben wrong. Question 2 (running, hidden):
        // Ben correct (900), Anna wrong.
        $byPoll = [
            1 => [$this->vote('tok-anna', 800, true, 3), $this->vote('tok-ben', 0, false, 2)],
            2 => [$this->vote('tok-anna', 0, false, 4), $this->vote('tok-ben', 900, true, 1)],
        ];
        $this->votes = $this->createMock(VoteMapper::class);
        $this->votes->method('findByPoll')->willReturnCallback(function (int $id) use ($byPoll): array {
            $this->read[] = $id;
            return $byPoll[$id] ?? [];
        });

        $players = $this->createMock(PlayerMapper::class);
        $players->method('findByRoom')->willReturn([
            $this->player('tok-anna', 'Anna'),
            $this->player('tok-ben', 'Ben'),
        ]);

        $this->service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        foreach ([
            'pollMapper' => $polls,
            'voteMapper' => $this->votes,
            'playerMapper' => $players,
            'quizService' => new QuizService(),
        ] as $name => $value) {
            (new ReflectionProperty(VoteService::class, $name))->setValue($this->service, $value);
        }
    }

    public function testWithoutSkippingAllQuestionsCount(): void {
        $rows = $this->service->leaderboardFor($this->room(), 'tok-anna');

        $this->assertSame([
            ['rank' => 1, 'nickname' => 'Ben', 'score' => 900, 'correct' => 1, 'me' => false],
            ['rank' => 2, 'nickname' => 'Anna', 'score' => 800, 'correct' => 1, 'me' => true],
        ], $rows);
        $this->assertSame([1, 2], $this->read);
    }

    public function testSkippedQuestionDoesNotCount(): void {
        $rows = $this->service->leaderboardFor($this->room(), 'tok-ben', [2]);

        $this->assertSame([
            ['rank' => 1, 'nickname' => 'Anna', 'score' => 800, 'correct' => 1, 'me' => false],
            ['rank' => 2, 'nickname' => 'Ben', 'score' => 0, 'correct' => 0, 'me' => true],
        ], $rows, 'Bens richtige Antwort auf die verdeckte Frage verrät sich nicht');
        $this->assertSame([1], $this->read, 'Stimmen der ausgelassenen Frage werden gar nicht gelesen');
    }

    public function testNullSkipsNothing(): void {
        $this->assertSame(
            $this->service->leaderboardFor($this->room(), null),
            $this->service->leaderboardFor($this->room(), null, []),
        );
    }

    public function testUnknownQuestionSkipsNothing(): void {
        $rows = $this->service->leaderboardFor($this->room(), null, [99]);

        $this->assertSame([900, 800], array_column($rows, 'score'));
    }

    private function room(): Room {
        $room = new Room();
        $room->setId(1);
        $room->setMode('quiz');
        return $room;
    }

    private function poll(int $id): Poll {
        $poll = new Poll();
        $poll->setId($id);
        $poll->setRoomId(1);
        $poll->setType('choice');
        return $poll;
    }

    private function vote(string $token, int $points, bool $correct, int $elapsed): Vote {
        $vote = new Vote();
        $vote->setVoterToken($token);
        $vote->setPayload(json_encode(['value' => 'AA', 'points' => $points, 'correct' => $correct, 'elapsed' => $elapsed]));
        return $vote;
    }

    private function player(string $token, string $nickname): Player {
        $player = new Player();
        $player->setRoomId(1);
        $player->setVoterToken($token);
        $player->setNickname($nickname);
        return $player;
    }
}
