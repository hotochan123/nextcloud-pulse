<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OC\Memcache\ArrayCache;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IMemcache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The vote change stamp (VoteMapper::changeStamp), task M3 of the security
 * review. Every phone and the projector ask for the version every few
 * seconds; the former stamp read every vote payload of the question for it
 * (60,000 votes: about 100 ms per 204). Now it is a random generation in the
 * shared cache that every write drops.
 *
 * What must hold, because a stale stamp means a 204 that hides new votes:
 * - no vote is read while nothing changes;
 * - every write path changes it, and it never returns to a value handed
 *   out before (not after an eviction either);
 * - a write inside a transaction keeps the exact stamp in charge until the
 *   marker expires — only the commit makes it visible;
 * - without a shared cache (NullCache) or with a broken one, the exact stamp.
 */
#[CoversClass(VoteMapper::class)]
class VoteStampTest extends TestCase {

    private ArrayCache $cache;
    private bool $cacheAvailable = true;
    private bool $inTransaction = false;
    /** @var array<int, list<array{id:int, payload:string}>> the "table" per question */
    private array $table = [7 => [['id' => 1, 'payload' => '{"value":"AA"}']], 8 => []];
    /** Number of vote reads (contentStamp). */
    private int $reads = 0;
    /** Written statements: what the cache looked like at that moment. */
    private array $statements = [];
    private VoteMapper $mapper;

    protected function setUp(): void {
        $this->cache = new ArrayCache('test');
        $this->mapper = $this->mapper(fn () => $this->cache);
    }

    public function testWithoutWritesStableAndWithoutReadingVotes(): void {
        $a = $this->mapper->changeStamp(7);
        $b = $this->mapper->changeStamp(7);

        $this->assertSame($a, $b, 'unchanged -> 204 stays possible');
        $this->assertStringStartsWith('g', $a);
        $this->assertSame(0, $this->reads, 'no vote read');
    }

    public function testEveryWritePathChangesTheStamp(): void {
        $seen = [$this->mapper->changeStamp(7)];
        $writes = [
            'insert' => fn () => $this->mapper->insert($this->vote(null)),
            'update' => fn () => $this->mapper->update($this->vote(3)),
            'insertOrUpdate' => fn () => $this->mapper->insertOrUpdate($this->vote(null)),
            'delete' => fn () => $this->mapper->delete($this->vote(3)),
            'deleteByPoll' => fn () => $this->mapper->deleteByPoll(7),
            'deleteByPollsAndToken' => fn () => $this->mapper->deleteByPollsAndToken([8, 7], 'tok'),
            'deleteDemoByPoll' => fn () => $this->mapper->deleteDemoByPoll(7),
        ];
        foreach ($writes as $name => $write) {
            $write();
            $stamp = $this->mapper->changeStamp(7);
            $this->assertNotContains($stamp, $seen, $name . ': new, never seen stamp');
            $seen[] = $stamp;
            $this->assertSame($stamp, $this->mapper->changeStamp(7), $name . ': stable again afterwards');
        }
        $this->assertSame(0, $this->reads);
    }

    public function testOtherQuestionStaysUntouched(): void {
        $seven = $this->mapper->changeStamp(7);
        $eight = $this->mapper->changeStamp(8);

        $this->mapper->insert($this->vote(null, pollId: 8));

        $this->assertSame($seven, $this->mapper->changeStamp(7));
        $this->assertNotSame($eight, $this->mapper->changeStamp(8));
    }

    public function testEvictedEntryGivesANewNeverSeenValue(): void {
        // Eviction or expiry: every client refetches once — none keeps an old
        // value that could come back (a counter restarting at 0 could).
        $seen = [];
        for ($i = 0; $i < 20; $i++) {
            $seen[] = $this->mapper->changeStamp(7);
            $this->cache->clear();
        }

        $this->assertCount(20, array_unique($seen));
    }

    public function testTwoWorkersShareTheStamp(): void {
        // A second request (another PHP worker) with its own mapper, same cache.
        $other = $this->mapper(fn () => $this->cache);

        $a = $this->mapper->changeStamp(7);
        $this->assertSame($a, $other->changeStamp(7));

        $other->insert($this->vote(null));

        $this->assertNotSame($a, $this->mapper->changeStamp(7), 'the writer in the other worker invalidates it');
    }

    public function testParallelFirstPollAgreesOnOneValue(): void {
        // Between our get and our add another worker seeded its generation:
        // add fails, its value counts.
        $cache = $this->createMock(IMemcache::class);
        $cache->method('get')->willReturnOnConsecutiveCalls(null, 'fremd', null);
        $cache->method('add')->willReturn(false);

        $this->assertSame('gfremd', $this->mapper(fn () => $cache)->changeStamp(7));
    }

