<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\BackgroundJob\CleanupStaleRoomsJob;
use OCA\Pulse\Migration\Version000000Date20260929120000;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * One grace period on update (Version000000Date20260929120000): owner
 * activity counts for retention in every room mode since this version, but
 * existing moderated rooms have `touched_at` = 0. Where the cleanup job has
 * never run, its first run comes minutes after the update and would delete
 * a deck its owner edited the day before — there, rooms without owner
 * activity count as used on the day of the update. Where the job already
 * ran, the old rule has been deciding all along, and nothing is written.
 */
#[CoversClass(Version000000Date20260929120000::class)]
class RetentionGraceMigrationTest extends TestCase {

    private const NOW = 1_800_000_000;

    /** @var list<string> query builder calls, in order */
    private array $sql = [];
    /** @var list<string> IOutput::info lines */
    private array $info = [];

    public function testWithoutJobRoomsWithoutOwnerActivityGetAGracePeriod(): void {
        // Installed fresh on an earlier version: the job was never registered.
        $this->migrate([], changed: 4);

        $this->assertSame([
            'update(pulse_rooms)',
            'set(touched_at, ' . self::NOW . ' int)',
            'where(touched_at = 0 int)',
            'executeStatement',
        ], $this->sql);
        $this->assertCount(1, $this->info);
        $this->assertStringContainsString('4 existing rooms', $this->info[0]);
    }

    public function testJobRegisteredButNeverRun(): void {
        // Registered but never run (for example no cron): the first run is
        // still ahead, so the grace period applies as well.
        $this->migrate([0], changed: 1);

        $this->assertContains('executeStatement', $this->sql);
    }

    public function testJobAlreadyRanChangesNothing(): void {
        // Production-like: the job runs daily. Moderated rooms keep being
        // judged by the old rule until their owner opens them again.
        $this->migrate([self::NOW - 3600], changed: 0);

        $this->assertSame([], $this->sql, 'no query at all');
        $this->assertSame([], $this->info);
    }

    public function testDuplicateEntryCountsIfOneRan(): void {
        $this->migrate([0, self::NOW - 86_400], changed: 0);

        $this->assertSame([], $this->sql);
    }

    public function testNoRoomAffectedNoMessage(): void {
        $this->migrate([], changed: 0);

        $this->assertContains('executeStatement', $this->sql);
        $this->assertSame([], $this->info);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Run postSchemaChange against a job list whose CleanupStaleRoomsJob
     * entries have the given last_run values.
     *
     * @param list<int> $lastRuns
     */
    private function migrate(array $lastRuns, int $changed): void {
        $jobs = array_map(function (int $lastRun): IJob {
            $job = $this->createMock(IJob::class);
            $job->method('getLastRun')->willReturn($lastRun);
            return $job;
        }, $lastRuns);
        $jobList = $this->createMock(IJobList::class);
        $jobList->method('getJobsIterator')
            ->with(CleanupStaleRoomsJob::class, null, 0)
            ->willReturn($jobs);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        $output = $this->createMock(IOutput::class);
        $output->method('info')->willReturnCallback(function (string $message): void {
            $this->info[] = $message;
        });

        $step = new Version000000Date20260929120000($this->db($changed), $jobList, $time);
        $step->postSchemaChange($output, static fn () => null, []);
    }

    /** Connection whose query builder writes its calls to $this->sql. */
    private function db(int $changed): IDBConnection {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(fn ($x, $y): string => "$x = $y");

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnCallback(
            fn ($value, $type = IQueryBuilder::PARAM_STR): string => $value . ($type === IQueryBuilder::PARAM_INT ? ' int' : ' str'),
        );
        $qb->method('update')->willReturnCallback(function (string $table) use ($qb): IQueryBuilder {
            $this->sql[] = "update($table)";
            return $qb;
        });
        $qb->method('set')->willReturnCallback(function (string $key, string $value) use ($qb): IQueryBuilder {
            $this->sql[] = "set($key, $value)";
            return $qb;
        });
        $qb->method('where')->willReturnCallback(function (string $predicate) use ($qb): IQueryBuilder {
            $this->sql[] = "where($predicate)";
            return $qb;
        });
        $qb->method('executeStatement')->willReturnCallback(function () use ($changed): int {
            $this->sql[] = 'executeStatement';
            return $changed;
        });

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        return $db;
    }
}
