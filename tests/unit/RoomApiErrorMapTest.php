<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Controller\RoomApiController;
use OCA\Pulse\Db\Poll;
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
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\RateLimiting\ILimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation of the moderator API's error answers, as they are today.
 *
 * Sixteen actions answer a service's InvalidArgumentException with its
 * message — some with 400, some with 404 (withRoom's status argument, or a
 * catch of their own). The split is not a rule (the same failing
 * requirePollInRoom is 400 on upload and 404 on delete), but the frontend
 * and old cached bundles branch on these statuses (results 404, progress
 * 204/404/409), so it is pinned here exactly, odd cases included. Fixing it
 * is a product decision, not a refactoring.
 *
 * In every other action it escapes (a 500), and so it does in results and
 * gradeAnswer when the question disappears after the read they answer for.
 *
 * Plus the answers that are not InvalidArgumentException pass-throughs:
 * showImage's own "Not found.", the 204 of results and progress for an
 * unchanged ?v=, and progress in a moderated room (409).
 */
#[CoversClass(RoomApiController::class)]
class RoomApiErrorMapTest extends TestCase {

    /**
     * Actions that do not pass an InvalidArgumentException's message on: in
     * most, one from a service escapes; showImage answers its own "Not
     * found.". The split is pinned by name: a new action has to be put on
     * one side.
     */
    private const WITHOUT_MESSAGE_ANSWER = [
        'index', 'summary', 'exportCsv', 'rename', 'leaderboard', 'show', 'destroy',
        'resetRoom', 'practice', 'reveal', 'endQuiz', 'progress', 'showImage', 'demoClear',
    ];

    /** 'service::method' that throws InvalidArgumentException('x'); '' = none. */
    private string $throwAt = '';
    /** @var list<string> every stubbed service call, as 'service::method' */
    private array $calls = [];
    /** @var array<string, mixed> request parameters; missing ones get the default */
    private array $params = ['action' => 'set'];
    private Room $room;

    private RoomService&MockObject $rooms;
    private DeckService&MockObject $deck;
    private StateService&MockObject $state;
    private VoteService&MockObject $votes;
    private DemoService&MockObject $demo;
    private PollImageService&MockObject $images;
    private PaceService&MockObject $pace;
    private PaceStateService&MockObject $paceState;

    protected function setUp(): void {
        $this->room = $this->liveRoom();
        $poll = new Poll();
        $poll->setId(7);

        $this->rooms = $this->createMock(RoomService::class);
        $this->rooms->method('getOwnedRoom')->willReturnCallback(fn (): Room => $this->room);
        $this->stub($this->rooms, 'rooms', [
            'createRoom' => fn (): Room => $this->room,
            'duplicateRoom' => fn (): Room => $this->room,
            'listRooms' => fn (): array => [],
            'setTitle' => fn () => null,
            'deleteRoom' => fn () => null,
            'resetRoom' => fn () => null,
            'setPractice' => fn () => null,
            'setRevealAtEnd' => fn () => null,
            'endQuiz' => fn () => null,
        ]);

        $this->deck = $this->createMock(DeckService::class);
        $this->stub($this->deck, 'deck', [
            'addPoll' => fn (): Poll => $poll,
            'updatePoll' => fn (): Poll => $poll,
            'reorder' => fn () => null,
            'setCurrent' => fn () => null,
            'deletePoll' => fn () => null,
            'resetPoll' => fn () => null,
            'lockPoll' => fn () => null,
            'unlockPoll' => fn () => null,
            'requirePollInRoom' => fn (): Poll => $poll,
            'ownerPoll' => fn (): array => [],
            'roomView' => fn (): array => [],
        ]);

        $this->state = $this->createMock(StateService::class);
        $this->stub($this->state, 'state', [
            'resultsVersion' => fn (): string => 'v1',
            'results' => fn (): array => ['type' => 'choice', 'total' => 0],
            'summary' => fn (): array => [],
            'exportCsv' => fn (): string => '',
        ]);

        $this->votes = $this->createMock(VoteService::class);
        $this->stub($this->votes, 'votes', [
            'gradeTextAnswer' => fn () => null,
            'leaderboardFor' => fn (): array => [],
        ]);

        $this->demo = $this->createMock(DemoService::class);
        $this->stub($this->demo, 'demo', [
            'seedDemoVotes' => fn (): array => ['added' => 0],
            'clearDemoVotes' => fn (): array => [],
        ]);

        $this->images = $this->createMock(PollImageService::class);
        $this->stub($this->images, 'images', [
            'store' => fn (): Poll => $poll,
            'remove' => fn (): Poll => $poll,
        ]);

        $this->pace = $this->createMock(PaceService::class);
        $this->pace->method('locked')->willReturnCallback(fn (Room $r, callable $fn): mixed => $fn($r));
        $this->stub($this->pace, 'pace', ['setPace' => fn (): Room => $this->room]);

        $this->paceState = $this->createMock(PaceStateService::class);
        $this->stub($this->paceState, 'paceState', [
            'progress' => fn (): array => ['n' => 3],
            'version' => fn (): string => 'v1',
        ]);
    }