    public function testWriteInTransactionKeepsTheExactStampUntilExpiry(): void {
        $before = $this->mapper->changeStamp(7);

        $this->inTransaction = true;
        $this->mapper->deleteByPollsAndToken([7], 'tok');
        $this->inTransaction = false;

        $this->assertNotNull($this->statements[0]['tx'], 'marker is set BEFORE the statement');
        $this->assertNull($this->cache->get('gen:7'), 'generation gone afterwards');

        // Uncommitted: the exact stamp reads what is visible. After the
        // "commit" (table changes), it changes.
        $during = $this->mapper->changeStamp(7);
        $this->assertStringStartsWith('c', $during);
        $this->assertNotSame($before, $during);
        $this->table[7] = [];
        $committed = $this->mapper->changeStamp(7);
        $this->assertNotSame($during, $committed, 'the commit becomes visible');

        // Marker expired (TTL): a generation again — one no client has seen.
        $this->cache->remove('tx:7');
        $after = $this->mapper->changeStamp(7);
        $this->assertStringStartsWith('g', $after);
        $this->assertNotContains($after, [$before, $during, $committed]);
    }

    public function testWithoutTransactionNoMarker(): void {
        $this->mapper->changeStamp(7);
        $this->mapper->insert($this->vote(null));

        $this->assertNull($this->statements[0]['tx']);
        $this->assertStringStartsWith('g', $this->mapper->changeStamp(7));
    }

    public function testWithoutSharedCacheExactStamp(): void {
        $this->cacheAvailable = false;
        $mapper = $this->mapper(fn () => $this->cache);

        $a = $mapper->changeStamp(7);
        $this->assertStringStartsWith('c', $a);
        $this->assertSame($a, $mapper->changeStamp(7));

        // Upsert: same count, different payload.
        $this->table[7][0]['payload'] = '{"value":"BB"}';
        $this->assertNotSame($a, $mapper->changeStamp(7));
        $this->assertSame(3, $this->reads);
    }

    public function testBrokenCacheExactStampAndWritingStillWorks(): void {
        $cache = $this->createMock(IMemcache::class);
        $cache->method('get')->willThrowException(new \RuntimeException('Redis gone'));
        $cache->method('remove')->willThrowException(new \RuntimeException('Redis gone'));
        $cache->method('set')->willThrowException(new \RuntimeException('Redis gone'));
        $mapper = $this->mapper(fn () => $cache);

        $this->assertStringStartsWith('c', $mapper->changeStamp(7));
        $this->inTransaction = true;
        $mapper->insert($this->vote(null));
        $this->assertCount(1, $this->statements, 'the vote is stored anyway');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** @param \Closure(): IMemcache $cache */
    private function mapper(\Closure $cache): VoteMapper {
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('isAvailable')->willReturnCallback(fn (): bool => $this->cacheAvailable);
        $factory->method('createDistributed')->willReturnCallback($cache);

        $db = $this->createMock(IDBConnection::class);
        $db->method('inTransaction')->willReturnCallback(fn (): bool => $this->inTransaction);
        $db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());

        return new VoteMapper($db, $factory);
    }

    private function queryBuilder(): IQueryBuilder {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('eq');
        $expr->method('in')->willReturn('in');
        $expr->method('like')->willReturn('like');

        $pollId = 0;
        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'insert', 'update', 'delete', 'setValue', 'set'] as $fluent) {
            $qb->method($fluent)->willReturnSelf();
        }
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnCallback(function (mixed $value) use (&$pollId): string {
            if (is_int($value)) {
                $pollId = $value;
            }
            return ':p';
        });
        $qb->method('executeStatement')->willReturnCallback(function (): int {
            $this->statements[] = ['tx' => $this->cache->get('tx:7')];
            return 1;
        });
        $qb->method('getLastInsertId')->willReturn(99);
        $qb->method('executeQuery')->willReturnCallback(function () use (&$pollId): IResult {
            $this->reads++;
            $rows = $this->table[$pollId] ?? [];
            $result = $this->createMock(IResult::class);
            $result->method('fetch')->willReturnCallback(static function () use (&$rows): array|false {
                return array_shift($rows) ?? false;
            });
            return $result;
        });
        return $qb;
    }

    private function vote(?int $id, int $pollId = 7): Vote {
        $vote = new Vote();
        if ($id !== null) {
            $vote->setId($id);
        }
        $vote->setPollId($pollId);
        $vote->setVoterToken('tok');
        $vote->setPayload('{"value":"AA"}');
        return $vote;
    }
}
