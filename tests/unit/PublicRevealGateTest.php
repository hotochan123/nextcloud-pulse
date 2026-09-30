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
use OCA\Pulse\Service\RoomService;
use OCA\Pulse\Service\StateService;
use OCA\Pulse\Service\TallyService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Release gates of the public views, second round.
 *
 * - Leaderboard of the overall summary without the running, still hidden question
 *   (otherwise the score shows right after the tap whether it was correct).
 * - "Quiz over" only applies while nothing new is running: a leftover
 *   'ended' question from an earlier run no longer reveals anything.
 * - Old poll rooms (from before the start timestamp): votes prove "shown".
 * - Hidden quiz questions: the status stays genuine ('locked' stays 'locked'),
 *   whether it is revealed is told by `revealed` alone. The order of ranking options
 *   and matching targets depends only on secret + question + option — not on
 *   the stored order, and therefore not on the solution.
 */
#[CoversClass(StateService::class)]
class PublicRevealGateTest extends TestCase {

    private PollMapper&MockObject $polls;
    private VoteService&MockObject $voteService;
    private StateService $service;
    /** The question publicState gets via find(). */
    private ?Poll $current = null;
    /** @var array<int,int> votes per question (countByPoll) */
    private array $voteCounts = [];
    /** @var array<string,Vote> own vote per token (findByPollAndToken) */
    private array $myVotes = [];

    protected function setUp(): void {
        $this->polls = $this->createMock(PollMapper::class);
        $this->polls->method('find')->willReturnCallback(fn (): Poll => $this->current);

        $votes = $this->createMock(VoteMapper::class);
        $votes->method('findByPoll')->willReturn([]);
        $votes->method('findByPollAndToken')->willReturnCallback(
            fn (int $pollId, string $token): Vote => $this->myVotes[$token] ?? throw new DoesNotExistException('keine Stimme'),
        );
        $votes->method('countByPoll')->willReturnCallback(fn (int $id): int => $this->voteCounts[$id] ?? 0);

        $this->voteService = $this->createMock(VoteService::class);
        $this->voteService->method('playerNickname')->willReturn(null);

        $roomService = $this->createMock(RoomService::class);
        $roomService->method('presentCount')->willReturn(0);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1000);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->willReturnCallback(
            static fn (string $key, string $default = ''): string => $key === 'secret' ? 'test-secret' : $default,
        );

