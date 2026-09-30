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
use OCA\Pulse\Service\AnswerRules;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\QuizService;
use OCA\Pulse\Service\TallyService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Invisible characters in words and names (TallyService::cleanText).
 *
 * Phones like to send zero-width spaces, NBSP or an "é" as two
 * characters (macOS, NFD). Without cleaning, "Kaffee" and "Kaffee\u{200B}"
 * would be two words in the cloud and "Anna" would appear twice in the leaderboard —
 * visually identical, technically different.
 *
 * What gets removed is a fixed list (soft hyphen, zero-width space,
 * direction control characters, word joiner, BOM), NOT everything in \p{Cf}. Inside
 * a word these stay: U+200C (ZWNJ, Persian "می‌خواهم"), U+200D (ZWJ, holds
 * emoji such as 👩‍💻 together) and the tag characters U+E0020–E007F (regional flags
 * such as Scotland/England — without them both would just be the black flag 🏴).
 * At the edges, ZWNJ/ZWJ are dropped like whitespace.
 */
#[CoversClass(TallyService::class)]
#[CoversClass(AnswerRules::class)]
#[CoversClass(VoteService::class)]
class UnicodeTextTest extends TestCase {

    /** Persian "I want": ZWNJ between prefix and stem. */
    private const PERSIAN = "\u{0645}\u{06CC}\u{200C}\u{062E}\u{0648}\u{0627}\u{0647}\u{0645}";
    /** 🏴 + tag sequence "gbsct" + terminator. */
    private const SCOTLAND = "\u{1F3F4}\u{E0067}\u{E0062}\u{E0073}\u{E0063}\u{E0074}\u{E007F}";
    /** 🏴 + tag sequence "gbeng" + terminator. */
    private const ENGLAND = "\u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}";

    private PlayerMapper&MockObject $players;
    private AnswerRules $rules;
    private VoteService $service;

    protected function setUp(): void {
        $this->players = $this->createMock(PlayerMapper::class);
        $this->players->method('findByRoom')->willReturn([$this->player('tok-anna', 'Anna')]);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1000);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->rules = new AnswerRules($l10n, new QuizService());

        // Joining runs under the room lock (here: straight through) and looks
        // at the room's questions for the name freeze (none here).
        $pace = $this->createMock(PaceService::class);
        $pace->method('locked')->willReturnCallback(static fn (Room $room, callable $fn): mixed => $fn($room));
        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturn([]);

