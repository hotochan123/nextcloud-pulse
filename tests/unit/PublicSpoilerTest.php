<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * What the public views (projector, phone) must NOT give away in advance.
 *
 * 1. Ordering/matching in the quiz: the stored option order IS the
 *    solution (or: target i belongs to item i). Until the reveal it comes out
 *    shuffled — sorted by a keyed hash over the server
 *    secret, question ID and option ID. That makes the order the same for all
 *    viewers and independent of the stored order.
 * 2. Overall summary `/s/{code}/summary`: questions never shown are left out
 *    entirely, otherwise the rest of the deck, answer options included, would
 *    be on every phone.
 *
 * Tested through the public entry points publicState/publicSummary — exactly
 * what the controllers deliver. The tally is real (TallyService),
 * the mappers are test doubles; there are no votes.
 */
#[CoversClass(StateService::class)]
class PublicSpoilerTest extends TestCase {

    /** Stored order = solution. */
    private const RANK = [
        ['id' => 'ZZ9A', 'label' => 'Erster'],
        ['id' => 'AB12', 'label' => 'Zweiter'],
        ['id' => 'M3K0', 'label' => 'Dritter'],
    ];

    private PollMapper&MockObject $polls;
    private StateService $service;
    private string $secret = 'test-secret';

    protected function setUp(): void {
        $this->polls = $this->createMock(PollMapper::class);

        $votes = $this->createMock(VoteMapper::class);
        $votes->method('findByPoll')->willReturn([]);
        $votes->method('findByPollAndToken')->willThrowException(new DoesNotExistException('keine Stimme'));
        $votes->method('countByPoll')->willReturn(0);

        $voteService = $this->createMock(VoteService::class);
        $voteService->method('leaderboardFor')->willReturn([]);
        $voteService->method('playerNickname')->willReturn(null);

        $roomService = $this->createMock(RoomService::class);
        $roomService->method('presentCount')->willReturn(0);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1000);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->willReturnCallback(
            fn (string $key, string $default = ''): string => $key === 'secret' ? $this->secret : $default,
        );

