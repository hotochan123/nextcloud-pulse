<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Player;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Presence;
use OCA\Pulse\Db\PresenceMapper;
use OCA\Pulse\Db\Progress;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\PaceStateService;
use OCA\Pulse\Service\QuizService;
use OCA\Pulse\Service\RoomService;
use OCA\Pulse\Service\TallyService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Shared world for the tests of the self-paced read views
 * (PaceStateService): one room with three questions in memory, mappers as
 * doubles over these lists, an adjustable clock and a local in-memory
 * cache. PaceService, VoteService (leaderboard), TallyService and
 * DeckService are real — so phone, projector, progress and leaderboard
 * are computed in the tests by the same rules as on the server.
 *
 * Deck (frozen order 11, 12, 13):
 * - 11 choice 'Hauptstadt von Frankreich?' (capital of France; Paris AA correct, Berlin BB), limit 20
 * - 12 ranking 'Planeten nach Größe' (planets by size: Jupiter, Saturn, Neptun), limit 20
 * - 13 free text 'Größter Planet?' (largest planet; accepted: Jupiter), limit 20
 */
abstract class PaceStateTestCase extends TestCase {

    protected const NOW = 1_800_000_000; // even: NOW and NOW+1 fall into the same 2-s bucket
    protected const SECRET = 'test-secret';

    protected PaceStateService $service;
    protected Room $room;
    protected int $now = self::NOW;
    protected int $present = 0;

    /** @var array<int, Poll> */
    protected array $polls = [];
    /** @var Progress[] */
    protected array $rows = [];
    /** @var Vote[] */
    protected array $votes = [];
    /** @var Player[] */
    protected array $players = [];
    /** @var array<string, int> Token -> last_seen */
    protected array $seen = [];
    /** @var array<string, mixed> contents of the local cache */
    protected array $cacheStore = [];
    /** @var array<string, int> call counter per mapper method */
    protected array $calls = [];

    protected function setUp(): void {
        $this->polls = [11 => $this->choicePoll(), 12 => $this->rankPoll(), 13 => $this->textPoll()];
        $this->room = $this->room();
        $this->players = [
            $this->player(31, 'tok-anna', 'Anna'),
            $this->player(32, 'tok-ben', 'Ben'),
            $this->player(33, 'tok-cem', 'Cem'),
        ];

        $pollMapper = $this->createMock(PollMapper::class);
        $pollMapper->method('find')->willReturnCallback(
            fn (int $id): Poll => $this->polls[$id] ?? throw new DoesNotExistException('no question'),
        );
        $pollMapper->method('findByRoom')->willReturnCallback(fn (): array => array_values($this->polls));
        $pollMapper->method('countByRoom')->willReturnCallback(fn (): int => count($this->polls));

        $voteMapper = $this->createMock(VoteMapper::class);
        $voteMapper->method('findByPolls')->willReturnCallback(function (array $ids, ?string $token = null): array {
            $this->countCall('votes.findByPolls');
            return array_values(array_filter(
                $this->votes,
                static fn (Vote $v): bool => in_array($v->getPollId(), $ids, true)
                    && ($token === null || $v->getVoterToken() === $token),
            ));
        });
        $voteMapper->method('findByPoll')->willReturnCallback(fn (int $id): array => array_values(array_filter(
            $this->votes,
            static fn (Vote $v): bool => $v->getPollId() === $id,
        )));

        $playerMapper = $this->createMock(PlayerMapper::class);
        $playerMapper->method('findByRoom')->willReturnCallback(fn (): array => $this->players);
        $playerMapper->method('countByRoom')->willReturnCallback(fn (): int => count($this->players));
        $playerMapper->method('findByRoomAndToken')->willReturnCallback(function (int $roomId, string $token): Player {
            foreach ($this->players as $p) {
                if ($p->getVoterToken() === $token) {
                    return $p;
                }
            }
            throw new DoesNotExistException('no player');
        });

        $progressMapper = $this->createMock(ProgressMapper::class);
        $progressMapper->method('findByRoom')->willReturnCallback(function (): array {
            $this->countCall('progress.findByRoom');
            $rows = $this->rows;
            usort($rows, static fn (Progress $a, Progress $b): int => strcmp($a->getVoterToken(), $b->getVoterToken())
                ?: ($a->getSeq() <=> $b->getSeq()));
            return $rows;
        });
        $progressMapper->method('findByRoomAndToken')->willReturnCallback(function (int $roomId, string $token): array {
            $rows = array_values(array_filter($this->rows, static fn (Progress $r): bool => $r->getVoterToken() === $token));
            usort($rows, static fn (Progress $a, Progress $b): int => $a->getSeq() <=> $b->getSeq());
            return $rows;
        });
        $progressMapper->method('hasRow')->willReturnCallback(function (int $pollId, string $token): bool {
            foreach ($this->rows as $r) {
                if ($r->getPollId() === $pollId && $r->getVoterToken() === $token) {
                    return true;
                }
            }
            return false;
        });

        $presenceMapper = $this->createMock(PresenceMapper::class);
        $presenceMapper->method('findByRoom')->willReturnCallback(function (): array {
            $out = [];
            foreach ($this->seen as $token => $ts) {
                $p = new Presence();
                $p->setRoomId(5);
                $p->setVoterToken($token);
                $p->setLastSeen($ts);
                $out[] = $p;
            }
            return $out;
        });

        $roomService = $this->createMock(RoomService::class);
        $roomService->method('presentCount')->willReturnCallback(fn (): int => $this->present);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
        $l10n->method('getLocaleCode')->willReturn('en');

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->willReturnCallback(
            static fn (string $key, string $default = ''): string => $key === 'secret' ? self::SECRET : $default,
        );

        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->cacheStore[$key] ?? null);
        $cache->method('set')->willReturnCallback(function (string $key, mixed $value, int $ttl = 0): bool {
            $this->cacheStore[$key] = $value;
            return true;
        });
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createLocal')->willReturn($cache);

