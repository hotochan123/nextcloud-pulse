<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;

/**
 * In-memory stand-ins for the two stores the owner-side storage code talks
 * to directly (security review, task D): the `pulse_rooms` / `pulse_polls`
 * rows it queries with the query builder, and the AppData image folder.
 *
 * The query-builder fake understands exactly the shapes that
 * PollImageService and RoomService build — select/selectDistinct, one
 * inner join, update … set, and eq / isNull / isNotNull / orX conditions —
 * and evaluates them against the arrays below, so a compare-and-set really
 * compares. Anything else fails loudly instead of answering wrongly.
 */
trait OwnerStorageFakes {
    /** @var array<int, string> room id => owner uid */
    private array $fakeRooms = [];
    /** @var array<int, array{room_id:int, image:?string}> poll id => row */
    private array $fakePolls = [];
    /** @var array<string, array{content:string, mtime:int}> stored name => file */
    private array $fakeFiles = [];
    private bool $fakeFolderExists = true;
    /** Called right before an UPDATE is evaluated — a "concurrent" writer. */
    private ?\Closure $beforeUpdate = null;
    /** @var list<string> executed statements, e.g. "update pulse_polls" */
    private array $fakeLog = [];

    private function fakeDb(): IDBConnection {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->fakeQueryBuilder());
        return $db;
    }

    private function fakeAppData(int $now = 0): IAppDataFactory {
        $folder = $this->fakeFolder($now);
        $appData = $this->createMock(IAppData::class);
        $appData->method('getFolder')->willReturnCallback(function (string $name) use ($folder): ISimpleFolder {
            if ($name !== 'polls' || !$this->fakeFolderExists) {
                throw new NotFoundException($name);
            }
            return $folder;
        });
        $appData->method('newFolder')->willReturnCallback(function (string $name) use ($folder): ISimpleFolder {
            $this->fakeFolderExists = true;
            return $folder;
        });
        $factory = $this->createMock(IAppDataFactory::class);
        $factory->method('get')->with('pulse')->willReturn($appData);
        return $factory;
    }

    // ── AppData ─────────────────────────────────────────────────────────────

    private function fakeFolder(int $now): ISimpleFolder {
        $files = &$this->fakeFiles;
        $fileOf = static function (string $name) use (&$files): ISimpleFile {
            return new class($name, $files) implements ISimpleFile {
                public function __construct(private string $name, private array &$files) {
                }
                public function getName(): string {
                    return $this->name;
                }
                public function getSize(): int|float {
                    return strlen($this->files[$this->name]['content'] ?? '');
                }
                public function getETag(): string {
                    return md5($this->files[$this->name]['content'] ?? '');
                }
                public function getMTime(): int {
                    return $this->files[$this->name]['mtime'] ?? 0;
                }
                public function getContent(): string {
                    if (!isset($this->files[$this->name])) {
                        throw new NotFoundException($this->name);
                    }
                    return $this->files[$this->name]['content'];
                }
                public function putContent($data): void {
                    $this->files[$this->name]['content'] = (string)$data;
                }
                public function delete(): void {
                    if (!isset($this->files[$this->name])) {
                        throw new NotFoundException($this->name);
                    }
                    unset($this->files[$this->name]);
                }
                public function getMimeType(): string {
                    return 'application/octet-stream';
                }
                public function getExtension(): string {
                    return pathinfo($this->name, PATHINFO_EXTENSION);
                }
                public function read() {
                    return false;
                }
                public function write() {
                    return false;
                }
            };
        };
        return new class($files, $fileOf, $now) implements ISimpleFolder {
            public function __construct(private array &$files, private \Closure $fileOf, private int $now) {
            }
            public function getDirectoryListing(): array {
                return array_map($this->fileOf, array_keys($this->files));
            }
            public function fileExists(string $name): bool {
                return isset($this->files[$name]);
            }
            public function getFile(string $name): ISimpleFile {
                if (!isset($this->files[$name])) {
                    throw new NotFoundException($name);
                }
                return ($this->fileOf)($name);
            }
            public function newFile(string $name, $content = null): ISimpleFile {
                $this->files[$name] = ['content' => (string)$content, 'mtime' => $this->now];
                return ($this->fileOf)($name);
            }
            public function delete(): void {
                $this->files = [];
            }
            public function getName(): string {
                return 'polls';
            }
            public function getFolder(string $name): ISimpleFolder {
                throw new NotFoundException($name);
            }
            public function newFolder(string $path): ISimpleFolder {
                throw new \LogicException('not needed');
            }
            // Part of ISimpleFolder from Nextcloud 35 on; an extra method is
            // fine on 34, a missing one is a fatal error on 35.
            public function getOrCreateFolder(string $path, int $maxRetries = 5): ISimpleFolder {
                throw new \LogicException('not needed');
            }
        };
    }

    // ── Query builder ───────────────────────────────────────────────────────

    private function fakeQueryBuilder(): IQueryBuilder {
        $q = new \ArrayObject([
            'kind' => null, 'table' => null, 'alias' => null, 'join' => null,
            'select' => [], 'distinct' => false, 'set' => [], 'where' => [], 'params' => [],
        ]);
        $composites = new \SplObjectStorage();

        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(static fn ($x, $y): string => "eq\x1f$x\x1f$y");
        $expr->method('isNull')->willReturnCallback(static fn ($x): string => "isnull\x1f$x");
        $expr->method('isNotNull')->willReturnCallback(static fn ($x): string => "notnull\x1f$x");
        $expr->method('orX')->willReturnCallback(function (...$parts) use ($composites): ICompositeExpression {
            $or = $this->createMock(ICompositeExpression::class);
            $composites[$or] = $parts;
            return $or;
        });

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnCallback(static function ($value) use ($q): string {
            $key = ':p' . count($q['params']);
            $q['params'] = $q['params'] + [$key => $value];
            return $key;
        });
        $qb->method('select')->willReturnCallback(static function (...$cols) use ($q, $qb) {
            $q['kind'] = 'select';
            $q['select'] = $cols;
            return $qb;
        });
        $qb->method('selectDistinct')->willReturnCallback(static function ($col) use ($q, $qb) {
            $q['kind'] = 'select';
            $q['select'] = [$col];
            $q['distinct'] = true;
            return $qb;
        });
        $qb->method('from')->willReturnCallback(static function ($table, $alias = null) use ($q, $qb) {
            $q['table'] = $table;
            $q['alias'] = $alias;
            return $qb;
        });
        $qb->method('innerJoin')->willReturnCallback(static function ($from, $table, $alias, $cond) use ($q, $qb) {
            $q['join'] = [$table, $alias, $cond];
            return $qb;
        });
        $qb->method('update')->willReturnCallback(static function ($table) use ($q, $qb) {
            $q['kind'] = 'update';
            $q['table'] = $table;
            return $qb;
        });
        $qb->method('set')->willReturnCallback(static function ($col, $value) use ($q, $qb) {
            $q['set'] = $q['set'] + [$col => $value];
            return $qb;
        });
        foreach (['where', 'andWhere'] as $method) {
            $qb->method($method)->willReturnCallback(static function (...$preds) use ($q, $qb) {
                $q['where'] = array_merge($q['where'], $preds);
                return $qb;
            });
        }
        $qb->method('executeStatement')->willReturnCallback(function () use ($q, $composites): int {
            if ($q['kind'] !== 'update' || $q['table'] !== 'pulse_polls') {
                throw new \LogicException('fake: unexpected statement');
            }
            if ($this->beforeUpdate !== null) {
                ($this->beforeUpdate)();
            }
            $this->fakeLog[] = 'update pulse_polls';
            $n = 0;
            foreach ($this->fakePolls as $id => $row) {
                if ($this->fakeMatches($this->fakePollRow($id), $q, $composites)) {
                    foreach ($q['set'] as $col => $param) {
                        $this->fakePolls[$id][$col] = $q['params'][$param];
                    }
                    $n++;
                }
            }
            return $n;
        });
        $qb->method('executeQuery')->willReturnCallback(function () use ($q, $composites): IResult {
            $rows = [];
            foreach ($this->fakeSourceRows($q) as $row) {
                if (!$this->fakeMatches($row, $q, $composites)) {
                    continue;
                }
                $out = [];
                foreach ($q['select'] as $col) {
                    $key = str_contains($col, '.') ? substr($col, strpos($col, '.') + 1) : $col;
                    $out[$key] = $row[$col];
                }
                $rows[] = $out;
            }
            if ($q['distinct']) {
                $rows = array_values(array_unique($rows, SORT_REGULAR));
            }
            return $this->fakeResult($rows);
        });
        return $qb;
    }

    /** @return list<array<string,mixed>> rows of the FROM (+ JOIN), keys plain and qualified */
    private function fakeSourceRows(\ArrayObject $q): array {
        $alias = $q['alias'];
        $rows = [];
        if ($q['table'] === 'pulse_rooms') {
            foreach ($this->fakeRooms as $id => $owner) {
                $rows[] = $this->fakeQualify(['id' => $id, 'owner_uid' => $owner], $alias);
            }
            return $rows;
        }
        if ($q['table'] !== 'pulse_polls') {
            throw new \LogicException('fake: unknown table ' . $q['table']);
        }
        foreach (array_keys($this->fakePolls) as $id) {
            $row = $this->fakeQualify($this->fakePollRow($id), $alias);
            if ($q['join'] !== null) {
                [$table, $joinAlias] = $q['join'];
                if ($table !== 'pulse_rooms') {
                    throw new \LogicException('fake: unknown join');
                }
                $roomId = $row['room_id'];
                if (!isset($this->fakeRooms[$roomId])) {
                    continue; // inner join
                }
                $row += $this->fakeQualify(['id' => $roomId, 'owner_uid' => $this->fakeRooms[$roomId]], $joinAlias, false);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function fakePollRow(int $id): array {
        return ['id' => $id, 'room_id' => $this->fakePolls[$id]['room_id'], 'image' => $this->fakePolls[$id]['image']];
    }

    private function fakeQualify(array $row, ?string $alias, bool $plain = true): array {
        $out = $plain ? $row : [];
        if ($alias !== null) {
            foreach ($row as $k => $v) {
                $out[$alias . '.' . $k] = $v;
            }
        }
        return $out;
    }

    private function fakeMatches(array $row, \ArrayObject $q, \SplObjectStorage $composites): bool {
        foreach ($q['where'] as $pred) {
            if (!$this->fakeHolds($pred, $row, $q, $composites)) {
                return false;
            }
        }
        return true;
    }

    private function fakeHolds(mixed $pred, array $row, \ArrayObject $q, \SplObjectStorage $composites): bool {
        if ($pred instanceof ICompositeExpression) {
            foreach ($composites[$pred] as $part) {
                if ($this->fakeHolds($part, $row, $q, $composites)) {
                    return true;
                }
            }
            return false;
        }
        $bits = explode("\x1f", (string)$pred);
        $value = function (string $ref) use ($row, $q): mixed {
            if (str_starts_with($ref, ':p')) {
                return $q['params'][$ref];
            }
            if (!array_key_exists($ref, $row)) {
                throw new \LogicException('fake: unknown column ' . $ref);
            }
            return $row[$ref];
        };
        return match ($bits[0]) {
            // SQL: NULL never equals anything.
            'eq' => $value($bits[1]) !== null && (string)$value($bits[1]) === (string)$value($bits[2]),
            'isnull' => $value($bits[1]) === null,
            'notnull' => $value($bits[1]) !== null,
            default => throw new \LogicException('fake: unknown predicate ' . $bits[0]),
        };
    }

    private function fakeResult(array $rows): IResult {
        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturnCallback(static function () use (&$rows): array|false {
            return array_shift($rows) ?? false;
        });
        $result->method('fetchOne')->willReturnCallback(static function () use (&$rows): mixed {
            $row = array_shift($rows);
            return $row === null ? false : reset($row);
        });
        $result->method('closeCursor')->willReturn(true);
        return $result;
    }
}
