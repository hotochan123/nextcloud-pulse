<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Player;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Anonymous input is bounded BEFORE the Unicode work runs over it.
 *
 * Cleaning a word costs a dozen Unicode passes (normalisers, regexes), and
 * nothing else bounds the body of a public request: a word-cloud vote with
 * millions of one-letter words used to pin a PHP worker for seconds, because
 * every entry was cleaned before the cut to maxWords. Now a list far longer
 * than the phone ever sends is rejected first, each entry is cut to 200
 * characters before it is cleaned, and the loop stops at maxWords distinct
 * words. Free text and nicknames are cut to four times their length before
 * their regexes. What the phone sends comes out exactly as before.
 */
#[CoversClass(VoteService::class)]
class WordVoteBoundsTest extends TestCase {

    private VoteService $service;
    /** @var list<string> nicknames that reached register() */
    private array $registered = [];

    protected function setUp(): void {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1000);

        $players = $this->createMock(PlayerMapper::class);
        $players->method('findByRoom')->willReturn([]);
        $players->method('register')->willReturnCallback(function (int $roomId, string $token, string $nickname): Player {
            $this->registered[] = $nickname;
            $p = new Player();
            $p->setNickname($nickname);
            return $p;
        });
        $pace = $this->createMock(PaceService::class);
        $pace->method('locked')->willReturnCallback(static fn (Room $room, callable $fn): mixed => $fn($room));
        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturn([]);

        $this->service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        foreach ([
            'l10n' => $l10n,
            'timeFactory' => $time,
            'playerMapper' => $players,
            'pollMapper' => $polls,
            'voteMapper' => $this->createMock(VoteMapper::class),
            'paceService' => $pace,
            'limits' => new \OCA\Pulse\Service\Limits($this->createMock(\OCP\IAppConfig::class)),
        ] as $name => $value) {
            (new ReflectionProperty(VoteService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── Word cloud: the list ───────────────────────────────────────────────

    public function testHugeWordListIsRejectedImmediately(): void {
        $flood = array_fill(0, 200_000, 'a');

        $start = hrtime(true);
        try {
            $this->service->normalizeValue($this->wordsPoll(3), $flood);
            $this->fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Invalid words.', $e->getMessage());
        }
        // Before any per-word work (cleaning 200,000 entries took hundreds of milliseconds).
        $this->assertLessThan(0.05, (hrtime(true) - $start) / 1e9);
    }

    public function testTwentyEntriesAreTheLowerBound(): void {
        // maxWords 3 -> max(20, 6) = 20 entries still count (empty fields and the like).
        $value = array_merge(['Kaffee'], array_fill(0, 19, ''));

        $this->assertSame(['Kaffee'], $this->service->normalizeValue($this->wordsPoll(3), $value));
    }

    public function testTwentyOneEntriesAreNoVote(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid words.');
        $this->service->normalizeValue($this->wordsPoll(3), array_fill(0, 21, 'Kaffee'));
    }

    public function testLimitGrowsWithTwiceMaxWords(): void {
        // A (hand-edited) question with 15 words: 30 entries pass, 31 do not.
        $poll = $this->wordsPoll(15);
        $this->assertSame(['Kaffee'], $this->service->normalizeValue($poll, array_fill(0, 30, 'Kaffee')));

        $this->expectExceptionMessage('Invalid words.');
        $this->service->normalizeValue($poll, array_fill(0, 31, 'Kaffee'));
    }

    public function testStopAtMaxWordsGivesTheSameWords(): void {
        // The first maxWords distinct words in order, first spelling — as the old cut afterwards.
        $words = $this->service->normalizeValue($this->wordsPoll(2), ['Tee', 'TEE', 'Kaffee', 'Kakao', 'Wasser']);

        $this->assertSame(['Tee', 'Kaffee'], $words);
    }

    public function testDuplicatesDoNotCountAsASlot(): void {
        $words = $this->service->normalizeValue($this->wordsPoll(3), ['Tee', 'tee', ' TEE ', 'Kaffee', "\u{200B}", 'Kakao']);

        $this->assertSame(['Tee', 'Kaffee', 'Kakao'], $words);
    }

    // ── Word cloud: one entry ──────────────────────────────────────────────

    public function testLongWordIsCutToFortyCharacters(): void {
        $words = $this->service->normalizeValue($this->wordsPoll(1), str_repeat('x', 1_000_000));

        $this->assertSame([str_repeat('x', 40)], $words);
    }

    public function testEntryIsCutBeforeCleaning(): void {
        // 200 invisible characters in front: the cut keeps only those, nothing visible remains.
        $this->expectExceptionMessage('Please enter at least one word.');
        $this->service->normalizeValue($this->wordsPoll(1), str_repeat("\u{200B}", 200) . 'Kaffee');
    }

    public function testShortBrokenUtf8IsDroppedAsBefore(): void {
        $this->assertSame(['Kaffee'], $this->service->normalizeValue($this->wordsPoll(2), ["\xFF\xFE", 'Kaffee']));
    }

    public function testLongBrokenUtf8DoesNotBecomeAQuestionMark(): void {
        // mb_substr would turn broken bytes into '?' — a word nobody typed.
        $this->assertSame(['Kaffee'], $this->service->normalizeValue($this->wordsPoll(2), [str_repeat("\xFF", 300), 'Kaffee']));
    }

    // ── Free text ──────────────────────────────────────────────────────────

    public function testHugeFreeTextIsCut(): void {
        $text = $this->service->normalizeValue($this->textPoll(), str_repeat('Antwort ', 500_000));

        $this->assertSame(mb_substr(str_repeat('Antwort ', 20), 0, 100), $text);
    }

    public function testFreeTextWithMultibyteCharactersStaysUncut(): void {
        // 100 characters, 200 bytes: fits the limit in characters, not in bytes.
        $this->assertSame(str_repeat('ä', 100), $this->service->normalizeValue($this->textPoll(), str_repeat('ä', 100)));
    }

    public function testLongBrokenFreeTextIsEmpty(): void {
        $this->expectExceptionMessage('Please enter an answer.');
        $this->service->normalizeValue($this->textPoll(), str_repeat("\xFF", 1000));
    }

    // ── Nickname ───────────────────────────────────────────────────────────

    public function testHugeNameIsCutToTwentyFourCharacters(): void {
        $this->service->quizJoin($this->quizRoom(), 'tok-neu', str_repeat('Anna', 1_000_000));

        $this->assertSame([str_repeat('Anna', 6)], $this->registered);
    }

    public function testLongBrokenNameIsEmpty(): void {
        $this->expectExceptionMessage('Please enter a name.');
        $this->service->quizJoin($this->quizRoom(), 'tok-neu', str_repeat("\xFF", 1000));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function wordsPoll(int $maxWords): Poll {
        $poll = new Poll();
        $poll->setType('words');
        $poll->setMaxWords($maxWords);
        return $poll;
    }

    private function textPoll(): Poll {
        $poll = new Poll();
        $poll->setType('text');
        return $poll;
    }

    private function quizRoom(): Room {
        $room = new Room();
        $room->setId(1);
        $room->setMode('quiz');
        return $room;
    }
}
