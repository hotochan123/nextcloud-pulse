<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Controller\RoomApiController;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\DemoService;
use OCA\Pulse\Service\Limits;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\PaceStateService;
use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\RoomService;
use OCA\Pulse\Service\StateService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\RateLimiting\ILimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The moderator's leaderboard route hands out the player IDs that the
 * `removePlayer` action of /pace takes: the moderated (live) quiz view lists
 * the players from it, each with "Remove from the quiz" (security review L1).
 * The public views never carry an ID (LivePlayerAdminTest).
 */
#[CoversClass(RoomApiController::class)]
class ModeratorPlayerIdsTest extends TestCase {

    private VoteService&MockObject $votes;

    protected function setUp(): void {
        $this->votes = $this->createMock(VoteService::class);
    }

    public function testLeaderboardAsksForThePlayerIds(): void {
        $rows = [['rank' => 1, 'nickname' => 'Anna', 'score' => 900, 'correct' => 1, 'me' => false, 'playerId' => 31]];
        $this->votes->expects($this->once())->method('leaderboardFor')
            ->with($this->isInstanceOf(Room::class), null, [], true)
            ->willReturn($rows);

        $response = $this->controller()->leaderboard('ABCDEF');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($rows, $response->getData());
    }

    private function controller(): RoomApiController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => $default);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $room = new Room();
        $room->setId(5);
        $room->setCode('ABCDEF');
        $room->setOwnerUid('alice');
        $room->setMode('quiz');
        $room->setPace('live');
        $rooms = $this->createMock(RoomService::class);
        $rooms->method('getOwnedRoom')->willReturn($room);

        return new RoomApiController(
            $request,
            $session,
            $rooms,
            $this->createMock(DeckService::class),
            $this->createMock(StateService::class),
            $this->votes,
            $this->createMock(DemoService::class),
            $this->createMock(PollImageService::class),
            $l10n,
            $this->createMock(PaceService::class),
            $this->createMock(PaceStateService::class),
            $this->createMock(RoomMapper::class),
            $this->createMock(ITimeFactory::class),
            $this->createMock(ILimiter::class),
            new Limits($this->createMock(IAppConfig::class)),
        );
    }
}
