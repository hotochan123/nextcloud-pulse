<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\CsvFormat;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\StateService;
use OCA\Pulse\Service\TallyService;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Golden bytes of the moderated CSV export (StateService::exportCsv): one
 * deck with every question type and all three scale modes, each with and
 * without answers. Where an empty-case row depends on the tally's rows and
 * not on the vote count, a question with rows but no votes (aspects nobody
 * rated) or with votes but no rows (only blanks) keeps the two apart. The
 * export is a download people open in Excel, so a refactoring must
 * reproduce it byte for byte — row order, the empty-case rows, quoting, the
 * BOM and the numbers in the export's language.
 *
 * German on purpose: only there do num() (comma, thousands dot) and the raw
 * compass points ("2 / -1", never localised) look different. The messages
 * stay the English source strings, formatted like the real IL10N.
 */
#[CoversClass(StateService::class)]
#[CoversClass(CsvFormat::class)]
class ModeratedCsvGoldenTest extends TestCase {

    private StateService $service;
    /** @var Poll[] deck in order */
    private array $deck = [];
    /** @var array<int, Vote[]> votes per question */
    private array $votes = [];

    protected function setUp(): void {
        if (!class_exists(\NumberFormatter::class)) {
            $this->markTestSkipped('intl missing: numbers would come out unformatted');
        }
        $deck = $this->createMock(DeckService::class);
        $deck->method('deck')->willReturnCallback(fn (): array => $this->deck);

        $voteMapper = $this->createMock(VoteMapper::class);
        $voteMapper->method('findByPoll')->willReturnCallback(fn (int $id): array => $this->votes[$id] ?? []);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
        $l10n->method('getLocaleCode')->willReturn('de');

        $this->service = (new \ReflectionClass(StateService::class))->newInstanceWithoutConstructor();
        foreach ([
            'deckService' => $deck,
            'voteMapper' => $voteMapper,
            'tallyService' => new TallyService(),
            'l10n' => $l10n,
        ] as $name => $value) {
            (new ReflectionProperty(StateService::class, $name))->setValue($this->service, $value);
        }
    }

