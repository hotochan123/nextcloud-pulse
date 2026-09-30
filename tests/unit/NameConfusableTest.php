<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Player;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Progress;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\PaceService;
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
 * Nicknames that only LOOK like another player's are taken as well.
 *
 * Case and invisible characters were already folded, but "Ａｎｎａ"
 * (fullwidth), "Αnna" (Greek Alpha) or "Аnna" (Cyrillic A) got in next to
 * "Anna" — an impersonation on the projector leaderboard and in the CSV the
 * teacher grades by name. TallyService::nameKey now folds NFKC and a small
 * table of Greek, Cyrillic and Latin lookalikes (deterministic, also without
 * intl); TallyService::namesClash adds Unicode's confusables data through
 * intl's Spoofchecker where it exists ("PauI" with a capital I, "rn" for
 * "m"). Names that already exist keep their spelling — only new names and
 * renames are checked, and so is every other spelling of one's own name:
 * nameKey folds less than namesClash, so "paui" could otherwise turn into
 * "PauI" next to "Paul" after answering or after the final standings, which
 * the rename freeze lets through (same nameKey).
 *
 * Code points that browsers render as nothing count as nothing, all of
 * Unicode's default-ignorables included — also the ones not assigned yet
 * ("Anna" + U+FFF0 or U+E0080 looked exactly like "Anna").
 */
#[CoversClass(TallyService::class)]
#[CoversClass(VoteService::class)]
class NameConfusableTest extends TestCase {

    private const TAKEN = 'This name is already taken. Please choose another one.';

    private PlayerMapper&MockObject $players;
    private VoteService $service;
    /** @var Player[] */
    private array $roster = [];
    /** @var Poll[] the room's questions (name freeze after the end) */
    private array $polls = [];
    /** @var list<string> tokens with an answer in the room (live name freeze) */
    private array $voted = [];
    /** @var list<string> tokens that started (self-paced name freeze) */
    private array $started = [];