    // ── InvalidArgumentException → 400 / 404 ─────────────────────────────

    /**
     * Every action that answers a service's InvalidArgumentException with its
     * message: the call, the service call that fails, and the status it
     * answers with today.
     */
    public static function invalidArgument(): array {
        return [
            // 400
            'create' => ['create', [], 'rooms::createRoom', Http::STATUS_BAD_REQUEST],
            'duplicate' => ['duplicate', ['ABCDEF'], 'rooms::duplicateRoom', Http::STATUS_BAD_REQUEST],
            'pace' => ['pace', ['ABCDEF'], 'pace::setPace', Http::STATUS_BAD_REQUEST],
            'addPoll' => ['addPoll', ['ABCDEF'], 'deck::addPoll', Http::STATUS_BAD_REQUEST],
            'updatePoll' => ['updatePoll', ['ABCDEF', 7], 'deck::updatePoll', Http::STATUS_BAD_REQUEST],
            'reorder' => ['reorder', ['ABCDEF'], 'deck::reorder', Http::STATUS_BAD_REQUEST],
            'gradeAnswer' => ['gradeAnswer', ['ABCDEF', 7], 'votes::gradeTextAnswer', Http::STATUS_BAD_REQUEST],
            'uploadImage: unknown question' => ['uploadImage', ['ABCDEF', 7], 'deck::requirePollInRoom', Http::STATUS_BAD_REQUEST],
            'uploadImage: unusable image' => ['uploadImage', ['ABCDEF', 7], 'images::store', Http::STATUS_BAD_REQUEST],
            'demoSeed' => ['demoSeed', ['ABCDEF'], 'demo::seedDemoVotes', Http::STATUS_BAD_REQUEST],
            // 404
            'setCurrent' => ['setCurrent', ['ABCDEF'], 'deck::setCurrent', Http::STATUS_NOT_FOUND],
            'deletePoll' => ['deletePoll', ['ABCDEF', 7], 'deck::deletePoll', Http::STATUS_NOT_FOUND],
            'results' => ['results', ['ABCDEF', 7], 'state::resultsVersion', Http::STATUS_NOT_FOUND],
            'resetPoll' => ['resetPoll', ['ABCDEF', 7], 'deck::resetPoll', Http::STATUS_NOT_FOUND],
            'deleteImage: unknown question' => ['deleteImage', ['ABCDEF', 7], 'deck::requirePollInRoom', Http::STATUS_NOT_FOUND],
            'deleteImage: removal fails' => ['deleteImage', ['ABCDEF', 7], 'images::remove', Http::STATUS_NOT_FOUND],
            'lockPoll' => ['lockPoll', ['ABCDEF', 7], 'deck::lockPoll', Http::STATUS_NOT_FOUND],
            'unlockPoll' => ['unlockPoll', ['ABCDEF', 7], 'deck::unlockPoll', Http::STATUS_NOT_FOUND],
        ];
    }