        $this->service = (new \ReflectionClass(StateService::class))->newInstanceWithoutConstructor();
        foreach ([
            'pollMapper' => $this->polls,
            'voteMapper' => $votes,
            'tallyService' => new TallyService(),
            'voteService' => $this->voteService,
            'roomService' => $roomService,
            'timeFactory' => $time,
            'l10n' => $l10n,
            'config' => $config,
        ] as $name => $value) {
            (new ReflectionProperty(StateService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── S2: leaderboard without the running, hidden question ──────────────

    public function testSummaryLeaderboardLeavesOutTheRunningHiddenQuestion(): void {
        // Per question: 1 revealed, 2 running (not revealed yet).
        $room = $this->room('quiz', active: 2);
        $this->deck(
            $this->poll(1, 'choice', 'locked', 900),
            $this->poll(2, 'choice', 'active', 950),
        );
        $this->voteService->expects($this->once())->method('leaderboardFor')
            ->with($room, 'tok-a', [2])
            ->willReturn([]);

        $this->assertSame([], $this->service->publicSummary($room, 'tok-a')['leaderboard']);
    }

    public function testSummaryLeaderboardWithARevealedRunningQuestionIsComplete(): void {
        $room = $this->room('quiz', active: 2);
        $this->deck(
            $this->poll(1, 'choice', 'locked', 900),
            $this->poll(2, 'choice', 'locked', 950),
        );
        $this->voteService->expects($this->once())->method('leaderboardFor')
            ->with($room, 'tok-a', [])
            ->willReturn([]);

        $this->service->publicSummary($room, 'tok-a');
    }

    public function testSummaryLeaderboardInTheLobbyWithoutTheSkippedQuestion(): void {
        // Question 2 ran but was never revealed (skipped), then lobby:
        // its points would otherwise give away right/wrong.
        $room = $this->room('quiz', active: 0);
        $this->deck(
            $this->poll(1, 'choice', 'locked', 900),
            $this->poll(2, 'choice', 'active', 950),
        );
        $this->voteService->expects($this->once())->method('leaderboardFor')
            ->with($room, null, [2])
            ->willReturn([]);

        $this->service->publicSummary($room, null);
    }

    public function testSummaryLeaderboardSkippedQuestionNextToARunningQuestion(): void {
        // Question 2 skipped (never revealed), question 3 running and revealed:
        // both hidden questions are missing, not just the running one.
        $room = $this->room('quiz', active: 3);
        $this->deck(
            $this->poll(1, 'choice', 'locked', 900),
            $this->poll(2, 'choice', 'active', 950),
            $this->poll(3, 'choice', 'active', 990),
        );
        $this->voteService->expects($this->once())->method('leaderboardFor')
            ->with($room, null, [2, 3])
            ->willReturn([]);

        $this->service->publicSummary($room, null);
    }

    public function testSummaryLeaderboardAfterTheEndPerQuestionCountsEverything(): void {
        // Final standings per question: the last question has ended, the cursor is in
        // the lobby. Even a skipped question counts now — as for the
        // moderator and on the projector.
        $room = $this->room('quiz', active: 0);
        $this->deck(
            $this->poll(1, 'choice', 'locked', 900),
            $this->poll(2, 'choice', 'active', 950),
            $this->poll(3, 'choice', 'ended', 990),
        );
        $this->voteService->expects($this->once())->method('leaderboardFor')
            ->with($room, null, [])
            ->willReturn([]);

        $this->service->publicSummary($room, null);
    }

    public function testSummaryLeaderboardAtTheEndIsComplete(): void {
        // "Reveal at the end" after /end: the running question is the ended one,
        // everything is revealed — nothing to leave out.
        $room = $this->room('quiz', active: 2, revealAtEnd: true);
        $this->deck(
            $this->poll(1, 'choice', 'locked', 900),
            $this->poll(2, 'choice', 'ended', 950),
        );
        $this->voteService->expects($this->once())->method('leaderboardFor')
            ->with($room, null, [])
            ->willReturn([]);

        $this->service->publicSummary($room, null);
    }

    public function testSummaryWithoutARevealedQuestionFetchesNoLeaderboard(): void {
        $room = $this->room('quiz', active: 1);
        $this->deck($this->poll(1, 'choice', 'active', 900));
        $this->voteService->expects($this->never())->method('leaderboardFor');

        $summary = $this->service->publicSummary($room, null);

        $this->assertFalse($summary['available']);
        $this->assertNull($summary['leaderboard']);
    }

    // ── S2: leftover 'ended' ───────────────────────────────────────────────

    public function testLeftoverEndRevealsNothingInANewRun(): void {
        // The first run ended on question 1 ('ended'); the second run is on
        // question 2. The overall summary must therefore NOT release the whole deck
        // including the solutions.
        $room = $this->room('quiz', active: 2, revealAtEnd: true);
        $this->deck(
            $this->poll(1, 'choice', 'ended', 900, [['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B']]),
            $this->poll(2, 'choice', 'active', 950),
        );
        $this->voteService->expects($this->never())->method('leaderboardFor');

        $summary = $this->service->publicSummary($room, null);

        $this->assertFalse($summary['available']);
        $this->assertNull($summary['leaderboard']);
        foreach ($summary['items'] as $item) {
            $this->assertFalse($item['revealed'], 'Frage ' . $item['poll']['id']);
            $this->assertFalse($item['poll']['revealed'], 'auch im Poll selbst, Frage ' . $item['poll']['id']);
            $this->assertNull($item['results']);
            $this->assertArrayNotHasKey('answerKey', $item['poll']);
            $this->assertArrayNotHasKey('correctOption', $item['poll']);
        }
    }

    public function testEndAppliesInTheLobby(): void {
        // After /end and "Back to the deck" (cursor 0) the final standings stay visible.
        $room = $this->room('quiz', active: 0, revealAtEnd: true);
        $this->deck(
            $this->poll(1, 'choice', 'locked', 900),
            $this->poll(2, 'choice', 'ended', 950),
        );
        $this->voteService->method('leaderboardFor')->willReturn([]);

        $summary = $this->service->publicSummary($room, null);

        $this->assertTrue($summary['available']);
        $this->assertSame([true, true], array_column($summary['items'], 'revealed'));
    }

    // ── S3: old poll rooms ─────────────────────────────────────────────────

    public function testPollQuestionWithVotesWithoutStartTimestampIsShown(): void {
        // Before v0.18.x the poll set no start timestamp. Votes only exist
        // on a question that was shown — so question 1 belongs in, question 3
        // (without votes, never on) does not.
        $room = $this->room('poll', active: 2);
        $this->deck(
            $this->poll(1, 'choice', 'active', 0),
            $this->poll(2, 'choice', 'active', 0),
            $this->poll(3, 'choice', 'active', 0),
        );
        $this->voteCounts = [1 => 4];

        $summary = $this->service->publicSummary($room, null);

        $this->assertSame([1, 2], array_map(static fn (array $i): int => $i['poll']['id'], $summary['items']));
    }

    public function testQuizQuestionWithVotesWithoutStartTimestampStaysOut(): void {
        // In a quiz, setCurrent has always set the timestamp — there votes are
        // no proof (and are not even counted).
        $room = $this->room('quiz', active: 2);
        $this->deck(
            $this->poll(1, 'choice', 'active', 0),
            $this->poll(2, 'choice', 'active', 950),
        );
        $this->voteCounts = [1 => 4];
        $this->voteService->method('leaderboardFor')->willReturn([]);

        $summary = $this->service->publicSummary($room, null);

        $this->assertSame([2], array_map(static fn (array $i): int => $i['poll']['id'], $summary['items']));
    }

    // ── S4: hidden means hidden ────────────────────────────────────────────

    public function testRankingDoesNotDependOnTheSolution(): void {
        // The same question (ID 7, the same option IDs) stored with three different
        // solutions: the same order is served every time. So the display
        // carries no information about the solution — not even when
        // it happens to match the solution (as with the third one).
        $room = $this->room('quiz', active: 7);
        $first = ['id' => 'AA01', 'label' => 'Erster'];
        $second = ['id' => 'BB02', 'label' => 'Zweiter'];
        $third = ['id' => 'CC03', 'label' => 'Dritter'];

        $served = [];
        foreach ([[$first, $second, $third], [$third, $first, $second], [$second, $third, $first]] as $solution) {
            $served[] = $this->stateFor($room, $this->poll(7, 'rank', 'active', 900, $solution))['poll']['options'];
        }

        $this->assertSame($served[0], $served[1]);
        $this->assertSame($served[0], $served[2]);
        $this->assertSame(['BB02', 'CC03', 'AA01'], array_column($served[0], 'id'));
    }

    public function testTwoOptionsGiveNothingAway(): void {
        // Previously two options always came out exactly reversed — which
        // gave the solution away. Now the display is the same for both possible
        // solutions: one of the two is shown in solution order.
        $room = $this->room('quiz', active: 7);
        $a = ['id' => 'AA01', 'label' => 'Erster'];
        $b = ['id' => 'BB02', 'label' => 'Zweiter'];

        $ab = $this->stateFor($room, $this->poll(7, 'rank', 'active', 900, [$a, $b]))['poll']['options'];
        $ba = $this->stateFor($room, $this->poll(7, 'rank', 'active', 900, [$b, $a]))['poll']['options'];

        $this->assertSame($ab, $ba);
        $this->assertContains(array_column($ab, 'id'), [['AA01', 'BB02'], ['BB02', 'AA01']]);
    }

    public function testMatchingTargetsDoNotDependOnTheSolution(): void {
        // Two solutions for the same items/targets (target i belongs to item i):
        // the targets come out the same, the items each keep their order.
        $room = $this->room('quiz', active: 7);
        $items = [['id' => 'IT01', 'label' => 'Hund'], ['id' => 'IT02', 'label' => 'Katze'], ['id' => 'IT03', 'label' => 'Kuh']];
        $barks = ['id' => 'TA01', 'label' => 'bellt'];
        $meows = ['id' => 'TB02', 'label' => 'miaut'];
        $moos = ['id' => 'TC03', 'label' => 'muht'];

        $correct = $this->stateFor($room, $this->poll(7, 'match', 'active', 900, ['items' => $items, 'targets' => [$barks, $meows, $moos]]));
        $different = $this->stateFor($room, $this->poll(7, 'match', 'active', 900, ['items' => $items, 'targets' => [$meows, $moos, $barks]]));

        $this->assertSame($correct['poll']['match']['targets'], $different['poll']['match']['targets']);
        $this->assertSame(['IT01', 'IT02', 'IT03'], array_column($correct['poll']['match']['items'], 'id'), 'Items bleiben');
        $this->assertSame(['IT01', 'IT02', 'IT03'], array_column($different['poll']['match']['items'], 'id'), 'Items bleiben');
    }

    /** @return array<string, array{bool, string}> [revealAtEnd, Status] */
    public static function revealedStates(): array {
        return [
            'je Frage, gesperrt' => [false, 'locked'],
            'je Frage, beendet' => [false, 'ended'],
            'am Ende, beendet' => [true, 'ended'],
        ];
    }

    #[DataProvider('revealedStates')]
    public function testRevealedComesInStoredOrder(bool $revealAtEnd, string $status): void {
        // Chosen so that the shuffled order is NOT the stored one —
        // otherwise the test would prove nothing.
        $this->voteService->method('leaderboardFor')->willReturn([]);
        $room = $this->room('quiz', active: 7, revealAtEnd: $revealAtEnd);

        $rank = $this->stateFor($room, $this->poll(7, 'rank', $status, 900, [
            ['id' => 'AA01', 'label' => 'Erster'],
            ['id' => 'BB02', 'label' => 'Zweiter'],
            ['id' => 'CC03', 'label' => 'Dritter'],
        ]));
        $this->assertTrue($rank['poll']['revealed']);
        $this->assertSame(['AA01', 'BB02', 'CC03'], array_column($rank['poll']['options'], 'id'));

        $match = $this->stateFor($room, $this->poll(7, 'match', $status, 900, [
            'items' => [['id' => 'IT01', 'label' => 'Hund'], ['id' => 'IT02', 'label' => 'Katze'], ['id' => 'IT03', 'label' => 'Kuh']],
            'targets' => [['id' => 'TC03', 'label' => 'bellt'], ['id' => 'TA01', 'label' => 'miaut'], ['id' => 'TB02', 'label' => 'muht']],
        ]));
        $this->assertSame(['TC03', 'TA01', 'TB02'], array_column($match['poll']['match']['targets'], 'id'));
    }

    public function testAtTheEndLockedStaysShuffled(): void {
        // Counterpart: the same questions, "Reveal at the end", only locked.
        $room = $this->room('quiz', active: 7, revealAtEnd: true);

        $rank = $this->stateFor($room, $this->poll(7, 'rank', 'locked', 900, [
            ['id' => 'AA01', 'label' => 'Erster'],
            ['id' => 'BB02', 'label' => 'Zweiter'],
            ['id' => 'CC03', 'label' => 'Dritter'],
        ]));
        $this->assertSame(['BB02', 'CC03', 'AA01'], array_column($rank['poll']['options'], 'id'));

        $match = $this->stateFor($room, $this->poll(7, 'match', 'locked', 900, [
            'items' => [['id' => 'IT01', 'label' => 'Hund'], ['id' => 'IT02', 'label' => 'Katze'], ['id' => 'IT03', 'label' => 'Kuh']],
            'targets' => [['id' => 'TC03', 'label' => 'bellt'], ['id' => 'TA01', 'label' => 'miaut'], ['id' => 'TB02', 'label' => 'muht']],
        ]));
        $this->assertSame(['TA01', 'TB02', 'TC03'], array_column($match['poll']['match']['targets'], 'id'));
    }

    public function testPollRankingIsNeverResorted(): void {
        $poll = $this->poll(7, 'rank', 'active', 900, [
            ['id' => 'AA01', 'label' => 'Erster'],
            ['id' => 'BB02', 'label' => 'Zweiter'],
        ]);

        $state = $this->stateFor($this->room('poll', active: 7), $poll);

        $this->assertSame(['AA01', 'BB02'], array_column($state['poll']['options'], 'id'));
    }

    public function testRevealAtEndLockedStaysLockedButHidden(): void {
        // "Reveal at the end": 'locked' only means "left over after flipping
        // the switch". The status stays genuine, yet nothing is
        // revealed — that is what `revealed` says, and phone and
        // projector go by it.
        $this->myVotes['tok-a'] = $this->vote(7, 'tok-a', ['value' => 'AA', 'correct' => true, 'points' => 950]);
        $this->current = $this->poll(7, 'choice', 'locked', 900);

        $state = $this->service->publicState($this->room('quiz', active: 7, revealAtEnd: true), 'tok-a');

        $this->assertSame('locked', $state['poll']['status']);
        $this->assertFalse($state['poll']['revealed']);
        $this->assertNull($state['results']);
        $this->assertNull($state['leaderboard']);
        $this->assertArrayNotHasKey('correctOption', $state['poll']);
        $this->assertArrayNotHasKey('answerKey', $state['poll']);
        $this->assertTrue($state['hasVoted']);
        $this->assertSame(['answered' => true, 'correct' => null, 'points' => null], $state['myResult']);
    }

    public function testRevealAtEndEndedStaysEnded(): void {
        $this->voteService->method('leaderboardFor')->willReturn([]);
        $state = $this->stateFor($this->room('quiz', active: 7, revealAtEnd: true), $this->poll(7, 'choice', 'ended', 900));

        $this->assertSame('ended', $state['poll']['status']);
        $this->assertTrue($state['poll']['revealed']);
        $this->assertSame('AA', $state['poll']['correctOption']);
    }

    public function testPerQuestionLockedStaysLocked(): void {
        // Per-question reveal: 'locked' IS revealed — the status stays genuine.
        $this->voteService->method('leaderboardFor')->willReturn([]);
        $state = $this->stateFor($this->room('quiz', active: 7), $this->poll(7, 'choice', 'locked', 900));

        $this->assertSame('locked', $state['poll']['status']);
        $this->assertTrue($state['poll']['revealed']);
    }

    public function testRunningQuizQuestionIsNotRevealed(): void {
        $state = $this->stateFor($this->room('quiz', active: 7), $this->poll(7, 'choice', 'active', 900));

        $this->assertSame('active', $state['poll']['status']);
        $this->assertFalse($state['poll']['revealed']);
        $this->assertArrayNotHasKey('correctOption', $state['poll']);
    }

    public function testPollLockedStaysLocked(): void {
        // Poll: 'locked' = paused, no solution to hide.
        $state = $this->stateFor($this->room('poll', active: 7, revealAtEnd: true), $this->poll(7, 'choice', 'locked', 900));

        $this->assertSame('locked', $state['poll']['status']);
    }

    public function testSummaryHiddenLockedStaysLocked(): void {
        // The same rule in the overall summary: status genuine, `revealed` false,
        // no solution.
        $room = $this->room('quiz', active: 2, revealAtEnd: true);
        $this->deck(
            $this->poll(1, 'choice', 'locked', 900),
            $this->poll(2, 'choice', 'active', 950),
        );

        $summary = $this->service->publicSummary($room, null);

        $this->assertSame(['locked', 'active'], array_map(static fn (array $i): string => $i['poll']['status'], $summary['items']));
        $this->assertSame([false, false], array_map(static fn (array $i): bool => $i['poll']['revealed'], $summary['items']));
        $this->assertSame([false, false], array_column($summary['items'], 'revealed'));
        foreach ($summary['items'] as $item) {
            $this->assertArrayNotHasKey('correctOption', $item['poll']);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function deck(Poll ...$polls): void {
        $this->polls->method('findByRoom')->willReturn($polls);
    }

    private function stateFor(Room $room, Poll $poll): array {
        $this->current = $poll;
        return $this->service->publicState($room, null);
    }

    private function room(string $mode, int $active, bool $revealAtEnd = false): Room {
        $room = new Room();
        $room->setId(1);
        $room->setCode('ABCDEF');
        $room->setMode($mode);
        $room->setActivePollId($active);
        $room->setRevealAtEnd($revealAtEnd);
        return $room;
    }

    private function poll(int $id, string $type, string $status, int $startedAt, array $options = [['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B']]): Poll {
        $poll = new Poll();
        $poll->setId($id);
        $poll->setRoomId(1);
        $poll->setType($type);
        $poll->setQuestion('Frage ' . $id);
        $poll->setOptions(json_encode($options));
        $poll->setStatus($status);
        $poll->setStartedAt($startedAt);
        $poll->setCorrectOption('AA');
        return $poll;
    }

    private function vote(int $pollId, string $token, array $payload): Vote {
        $vote = new Vote();
        $vote->setPollId($pollId);
        $vote->setVoterToken($token);
        $vote->setPayload(json_encode($payload));
        return $vote;
    }
}