    public function testEveryTypeAndScaleMode(): void {
        $this->everyType();

        $expected = <<<'CSV'
            No.;Question;Type;Answer;Votes;Share
            1;"Favourite colour?";"Multiple choice";Red;2;50%
            1;"Favourite colour?";"Multiple choice";Green;1;25%
            1;"Favourite colour?";"Multiple choice";Blue;0;0%
            2;"Tea or ""coffee""; why?";"Multiple choice";Tea;0;
            2;"Tea or ""coffee""; why?";"Multiple choice";Coffee;0;
            3;"The earth is flat.";True/False;True;1;33%
            3;"The earth is flat.";True/False;False;2;67%
            4;"Nobody judged";True/False;True;0;
            4;"Nobody judged";True/False;False;0;
            5;"Which are planets?";"Multiple answers";Mars;2;100%
            5;"Which are planets?";"Multiple answers";Moon;0;0%
            5;"Which are planets?";"Multiple answers";Venus;1;50%
            6;"Nobody picked";"Multiple answers";Mars;0;
            6;"Nobody picked";"Multiple answers";Venus;0;
            7;"One word for Monday?";"Word cloud";coffee;2;67%
            7;"One word for Monday?";"Word cloud";tea;1;33%
            7;"One word for Monday?";"Word cloud";'=cmd;1;33%
            8;"Nobody typed";"Word cloud";"(no answers)";0;
            9;"Only blanks";"Word cloud";"(no answers)";0;
            10;"How was it?";Scale;1;0;0%
            10;"How was it?";Scale;2;0;0%
            10;"How was it?";Scale;3;0;0%
            10;"How was it?";Scale;4;1;33%
            10;"How was it?";Scale;5;2;67%
            10;"How was it?";Scale;Average;4,67;
            11;"Nobody rated";Scale;1;0;
            11;"Nobody rated";Scale;2;0;
            11;"Nobody rated";Scale;3;0;
            11;"Nobody rated";Scale;Average;0;
            12;"Rate the talk";Scale;"Content · average";7,5;2
            12;"Rate the talk";Scale;"Delivery · average";5;1
            13;"Nobody rated the aspects";Scale;"Content · average";0;0
            13;"Nobody rated the aspects";Scale;"Delivery · average";0;0
            14;"No aspects yet";Scale;"(no aspects)";0;
            15;"Where do you stand?";Scale;"Centre of gravity (X / Y)";"0,33 / 1,33";3
            15;"Where do you stand?";Scale;"Point (X / Y)";"2 / -1";
            15;"Where do you stand?";Scale;"Point (X / Y)";"-3 / 4";
            15;"Where do you stand?";Scale;"Point (X / Y)";"2 / 1";
            16;"Nobody placed a point";Scale;"Centre of gravity (X / Y)";—;0
            17;"Order by size";Ranking;"1. Jupiter · average place 1,33";2;3
            17;"Order by size";Ranking;"2. Saturn · average place 2";1;3
            17;"Order by size";Ranking;"3. Neptune · average place 2,67";0;3
            18;"Nobody ranked";Ranking;"1. Jupiter · average place 0";0;0
            18;"Nobody ranked";Ranking;"2. Saturn · average place 0";0;0
            19;"Nothing to rank";Ranking;"(no answers)";0;
            20;"Match the capitals";Matching;"France → Paris";2;67%
            20;"Match the capitals";Matching;"France → Rome";1;33%
            20;"Match the capitals";Matching;"Italy → Rome";1;50%
            20;"Match the capitals";Matching;"Italy → Madrid";1;50%
            21;"Nobody matched";Matching;"(no answers)";0;
            22;"When did Apollo 11 land?";"Number guess";1.968,5;1;25%
            22;"When did Apollo 11 land?";"Number guess";1.969;2;50%
            22;"When did Apollo 11 land?";"Number guess";2.001;1;25%
            23;"Nobody guessed";"Number guess";"(no answers)";0;
            24;"Largest planet?";"Free text";Jupiter;2;40%
            24;"Largest planet?";"Free text";Saturn;1;20%
            24;"Largest planet?";"Free text";'=1+1;1;20%
            25;"Only a blank answer";"Free text";"(no answers)";0;
            26;"Nobody answered";"Free text";"(no answers)";0;
            CSV;
        $this->assertSame("\xEF\xBB\xBF" . $expected . "\n", $this->service->exportCsv(new Room()));
    }

    public function testEmptyDeckIsTheHeaderAlone(): void {
        $this->assertSame("\xEF\xBB\xBFNo.;Question;Type;Answer;Votes;Share\n", $this->service->exportCsv(new Room()));
    }

    // ── Deck ───────────────────────────────────────────────────────────────

