<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Controller\PublicVoteController;
use OCA\Pulse\Controller\RoomApiController;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\PresenceMapper;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\CodeGenerator;
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
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\RateLimiting\ILimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "This room is not self-paced." in a moderated quiz: one situation, two
 * statuses, on purpose. The moderator API answers 409 (the window actions
 * of /pace here; /progress in RoomApiErrorMapTest), the public /next 400.
 * That is the rule in ConflictException: 409 only in the moderator API,
 * public routes answer 400. The moderator's clients reload the room on a
 * 409 (progress-poll, PaceRun); the phone toasts every answer from /next
 * but a 404 alike.
 *
 * PaceService is real (only its mappers are doubles), so this pins the
 * exception the service throws together with the status the controller
 * makes of it: a ConflictException from next() would be a 500 on the
 * public route, which has no catch for it.
 */
#[CoversClass(RoomApiController::class)]
#[CoversClass(PublicVoteController::class)]
#[CoversClass(PaceService::class)]
class NotSelfPacedTest extends TestCase {

    private const NOW = 1_800_000_000;
    /** A token in the form CodeGenerator::voterToken issues. */
    private const ANNA = 'AnnaAnnaAnnaAnnaAnnaAnnaAnnaAnna';

    /** @var array<string, mixed> request parameters; missing ones get the default */
    private array $params = [];
    /** @var list<string> transaction steps of the room lock */
    private array $tx = [];

    private IRequest $request;
    private IL10N $l10n;
    private ITimeFactory $time;
    private PaceService $pace;

    protected function setUp(): void {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            fn (string $key, mixed $default = null): mixed => array_key_exists($key, $this->params) ? $this->params[$key] : $default,
        );
        $request->method('getCookie')->willReturn(self::ANNA);
        $request->method('getRemoteAddress')->willReturn('192.0.2.1');
        // Like the public bundle (@nextcloud/axios): participant POSTs need it (PublicCrossSiteTest).
        $request->method('getHeader')->willReturnCallback(fn (string $name): string => $name === 'X-Requested-With' ? 'XMLHttpRequest' : '');
        $this->request = $request;

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $this->l10n = $l10n;

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        $this->time = $time;

        // The room lock hands back the moderated room; nothing may be written.
        $rooms = $this->createMock(RoomMapper::class);
        $rooms->method('lockForUpdate')->willReturnCallback(fn (): Room => $this->room());
        $rooms->expects($this->never())->method('update');
        $db = $this->createMock(IDBConnection::class);
        foreach (['beginTransaction', 'commit', 'rollBack'] as $step) {
            $db->method($step)->willReturnCallback(function () use ($step): void {
                $this->tx[] = $step;
            });
        }
        $this->pace = new PaceService(
            $rooms,
            $this->createMock(PollMapper::class),
            $this->createMock(PlayerMapper::class),
            $this->createMock(ProgressMapper::class),
            $this->createMock(VoteMapper::class),
            $this->createMock(PresenceMapper::class),
            $this->createMock(RoomService::class),
            $db,
            $this->time,
            $this->l10n,
        );
    }

    // ── Moderator: 409 ─────────────────────────────────────────────────────

    public static function windowActions(): array {
        return [
            'open' => [['action' => 'open']],
            'close' => [['action' => 'close']],
            'extend' => [['action' => 'extend', 'closesAt' => self::NOW + 3600]],
            'release' => [['action' => 'release']],
        ];
    }

    #[DataProvider('windowActions')]
    public function testModeratorWindowActionIs409(array $params): void {
        $this->params = $params;

        $response = $this->moderator()->pace('ABCDEF');

        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $this->assertSame(['message' => 'This room is not self-paced.'], $response->getData());
        $this->assertSame(['beginTransaction', 'rollBack'], $this->tx, 'checked on the locked row, nothing committed');
    }

    // ── Public: 400 ────────────────────────────────────────────────────────

    public function testPublicNextIs400(): void {
        $this->params = ['after' => 0];

        $response = $this->public()->next('ABCDEF');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['message' => 'This room is not self-paced.'], $response->getData());
        $this->assertFalse($response->isThrottled());
        $this->assertSame([], $this->tx, '/next takes no room lock');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function moderator(): RoomApiController {
        $rooms = $this->createMock(RoomService::class);
        $rooms->method('getOwnedRoom')->willReturnCallback(fn (): Room => $this->room());
        return new RoomApiController(
            $this->request,
            $this->createMock(IUserSession::class),
            $rooms,
            $this->createMock(DeckService::class),
            $this->createMock(StateService::class),
            $this->createMock(VoteService::class),
            $this->createMock(DemoService::class),
            $this->createMock(PollImageService::class),
            $this->l10n,
            $this->pace,
            $this->createMock(PaceStateService::class),
            $this->createMock(RoomMapper::class),
            $this->time,
            $this->createMock(ILimiter::class),
            new Limits($this->createMock(IAppConfig::class)),
        );
    }

    private function public(): PublicVoteController {
        $mapper = $this->createMock(RoomMapper::class);
        $mapper->method('findByCode')->willReturnCallback(fn (): Room => $this->room());
        // The code was right: no brute-force attempt, whatever the answer.
        $throttler = $this->createMock(IThrottler::class);
        $throttler->expects($this->never())->method('registerAttempt');
        return new PublicVoteController(
            $this->request,
            $mapper,
            $this->createMock(RoomService::class),
            $this->createMock(StateService::class),
            $this->createMock(VoteService::class),
            $this->createMock(DeckService::class),
            $this->createMock(PollImageService::class),
            $this->createMock(CodeGenerator::class),
            $this->time,
            $this->l10n,
            $this->pace,
            $this->createMock(ILimiter::class),
            $throttler,
            $this->createMock(IURLGenerator::class),
            $this->createMock(IAppConfig::class),
            new Limits($this->createMock(IAppConfig::class)),
        );
    }

    /** Moderated quiz with a question running. */
    private function room(): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('ABCDEF');
        $room->setOwnerUid('');
        $room->setMode('quiz');
        $room->setPace('live');
        $room->setActivePollId(11);
        return $room;
    }
}
