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
use OCA\Pulse\Service\NotOwnerException;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\PaceStateService;
use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\RoomService;
use OCA\Pulse\Service\StateService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Retention, controller side: every moderator request that names a room
 * records owner activity (RoomMapper::touch) exactly once — moderated rooms
 * included, reading and writing alike. Before, only self-paced rooms were
 * touched, so a moderated deck created more than 30 days ago that no
 * audience had joined was deleted even if its owner had edited it yesterday.
 * CleanupRetentionTest covers the other half (what the job does with
 * `touched_at`).
 */
#[CoversClass(RoomApiController::class)]
class OwnerActivityTest extends TestCase {

    private const NOW = 1_800_000_000;
    /** Actions without a room: they must not touch anything. */
    private const WITHOUT_ROOM = ['index', 'create'];

    private Room $room;
    private bool $foreign = false;
    /** @var list<array{0: int, 1: int}> [roomId, now] per RoomMapper::touch call */
    private array $touches = [];

    protected function setUp(): void {
        $this->room = $this->liveRoom();
    }

    /**
     * Every action that names a room: [method, arguments]. index and create
     * name none; testEveryActionIsClassified keeps both lists complete.
     */
    public static function roomActions(): array {
        $c = ['ABCDEF'];
        $p = ['ABCDEF', 7];
        return [
            'summary' => ['summary', $c],
            'exportCsv' => ['exportCsv', $c],
            'duplicate' => ['duplicate', $c],
            'rename' => ['rename', $c],
            'leaderboard' => ['leaderboard', $c],
            'show' => ['show', $c],
            'destroy' => ['destroy', $c],
            'resetRoom' => ['resetRoom', $c],
            'practice' => ['practice', $c],
            'reveal' => ['reveal', $c],
            'endQuiz' => ['endQuiz', $c],
            'pace' => ['pace', $c],
            'progress' => ['progress', $c],
            'addPoll' => ['addPoll', $c],
            'updatePoll' => ['updatePoll', $p],
            'reorder' => ['reorder', $c],
            'setCurrent' => ['setCurrent', $c],
            'deletePoll' => ['deletePoll', $p],
            'results' => ['results', $p],
            'resetPoll' => ['resetPoll', $p],
            'gradeAnswer' => ['gradeAnswer', $p],
            'uploadImage' => ['uploadImage', $p],
            'deleteImage' => ['deleteImage', $p],
            'showImage' => ['showImage', $p],
            'lockPoll' => ['lockPoll', $p],
            'unlockPoll' => ['unlockPoll', $p],
            'demoSeed' => ['demoSeed', $c],
            'demoClear' => ['demoClear', $c],
        ];
    }

    #[DataProvider('roomActions')]
    public function testEveryRoomActionCountsExactlyOnceInModeratedRoomsToo(string $method, array $args): void {
        $response = $this->controller()->$method(...$args);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertLessThan(500, $response->getStatus(), $method);
        $this->assertSame([[5, self::NOW]], $this->touches, $method . ' must record owner activity once');
    }

    #[DataProvider('roomActions')]
    public function testEveryRoomActionCountsExactlyOnceInSelfPacedMode(string $method, array $args): void {
        $this->room = $this->selfRoom();

        $this->controller()->$method(...$args);

        $this->assertSame([[5, self::NOW]], $this->touches, $method);
    }

    public function testOpeningAModeratedRoomCounts(): void {
        // The decisive case: GET /rooms/{code} in a moderated room used to
        // record nothing.
        $response = $this->controller()->show('ABCDEF');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([[5, self::NOW]], $this->touches);
    }

    public function testListAndCreateDoNotCount(): void {
        // Merely opening the app must not keep every room alive.
        $controller = $this->controller();
        $controller->index();
        $controller->create();

        $this->assertSame([], $this->touches);
    }

    public function testForeignRoomDoesNotCount(): void {
        $this->foreign = true;

        $response = $this->controller()->show('ABCDEF');

        // 404 like an unknown code (RoomApiNotFoundTest), and no touch.
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame([], $this->touches);
    }

    public static function freshVisits(): array {
        return [
            'ten minutes ago' => [self::NOW - 600, false],
            'exactly one hour ago' => [self::NOW - RoomMapper::TOUCH_INTERVAL, false],
            'one hour and one second ago' => [self::NOW - RoomMapper::TOUCH_INTERVAL - 1, true],
            'never' => [0, true],
        ];
    }

