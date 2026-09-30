<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Service\CodeGenerator;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\Limits;
use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\RoomService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Caps per account (security review, task D / M4). Pulse's rooms, questions
 * and images count towards no user quota, so without caps one account could
 * fill the database and the data directory with a few scripted requests:
 * - rooms per account, questions per room, characters per question — each
 *   with a default and an app config override (Limits);
 * - every other free-text field of a question is bounded too (option
 *   labels, the list of accepted answers), so a question has a maximum size;
 * - copying a room checks every cap, the image budget included, before it
 *   writes anything, and removes a copy that fails halfway;
 * - parallel requests each check before any of them has written, so rooms,
 *   questions and copies with images count again after writing and take
 *   their own write back when the account is over (security review, second
 *   round: 12 parallel copies stored twice the image budget).
 */
#[CoversClass(Limits::class)]
#[CoversClass(DeckService::class)]
#[CoversClass(RoomService::class)]
class OwnerCapsTest extends TestCase {

    /** App config: key => value (what an admin set with occ config:app:set). */
    private array $config = [];
    /** Questions already in the room (PollMapper::countByRoom). */
    private int $pollCount = 0;
    /** Rooms the account already has (RoomMapper::findByOwner). */
    private int $roomCount = 0;
    /** @var list<Poll> */
    private array $insertedPolls = [];
    /** @var list<Room> */
    private array $insertedRooms = [];
    /** @var list<int> IDs of deleted rooms */
    private array $deletedRooms = [];
    /** @var list<Poll> */
    private array $deletedPolls = [];
    /** Rows a parallel request of the same account inserts at the same moment. */
    private int $parallelInserts = 0;

    // ── Limits ─────────────────────────────────────────────────────────────

    public static function defaults(): array {
        return [
            'image bytes' => [Limits::IMAGE_BYTES_PER_OWNER, 200 * 1024 * 1024],
            'polls per room' => [Limits::POLLS_PER_ROOM, 100],
            'rooms per owner' => [Limits::ROOMS_PER_OWNER, 200],
            'question length' => [Limits::QUESTION_LENGTH, 1000],
        ];
    }

    #[DataProvider('defaults')]
    public function testDefaults(string $key, int $expected): void {
        $this->assertSame($expected, $this->limits()->get($key));
    }

    public static function overrides(): array {
        return [
            'positive number' => [500, 500],
            'zero keeps the default' => [0, 200],
            'negative keeps the default' => [-5, 200],
        ];
    }

    #[DataProvider('overrides')]
    public function testOnlyAPositiveNumberOverrides(int $stored, int $expected): void {
        $this->config[Limits::ROOMS_PER_OWNER] = $stored;

        $this->assertSame($expected, $this->limits()->get(Limits::ROOMS_PER_OWNER));
    }

    public function testUnreadableConfigKeepsTheDefault(): void {
        // e.g. stored with --type=string: IAppConfig throws a type conflict.
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willThrowException(new \RuntimeException('conflict'));

        $this->assertSame(100, (new Limits($appConfig))->get(Limits::POLLS_PER_ROOM));
        $this->assertSame(30, (new Limits($appConfig))->rate(Limits::RATE_CREATE));
    }

    public function testRatesHaveDefaultsAndOverrides(): void {
        $limits = $this->limits();
        $this->assertSame(30, $limits->rate(Limits::RATE_CREATE));
        $this->assertSame(10, $limits->rate(Limits::RATE_DUPLICATE));
        $this->assertSame(120, $limits->rate(Limits::RATE_ADD_POLL));
        $this->assertSame(30, $limits->rate(Limits::RATE_UPLOAD_IMAGE));
        $this->assertSame(30, $limits->rate(Limits::RATE_DEMO));

        $this->config['rate_limit_add_poll'] = 7;
        $this->assertSame(7, $this->limits()->rate(Limits::RATE_ADD_POLL));
    }

    public function testUnknownKeyIsAProgrammingError(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->limits()->get('max_everything');
    }

    // ── Questions ──────────────────────────────────────────────────────────

