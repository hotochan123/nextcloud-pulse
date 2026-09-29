<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Player;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Progress;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\QuizService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Self-paced leaderboard (VoteService::selfLeaderboard).
 *
 * Only final votes count — otherwise an answer in the correction window
 * gives itself away through the score. "Final" includes "no longer
 * correctable": window closed or question left counts immediately. Without a
 * timer there is no time tie-break (the times include idle time). The whole
 * deck comes in ONE query; public callers read the raw rows from a
 * 2 s cache whose key carries the window fields.
 */
#[CoversClass(VoteService::class)]
class SelfLeaderboardTest extends TestCase {

    private const NOW = 1_800_000_000; // even: NOW and NOW+1 fall into the same 2 s bucket

    private VoteService $service;
    private VoteMapper&MockObject $votes;
    private ICacheFactory&MockObject $cacheFactory;
    private int $now = self::NOW;

    /** @var Vote[] */
    private array $deckVotes = [];
    /** @var Progress[] */
    private array $rows = [];
    /** @var Player[] */
    private array $players = [];
    /** @var list<list<int>> arguments of the findByPolls calls */
    private array $queries = [];
    /** @var array<string, mixed> contents of the local cache test double */
    private array $store = [];
    /** @var list<array{string, int}> set() calls (key, TTL) */
    private array $sets = [];

    protected function setUp(): void {
        $this->players = [$this->player('tok-anna', 'Anna'), $this->player('tok-ben', 'Ben'), $this->player('tok-cem', 'Cem')];

        $this->votes = $this->createMock(VoteMapper::class);
        $this->votes->method('findByPolls')->willReturnCallback(function (array $ids, ?string $token = null): array {
            $this->queries[] = $ids;
            return $this->deckVotes;
        });
        // The moderated path (one query per question) is never taken.
        $this->votes->expects($this->never())->method('findByPoll');

        $progress = $this->createMock(ProgressMapper::class);
        $progress->method('findByRoom')->willReturnCallback(fn (): array => $this->rows);

        $playerMapper = $this->createMock(PlayerMapper::class);
        $playerMapper->method('findByRoom')->willReturnCallback(fn (): array => $this->players);

        $polls = $this->createMock(PollMapper::class);
        $polls->expects($this->never())->method('findByRoom');

        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->store[$key] ?? null);
        $cache->method('set')->willReturnCallback(function (string $key, mixed $value, int $ttl): bool {
            $this->store[$key] = $value;
            $this->sets[] = [$key, $ttl];
            return true;
        });
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createLocal')->with('pulse')->willReturn($cache);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);

