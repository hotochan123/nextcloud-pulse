<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The room lock (RoomMapper::lockForUpdate, used by PaceService::locked).
 *
 * On MySQL/MariaDB and PostgreSQL it is SELECT … FOR UPDATE. SQLite has no
 * FOR UPDATE, and a transaction there that starts with a plain read cannot
 * write any more once a parallel one committed first ("database is locked",
 * without waiting): a class joining a quiz at the same moment got 500s.
 * So on SQLite the lock starts with a no-op write of the room row, which
 * takes the database's write lock before anything is read.
 */
#[CoversClass(RoomMapper::class)]
class RoomLockTest extends TestCase {

    /** @var list<string> what the query builders did, in order */
    private array $log = [];
    private bool $roomExists = true;

    public function testSqliteWritesTheRowBeforeReadingIt(): void {
        $room = $this->mapper(IDBConnection::PLATFORM_SQLITE)->lockForUpdate(5);

        $this->assertSame(5, $room->getId());
        $this->assertSame([
            'update pulse_rooms', 'set id = id', 'where id', 'executeStatement',
            'select *', 'from pulse_rooms', 'where id', 'executeQuery',
        ], $this->log);
    }

    public static function lockingDatabases(): array {
        return [
            'PostgreSQL' => [IDBConnection::PLATFORM_POSTGRES],
            'MySQL' => [IDBConnection::PLATFORM_MYSQL],
            'MariaDB' => [IDBConnection::PLATFORM_MARIADB],
            'Oracle' => [IDBConnection::PLATFORM_ORACLE],
        ];
    }

    #[DataProvider('lockingDatabases')]
    public function testOtherDatabasesLockWithForUpdateAndWriteNothing(string $platform): void {
        $this->mapper($platform)->lockForUpdate(5);

        $this->assertSame(['select *', 'from pulse_rooms', 'where id', 'forUpdate', 'executeQuery'], $this->log);
    }

    public function testDeletedRoomIsStillNotFoundOnSqlite(): void {
        // The no-op write matches no row; the read then reports the room gone
        // (PaceService::locked turns that into RoomGoneException).
        $this->roomExists = false;

        $this->expectException(DoesNotExistException::class);
        $this->mapper(IDBConnection::PLATFORM_SQLITE)->lockForUpdate(5);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function mapper(string $platform): RoomMapper {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getDatabaseProvider')->willReturn($platform);
        $db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());
        return new RoomMapper($db);
    }

    private function queryBuilder(): IQueryBuilder {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(static fn (string $col): string => 'eq ' . $col);

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturn(':id');
        $qb->method('update')->willReturnCallback(function (string $table) use ($qb): IQueryBuilder {
            $this->log[] = 'update ' . $table;
            return $qb;
        });
        $qb->method('set')->willReturnCallback(function (string $col, string $value) use ($qb): IQueryBuilder {
            $this->log[] = 'set ' . $col . ' = ' . $value;
            return $qb;
        });
        $qb->method('select')->willReturnCallback(function (...$cols) use ($qb): IQueryBuilder {
            $this->log[] = 'select ' . implode(',', $cols);
            return $qb;
        });
        $qb->method('from')->willReturnCallback(function (string $table) use ($qb): IQueryBuilder {
            $this->log[] = 'from ' . $table;
            return $qb;
        });
        $qb->method('where')->willReturnCallback(function (string $pred) use ($qb): IQueryBuilder {
            $this->log[] = 'where ' . substr($pred, 3);
            return $qb;
        });
        $qb->method('forUpdate')->willReturnCallback(function () use ($qb): IQueryBuilder {
            $this->log[] = 'forUpdate';
            return $qb;
        });
        $qb->method('executeStatement')->willReturnCallback(function (): int {
            $this->log[] = 'executeStatement';
            return $this->roomExists ? 1 : 0;
        });
        $qb->method('executeQuery')->willReturnCallback(function (): IResult {
            $this->log[] = 'executeQuery';
            $rows = $this->roomExists ? [['id' => 5, 'code' => 'ABCDEF', 'owner_uid' => 'alice']] : [];
            $result = $this->createMock(IResult::class);
            $next = static function () use (&$rows): array|false {
                return array_shift($rows) ?? false;
            };
            // QBMapper::findEntity reads with fetch() up to Nextcloud 34 and
            // with fetchAssociative() from 35 on.
            $result->method('fetch')->willReturnCallback($next);
            $result->method('fetchAssociative')->willReturnCallback($next);
            return $result;
        });
        return $qb;
    }
}