    public function testHundredthQuestionFitsTheHundredAndFirstDoesNot(): void {
        $this->pollCount = 99;
        $this->deck()->addPoll($this->room(), $this->choice());
        $this->assertCount(1, $this->insertedPolls);

        $this->pollCount = 100;
        try {
            $this->deck()->addPoll($this->room(), $this->choice());
            $this->fail('101st question accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('A room can hold at most 100 questions.', $e->getMessage());
        }
        $this->assertCount(1, $this->insertedPolls, 'nothing inserted');
    }

    public function testParallelQuestionOverTheCapIsTakenBack(): void {
        // 99 questions; this request and a parallel one both passed the check.
        $this->pollCount = 99;
        $this->parallelInserts = 1;

        try {
            $this->deck()->addPoll($this->room(), $this->choice());
            $this->fail('101 questions in a room that holds 100');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('A room can hold at most 100 questions.', $e->getMessage());
        }
        $this->assertSame($this->insertedPolls, $this->deletedPolls, 'its own question is deleted again');
        $this->assertSame(100, $this->pollCount);
    }

    public function testQuestionCapFollowsTheConfig(): void {
        $this->config[Limits::POLLS_PER_ROOM] = 3;
        $this->pollCount = 3;

        $this->expectExceptionMessage('A room can hold at most 3 questions.');
        $this->deck()->addPoll($this->room(), $this->choice());
    }

    public function testQuestionOfExactlyTheMaximumLengthIsKept(): void {
        $text = str_repeat('ä', 1000); // characters, not bytes

        $poll = $this->deck()->addPoll($this->room(), $this->choice($text));

        $this->assertSame($text, $poll->getQuestion());
    }

    public function testLongerQuestionIsRejectedNotCut(): void {
        try {
            $this->deck()->addPoll($this->room(), $this->choice(str_repeat('a', 1001)));
            $this->fail('1001 characters accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('The question can be at most 1000 characters long.', $e->getMessage());
        }
        $this->assertSame([], $this->insertedPolls);
    }

    public function testSurroundingWhitespaceDoesNotCount(): void {
        $poll = $this->deck()->addPoll($this->room(), $this->choice('  ' . str_repeat('a', 1000) . "\n\n"));

        $this->assertSame(1000, mb_strlen($poll->getQuestion()));
    }

    public function testEditingIsBoundByTheSameLength(): void {
        $this->config[Limits::QUESTION_LENGTH] = 20;
        $existing = new Poll();
        $existing->setId(7);
        $existing->setRoomId(1);
        $existing->setStatus('active');
        $polls = $this->createMock(PollMapper::class);
        $polls->method('find')->willReturn($existing);
        $polls->expects($this->never())->method('update');

        $this->expectExceptionMessage('The question can be at most 20 characters long.');
        $this->deck($polls)->updatePoll($this->room(), 7, $this->choice(str_repeat('b', 21)));
    }

    public function testOptionLabelsAreCut(): void {
        $long = str_repeat('o', 5000);

        $poll = $this->deck()->addPoll($this->room(), ['type' => 'choice', 'question' => 'Q', 'options' => [$long, 'B']]);

        $labels = array_column(json_decode($poll->getOptions(), true), 'label');
        $this->assertSame([str_repeat('o', DeckService::OPTION_MAX), 'B'], $labels);
    }

    public function testAcceptedAnswersAreBounded(): void {
        $answers = array_map(static fn (int $i): string => 'answer ' . $i, range(1, 500));

        $poll = $this->deck()->addPoll($this->room('quiz'), ['type' => 'text', 'question' => 'Q', 'answers' => $answers, 'timeLimit' => 30]);

        $key = json_decode($poll->getAnswerKey(), true);
        $this->assertCount(DeckService::ACCEPTED_MAX, $key['accepted']);
        $this->assertSame('answer 1', $key['accepted'][0]);
    }

    public function testEightComposerAnswersStayUntouched(): void {
        $answers = ['Jupiter', 'jupiter planet', 'Gasriese', 'J', 'Jup', 'Jupi', 'Jupit', 'Jupite'];

        $poll = $this->deck()->addPoll($this->room('quiz'), ['type' => 'text', 'question' => 'Q', 'answers' => $answers, 'timeLimit' => 30]);

        $this->assertSame($answers, json_decode($poll->getAnswerKey(), true)['accepted']);
    }

    // ── Rooms ──────────────────────────────────────────────────────────────

    public function testTwoHundredthRoomFitsTheNextDoesNot(): void {
        $this->roomCount = 199;
        $this->rooms()->createRoom('alice');
        $this->assertCount(1, $this->insertedRooms);

        $this->roomCount = 200;
        try {
            $this->rooms()->createRoom('alice');
            $this->fail('201st room created');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('You can have at most 200 rooms. Delete rooms you no longer need.', $e->getMessage());
        }
        $this->assertCount(1, $this->insertedRooms);
    }

    public function testParallelRoomOverTheCapIsTakenBack(): void {
        $this->roomCount = 199;
        $this->parallelInserts = 1;

        try {
            $this->rooms()->createRoom('alice');
            $this->fail('201 rooms for an account that may have 200');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('You can have at most 200 rooms. Delete rooms you no longer need.', $e->getMessage());
        }
        $this->assertSame([99], $this->deletedRooms);
        $this->assertSame(200, $this->roomCount);
    }

    public function testRoomCapFollowsTheConfig(): void {
        $this->config[Limits::ROOMS_PER_OWNER] = 2;
        $this->roomCount = 2;

        $this->expectExceptionMessage('You can have at most 2 rooms.');
        $this->rooms()->createRoom('alice');
    }

    // ── Copying ────────────────────────────────────────────────────────────

    public function testCopyOfAFullAccountWritesNothing(): void {
        $this->roomCount = 200;

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->rooms(source: [$this->sourcePoll(1)])->duplicateRoom($this->room(), 'alice');
        } finally {
            $this->assertSame([], $this->insertedRooms);
            $this->assertSame([], $this->insertedPolls);
        }
    }

    public function testCopyOfAnOversizedDeckWritesNothing(): void {
        // The cap was lowered after the room was built.
        $this->config[Limits::POLLS_PER_ROOM] = 2;
        $source = [$this->sourcePoll(1), $this->sourcePoll(2), $this->sourcePoll(3)];

        try {
            $this->rooms(source: $source)->duplicateRoom($this->room(), 'alice');
            $this->fail('copied 3 questions into a room that may hold 2');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('A room can hold at most 2 questions.', $e->getMessage());
        }
        $this->assertSame([], $this->insertedRooms);
    }

    public function testCopyOverTheImageBudgetFailsBeforeTheFirstWrite(): void {
        // Precomputed: 3 images of 40 bytes, 100 bytes left.
        $images = $this->createMock(PollImageService::class);
        $images->method('sizeOf')->willReturn(40);
        $images->method('remainingBudget')->with('alice')->willReturn(100);
        $images->method('budgetExceeded')->willReturn(new \InvalidArgumentException('budget'));
        $images->expects($this->never())->method('copy');

        $this->expectExceptionMessage('budget');
        try {
            $this->rooms(source: [$this->sourcePoll(1), $this->sourcePoll(2), $this->sourcePoll(3)], images: $images)
                ->duplicateRoom($this->room(), 'alice');
        } finally {
            $this->assertSame([], $this->insertedRooms);
        }
    }

    public function testCopyPassesTheShrinkingBudgetOn(): void {
        $images = $this->createMock(PollImageService::class);
        $images->method('sizeOf')->willReturn(40);
        $images->method('remainingBudget')->willReturn(100);
        $seen = [];
        $images->method('copy')->willReturnCallback(static function (Poll $src, Poll $dst, ?int $remaining) use (&$seen): int {
            $seen[] = $remaining;
            return 40;
        });

        $this->rooms(source: [$this->sourcePoll(1), $this->sourcePoll(2)], images: $images)->duplicateRoom($this->room(), 'alice');

        $this->assertSame([100, 60], $seen);
    }

    public function testCopyWithoutImagesDoesNotReadTheBudget(): void {
        $images = $this->createMock(PollImageService::class);
        $images->method('sizeOf')->willReturn(0);
        $images->expects($this->never())->method('remainingBudget');

        $copy = $this->rooms(source: [$this->sourcePoll(1)], images: $images)->duplicateRoom($this->room(), 'alice');

        $this->assertSame(99, $copy->getId());
        $this->assertCount(1, $this->insertedPolls);
    }

    public function testCopyCountsTheBudgetAgainAndRemovesItselfWhenOver(): void {
        // 100 bytes free when this copy checked; a parallel copy wrote too, and
        // afterwards the account is 20 bytes over.
        $images = $this->createMock(PollImageService::class);
        $images->method('sizeOf')->willReturn(40);
        $images->method('remainingBudget')->willReturnOnConsecutiveCalls(100, -20);
        $images->method('copy')->willReturn(40);
        $images->method('budgetExceeded')->willReturn(new \InvalidArgumentException('budget'));

        try {
            $this->rooms(source: [$this->sourcePoll(1)], images: $images)->duplicateRoom($this->room(), 'alice');
            $this->fail('a copy over the budget reported as success');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('budget', $e->getMessage());
        }
        $this->assertSame([99], $this->deletedRooms, 'the copy and its images go again');
    }

    public function testCopyWithinTheBudgetAfterwardsStays(): void {
        $images = $this->createMock(PollImageService::class);
        $images->method('sizeOf')->willReturn(40);
        $images->method('remainingBudget')->willReturnOnConsecutiveCalls(100, 0);
        $images->method('copy')->willReturn(40);

        $this->rooms(source: [$this->sourcePoll(1)], images: $images)->duplicateRoom($this->room(), 'alice');

        $this->assertSame([], $this->deletedRooms);
    }

    public function testCopyFailingHalfwayIsRemovedAgain(): void {
        // A parallel upload used up the budget between the check and the copy.
        $images = $this->createMock(PollImageService::class);
        $images->method('sizeOf')->willReturn(10);
        $images->method('remainingBudget')->willReturn(100);
        $calls = 0;
        $images->method('copy')->willReturnCallback(static function () use (&$calls): int {
            if (++$calls === 2) {
                throw new \InvalidArgumentException('budget');
            }
            return 10;
        });

        try {
            $this->rooms(source: [$this->sourcePoll(1), $this->sourcePoll(2)], images: $images)->duplicateRoom($this->room(), 'alice');
            $this->fail('half a copy reported as success');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('budget', $e->getMessage());
        }
        $this->assertSame([99], $this->deletedRooms, 'the half-finished copy is deleted');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function limits(): Limits {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default): int => $this->config[$key] ?? $default);
        return new Limits($appConfig);
    }

