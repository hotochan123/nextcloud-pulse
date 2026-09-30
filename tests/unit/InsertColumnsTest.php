<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Schema\Schema;
use OCA\Pulse\Db\Player;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Presence;
use OCA\Pulse\Db\Progress;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Service\CodeGenerator;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\RoomService;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\ISchemaWrapper;
use OCP\IL10N;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Every INSERT carries every NOT NULL column MySQL has no default for.
 *
 * The schema is the app's own: every migration's changeSchema() is replayed
 * offline, and MySQL 8.4's DBAL platform decides which declared defaults
 * survive. Doctrine drops the default of TEXT/BLOB columns on MySQL (MariaDB,
 * PostgreSQL and SQLite keep it), so `pulse_polls.options`
 * (TEXT NOT NULL DEFAULT '[]') has none there. The generic entity setter skips
 * a value equal to the current one; a new word cloud, number or free-text
 * question, and a copied room containing one, left `options` out of the
 * INSERT, and strict MySQL refused it (error 1364).
 *
 * Two kinds of columns:
 *  - a declared default MySQL drops: a NEW entity already marks it (entity
 *    constructor), so no call site can forget it, and it writes exactly the
 *    declared default;
 *  - no default on any database: the insert paths set it from real values
 *    (checked here for addPoll with every question type and duplicateRoom).
 */
#[CoversClass(Poll::class)]
#[CoversClass(DeckService::class)]
#[CoversClass(RoomService::class)]
class InsertColumnsTest extends TestCase {

    /** Table -> entity class. Every table the migrations create must be listed. */
    private const ENTITIES = [
        'pulse_rooms' => Room::class,
        'pulse_polls' => Poll::class,
        'pulse_votes' => Vote::class,
        'pulse_presence' => Presence::class,
        'pulse_players' => Player::class,
        'pulse_progress' => Progress::class,
    ];

    private static ?Schema $schema = null;

    // ── Schema ──────────────────────────────────────────────────────────────

    public function testJedeTabelleHatEineEntity(): void {
        $tables = array_map(static fn ($t): string => $t->getName(), $this->schema()->getTables());
        sort($tables);
        $known = array_keys(self::ENTITIES);
        sort($known);
        $this->assertSame($known, $tables);
    }

    public function testMysqlVerwirftGenauDenDefaultVonOptions(): void {
        // The audit result, and a check that the replay is not vacuous.
        $this->assertSame(['pulse_polls' => ['options' => '[]']], $this->droppedDefaults());
    }

    // ── Entities ────────────────────────────────────────────────────────────

    public static function tables(): array {
        return array_map(static fn (string $table): array => [$table], array_combine(array_keys(self::ENTITIES), array_keys(self::ENTITIES)));
    }

    #[DataProvider('tables')]
    public function testNeueEntityMarkiertGenauDieSpaltenMitVerworfenemDefault(string $table): void {
        $class = self::ENTITIES[$table];
        /** @var Entity $entity */
        $entity = new $class();
        $dropped = $this->droppedDefaults()[$table] ?? [];
        $expected = array_map(static fn (string $column): string => $entity->columnToProperty($column), array_keys($dropped));
        // Exactly those: a column without any default stays unmarked, so a
        // forgotten setter still fails loudly instead of storing '' or 0.
        $this->assertSame($expected, array_keys($entity->getUpdatedFields()), "$table: columns every INSERT of a new entity carries");
        foreach ($dropped as $column => $default) {
            // The INSERT writes what the other databases would fill in.
            $this->assertSame($default, $entity->{'get' . ucfirst($entity->columnToProperty($column))}());
        }
    }

    public function testGeladeneUmfrageSchreibtBeimUpdateNurGeaendertes(): void {
        // fromRow() runs the constructor and then resets the marks: an UPDATE
        // of a loaded poll must not rewrite `options` (the edit path sets it
        // itself when the content changes).
        $poll = Poll::fromRow([
            'id' => '5', 'room_id' => '1', 'type' => 'words', 'question' => 'Q',
            'options' => '[]', 'max_words' => '3', 'status' => 'active', 'position' => '0',
            'correct_option' => '', 'answer_key' => null, 'image' => '', 'time_limit' => '0',
            'started_at' => '0', 'created_at' => '100',
        ]);
        $this->assertSame([], $poll->getUpdatedFields());

        $poll->setStatus('ended');
        $this->assertSame(['status' => true], $poll->getUpdatedFields());
    }

    // ── Insert paths ────────────────────────────────────────────────────────

