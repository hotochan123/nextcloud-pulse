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
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\RoomGoneException;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\Security\RateLimiting\ILimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Moderated (live) quiz join (VoteService::quizJoin -> liveJoin).
 *
 * - Under the room lock, like the self-paced join: two simultaneous
 *   "Anna"s no longer both get the name, and the decision falls on the
 *   freshly locked row.
 * - "Lock joining" turns new tokens away (before the name check — a
 *   throwaway token does not even learn which names are taken); known
 *   players get back in.
 * - The name is fixed once the token has answered a question of the room or
 *   the quiz is over: a name vetted in the lobby can no longer be swapped for
 *   another one on the podium. The spelling of one's own name stays
 *   changeable, and re-sending it always works.
 * - A new token starts empty: a vote left over from a removal in flight does
 *   not come back under a new name. Without such a left-over nothing is
 *   deleted: a delete counts as a vote write and would change the stamp of
 *   the running question, sending every phone into a full refetch per join.
 * - At most PaceService::MAX_JOINED players (voter tokens cost nothing to
 *   make up); anyone already playing still gets back in. No count per
 *   address (a conference behind one NAT).
 */
#[CoversClass(VoteService::class)]
class LiveQuizJoinTest extends TestCase {

    /** App config an admin set (Limits keys): key => value. */
    private array $limitConfig = [];

    private VoteService $service;
    private PlayerMapper&MockObject $players;
    private VoteMapper&MockObject $votes;
    private PaceService&MockObject $pace;

    /** What PaceService::locked passes to the callback — the authoritative row. */
    private Room $locked;
    /** @var Player[] */
    private array $roster = [];
    /** @var Poll[] */
    private array $deck = [];
    /** @var array<string, Vote[]> votes per token */
    private array $answered = [];
    private int $lockCalls = 0;

    protected function setUp(): void {
        $this->locked = $this->room();
        $this->roster = [$this->player(31, 'tok-anna', 'Anna'), $this->player(32, 'tok-ben', 'Ben')];
        $this->deck = [$this->poll(21, 'locked'), $this->poll(22, 'active')];

        $this->pace = $this->createMock(PaceService::class);
        $this->pace->method('locked')->willReturnCallback(function (Room $room, callable $fn): mixed {
            $this->lockCalls++;
            return $fn($this->locked);
        });

        $this->players = $this->createMock(PlayerMapper::class);
        $this->players->method('findByRoom')->willReturnCallback(fn (): array => $this->roster);

        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturnCallback(fn (): array => $this->deck);

        $this->votes = $this->createMock(VoteMapper::class);
        $this->votes->method('findByPolls')->willReturnCallback(
            fn (array $pollIds, ?string $token = null): array => $this->answered[$token] ?? [],
        );

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1000);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $limiter = $this->createMock(ILimiter::class);
        $limiter->expects($this->never())->method($this->anything());

