<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Controller\PublicVoteController;
use OCA\Pulse\Controller\RoomApiController;
use OCA\Pulse\Db\Player;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Service\CodeGenerator;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\DemoService;
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
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\RateLimiting\ILimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Parameters and cookie come raw from the client. As a list, an object, 1e100
 * or in a shape the server never handed out, they must not trigger a PHP
 * warning (phpunit.xml: failOnWarning), a TypeError or a 500 —
 * they count as not sent: the usual 400, the default or a new
 * cookie. Plus a source-code guard against raw casts on request values.
 *
 * The services are dummies; all that counts here is what the controller reads
 * from the request and passes on. HostileInputTest checks the services themselves.
 */
#[CoversClass(RoomApiController::class)]
#[CoversClass(PublicVoteController::class)]
class ControllerInputTest extends TestCase {

    /** Nested object — `x[y]=…` in a form, {"x":["y"]} in JSON. */
    private const HOSTILE = ['x' => ['y']];
    /** The token that the CodeGenerator::voterToken dummy hands out anew. */
    private const FRESH = 'BennBennBennBennBennBennBennBenn';

    /** @var array<string, mixed> sent parameters; if a key is missing, $fallback applies */
    private array $params = [];
    /** Value of every parameter that is not in $params (null = missing). */
    private mixed $fallback = self::HOSTILE;
    private mixed $cookie = self::HOSTILE;
    /** @var list<array{0: string, 1: list<mixed>}> PaceService calls, without the room */
    private array $paceCalls = [];
    /** @var list<array{0: string, 1: list<mixed>}> calls to Deck/Vote/State/Room, without the room */
    private array $calls = [];
    /** How often CodeGenerator::voterToken handed out a new token. */
    private int $issued = 0;

    private Room $room;
    private IRequest&MockObject $request;
    private IL10N&MockObject $l10n;
    private PaceService&MockObject $pace;
    private DeckService&MockObject $deck;
    private VoteService&MockObject $votes;
    private StateService&MockObject $state;
    private RoomService&MockObject $rooms;
    private CodeGenerator&MockObject $codes;
    private ITimeFactory&MockObject $time;