    public static function pollTypes(): array {
        $pairs = [['left' => 'a', 'right' => '1'], ['left' => 'b', 'right' => '2']];
        $axis = ['title' => 'T', 'poleLow' => 'lo', 'poleHigh' => 'hi'];
        $aspects = [['label' => 'X'], ['label' => 'Y'], ['label' => 'Z']];
        return [
            'poll choice' => ['poll', ['type' => 'choice', 'options' => ['A', 'B']]],
            'poll words' => ['poll', ['type' => 'words', 'maxWords' => 3]],
            'poll scale' => ['poll', ['type' => 'scale', 'scaleMax' => 5]],
            'poll spectrum' => ['poll', ['type' => 'scale', 'scaleMode' => 'spectrum', 'aspects' => $aspects]],
            'poll compass' => ['poll', ['type' => 'scale', 'scaleMode' => 'compass', 'axisX' => $axis, 'axisY' => $axis]],
            'poll rank' => ['poll', ['type' => 'rank', 'options' => ['A', 'B', 'C']]],
            'poll match' => ['poll', ['type' => 'match', 'pairs' => $pairs]],
            'quiz choice' => ['quiz', ['type' => 'choice', 'options' => ['A', 'B'], 'correctIndex' => 0]],
            'quiz truefalse' => ['quiz', ['type' => 'truefalse', 'correctIndex' => 1]],
            'quiz multi' => ['quiz', ['type' => 'multi', 'options' => ['A', 'B', 'C'], 'correctIndexes' => [0, 2]]],
            'quiz number' => ['quiz', ['type' => 'number', 'target' => 42, 'tolerance' => 1]],
            'quiz text' => ['quiz', ['type' => 'text', 'answers' => ['Paris']]],
            'quiz rank' => ['quiz', ['type' => 'rank', 'options' => ['A', 'B', 'C']]],
            'quiz match' => ['quiz', ['type' => 'match', 'pairs' => $pairs]],
        ];
    }

    #[DataProvider('pollTypes')]
    public function testAddPollSchreibtJedeSpalteOhneMysqlDefault(string $mode, array $data): void {
        $inserted = [];
        $polls = $this->createMock(PollMapper::class);
        $polls->method('insert')->willReturnCallback(static function (Poll $p) use (&$inserted): Poll {
            $inserted[] = $p;
            return $p;
        });
        $service = $this->withDeps(DeckService::class, [
            'pollMapper' => $polls,
            'codeGenerator' => $this->codes(),
            'timeFactory' => $this->clock(),
            'l10n' => $this->l10n(),
            'limits' => new \OCA\Pulse\Service\Limits($this->createMock(\OCP\IAppConfig::class)),
        ]);

        $service->addPoll($this->room($mode), $data + ['question' => 'Q']);

        $this->assertCount(1, $inserted);
        $this->assertInsertCarries('pulse_polls', $inserted[0]);
    }

