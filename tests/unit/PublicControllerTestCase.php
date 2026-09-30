<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Controller\PublicController;
use OCA\Pulse\Controller\PublicVoteController;
use OCA\Pulse\Db\Player;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Service\CodeGenerator;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\Limits;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\RoomService;
use OCA\Pulse\Service\StateService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Services\IInitialState;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\Bruteforce\MaxDelayReached;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;
use PHPUnit\Framework\TestCase;

/**
 * Not a test: shared base for the tests of the public controllers
 * (PublicVoteController, PublicController) — the brute-force throttle for
 * unknown codes, the cross-site check on POSTs, the voter cookie and the
 * limits on new tokens.
 *
 * The request is described by plain fields: headers, the raw Cookie header
 * (sendCookies() derives $_COOKIE from it the way PHP does), protocol and
 * webroot. Services are dummies that record what reaches them; throttler,
 * limiter and app config are fakes whose calls are recorded as well.
 */
abstract class PublicControllerTestCase extends TestCase {
    /** A token in the form CodeGenerator::voterToken issues. */
    protected const ANNA = 'AnnaAnnaAnnaAnnaAnnaAnnaAnnaAnna';
    protected const EVE = 'EveEveEveEveEveEveEveEveEveEveEv';
    /** The token the CodeGenerator dummy hands out anew. */
    protected const FRESH = 'BennBennBennBennBennBennBennBenn';
    protected const IP = '192.0.2.1';
    protected const NOW = 1_800_000_000;

    /** @var array<string, string> request headers, looked up case-insensitively */
    protected array $headers = ['X-Requested-With' => 'XMLHttpRequest'];
    /** @var array<string, mixed> $_COOKIE */
    protected array $cookies = [];
    /** @var array<string, mixed> request parameters */
    protected array $params = [];
    protected string $protocol = 'https';
    protected string $webroot = '';
    /** App config pulse/voter_cookie_host_since (0 = not set). */
    protected int $hostSince = 0;
    /** Set to make the app config throw like a value of the wrong type. */
    protected ?\Exception $configError = null;
    /** null = the code is unknown (findByCode throws). */
    protected ?Room $room = null;
    /** The throttler reports "more than ten misses in 30 minutes". */
    protected bool $blocked = false;
    /**
     * Counting throttler instead of $blocked: the recorded misses of this
     * address; blocked while there are more than ten (Nextcloud's rule).
     * null = use $blocked.
     */
    protected ?int $attempts = null;
    /** @var list<string> limiter identifiers that are exhausted */
    protected array $exhausted = [];
    /** Thrown by VoteService::quizJoin when set. */
    protected ?\Exception $joinError = null;
    /** App config values an admin set (Limits keys), key => value. */
    protected array $limitConfig = [];
    /** The token already has a presence row in the room (heartbeat asks no one). */
    protected bool $presenceRow = false;

    /** @var list<array{0: string, 1: list<mixed>}> every recorded call */
    protected array $calls = [];
    protected int $issued = 0;

    protected function setUp(): void {
        $this->room = $this->liveRoom();
    }

    // ── Request ────────────────────────────────────────────────────────────

    /**
     * Send a raw Cookie header; $_COOKIE follows the way PHP builds it: the
     * first of equal names wins, ' ' and '.' in a name become '_',
     * `name[x]` is an array, and a name that starts with `__Host-` or
     * `__Secure-` only after that is dropped (PHP 8.5, checked with
     * `php -S`: `_.Host-x`, `_ Host-x`, `_[Host-x` never arrive).
     */
    protected function sendCookies(string $raw): void {
        $this->headers['Cookie'] = $raw;
        $this->cookies = [];
        foreach (explode(';', $raw) as $pair) {
            [$name, $value] = array_pad(explode('=', ltrim($pair), 2), 2, '');
            if ($name === '') {
                continue;
            }
            $rawName = $name;
            $bracket = strpos($name, '[');
            $index = null;
            if ($bracket !== false && strpos($name, ']', $bracket) !== false) {
                $index = substr($name, $bracket + 1, strpos($name, ']', $bracket) - $bracket - 1);
                $name = substr($name, 0, $bracket);
            }
            $name = strtr($name, ' .[', '___');
            foreach (['__Host-', '__Secure-'] as $prefix) {
                if (str_starts_with($name, $prefix) && !str_starts_with($rawName, $prefix)) {
                    continue 2;
                }
            }
            if (array_key_exists($name, $this->cookies)) {
                continue;
            }
            $this->cookies[$name] = $index === null ? rawurldecode($value) : [$index => rawurldecode($value)];
        }
    }

