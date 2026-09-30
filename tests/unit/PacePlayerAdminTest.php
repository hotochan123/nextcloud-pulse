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
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\ConflictException;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\RoomService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Countermeasures against throwaway players and name squatters: locking joins and
 * removing a person. Removing clears everything attached to their token:
 * first the player itself (the name becomes free, its row stays locked until
 * the commit: a concurrent /vote or /next waits for it in
 * assertStillJoined), then votes in the frozen deck, progress,
 * presence. Also allowed after the release: a squatter should not remain in the
 * final standings.
 */
#[CoversClass(PaceService::class)]
class PacePlayerAdminTest extends TestCase {

    private const NOW = 1_800_000_000;

    private PaceService $service;
    private RoomMapper&MockObject $rooms;
    private VoteMapper&MockObject $votes;
    private ProgressMapper&MockObject $progress;
    private PresenceMapper&MockObject $presence;
    private PlayerMapper&MockObject $players;
    private Room $locked;
    /** @var list<string> Call order when removing */
    private array $calls = [];
    /** @var list<string> Transaction sequence */
    private array $tx = [];

    protected function setUp(): void {
        $this->locked = $this->room(self::NOW - 100);

        $this->rooms = $this->createMock(RoomMapper::class);
        $this->rooms->expects($this->once())->method('lockForUpdate')->with(5)
            ->willReturnCallback(fn (): Room => $this->locked);
        $this->rooms->method('findByCode')->willReturnCallback(fn (): Room => $this->locked);

        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturn([$this->poll(21), $this->poll(22)]);

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
        $time->method('getTime')->willReturn(self::NOW);

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

    // ── Lock joins ─────────────────────────────────────────────────────────

    public function testBeitrittSperrenNurImQuiz(): void {
        // Moderated quizzes can lock joining too (LivePlayerAdminTest), a poll has no players.
        $this->locked->setMode('poll');
        $this->locked->setPace('live');
        $this->rooms->expects($this->never())->method('update');

        try {
            $this->service->setJoinsLocked($this->room(), true);
            $this->fail('ConflictException erwartet');
        } catch (ConflictException $e) {
            $this->assertSame('This room is not a quiz.', $e->getMessage());
        }
        $this->assertSame(['beginTransaction', 'rollBack'], $this->tx);
    }

    public function testBeitrittSperrenSetztDasFlag(): void {
        $this->rooms->expects($this->once())->method('update')
            ->with($this->identicalTo($this->locked))->willReturnArgument(0);

        $result = $this->service->setJoinsLocked($this->room(), true);

        $this->assertTrue($result->getJoinsLocked());
        $this->assertSame(['beginTransaction', 'commit'], $this->tx);
    }

    public function testBeitrittWiederOeffnen(): void {
        $this->locked->setJoinsLocked(true);
        $this->locked->resetUpdatedFields();
        $this->rooms->expects($this->once())->method('update')->willReturnArgument(0);

        $this->assertFalse($this->service->setJoinsLocked($this->room(), false)->getJoinsLocked());
    }

    // ── Remove person ──────────────────────────────────────────────────────

    public function testUnbekannteOderFremdeIdIstEinEingabefehler(): void {
        // A player ID from another room does not show up in findByRoom.
        $this->players->expects($this->never())->method('delete');
        $this->votes->expects($this->never())->method('deleteByPollsAndToken');

        try {
            $this->service->removePlayer($this->room(), 99);
            $this->fail('InvalidArgumentException erwartet');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Player not found.', $e->getMessage());
        }
        $this->assertSame(['beginTransaction', 'rollBack'], $this->tx);
    }

    public function testEntfernenRaeumtAllesVomTokenInFesterReihenfolge(): void {
        $this->recordRemoval([12, 15, 13]);

        $this->service->removePlayer($this->room(), 32);

        $this->assertSame([
            'player:32',
            'votes:12,15,13:tok-zz',
            'progress:5:tok-zz',
            'presence:5:tok-zz',
        ], $this->calls);
        $this->assertSame(['beginTransaction', 'commit'], $this->tx);
    }

    public function testEntfernenAuchNachDerFreigabe(): void {
        $this->locked = $this->room(self::NOW - 100, self::NOW - 1, self::NOW - 1);
        $this->recordRemoval([12, 15, 13]);

        $this->service->removePlayer($this->room(), 31);

        $this->assertSame('player:31', $this->calls[0]);
        $this->assertCount(4, $this->calls);
        $this->assertSame(['beginTransaction', 'commit'], $this->tx);
    }

    public function testEntfernenImEntwurfNimmtDasAktuelleDeck(): void {
        $this->locked = $this->room();
        $this->recordRemoval(null);

        $this->service->removePlayer($this->room(), 31);

        $this->assertSame('votes:21,22:tok-anna', $this->calls[1]);
    }

    public function testEntfernenNurImQuiz(): void {
        $this->locked->setMode('poll');
        $this->locked->setPace('live');
        $this->players->expects($this->never())->method('delete');

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('This room is not a quiz.');
        $this->service->removePlayer($this->room(), 31);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** Record all delete calls in $this->calls. */
    private function recordRemoval(?array $order): void {
        if ($order !== null) {
            $this->locked->setDeckOrder('[' . implode(',', $order) . ']');
        }
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

    private function room(int $openedAt = 0, int $closedAt = 0, int $releasedAt = 0): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('ABCDEF');
        $room->setMode('quiz');
        $room->setPace('self');
        $room->setOpenedAt($openedAt);
        $room->setClosedAt($closedAt);
        $room->setReleasedAt($releasedAt);
        if ($openedAt > 0) {
            $room->setDeckOrder('[12,15,13]');
        }
        $room->resetUpdatedFields();
        return $room;
    }

    private function poll(int $id): Poll {
        $poll = new Poll();
        $poll->setId($id);
        $poll->setRoomId(5);
        return $poll;
    }

    private function player(int $id, string $token, string $nickname): Player {
        $player = new Player();
        $player->setId($id);
        $player->setRoomId(5);
        $player->setVoterToken($token);
        $player->setNickname($nickname);
        return $player;
    }
}
