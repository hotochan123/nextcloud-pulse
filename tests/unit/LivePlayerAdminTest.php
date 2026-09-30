<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Player;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\PresenceMapper;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\QuizService;
use OCA\Pulse\Service\RoomService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Player administration in a moderated (live) quiz: the existing /pace
 * actions `lockJoins`, `unlockJoins` and `removePlayer` work there too.
 *
 * Without them the host's only remedy against a name squatter or a crowd of
 * throwaway players on the podium was resetting the whole room. Removing
 * takes the player and their votes on EVERY question of the room (a
 * moderated room has no frozen order) off the leaderboard, at any time —
 * also after the final standings. The moderator leaderboard can carry the
 * player IDs for that (`playerId`, opt-in, never public).
 */
#[CoversClass(PaceService::class)]
#[CoversClass(VoteService::class)]
class LivePlayerAdminTest extends TestCase {

    private PaceService $service;
    private RoomMapper&MockObject $rooms;
    private VoteMapper&MockObject $votes;
    private ProgressMapper&MockObject $progress;
    private PresenceMapper&MockObject $presence;
    private PlayerMapper&MockObject $players;
    private Room $locked;
    /** @var list<string> */
    private array $calls = [];
    /** @var list<string> */
    private array $tx = [];

    protected function setUp(): void {
        $this->locked = $this->room();

        $this->rooms = $this->createMock(RoomMapper::class);
        $this->rooms->method('lockForUpdate')->willReturnCallback(fn (): Room => $this->locked);
        $this->rooms->method('findByCode')->willReturnCallback(fn (): Room => $this->locked);

        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturn([$this->poll(21), $this->poll(22), $this->poll(23)]);

        $this->players = $this->createMock(PlayerMapper::class);
        $this->players->method('findByRoom')->willReturn([
            $this->player(31, 'tok-anna', 'Anna'),
            $this->player(32, 'tok-zz', 'Z'),
        ]);

        $this->votes = $this->createMock(VoteMapper::class);
        $this->progress = $this->createMock(ProgressMapper::class);
        $this->presence = $this->createMock(PresenceMapper::class);

        $db = $this->createMock(IDBConnection::class);
        foreach (['beginTransaction', 'commit', 'rollBack'] as $step) {
            $db->method($step)->willReturnCallback(function () use ($step): void {
                $this->tx[] = $step;
            });
        }

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1_800_000_000);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->service = (new \ReflectionClass(PaceService::class))->newInstanceWithoutConstructor();
        foreach ([
            'roomMapper' => $this->rooms,
            'pollMapper' => $polls,
            'playerMapper' => $this->players,
            'progressMapper' => $this->progress,
            'voteMapper' => $this->votes,
            'presenceMapper' => $this->presence,
            'roomService' => $this->createMock(RoomService::class),
            'db' => $db,
            'timeFactory' => $time,
            'l10n' => $l10n,
        ] as $name => $value) {
            (new ReflectionProperty(PaceService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── Lock joining ───────────────────────────────────────────────────────

    public function testLockJoiningInLiveQuiz(): void {
        $this->rooms->expects($this->once())->method('update')
            ->with($this->identicalTo($this->locked))->willReturnArgument(0);

        $result = $this->service->setJoinsLocked($this->room(), true);

        $this->assertTrue($result->getJoinsLocked());
        $this->assertSame(['beginTransaction', 'commit'], $this->tx);
    }

    public function testReopenJoiningInLiveQuiz(): void {
        $this->locked->setJoinsLocked(true);
        $this->locked->resetUpdatedFields();
        $this->rooms->expects($this->once())->method('update')->willReturnArgument(0);

        $this->assertFalse($this->service->setJoinsLocked($this->room(), false)->getJoinsLocked());
    }

    // ── Remove ─────────────────────────────────────────────────────────────

    public function testRemoveTakesVotesOnAllQuestionsOfTheRoom(): void {
        $this->recordRemoval();

        $this->service->removePlayer($this->room(), 32);

        $this->assertSame([
            'player:32',
            'votes:21,22,23:tok-zz',
            'progress:7:tok-zz',
            'presence:7:tok-zz',
        ], $this->calls);
        $this->assertSame(['beginTransaction', 'commit'], $this->tx);
    }

    public function testRemoveAlsoAfterTheQuizEnd(): void {
        // A renamed squatter on the final podium: nothing about the state stops it.
        $this->locked->setActivePollId(23);
        $this->recordRemoval();

        $this->service->removePlayer($this->room(), 31);

        $this->assertSame('player:31', $this->calls[0]);
        $this->assertSame(['beginTransaction', 'commit'], $this->tx);
    }

    public function testForeignIdIsAnInputError(): void {
        $this->players->expects($this->never())->method('delete');

        $this->expectExceptionMessage('Player not found.');
        $this->service->removePlayer($this->room(), 99);
    }

    // ── Moderator leaderboard with IDs ─────────────────────────────────────

    public function testModeratorLeaderboardCarriesThePlayerIdOnRequest(): void {
        $votes = $this->leaderboardService();

        $rows = $votes->leaderboardFor($this->room(), null, [], true);

        $this->assertSame(['Z', 'Anna'], array_column($rows, 'nickname'));
        $this->assertSame([32, 31], array_column($rows, 'playerId'));
    }

    public function testNoPlayerIdWithoutRequest(): void {
        // Public views (phones, projector) never get the IDs.
        $votes = $this->leaderboardService();

        foreach ([$votes->leaderboardFor($this->room(), null), $votes->leaderboardFor($this->room(), 'tok-anna')] as $rows) {
            $this->assertCount(2, $rows);
            foreach ($rows as $row) {
                $this->assertArrayNotHasKey('playerId', $row);
                $this->assertArrayNotHasKey('token', $row);
            }
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** VoteService for the moderated leaderboard: Z has 500 points, Anna none. */
    private function leaderboardService(): VoteService {
        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturn([$this->poll(21)]);
        $votes = $this->createMock(VoteMapper::class);
        $vote = new Vote();
        $vote->setPollId(21);
        $vote->setVoterToken('tok-zz');
        $vote->setPayload(json_encode(['value' => 'A', 'points' => 500, 'correct' => true, 'elapsed' => 3]));
        $votes->method('findByPoll')->willReturn([$vote]);

        $service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        foreach ([
            'pollMapper' => $polls,
            'voteMapper' => $votes,
            'playerMapper' => $this->players,
            'quizService' => (new \ReflectionClass(QuizService::class))->newInstanceWithoutConstructor(),
        ] as $name => $value) {
            (new ReflectionProperty(VoteService::class, $name))->setValue($service, $value);
        }
        return $service;
    }

    private function recordRemoval(): void {
        $this->votes->expects($this->once())->method('deleteByPollsAndToken')
            ->willReturnCallback(function (array $ids, string $token): void {
                $this->calls[] = 'votes:' . implode(',', $ids) . ':' . $token;
            });
        $this->progress->expects($this->once())->method('deleteByRoomAndToken')
            ->willReturnCallback(function (int $roomId, string $token): void {
                $this->calls[] = 'progress:' . $roomId . ':' . $token;
            });
        $this->presence->expects($this->once())->method('deleteByRoomAndToken')
            ->willReturnCallback(function (int $roomId, string $token): void {
                $this->calls[] = 'presence:' . $roomId . ':' . $token;
            });
        $this->players->expects($this->once())->method('delete')
            ->willReturnCallback(function (Player $p): Player {
                $this->calls[] = 'player:' . $p->getId();
                return $p;
            });
    }

    private function room(): Room {
        $room = new Room();
        $room->setId(7);
        $room->setCode('LIVE01');
        $room->setMode('quiz');
        $room->setPace('live');
        $room->resetUpdatedFields();
        return $room;
    }

    private function poll(int $id): Poll {
        $poll = new Poll();
        $poll->setId($id);
        $poll->setRoomId(7);
        return $poll;
    }

    private function player(int $id, string $token, string $nickname): Player {
        $player = new Player();
        $player->setId($id);
        $player->setRoomId(7);
        $player->setVoterToken($token);
        $player->setNickname($nickname);
        return $player;
    }
}
