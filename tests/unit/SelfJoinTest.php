<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Player;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Progress;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Self-paced join (VoteService::quizJoin -> selfJoin).
 *
 * Runs under the room lock (PaceService::locked) and checks against the freshly
 * locked row: no joining after closing (the next group would otherwise get
 * at the final standings and solutions), "Lock joining" and the cap of
 * 300 only affect new tokens, and after starting the name is fixed — otherwise
 * a token could slip into a freed-up name after answering.
 * A new token starts empty: it does not inherit leftovers of a removed player
 * with the same cookie. The moderated join only shares the room lock.
 */
#[CoversClass(VoteService::class)]
class SelfJoinTest extends TestCase {

    private const NOW = 1_800_000_000;

    private VoteService $service;
    private PlayerMapper&MockObject $players;
    private PaceService&MockObject $pace;

    /** What PaceService::locked passes to the callback — the authoritative row. */
    private Room $locked;
    /** @var Player[] */
    private array $roster = [];
    /** @var array<string, Progress[]> progress per token */
    private array $progress = [];
    private int $lockCalls = 0;

    protected function setUp(): void {
        $this->locked = $this->room(self::NOW - 100);
        $this->roster = [$this->player('tok-anna', 'Anna'), $this->player('tok-ben', 'Ben')];

        $this->pace = $this->createMock(PaceService::class);
        $this->pace->method('locked')->willReturnCallback(function (Room $room, callable $fn): mixed {
            $this->lockCalls++;
            return $fn($this->locked);
        });

        $this->players = $this->createMock(PlayerMapper::class);
        $this->players->method('findByRoom')->willReturnCallback(fn (): array => $this->roster);

        $progress = $this->createMock(ProgressMapper::class);
        $progress->method('findByRoomAndToken')->willReturnCallback(
            fn (int $roomId, string $token): array => $this->progress[$token] ?? [],
        );
        $progress->method('findByRoom')->willReturnCallback(
            fn (): array => array_merge([], ...array_values($this->progress)),
        );

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        foreach ([
            'playerMapper' => $this->players,
            'timeFactory' => $time,
            'l10n' => $l10n,
            'paceService' => $this->pace,
            'limits' => new \OCA\Pulse\Service\Limits($this->createMock(\OCP\IAppConfig::class)),
            'progressMapper' => $progress,
            'cacheFactory' => $this->createMock(ICacheFactory::class),
        ] as $name => $value) {
            (new ReflectionProperty(VoteService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── Window state ───────────────────────────────────────────────────────

    public static function closedWindows(): array {
        return [
            // closesAt, closedAt, releasedAt
            'manuell geschlossen' => [0, self::NOW - 1, 0],
            'Frist abgelaufen' => [self::NOW, 0, 0],
            'freigegeben' => [0, self::NOW - 1, self::NOW - 1],
        ];
    }

    #[DataProvider('closedWindows')]
    public function testNoJoinAfterClosing(int $closesAt, int $closedAt, int $releasedAt): void {
        $this->locked = $this->room(self::NOW - 100, $closesAt, $closedAt, $releasedAt);

        $this->assertRejected('The quiz is closed.', 'tok-neu', 'Cem');
    }

    public function testKnownPlayersNotAfterClosingEither(): void {
        $this->locked = $this->room(self::NOW - 100, 0, self::NOW - 1);

        $this->assertRejected('The quiz is closed.', 'tok-anna', 'Anna');
    }

    public function testAllowedInDraft(): void {
        $this->locked = $this->room(0);
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');
    }

    public function testAllowedInOpenWindow(): void {
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');
    }

    public function testStateCountsOnTheLockedRow(): void {
        // The room passed in is stale (open); read with the lock it is already closed.
        $this->locked = $this->room(self::NOW - 100, 0, self::NOW - 1);
        $this->players->expects($this->never())->method('register');

        try {
            $this->service->quizJoin($this->room(self::NOW - 100), 'tok-neu', 'Cem');
            $this->fail('InvalidArgumentException erwartet');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('The quiz is closed.', $e->getMessage());
        }
        $this->assertSame(1, $this->lockCalls);
    }

    public function testRunsUnderTheRoomLock(): void {
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');

        $this->assertSame(1, $this->lockCalls);
    }

    // ── Lock joining, cap ──────────────────────────────────────────────────

    public function testLockedNewTokenIsRejected(): void {
        $this->locked->setJoinsLocked(true);

        $this->assertRejected('Joining is closed for this quiz.', 'tok-neu', 'Cem');
    }

    public function testLockedKnownTokenStillGetsIn(): void {
        // Phone reloaded, name confirmed again.
        $this->locked->setJoinsLocked(true);
        $this->expectRegister('tok-anna', 'Anna');

        $this->join('tok-anna', 'Anna');
    }

    public function testLockedBeforeTheNameCheck(): void {
        // A throwaway token does not even learn which names are taken.
        $this->locked->setJoinsLocked(true);

        $this->assertRejected('Joining is closed for this quiz.', 'tok-neu', 'anna');
    }

    public function testFullRoomRejectsNewTokens(): void {
        // The cap counts players who have started (SelfJoinCapTest has the rest).
        $this->roster = $this->manyPlayers(PaceService::MAX_PLAYERS);
        foreach ($this->roster as $p) {
            $this->progress[$p->getVoterToken()] = [$this->progressRow($p->getVoterToken())];
        }

        $this->assertRejected('This quiz is full.', 'tok-neu', 'Cem');
    }

    public function testOneFreeSlotStillWorks(): void {
        $this->roster = $this->manyPlayers(PaceService::MAX_PLAYERS - 1);
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');
    }

    public function testFullRoomLetsKnownPlayersIn(): void {
        $this->roster = $this->manyPlayers(PaceService::MAX_PLAYERS);
        $this->expectRegister('tok-7', 'P7');

        $this->join('tok-7', 'P7');
    }

    // ── Name after starting ────────────────────────────────────────────────

    public function testRenameAfterStartIsRejected(): void {
        $this->progress['tok-anna'] = [$this->progressRow('tok-anna')];

        $this->assertRejected('You can\'t change your name after starting.', 'tok-anna', 'Anna2');
    }

    public function testRenameBeforeStartIsAllowed(): void {
        $this->expectRegister('tok-anna', 'Annika');

        $this->join('tok-anna', 'Annika');
    }

    public function testCaseOfOwnNameStaysChangeableAfterStart(): void {
        $this->progress['tok-anna'] = [$this->progressRow('tok-anna')];
        $this->expectRegister('tok-anna', 'ANNA');

        $this->join('tok-anna', 'ANNA');
    }

    public function testDuplicateNameIsRejected(): void {
        $this->assertRejected('This name is already taken. Please choose another one.', 'tok-neu', ' anna ');
    }

    public function testRenameToAnotherPlayersNameIsRejected(): void {
        $this->assertRejected('This name is already taken. Please choose another one.', 'tok-ben', 'ANNA');
    }

    public function testEmptyNameIsRejected(): void {
        $this->assertRejected('Please enter a name.', 'tok-neu', "\u{200B} ");
    }

    // ── A new identity starts empty ────────────────────────────────────────

    public function testNewTokenInOpenWindowStartsEmpty(): void {
        // Say, the cookie of a removed person: whatever of theirs is left goes away —
        // under the lock and before registering, question 1 with a new clock.
        $calls = [];
        $this->pace->expects($this->once())->method('forgetToken')
            ->with($this->identicalTo($this->locked), 'tok-neu')
            ->willReturnCallback(function () use (&$calls): void {
                $calls[] = 'forget';
            });
        $this->players->expects($this->once())->method('register')
            ->willReturnCallback(function () use (&$calls): Player {
                $calls[] = 'register';
                return $this->player('tok-neu', 'Cem');
            });

        $this->join('tok-neu', 'Cem');

        $this->assertSame(['forget', 'register'], $calls);
    }

    public function testKnownTokenKeepsItsState(): void {
        $this->pace->expects($this->never())->method('forgetToken');
        $this->expectRegister('tok-anna', 'Anna');

        $this->join('tok-anna', 'Anna');
    }

    public function testNothingToForgetInDraft(): void {
        $this->locked = $this->room(0);
        $this->pace->expects($this->never())->method('forgetToken');
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');
    }

    public function testRejectedForgetsNothing(): void {
        $this->locked->setJoinsLocked(true);
        $this->pace->expects($this->never())->method('forgetToken');

        $this->assertRejected('Joining is closed for this quiz.', 'tok-neu', 'Cem');
    }

    // ── Moderated mode ─────────────────────────────────────────────────────

    public function testModeratedOnlyTheRoomLockNoSelfPacedPaths(): void {
        // Moderated the join takes the room lock too (LiveQuizJoinTest), but
        // never the self-paced paths: no progress, no forgetToken, no
        // started-players cap — 599 joined players, far more than
        // MAX_PLAYERS, still let a new one in (the moderated ceiling is
        // MAX_JOINED, see LiveQuizJoinTest).
        $room = $this->room(0);
        $room->setPace('live');
        $this->locked = $room;
        $this->pace->expects($this->never())->method('forgetToken');
        $progress = $this->createMock(ProgressMapper::class);
        $progress->expects($this->never())->method($this->anything());
        (new ReflectionProperty(VoteService::class, 'progressMapper'))->setValue($this->service, $progress);
        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturn([]);
        (new ReflectionProperty(VoteService::class, 'pollMapper'))->setValue($this->service, $polls);
        (new ReflectionProperty(VoteService::class, 'voteMapper'))->setValue($this->service, $this->createMock(VoteMapper::class));
        $this->roster = $this->manyPlayers(PaceService::MAX_JOINED - 1);
        $this->expectRegister('tok-neu', 'Cem');

        $this->service->quizJoin($room, 'tok-neu', 'Cem');

        $this->assertSame(1, $this->lockCalls);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function join(string $token, string $nickname): Player {
        return $this->service->quizJoin($this->room(self::NOW - 100), $token, $nickname);
    }

    private function assertRejected(string $message, string $token, string $nickname): void {
        $this->players->expects($this->never())->method('register');
        try {
            $this->join($token, $nickname);
            $this->fail('InvalidArgumentException erwartet: ' . $message);
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function expectRegister(string $token, string $nickname): void {
        $this->players->expects($this->once())->method('register')
            ->with(5, $token, $nickname, self::NOW)
            ->willReturn($this->player($token, $nickname));
    }

    private function room(int $openedAt, int $closesAt = 0, int $closedAt = 0, int $releasedAt = 0): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('AB12CD');
        $room->setMode('quiz');
        $room->setPace('self');
        $room->setOpenedAt($openedAt);
        $room->setClosesAt($closesAt);
        $room->setClosedAt($closedAt);
        $room->setReleasedAt($releasedAt);
        if ($openedAt > 0) {
            $room->setDeckOrder('[11,12]');
        }
        return $room;
    }

    /** @return Player[] */
    private function manyPlayers(int $n): array {
        return array_map(fn (int $i): Player => $this->player('tok-' . $i, 'P' . $i), range(1, $n));
    }

    private function player(string $token, string $nickname): Player {
        $player = new Player();
        $player->setRoomId(5);
        $player->setVoterToken($token);
        $player->setNickname($nickname);
        return $player;
    }

    private function progressRow(string $token): Progress {
        $row = new Progress();
        $row->setRoomId(5);
        $row->setPollId(11);
        $row->setVoterToken($token);
        $row->setStartedAt(self::NOW - 10);
        return $row;
    }
}