    protected function setUp(): void {
        $this->room = $this->quizRoom();

        $this->request = $this->createMock(IRequest::class);
        // Like Nextcloud: a null value (JSON null, missing) yields the default.
        $this->request->method('getParam')->willReturnCallback(
            fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $this->params) ? $this->params[$key] : $this->fallback) ?? $default
        );
        $this->request->method('getCookie')->willReturnCallback(fn () => $this->cookie);
        // `image[]=…`: PHP creates every field of the upload as a list.
        $this->request->method('getUploadedFile')->willReturn(['name' => ['a.png'], 'tmp_name' => ['/tmp/x'], 'error' => [0], 'size' => [1]]);
        $this->request->method('getRemoteAddress')->willReturn('192.0.2.1');
        // Like the public bundle (@nextcloud/axios): participant POSTs need it (PublicCrossSiteTest).
        $this->request->method('getHeader')->willReturnCallback(fn (string $name): string => $name === 'X-Requested-With' ? 'XMLHttpRequest' : '');

        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnArgument(0);

        $this->pace = $this->createMock(PaceService::class);
        foreach (['setPace', 'openWindow', 'closeWindow', 'extendWindow', 'releaseWindow', 'setJoinsLocked', 'removePlayer'] as $m) {
            $this->pace->method($m)->willReturnCallback(function (Room $r, mixed ...$rest) use ($m): Room {
                $this->paceCalls[] = [$m, $rest];
                return $r;
            });
        }

        $this->deck = $this->createMock(DeckService::class);
        $this->deck->method('setCurrent')->willReturnCallback(function (Room $r, int $pollId): void {
            $this->calls[] = ['setCurrent', [$pollId]];
        });

        $this->votes = $this->createMock(VoteService::class);
        $this->votes->method('quizJoin')->willReturnCallback(function (Room $r, string $token, string $nickname): Player {
            $this->calls[] = ['quizJoin', [$token, $nickname]];
            return new Player();
        });
        $this->votes->method('recordVote')->willReturnCallback(function (Room $r, string $token, mixed $value, bool $keyboard = false, ?int $pollId = null): void {
            $this->calls[] = ['recordVote', [$token, $value, $keyboard, $pollId]];
        });
        $this->votes->method('gradeTextAnswer')->willReturnCallback(function (Room $r, int $pollId, string $answer, bool $correct): void {
            $this->calls[] = ['gradeTextAnswer', [$pollId, $answer, $correct]];
        });

        $this->state = $this->createMock(StateService::class);
        $this->state->method('publicSummary')->willReturnCallback(function (Room $r, ?string $token): array {
            $this->calls[] = ['publicSummary', [$token]];
            return [];
        });

        $this->rooms = $this->createMock(RoomService::class);
        $this->rooms->method('getOwnedRoom')->willReturnCallback(fn (): Room => $this->room);
        $this->rooms->method('heartbeat')->willReturnCallback(function (Room $r, string $token): void {
            $this->calls[] = ['heartbeat', [$token]];
        });

        $this->codes = $this->createMock(CodeGenerator::class);
        $this->codes->method('voterToken')->willReturnCallback(function (): string {
            $this->issued++;
            return self::FRESH;
        });

        $this->time = $this->createMock(ITimeFactory::class);
        $this->time->method('getDateTime')->willReturnCallback(fn () => new \DateTime('@1800000000'));
        $this->time->method('getTime')->willReturn(1800000000);
    }

    // ── Moderator: every action, every parameter a list ────────────────────

    /**
     * Every public action of the moderator controller: [method, arguments,
     * self-paced room?]. testEveryActionIsCovered keeps the list
     * complete — a new action without a row here is caught there.
     */
    public static function moderatorActions(): array {
        $c = ['ABCDEF'];
        $p = ['ABCDEF', 7];
        return [
            'index' => ['index', [], false],
            'summary' => ['summary', $c, false],
            'exportCsv' => ['exportCsv', $c, false],
            'create' => ['create', [], false],
            'duplicate' => ['duplicate', $c, false],
            'rename' => ['rename', $c, false],
            'leaderboard' => ['leaderboard', $c, false],
            'show' => ['show', $c, false],
            'destroy' => ['destroy', $c, false],
            'resetRoom' => ['resetRoom', $c, false],
            'practice' => ['practice', $c, false],
            'reveal' => ['reveal', $c, false],
            'endQuiz' => ['endQuiz', $c, false],
            'pace' => ['pace', $c, false],
            'progress' => ['progress', $c, true],
            'addPoll' => ['addPoll', $c, false],
            'updatePoll' => ['updatePoll', $p, false],
            'reorder' => ['reorder', $c, false],
            'setCurrent' => ['setCurrent', $c, false],
            'deletePoll' => ['deletePoll', $p, false],
            'results' => ['results', $p, false],
            'resetPoll' => ['resetPoll', $p, false],
            'gradeAnswer' => ['gradeAnswer', $p, false],
            'uploadImage' => ['uploadImage', $p, false],
            'deleteImage' => ['deleteImage', $p, false],
            'showImage' => ['showImage', $p, false],
            'lockPoll' => ['lockPoll', $p, false],
            'unlockPoll' => ['unlockPoll', $p, false],
            'demoSeed' => ['demoSeed', $c, false],
            'demoClear' => ['demoClear', $c, false],
        ];
    }

    #[DataProvider('moderatorActions')]
    public function testModeratorActionWithListInEveryParameter(string $method, array $args, bool $self): void {
        $this->room = $self ? $this->selfRoom() : $this->quizRoom();

        $response = $this->moderator()->$method(...$args);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertLessThan(500, $response->getStatus(), $method);
    }

    public function testEveryActionIsCovered(): void {
        $declared = [];
        foreach ((new \ReflectionClass(RoomApiController::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() === RoomApiController::class && $m->getName() !== '__construct') {
                $declared[] = $m->getName();
            }
        }
        $covered = array_keys(self::moderatorActions());
        sort($declared);
        sort($covered);
        $this->assertSame($declared, $covered, 'Neue Moderator-Aktion? Eine Zeile in moderatorActions() ergänzen.');
    }

    // ── /pace ──────────────────────────────────────────────────────────────

    public function testDeadlineAsListIs400(): void {
        $response = $this->paceAction('open', ['closesAt' => self::HOSTILE]);

        $this->assertBadRequest($response, 'The deadline must be between one minute and 30 days from now.');
        $this->assertSame([], $this->paceCalls);
    }

    public function testInfiniteDeadlineIs400(): void {
        // JSON 1e999 arrives in PHP as float INF.
        $response = $this->paceAction('open', ['closesAt' => INF]);

        $this->assertBadRequest($response, 'The deadline must be between one minute and 30 days from now.');
        $this->assertSame([], $this->paceCalls);
    }

    public function testExtendWithDeadlineAsListIs400(): void {
        // Silently "no deadline" would mean, when extending: open until someone closes.
        $response = $this->paceAction('extend', ['closesAt' => self::HOSTILE]);

        $this->assertBadRequest($response, 'The deadline must be between one minute and 30 days from now.');
        $this->assertSame([], $this->paceCalls);
    }

    public function testTimerSwitchAsListIs400(): void {
        $response = $this->paceAction('open', ['timed' => self::HOSTILE]);

        $this->assertBadRequest($response, 'Invalid request.');
        $this->assertSame([], $this->paceCalls);
    }

    public function testFeedbackAsListArrivesEmptyAtTheService(): void {
        // The service checks it anyway: '' -> "Unknown feedback setting."
        $this->paceAction('open', ['feedback' => self::HOSTILE]);

        $this->assertSame([['openWindow', [0, null, '']]], $this->paceCalls);
    }

    public function testOpenWithoutSettingsTakesTheDefaults(): void {
        $this->paceAction('open', []);

        $this->assertSame([['openWindow', [0, null, null]]], $this->paceCalls);
    }

    public function testDeadlineAsDigitString(): void {
        $this->paceAction('open', ['closesAt' => '1800003600']);

        $this->assertSame([['openWindow', [1800003600, null, null]]], $this->paceCalls);
    }

    public function testDeadlineWithDecimalPlacesIsTruncated(): void {
        $this->paceAction('open', ['closesAt' => 1800003600.7]);

        $this->assertSame([['openWindow', [1800003600, null, null]]], $this->paceCalls);
    }

    public function testPaceAsListArrivesEmptyAtTheService(): void {
        $this->paceAction('set', ['pace' => self::HOSTILE]);

        $this->assertSame([['setPace', ['']]], $this->paceCalls);
    }

    public function testPlayerAsListIsNone(): void {
        $this->paceAction('removePlayer', ['playerId' => self::HOSTILE]);

        $this->assertSame([['removePlayer', [0]]], $this->paceCalls);
    }

    public function testActionAsListIsUnknown(): void {
        $response = $this->paceAction(self::HOSTILE, []);

        $this->assertBadRequest($response, 'Unknown action.');
        $this->assertSame([], $this->paceCalls);
    }

    public static function paceActions(): array {
        return [
            'set' => ['set'],
            'open' => ['open'],
            'close' => ['close'],
            'extend' => ['extend'],
            'release' => ['release'],
            'lockJoins' => ['lockJoins'],
            'unlockJoins' => ['unlockJoins'],
            'removePlayer' => ['removePlayer'],
        ];
    }

    #[DataProvider('paceActions')]
    public function testPaceActionWithListsInAllOtherParameters(string $action): void {
        $this->room = $this->selfRoom();
        $this->params = ['action' => $action];

        $response = $this->moderator()->pace('ABCDEF');

        $this->assertLessThan(500, $response->getStatus(), $action);
    }

    // ── close {release} ────────────────────────────────────────────────────

    /** sent -> what closeWindow receives (null = rule based on the deadline) */
    public static function validRelease(): array {
        return [
            'fehlt' => [[], null],
            'JSON null' => [['release' => null], null],
            'leer (Formular)' => [['release' => ''], false],
            'JSON false' => [['release' => false], false],
            'JSON true' => [['release' => true], true],
            'JSON 0' => [['release' => 0], false],
            'JSON 1' => [['release' => 1], true],
            '"false"' => [['release' => 'false'], false],
            '"0"' => [['release' => '0'], false],
            '"off"' => [['release' => 'off'], false],
            '"true"' => [['release' => 'true'], true],
            '"1"' => [['release' => '1'], true],
        ];
    }

    #[DataProvider('validRelease')]
    public function testReleaseGoesToCloseWindow(array $params, ?bool $expected): void {
        $response = $this->paceAction('close', $params);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([['closeWindow', [$expected]]], $this->paceCalls);
    }

    public static function invalidRelease(): array {
        return [
            'Liste' => [['x']],
            'Objekt' => [['a' => 1]],
            'Wort' => ['maybe'],
            'Zahl 2' => [2],
            'Kommazahl' => [0.5],
        ];
    }

    #[DataProvider('invalidRelease')]
    public function testInvalidReleaseIs400WithoutClosing(mixed $release): void {
        // Never silently the old rule: in a race it would mean "release".
        $response = $this->paceAction('close', ['release' => $release]);

        $this->assertBadRequest($response, 'Invalid request.');
        $this->assertSame([], $this->paceCalls);
    }

    public function testReleaseCountsOnlyOnClose(): void {
        $response = $this->paceAction('release', ['release' => ['x']]);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([['releaseWindow', []]], $this->paceCalls);

        $this->paceCalls = [];
        $response = $this->paceAction('extend', ['release' => ['x'], 'closesAt' => 0]);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([['extendWindow', [0]]], $this->paceCalls);
    }

    // ── Grading free text ──────────────────────────────────────────────────

    public function testGradingKeepsNul(): void {
        // The vote "Pa\0ris" keeps its NUL (normalizeValue); the moderator
        // sends exactly this text back. Without the NUL gradeText would never find
        // the group, and the answer would stay "Being checked" forever.
        $this->fallback = null;
        $this->params = ['answer' => "Pa\0ris", 'correct' => true];

        $this->moderator()->gradeAnswer('ABCDEF', 7);

        $this->assertSame([[7, "Pa\0ris", true]], $this->callsTo('gradeTextAnswer'));
    }

    public function testGradingWithListsArrivesEmpty(): void {
        // The service then reports "Empty answer." (400).
        $this->params = ['answer' => self::HOSTILE, 'correct' => self::HOSTILE];

        $this->moderator()->gradeAnswer('ABCDEF', 7);

        $this->assertSame([[7, '', false]], $this->callsTo('gradeTextAnswer'));
    }

    // ── Cursor ─────────────────────────────────────────────────────────────

    public function testCursorAsListIs400(): void {
        $this->fallback = null;
        $this->params = ['pollId' => self::HOSTILE];

        $response = $this->moderator()->setCurrent('ABCDEF');

        $this->assertBadRequest($response, 'Invalid request.');
        $this->assertSame([], $this->callsTo('setCurrent'));
    }

    public function testHugeCursorIs400(): void {
        $this->fallback = null;
        $this->params = ['pollId' => 1e100];

        $response = $this->moderator()->setCurrent('ABCDEF');

        $this->assertBadRequest($response, 'Invalid request.');
        $this->assertSame([], $this->callsTo('setCurrent'));
    }

    public function testMissingCursorMeansPresentationIsIdle(): void {
        $this->fallback = null;

        $response = $this->moderator()->setCurrent('ABCDEF');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([[0]], $this->callsTo('setCurrent'));
    }

    // ── Public ─────────────────────────────────────────────────────────────

    public static function publicActions(): array {
        $rows = [];
        foreach (['moderiert' => false, 'eigenes Tempo' => true] as $roomKind => $self) {
            foreach ([
                'state' => ['ABCDEF'],
                'summary' => ['ABCDEF'],
                'image' => ['ABCDEF', 7],
                'join' => ['ABCDEF'],
                'vote' => ['ABCDEF'],
                'next' => ['ABCDEF'],
            ] as $method => $args) {
                $rows["$method, $roomKind"] = [$method, $args, $self];
            }
        }
        return $rows;
    }

    #[DataProvider('publicActions')]
    public function testPublicActionWithListInEveryParameter(string $method, array $args, bool $self): void {
        $this->room = $self ? $this->selfRoom() : $this->quizRoom();

        $response = $this->public()->$method(...$args);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertLessThan(500, $response->getStatus(), $method);
    }

    public static function brokenCookies(): array {
        return [
            'Objekt (pulse_vt[x][]=y)' => [self::HOSTILE],
            'Liste (pulse_vt[]=a)' => [['a']],
            'zu lang' => [str_repeat('A', 33)],
            'fremde Form' => ['abc'],
            'Zeilenumbruch am Ende' => [str_repeat('A', 32) . "\n"],
            'kaputtes UTF-8' => [str_repeat("\xFF", 32)],
            'leer' => [''],
        ];
    }

    #[DataProvider('brokenCookies')]
    public function testBrokenCookieCountsAsNone(mixed $cookie): void {
        $this->fallback = null;
        $this->cookie = $cookie;

        // /state hands out a new cookie; the person counts once it comes back
        // (no presence row for a request without a cookie, PublicNewTokenTest).
        $response = $this->public()->state('ABCDEF');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([], $this->callsTo('heartbeat'));
        $this->assertSame(self::FRESH, $response->getCookies()['pulse_vt']['value'] ?? null);

        // /summary reads without a cookie.
        $this->public()->summary('ABCDEF');
        $this->assertSame([[null]], $this->callsTo('publicSummary'));

        // /next without a cookie: no player, nothing to start.
        $this->room = $this->selfRoom();
        $this->assertBadRequest($this->public()->next('ABCDEF'), 'Please choose a name first.');
    }

    public function testValidCookieStays(): void {
        $this->fallback = null;
        $this->cookie = str_repeat('Anna', 8);
        $this->params = ['nickname' => 'Anna'];

        $this->public()->join('ABCDEF');

        $this->assertSame([[str_repeat('Anna', 8), 'Anna']], $this->callsTo('quizJoin'));
        $this->assertSame(0, $this->issued, 'kein neues Token vergeben');
    }

    public function testNameAsListArrivesEmpty(): void {
        // The service then reports "Please enter a name." (400).
        $this->params = ['nickname' => self::HOSTILE];

        $this->public()->join('ABCDEF');

        $this->assertSame([[self::FRESH, '']], $this->callsTo('quizJoin'));
    }

    public function testVoteWithHugePollIdCountsAsWithout(): void {
        $this->fallback = null;
        $this->params = ['value' => 'AA', 'pollId' => 1e100];

        $this->public()->vote('ABCDEF');

        $this->assertSame([[self::FRESH, 'AA', false, null]], $this->callsTo('recordVote'));
    }

    // ── Source-code guard ──────────────────────────────────────────────────

    public function testNoRawCastOnRequestValues(): void {
        $files = glob(dirname(__DIR__, 2) . '/lib/Controller/*.php');
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertNotFalse($source, $file);
            $this->assertDoesNotMatchRegularExpression('/\((?:string|int|float|bool)\)\s*\$this->request->/', $source, basename($file));
            $this->assertDoesNotMatchRegularExpression('/(?:intval|floatval|strval|settype|filter_var)\(\s*\$this->request->/', $source, basename($file));
        }
        // Only PublicVoteController::cookieValue() reads the voter cookie.
        $controllers = dirname(__DIR__, 2) . '/lib/Controller/';
        $this->assertSame(1, substr_count((string)file_get_contents($controllers . 'PublicVoteController.php'), '->getCookie('));
        $this->assertSame(0, substr_count((string)file_get_contents($controllers . 'RoomApiController.php'), '->getCookie('));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** /pace in a self-paced room; everything except $params is missing. */
    private function paceAction(mixed $action, array $params): JSONResponse {
        $this->room = $this->selfRoom();
        $this->fallback = null;
        $this->params = ['action' => $action] + $params;
        return $this->moderator()->pace('ABCDEF');
    }

    /** @return list<list<mixed>> the arguments of every call of $method */
    private function callsTo(string $method): array {
        return array_values(array_map(
            static fn (array $c): array => $c[1],
            array_filter($this->calls, static fn (array $c): bool => $c[0] === $method),
        ));
    }

    private function assertBadRequest(JSONResponse $response, string $message): void {
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['message' => $message], $response->getData());
    }

    private function moderator(): RoomApiController {
        return new RoomApiController(
            $this->request,
            $this->createMock(IUserSession::class),
            $this->rooms,
            $this->deck,
            $this->state,
            $this->votes,
            $this->createMock(DemoService::class),
            $this->createMock(PollImageService::class),
            $this->l10n,
            $this->pace,
            $this->createMock(PaceStateService::class),
            $this->createMock(RoomMapper::class),
            $this->time,
            $this->createMock(ILimiter::class),
            new \OCA\Pulse\Service\Limits($this->createMock(\OCP\IAppConfig::class)),
        );
    }

    private function public(): PublicVoteController {
        $mapper = $this->createMock(RoomMapper::class);
        $mapper->method('findByCode')->willReturnCallback(fn (): Room => $this->room);
        return new PublicVoteController(
            $this->request,
            $mapper,
            $this->rooms,
            $this->state,
            $this->votes,
            $this->deck,
            $this->createMock(PollImageService::class),
            $this->codes,
            $this->time,
            $this->l10n,
            $this->pace,
            $this->createMock(ILimiter::class),
            $this->createMock(\OCP\Security\Bruteforce\IThrottler::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IAppConfig::class),
            new \OCA\Pulse\Service\Limits($this->createMock(\OCP\IAppConfig::class)),
        );
    }

    /** Moderated quiz (cursor, no room lock). */
    private function quizRoom(): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('ABCDEF');
        $room->setOwnerUid('');
        $room->setMode('quiz');
        $room->setPace('live');
        return $room;
    }

    /** Self-paced quiz, window open. */
    private function selfRoom(): Room {
        $room = $this->quizRoom();
        $room->setPace('self');
        $room->setOpenedAt(1_799_999_900);
        $room->setDeckOrder('[11]');
        return $room;
    }
}