        $formatter = $this->createMock(IDateTimeFormatter::class);
        $formatter->method('formatDateTime')->willReturnCallback(static fn (int $ts): string => 'T' . $ts);

        $pace = self::build(PaceService::class, [
            'progressMapper' => $progressMapper,
            'pollMapper' => $pollMapper,
            'timeFactory' => $time,
            'l10n' => $l10n,
        ]);
        $deck = self::build(DeckService::class, ['pollMapper' => $pollMapper, 'l10n' => $l10n]);
        $voteService = self::build(VoteService::class, [
            'pollMapper' => $pollMapper,
            'voteMapper' => $voteMapper,
            'playerMapper' => $playerMapper,
            'quizService' => new QuizService(),
            'deckService' => $deck,
            'timeFactory' => $time,
            'l10n' => $l10n,
            'paceService' => $pace,
            'progressMapper' => $progressMapper,
            'cacheFactory' => $cacheFactory,
        ]);
        $this->service = self::build(PaceStateService::class, [
            'paceService' => $pace,
            'voteService' => $voteService,
            'tallyService' => new TallyService(),
            'roomService' => $roomService,
            'deckService' => $deck,
            'pollMapper' => $pollMapper,
            'voteMapper' => $voteMapper,
            'playerMapper' => $playerMapper,
            'progressMapper' => $progressMapper,
            'presenceMapper' => $presenceMapper,
            'timeFactory' => $time,
            'dateTimeFormatter' => $formatter,
            'config' => $config,
            'cacheFactory' => $cacheFactory,
            'l10n' => $l10n,
        ]);
    }

    /**
     * Build a service without its constructor and set the given dependencies
     * (as in the other reflection tests).
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected static function build(string $class, array $deps): object {
        $object = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        foreach ($deps as $name => $value) {
            (new ReflectionProperty($class, $name))->setValue($object, $value);
        }
        return $object;
    }

    private function countCall(string $what): void {
        $this->calls[$what] = ($this->calls[$what] ?? 0) + 1;
    }

    // ── Calls ──────────────────────────────────────────────────────────────

    protected function phone(string $token = 'tok-anna'): array {
        return $this->service->publicState($this->room, $token, false);
    }

    protected function beamer(): array {
        return $this->service->publicState($this->room, null, true);
    }

    // ── World ──────────────────────────────────────────────────────────────

    /** Open race: opened 100 s ago, no deadline, with timer, verdict per question. */
    protected function room(): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('AB12CD');
        $room->setTitle('Planeten');
        $room->setMode('quiz');
        $room->setPace('self');
        $room->setOpenedAt(self::NOW - 100);
        $room->setDeckOrder('[11,12,13]');
        $room->setTimed(true);
        $room->setFeedback('each');
        return $room;
    }

    /** Window closed (manually, with a deadline — so not released at the same time). */
    protected function close(): void {
        $this->room->setClosesAt(self::NOW + 3600);
        $this->room->setClosedAt(self::NOW - 1);
    }

    protected function release(): void {
        $this->room->setClosedAt(self::NOW - 1);
        $this->room->setReleasedAt(self::NOW - 1);
    }

    protected function choicePoll(): Poll {
        $poll = $this->poll(11, 'choice', 'Hauptstadt von Frankreich?', 0);
        $poll->setOptions(json_encode([['id' => 'AA', 'label' => 'Paris'], ['id' => 'BB', 'label' => 'Berlin']]));
        $poll->setCorrectOption('AA');
        return $poll;
    }

    protected function rankPoll(): Poll {
        $poll = $this->poll(12, 'rank', 'Planeten nach Größe', 1);
        $poll->setOptions(json_encode([
            ['id' => 'JU01', 'label' => 'Jupiter'],
            ['id' => 'SA02', 'label' => 'Saturn'],
            ['id' => 'NE03', 'label' => 'Neptun'],
        ]));
        $poll->setAnswerKey(json_encode(['order' => ['JU01', 'SA02', 'NE03']]));
        return $poll;
    }

    protected function textPoll(): Poll {
        $poll = $this->poll(13, 'text', 'Größter Planet?', 2);
        $poll->setAnswerKey(json_encode(['accepted' => ['Jupiter'], 'rejected' => []]));
        return $poll;
    }

    private function poll(int $id, string $type, string $question, int $position): Poll {
        $poll = new Poll();
        $poll->setId($id);
        $poll->setRoomId(5);
        $poll->setType($type);
        $poll->setQuestion($question);
        $poll->setStatus('active');
        $poll->setPosition($position);
        $poll->setTimeLimit(20);
        return $poll;
    }

    protected function player(int $id, string $token, string $nickname): Player {
        $player = new Player();
        $player->setId($id);
        $player->setRoomId(5);
        $player->setVoterToken($token);
        $player->setNickname($nickname);
        return $player;
    }

    /** Progress row; seq follows the order 11, 12, 13. */
    protected function row(int $pollId, string $token, int $startedAt, int $leftAt = 0): Progress {
        $row = new Progress();
        $row->setRoomId(5);
        $row->setPollId($pollId);
        $row->setVoterToken($token);
        $row->setSeq(array_search($pollId, [11, 12, 13], true));
        $row->setStartedAt($startedAt);
        $row->setLeftAt($leftAt);
        $this->rows[] = $row;
        return $row;
    }

    /** Vote with a payload as recordSelfVote writes it. */
    protected function vote(
        int $pollId,
        string $token,
        mixed $value,
        int $points,
        bool $correct,
        int $createdAt,
        int $elapsed = 5,
        int $limit = 20,
        int $fw = 3,
        ?bool $pending = null,
        bool $fixed = false,
    ): Vote {
        $payload = ['value' => $value, 'points' => $points, 'correct' => $correct, 'elapsed' => $elapsed];
        if ($pollId === 13) {
            $payload['norm'] = TallyService::normalizeText((string)$value);
        }
        if ($pending !== null) {
            $payload['pending'] = $pending;
        }
        $payload['limit'] = $limit;
        $payload['fw'] = $fw;
        if ($fixed) {
            $payload['fixed'] = true;
        }
        $vote = new Vote();
        $vote->setId(count($this->votes) + 1);
        $vote->setPollId($pollId);
        $vote->setVoterToken($token);
        $vote->setPayload(json_encode($payload));
        $vote->setCreatedAt($createdAt);
        $this->votes[] = $vote;
        return $vote;
    }

    /** Nickname -> value of a leaderboard column. */
    protected static function column(?array $leaderboard, string $field): array {
        return array_column($leaderboard ?? [], $field, 'nickname');
    }
}