        $this->service = (new \ReflectionClass(StateService::class))->newInstanceWithoutConstructor();
        foreach ([
            'pollMapper' => $this->polls,
            'voteMapper' => $votes,
            'tallyService' => new TallyService(),
            'voteService' => $voteService,
            'roomService' => $roomService,
            'timeFactory' => $time,
            'l10n' => $l10n,
            'config' => $config,
        ] as $name => $value) {
            (new ReflectionProperty(StateService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── Ordering: no spoiler via the option order ──────────────────────────

    public function testQuizRankingShuffledBeforeReveal(): void {
        $state = $this->stateFor($this->room('quiz', active: 7), $this->rankPoll('active'));
        $served = $state['poll']['options'];

        $this->assertFalse($state['poll']['revealed']);
        $this->assertIsPermutation(self::RANK, $served);
        $this->assertSame(self::hashOrder(7, self::RANK, $this->secret), $served);
    }

    public function testQuizRankingInStoredOrderAfterReveal(): void {
        // Per-question reveal: 'locked' means revealed.
        $state = $this->stateFor($this->room('quiz', active: 7), $this->rankPoll('locked'));

        $this->assertTrue($state['poll']['revealed']);
        $this->assertSame(['ZZ9A', 'AB12', 'M3K0'], array_column($state['poll']['options'], 'id'));
    }

    public function testQuizRevealAtEndStaysShuffledWhenLocked(): void {
        // "Reveal at the end": 'locked' is only paused, only 'ended' reveals.
        $room = $this->room('quiz', active: 7, revealAtEnd: true);

        $locked = $this->stateFor($room, $this->rankPoll('locked'));
        $this->assertSame('locked', $locked['poll']['status']);
        $this->assertFalse($locked['poll']['revealed']);
        $this->assertSame(self::hashOrder(7, self::RANK, $this->secret), $locked['poll']['options']);

        $ended = $this->stateFor($room, $this->rankPoll('ended'));
        $this->assertTrue($ended['poll']['revealed']);
        $this->assertSame(['ZZ9A', 'AB12', 'M3K0'], array_column($ended['poll']['options'], 'id'));
    }

    public function testPollRankingStaysInStoredOrder(): void {
        // A poll has no solution — the moderator's order applies.
        $state = $this->stateFor($this->room('poll', active: 7), $this->rankPoll('active'));

        $this->assertSame(['ZZ9A', 'AB12', 'M3K0'], array_column($state['poll']['options'], 'id'));
    }

    public function testQuizMatchingShufflesOnlyTheTargets(): void {
        $items = [['id' => 'QQ01', 'label' => 'Hund'], ['id' => 'AA01', 'label' => 'Katze'], ['id' => 'MM01', 'label' => 'Kuh']];
        // Target i belongs to item i — so what is stored is the solution.
        $targets = [['id' => 'TC03', 'label' => 'bellt'], ['id' => 'TA01', 'label' => 'miaut'], ['id' => 'TB02', 'label' => 'muht']];
        $poll = $this->poll(7, 'match', 'active', 900, ['items' => $items, 'targets' => $targets]);

        $match = $this->stateFor($this->room('quiz', active: 7), $poll)['poll']['match'];

        $this->assertSame($items, $match['items'], 'Items behalten ihre Folge');
        $this->assertIsPermutation($targets, $match['targets']);
        $this->assertSame(self::hashOrder(7, $targets, $this->secret), $match['targets']);
        $this->assertSame(['TA01', 'TB02', 'TC03'], array_column($match['targets'], 'id'), 'hier nicht die Lösungsfolge');
    }

    public function testQuizMatchingUnchangedAfterReveal(): void {
        $poll = $this->poll(7, 'match', 'locked', 900, [
            'items' => [['id' => 'QQ01', 'label' => 'Hund'], ['id' => 'AA01', 'label' => 'Katze']],
            'targets' => [['id' => 'ZT01', 'label' => 'bellt'], ['id' => 'BT01', 'label' => 'miaut']],
        ]);

        $state = $this->stateFor($this->room('quiz', active: 7), $poll);

        $this->assertSame(['ZT01', 'BT01'], array_column($state['poll']['match']['targets'], 'id'));
    }

    public function testShuffleIsTheSameForAllViewers(): void {
        // Projector and phones fetch separately — the order must not jump,
        // neither between two fetches nor between tokens nor between the
        // live view and the overall summary.
        $room = $this->room('quiz', active: 7);
        $first = $this->stateFor($room, $this->rankPoll('active'))['poll']['options'];

        $this->assertSame($first, $this->stateFor($room, $this->rankPoll('active'))['poll']['options']);
        foreach (['tok-a', 'tok-b', ''] as $token) {
            $this->assertSame($first, $this->stateFor($room, $this->rankPoll('active'), $token)['poll']['options'], 'Token ' . $token);
        }

        // stateFor has swapped the mapper — swap it back for the overall summary.
        (new ReflectionProperty(StateService::class, 'pollMapper'))->setValue($this->service, $this->polls);
        $this->polls->method('findByRoom')->willReturn([$this->rankPoll('active')]);
        foreach ([null, 'tok-a'] as $token) {
            $this->assertSame($first, $this->service->publicSummary($room, $token)['items'][0]['poll']['options']);
        }
    }

    public function testShuffleDependsOnSecretAndQuestion(): void {
        // The same option IDs, the same solution: another instance (another
        // secret) or another question shuffles differently. The IDs are
        // chosen so that the orders actually differ.
        $room7 = $this->room('quiz', active: 7);
        $room8 = $this->room('quiz', active: 8);

        $this->assertSame(['AB12', 'M3K0', 'ZZ9A'], $this->servedIds($room7, $this->rankPoll('active')));
        $this->assertSame(['M3K0', 'ZZ9A', 'AB12'], $this->servedIds($room8, $this->poll(8, 'rank', 'active', 900, self::RANK)), 'andere Frage');

        $this->secret = 'anderes-geheimnis';
        $this->assertSame(['M3K0', 'ZZ9A', 'AB12'], $this->servedIds($room7, $this->rankPoll('active')), 'anderes Geheimnis');
    }

    // ── Overall summary: only questions that were shown ────────────────────

    public function testSummaryLeavesOutNeverShownQuestions(): void {
        $room = $this->room('poll', active: 2);
        $this->polls->method('findByRoom')->willReturn([
            $this->poll(1, 'choice', 'active', 900),   // shown earlier
            $this->poll(2, 'choice', 'active', 950),   // running now
            $this->poll(3, 'choice', 'active', 0),     // never shown yet
        ]);

        $summary = $this->service->publicSummary($room, null);

        $this->assertSame([1, 2], array_map(static fn (array $i): int => $i['poll']['id'], $summary['items']));
        $this->assertStringNotContainsString('Frage 3', json_encode($summary), 'kein Text der nächsten Frage');
    }

    public function testSummaryKeepsActiveQuestionWithoutStartTime(): void {
        // Poll room from before the start time existed: the running question has 0.
        $room = $this->room('poll', active: 3);
        $this->polls->method('findByRoom')->willReturn([$this->poll(3, 'choice', 'active', 0)]);

        $this->assertCount(1, $this->service->publicSummary($room, null)['items']);
    }

    public function testSummaryKeepsOldLockedPollQuestion(): void {
        // Legacy data: locked, but never given a start time — it was
        // shown nonetheless (otherwise nobody could have locked it).
        $room = $this->room('poll', active: 0);
        $this->polls->method('findByRoom')->willReturn([
            $this->poll(1, 'choice', 'locked', 0),
            $this->poll(2, 'choice', 'ended', 0),
            $this->poll(3, 'choice', 'active', 0),
        ]);

        $summary = $this->service->publicSummary($room, null);

        $this->assertSame([1, 2], array_map(static fn (array $i): int => $i['poll']['id'], $summary['items']));
    }

    public function testQuizSummaryShowsNoLaterQuestionsAndShufflesOpenRanking(): void {
        // Quiz per question: question 1 revealed, question 2 running, question 3 still to come.
        $room = $this->room('quiz', active: 2);
        $this->polls->method('findByRoom')->willReturn([
            $this->poll(1, 'choice', 'locked', 900, [['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B']]),
            $this->poll(2, 'rank', 'active', 950, self::RANK),
            $this->poll(3, 'choice', 'active', 0, [['id' => 'CC', 'label' => 'C'], ['id' => 'DD', 'label' => 'D']]),
        ]);

        $summary = $this->service->publicSummary($room, null);

        $this->assertTrue($summary['available']);
        $this->assertSame([1, 2], array_map(static fn (array $i): int => $i['poll']['id'], $summary['items']));
        $this->assertFalse($summary['items'][1]['revealed']);
        $this->assertFalse($summary['items'][1]['poll']['revealed']);
        $this->assertSame(self::hashOrder(2, self::RANK, $this->secret), $summary['items'][1]['poll']['options'],
            'auch in der Gesamtauswertung verrät die Folge nichts');
        $this->assertNotSame(array_column(self::RANK, 'id'), array_column($summary['items'][1]['poll']['options'], 'id'),
            'hier nicht die Lösungsfolge');
    }

    public function testQuizSummaryAtTheEndWithoutNeverShownQuestions(): void {
        // "Reveal at the end" after /end: the deck is uncovered — but only
        // what was actually shown. Skipped questions stay out.
        $room = $this->room('quiz', active: 2, revealAtEnd: true);
        $this->polls->method('findByRoom')->willReturn([
            $this->poll(1, 'rank', 'locked', 900, self::RANK),
            $this->poll(2, 'choice', 'ended', 950, [['id' => 'AA', 'label' => 'A']]),
            $this->poll(3, 'choice', 'active', 0, [['id' => 'CC', 'label' => 'C']]),
        ]);

        $summary = $this->service->publicSummary($room, null);

        $this->assertSame([1, 2], array_map(static fn (array $i): int => $i['poll']['id'], $summary['items']));
        $this->assertTrue($summary['items'][0]['revealed']);
        $this->assertSame(['ZZ9A', 'AB12', 'M3K0'], array_column($summary['items'][0]['poll']['options'], 'id'),
            'aufgelöst → gespeicherte Folge (= Lösung)');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Expected shuffle, recomputed independently of the service: ascending
     * by HMAC-SHA256 over "questionID:optionID", key "pulse-order:"
     * + instance secret. The stored order plays no part.
     *
     * @param list<array{id:string,label:string}> $list
     * @return list<array{id:string,label:string}>
     */
    private static function hashOrder(int $pollId, array $list, string $secret): array {
        usort($list, static fn (array $a, array $b): int => strcmp(
            hash_hmac('sha256', $pollId . ':' . $a['id'], 'pulse-order:' . $secret),
            hash_hmac('sha256', $pollId . ':' . $b['id'], 'pulse-order:' . $secret),
        ));
        return $list;
    }

    /**
     * What is delivered is a permutation of the stored list: the same
     * entries, every label still attached to its ID, nothing duplicated.
     */
    private function assertIsPermutation(array $stored, array $served): void {
        $this->assertCount(count($stored), $served);
        $byId = array_column($served, null, 'id');
        $this->assertCount(count($stored), $byId, 'keine ID doppelt');
        foreach ($stored as $entry) {
            $this->assertSame($entry, $byId[$entry['id']] ?? null, 'Beschriftung wandert mit ihrer ID: ' . $entry['id']);
        }
    }

    /** @return list<string> */
    private function servedIds(Room $room, Poll $poll): array {
        return array_column($this->stateFor($room, $poll)['poll']['options'], 'id');
    }

    private function stateFor(Room $room, Poll $poll, ?string $token = null): array {
        $polls = $this->createMock(PollMapper::class);
        $polls->method('find')->willReturn($poll);
        (new ReflectionProperty(StateService::class, 'pollMapper'))->setValue($this->service, $polls);
        return $this->service->publicState($room, $token);
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

    private function rankPoll(string $status): Poll {
        return $this->poll(7, 'rank', $status, 900, self::RANK);
    }

    private function poll(int $id, string $type, string $status, int $startedAt, array $options = [['id' => 'AA', 'label' => 'A']]): Poll {
        $poll = new Poll();
        $poll->setId($id);
        $poll->setRoomId(1);
        $poll->setType($type);
        $poll->setQuestion('Frage ' . $id);
        $poll->setOptions(json_encode($options));
        $poll->setStatus($status);
        $poll->setStartedAt($startedAt);
        return $poll;
    }
}
