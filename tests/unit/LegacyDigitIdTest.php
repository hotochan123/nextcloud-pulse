<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\AnswerRules;
use OCA\Pulse\Service\CodeGenerator;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\QuizService;
use OCA\Pulse\Service\TallyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Multiple-answer questions created before option IDs always got a letter: an ID
 * made only of digits ("8423") was stored as the number 8423 in the answer key and
 * in the votes, because PHP had collected the IDs as array keys. Reading them back
 * as strings makes such a question behave like a new one, without touching the data.
 */
#[CoversClass(Poll::class)]
#[CoversClass(Vote::class)]
#[CoversClass(AnswerRules::class)]
class LegacyDigitIdTest extends TestCase {

    private function multiPoll(?string $answerKey): Poll {
        $poll = new Poll();
        $poll->setId(7);
        $poll->setRoomId(1);
        $poll->setType('multi');
        $poll->setOptions(json_encode([['id' => '8423', 'label' => 'Eight'], ['id' => 'AB2C', 'label' => 'Other'], ['id' => 'ZZ9Q', 'label' => 'Third']]));
        $poll->setAnswerKey($answerKey);
        return $poll;
    }

    private function vote(mixed $value): Vote {
        $vote = new Vote();
        $vote->setPayload(json_encode(['value' => $value]));
        return $vote;
    }

    private function rules(): AnswerRules {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        return new AnswerRules($l10n, new QuizService());
    }

    public function testStoredNumericKeyIsReadAsStrings(): void {
        $poll = $this->multiPoll('{"correct":[8423,"AB2C"]}');
        $this->assertSame(['8423', 'AB2C'], $poll->getAnswerKeyArray()['correct']);
    }

    public function testOtherKeysAreUntouched(): void {
        $poll = $this->multiPoll('{"target":42,"tolerance":1}');
        $this->assertSame(['target' => 42, 'tolerance' => 1], $poll->getAnswerKeyArray());
        $poll->setAnswerKey('{"order":["B1","A1"]}');
        $this->assertSame(['order' => ['B1', 'A1']], $poll->getAnswerKeyArray());
        $poll->setAnswerKey(null);
        $this->assertSame([], $poll->getAnswerKeyArray());
    }

    public function testStoredNumericVoteIsReadAsStrings(): void {
        $this->assertSame(['8423', 'AB2C'], $this->vote([8423, 'AB2C'])->getValueFor('multi'));
    }

    public function testOtherTypesKeepTheVoteAsStored(): void {
        // Only multiple answers had the integer IDs; a word cloud keeps skipping
        // a non-string entry (TallyServiceTest::testWordCloudSkipsEmptyAndNonStrings).
        $this->assertSame([42, 'hi'], $this->vote([42, 'hi'])->getValueFor('words'));
        $this->assertSame(['AB2C' => 3], $this->vote(['AB2C' => 3])->getValueFor('scale'));
        $this->assertSame(42, $this->vote(42)->getValueFor('number'));
        $this->assertSame('8423', $this->vote('8423')->getValueFor('choice'));
        $this->assertSame([8423], $this->vote([8423])->getValue(), 'getValue itself is unchanged');
    }

    public function testNewVoteOnDigitIdIsStoredAsString(): void {
        $poll = $this->multiPoll('{"correct":[8423]}');
        $this->assertSame(['8423'], $this->rules()->normalizeValue($poll, ['8423']));
        $this->assertSame(['8423', 'AB2C'], $this->rules()->normalizeValue($poll, ['AB2C', '8423']));
    }

    public function testNewVoteIsGradedAgainstTheOldNumericKey(): void {
        $poll = $this->multiPoll('{"correct":[8423]}');
        $rules = $this->rules();
        $right = $rules->quizPayload($poll, $rules->normalizeValue($poll, ['8423']), 1000, 20);
        $this->assertTrue($right['correct']);
        $wrong = $rules->quizPayload($poll, $rules->normalizeValue($poll, ['AB2C']), 1000, 20);
        $this->assertFalse($wrong['correct']);
        $poll->setAnswerKey('{"correct":[8423,"ZZ9Q"]}');
        $both = $rules->quizPayload($poll, $rules->normalizeValue($poll, ['ZZ9Q', '8423']), 1000, 20);
        $this->assertTrue($both['correct'], 'mixed key, digits sort among letters the same on both sides');
    }

    public function testOldNumericVoteIsCountedInTheTally(): void {
        $poll = $this->multiPoll('{"correct":[8423]}');
        $tally = (new TallyService())->tally($poll, [$this->vote([8423]), $this->vote(['8423', 'AB2C'])]);
        $counts = array_column($tally['results'], 'count', 'id');
        $this->assertSame(2, $counts['8423']);
        $this->assertSame(1, $counts['AB2C']);
    }

    public function testComposerStoresADigitIdAsString(): void {
        $poll = new Poll();
        $poll->setId(7);
        $poll->setRoomId(1);
        $poll->setType('multi');
        $poll->setStatus('active');

        $polls = $this->createMock(PollMapper::class);
        $polls->method('find')->willReturn($poll);
        $polls->method('update')->willReturnArgument(0);
        $ids = ['8423', 'AB2C', 'ZZ9Q'];
        $codes = $this->createMock(CodeGenerator::class);
        $codes->method('optionId')->willReturnCallback(static function () use (&$ids): string {
            return array_shift($ids) ?? 'OPTX';
        });
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(5000);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $service = (new \ReflectionClass(DeckService::class))->newInstanceWithoutConstructor();
        foreach ([
            'pollMapper' => $polls,
            'voteMapper' => $this->createMock(VoteMapper::class),
            'codeGenerator' => $codes,
            'timeFactory' => $time,
            'l10n' => $l10n,
            'limits' => new \OCA\Pulse\Service\Limits($this->createMock(\OCP\IAppConfig::class)),
        ] as $name => $value) {
            (new ReflectionProperty(DeckService::class, $name))->setValue($service, $value);
        }
        $room = new Room();
        $room->setId(1);
        $room->setMode('quiz');
        $room->setActivePollId(0);

        $service->updatePoll($room, 7, ['type' => 'multi', 'question' => 'Which?', 'options' => ['Eight', 'Other', 'Third'], 'correctIndexes' => [0, 2], 'timeLimit' => 20]);

        $this->assertSame('{"correct":["8423","ZZ9Q"]}', $poll->getAnswerKey());
    }
}
