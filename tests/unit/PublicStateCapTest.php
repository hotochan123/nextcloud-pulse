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
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\PublicPayload;
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
 * Moderated rooms, task M3 of the security review: the public views
 * (publicState, publicSummary) cap their tallies and leaderboards
 * (PublicPayload), the moderator's results, summary and CSV keep everything.
 */
#[CoversClass(StateService::class)]
class PublicStateCapTest extends TestCase {

    private PollMapper&MockObject $polls;
    private VoteService&MockObject $voteService;
    private StateService $service;
    private Poll $current;
    /** @var Vote[] */
    private array $votes = [];

    protected function setUp(): void {
        $this->polls = $this->createMock(PollMapper::class);
        $this->polls->method('find')->willReturnCallback(fn (): Poll => $this->current);
        $this->polls->method('findByRoom')->willReturnCallback(fn (): array => [$this->current]);

        $votes = $this->createMock(VoteMapper::class);
        $votes->method('findByPoll')->willReturnCallback(fn (): array => $this->votes);
        $votes->method('countByPoll')->willReturnCallback(fn (): int => count($this->votes));
        $votes->method('findByPollAndToken')->willThrowException(new DoesNotExistException('keine'));

        $this->voteService = $this->createMock(VoteService::class);
        $this->voteService->method('playerNickname')->willReturn(null);

        $roomService = $this->createMock(RoomService::class);
        $roomService->method('presentCount')->willReturn(0);

        $deck = $this->createMock(DeckService::class);
        $deck->method('requirePollInRoom')->willReturnCallback(fn (): Poll => $this->current);
        $deck->method('deck')->willReturnCallback(fn (): array => [$this->current]);
        $deck->method('ownerPoll')->willReturn([]);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1000);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $l10n->method('getLocaleCode')->willReturn('en');

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->willReturnCallback(
            static fn (string $key, string $default = ''): string => $key === 'secret' ? 'test-secret' : $default,
        );