    protected function setUp(): void {
        $this->roster = [$this->player('tok-anna', 'Anna'), $this->player('tok-paul', 'Paul')];
        $this->players = $this->createMock(PlayerMapper::class);
        $this->players->method('findByRoom')->willReturnCallback(fn (): array => $this->roster);

        $pace = $this->createMock(PaceService::class);
        $pace->method('locked')->willReturnCallback(static fn (Room $room, callable $fn): mixed => $fn($room));
        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturnCallback(fn (): array => $this->polls);
        $progress = $this->createMock(ProgressMapper::class);
        $progress->method('findByRoomAndToken')->willReturnCallback(
            fn (int $room, string $token): array => in_array($token, $this->started, true) ? [new Progress()] : []
        );
        $votes = $this->createMock(VoteMapper::class);
        $votes->method('findByPolls')->willReturnCallback(
            fn (array $ids, string $token): array => in_array($token, $this->voted, true) ? [new Vote()] : []
        );

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1000);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        foreach ([
            'playerMapper' => $this->players,
            'pollMapper' => $polls,
            'voteMapper' => $votes,
            'progressMapper' => $progress,
            'paceService' => $pace,
            'limits' => new \OCA\Pulse\Service\Limits($this->createMock(\OCP\IAppConfig::class)),
            'timeFactory' => $time,
            'l10n' => $l10n,
        ] as $name => $value) {
            (new ReflectionProperty(VoteService::class, $name))->setValue($this->service, $value);
        }
    }

    protected function tearDown(): void {
        // Back to "not checked yet" after the tests that pretend intl lacks it.
        (new ReflectionProperty(TallyService::class, 'spoofchecker'))->setValue(null, null);
    }

    // ── nameKey: deterministic fold ────────────────────────────────────────

    public static function sameNames(): array {
        return [
            'Vollbreite' => ['Anna', "\u{FF21}\u{FF4E}\u{FF4E}\u{FF41}"],
            'griechisches Alpha' => ['Anna', "\u{0391}nna"],
            'kyrillisches A' => ['Anna', "\u{0410}nna"],
            'kyrillisches a klein' => ['Anna', "Ann\u{0430}"],
            'kyrillisches O und E' => ['Oleg', "\u{041E}l\u{0435}g"],
            'kyrillisches H' => ['Hans', "\u{041D}ans"],
            'griechisches o klein' => ['Tom', "T\u{03BF}m"],
            'punktloses i' => ['Mia', "M\u{0131}a"],
            'eingekreist' => ['Anna', "\u{24B6}nna"],
            'mathematisch fett' => ['Anna', "\u{1D400}nna"],
            'Ligatur' => ['Finn', "\u{FB01}nn"],
            'kyrillisch mit Trema' => ["\u{00CB}va", "\u{0401}va"],
            'Gross und klein bleibt' => ['anna', 'ANNA'],
            'Akzent zerlegt' => ["Ren\u{00E9}", "Rene\u{0301}"],
        ];
    }

    #[DataProvider('sameNames')]
    public function testLookalikesHaveTheSameKey(string $a, string $b): void {
        $this->assertSame(TallyService::nameKey($a), TallyService::nameKey($b));
    }

    public static function differentNames(): array {
        return [
            'Anna / Anne' => ['Anna', 'Anne'],
            'Lea / Lena' => ['Lea', 'Lena'],
            'Umlaut' => ["M\u{00FC}ller", 'Muller'],
            'griechischer Name' => ["\u{039D}\u{03AF}\u{03BA}\u{03BF}\u{03C2}", 'Nikos'],
            'kyrillischer Name' => ["\u{0410}\u{043D}\u{043D}\u{0430}", 'Anna'],
            'Ziffer statt O' => ['Tom', 'T0m'],
        ];
    }

    #[DataProvider('differentNames')]
    public function testRealDifferencesStay(string $a, string $b): void {
        $this->assertNotSame(TallyService::nameKey($a), TallyService::nameKey($b));
    }

    public function testInvisibleStaysEmpty(): void {
        $this->assertSame('', TallyService::nameKey("\u{FE0F}\u{034F}\u{200D}"));
        $this->assertSame('', TallyService::nameKey("\u{2800}\u{3000}"));
    }

    /** Default-ignorable code points that are not assigned yet, and relatives. */
    public static function reservedInvisibles(): array {
        return [
            'U+FFF0' => ["\u{FFF0}"],
            'U+FFF8' => ["\u{FFF8}"],
            'U+2065' => ["\u{2065}"],
            'U+E0000' => ["\u{E0000}"],
            'U+E0002' => ["\u{E0002}"],
            'U+E0080' => ["\u{E0080}"],
            'U+E01F0' => ["\u{E01F0}"],
            'U+E0FFF' => ["\u{E0FFF}"],
            'U+1BCA0 (shorthand format control)' => ["\u{1BCA0}"],
        ];
    }

    #[DataProvider('reservedInvisibles')]
    public function testReservedInvisibleCharactersAreDropped(string $invisible): void {
        $this->assertSame('Anna', TallyService::cleanText('Anna' . $invisible));
        $this->assertSame('anna', TallyService::nameKey('An' . $invisible . 'na'));
        $this->assertTrue(TallyService::namesClash('Anna' . $invisible, 'Anna'));
    }

    #[DataProvider('reservedInvisibles')]
    public function testReservedInvisibleCharactersOnJoin(string $invisible): void {
        $this->players->expects($this->never())->method('register');

        $this->expectExceptionMessage(self::TAKEN);
        $this->service->quizJoin($this->room('live'), 'tok-neu', 'Anna' . $invisible);
    }

    public function testEveryDefaultIgnorableIsInvisibleForNames(): void {
        // Against Unicode's own list (PCRE: \p{DI}), not against the one in
        // TallyService: planes 0, 1, 2 and 14 hold every such code point.
        if (@preg_match('/\p{DI}/u', 'a') === false) {
            $this->markTestSkipped('PCRE without the DI property');
        }
        $missed = [];
        foreach ([[0, 0x2FFFF], [0xE0000, 0xE0FFF]] as [$from, $to]) {
            for ($cp = $from; $cp <= $to; $cp++) {
                if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                    continue;
                }
                $char = mb_chr($cp, 'UTF-8');
                if (preg_match('/\p{DI}/u', $char) === 1 && TallyService::nameKey('Anna' . $char) !== 'anna') {
                    $missed[] = sprintf('U+%04X', $cp);
                }
            }
        }
        $this->assertSame([], $missed);
    }

    public function testVisibleSpecialCharactersStay(): void {
        // U+FFFC/FFFD are drawn (a box, a question mark) — a different name.
        $this->assertFalse(TallyService::namesClash("Anna\u{FFFD}", 'Anna'));
        $this->assertSame("Anna\u{FFFC}", TallyService::cleanText("Anna\u{FFFC}"));
    }

    // ── namesClash: Spoofchecker where it exists ───────────────────────────

    public function testSpoofcheckerDetectsCapitalIForSmallL(): void {
        if (!class_exists(\Spoofchecker::class)) {
            $this->markTestSkipped('intl without Spoofchecker');
        }
        $this->assertTrue(TallyService::namesClash('PauI', 'Paul'));
        $this->assertTrue(TallyService::namesClash('Tirn', 'Tim'));
        $this->assertFalse(TallyService::namesClash('Paula', 'Paul'));
        $this->assertFalse(TallyService::namesClash('Lena', 'Lea'));
    }

    public function testWithoutSpoofcheckerTheTableApplies(): void {
        (new ReflectionProperty(TallyService::class, 'spoofchecker'))->setValue(null, false);

        $this->assertTrue(TallyService::namesClash("\u{0391}nna", 'Anna'));
        $this->assertTrue(TallyService::namesClash("\u{FF21}nna", 'anna'));
        // "I"/"l" needs the confusables data — not folded by the table.
        $this->assertFalse(TallyService::namesClash('PauI', 'Paul'));
    }

    public function testClashInReturnsTheFirstMatch(): void {
        $this->assertSame(2, TallyService::clashIn("\u{0410}nna", ['Ben', 'Cem', 'Anna', 'ANNA']));
        $this->assertNull(TallyService::clashIn('Dora', ['Ben', 'Cem']));
    }

    // ── Joining ────────────────────────────────────────────────────────────

    public static function lookalikeNames(): array {
        return [
            'Vollbreite' => ["\u{FF21}\u{FF4E}\u{FF4E}\u{FF41}"],
            'griechisch' => ["\u{0391}nna"],
            'kyrillisch' => ["\u{0410}nna"],
            'kyrillisch gross' => ["\u{0410}NN\u{0410}"],
        ];
    }

    #[DataProvider('lookalikeNames')]
    public function testLookalikeRefusedInLiveQuiz(string $name): void {
        $this->players->expects($this->never())->method('register');

        $this->expectExceptionMessage(self::TAKEN);
        $this->service->quizJoin($this->room('live'), 'tok-neu', $name);
    }

    #[DataProvider('lookalikeNames')]
    public function testLookalikeRefusedInSelfPacedQuiz(string $name): void {
        $this->players->expects($this->never())->method('register');

        $this->expectExceptionMessage(self::TAKEN);
        $this->service->quizJoin($this->room('self'), 'tok-neu', $name);
    }

    public function testRenamingToALookalikeRefused(): void {
        $this->players->expects($this->never())->method('register');

        $this->expectExceptionMessage(self::TAKEN);
        $this->service->quizJoin($this->room('live'), 'tok-paul', "\u{0410}nna");
    }

    public function testExistingLookalikeKeepsItsName(): void {
        // Joined before this rule: re-sending the own name still works.
        $this->roster[] = $this->player('tok-fake', "\u{0410}nna");
        $this->players->expects($this->once())->method('register')
            ->with(1, 'tok-fake', "\u{0410}nna", 1000)
            ->willReturn(new Player());

        $this->service->quizJoin($this->room('live'), 'tok-fake', "\u{0410}nna");
    }

    public function testSpoofcheckerOnJoin(): void {
        if (!class_exists(\Spoofchecker::class)) {
            $this->markTestSkipped('intl without Spoofchecker');
        }
        $this->players->expects($this->never())->method('register');

        $this->expectExceptionMessage(self::TAKEN);
        $this->service->quizJoin($this->room('live'), 'tok-neu', 'PauI');
    }

    // ── Other spellings of one's own name ──────────────────────────────────

    /** @return array<string, array{string, \Closure(self): void}> */
    public static function frozen(): array {
        return [
            'live, nach der Antwort' => ['live', static function (self $t): void {
                $t->voted = ['tok-x'];
            }],
            'live, nach dem Quizende' => ['live', static function (self $t): void {
                $poll = new Poll();
                $poll->setId(3);
                $poll->setStatus('ended');
                $t->polls = [$poll];
            }],
            'eigenes Tempo, nach dem Start' => ['self', static function (self $t): void {
                $t->started = ['tok-x'];
            }],
            'ohne Einfrieren (Lobby)' => ['live', static function (self $t): void {
            }],
        ];
    }

    #[DataProvider('frozen')]
    public function testSameKeyDoesNotBecomeALookalike(string $pace, \Closure $freeze): void {
        if (!class_exists(\Spoofchecker::class)) {
            $this->markTestSkipped('intl without Spoofchecker');
        }
        // The audit's path: join as "paui" (free), answer, then "PauI" — the
        // same nameKey as "paui", so no rename in the freeze's eyes, but a
        // lookalike of "Paul" that a direct join is refused.
        $this->roster[] = $this->player('tok-x', 'paui');
        $freeze($this);
        $this->players->expects($this->never())->method('register');

        $this->expectExceptionMessage(self::TAKEN);
        $this->service->quizJoin($this->room($pace), 'tok-x', 'PauI');
    }

    #[DataProvider('frozen')]
    public function testSameKeyWithoutLookalikeStaysAllowed(string $pace, \Closure $freeze): void {
        $this->roster[] = $this->player('tok-x', 'paui');
        $freeze($this);
        $this->players->expects($this->once())->method('register')
            ->with(1, 'tok-x', 'PAUI', 1000)
            ->willReturn(new Player());

        $this->service->quizJoin($this->room($pace), 'tok-x', 'PAUI');
    }

    public function testOldLookalikeMayChangeItsSpelling(): void {
        // Let in by an older rule next to "Anna": another spelling of its own
        // name is checked against everyone else, not against "Anna".
        $this->roster[] = $this->player('tok-fake', "\u{0410}nna");
        $this->voted = ['tok-fake'];
        $this->players->expects($this->once())->method('register')
            ->with(1, 'tok-fake', "\u{0410}NN\u{0410}", 1000)
            ->willReturn(new Player());

        $this->service->quizJoin($this->room('live'), 'tok-fake', "\u{0410}NN\u{0410}");
    }

    public function testOwnSpellingIsStored(): void {
        // The fold is only for comparing: "Ｂｅｎ" is stored as typed.
        $this->players->expects($this->once())->method('register')
            ->with(1, 'tok-neu', "\u{FF22}\u{FF45}\u{FF4E}", 1000)
            ->willReturn(new Player());

        $this->service->quizJoin($this->room('live'), 'tok-neu', "\u{FF22}\u{FF45}\u{FF4E}");
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function room(string $pace): Room {
        $room = new Room();
        $room->setId(1);
        $room->setMode('quiz');
        $room->setPace($pace);
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