        $this->service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        foreach ([
            'pollMapper' => $polls,
            'voteMapper' => $this->votes,
            'playerMapper' => $playerMapper,
            'quizService' => new QuizService(),
            'timeFactory' => $time,
            'progressMapper' => $progress,
            'cacheFactory' => $this->cacheFactory,
        ] as $name => $value) {
            (new ReflectionProperty(VoteService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── Only final votes ───────────────────────────────────────────────────

    public function testStimmeImKorrekturfensterZaehltNochNicht(): void {
        $this->rows = [$this->row(11, 'tok-anna')];
        $this->deckVotes = [$this->vote(11, 'tok-anna', 900, true, 5, createdAt: self::NOW - 3)];

        $this->assertSame(0, $this->score('Anna'), 'in der Sekunde created+fw ist noch korrigierbar');

        $this->now = self::NOW + 1;
        $this->assertSame(900, $this->score('Anna'), 'danach endgültig');
    }

    public function testLaengeresFensterDerErstenAntwortGilt(): void {
        $this->rows = [$this->row(11, 'tok-anna')];
        $this->deckVotes = [$this->vote(11, 'tok-anna', 900, true, 5, createdAt: self::NOW - 5, fw: 6)];

        $this->assertSame(0, $this->score('Anna'));
    }

    public function testKorrigierteStimmeZaehltSofort(): void {
        $this->rows = [$this->row(11, 'tok-anna')];
        $this->deckVotes = [$this->vote(11, 'tok-anna', 900, true, 5, createdAt: self::NOW, fixed: true)];

        $this->assertSame(900, $this->score('Anna'));
    }

    public function testVerlasseneFrageZaehltSofort(): void {
        $this->rows = [$this->row(11, 'tok-anna', leftAt: self::NOW)];
        $this->deckVotes = [$this->vote(11, 'tok-anna', 900, true, 5, createdAt: self::NOW)];

        $this->assertSame(900, $this->score('Anna'), 'nach /next ist nichts mehr zu korrigieren');
    }

    public function testGeschlossenesFensterZaehltSofort(): void {
        // "Close" = final standings are in immediately, without waiting for fw.
        $this->rows = [$this->row(11, 'tok-anna')];
        $this->deckVotes = [$this->vote(11, 'tok-anna', 900, true, 5, createdAt: self::NOW)];
        $room = $this->room();
        $room->setClosedAt(self::NOW);
        $room->setReleasedAt(self::NOW);

        $this->assertSame(900, $this->scoreIn($room, 'Anna'));
    }

    public function testAbgelaufeneFristZaehltSofort(): void {
        $this->rows = [$this->row(11, 'tok-anna')];
        $this->deckVotes = [$this->vote(11, 'tok-anna', 900, true, 5, createdAt: self::NOW)];
        $room = $this->room();
        $room->setClosesAt(self::NOW);

        $this->assertSame(900, $this->scoreIn($room, 'Anna'));
    }

    public function testPunkteRichtigeUndGeteilterRangBeiNullPunkten(): void {
        $this->rows = [$this->row(11, 'tok-anna', leftAt: self::NOW), $this->row(12, 'tok-anna', leftAt: self::NOW), $this->row(11, 'tok-ben', leftAt: self::NOW)];
        $this->deckVotes = [
            $this->vote(11, 'tok-anna', 900, true, 5, createdAt: self::NOW - 20),
            $this->vote(12, 'tok-anna', 0, false, 3, createdAt: self::NOW - 10),
            $this->vote(11, 'tok-ben', 0, false, 2, createdAt: self::NOW - 20),
        ];

        $this->assertSame([
            ['rank' => 1, 'nickname' => 'Anna', 'score' => 900, 'correct' => 1, 'me' => false],
            ['rank' => 2, 'nickname' => 'Ben', 'score' => 0, 'correct' => 0, 'me' => true],
            ['rank' => 2, 'nickname' => 'Cem', 'score' => 0, 'correct' => 0, 'me' => false],
        ], $this->service->selfLeaderboard($this->room(), 'tok-ben'), 'keine Tokens, eigene Zeile markiert');
    }

    // ── Ties ───────────────────────────────────────────────────────────────

    public function testOhneTimerGleichePunkteGleicherRang(): void {
        $this->bothRight(annaTime: 5, benTime: 5000);
        $room = $this->room();
        $room->setTimed(false);

        $rows = $this->service->selfLeaderboard($room, null);

        $this->assertSame(['Anna' => 1, 'Ben' => 1], array_column(array_slice($rows, 0, 2), 'rank', 'nickname'));
    }

    public function testMitTimerEntscheidetDieZeit(): void {
        $this->bothRight(annaTime: 9, benTime: 4);

        $rows = $this->service->selfLeaderboard($this->room(), null);

        $this->assertSame([['Ben', 1], ['Anna', 2]], array_map(
            static fn (array $r): array => [$r['nickname'], $r['rank']],
            array_slice($rows, 0, 2),
        ));
    }

    // ── Queries, cut-off, cache ────────────────────────────────────────────

    public function testEineAbfrageFuersEingefroreneDeck(): void {
        $this->service->selfLeaderboard($this->room(), null);

        $this->assertSame([[11, 12, 13]], $this->queries);
    }

    public function testLimitSchneidetAb(): void {
        $this->players = array_map(fn (int $i): Player => $this->player('tok-' . $i, 'P' . $i), range(1, 10));

        $this->assertCount(8, $this->service->selfLeaderboard($this->room(), null, 8));
        $this->assertCount(10, $this->service->selfLeaderboard($this->room(), null));
    }

    public function testImSelbenEimerNurEinmalGerechnet(): void {
        $first = $this->service->selfLeaderboard($this->room(), 'tok-anna', 0, true);
        $this->now = self::NOW + 1;
        $second = $this->service->selfLeaderboard($this->room(), 'tok-ben', 0, true);

        $this->assertCount(1, $this->queries);
        $this->assertSame([['lb:5:' . (self::NOW - 100) . ':0:0:0:' . intdiv(self::NOW, 2), 3]], $this->sets);
        // The cache holds the raw rows; the me flag is added per viewer afterwards.
        $this->assertTrue($first[0]['me']);
        $this->assertTrue($second[1]['me']);
        $this->assertArrayNotHasKey('token', $second[0]);
    }

    public function testNaechsterEimerRechnetNeu(): void {
        $this->service->selfLeaderboard($this->room(), null, 0, true);
        $this->now = self::NOW + 2;
        $this->service->selfLeaderboard($this->room(), null, 0, true);

        $this->assertCount(2, $this->queries);
    }

    public function testFensterwechselGreiftSofort(): void {
        $this->service->selfLeaderboard($this->room(), null, 0, true);
        $closed = $this->room();
        $closed->setClosedAt(self::NOW);
        $this->service->selfLeaderboard($closed, null, 0, true);

        $this->assertCount(2, $this->queries, 'anderes closed_at, anderer Schlüssel');
    }

    public function testAblaufendeFristGreiftSofort(): void {
        // Without a DB change: in the second of closes_at the window is closed. The
        // effective close is part of the key, the final standings don't wait for a bucket.
        $room = $this->room();
        $room->setClosesAt(self::NOW + 1);
        $this->service->selfLeaderboard($room, null, 0, true);
        $this->now = self::NOW + 1;
        $this->service->selfLeaderboard($room, null, 0, true);

        $this->assertCount(2, $this->queries);
    }

    public function testUngecachtFasstDenCacheNieAn(): void {
        $this->cacheFactory->expects($this->never())->method('createLocal');

        $this->service->selfLeaderboard($this->room(), null);
        $this->service->selfLeaderboard($this->room(), null, 8);

        $this->assertCount(2, $this->queries);
    }

    public function testOhneLokalenCacheWirdJedesMalGerechnet(): void {
        // NullCache (no APCu): get always returns null.
        $null = $this->createMock(ICache::class);
        $null->method('get')->willReturn(null);
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createLocal')->willReturn($null);
        (new ReflectionProperty(VoteService::class, 'cacheFactory'))->setValue($this->service, $factory);

        $this->service->selfLeaderboard($this->room(), null, 0, true);
        $this->service->selfLeaderboard($this->room(), null, 0, true);

        $this->assertCount(2, $this->queries);
    }

    public function testImEntwurfKeineStimmen(): void {
        // Before opening there is no order (findByPolls([]) does not query).
        $room = $this->room();
        $room->setOpenedAt(0);
        $room->setDeckOrder(null);

        $rows = $this->service->selfLeaderboard($room, null);

        $this->assertSame([[]], $this->queries);
        $this->assertSame([0, 0, 0], array_column($rows, 'score'));
    }

    public function testLeaderboardForVerzweigtImEigenenTempo(): void {
        // The skipped question plays no role — there is no running question for everyone.
        $this->rows = [$this->row(11, 'tok-anna', leftAt: self::NOW)];
        $this->deckVotes = [$this->vote(11, 'tok-anna', 900, true, 5, createdAt: self::NOW - 20)];

        $rows = $this->service->leaderboardFor($this->room(), 'tok-anna', [11]);

        $this->assertSame(['rank' => 1, 'nickname' => 'Anna', 'score' => 900, 'correct' => 1, 'me' => true], $rows[0]);
        $this->assertSame([[11, 12, 13]], $this->queries);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function score(string $nickname): int {
        return $this->scoreIn($this->room(), $nickname);
    }

    private function scoreIn(Room $room, string $nickname): int {
        $rows = $this->service->selfLeaderboard($room, null);
        return array_column($rows, 'score', 'nickname')[$nickname];
    }

    /** Anna and Ben both correct with the same score, different times. */
    private function bothRight(int $annaTime, int $benTime): void {
        $this->rows = [$this->row(11, 'tok-anna', leftAt: self::NOW), $this->row(11, 'tok-ben', leftAt: self::NOW)];
        $this->deckVotes = [
            $this->vote(11, 'tok-anna', 1000, true, $annaTime, createdAt: self::NOW - 20),
            $this->vote(11, 'tok-ben', 1000, true, $benTime, createdAt: self::NOW - 20),
        ];
    }

    private function room(): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('AB12CD');
        $room->setMode('quiz');
        $room->setPace('self');
        $room->setOpenedAt(self::NOW - 100);
        $room->setDeckOrder('[11,12,13]');
        return $room;
    }

    private function row(int $pollId, string $token, int $leftAt = 0): Progress {
        $row = new Progress();
        $row->setRoomId(5);
        $row->setPollId($pollId);
        $row->setVoterToken($token);
        $row->setStartedAt(self::NOW - 30);
        $row->setLeftAt($leftAt);
        return $row;
    }

    private function vote(int $pollId, string $token, int $points, bool $correct, int $elapsed, int $createdAt, int $fw = 3, bool $fixed = false): Vote {
        $payload = ['value' => 'AA', 'points' => $points, 'correct' => $correct, 'elapsed' => $elapsed, 'limit' => 20, 'fw' => $fw];
        if ($fixed) {
            $payload['fixed'] = true;
        }
        $vote = new Vote();
        $vote->setPollId($pollId);
        $vote->setVoterToken($token);
        $vote->setPayload(json_encode($payload));
        $vote->setCreatedAt($createdAt);
        return $vote;
    }

    private function player(string $token, string $nickname): Player {
        $player = new Player();
        $player->setRoomId(5);
        $player->setVoterToken($token);
        $player->setNickname($nickname);
        return $player;
    }
}