        $this->service = (new \ReflectionClass(StateService::class))->newInstanceWithoutConstructor();
        foreach ([
            'pollMapper' => $this->polls,
            'voteMapper' => $votes,
            'tallyService' => new TallyService(),
            'deckService' => $deck,
            'voteService' => $this->voteService,
            'roomService' => $roomService,
            'timeFactory' => $time,
            'l10n' => $l10n,
            'config' => $config,
        ] as $name => $value) {
            (new ReflectionProperty(StateService::class, $name))->setValue($this->service, $value);
        }
    }

    public function testWortwolkeOeffentlichGekapptBeimModeratorVoll(): void {
        $this->current = $this->poll('words', 'active');
        for ($i = 0; $i < 150; $i++) {
            $this->votes[] = $this->vote($i, ['wort' . $i, 'alle']);
        }
        $room = $this->room('poll');

        $public = $this->service->publicState($room, null)['results'];
        $this->assertCount(PublicPayload::LIST_TOP, $public['results']);
        $this->assertSame(['word' => 'alle', 'count' => 150], $public['results'][0]);
        $this->assertSame(151, $public['resultsTotal']);
        $this->assertSame(300, $public['mentions']);
        $this->assertSame(150, $public['total']);

        $this->assertCount(151, $this->service->results($room, 1)['results'], 'Moderator: alle Wörter');
        $this->assertCount(151, $this->service->summary($room)[0]['results']['results'], 'Moderator-Übersicht: alle Wörter');
        $csvRows = substr_count($this->service->exportCsv($room), "\n");
        $this->assertSame(1 + 151, $csvRows, 'CSV: Kopfzeile + jedes Wort');
    }

    public function testFreitextUndKompassOeffentlichGekappt(): void {
        $this->current = $this->poll('text', 'active');
        for ($i = 0; $i < 250; $i++) {
            $this->votes[] = $this->vote($i, 'Antwort ' . $i);
        }
        $text = $this->service->publicState($this->room('poll'), null)['results'];
        $this->assertCount(PublicPayload::LIST_TOP, $text['answers']);
        $this->assertSame(250, $text['answersTotal']);
        $this->assertCount(250, $this->service->results($this->room('poll'), 1)['answers']);

        $this->current = $this->poll('scale', 'active', json_encode(['mode' => 'compass', 'range' => 5]));
        $this->votes = [];
        for ($i = 0; $i < 2000; $i++) {
            $this->votes[] = $this->vote($i, ['x' => $i % 11 - 5, 'y' => 2]);
        }
        $compass = $this->service->publicState($this->room('poll'), null)['results'];
        $this->assertCount(PublicPayload::COMPASS_SAMPLE, $compass['points']);
        $this->assertSame(2000, $compass['pointsTotal']);
        $this->assertCount(2000, $this->service->results($this->room('poll'), 1)['points']);
    }

    public function testQuizRanglisteZehnPlusEigeneZeile(): void {
        $this->current = $this->poll('choice', 'ended');
        $rows = self::board(30, me: 20);
        $this->voteService->method('leaderboardFor')->willReturnCallback(
            static fn (Room $room, ?string $token): array => $token === null ? self::board(30) : $rows,
        );
        $room = $this->room('quiz');

        $state = $this->service->publicState($room, 'tok-me');
        $this->assertCount(10, $state['leaderboard']);
        $this->assertSame(30, $state['leaderboardTotal']);
        $this->assertSame(21, $state['leaderboardMe']['rank']);
        $this->assertSame([19, 20, 21, 22, 23], array_column($state['leaderboardAround'], 'rank'));

        $summary = $this->service->publicSummary($room, 'tok-me');
        $this->assertCount(10, $summary['leaderboard']);
        $this->assertSame(30, $summary['leaderboardTotal']);
        $this->assertSame(21, $summary['leaderboardMe']['rank']);

        $this->assertCount(30, $this->service->results($room, 1)['leaderboard'], 'Moderator: die volle Rangliste');
    }

    public function testQuizVorDerAufloesungLeereZusatzfelder(): void {
        $this->current = $this->poll('choice', 'active');

        $state = $this->service->publicState($this->room('quiz'), 'tok-me');

        $this->assertNull($state['leaderboard']);
        $this->assertSame(0, $state['leaderboardTotal']);
        $this->assertNull($state['leaderboardMe']);
        $this->assertSame([], $state['leaderboardAround']);
    }

    public function testUmfrageOhneRanglistenfelder(): void {
        $this->current = $this->poll('choice', 'active');

        $state = $this->service->publicState($this->room('poll'), null);

        $this->assertArrayNotHasKey('leaderboard', $state);
        $this->assertArrayNotHasKey('leaderboardTotal', $state);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function room(string $mode): Room {
        $room = new Room();
        $room->setId(1);
        $room->setCode('ABCDEF');
        $room->setMode($mode);
        $room->setActivePollId(1);
        return $room;
    }

    private function poll(string $type, string $status, ?string $options = null): Poll {
        $poll = new Poll();
        $poll->setId(1);
        $poll->setRoomId(1);
        $poll->setType($type);
        $poll->setQuestion('Frage');
        $poll->setOptions($options ?? json_encode([['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B']]));
        $poll->setStatus($status);
        $poll->setStartedAt(900);
        $poll->setMaxWords(3);
        return $poll;
    }

    private function vote(int $i, mixed $value): Vote {
        $vote = new Vote();
        $vote->setId($i + 1);
        $vote->setPollId(1);
        $vote->setVoterToken('tok-' . $i);
        $vote->setPayload(json_encode(['value' => $value]));
        return $vote;
    }

    private static function board(int $n, ?int $me = null): array {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['rank' => $i + 1, 'nickname' => 'P' . ($i + 1), 'score' => 1000 - $i, 'correct' => 1, 'me' => $i === $me];
        }
        return $rows;
    }
}