    private function l10n(): IL10N {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf($text, $p));
        return $l10n;
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

    private function deck(?PollMapper $polls = null): DeckService {
        if ($polls === null) {
            $polls = $this->createMock(PollMapper::class);
            $polls->method('countByRoom')->willReturnCallback(fn (): int => $this->pollCount);
            $polls->method('insert')->willReturnCallback(function (Poll $p): Poll {
                $this->insertedPolls[] = $p;
                $this->pollCount += 1 + $this->parallelInserts;
                return $p;
            });
            $polls->method('delete')->willReturnCallback(function (Poll $p): Poll {
                $this->deletedPolls[] = $p;
                $this->pollCount--;
                return $p;
            });
        }
        return $this->build(DeckService::class, [
            'pollMapper' => $polls,
            'codeGenerator' => $this->codes(),
            'timeFactory' => $this->clock(),
            'l10n' => $this->l10n(),
            'limits' => $this->limits(),
        ]);
    }

    /** @param list<Poll> $source the deck duplicateRoom copies */
    private function rooms(array $source = [], ?PollImageService $images = null): RoomService {
        $rooms = $this->createMock(RoomMapper::class);
        $rooms->method('findByOwner')->willReturnCallback(fn (): array => array_fill(0, $this->roomCount, new Room()));
        $rooms->method('insert')->willReturnCallback(function (Room $r): Room {
            $r->setId(99);
            $this->insertedRooms[] = $r;
            $this->roomCount += 1 + $this->parallelInserts;
            return $r;
        });
        $rooms->method('delete')->willReturnCallback(function (Room $r): Room {
            $this->deletedRooms[] = $r->getId();
            $this->roomCount--;
            return $r;
        });
        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturnCallback(fn (int $id): array => $id === 1 ? $source : $this->insertedPolls);
        $polls->method('insert')->willReturnCallback(function (Poll $p): Poll {
            $this->insertedPolls[] = $p;
            return $p;
        });
        $db = $this->createMock(IDBConnection::class);
        return $this->build(RoomService::class, [
            'roomMapper' => $rooms,
            'pollMapper' => $polls,
            'voteMapper' => $this->createMock(\OCA\Pulse\Db\VoteMapper::class),
            'presenceMapper' => $this->createMock(\OCA\Pulse\Db\PresenceMapper::class),
            'playerMapper' => $this->createMock(\OCA\Pulse\Db\PlayerMapper::class),
            'progressMapper' => $this->createMock(\OCA\Pulse\Db\ProgressMapper::class),
            'codeGenerator' => $this->codes(),
            'imageService' => $images ?? $this->createMock(PollImageService::class),
            'timeFactory' => $this->clock(),
            'l10n' => $this->l10n(),
            'db' => $db,
            'limits' => $this->limits(),
        ]);
    }

    private function build(string $class, array $deps): object {
        $service = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        foreach ($deps as $name => $value) {
            (new ReflectionProperty($class, $name))->setValue($service, $value);
        }
        return $service;
    }

    private function room(string $mode = 'poll'): Room {
        $room = new Room();
        $room->setId(1);
        $room->setCode('ABCDEF');
        $room->setOwnerUid('alice');
        $room->setMode($mode);
        return $room;
    }

    private function choice(string $question = 'Q?'): array {
        return ['type' => 'choice', 'question' => $question, 'options' => ['A', 'B']];
    }

    private function sourcePoll(int $id): Poll {
        $poll = new Poll();
        $poll->setId($id);
        $poll->setRoomId(1);
        $poll->setType('choice');
        $poll->setQuestion('Q' . $id);
        $poll->setOptions('[]');
        $poll->setMaxWords(0);
        $poll->setCorrectOption('');
        $poll->setTimeLimit(0);
        return $poll;
    }
}