    #[DataProvider('invalidArgument')]
    public function testInvalidArgumentAnswersWithTheServiceMessage(string $method, array $args, string $throwAt, int $status): void {
        $this->throwAt = $throwAt;

        $response = $this->controller()->$method(...$args);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame($status, $response->getStatus());
        $this->assertSame(['message' => 'x'], $response->getData());
    }

    public function testEveryActionIsClassified(): void {
        $declared = [];
        foreach ((new \ReflectionClass(RoomApiController::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() === RoomApiController::class && $m->getName() !== '__construct') {
                $declared[] = $m->getName();
            }
        }
        $catching = array_values(array_unique(array_column(self::invalidArgument(), 0)));
        $this->assertCount(16, $catching, 'actions that answer with the InvalidArgumentException message');
        $known = array_merge($catching, self::WITHOUT_MESSAGE_ANSWER);
        sort($declared);
        sort($known);
        $this->assertSame($declared, $known, 'New moderator action? Add it to invalidArgument() or WITHOUT_MESSAGE_ANSWER.');
    }

    public function testShowImageAnswersNotFoundWithItsOwnMessage(): void {
        $this->throwAt = 'deck::requirePollInRoom';

        $response = $this->controller()->showImage('ABCDEF', 7);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(['message' => 'Not found.'], $response->getData());
    }

    // ── InvalidArgumentException that escapes (500) ──────────────────────

    /**
     * Everywhere else a service's InvalidArgumentException escapes: in every
     * action of WITHOUT_MESSAGE_ANSWER except showImage, and in the two races
     * where the question disappears between the read that answers 400/404
     * and StateService::results.
     *
     * [method, args, the service call that fails, self-paced room]
     */
    public static function escaping(): array {
        return [
            'index' => ['index', [], 'rooms::listRooms', false],
            'summary' => ['summary', ['ABCDEF'], 'state::summary', false],
            'exportCsv' => ['exportCsv', ['ABCDEF'], 'state::exportCsv', false],
            'rename' => ['rename', ['ABCDEF'], 'rooms::setTitle', false],
            'leaderboard' => ['leaderboard', ['ABCDEF'], 'votes::leaderboardFor', false],
            'show' => ['show', ['ABCDEF'], 'deck::roomView', false],
            'destroy' => ['destroy', ['ABCDEF'], 'rooms::deleteRoom', false],
            'resetRoom' => ['resetRoom', ['ABCDEF'], 'rooms::resetRoom', false],
            'practice' => ['practice', ['ABCDEF'], 'rooms::setPractice', false],
            'reveal' => ['reveal', ['ABCDEF'], 'rooms::setRevealAtEnd', false],
            'endQuiz' => ['endQuiz', ['ABCDEF'], 'rooms::endQuiz', false],
            'progress' => ['progress', ['ABCDEF'], 'paceState::progress', true],
            'demoClear' => ['demoClear', ['ABCDEF'], 'demo::clearDemoVotes', false],
            // the version read succeeded, the tally does not
            'results: question gone before the tally' => ['results', ['ABCDEF', 7], 'state::results', false],
            // the grading succeeded, the tally does not
            'gradeAnswer: question gone after grading' => ['gradeAnswer', ['ABCDEF', 7], 'state::results', false],
        ];
    }

    #[DataProvider('escaping')]
    public function testUndeclaredInvalidArgumentEscapes(string $method, array $args, string $throwAt, bool $self): void {
        $this->room = $self ? $this->selfRoom() : $this->liveRoom();
        $this->throwAt = $throwAt;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('x');
        $this->controller()->$method(...$args);
    }

    public function testEveryActionWithoutAnswerHasAnEscapeCase(): void {
        $covered = array_column(self::escaping(), 0);
        $this->assertSame([], array_values(array_diff(self::WITHOUT_MESSAGE_ANSWER, ['showImage'], $covered)),
            'actions in WITHOUT_MESSAGE_ANSWER without a case in escaping()');
    }

    // ── Adaptive polling: 204 for an unchanged ?v= ────────────────────────

    /** [method, args, self-paced room] — both reads answer 'v1' as the current version. */
    public static function versionedReads(): array {
        return [
            'results' => ['results', ['ABCDEF', 7], false],
            'progress' => ['progress', ['ABCDEF'], true],
        ];
    }

    #[DataProvider('versionedReads')]
    public function testMatchingVersionIs204WithoutABody(string $method, array $args, bool $self): void {
        $this->room = $self ? $this->selfRoom() : $this->liveRoom();
        $this->params = ['v' => 'v1'];

        $response = $this->controller()->$method(...$args);

        $this->assertSame(Response::class, $response::class);
        $this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
    }

    #[DataProvider('versionedReads')]
    public function testOtherOrMissingVersionGetsTheDataWithTheVersionLast(string $method, array $args, bool $self): void {
        $this->room = $self ? $this->selfRoom() : $this->liveRoom();
        $expected = $self
            ? ['n' => 3, 'version' => 'v1']
            : ['type' => 'choice', 'total' => 0, 'version' => 'v1'];

        foreach (['stale' => ['v' => 'v0'], 'missing' => []] as $case => $params) {
            $this->params = $params;

            $response = $this->controller()->$method(...$args);

            $this->assertInstanceOf(JSONResponse::class, $response, $case);
            $this->assertSame(Http::STATUS_OK, $response->getStatus(), $case);
            $this->assertSame($expected, $response->getData(), $case);
        }
    }

    public function testUnchangedResultsAreNotBuiltAtAll(): void {
        $this->params = ['v' => 'v1'];

        $this->controller()->results('ABCDEF', 7);

        $this->assertSame(['state::resultsVersion'], $this->calls);
    }

    public function testProgressInAModeratedRoomIs409(): void {
        $this->params = ['v' => 'v1'];

        $response = $this->controller()->progress('ABCDEF');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $this->assertSame(['message' => 'This room is not self-paced.'], $response->getData());
        $this->assertSame([], $this->calls);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Stub $answers on $mock and record each call; the call named by
     * $this->throwAt throws InvalidArgumentException('x') instead.
     *
     * @param array<string, \Closure(): mixed> $answers
     */
    private function stub(MockObject $mock, string $service, array $answers): void {
        foreach ($answers as $method => $answer) {
            $mock->method($method)->willReturnCallback(function () use ($service, $method, $answer): mixed {
                $this->calls[] = $service . '::' . $method;
                if ($this->throwAt === $service . '::' . $method) {
                    throw new \InvalidArgumentException('x');
                }
                return $answer();
            });
        }
    }

    private function controller(): RoomApiController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            fn (string $key, mixed $default = null): mixed => array_key_exists($key, $this->params) ? $this->params[$key] : $default,
        );
        $request->method('getUploadedFile')->willReturn(['name' => 'a.png', 'tmp_name' => '/nonexistent', 'error' => 0, 'size' => 1]);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        return new RoomApiController(
            $request,
            $session,
            $this->rooms,
            $this->deck,
            $this->state,
            $this->votes,
            $this->demo,
            $this->images,
            $l10n,
            $this->pace,
            $this->paceState,
            $this->createMock(RoomMapper::class),
            $this->createMock(ITimeFactory::class),
            $this->createMock(ILimiter::class),
            new Limits($this->createMock(IAppConfig::class)),
        );
    }

    /** Moderated quiz: deck changes and cursor control run without the room lock. */
    private function liveRoom(): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('ABCDEF');
        $room->setOwnerUid('alice');
        $room->setMode('quiz');
        $room->setPace('live');
        return $room;
    }

    private function selfRoom(): Room {
        $room = $this->liveRoom();
        $room->setPace('self');
        return $room;
    }
}
