<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Player;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Progress;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Service\JoinLimitException;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Self-paced caps that throwaway joins can no longer turn against the class.
 *
 * Before, every joined token counted toward the cap of 300 and every join
 * REQUEST toward the budget per address: three addresses filled the quiz in
 * seconds, and one person behind a school NAT used up the budget of the whole
 * class. Now
 * - PaceService::MAX_PLAYERS counts players who have started (a progress row);
 * - at most PaceService::MAX_JOINED are joined at once, and at that ceiling,
 *   in the open window, players who joined STALE_JOIN seconds ago and never
 *   started make room (not in the draft: nobody can have started there);
 * - only successful NEW players count against the address
 *   (PaceService::NEW_PLAYER_LIMIT per JOIN_PERIOD), as the last check before
 *   registering — taken names, retries and known players cost nothing.
 */
#[CoversClass(VoteService::class)]
class SelfJoinCapTest extends TestCase {

    private const NOW = 1_800_000_000;
    private const IP = '203.0.113.7';

    private VoteService $service;
    private PlayerMapper&MockObject $players;
    private ILimiter&MockObject $limiter;
    private PaceService&MockObject $pace;
    private Room $locked;
    /** @var Player[] */
    private array $roster = [];
    /** @var list<string> tokens with a progress row */
    private array $started = [];
    /** @var list<string> */
    private array $calls = [];
    /** App config an admin set (Limits keys): key => value. */
    private array $limitConfig = [];

