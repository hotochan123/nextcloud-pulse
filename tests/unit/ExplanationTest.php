<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\CodeGenerator;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\Limits;
use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\RoomService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Explanation of a quiz question (why the correct answer is correct), shown
 * with the reveal. Stored by the composer path (DeckService::applyPollData):
 * plain text, trimmed, line breaks kept, cut to EXPLANATION_MAX, empty = none.
 * Only a quiz has a reveal — a poll never stores one. It is part of the
 * solution: not in Poll::jsonSerialize (the participant shape), only in the
 * owner view (ownerPoll); the public gates are in PublicRevealGateTest and the
 * self-paced gate tests. A copied room takes it along.
 */
#[CoversClass(DeckService::class)]
#[CoversClass(Poll::class)]
#[CoversClass(RoomService::class)]
class ExplanationTest extends TestCase {

    /** @var list<Poll> */
    private array $inserted = [];
    private DeckService $deck;
    private ?Poll $existing = null;

    protected function setUp(): void {
        $polls = $this->createMock(PollMapper::class);
        $polls->method('insert')->willReturnCallback(function (Poll $p): Poll {
            $this->inserted[] = $p;
            return $p;
        });
        $polls->method('update')->willReturnArgument(0);
        $polls->method('find')->willReturnCallback(fn (): Poll => $this->existing);

        $this->deck = $this->withDeps(DeckService::class, [
            'pollMapper' => $polls,
            'voteMapper' => $this->createMock(VoteMapper::class),
            'codeGenerator' => $this->codes(),
            'timeFactory' => $this->clock(),
            'l10n' => $this->l10n(),
            'limits' => new Limits($this->createMock(IAppConfig::class)),
        ]);
    }

    public function testQuizStoresTheExplanationTrimmed(): void {
        $poll = $this->add('quiz', "  \r\nParis has been the capital since 987.\r\nNot Lyon.\r  \n");

        $this->assertSame("Paris has been the capital since 987.\nNot Lyon.", $poll->getExplanation());
    }

    public function testQuizExplanationIsCutAtTheLimit(): void {
        // Multibyte: characters, not bytes, and no broken UTF-8 at the cut.
        $poll = $this->add('quiz', str_repeat('ä', DeckService::EXPLANATION_MAX + 50));

        $this->assertSame(DeckService::EXPLANATION_MAX, mb_strlen((string)$poll->getExplanation()));
        $this->assertTrue(mb_check_encoding((string)$poll->getExplanation(), 'UTF-8'));
    }

    public static function noExplanation(): array {
        return [
            'missing' => [null],
            'empty' => [''],
            'only whitespace' => [" \n\t "],
            'a list' => [['why']],
            'an object' => [['text' => 'why']],
            'broken UTF-8' => ["\xC3\x28"],
        ];
    }

    #[DataProvider('noExplanation')]
    public function testNothingUsableMeansNone(mixed $raw): void {
        $poll = $this->add('quiz', $raw);

        $this->assertNull($poll->getExplanation());
        $this->assertSame('', $poll->explanationOrEmpty());
    }

    public function testPollIgnoresTheExplanation(): void {
        // A poll has no reveal and no correct answer — nothing to explain.
        $poll = $this->add('poll', 'because');

        $this->assertNull($poll->getExplanation());
        $this->assertSame('', $this->deck->ownerPoll($poll)['explanation']);
    }

    public function testEditingReplacesAndClearsIt(): void {
        $this->existing = $this->quizPoll('old reason');

        $this->deck->updatePoll($this->room('quiz'), 7, $this->quizData() + ['explanation' => 'new reason']);
        $this->assertSame('new reason', $this->existing->getExplanation());

        $this->deck->updatePoll($this->room('quiz'), 7, $this->quizData());
        $this->assertNull($this->existing->getExplanation(), 'left out on save = removed');
    }

    public function testOnlyTheOwnerViewCarriesIt(): void {
        $poll = $this->add('quiz', 'Because of the river.');

        $this->assertArrayNotHasKey('explanation', $poll->jsonSerialize(), 'participant shape: never');
        $this->assertStringNotContainsString('river', (string)json_encode($poll->jsonSerialize()));
        $this->assertSame('Because of the river.', $this->deck->ownerPoll($poll)['explanation']);
    }

    public function testCopiedRoomTakesTheExplanationAlong(): void {
        $source = [$this->quizPoll("Line one\nLine two"), $this->quizPoll(null)];

        $rooms = $this->createMock(RoomMapper::class);
        $rooms->method('insert')->willReturnCallback(static function (Room $r): Room {
            $r->setId(99);
            return $r;
        });
        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturn($source);
        $copies = [];
        $polls->method('insert')->willReturnCallback(static function (Poll $p) use (&$copies): Poll {
            $copies[] = $p;
            return $p;
        });
        $service = $this->withDeps(RoomService::class, [
            'roomMapper' => $rooms,
            'pollMapper' => $polls,
            'codeGenerator' => $this->codes(),
            'imageService' => $this->createMock(PollImageService::class),
            'timeFactory' => $this->clock(),
            'l10n' => $this->l10n(),
            'limits' => new Limits($this->createMock(IAppConfig::class)),
        ]);

        $service->duplicateRoom($this->room('quiz'), 'alice');

        $this->assertCount(2, $copies);
        $this->assertSame("Line one\nLine two", $copies[0]->getExplanation());
        $this->assertNull($copies[1]->getExplanation());
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function add(string $mode, mixed $explanation): Poll {
        $data = $mode === 'quiz'
            ? $this->quizData()
            : ['type' => 'choice', 'question' => 'Q', 'options' => ['A', 'B']];
        if ($explanation !== null) {
            $data['explanation'] = $explanation;
        }
        $this->deck->addPoll($this->room($mode), $data);
        $this->assertNotEmpty($this->inserted);
        return $this->inserted[count($this->inserted) - 1];
    }

    private function quizData(): array {
        return ['type' => 'choice', 'question' => 'Capital of France?', 'options' => ['Paris', 'Lyon'], 'correctIndex' => 0, 'timeLimit' => 20];
    }

    private function quizPoll(?string $explanation): Poll {
        $poll = new Poll();
        $poll->setId(7);
        $poll->setRoomId(1);
        $poll->setType('choice');
        $poll->setQuestion('Capital of France?');
        $poll->setOptions('[{"id":"AA","label":"Paris"},{"id":"BB","label":"Lyon"}]');
        $poll->setCorrectOption('AA');
        $poll->setStatus('active');
        $poll->setExplanation($explanation);
        return $poll;
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
        $codes = $this->createMock(CodeGenerator::class);
        $n = 0;
        $codes->method('optionId')->willReturnCallback(static function () use (&$n): string {
            return 'OPT' . (++$n);
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
}