        $this->service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        foreach ([
            'playerMapper' => $this->players,
            'pollMapper' => $polls,
            'voteMapper' => $this->votes,
            'paceService' => $this->pace,
            'limits' => $this->limits(),
            'timeFactory' => $time,
            'l10n' => $l10n,
            'limiter' => $limiter,
        ] as $name => $value) {
            (new ReflectionProperty(VoteService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── Room lock ──────────────────────────────────────────────────────────

    public function testLaeuftUnterDerRaumsperre(): void {
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');

        $this->assertSame(1, $this->lockCalls);
    }

    public function testEntscheidetAnDerGesperrtenZeile(): void {
        // The room passed in is stale (joining open); read with the lock it is locked.
        $this->locked->setJoinsLocked(true);

        $this->assertRejected('Joining is closed for this quiz.', 'tok-neu', 'Cem');
        $this->assertSame(1, $this->lockCalls);
    }

    public function testGleichzeitigesZweitesAnnaSiehtDasErste(): void {
        // Serialised by the lock: the second request reads the first one's row.
        $this->players->expects($this->once())->method('register')
            ->willReturnCallback(function (int $roomId, string $token, string $nickname): Player {
                $this->roster[] = $p = $this->player(33, $token, $nickname);
                return $p;
            });

        $this->join('tok-1', 'Cem');
        try {
            $this->join('tok-2', 'cem');
            $this->fail('InvalidArgumentException erwartet');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('This name is already taken. Please choose another one.', $e->getMessage());
        }
    }

    public function testGeloeschterRaumWirdNichtRegistriert(): void {
        $pace = $this->createMock(PaceService::class);
        $pace->method('locked')->willThrowException(new RoomGoneException());
        (new ReflectionProperty(VoteService::class, 'paceService'))->setValue($this->service, $pace);
        $this->players->expects($this->never())->method('register');

        $this->expectException(RoomGoneException::class);
        $this->join('tok-neu', 'Cem');
    }

    // ── Lock joining ───────────────────────────────────────────────────────

    public function testGesperrtNeuesTokenWirdAbgewiesen(): void {
        $this->locked->setJoinsLocked(true);

        $this->assertRejected('Joining is closed for this quiz.', 'tok-neu', 'Cem');
    }

    public function testGesperrtVorDerNamenspruefung(): void {
        $this->locked->setJoinsLocked(true);

        $this->assertRejected('Joining is closed for this quiz.', 'tok-neu', 'anna');
    }

    public function testGesperrtBekanntesTokenKommtWeiterRein(): void {
        $this->locked->setJoinsLocked(true);
        $this->expectRegister('tok-anna', 'Anna');

        $this->join('tok-anna', 'Anna');
    }

    // ── Name freeze ────────────────────────────────────────────────────────

    public function testUmbenennenNachDerErstenAntwortWirdAbgelehnt(): void {
        $this->answered['tok-anna'] = [new Vote()];

        $this->assertRejected('You can\'t change your name after starting.', 'tok-anna', 'Annika');
    }

    public function testUmbenennenNachDemQuizendeWirdAbgelehnt(): void {
        // Never answered, but the final standings are up.
        $this->deck[1]->setStatus('ended');

        $this->assertRejected('You can\'t change your name after starting.', 'tok-ben', 'Boss');
    }

    public function testUmbenennenVorDerErstenAntwortIstErlaubt(): void {
        $this->expectRegister('tok-anna', 'Annika');

        $this->join('tok-anna', 'Annika');
    }

    public function testSchreibweiseDesEigenenNamensBleibtAenderbar(): void {
        $this->answered['tok-anna'] = [new Vote()];
        $this->deck[1]->setStatus('ended');
        $this->expectRegister('tok-anna', 'ANNA');

        $this->join('tok-anna', 'ANNA');
    }

    public function testEigenerNameWirdOhneFragenrundeBestaetigt(): void {
        // Re-sending one's own name reads neither the questions nor the votes.
        $this->votes->expects($this->never())->method('findByPolls');
        $this->votes->expects($this->never())->method('deleteByPollsAndToken');
        $this->expectRegister('tok-ben', 'Ben');

        $this->join('tok-ben', 'Ben');
    }

    public function testNachDemQuizendeKannEinNeuerNochBeitreten(): void {
        // Only renaming is frozen; a latecomer still gets a (zero-point) row.
        $this->deck[1]->setStatus('ended');
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');
    }

    // ── A new token starts empty ───────────────────────────────────────────

    public function testNeuesTokenVerliertUebriggebliebeneStimmen(): void {
        $this->answered['tok-neu'] = [new Vote()];
        $calls = [];
        $this->votes->expects($this->once())->method('deleteByPollsAndToken')
            ->with([21, 22], 'tok-neu')
            ->willReturnCallback(function () use (&$calls): void {
                $calls[] = 'forget';
            });
        $this->players->expects($this->once())->method('register')
            ->willReturnCallback(function () use (&$calls): Player {
                $calls[] = 'register';
                return $this->player(33, 'tok-neu', 'Cem');
            });

        $this->join('tok-neu', 'Cem');

        $this->assertSame(['forget', 'register'], $calls);
    }

    public function testNeuesTokenOhneResteLoeschtNichts(): void {
        // The usual join: nothing to forget, so no vote write — the stamp of
        // the running question stays, and nobody refetches because of a join.
        $this->votes->expects($this->once())->method('findByPolls')->with([21, 22], 'tok-neu');
        $this->votes->expects($this->never())->method('deleteByPollsAndToken');
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');
    }

    public function testBekanntesTokenBehaeltSeineStimmen(): void {
        $this->votes->expects($this->never())->method('deleteByPollsAndToken');
        $this->expectRegister('tok-anna', 'Annika');

        $this->join('tok-anna', 'Annika');
    }

    public function testAbgewiesenVergisstNichts(): void {
        $this->votes->expects($this->never())->method('deleteByPollsAndToken');

        $this->assertRejected('This name is already taken. Please choose another one.', 'tok-neu', 'ANNA');
    }

    // ── Cap, but no count per address ──────────────────────────────────────

    public function testUnterDemDeckelKeinZaehlerProAdresse(): void {
        // The limiter mock refuses every call (setUp); one below the cap still lets a new one in.
        $this->roster = $this->manyPlayers(PaceService::MAX_JOINED - 1);
        $this->expectRegister('tok-neu', 'Cem');

        $this->service->quizJoin($this->room(), 'tok-neu', 'Cem', '203.0.113.7');
    }

    public function testAmDeckelIstDasQuizVoll(): void {
        $this->roster = $this->manyPlayers(PaceService::MAX_JOINED);
        $this->votes->expects($this->never())->method('deleteByPollsAndToken');

        $this->assertRejected('This quiz is full.', 'tok-neu', 'Cem');
    }

    public function testAmDeckelKommtEinBekannterSpielerWeiterRein(): void {
        $this->roster = $this->manyPlayers(PaceService::MAX_JOINED);
        $this->expectRegister('tok-7', 'P7');

        $this->join('tok-7', 'P7');
    }

    public function testDeckelFolgtDerAppConfig(): void {
        // occ config:app:set pulse max_players_per_room --value 3
        $this->limitConfig[\OCA\Pulse\Service\Limits::PLAYERS_PER_ROOM] = 3;
        $this->roster = $this->manyPlayers(3);

        $this->assertRejected('This quiz is full.', 'tok-neu', 'Cem');
    }

    public function testHoehererDeckelLaesstMehrAlsSechshundertRein(): void {
        // A keynote: the instance raised the ceiling.
        $this->limitConfig[\OCA\Pulse\Service\Limits::PLAYERS_PER_ROOM] = 1000;
        $this->roster = $this->manyPlayers(PaceService::MAX_JOINED);
        $this->expectRegister('tok-neu', 'Cem');

        $this->join('tok-neu', 'Cem');
    }

    public function testGesperrtGehtVorVoll(): void {
        // The same order as self-paced: a locked room says so, not "full".
        $this->roster = $this->manyPlayers(PaceService::MAX_JOINED);
        $this->locked->setJoinsLocked(true);

        $this->assertRejected('Joining is closed for this quiz.', 'tok-neu', 'Cem');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** @return Player[] */
    private function manyPlayers(int $count): array {
        return array_map(fn (int $i): Player => $this->player($i, 'tok-' . $i, 'P' . $i), range(1, $count));
    }

    private function join(string $token, string $nickname): Player {
        return $this->service->quizJoin($this->room(), $token, $nickname);
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
            ->with(7, $token, $nickname, 1000)
            ->willReturn($this->player(99, $token, $nickname));
    }

    private function room(): Room {
        $room = new Room();
        $room->setId(7);
        $room->setCode('LIVE01');
        $room->setMode('quiz');
        $room->setPace('live');
        return $room;
    }

    private function poll(int $id, string $status): Poll {
        $poll = new Poll();
        $poll->setId($id);
        $poll->setRoomId(7);
        $poll->setStatus($status);
        return $poll;
    }

    private function player(int $id, string $token, string $nickname): Player {
        $player = new Player();
        $player->setId($id);
        $player->setRoomId(7);
        $player->setVoterToken($token);
        $player->setNickname($nickname);
        return $player;
    }

    private function limits(): \OCA\Pulse\Service\Limits {
        $config = $this->createMock(\OCP\IAppConfig::class);
        $config->method('getValueInt')->willReturnCallback(
            fn (string $app, string $key, int $default = 0): int => $this->limitConfig[$key] ?? $default
        );
        return new \OCA\Pulse\Service\Limits($config);
    }
}