    protected function setUp(): void {
        $this->locked = $this->room(self::NOW - 3600);

        $this->pace = $this->createMock(PaceService::class);
        $this->pace->method('locked')->willReturnCallback(fn (Room $room, callable $fn): mixed => $fn($this->locked));
        $this->pace->method('forgetToken')->willReturnCallback(function (Room $r, string $token): void {
            $this->calls[] = 'forget:' . $token;
        });

        $this->players = $this->createMock(PlayerMapper::class);
        $this->players->method('findByRoom')->willReturnCallback(fn (): array => $this->roster);
        $this->players->method('delete')->willReturnCallback(function (Player $p): Player {
            $this->calls[] = 'evict:' . $p->getVoterToken();
            return $p;
        });

        $progress = $this->createMock(ProgressMapper::class);
        $progress->method('findByRoom')->willReturnCallback(fn (): array => array_map(
            fn (string $t): Progress => $this->progressRow($t),
            $this->started,
        ));
        $progress->method('findByRoomAndToken')->willReturnCallback(
            fn (int $roomId, string $t): array => in_array($t, $this->started, true) ? [$this->progressRow($t)] : [],
        );

        $this->limiter = $this->createMock(ILimiter::class);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        foreach ([
            'playerMapper' => $this->players,
            'progressMapper' => $progress,
            'paceService' => $this->pace,
            'limits' => $this->limits(),
            'limiter' => $this->limiter,
            'cacheFactory' => $this->createMock(ICacheFactory::class),
            'timeFactory' => $time,
            'l10n' => $l10n,
        ] as $name => $value) {
            (new ReflectionProperty(VoteService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── MAX_PLAYERS counts who has started ─────────────────────────────────

    public function testNieGestarteteFuellenDasQuizNichtMehr(): void {
        $this->roster = $this->players(PaceService::MAX_PLAYERS, self::NOW - 60);
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');
    }

    public function testDreihundertGestarteteSindVoll(): void {
        $this->roster = $this->players(PaceService::MAX_PLAYERS, self::NOW - 60);
        $this->started = array_map(static fn (Player $p): string => $p->getVoterToken(), $this->roster);

        $this->assertRejected('This quiz is full.', 'tok-neu', 'Cem');
    }

    public function testNurGestarteteSpielerZaehlen(): void {
        // Progress rows of a removed token (no player any more) do not count.
        $this->roster = $this->players(PaceService::MAX_PLAYERS, self::NOW - 60);
        $this->started = array_map(static fn (Player $p): string => $p->getVoterToken(), array_slice($this->roster, 1));
        $this->started[] = 'tok-weg';
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');
    }

    public function testBekannteSpielerKommenAuchVollRein(): void {
        $this->roster = $this->players(PaceService::MAX_PLAYERS, self::NOW - 60);
        $this->started = array_map(static fn (Player $p): string => $p->getVoterToken(), $this->roster);
        $this->limiter->expects($this->never())->method('registerAnonRequest');
        $this->expectRegister('tok-7', 'P7');

        $this->join('tok-7', 'P7');
    }

    // ── Ceiling MAX_JOINED ─────────────────────────────────────────────────

    public function testDeckeImEntwurfOhneRaeumen(): void {
        $this->locked = $this->room(0);
        $this->roster = $this->players(PaceService::MAX_JOINED, self::NOW - 7200);
        $this->players->expects($this->never())->method('delete');

        $this->assertRejected('This quiz is full.', 'tok-neu', 'Cem');
    }

    public function testDeckeImOffenenFensterRaeumtAlteNieGestartete(): void {
        $this->roster = array_merge(
            $this->players(PaceService::MAX_JOINED - 2, self::NOW - PaceService::STALE_JOIN - 1, 'alt'),
            $this->players(2, self::NOW - 10, 'frisch'),
        );
        $this->started = ['tok-alt1'];
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');

        // Every stale player who never started, none who started, none who just joined.
        $this->assertCount(PaceService::MAX_JOINED - 3, array_filter($this->calls, static fn (string $c): bool => str_starts_with($c, 'evict:')));
        $this->assertNotContains('evict:tok-alt1', $this->calls);
        $this->assertNotContains('evict:tok-frisch1', $this->calls);
        $this->assertContains('evict:tok-alt2', $this->calls);
    }

    public function testGenauAmStichtagWirdGeraeumt(): void {
        $this->roster = $this->players(PaceService::MAX_JOINED, self::NOW - PaceService::STALE_JOIN, 'alt');
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');

        $this->assertContains('evict:tok-alt1', $this->calls);
    }

    public function testNurFrischeNieGestarteteBleibtVoll(): void {
        $this->roster = $this->players(PaceService::MAX_JOINED, self::NOW - PaceService::STALE_JOIN + 1);

        $this->assertRejected('This quiz is full.', 'tok-neu', 'Cem');
        $this->assertSame([], $this->calls);
    }

    public function testGeraeumterNameIstWiederFrei(): void {
        $this->roster = $this->players(PaceService::MAX_JOINED, self::NOW - 7200);
        $this->expectRegister('tok-neu', 'P5');

        $this->join('tok-neu', 'P5');
    }

    public function testUnterDreihundertKeinBlickInDenFortschritt(): void {
        $progress = $this->createMock(ProgressMapper::class);
        $progress->expects($this->never())->method('findByRoom');
        (new ReflectionProperty(VoteService::class, 'progressMapper'))->setValue($this->service, $progress);
        $this->roster = $this->players(PaceService::MAX_PLAYERS - 1, self::NOW - 60);
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');
    }

    public function testDeckeFolgtDerAppConfig(): void {
        // occ config:app:set pulse max_players_per_room --value 100: in the
        // draft the ceiling alone decides, below MAX_PLAYERS as well.
        $this->limitConfig[\OCA\Pulse\Service\Limits::PLAYERS_PER_ROOM] = 100;
        $this->locked = $this->room(0);
        $this->roster = $this->players(100, self::NOW - 60);

        $this->assertRejected('This quiz is full.', 'tok-neu', 'Cem');
    }

    public function testNiedrigeDeckeRaeumtImOffenenFenster(): void {
        $this->limitConfig[\OCA\Pulse\Service\Limits::PLAYERS_PER_ROOM] = 100;
        $this->roster = $this->players(100, self::NOW - PaceService::STALE_JOIN - 1, 'alt');
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');

        $this->assertCount(100, array_filter($this->calls, static fn (string $c): bool => str_starts_with($c, 'evict:')));
    }

    public function testHoehereDeckeRaeumtBeiSechshundertNochNicht(): void {
        $this->limitConfig[\OCA\Pulse\Service\Limits::PLAYERS_PER_ROOM] = 1000;
        $this->roster = $this->players(PaceService::MAX_JOINED, self::NOW - 7200);
        $this->players->expects($this->never())->method('delete');
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');
    }

    // ── New players per address ────────────────────────────────────────────

    public function testNeueSpielerProAdresseFolgenDerAppConfig(): void {
        $this->limitConfig[\OCA\Pulse\Service\Limits::NEW_PLAYERS_PER_ADDRESS] = 7;
        $this->limiter->expects($this->once())->method('registerAnonRequest')
            ->with('pulse-player-5', 7, PaceService::JOIN_PERIOD, self::IP);
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem', self::IP);
    }

    public function testStandardSindSovieleNeueWieFrueherAnfragen(): void {
        // The old flood guard allowed 120 join requests per address and room;
        // as many new names get in now, retries free on top.
        $this->assertSame(120, PaceService::NEW_PLAYER_LIMIT);
    }

    public function testNeuerSpielerZaehltGegenSeineAdresse(): void {
        $this->limiter->expects($this->once())->method('registerAnonRequest')
            ->with('pulse-player-5', PaceService::NEW_PLAYER_LIMIT, PaceService::JOIN_PERIOD, self::IP)
            ->willReturnCallback(function (): void {
                $this->calls[] = 'count';
            });
        $this->players->expects($this->once())->method('register')
            ->willReturnCallback(function () {
                $this->calls[] = 'register';
                return $this->player('tok-neu', 'Cem', self::NOW);
            });

        $this->join('tok-neu', 'Cem', self::IP);

        $this->assertSame(['count', 'forget:tok-neu', 'register'], $this->calls);
    }

    public function testErschoepfteAdresseBekommtJoinLimit(): void {
        $this->limiter->method('registerAnonRequest')
            ->willThrowException(new class('') extends \Exception implements IRateLimitExceededException {
            });
        $this->players->expects($this->never())->method('register');
        $this->pace->expects($this->never())->method('forgetToken');

        try {
            $this->join('tok-neu', 'Cem', self::IP);
            $this->fail('JoinLimitException erwartet');
        } catch (JoinLimitException $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e, 'old callers still answer 400');
            $this->assertSame('Too many attempts. Please wait a moment.', $e->getMessage());
        }
    }

    public function testVergebenerNameKostetNichts(): void {
        $this->roster = [$this->player('tok-anna', 'Anna', self::NOW - 60)];
        $this->limiter->expects($this->never())->method('registerAnonRequest');

        $this->assertRejected('This name is already taken. Please choose another one.', 'tok-neu', 'anna', self::IP);
    }

    public function testBekanntesTokenKostetNichts(): void {
        $this->roster = [$this->player('tok-anna', 'Anna', self::NOW - 60)];
        $this->limiter->expects($this->never())->method('registerAnonRequest');
        $this->expectRegister('tok-anna', 'Anna');

        $this->join('tok-anna', 'Anna', self::IP);
    }

    public function testGesperrtKostetNichts(): void {
        $this->limiter->expects($this->never())->method('registerAnonRequest');
        $this->locked->setJoinsLocked(true);

        $this->assertRejected('Joining is closed for this quiz.', 'tok-neu', 'Cem', self::IP);
    }

    public function testVollKostetNichts(): void {
        $this->limiter->expects($this->never())->method('registerAnonRequest');
        $this->roster = $this->players(PaceService::MAX_PLAYERS, self::NOW - 60);
        $this->started = array_map(static fn (Player $p): string => $p->getVoterToken(), $this->roster);

        $this->assertRejected('This quiz is full.', 'tok-neu', 'Cem', self::IP);
    }

    public function testOhneAdresseKeinZaehler(): void {
        // Callers that do not pass the address (older controller): no count.
        $this->limiter->expects($this->never())->method('registerAnonRequest');
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function join(string $token, string $nickname, ?string $ip = null): Player {
        return $this->service->quizJoin($this->room(self::NOW - 3600), $token, $nickname, $ip);
    }

    private function assertRejected(string $message, string $token, string $nickname, ?string $ip = null): void {
        $this->players->expects($this->never())->method('register');
        try {
            $this->join($token, $nickname, $ip);
            $this->fail('InvalidArgumentException erwartet: ' . $message);
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function expectRegister(string $token, string $nickname): void {
        $this->players->expects($this->once())->method('register')
            ->with(5, $token, $nickname, self::NOW)
            ->willReturn($this->player($token, $nickname, self::NOW));
    }

    private function room(int $openedAt): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('AB12CD');
        $room->setMode('quiz');
        $room->setPace('self');
        $room->setOpenedAt($openedAt);
        if ($openedAt > 0) {
            $room->setDeckOrder('[11,12]');
        }
        return $room;
    }

    /** @return Player[] tok-{prefix}1 … named {P or prefix}1 … */
    private function players(int $n, int $createdAt, string $prefix = ''): array {
        return array_map(
            fn (int $i): Player => $this->player('tok-' . $prefix . $i, ($prefix === '' ? 'P' : $prefix) . $i, $createdAt),
            range(1, $n),
        );
    }

    private function player(string $token, string $nickname, int $createdAt): Player {
        $player = new Player();
        $player->setRoomId(5);
        $player->setVoterToken($token);
        $player->setNickname($nickname);
        $player->setCreatedAt($createdAt);
        return $player;
    }

    private function progressRow(string $token): Progress {
        $row = new Progress();
        $row->setRoomId(5);
        $row->setPollId(11);
        $row->setVoterToken($token);
        $row->setStartedAt(self::NOW - 10);
        return $row;
    }

    private function limits(): \OCA\Pulse\Service\Limits {
        $config = $this->createMock(\OCP\IAppConfig::class);
        $config->method('getValueInt')->willReturnCallback(
            fn (string $app, string $key, int $default = 0): int => $this->limitConfig[$key] ?? $default
        );
        return new \OCA\Pulse\Service\Limits($config);
    }
}