    public function testDuplicateRoomSchreibtJedeSpalteOhneMysqlDefault(): void {
        // Loaded the way the mapper loads them; two of them have options '[]'.
        $row = static fn (int $id, string $type, string $options, ?string $key): array => [
            'id' => (string)$id, 'room_id' => '1', 'type' => $type, 'question' => "Q$id",
            'options' => $options, 'max_words' => '3', 'status' => 'ended', 'position' => (string)($id - 1),
            'correct_option' => '', 'answer_key' => $key, 'image' => '', 'time_limit' => '30',
            'started_at' => '400', 'created_at' => '100',
        ];
        $source = [
            Poll::fromRow($row(1, 'number', '[]', '{"target":7,"tolerance":0}')),
            Poll::fromRow($row(2, 'text', '[]', '{"accepted":["paris"],"rejected":[]}')),
            Poll::fromRow($row(3, 'choice', '[{"id":"AAAA","label":"A"},{"id":"BBBB","label":"B"}]', null)),
        ];

        $rooms = $this->createMock(RoomMapper::class);
        $insertedRooms = [];
        $rooms->method('insert')->willReturnCallback(static function (Room $r) use (&$insertedRooms): Room {
            $r->setId(99);
            $insertedRooms[] = $r;
            return $r;
        });
        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->with(1)->willReturn($source);
        $insertedPolls = [];
        $polls->method('insert')->willReturnCallback(static function (Poll $p) use (&$insertedPolls): Poll {
            $insertedPolls[] = $p;
            return $p;
        });
        $service = $this->withDeps(RoomService::class, [
            'roomMapper' => $rooms,
            'pollMapper' => $polls,
            'codeGenerator' => $this->codes(),
            'imageService' => $this->createMock(PollImageService::class),
            'timeFactory' => $this->clock(),
            'l10n' => $this->l10n(),
            'limits' => new \OCA\Pulse\Service\Limits($this->createMock(\OCP\IAppConfig::class)),
        ]);

        $service->duplicateRoom($this->room('quiz'), 'alice');

        $this->assertCount(1, $insertedRooms);
        $this->assertInsertCarries('pulse_rooms', $insertedRooms[0]);
        $this->assertCount(3, $insertedPolls);
        foreach ($insertedPolls as $i => $copy) {
            $this->assertInsertCarries('pulse_polls', $copy);
            $this->assertSame(99, $copy->getRoomId());
            $this->assertSame($source[$i]->getOptions(), $copy->getOptions());
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * The entity about to be inserted names every NOT NULL column that MySQL
     * has no default for (declared default dropped, or none declared at all).
     */
    private function assertInsertCarries(string $table, Entity $entity): void {
        $missing = [];
        foreach ($this->mysqlWithoutDefault()[$table] as $column) {
            if (!array_key_exists($entity->columnToProperty($column), $entity->getUpdatedFields())) {
                $missing[] = $column;
            }
        }
        $this->assertSame([], $missing, "INSERT into $table leaves out columns MySQL has no default for");
    }

    /** @return array<string, array<string, mixed>> table => [column => declared default] */
    private function droppedDefaults(): array {
        $out = [];
        foreach ($this->notNullColumns() as $table => $columns) {
            foreach ($columns as $column) {
                if ($column->getDefault() !== null && !$this->mysqlHasDefault($column->toArray())) {
                    $out[$table][$column->getName()] = $column->getDefault();
                }
            }
        }
        return $out;
    }

    /** @return array<string, list<string>> table => NOT NULL columns without a MySQL default */
    private function mysqlWithoutDefault(): array {
        $out = [];
        foreach ($this->notNullColumns() as $table => $columns) {
            $out[$table] = [];
            foreach ($columns as $column) {
                if (!$this->mysqlHasDefault($column->toArray())) {
                    $out[$table][] = $column->getName();
                }
            }
        }
        return $out;
    }

    /** @return array<string, list<\Doctrine\DBAL\Schema\Column>> NOT NULL, not autoincrement */
    private function notNullColumns(): array {
        $out = [];
        foreach ($this->schema()->getTables() as $table) {
            $out[$table->getName()] = array_values(array_filter(
                $table->getColumns(),
                static fn ($c): bool => $c->getNotnull() && !$c->getAutoincrement(),
            ));
        }
        return $out;
    }

    /** Exactly the DBAL code that writes the DEFAULT clause of MySQL's CREATE TABLE. */
    private function mysqlHasDefault(array $column): bool {
        return (new MySQL84Platform())->getDefaultValueDeclarationSQL($column) !== '';
    }

    /** The schema after all migrations, replayed in order against an empty schema. */
    private function schema(): Schema {
        if (self::$schema !== null) {
            return self::$schema;
        }
        $schema = new Schema();
        $wrapper = new class($schema) implements ISchemaWrapper {
            public function __construct(
                private Schema $schema,
            ) {
            }
            public function getTable($tableName) {
                return $this->schema->getTable($tableName);
            }
            public function hasTable($tableName) {
                return $this->schema->hasTable($tableName);
            }
            public function createTable($tableName) {
                return $this->schema->createTable($tableName);
            }
            public function dropTable($tableName) {
                $this->schema->dropTable($tableName);
                return $this->schema;
            }
            public function getTables() {
                return $this->schema->getTables();
            }
            public function getTableNames() {
                return array_map(static fn ($t): string => $t->getName(), $this->schema->getTables());
            }
            public function getTableNamesWithoutPrefix() {
                return $this->getTableNames();
            }
            public function getDatabasePlatform() {
                return new MySQL84Platform();
            }
            public function dropAutoincrementColumn(string $table, string $column): void {
                throw new \LogicException('not used by the migrations');
            }
        };
        $files = glob(dirname(__DIR__, 2) . '/lib/Migration/Version*.php');
        sort($files);
        $this->assertNotEmpty($files);
        $output = $this->createMock(IOutput::class);
        foreach ($files as $file) {
            $class = 'OCA\\Pulse\\Migration\\' . basename($file, '.php');
            // Constructor dependencies only serve pre/postSchemaChange.
            $step = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $step->changeSchema($output, static fn (): ISchemaWrapper => $wrapper, []);
        }
        return self::$schema = $schema;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function withDeps(string $class, array $deps): object {
        $service = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        foreach ($deps as $name => $value) {
            (new ReflectionProperty($class, $name))->setValue($service, $value);
        }
        return $service;
    }

    private function room(string $mode): Room {
        $room = new Room();
        $room->setId(1);
        $room->setCode('ABCDEF');
        $room->setOwnerUid('alice');
        $room->setMode($mode);
        return $room;
    }

    private function codes(): CodeGenerator {
        $n = 0;
        $codes = $this->createMock(CodeGenerator::class);
        $codes->method('optionId')->willReturnCallback(static function () use (&$n): string {
            return sprintf('OPT%d', ++$n);
        });
        $codes->method('uniqueRoomCode')->willReturn('XYZ234');
        return $codes;
    }

    private function clock(): ITimeFactory {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(5000);
        return $time;
    }

    private function l10n(): IL10N {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        return $l10n;
    }
}
