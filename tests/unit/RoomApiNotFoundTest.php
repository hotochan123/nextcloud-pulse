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
use OCA\Pulse\Service\NotOwnerException;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\PaceStateService;
use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\RoomService;
use OCA\Pulse\Service\StateService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Moderator API, owner side of the security review (task D).
 *
 * L5: someone else's room answers exactly like a code that does not exist.
 * Before, it was 403 "No access to this room." against 404 "Room not
 * found." — an oracle any logged-in account could query about 260 times a
 * second without the brute-force protection of the public routes.
 *
 * M4: the actions that create something (room, copy, question, image, demo
 * votes) are rate-limited per account, with a message the toast can show;
 * the reads are deliberately not — the presenter polls results and progress
 * every 1.2 to 2.5 seconds, from several tabs.
 */
#[CoversClass(RoomApiController::class)]
class RoomApiNotFoundTest extends TestCase {

    /** 'foreign' | 'unknown' | 'own' */
    private string $case = 'own';
    private ILimiter&MockObject $limiter;
    private RoomService&MockObject $rooms;
    private bool $loggedIn = true;
    /** What duplicateRoom throws, if anything. */
    private ?\Throwable $duplicateFails = null;

    protected function setUp(): void {
        $this->limiter = $this->createMock(ILimiter::class);
        $this->rooms = $this->createMock(RoomService::class);
        $this->rooms->method('getOwnedRoom')->willReturnCallback(function (): Room {
            return match ($this->case) {
                'foreign' => throw new NotOwnerException(),
                'unknown' => throw new DoesNotExistException('nope'),
                default => $this->room(),
            };
        });
        $this->rooms->method('duplicateRoom')->willReturnCallback(function (): Room {
            if ($this->duplicateFails !== null) {
                throw $this->duplicateFails;
            }
            return $this->room();
        });
    }

    /** Every action that names a room (all public ones except index and create). */
    public static function roomActions(): array {
        $out = [];
        foreach ((new \ReflectionClass(RoomApiController::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() !== RoomApiController::class
                || in_array($m->getName(), ['__construct', 'index', 'create'], true)) {
                continue;
            }
            $args = [];
            foreach ($m->getParameters() as $p) {
                $args[] = $p->getName() === 'code' ? 'ABCDEF' : 7;
            }
            $out[$m->getName()] = [$m->getName(), $args];
        }
        return $out;
    }

    #[DataProvider('roomActions')]
    public function testForeignRoomLooksExactlyLikeAnUnknownCode(string $method, array $args): void {
        $this->case = 'foreign';
        $foreign = $this->controller()->$method(...$args);
        $this->case = 'unknown';
        $unknown = $this->controller()->$method(...$args);

        $this->assertSame(Http::STATUS_NOT_FOUND, $foreign->getStatus(), $method);
        $this->assertSame($unknown->getStatus(), $foreign->getStatus(), $method);
        $this->assertInstanceOf(JSONResponse::class, $foreign);
        $this->assertSame($unknown->getData(), $foreign->getData(), $method);
        $this->assertSame(['message' => 'Room not found.'], $foreign->getData(), $method);
    }

    public function testNoActionAnswers403AnyMore(): void {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/RoomApiController.php');
        $this->assertStringNotContainsString('STATUS_FORBIDDEN', $source);
        $this->assertStringNotContainsString('No access to this room.', $source);
    }

    // ── Rate limits ────────────────────────────────────────────────────────

    public static function limitedActions(): array {
        return [
            'create' => ['create', [], 'pulse-create', 30],
            'duplicate' => ['duplicate', ['ABCDEF'], 'pulse-duplicate', 10],
            'addPoll' => ['addPoll', ['ABCDEF'], 'pulse-add_poll', 120],
            'uploadImage' => ['uploadImage', ['ABCDEF', 7], 'pulse-upload_image', 30],
            'demoSeed' => ['demoSeed', ['ABCDEF'], 'pulse-demo', 30],
        ];
    }

    #[DataProvider('limitedActions')]
    public function testCreatingActionsCountPerAccount(string $method, array $args, string $id, int $limit): void {
        $this->limiter->expects($this->once())->method('registerUserRequest')
            ->with($id, $limit, 60, $this->isInstanceOf(IUser::class));

        $this->controller()->$method(...$args);
    }

    #[DataProvider('limitedActions')]
    public function testOverTheLimitIs429WithAMessageAndDoesNothing(string $method, array $args): void {
        $this->limiter->method('registerUserRequest')->willThrowException(
            new class('') extends \Exception implements IRateLimitExceededException {
            },
        );
        // Counted before the room is even loaded, like #[UserRateLimit].
        $this->rooms->expects($this->never())->method('getOwnedRoom');
        $this->rooms->expects($this->never())->method('createRoom');

        $response = $this->controller()->$method(...$args);

        $this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
        $this->assertSame(['message' => 'Too many attempts. Please wait a moment.'], $response->getData());
    }

    #[DataProvider('roomActions')]
    public function testReadsAndOtherActionsAreNotRateLimited(string $method, array $args): void {
        if (array_key_exists($method, self::limitedActions())) {
            $this->assertTrue(true);
            return;
        }
        $this->limiter->expects($this->never())->method('registerUserRequest');

        $response = $this->controller()->$method(...$args);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testWithoutASessionNothingIsCounted(): void {
        $this->loggedIn = false;
        $this->limiter->expects($this->never())->method('registerUserRequest');

        $this->controller()->create();
    }

    // ── Caps surface as 400 with the service's message ────────────────────

    public function testRoomCapIs400(): void {
        $this->rooms->method('createRoom')->willThrowException(new \InvalidArgumentException('You can have at most 200 rooms.'));

        $response = $this->controller()->create();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['message' => 'You can have at most 200 rooms.'], $response->getData());
    }

    public function testCopyCapIs400(): void {
        $this->duplicateFails = new \InvalidArgumentException('budget');

        $response = $this->controller()->duplicate('ABCDEF');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['message' => 'budget'], $response->getData());
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function controller(): RoomApiController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => $default);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturnCallback(function (): ?IUser {
            if (!$this->loggedIn) {
                return null;
            }
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn('alice');
            return $user;
        });

        $pace = $this->createMock(PaceService::class);
        $pace->method('locked')->willReturnCallback(fn (Room $r, callable $fn): mixed => $fn($r));

        return new RoomApiController(
            $request,
            $session,
            $this->rooms,
            $this->createMock(DeckService::class),
            $this->createMock(StateService::class),
            $this->createMock(VoteService::class),
            $this->createMock(DemoService::class),
            $this->createMock(PollImageService::class),
            $l10n,
            $pace,
            $this->createMock(PaceStateService::class),
            $this->createMock(RoomMapper::class),
            $this->createMock(ITimeFactory::class),
            $this->limiter,
            new Limits($this->createMock(IAppConfig::class)),
        );
    }

    private function room(): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('ABCDEF');
        $room->setOwnerUid('alice');
        $room->setMode('quiz');
        $room->setPace('live');
        return $room;
    }
}