    protected function withoutHeader(string $name): void {
        unset($this->headers[$name]);
    }

    // ── Controllers ────────────────────────────────────────────────────────

    protected function voteController(): PublicVoteController {
        return new PublicVoteController(
            $this->request(),
            $this->roomMapper(),
            $this->roomService(),
            $this->stateService(),
            $this->voteService(),
            $this->deckService(),
            $this->createMock(PollImageService::class),
            $this->codeGenerator(),
            $this->timeFactory(),
            $this->l10n(),
            $this->paceService(),
            $this->limiter(),
            $this->throttler(),
            $this->urlGenerator(),
            $this->appConfig(),
            $this->limits(),
        );
    }

    protected function pageController(): PublicController {
        $initial = $this->createMock(IInitialState::class);
        $initial->method('provideInitialState')->willReturnCallback(function (string $key, mixed $value): void {
            $this->calls[] = ['initialState', [$key, $value]];
        });
        return new PublicController($this->request(), $this->roomMapper(), $initial, $this->throttler());
    }

    // ── Recorded calls ─────────────────────────────────────────────────────

    /** @return list<list<mixed>> the arguments of every call of $method */
    protected function callsTo(string $method): array {
        return array_values(array_map(
            static fn (array $c): array => $c[1],
            array_filter($this->calls, static fn (array $c): bool => $c[0] === $method),
        ));
    }

    /** @return array<string, array{value: mixed, expireDate: ?\DateTime, sameSite: string}> */
    protected function cookiesOf(Response $response): array {
        return $response->getCookies();
    }

    protected function assertNoCookies(Response $response): void {
        $this->assertSame([], $response->getCookies());
    }

    protected function assertExpired(Response $response, string $name): void {
        $cookie = $response->getCookies()[$name] ?? null;
        $this->assertNotNull($cookie, "$name is expired");
        $this->assertSame('expired', $cookie['value']);
        $this->assertLessThan(self::NOW, $cookie['expireDate']->getTimestamp());
    }

    // ── Rooms ──────────────────────────────────────────────────────────────