    #[DataProvider('freshVisits')]
    public function testAtMostOneWriteAnHour(int $touchedAt, bool $writes): void {
        // The same boundary as the WHERE in RoomMapper::touch — only here
        // the presenter's polls of results and progress do not even send the
        // UPDATE.
        $this->room->setTouchedAt($touchedAt);

        $this->controller()->results('ABCDEF', 7);

        $this->assertSame($writes ? [[5, self::NOW]] : [], $this->touches);
    }

    public function testEveryActionIsClassified(): void {
        $declared = [];
        foreach ((new \ReflectionClass(RoomApiController::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() === RoomApiController::class && $m->getName() !== '__construct') {
                $declared[] = $m->getName();
            }
        }
        $known = array_merge(array_keys(self::roomActions()), self::WITHOUT_ROOM);
        sort($declared);
        sort($known);
        $this->assertSame($declared, $known, 'New moderator action? Add it to roomActions() or WITHOUT_ROOM.');
    }

    public function testRoomsAreLoadedOnlyThroughOwnedRoom(): void {
        // Source guard: a new action that called getOwnedRoom itself would
        // silently skip the touch.
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/RoomApiController.php');
        $this->assertSame(1, substr_count($source, '->getOwnedRoom('), 'only ownedRoom() may call getOwnedRoom()');
        $this->assertSame(1, substr_count($source, '->roomMapper->touch('), 'only touch() may write touched_at');
    }

    public function testVisitAppearsInNoResponse(): void {
        // A touch must not change what a client sees — no payload, no
        // version hash. Room::jsonSerialize is the moderator payload (roomView);
        // everything else builds its fields by hand, so the guard is: nobody
        // outside retention reads touchedAt at all.
        $room = $this->liveRoom();
        $room->setTouchedAt(self::NOW);
        $this->assertArrayNotHasKey('touchedAt', $room->jsonSerialize());

        $readers = [];
        $lib = dirname(__DIR__, 2) . '/lib';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php' && str_contains((string)file_get_contents($file->getPathname()), 'getTouchedAt(')) {
                $readers[] = substr($file->getPathname(), strlen($lib) + 1);
            }
        }
        sort($readers);
        // Room.php only declares the getter (@method).
        $this->assertSame(['Controller/RoomApiController.php', 'Db/Room.php', 'Service/RoomService.php'], $readers);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function controller(): RoomApiController {
        $request = $this->createMock(IRequest::class);
        // Nothing sent: every parameter takes its default.
        $request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => $default);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $rooms = $this->createMock(RoomService::class);
        $rooms->method('getOwnedRoom')->willReturnCallback(function (): Room {
            if ($this->foreign) {
                throw new NotOwnerException();
            }
            return $this->room;
        });
        $rooms->method('duplicateRoom')->willReturnCallback(fn (): Room => $this->liveRoom());

        $mapper = $this->createMock(RoomMapper::class);
        $mapper->method('touch')->willReturnCallback(function (int $roomId, int $now): void {
            $this->touches[] = [$roomId, $now];
        });

        $pace = $this->createMock(PaceService::class);
        // Self-paced writes run under the room lock; the dummy just runs them.
        $pace->method('locked')->willReturnCallback(fn (Room $r, callable $fn): mixed => $fn($r));

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        return new RoomApiController(
            $request,
            $this->createMock(IUserSession::class),
            $rooms,
            $this->createMock(DeckService::class),
            $this->createMock(StateService::class),
            $this->createMock(VoteService::class),
            $this->createMock(DemoService::class),
            $this->createMock(PollImageService::class),
            $l10n,
            $pace,
            $this->createMock(PaceStateService::class),
            $mapper,
            $time,
            $this->createMock(\OCP\Security\RateLimiting\ILimiter::class),
            new \OCA\Pulse\Service\Limits($this->createMock(\OCP\IAppConfig::class)),
        );
    }

    /** Moderated quiz, never touched. */
    private function liveRoom(): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('ABCDEF');
        $room->setOwnerUid('');
        $room->setMode('quiz');
        $room->setPace('live');
        $room->setCreatedAt(self::NOW - 40 * 86_400);
        return $room;
    }

    /** Self-paced quiz, still a draft. */
    private function selfRoom(): Room {
        $room = $this->liveRoom();
        $room->setPace('self');
        return $room;
    }
}
