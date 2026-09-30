<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Service\CodeGenerator;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The services behind the controllers fed with hostile values: nested
 * lists and objects, true, 1e300, INF, "1e999", twenty digits, broken
 * UTF-8 and NUL — in every field of every question template (DeckService), in every
 * vote value of every question type (VoteService::normalizeValue) and in the image upload.
 *
 * Exactly two outcomes are allowed: a result that can be stored, or an
 * InvalidArgumentException with a message (400). No PHP warning (phpunit.xml:
 * failOnWarning), no TypeError, no value that makes json_encode fail.
 */
#[CoversClass(DeckService::class)]
#[CoversClass(VoteService::class)]
#[CoversClass(PollImageService::class)]
class HostileInputTest extends TestCase {

    private const HOSTILE = [
        ['x'],
        ['a' => ['b' => 'c']],
        true,
        1e300,
        INF,
        '1e999',
        '99999999999999999999',
        "\xFF\xFE",
        "a\0b",
        null,
    ];

    private DeckService $deck;
    private VoteService $votes;
    /** @var list<array{0: int, 1: int}> PollMapper::setPosition(pollId, position) */
    private array $positions = [];

    protected function setUp(): void {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $polls = $this->createMock(PollMapper::class);
        $polls->method('insert')->willReturnArgument(0);
        $polls->method('update')->willReturnArgument(0);
        $polls->method('nextPosition')->willReturn(0);
        $polls->method('find')->willReturnCallback(static function (int $id): Poll {
            if ($id !== 11 && $id !== 12) {
                throw new DoesNotExistException('');
            }
            $poll = new Poll();
            $poll->setId($id);
            $poll->setRoomId(1);
            return $poll;
        });
        $polls->method('setPosition')->willReturnCallback(function (int $pollId, int $position): void {
            $this->positions[] = [$pollId, $position];
        });

        $ids = ['OPT1', 'OPT2', 'OPT3', 'OPT4', 'OPT5', 'OPT6', 'OPT7', 'OPT8'];
        $codes = $this->createMock(CodeGenerator::class);
        $codes->method('optionId')->willReturnCallback(static function () use (&$ids): string {
            return array_shift($ids) ?? 'OPTX';
        });

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(5000);

        $this->deck = (new \ReflectionClass(DeckService::class))->newInstanceWithoutConstructor();
        foreach ([
            'pollMapper' => $polls,
            'codeGenerator' => $codes,
            'timeFactory' => $time,
            'l10n' => $l10n,
            'limits' => new \OCA\Pulse\Service\Limits($this->createMock(\OCP\IAppConfig::class)),
        ] as $name => $value) {
            (new ReflectionProperty(DeckService::class, $name))->setValue($this->deck, $value);
        }

        $this->votes = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(VoteService::class, 'l10n'))->setValue($this->votes, $l10n);
    }

    // ── DeckService: every template, every field ───────────────────────────

    /** Valid questions per mode — each field in them is made unusable once. */
    public static function templates(): array {
        $options = ['A', 'B'];
        $pairs = [['left' => 'a', 'right' => '1'], ['left' => 'b', 'right' => '2']];
        $axis = ['title' => 'Achse', 'poleLow' => 'links', 'poleHigh' => 'rechts'];
        return [
            'Poll choice' => ['poll', ['type' => 'choice', 'question' => 'Q?', 'options' => $options]],
            'Poll words' => ['poll', ['type' => 'words', 'question' => 'Q?', 'maxWords' => 3]],
            'Poll scale single' => ['poll', ['type' => 'scale', 'question' => 'Q?', 'scaleMode' => 'single', 'scaleMax' => 5, 'minLabel' => 'wenig', 'maxLabel' => 'viel']],
            'Poll scale spectrum' => ['poll', ['type' => 'scale', 'question' => 'Q?', 'scaleMode' => 'spectrum', 'scaleMax' => 10, 'aspects' => [['label' => 'a'], ['label' => 'b'], ['label' => 'c']]]],
            'Poll scale compass' => ['poll', ['type' => 'scale', 'question' => 'Q?', 'scaleMode' => 'compass', 'range' => 5, 'axisX' => $axis, 'axisY' => $axis, 'cornerLabels' => ['a', 'b'], 'heatmapThreshold' => 45]],
            'Poll rank' => ['poll', ['type' => 'rank', 'question' => 'Q?', 'options' => $options]],
            'Poll match' => ['poll', ['type' => 'match', 'question' => 'Q?', 'pairs' => $pairs]],
            'Quiz choice' => ['quiz', ['type' => 'choice', 'question' => 'Q?', 'options' => $options, 'correctIndex' => 1, 'timeLimit' => 30]],
            'Quiz truefalse' => ['quiz', ['type' => 'truefalse', 'question' => 'Q?', 'correctIndex' => 0, 'timeLimit' => 30]],
            'Quiz multi' => ['quiz', ['type' => 'multi', 'question' => 'Q?', 'options' => $options, 'correctIndexes' => [0], 'timeLimit' => 30]],
            'Quiz number' => ['quiz', ['type' => 'number', 'question' => 'Q?', 'target' => 5, 'tolerance' => 1, 'timeLimit' => 30]],
            'Quiz text' => ['quiz', ['type' => 'text', 'question' => 'Q?', 'answers' => ['x'], 'timeLimit' => 30]],
            'Quiz rank' => ['quiz', ['type' => 'rank', 'question' => 'Q?', 'options' => $options, 'timeLimit' => 30]],
            'Quiz match' => ['quiz', ['type' => 'match', 'question' => 'Q?', 'pairs' => $pairs, 'timeLimit' => 30]],
        ];
    }

    #[DataProvider('templates')]
    public function testEveryFieldMadeUnusable(string $mode, array $valid): void {
        $type = $valid['type'];
        // The template itself must be valid, otherwise the loop proves nothing.
        $this->assertInstanceOf(Poll::class, $this->deck->addPoll($this->room($mode), $valid));

        foreach (self::HOSTILE as $i => $h) {
            foreach (array_keys($valid) as $key) {
                $this->attempt($mode, "$mode/$type/$key#$i", [$key => $h] + $valid);
            }
            // Nested: the value sits one level deeper.
            $nested = [
                'options' => [[$h, 'A', 'B'], [['label' => $h], 'A', 'B']],
                'pairs' => [[['left' => $h, 'right' => 'x'], ['left' => 'b', 'right' => 'y']]],
                'aspects' => [[['label' => $h, 'poleLow' => $h, 'poleHigh' => $h], ['label' => 'b'], ['label' => 'c'], ['label' => 'd']]],
                'answers' => [[$h, 'ok']],
                'cornerLabels' => [[$h, $h]],
                'correctIndexes' => [[$h, 0]],
                'axisX' => [['title' => $h, 'poleLow' => 'l', 'poleHigh' => 'h']],
            ];
            foreach ($nested as $key => $variants) {
                if (!array_key_exists($key, $valid)) {
                    continue;
                }
                foreach ($variants as $j => $variant) {
                    $this->attempt($mode, "$mode/$type/$key#$i.$j", [$key => $variant] + $valid);
                }
            }
        }
    }

    public function testOptionAsListOrObjectIsDropped(): void {
        $poll = $this->deck->addPoll($this->room('poll'), [
            'type' => 'choice',
            'question' => 'Q?',
            'options' => [['x'], ['label' => ['y']], 'A', 'B'],
        ]);

        $this->assertSame(['A', 'B'], array_column($poll->getOptionsArray(), 'label'));
    }

    public function testQuestionWithBrokenUtf8IsEmpty(): void {
        $this->expectExceptionMessage('The question must not be empty.');
        $this->deck->addPoll($this->room('poll'), ['type' => 'choice', 'question' => "\xFF\xFE", 'options' => ['A', 'B']]);
    }

    public function testNulInTheQuestionIsDropped(): void {
        $poll = $this->deck->addPoll($this->room('poll'), ['type' => 'choice', 'question' => "a\0b", 'options' => ['A', 'B']]);

        $this->assertSame('ab', $poll->getQuestion());
    }

    public function testInfiniteTargetNumberIsNoNumber(): void {
        foreach (['1e999', INF] as $target) {
            try {
                $this->deck->addPoll($this->room('quiz'), ['type' => 'number', 'question' => 'N?', 'target' => $target, 'timeLimit' => 30]);
                $this->fail('target number ' . var_export($target, true) . ' accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Please enter a target number.', $e->getMessage());
            }
        }
    }

    public function testInfiniteToleranceBecomesZero(): void {
        $poll = $this->deck->addPoll($this->room('quiz'), ['type' => 'number', 'question' => 'N?', 'target' => 5, 'tolerance' => INF, 'timeLimit' => 30]);

        $this->assertSame(['target' => 5, 'tolerance' => 0], $poll->getAnswerKeyArray());
    }

    public function testHugeTimeLimitTakesTheDefault(): void {
        $poll = $this->deck->addPoll($this->room('quiz'), ['type' => 'choice', 'question' => 'Q?', 'options' => ['A', 'B'], 'correctIndex' => 0, 'timeLimit' => 1e100]);

        $this->assertSame(30, $poll->getTimeLimit());
    }

    public function testHugeCorrectAnswerIsNone(): void {
        $this->expectExceptionMessage('Please mark the correct answer.');
        $this->deck->addPoll($this->room('quiz'), ['type' => 'choice', 'question' => 'Q?', 'options' => ['A', 'B'], 'correctIndex' => 1e100, 'timeLimit' => 30]);
    }

    public function testOrderAsObjectCountsInItsOwnOrder(): void {
        // JSON object instead of a list: the keys would be text (setPosition(int, int)).
        $this->deck->reorder($this->room('poll'), ['a' => 12, 'b' => 11]);

        $this->assertSame([[12, 0], [11, 1]], $this->positions);
    }

    public function testNestedOrderIsInvalid(): void {
        try {
            $this->deck->reorder($this->room('poll'), [[11]]);
            $this->fail('nested order accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Invalid order.', $e->getMessage());
        }
        $this->assertSame([], $this->positions, 'nothing written');
    }

    // ── VoteService: every question type, every vote value ─────────────────

    public function testChoice(): void {
        $this->probe($this->choicePoll('choice'), static fn (): array => []);
    }

    public function testTrueFalse(): void {
        $this->probe($this->choicePoll('truefalse'), static fn (): array => []);
    }

    public function testMultipleAnswers(): void {
        $this->probe($this->choicePoll('multi'), static fn (mixed $h): array => [[$h, 'OA']]);
    }

    public function testRanking(): void {
        $this->probe($this->choicePoll('rank'), static fn (mixed $h): array => [[$h, 'OA']]);
    }

    public function testMatching(): void {
        $poll = $this->poll('match', [
            'items' => [['id' => 'I1', 'label' => 'a'], ['id' => 'I2', 'label' => 'b']],
            'targets' => [['id' => 'T1', 'label' => '1'], ['id' => 'T2', 'label' => '2']],
        ]);
        $this->probe($poll, static fn (mixed $h): array => [['I1' => $h, 'I2' => 'T2']]);
    }

    public function testNumberGuess(): void {
        $this->probe($this->poll('number', []), static fn (): array => []);
    }

    public function testFreeText(): void {
        $this->probe($this->poll('text', []), static fn (): array => []);
    }

    public function testWords(): void {
        $poll = $this->poll('words', []);
        $poll->setMaxWords(3);
        $this->probe($poll, static fn (mixed $h): array => [[$h, 'ok']]);
    }

    public function testScaleSingle(): void {
        $this->probe($this->singlePoll(), static fn (): array => []);
    }

    public function testScaleSpectrum(): void {
        $this->probe($this->spectrumPoll(), static fn (mixed $h): array => [['S1' => $h, 'S2' => 1, 'S3' => 2]]);
    }

    public function testScaleCompass(): void {
        $this->probe($this->compassPoll(), static fn (mixed $h): array => [['x' => $h, 'y' => 0]]);
    }

    public function testInfiniteGuessIsNoNumber(): void {
        foreach (['1e999', INF] as $value) {
            try {
                $this->votes->normalizeValue($this->poll('number', []), $value);
                $this->fail('guess ' . var_export($value, true) . ' accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Please enter a number.', $e->getMessage());
            }
        }
    }

    public function testHugeScaleValueIsInvalid(): void {
        $this->expectExceptionMessage('Invalid value.');
        $this->votes->normalizeValue($this->singlePoll(), 1e100);
    }

    public function testHugeSpectrumValueIsInvalid(): void {
        $this->expectExceptionMessage('Invalid value.');
        $this->votes->normalizeValue($this->spectrumPoll(), ['S1' => 1e100, 'S2' => 1, 'S3' => 2]);
    }

    public function testHugeCompassValueIsInvalid(): void {
        $this->expectExceptionMessage('Invalid position.');
        $this->votes->normalizeValue($this->compassPoll(), ['x' => 1e100, 'y' => 0]);
    }

    // ── Image upload ───────────────────────────────────────────────────────

    public function testImageFieldAsListIsNoImage(): void {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $images = (new \ReflectionClass(PollImageService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(PollImageService::class, 'l10n'))->setValue($images, $l10n);

        // `image[]=…`: PHP turns every field of the upload into a list.
        $this->expectExceptionMessage('No image received.');
        $images->store(new Poll(), ['name' => ['a'], 'tmp_name' => ['x'], 'error' => [0], 'size' => [1]]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** Create a question: a Poll or a 400 with a message — nothing else. */
    private function attempt(string $mode, string $label, array $data): void {
        try {
            $poll = $this->deck->addPoll($this->room($mode), $data);
        } catch (\InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage(), $label);
            return;
        } catch (\Throwable $e) {
            $this->fail("$label: " . get_class($e) . ' ' . $e->getMessage());
        }
        $this->assertInstanceOf(Poll::class, $poll, $label);
        // json_encode returns false when something cannot be stored (INF, broken UTF-8).
        $this->assertIsString($poll->getOptions(), "$label: options");
        $this->assertTrue($poll->getAnswerKey() === null || is_string($poll->getAnswerKey()), "$label: answerKey");
    }

    /**
     * Send every hostile value as a vote, plus the nested
     * variants from $nested($h): a storable value or a 400.
     *
     * @param callable(mixed): list<mixed> $nested
     */
    private function probe(Poll $poll, callable $nested): void {
        foreach (self::HOSTILE as $i => $h) {
            foreach ([$h, ...$nested($h)] as $j => $value) {
                $label = $poll->getType() . "#$i.$j";
                try {
                    $out = $this->votes->normalizeValue($poll, $value);
                } catch (\InvalidArgumentException $e) {
                    $this->assertNotSame('', $e->getMessage(), $label);
                    continue;
                } catch (\Throwable $e) {
                    $this->fail("$label: " . get_class($e) . ' ' . $e->getMessage());
                }
                $this->assertNotFalse(json_encode($out), "$label: not storable");
            }
        }
    }

    private function room(string $mode): Room {
        $room = new Room();
        $room->setId(1);
        $room->setMode($mode);
        return $room;
    }

    private function poll(string $type, array $options): Poll {
        $poll = new Poll();
        $poll->setId(7);
        $poll->setRoomId(1);
        $poll->setType($type);
        $poll->setOptions(json_encode($options));
        return $poll;
    }

    private function choicePoll(string $type): Poll {
        return $this->poll($type, [['id' => 'OA', 'label' => 'A'], ['id' => 'OB', 'label' => 'B']]);
    }

    /** Scale config exactly as DeckService::buildScale writes it. */
    private function singlePoll(): Poll {
        return $this->poll('scale', ['mode' => 'single', 'min' => 1, 'max' => 5, 'minLabel' => '', 'maxLabel' => '']);
    }

    private function spectrumPoll(): Poll {
        return $this->poll('scale', ['mode' => 'spectrum', 'min' => 0, 'max' => 10, 'aspects' => [
            ['id' => 'S1', 'label' => 'a', 'poleLow' => '', 'poleHigh' => ''],
            ['id' => 'S2', 'label' => 'b', 'poleLow' => '', 'poleHigh' => ''],
            ['id' => 'S3', 'label' => 'c', 'poleLow' => '', 'poleHigh' => ''],
        ]]);
    }

    private function compassPoll(): Poll {
        $axis = ['title' => 'Achse', 'poleLow' => 'l', 'poleHigh' => 'h'];
        return $this->poll('scale', [
            'mode' => 'compass', 'range' => 5, 'axisX' => $axis, 'axisY' => $axis, 'cornerLabels' => [], 'heatmapThreshold' => 45,
        ]);
    }
}
