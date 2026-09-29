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
 * Parameter und Cookie kommen roh vom Client. Als Liste, Objekt, 1e100 oder in
 * einer Form, die der Server nie vergeben hat, dürfen sie keine PHP-Warnung
 * auslösen (phpunit.xml: failOnWarning), keinen TypeError und keine 500 —
 * sie gelten als nicht gesendet: die übliche 400, die Vorgabe oder ein neues
 * Cookie. Dazu ein Quelltext-Wächter gegen rohe Casts auf Request-Werte.
 *
 * Die Dienste sind Attrappen; hier zählt nur, was der Controller aus der
 * Anfrage liest und weiterreicht. Die Dienste selbst prüft HostileInputTest.
 */
#[CoversClass(RoomApiController::class)]
#[CoversClass(PublicVoteController::class)]
class ControllerInputTest extends TestCase {

    /** Verschachteltes Objekt — `x[y]=…` im Formular, {"x":["y"]} in JSON. */
    private const HOSTILE = ['x' => ['y']];
    /** Das Token, das die Attrappe von CodeGenerator::voterToken neu vergibt. */
    private const FRESH = 'BennBennBennBennBennBennBennBenn';

    /** @var array<string, mixed> gesendete Parameter; fehlt ein Schlüssel, gilt $fallback */
    private array $params = [];
    /** Wert jedes Parameters, der nicht in $params steht (null = fehlt). */
    private mixed $fallback = self::HOSTILE;
    private mixed $cookie = self::HOSTILE;
    /** @var list<array{0: string, 1: list<mixed>}> PaceService-Aufrufe, ohne den Raum */
    private array $paceCalls = [];
    /** @var list<array{0: string, 1: list<mixed>}> Aufrufe an Deck/Vote/State/Room, ohne den Raum */
    private array $calls = [];
    /** Wie oft CodeGenerator::voterToken ein neues Token vergab. */
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
        // Wie Nextcloud: ein Wert null (JSON null, fehlt) ergibt die Vorgabe.
        $this->request->method('getParam')->willReturnCallback(
            fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $this->params) ? $this->params[$key] : $this->fallback) ?? $default
        );
        $this->request->method('getCookie')->willReturnCallback(fn () => $this->cookie);
        // `image[]=…`: PHP legt jedes Feld des Uploads als Liste an.
        $this->request->method('getUploadedFile')->willReturn(['name' => ['a.png'], 'tmp_name' => ['/tmp/x'], 'error' => [0], 'size' => [1]]);
        $this->request->method('getRemoteAddress')->willReturn('192.0.2.1');

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

    // ── Moderator: jede Aktion, jeder Parameter eine Liste ─────────────────

    /**
     * Jede öffentliche Aktion des Moderator-Controllers: [Methode, Argumente,
     * Raum im eigenen Tempo?]. testJedeAktionIstAbgedeckt hält die Liste
     * vollständig — eine neue Aktion ohne Zeile hier fällt dort auf.
     */
    public static function moderatorAktionen(): array {
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

    #[DataProvider('moderatorAktionen')]
    public function testModeratorAktionMitListeInJedemParameter(string $method, array $args, bool $self): void {
        $this->room = $self ? $this->selfRoom() : $this->quizRoom();

        $response = $this->moderator()->$method(...$args);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertLessThan(500, $response->getStatus(), $method);
    }

    public function testJedeAktionIstAbgedeckt(): void {
        $declared = [];
        foreach ((new \ReflectionClass(RoomApiController::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() === RoomApiController::class && $m->getName() !== '__construct') {
                $declared[] = $m->getName();
            }
        }
        $covered = array_keys(self::moderatorAktionen());
        sort($declared);
        sort($covered);
        $this->assertSame($declared, $covered, 'Neue Moderator-Aktion? Eine Zeile in moderatorAktionen() ergänzen.');
    }

    // ── /pace ──────────────────────────────────────────────────────────────

    public function testFristAlsListeIst400(): void {
        $response = $this->paceAction('open', ['closesAt' => self::HOSTILE]);

        $this->assertBadRequest($response, 'The deadline must be between one minute and 30 days from now.');
        $this->assertSame([], $this->paceCalls);
    }

    public function testFristUnendlichIst400(): void {
        // JSON 1e999 kommt in PHP als float INF an.
        $response = $this->paceAction('open', ['closesAt' => INF]);

        $this->assertBadRequest($response, 'The deadline must be between one minute and 30 days from now.');
        $this->assertSame([], $this->paceCalls);
    }

    public function testVerlaengernMitFristAlsListeIst400(): void {
        // Still „ohne Frist“ hieße beim Verlängern: offen, bis jemand schließt.
        $response = $this->paceAction('extend', ['closesAt' => self::HOSTILE]);

        $this->assertBadRequest($response, 'The deadline must be between one minute and 30 days from now.');
        $this->assertSame([], $this->paceCalls);
    }

    public function testTimerSchalterAlsListeIst400(): void {
        $response = $this->paceAction('open', ['timed' => self::HOSTILE]);

        $this->assertBadRequest($response, 'Invalid request.');
        $this->assertSame([], $this->paceCalls);
    }

    public function testRueckmeldungAlsListeKommtLeerBeimDienstAn(): void {
        // Der Dienst prüft sie ohnehin: '' -> „Unknown feedback setting.“
        $this->paceAction('open', ['feedback' => self::HOSTILE]);

        $this->assertSame([['openWindow', [0, null, '']]], $this->paceCalls);
    }

    public function testOeffnenOhneEinstellungenNimmtDieVorgaben(): void {
        $this->paceAction('open', []);

        $this->assertSame([['openWindow', [0, null, null]]], $this->paceCalls);
    }

    public function testFristAlsZiffernText(): void {
        $this->paceAction('open', ['closesAt' => '1800003600']);

        $this->assertSame([['openWindow', [1800003600, null, null]]], $this->paceCalls);
    }

    public function testFristMitNachkommastellenWirdAbgeschnitten(): void {
        $this->paceAction('open', ['closesAt' => 1800003600.7]);

        $this->assertSame([['openWindow', [1800003600, null, null]]], $this->paceCalls);
    }

    public function testTempoAlsListeKommtLeerBeimDienstAn(): void {
        $this->paceAction('set', ['pace' => self::HOSTILE]);

        $this->assertSame([['setPace', ['']]], $this->paceCalls);
    }

    public function testSpielerAlsListeIstKeiner(): void {
        $this->paceAction('removePlayer', ['playerId' => self::HOSTILE]);

        $this->assertSame([['removePlayer', [0]]], $this->paceCalls);
    }

    public function testAktionAlsListeIstUnbekannt(): void {
        $response = $this->paceAction(self::HOSTILE, []);

        $this->assertBadRequest($response, 'Unknown action.');
        $this->assertSame([], $this->paceCalls);
    }

    public static function paceAktionen(): array {
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

    #[DataProvider('paceAktionen')]
    public function testPaceAktionMitListenInAllenAnderenParametern(string $action): void {
        $this->room = $this->selfRoom();
        $this->params = ['action' => $action];

        $response = $this->moderator()->pace('ABCDEF');

        $this->assertLessThan(500, $response->getStatus(), $action);
    }

    // ── close {release} ────────────────────────────────────────────────────

    /** gesendet -> was closeWindow bekommt (null = Regel nach der Frist) */
    public static function gueltigesRelease(): array {
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

    #[DataProvider('gueltigesRelease')]
    public function testReleaseGehtAnCloseWindow(array $params, ?bool $expected): void {
        $response = $this->paceAction('close', $params);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([['closeWindow', [$expected]]], $this->paceCalls);
    }

    public static function ungueltigesRelease(): array {
        return [
            'Liste' => [['x']],
            'Objekt' => [['a' => 1]],
            'Wort' => ['maybe'],
            'Zahl 2' => [2],
            'Kommazahl' => [0.5],
        ];
    }

    #[DataProvider('ungueltigesRelease')]
    public function testUngueltigesReleaseIst400OhneSchliessen(mixed $release): void {
        // Nie still die alte Regel: die hieße im Rennen „freigeben“.
        $response = $this->paceAction('close', ['release' => $release]);

        $this->assertBadRequest($response, 'Invalid request.');
        $this->assertSame([], $this->paceCalls);
    }

    public function testReleaseZaehltNurBeiClose(): void {
        $response = $this->paceAction('release', ['release' => ['x']]);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([['releaseWindow', []]], $this->paceCalls);

        $this->paceCalls = [];
        $response = $this->paceAction('extend', ['release' => ['x'], 'closesAt' => 0]);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([['extendWindow', [0]]], $this->paceCalls);
    }

    // ── Freitext bewerten ──────────────────────────────────────────────────

    public function testBewertungBehaeltNul(): void {
        // Die Stimme „Pa\0ris“ behält ihr NUL (normalizeValue); die Moderation
        // schickt genau diesen Text zurück. Ohne NUL fände gradeText die Gruppe
        // nie, und die Antwort bliebe für immer „wird geprüft“.
        $this->fallback = null;
        $this->params = ['answer' => "Pa\0ris", 'correct' => true];

        $this->moderator()->gradeAnswer('ABCDEF', 7);

        $this->assertSame([[7, "Pa\0ris", true]], $this->callsTo('gradeTextAnswer'));
    }

    public function testBewertungMitListenKommtLeerAn(): void {
        // Der Dienst meldet dann „Empty answer.“ (400).
        $this->params = ['answer' => self::HOSTILE, 'correct' => self::HOSTILE];

        $this->moderator()->gradeAnswer('ABCDEF', 7);

        $this->assertSame([[7, '', false]], $this->callsTo('gradeTextAnswer'));
    }

    // ── Cursor ─────────────────────────────────────────────────────────────

    public function testCursorAlsListeIst400(): void {
        $this->fallback = null;
        $this->params = ['pollId' => self::HOSTILE];

        $response = $this->moderator()->setCurrent('ABCDEF');

        $this->assertBadRequest($response, 'Invalid request.');
        $this->assertSame([], $this->callsTo('setCurrent'));
    }

    public function testCursorRiesigIst400(): void {
        $this->fallback = null;
        $this->params = ['pollId' => 1e100];

        $response = $this->moderator()->setCurrent('ABCDEF');

        $this->assertBadRequest($response, 'Invalid request.');
        $this->assertSame([], $this->callsTo('setCurrent'));
    }

    public function testCursorFehltHeisstPraesentationRuht(): void {
        $this->fallback = null;

        $response = $this->moderator()->setCurrent('ABCDEF');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([[0]], $this->callsTo('setCurrent'));
    }

    // ── Öffentlich ─────────────────────────────────────────────────────────

    public static function oeffentlicheAktionen(): array {
        $rows = [];
        foreach (['moderiert' => false, 'eigenes Tempo' => true] as $raum => $self) {
            foreach ([
                'state' => ['ABCDEF'],
                'summary' => ['ABCDEF'],
                'image' => ['ABCDEF', 7],
                'join' => ['ABCDEF'],
                'vote' => ['ABCDEF'],
                'next' => ['ABCDEF'],
            ] as $method => $args) {
                $rows["$method, $raum"] = [$method, $args, $self];
            }
        }
        return $rows;
    }

    #[DataProvider('oeffentlicheAktionen')]
    public function testOeffentlicheAktionMitListeInJedemParameter(string $method, array $args, bool $self): void {
        $this->room = $self ? $this->selfRoom() : $this->quizRoom();

        $response = $this->public()->$method(...$args);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertLessThan(500, $response->getStatus(), $method);
    }

    public static function kaputteCookies(): array {
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

    #[DataProvider('kaputteCookies')]
    public function testKaputtesCookieGiltAlsKeins(mixed $cookie): void {
        $this->fallback = null;
        $this->cookie = $cookie;

        // /state vergibt ein neues Cookie und zählt die Person damit.
        $response = $this->public()->state('ABCDEF');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([[self::FRESH]], $this->callsTo('heartbeat'));
        $this->assertSame(self::FRESH, $response->getCookies()['pulse_vt']['value'] ?? null);

        // /summary liest ohne Cookie.
        $this->public()->summary('ABCDEF');
        $this->assertSame([[null]], $this->callsTo('publicSummary'));

        // /next ohne Cookie: kein Spieler, nichts zu starten.
        $this->room = $this->selfRoom();
        $this->assertBadRequest($this->public()->next('ABCDEF'), 'Please choose a name first.');
    }

    public function testGueltigesCookieBleibt(): void {
        $this->fallback = null;
        $this->cookie = str_repeat('Anna', 8);
        $this->params = ['nickname' => 'Anna'];

        $this->public()->join('ABCDEF');

        $this->assertSame([[str_repeat('Anna', 8), 'Anna']], $this->callsTo('quizJoin'));
        $this->assertSame(0, $this->issued, 'kein neues Token vergeben');
    }

    public function testNameAlsListeKommtLeerAn(): void {
        // Der Dienst meldet dann „Please enter a name.“ (400).
        $this->params = ['nickname' => self::HOSTILE];

        $this->public()->join('ABCDEF');

        $this->assertSame([[self::FRESH, '']], $this->callsTo('quizJoin'));
    }

    public function testStimmeMitRiesigerFrageIdGiltAlsOhne(): void {
        $this->fallback = null;
        $this->params = ['value' => 'AA', 'pollId' => 1e100];

        $this->public()->vote('ABCDEF');

        $this->assertSame([[self::FRESH, 'AA', false, null]], $this->callsTo('recordVote'));
    }

    // ── Quelltext-Wächter ──────────────────────────────────────────────────

    public function testKeinRoherCastAufRequestWerte(): void {
        $files = glob(dirname(__DIR__, 2) . '/lib/Controller/*.php');
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertNotFalse($source, $file);
            $this->assertDoesNotMatchRegularExpression('/\((?:string|int|float|bool)\)\s*\$this->request->/', $source, basename($file));
            $this->assertDoesNotMatchRegularExpression('/(?:intval|floatval|strval|settype|filter_var)\(\s*\$this->request->/', $source, basename($file));
        }
        // Das Voter-Cookie liest nur PublicVoteController::voterToken().
        $controllers = dirname(__DIR__, 2) . '/lib/Controller/';
        $this->assertSame(1, substr_count((string)file_get_contents($controllers . 'PublicVoteController.php'), '->getCookie('));
        $this->assertSame(0, substr_count((string)file_get_contents($controllers . 'RoomApiController.php'), '->getCookie('));
    }

    // ── Helfer ─────────────────────────────────────────────────────────────

    /** /pace im eigenen Tempo; alles außer $params fehlt. */
    private function paceAction(mixed $action, array $params): JSONResponse {
        $this->room = $this->selfRoom();
        $this->fallback = null;
        $this->params = ['action' => $action] + $params;
        return $this->moderator()->pace('ABCDEF');
    }

    /** @return list<list<mixed>> die Argumente jedes Aufrufs von $method */
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
        );
    }

    /** Moderiertes Quiz (Cursor, keine Raumsperre). */
    private function quizRoom(): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('ABCDEF');
        $room->setOwnerUid('');
        $room->setMode('quiz');
        $room->setPace('live');
        return $room;
    }

    /** Quiz im eigenen Tempo, Fenster offen. */
    private function selfRoom(): Room {
        $room = $this->quizRoom();
        $room->setPace('self');
        $room->setOpenedAt(1_799_999_900);
        $room->setDeckOrder('[11]');
        return $room;
    }
}