    private function everyType(): void {
        $this->poll('choice', 'Favourite colour?', [['id' => 'R', 'label' => 'Red'], ['id' => 'G', 'label' => 'Green'], ['id' => 'B', 'label' => 'Blue']],
            // 'X' is no option: it counts in the total, not in a row.
            ['R', 'R', 'G', 'X']);
        $this->poll('choice', 'Tea or "coffee"; why?', [['id' => 'T', 'label' => 'Tea'], ['id' => 'C', 'label' => 'Coffee']], []);
        $this->poll('truefalse', 'The earth is flat.', [['id' => 'T', 'label' => 'True'], ['id' => 'F', 'label' => 'False']], ['F', 'F', 'T']);
        $this->poll('truefalse', 'Nobody judged', [['id' => 'T', 'label' => 'True'], ['id' => 'F', 'label' => 'False']], []);
        $this->poll('multi', 'Which are planets?', [['id' => 'A1', 'label' => 'Mars'], ['id' => 'B2', 'label' => 'Moon'], ['id' => 'C3', 'label' => 'Venus']],
            [['A1', 'C3'], ['A1']]);
        $this->poll('multi', 'Nobody picked', [['id' => 'A1', 'label' => 'Mars'], ['id' => 'C3', 'label' => 'Venus']], []);
        $this->poll('words', 'One word for Monday?', [], [['Coffee', 'tea'], ['coffee'], ['=cmd']]);
        $this->poll('words', 'Nobody typed', [], []);
        // Answered, but nothing that counts as a word: no rows from the tally, one vote in the total.
        $this->poll('words', 'Only blanks', [], [['', ' ']]);
        $this->poll('scale', 'How was it?', ['mode' => 'single', 'min' => 1, 'max' => 5, 'minLabel' => 'bad', 'maxLabel' => 'good'], [4, 5, 5]);
        $this->poll('scale', 'Nobody rated', ['mode' => 'single', 'min' => 1, 'max' => 3], []);
        $this->poll('scale', 'Rate the talk', ['mode' => 'spectrum', 'min' => 0, 'max' => 10, 'aspects' => [
            ['id' => 'S1', 'label' => 'Content'], ['id' => 'S2', 'label' => 'Delivery'],
        ]], [['S1' => 8, 'S2' => 5], ['S1' => 7]]);
        // Aspects without votes: one row each, not "(no aspects)".
        $this->poll('scale', 'Nobody rated the aspects', ['mode' => 'spectrum', 'min' => 0, 'max' => 10, 'aspects' => [
            ['id' => 'S1', 'label' => 'Content'], ['id' => 'S2', 'label' => 'Delivery'],
        ]], []);
        $this->poll('scale', 'No aspects yet', ['mode' => 'spectrum', 'min' => 0, 'max' => 10, 'aspects' => []], []);
        $this->poll('scale', 'Where do you stand?', ['mode' => 'compass', 'range' => 5], [['x' => 2, 'y' => -1], ['x' => -3, 'y' => 4], ['x' => 2, 'y' => 1]]);
        $this->poll('scale', 'Nobody placed a point', ['mode' => 'compass', 'range' => 5], []);
        $this->poll('rank', 'Order by size', [['id' => 'J', 'label' => 'Jupiter'], ['id' => 'S', 'label' => 'Saturn'], ['id' => 'N', 'label' => 'Neptune']],
            [['J', 'S', 'N'], ['S', 'J', 'N'], ['J', 'N', 'S']]);
        $this->poll('rank', 'Nobody ranked', [['id' => 'J', 'label' => 'Jupiter'], ['id' => 'S', 'label' => 'Saturn']], []);
        $this->poll('rank', 'Nothing to rank', [], []);
        $this->poll('match', 'Match the capitals', [
            'items' => [['id' => 'I1', 'label' => 'France'], ['id' => 'I2', 'label' => 'Italy']],
            'targets' => [['id' => 'T1', 'label' => 'Paris'], ['id' => 'T2', 'label' => 'Rome'], ['id' => 'T3', 'label' => 'Madrid']],
        ], [['I1' => 'T1', 'I2' => 'T2'], ['I1' => 'T1', 'I2' => 'T3'], ['I1' => 'T2']]);
        $this->poll('match', 'Nobody matched', [
            'items' => [['id' => 'I1', 'label' => 'France']],
            'targets' => [['id' => 'T1', 'label' => 'Paris']],
        ], []);
        $this->poll('number', 'When did Apollo 11 land?', [], [1969, 1968.5, 1969, 2001], ['target' => 1969, 'tolerance' => 1]);
        $this->poll('number', 'Nobody guessed', [], [], ['target' => 1969, 'tolerance' => 1]);
        $this->poll('text', 'Largest planet?', [], ['Jupiter', 'jupiter ', 'Saturn', '=1+1', ''], ['accepted' => ['Jupiter'], 'rejected' => ['Saturn']]);
        // Answered, but only blank: no answer group, one vote in the total.
        $this->poll('text', 'Only a blank answer', [], [''], ['accepted' => [], 'rejected' => []]);
        $this->poll('text', 'Nobody answered', [], [], ['accepted' => [], 'rejected' => []]);
    }

    /**
     * @param array $options options JSON (a list, or the scale/matching config)
     * @param list<mixed> $values stored vote values
     * @param ?array $key answer key
     */
    private function poll(string $type, string $question, array $options, array $values, ?array $key = null): void {
        $id = count($this->deck) + 1;
        $poll = new Poll();
        $poll->setId($id);
        $poll->setRoomId(5);
        $poll->setType($type);
        $poll->setQuestion($question);
        $poll->setOptions(json_encode($options));
        if ($key !== null) {
            $poll->setAnswerKey(json_encode($key));
        }
        $this->deck[] = $poll;
        foreach ($values as $value) {
            $vote = new Vote();
            $vote->setPollId($id);
            $vote->setPayload(json_encode(['value' => $value]));
            $this->votes[$id][] = $vote;
        }
    }
}