        $this->service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        foreach ([
            'playerMapper' => $this->players,
            'pollMapper' => $polls,
            'voteMapper' => $this->createMock(VoteMapper::class),
            'paceService' => $pace,
            'limits' => new \OCA\Pulse\Service\Limits($this->createMock(\OCP\IAppConfig::class)),
            'timeFactory' => $time,
            'l10n' => $l10n,
        ] as $name => $value) {
            (new ReflectionProperty(VoteService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── cleanText ──────────────────────────────────────────────────────────

    public static function cleanedTexts(): array {
        return [
            'zero width at the end' => ["Kaffee\u{200B}", 'Kaffee'],
            'zero width in the middle' => ["Kaf\u{200B}fee", 'Kaffee'],
            'BOM in front' => ["\u{FEFF}Kaffee", 'Kaffee'],
            'direction marks' => ["\u{200F}Kaffee\u{200E}", 'Kaffee'],
            'direction embedding' => ["\u{202B}Kaffee\u{202C}", 'Kaffee'],
            'direction isolate' => ["\u{2067}Kaffee\u{2069}", 'Kaffee'],
            'soft hyphen' => ["Kaf\u{00AD}fee", 'Kaffee'],
            'word joiner' => ["Kaf\u{2060}fee", 'Kaffee'],
            'invisible times' => ["Kaf\u{2062}fee", 'Kaffee'],
            'Mongolian vowel separator' => ["\u{180E}Kaffee", 'Kaffee'],
            'NBSP at the edges' => ["\u{00A0}Kaffee\u{00A0}", 'Kaffee'],
            'ideographic space' => ["\u{3000}コーヒー\u{3000}", 'コーヒー'],
            'narrow NBSP' => ["\u{202F}Tee\u{2009}", 'Tee'],
            'NBSP in the middle stays' => ["Café\u{00A0}Latte", "Café\u{00A0}Latte"],
            'NFD becomes NFC' => ["Cafe\u{0301}", "Caf\u{00E9}"],
            'emoji with joiner stays' => ["\u{1F469}\u{200D}\u{1F4BB}", "\u{1F469}\u{200D}\u{1F4BB}"],
            'joiner at the edge is dropped' => ["\u{200D}Tee\u{200D}", 'Tee'],
            'ZWNJ in a Persian word stays' => [self::PERSIAN, self::PERSIAN],
            'ZWNJ at the edge is dropped' => ["\u{200C}" . self::PERSIAN . "\u{200C}", self::PERSIAN],
            'Scotland flag stays whole' => [self::SCOTLAND, self::SCOTLAND],
            'flag with whitespace around it' => ["\u{00A0}" . self::ENGLAND . "\u{200B}", self::ENGLAND],
            'only invisible characters' => ["\u{200B}\u{00A0}\u{FEFF}\u{3000}\u{200D}", ''],
            'normal text unchanged' => ['Grüße, Welt!', 'Grüße, Welt!'],
        ];
    }

    #[DataProvider('cleanedTexts')]
    public function testCleanText(string $in, string $out): void {
        $this->assertSame($out, TallyService::cleanText($in));
    }

    public function testNormalizeWordUnitesNfdAndNfc(): void {
        $this->assertSame("caf\u{00E9}", TallyService::normalizeWord("CAFE\u{0301}"));
        $this->assertSame(TallyService::normalizeWord("caf\u{00E9}"), TallyService::normalizeWord("Cafe\u{0301}\u{200B}"));
    }

    public function testNormalizeWordWithInvisibleEdges(): void {
        $this->assertSame('kaffee', TallyService::normalizeWord("\u{3000}KAFFEE\u{00A0}\u{200B}"));
    }

    // ── Word cloud: vote validation ────────────────────────────────────────

    public function testOnlyInvisibleCharactersAreNoWord(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please enter at least one word.');
        $this->rules->normalizeValue($this->wordsPoll(3), ["\u{200B}", "\u{00A0}", "\u{FEFF}\u{200D}"]);
    }

    public function testInvisibleVariantsAreOneWord(): void {
        $words = $this->rules->normalizeValue($this->wordsPoll(3), ['Kaffee', "Kaffee\u{200B}", "\u{00A0}KAFFEE"]);

        $this->assertSame(['Kaffee'], $words);
    }

    public function testTheCleanedFormIsStored(): void {
        $words = $this->rules->normalizeValue($this->wordsPoll(3), ["\u{200B}Cafe\u{0301}\u{3000}"]);

        $this->assertSame(["Caf\u{00E9}"], $words);
    }

    public function testEmojiWithJoinerStaysOneWord(): void {
        $words = $this->rules->normalizeValue($this->wordsPoll(3), ["\u{1F469}\u{200D}\u{1F4BB}"]);

        $this->assertSame(["\u{1F469}\u{200D}\u{1F4BB}"], $words);
    }

    public function testPersianWordWithZwnjStaysOneWord(): void {
        // Without the ZWNJ it would be a different (misspelled) word.
        $words = $this->rules->normalizeValue($this->wordsPoll(3), [self::PERSIAN, "\u{200B}" . self::PERSIAN]);

        $this->assertSame([self::PERSIAN], $words);
        $this->assertStringContainsString("\u{200C}", $words[0]);
    }

    public function testScotlandFlagStaysOneWord(): void {
        $words = $this->rules->normalizeValue($this->wordsPoll(3), [self::SCOTLAND]);

        $this->assertSame([self::SCOTLAND], $words);
    }

    public function testEnglandAndScotlandAreTwoWords(): void {
        // With \p{Cf}, only 🏴 was left of both — the cloud would have merged them.
        $this->assertNotSame(TallyService::normalizeWord(self::ENGLAND), TallyService::normalizeWord(self::SCOTLAND));

        $words = $this->rules->normalizeValue($this->wordsPoll(3), [self::ENGLAND, self::SCOTLAND]);

        $this->assertSame([self::ENGLAND, self::SCOTLAND], $words);
    }

    public function testNonScalarValuesAreSkipped(): void {
        $words = $this->rules->normalizeValue($this->wordsPoll(3), [['Kaffee'], null, 'Tee', ['x' => 'y']]);

        $this->assertSame(['Tee'], $words);
    }

    public function testCutDoesNotEndOnWhitespace(): void {
        // Character 40 is a space — cleaning runs again after the cut.
        $word = str_repeat('x', 39) . ' Rest';
        $words = $this->rules->normalizeValue($this->wordsPoll(3), [$word]);

        $this->assertSame([str_repeat('x', 39)], $words);
    }

    public function testCutDoesNotEndOnNbsp(): void {
        $word = str_repeat('x', 39) . "\u{00A0}Rest";
        $words = $this->rules->normalizeValue($this->wordsPoll(3), [$word]);

        $this->assertSame([str_repeat('x', 39)], $words);
    }

    // ── Player names ───────────────────────────────────────────────────────

    public static function takenNamesWithInvisibleCharacters(): array {
        return [
            'zero width at the end' => ["Anna\u{200B}"],
            'zero width in the middle' => ["An\u{200B}na"],
            'NBSP edges' => ["\u{00A0}Anna\u{00A0}"],
            'U+3000 edge' => ["\u{3000}anna"],
            'BOM' => ["\u{FEFF}ANNA"],
            'variation selector' => ["Anna\u{FE0F}"],
            'text variation selector' => ["ANNA\u{FE0E}"],
            'grapheme joiner' => ["An\u{034F}na"],
            'Braille blank' => ["Anna\u{2800}"],
            'language tag' => ["\u{E0001}Anna"],
        ];
    }

    #[DataProvider('takenNamesWithInvisibleCharacters')]
    public function testInvisibleVariantOfATakenNameIsRejected(string $name): void {
        $this->players->expects($this->never())->method('register');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This name is already taken. Please choose another one.');
        $this->service->quizJoin($this->room(), 'tok-neu', $name);
    }

    public function testLegacyNameWithInvisibleCharacterCollides(): void {
        // A name from before the cleanup ("Ben\u{200B}") takes "Ben".
        $players = $this->createMock(PlayerMapper::class);
        $players->method('findByRoom')->willReturn([$this->player('tok-ben', "Ben\u{200B}")]);
        $players->expects($this->never())->method('register');
        (new ReflectionProperty(VoteService::class, 'playerMapper'))->setValue($this->service, $players);

        $this->expectExceptionMessage('This name is already taken. Please choose another one.');
        $this->service->quizJoin($this->room(), 'tok-neu', 'ben');
    }

    public function testZeroWidthBetweenSpacesGivesOneSpace(): void {
        // Invisible characters go first, then whitespace is collapsed — otherwise
        // a double space would remain that looks like a single one in the browser.
        $this->players->expects($this->once())->method('register')
            ->with(1, 'tok-neu', 'Anna Lena', 1000)
            ->willReturn($this->player('tok-neu', 'Anna Lena'));

        $this->service->quizJoin($this->room(), 'tok-neu', "Anna \u{200B} Lena");
    }

    public function testLegacyNameWithDoubleSpaceCollides(): void {
        $players = $this->createMock(PlayerMapper::class);
        $players->method('findByRoom')->willReturn([$this->player('tok-al', 'Anna  Lena')]);
        $players->expects($this->never())->method('register');
        (new ReflectionProperty(VoteService::class, 'playerMapper'))->setValue($this->service, $players);

        $this->expectExceptionMessage('This name is already taken. Please choose another one.');
        $this->service->quizJoin($this->room(), 'tok-neu', 'anna lena');
    }

    public function testJoinerBeforeAccentGivesTheSameName(): void {
        // The joiner blocks NFC; without it, "René" must come out.
        $this->assertSame(TallyService::nameKey("Ren\u{00E9}"), TallyService::nameKey("Rene\u{034F}\u{0301}"));
        $this->assertSame(TallyService::wordKey("Caf\u{00E9}"), TallyService::wordKey("Cafe\u{FE0F}\u{0301}"));
    }

    public function testNameOfOnlyVariationSelectorsIsRejected(): void {
        $this->players->expects($this->never())->method('register');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please enter a name.');
        $this->service->quizJoin($this->room(), 'tok-neu', "\u{FE0F}\u{034F}");
    }

    public function testNameOfOnlyBrailleBlanksIsRejected(): void {
        $this->players->expects($this->never())->method('register');

        $this->expectExceptionMessage('Please enter a name.');
        $this->service->quizJoin($this->room(), 'tok-neu', "\u{2800}\u{2800}");
    }

    public function testWordOfVariationSelectorAndJoinerIsNoWord(): void {
        $words = $this->rules->normalizeValue($this->wordsPoll(1), ["\u{FE0F}\u{200D}\u{FE0F}", "\u{2800}", 'Kaffee']);

        $this->assertSame(['Kaffee'], $words);
    }

    public function testWordOfOnlyAVariationSelectorTakesNoSlot(): void {
        $words = $this->rules->normalizeValue($this->wordsPoll(1), ["\u{034F}", 'Kaffee']);

        $this->assertSame(['Kaffee'], $words);
    }

    public function testNameOfOnlyInvisibleCharactersIsRejected(): void {
        $this->players->expects($this->never())->method('register');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please enter a name.');
        $this->service->quizJoin($this->room(), 'tok-neu', "\u{200B}\u{00A0}\u{3000}\u{FEFF}");
    }

    public function testKindsOfWhitespaceBecomeOneSpace(): void {
        $this->players->expects($this->once())->method('register')
            ->with(1, 'tok-neu', 'Anna Lena Maria', 1000)
            ->willReturn($this->player('tok-neu', 'Anna Lena Maria'));

        $this->service->quizJoin($this->room(), 'tok-neu', "Anna\u{00A0}\u{00A0}Lena\u{3000}Maria\u{200B}");
    }

    public function testCutTo24CharactersDoesNotEndOnASpace(): void {
        // Character 24 is the space before the surname.
        $first = str_repeat('a', 23);
        $this->players->expects($this->once())->method('register')
            ->with(1, 'tok-neu', $first, 1000)
            ->willReturn($this->player('tok-neu', $first));

        $this->service->quizJoin($this->room(), 'tok-neu', $first . ' Lena');
    }

    public function testCutCountsCharactersNotBytes(): void {
        $name = str_repeat('Ä', 30);
        $this->players->expects($this->once())->method('register')
            ->with(1, 'tok-neu', str_repeat('Ä', 24), 1000)
            ->willReturn($this->player('tok-neu', str_repeat('Ä', 24)));

        $this->service->quizJoin($this->room(), 'tok-neu', $name);
    }

    private function wordsPoll(int $maxWords): Poll {
        $poll = new Poll();
        $poll->setType('words');
        $poll->setMaxWords($maxWords);
        return $poll;
    }

    private function room(): Room {
        $room = new Room();
        $room->setId(1);
        $room->setMode('quiz');
        return $room;
    }

    private function player(string $token, string $nickname): Player {
        $player = new Player();
        $player->setRoomId(1);
        $player->setVoterToken($token);
        $player->setNickname($nickname);
        return $player;
    }
}