    protected function liveRoom(): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('ABCDEF');
        $room->setOwnerUid('');
        $room->setMode('quiz');
        $room->setPace('live');
        return $room;
    }

    protected function selfRoom(): Room {
        $room = $this->liveRoom();
        $room->setPace('self');
        $room->setOpenedAt(self::NOW - 100);
        $room->setDeckOrder('[11]');
        return $room;
    }

    // ── Doubles ────────────────────────────────────────────────────────────

    private function request(): IRequest {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default
        );
        $request->method('getHeader')->willReturnCallback(function (string $name): string {
            foreach ($this->headers as $key => $value) {
                if (strcasecmp($key, $name) === 0) {
                    return $value;
                }
            }
            return '';
        });
        $request->method('getCookie')->willReturnCallback(fn (string $name): mixed => $this->cookies[$name] ?? null);
        $request->method('getServerProtocol')->willReturnCallback(fn (): string => $this->protocol);
        $request->method('getRemoteAddress')->willReturn(self::IP);
        return $request;
    }

    private function roomMapper(): RoomMapper {
        $mapper = $this->createMock(RoomMapper::class);
        $mapper->method('findByCode')->willReturnCallback(function (string $code): Room {
            $this->calls[] = ['findByCode', [$code]];
            if ($this->room === null) {
                throw new DoesNotExistException('');
            }
            return $this->room;
        });
        return $mapper;
    }

    private function roomService(): RoomService {
        $rooms = $this->createMock(RoomService::class);
        $rooms->method('heartbeat')->willReturnCallback(function (Room $r, string $token, ?\Closure $mayAdd = null): void {
            $this->calls[] = ['heartbeat', [$token]];
            // Like PresenceMapper::touch: asked only for a token without a row.
            if (!$this->presenceRow && $mayAdd !== null) {
                $this->calls[] = ['presenceAdded', [$mayAdd()]];
            }
        });
        return $rooms;
    }

    private function stateService(): StateService {
        $state = $this->createMock(StateService::class);
        $state->method('publicState')->willReturnCallback(function (Room $r, ?string $token, bool $spectate = false): array {
            $this->calls[] = ['publicState', [$token, $spectate]];
            return ['token' => $token];
        });
        $state->method('stateVersion')->willReturn('v1');
        $state->method('selfVersion')->willReturn('s1');
        $state->method('publicSummary')->willReturnCallback(function (Room $r, ?string $token): array {
            $this->calls[] = ['publicSummary', [$token]];
            return [];
        });
        $state->method('imageVisible')->willReturnCallback(function (Room $r, Poll $p, ?string $token): bool {
            $this->calls[] = ['imageVisible', [$token]];
            return false;
        });
        return $state;
    }

    private function voteService(): VoteService {
        $votes = $this->createMock(VoteService::class);
        $votes->method('quizJoin')->willReturnCallback(function (Room $r, string $token, string $nickname, ?string $address = null): Player {
            $this->calls[] = ['quizJoin', [$token, $nickname]];
            $this->calls[] = ['quizJoinAddress', [$address]];
            if ($this->joinError !== null) {
                throw $this->joinError;
            }
            return new Player();
        });
        $votes->method('recordVote')->willReturnCallback(function (Room $r, string $token, mixed $value, bool $keyboard = false, ?int $pollId = null): void {
            $this->calls[] = ['recordVote', [$token, $value]];
        });
        return $votes;
    }

    private function deckService(): DeckService {
        $deck = $this->createMock(DeckService::class);
        $deck->method('requirePollInRoom')->willReturnCallback(fn (): Poll => new Poll());
        return $deck;
    }

    private function paceService(): PaceService {
        $pace = $this->createMock(PaceService::class);
        $pace->method('next')->willReturnCallback(function (Room $r, string $token, int $after): void {
            $this->calls[] = ['next', [$token, $after]];
        });
        return $pace;
    }

    private function codeGenerator(): CodeGenerator {
        $codes = $this->createMock(CodeGenerator::class);
        $codes->method('voterToken')->willReturnCallback(function (): string {
            $this->issued++;
            return self::FRESH;
        });
        return $codes;
    }

    private function timeFactory(): ITimeFactory {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        $time->method('getDateTime')->willReturnCallback(fn (): \DateTime => new \DateTime('@' . self::NOW));
        return $time;
    }

    private function l10n(): IL10N {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        return $l10n;
    }

    private function limiter(): ILimiter {
        $limiter = $this->createMock(ILimiter::class);
        $limiter->method('registerAnonRequest')->willReturnCallback(function (string $id, int $limit, int $period, string $ip): void {
            $this->calls[] = ['registerAnonRequest', [$id, $limit, $period, $ip]];
            if (in_array($id, $this->exhausted, true)) {
                throw new class extends \Exception implements IRateLimitExceededException {
                };
            }
        });
        return $limiter;
    }

    private function throttler(): IThrottler {
        $throttler = $this->createMock(IThrottler::class);
        $throttler->method('registerAttempt')->willReturnCallback(function (string $action, string $ip, array $metadata = []): void {
            $this->calls[] = ['registerAttempt', [$action, $ip]];
            if ($this->attempts !== null) {
                $this->attempts++;
            }
        });
        $throttler->method('sleepDelayOrThrowOnMax')->willReturnCallback(function (string $ip, string $action = ''): int {
            $this->calls[] = ['sleepDelayOrThrowOnMax', [$ip, $action]];
            if ($this->attempts !== null ? $this->attempts > 10 : $this->blocked) {
                throw new MaxDelayReached('Reached maximum delay');
            }
            return 0;
        });
        return $throttler;
    }

    private function limits(): Limits {
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueInt')->willReturnCallback(
            fn (string $app, string $key, int $default = 0): int => $this->limitConfig[$key] ?? $default
        );
        return new Limits($config);
    }

    private function urlGenerator(): IURLGenerator {
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('getWebroot')->willReturnCallback(fn (): string => $this->webroot);
        return $urls;
    }

    private function appConfig(): IAppConfig {
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueInt')->willReturnCallback(function (string $app, string $key): int {
            if ($this->configError !== null) {
                throw $this->configError;
            }
            return $app === 'pulse' && $key === PublicVoteController::HOST_COOKIE_SINCE ? $this->hostSince : 0;
        });
        $config->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
            $this->calls[] = ['setValueInt', [$app, $key, $value]];
            $this->hostSince = $value;
            return true;
        });
        return $config;
    }
}
