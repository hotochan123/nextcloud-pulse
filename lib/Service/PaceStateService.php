<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\AppInfo\Application;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\PresenceMapper;
use OCA\Pulse\Db\Progress;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IL10N;

/**
 * Read views of the self-paced quiz in one place: phone and projector
 * state, the overall summary for the phone, image access, the moderator
 * results, the progress per person and question, and the two CSV views.
 * StateService delegates here in its first line (via
 * PaceService::isSelf) — so its moderated code stays untouched.
 * Writes nothing.
 *
 * Rules for everything that goes out publicly:
 * - Verdict, points and leaderboard only from final votes
 *   (PaceService::isFinal with correctable per row). Never a solution and never
 *   a distribution before the release, never the order of the deck.
 * - The version is a keyed hash over the fully built state without
 *   serverNow. Everything time-driven (deadline, finality, time running out) is
 *   in the payload as state — so the version jumps exactly then, without a job
 *   and without a second computation rule that could drift apart. In return,
 *   nothing except serverNow may change every second (no remaining time).
 * - The expensive room aggregates (projector, public leaderboard) stay 2 s in
 *   the local cache; the phone only reads its own rows and votes.
 */
class PaceStateService {
    /** Projector state: the finished payload stays this many seconds per bucket in the local cache. */
    private const BEAMER_BUCKET = 2;
    /** Projector during the race: at most this many leaderboard rows. */
    private const BEAMER_TOP = 8;

    public function __construct(
        private PaceService $paceService,
        private VoteService $voteService,
        private TallyService $tallyService,
        private RoomService $roomService,        // presentCount
        private DeckService $deckService,        // requirePollInRoom (results)
        private PollMapper $pollMapper,
        private VoteMapper $voteMapper,
        private PlayerMapper $playerMapper,
        private ProgressMapper $progressMapper,
        private PresenceMapper $presenceMapper,
        private ITimeFactory $timeFactory,
        private IDateTimeFormatter $dateTimeFormatter,
        private IConfig $config,                 // secret for PublicView
        private ICacheFactory $cacheFactory,     // projector state 2 s
        private IL10N $l10n,
    ) {
    }

    // ── Public state (/state) ───────────────────────────────────────────────

    /**
     * State for the phone or (spectate) for the projector. `results` is
     * always null when self-paced — distributions only exist in /summary after
     * the release.
     *
     * Anyone may fetch the projector state, and it reads the whole room
     * aggregate; the finished payload is therefore kept in the local cache per
     * room and 2-s bucket. Window changes are part of the key and take effect
     * immediately (including the expiring deadline, via the effective close),
     * votes and progress appear at most 2 s later. serverNow is only filled in
     * after the cache; the version (without serverNow) stays the same within
     * the bucket, so 204 keeps working. Without APCu it is built every time.
     */
    public function publicState(Room $room, ?string $voterToken, bool $spectate): array {
        $now = $this->timeFactory->getTime();
        if (!$spectate) {
            return $this->phoneState($room, $voterToken, $now);
        }
        $cache = $this->cacheFactory->createLocal('pulse');
        $key = 'beamer:' . $room->getId() . ':' . $room->getOpenedAt()
            . ':' . PaceService::effectiveClosedAt($room, $now) . ':' . $room->getClosesAt()
            . ':' . $room->getReleasedAt() . ':' . intdiv($now, self::BEAMER_BUCKET);
        $state = $cache->get($key);
        if (!is_array($state)) {
            $state = $this->beamerState($room, $now);
            $cache->set($key, $state, self::BEAMER_BUCKET + 1);
        }
        $state['serverNow'] = $now;
        return $state;
    }

    /**
     * Version of a fully built payload (phone, projector, moderator
     * results, progress): opaque keyed hash without serverNow.
     * In the progress, "last seen" is also left out — it changes
     * with every phone heartbeat (~1.3 s), and the moderator would never get
     * a 204 during the race. Only `online` goes into the hash.
     */
    public function version(array $payload): string {
        unset($payload['serverNow'], $payload['version']);
        if (isset($payload['players']) && is_array($payload['players'])) {
            foreach ($payload['players'] as &$player) {
                if (is_array($player)) {
                    unset($player['lastSeen']);
                }
            }
            unset($player);
        }
        return PublicView::opaque('self:' . json_encode($payload, JSON_PARTIAL_OUTPUT_ON_ERROR), $this->secret());
    }

    /**
     * Phone: own question with a personal clock, own progress, own
     * verdict. After the release every cookie gets the final standings (even
     * without a player — non-participants get nothing more than that).
     */
    private function phoneState(Room $room, ?string $voterToken, int $now): array {
        $state = PaceService::deriveState($room, $now);
        $released = $state === PaceService::STATE_RELEASED;
        $out = $this->head($room, $now);
        if ($state === PaceService::STATE_DRAFT) {
            // Waiting state: "N here" as in the moderated lobby. Only here —
            // afterwards every heartbeat would push up the version of all phones.
            $out['present'] = $this->roomService->presentCount($room);
        }
        $out['nickname'] = $this->voteService->playerNickname($room, $voterToken);
        $out['progress'] = null;
        $out['myResult'] = null;
        $out['myScore'] = null;
        if ($released && !$room->getPractice()) {
            $out['leaderboard'] = $this->voteService->selfLeaderboard($room, $voterToken, 0, true);
        }
        if ($voterToken === null || $out['nickname'] === null || $room->getOpenedAt() === 0) {
            return $out;
        }

        $rows = $this->paceService->rows($room, $voterToken);
        $votes = $this->votesByPoll($rows, $voterToken);
        $open = PaceService::openOf($rows);
        $last = PaceService::lastOf($rows);
        $n = count(PaceService::order($room));
        // Defensive: the open row's question no longer exists -> no poll,
        // but `after` still points to it, and /next moves on with it.
        $poll = null;
        if ($open !== null) {
            try {
                $poll = $this->pollMapper->find($open->getPollId());
            } catch (DoesNotExistException) {
                $poll = null;
            }
        }
        $limit = $poll !== null ? $this->limitFor($room, $poll) : 0;

        $out['progress'] = [
            'k' => self::kOf($open, $last),
            'n' => $n,
            'started' => $rows !== [],
            'finished' => PaceService::isFinished($n, $last, $last !== null && isset($votes[$last->getPollId()]), $state),
            // Flips exactly once (no remaining time in the payload, otherwise there would never be a 204).
            'timeUp' => $open !== null && $limit > 0 && $now - $open->getStartedAt() > $limit,
            'currentPollId' => $open?->getPollId() ?? 0,
            // The value for /next {after}: without it a reloaded phone would never
            // get any further after an abort between closing and starting.
            'after' => PaceService::afterFor($rows),
        ];
        if ($released || PaceService::effectiveFeedback($room) === PaceService::FEEDBACK_EACH) {
            $out['myScore'] = $this->scoreOf($room, $rows, $votes, $now);
        }
        if ($state === PaceService::STATE_OPEN && $open !== null && $poll !== null) {
            $out['poll'] = $this->selfPoll($room, $poll, $open, false);
            $vote = $votes[$poll->getId()] ?? null;
            if ($vote !== null) {
                $out['hasVoted'] = true;
                $out['myValue'] = $vote->getValue();
                $out['myResult'] = $this->verdict($room, $vote, $open, $now, false);
            }
        }
        return $out;
    }

    /**
     * Projector: the race as numbers (who is on which question, who is
     * done) and the leaderboard — never questions, options or distributions.
     *
     * Every person who started counts exactly once: finished (PaceService::isFinished —
     * including anyone who answered the last question but did not tap "I’m done", and
     * after closing everyone on the last question) or on their question k (kOf: open
     * row, otherwise the last one reached — the same number as on the phone and in the
     * progress view). Not started (joined − started) + per question + finished =
     * joined; the projector bases every bar on that.
     */
    private function beamerState(Room $room, int $now): array {
        $state = PaceService::deriveState($room, $now);
        $out = $this->head($room, $now);
        // Spectators count who is currently here (entrance display), in every state.
        $out['present'] = $this->roomService->presentCount($room);

        $order = PaceService::order($room);
        $n = count($order);
        /** @var array<string, Progress[]> $byToken */
        $byToken = [];
        $lastPolls = [];
        foreach ($this->progressMapper->findByRoom($room->getId()) as $row) {
            $byToken[$row->getVoterToken()][] = $row;
            if ($row->getLeftAt() === 0 && $row->getSeq() === $n - 1) {
                $lastPolls[$row->getPollId()] = true;
            }
        }
        // Only those standing open on the last question need the vote for isFinished
        // — one query over this one question instead of over the whole deck.
        $answeredLast = [];
        if ($state === PaceService::STATE_OPEN && $lastPolls !== []) {
            foreach ($this->voteMapper->findByPolls(array_keys($lastPolls)) as $vote) {
                $answeredLast[$vote->getPollId() . ':' . $vote->getVoterToken()] = true;
            }
        }
        $onQuestion = array_fill(0, $n, 0);
        $finished = 0;
        foreach ($byToken as $token => $rows) {
            $last = PaceService::lastOf($rows);
            $answered = $last !== null && isset($answeredLast[$last->getPollId() . ':' . $token]);
            if (PaceService::isFinished($n, $last, $answered, $state)) {
                $finished++;
                continue; // done — not also on the last question
            }
            // Open row, otherwise the last one reached (abort between closing
            // and starting in /next) — never in no bar at all.
            $k = self::kOf(PaceService::openOf($rows), $last);
            if ($k >= 1 && $k <= $n) {
                $onQuestion[$k - 1]++; // defensive: a seq outside the order counts nowhere
            }
        }
        $out['race'] = [
            'n' => $n,
            'joined' => $this->playerMapper->countByRoom($room->getId()),
            'started' => count($byToken),
            'finished' => $finished,
            'onQuestion' => $onQuestion,
        ];

        // Practice run: never a leaderboard. After the release the full final standings;
        // during the race (only with feedback after each question) the top.
        if ($room->getPractice()) {
            $out['leaderboard'] = null;
        } elseif ($state === PaceService::STATE_RELEASED) {
            $out['leaderboard'] = $this->voteService->selfLeaderboard($room, null, 0, true);
        } elseif (($state === PaceService::STATE_OPEN || $state === PaceService::STATE_CLOSED)
            && PaceService::effectiveFeedback($room) === PaceService::FEEDBACK_EACH) {
            $out['leaderboard'] = $this->voteService->selfLeaderboard($room, null, self::BEAMER_TOP, true);
        }
        return $out;
    }

    /** Common head of phone and projector state. */
    private function head(Room $room, int $now): array {
        return [
            'room' => [
                'code' => $room->getCode(),
                'title' => $room->titleOrEmpty(),
                'mode' => $room->getMode(),
                'pace' => $room->getPace(),
            ],
            // If the bundle in the tab no longer matches the server, it reloads once.
            'protocol' => Application::PROTOCOL,
            'serverNow' => $now,
            'practice' => $room->getPractice(),
            'window' => $this->window($room, $now),
            'present' => 0,
            'poll' => null,
            'results' => null,
            'hasVoted' => false,
            'myValue' => null,
            'answered' => 0,
            'leaderboard' => null,
        ];
    }

    // ── Overall summary for the phone (/summary) ────────────────────────────

    /**
     * Review for the phone. Without a cookie or without a reached question, at most
     * the final standings — so /summary is never an answer key. With rows before
     * the release, only one's own questions (in the open window only the ones left;
     * the open one is on the main screen), shuffled and without a solution.
     * After the release exactly the reached questions with solution and tally —
     * only those: otherwise one tap on "Start quiz" would be enough for the whole key.
     *
     * @return array{available:bool, mode:string, pace:string, practice:bool, title:string, window:array, myScore:?int, leaderboard:?list<array>, items:list<array{poll:array, revealed:bool, results:?array, mine:?array}>}
     */
    public function publicSummary(Room $room, ?string $voterToken): array {
        $now = $this->timeFactory->getTime();
        $state = PaceService::deriveState($room, $now);
        $released = $state === PaceService::STATE_RELEASED;
        $out = [
            'available' => false,
            'mode' => $room->getMode(),
            'pace' => $room->getPace(),
            'practice' => $room->getPractice(),
            'title' => $room->titleOrEmpty(),
            'window' => $this->window($room, $now),
            'myScore' => null,
            'leaderboard' => null,
            'items' => [],
        ];
        $rows = ($voterToken !== null && $voterToken !== '') ? $this->paceService->rows($room, $voterToken) : [];
        if ($rows === []) {
            if ($released && !$room->getPractice()) {
                $out['leaderboard'] = $this->voteService->selfLeaderboard($room, null, 0, true);
            }
            $out['available'] = $out['leaderboard'] !== null;
            return $out;
        }

        $votes = $this->votesByPoll($rows, (string)$voterToken);
        if ($released || PaceService::effectiveFeedback($room) === PaceService::FEEDBACK_EACH) {
            $out['myScore'] = $this->scoreOf($room, $rows, $votes, $now);
        }
        $shown = $state === PaceService::STATE_OPEN
            ? array_values(array_filter($rows, static fn (Progress $r): bool => $r->getLeftAt() > 0))
            : $rows;
        $polls = [];
        foreach ($this->pollMapper->findByRoom($room->getId()) as $poll) {
            $polls[$poll->getId()] = $poll;
        }
        // After the release the tally of all votes — in ONE query for
        // the reached questions.
        $all = [];
        if ($released) {
            $ids = array_map(static fn (Progress $r): int => $r->getPollId(), $shown);
            foreach ($this->voteMapper->findByPolls($ids) as $vote) {
                $all[$vote->getPollId()][] = $vote;
            }
        }
        foreach ($shown as $row) {
            $poll = $polls[$row->getPollId()] ?? null;
            if ($poll === null) {
                continue; // defensive: deleted question
            }
            $vote = $votes[$poll->getId()] ?? null;
            $out['items'][] = [
                'poll' => $this->selfPoll($room, $poll, $row, $released),
                'revealed' => $released,
                'results' => $released ? $this->tallyService->tally($poll, $all[$poll->getId()] ?? []) : null,
                'mine' => $vote === null ? null
                    : ['value' => $vote->getValue()] + $this->verdict($room, $vote, $row, $now, $released),
            ];
        }
        if ($released && !$room->getPractice()) {
            $out['leaderboard'] = $this->voteService->selfLeaderboard($room, $voterToken, 0, true);
        }
        $out['available'] = $out['items'] !== [] || $out['leaderboard'] !== null;
        return $out;
    }

    /**
     * Image of a question only for people who have reached it — in every
     * window state, so even after the release only for reached questions.
     */
    public function imageVisible(Room $room, Poll $poll, ?string $voterToken): bool {
        return $voterToken !== null && $voterToken !== ''
            && $this->progressMapper->hasRow($poll->getId(), $voterToken);
    }

    // ── Moderator ───────────────────────────────────────────────────────────

    /**
     * Results of a question for the moderator: tally of all votes,
     * plus window and leaderboard. There is no shared clock (startedAt 0),
     * the question runs for each person from their /next.
     *
     * @throws \InvalidArgumentException question does not belong to the room
     */
    public function results(Room $room, int $pollId): array {
        $poll = $this->deckService->requirePollInRoom($room, $pollId);
        $now = $this->timeFactory->getTime();
        $tally = $this->tallyService->tally($poll, $this->voteMapper->findByPoll($poll->getId()));
        $tally['present'] = $this->roomService->presentCount($room);
        $tally['serverNow'] = $now;
        $tally['startedAt'] = 0;
        $tally['timeLimit'] = $this->limitFor($room, $poll);
        $tally['status'] = 'active';
        $tally['practice'] = $room->getPractice();
        $tally['pace'] = $room->getPace();
        $tally['window'] = $this->window($room, $now);
        $tally['leaderboard'] = $room->getPractice() ? [] : $this->voteService->selfLeaderboard($room, null);
        return $tally;
    }

    /**
     * Progress per person and question (race/homework) for the moderator,
     * without a version (the controller sets it via version()).
     *
     * With feedback "At the end", points, hits and leaderboard stay hidden until
     * the release, except with $scores (explicit switch): the laptop is often
     * connected to the projector, and if Anna saw her `correct` jump, feedback
     * at the end would be void for everyone watching. Points only count
     * from final votes; `pendingAnswers` never carries a solution.
     */
    public function progress(Room $room, bool $scores = false): array {
        $now = $this->timeFactory->getTime();
        $state = PaceService::deriveState($room, $now);
        $released = $state === PaceService::STATE_RELEASED;
        $hidden = !$scores && !$released && PaceService::effectiveFeedback($room) === PaceService::FEEDBACK_END;
        $order = PaceService::order($room);
        $n = count($order);
        $kOf = array_flip($order); // pollId -> index in the order

        $polls = [];
        foreach ($this->pollMapper->findByRoom($room->getId()) as $poll) {
            $polls[$poll->getId()] = $poll;
        }
        [$byToken, $rowAt] = $this->indexRows($this->progressMapper->findByRoom($room->getId()));
        $reached = [];
        foreach ($rowAt as $row) {
            $reached[$row->getPollId()] = ($reached[$row->getPollId()] ?? 0) + 1;
        }

        $perQuestion = [];
        $perToken = [];
        $pending = []; // pollId -> norm -> {answer, count}
        foreach ($this->voteMapper->findByPolls($order) as $vote) {
            $d = self::payload($vote);
            $pid = $vote->getPollId();
            $t = $vote->getVoterToken();
            $q = &$perQuestion[$pid];
            $q ??= ['answered' => 0, 'correct' => 0, 'pending' => 0];
            $s = &$perToken[$t];
            $s ??= ['answered' => 0, 'correct' => 0, 'score' => 0, 'pending' => 0, 'voted' => [], 'lastVote' => 0];
            $q['answered']++;
            $s['answered']++;
            $s['voted'][$pid] = true;
            $s['lastVote'] = max($s['lastVote'], $vote->getCreatedAt());
            if (!empty($d['pending'])) {
                $q['pending']++;
                $s['pending']++;
                $raw = is_string($d['value'] ?? null) ? $d['value'] : '';
                $norm = (string)($d['norm'] ?? TallyService::normalizeText($raw));
                $pending[$pid][$norm] ??= ['answer' => $raw, 'count' => 0];
                $pending[$pid][$norm]['count']++;
            }
            $correctable = PaceService::correctable($room, $now, $rowAt[$pid . ':' . $t] ?? null);
            if (PaceService::isFinal($d, $vote->getCreatedAt(), $now, $correctable)) {
                $s['score'] += (int)($d['points'] ?? 0);
                if (!empty($d['correct'])) {
                    $q['correct']++;
                    $s['correct']++;
                }
            }
            unset($q, $s);
        }

        $questions = [];
        foreach ($order as $i => $pid) {
            $poll = $polls[$pid] ?? null;
            if ($poll === null) {
                continue; // defensive: deleted question
            }
            $q = $perQuestion[$pid] ?? ['answered' => 0, 'correct' => 0, 'pending' => 0];
            $questions[] = [
                'pollId' => $pid,
                'k' => $i + 1,
                'type' => $poll->getType(),
                'question' => $poll->getQuestion(),
                'reached' => $reached[$pid] ?? 0,
                'answered' => $q['answered'],
                'correct' => $hidden ? null : $q['correct'],
                'pending' => $q['pending'],
            ];
        }

        $lastSeen = [];
        foreach ($this->presenceMapper->findByRoom($room->getId()) as $presence) {
            $lastSeen[$presence->getVoterToken()] = $presence->getLastSeen();
        }
        $players = [];
        foreach ($this->playerMapper->findByRoom($room->getId()) as $player) {
            $t = $player->getVoterToken();
            $rows = $byToken[$t] ?? [];
            $s = $perToken[$t] ?? ['answered' => 0, 'correct' => 0, 'score' => 0, 'pending' => 0, 'voted' => [], 'lastVote' => 0];
            $open = PaceService::openOf($rows);
            $last = PaceService::lastOf($rows);
            $seen = $lastSeen[$t] ?? 0;
            $players[] = [
                'id' => $player->getId(),
                'nickname' => $player->getNickname(),
                'k' => self::kOf($open, $last),
                'started' => $rows !== [],
                'finished' => PaceService::isFinished($n, $last, $last !== null && isset($s['voted'][$last->getPollId()]), $state),
                'currentPollId' => $open?->getPollId() ?? 0,
                'currentStartedAt' => $open?->getStartedAt() ?? 0,
                'answered' => $s['answered'],
                // Left without an answer: a signal for throwaway players who just flip through.
                'skipped' => count(array_filter(
                    $rows,
                    static fn (Progress $r): bool => $r->getLeftAt() > 0 && !isset($s['voted'][$r->getPollId()]),
                )),
                'correct' => $hidden ? null : $s['correct'],
                'score' => $hidden ? null : $s['score'],
                'pending' => $s['pending'],
                'startedAt' => self::firstStart($rows),
                'lastActivity' => max($s['lastVote'], self::lastRowActivity($rows)),
                'online' => $seen > 0 && $seen >= $now - RoomService::PRESENCE_WINDOW,
                'lastSeen' => $seen,
            ];
        }
        usort($players, static fn (array $a, array $b): int => strcasecmp($a['nickname'], $b['nickname']));

        $pendingAnswers = [];
        foreach ($pending as $pid => $groups) {
            foreach ($groups as $g) {
                $pendingAnswers[] = ['pollId' => $pid, 'k' => ($kOf[$pid] ?? 0) + 1, 'answer' => $g['answer'], 'count' => $g['count']];
            }
        }
        usort($pendingAnswers, static fn (array $a, array $b): int => ($a['k'] <=> $b['k']) ?: ($b['count'] <=> $a['count']));

        return [
            'pace' => $room->getPace(),
            'practice' => $room->getPractice(),
            'serverNow' => $now,
            'present' => $this->roomService->presentCount($room),
            'window' => PaceService::windowView($room, $now, count($polls)),
            'n' => $n,
            'questions' => $questions,
            'players' => $players,
            'scoresHidden' => $hidden,
            'pendingAnswers' => $pendingAnswers,
            // Practice run: no scoring. Computed freshly (only one moderator).
            'leaderboard' => $room->getPractice() ? [] : ($hidden ? null : $this->voteService->selfLeaderboard($room, null)),
        ];
    }

    // ── CSV ─────────────────────────────────────────────────────────────────

    /**
     * CSV view for the teacher: `players` (one row per person, in leaderboard
     * order) or `answers` (one row per vote, by question, then
     * name). `;`-separated + UTF-8 BOM like the moderated export.
     *
     * @throws \InvalidArgumentException unknown view
     */
    public function exportCsv(Room $room, string $view): string {
        $lines = match ($view) {
            'players' => $this->playerLines($room),
            'answers' => $this->answerLines($room),
            default => throw new \InvalidArgumentException($this->l10n->t('Invalid request.')),
        };
        $fh = fopen('php://temp', 'r+');
        foreach ($lines as $line) {
            // Without backslash escaping (RFC 4180, the way Excel reads it).
            fputcsv($fh, $line, ';', '"', '');
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return "\xEF\xBB\xBF" . $csv; // UTF-8 BOM
    }

    /**
     * Rank, points and hits come from the leaderboard (fresh, final votes
     * only). "Time (s)" only sums votes with a timer — without a timer,
     * elapsed contains the whole idle time between /next and the answer, hours
     * for homework; if no vote has a limit, the cell stays empty.
     *
     * @return list<list<int|string>>
     */
    private function playerLines(Room $room): array {
        $now = $this->timeFactory->getTime();
        $state = PaceService::deriveState($room, $now);
        $order = PaceService::order($room);
        $n = count($order);
        [$byToken, $rowAt] = $this->indexRows($this->progressMapper->findByRoom($room->getId()));

        $perToken = [];
        foreach ($this->voteMapper->findByPolls($order) as $vote) {
            $d = self::payload($vote);
            $t = $vote->getVoterToken();
            $s = &$perToken[$t];
            $s ??= ['answered' => 0, 'voted' => [], 'lastVote' => 0, 'time' => null];
            $s['answered']++;
            $s['voted'][$vote->getPollId()] = true;
            $s['lastVote'] = max($s['lastVote'], $vote->getCreatedAt());
            $correctable = PaceService::correctable($room, $now, $rowAt[$vote->getPollId() . ':' . $t] ?? null);
            if ((int)($d['limit'] ?? 0) > 0 && PaceService::isFinal($d, $vote->getCreatedAt(), $now, $correctable)) {
                $s['time'] = ($s['time'] ?? 0) + max(0, (int)($d['elapsed'] ?? 0));
            }
            unset($s);
        }

        $lines = [[
            $this->l10n->t('Rank'),
            $this->l10n->t('Name'),
            $this->l10n->t('Score'),
            $this->l10n->t('Correct'),
            $this->l10n->t('Answered'),
            $this->l10n->t('Reached'),
            $this->l10n->t('Finished'),
            $this->l10n->t('Started'),
            $this->l10n->t('Last activity'),
            $this->l10n->t('Time (s)'),
        ]];
        foreach ($this->voteService->selfLeaderboardRows($room, $now) as $lb) {
            $t = $lb['token'];
            $rows = $byToken[$t] ?? [];
            $s = $perToken[$t] ?? ['answered' => 0, 'voted' => [], 'lastVote' => 0, 'time' => null];
            $last = PaceService::lastOf($rows);
            $finished = PaceService::isFinished($n, $last, $last !== null && isset($s['voted'][$last->getPollId()]), $state);
            $lines[] = [
                $lb['rank'],
                CsvFormat::cell($lb['nickname']),
                $lb['score'],
                $lb['correct'],
                $s['answered'],
                // With spaces: Excel would read "3/7" as a date.
                self::kOf(PaceService::openOf($rows), $last) . ' / ' . $n,
                $finished ? $this->l10n->t('yes') : $this->l10n->t('no'),
                $this->date(self::firstStart($rows)),
                $this->date(max($s['lastVote'], self::lastRowActivity($rows))),
                $s['time'] ?? '',
            ];
        }
        return $lines;
    }

    /**
     * One row per vote. Points and result only for final votes
     * ("—" otherwise); the time stays empty if the vote came without a timer.
     *
     * @return list<list<int|string>>
     */
    private function answerLines(Room $room): array {
        $now = $this->timeFactory->getTime();
        $order = PaceService::order($room);
        $kOf = array_flip($order);
        [, $rowAt] = $this->indexRows($this->progressMapper->findByRoom($room->getId()));
        $polls = [];
        foreach ($this->pollMapper->findByRoom($room->getId()) as $poll) {
            $polls[$poll->getId()] = $poll;
        }
        $names = [];
        foreach ($this->playerMapper->findByRoom($room->getId()) as $player) {
            $names[$player->getVoterToken()] = $player->getNickname();
        }

        $entries = [];
        foreach ($this->voteMapper->findByPolls($order) as $vote) {
            $poll = $polls[$vote->getPollId()] ?? null;
            if ($poll === null) {
                continue; // defensive: deleted question
            }
            $d = self::payload($vote);
            $t = $vote->getVoterToken();
            $correctable = PaceService::correctable($room, $now, $rowAt[$poll->getId() . ':' . $t] ?? null);
            $final = PaceService::isFinal($d, $vote->getCreatedAt(), $now, $correctable);
            $result = match (true) {
                !$final => '—',
                !empty($d['pending']) => $this->l10n->t('Pending'),
                !empty($d['correct']) => $this->l10n->t('Correct'),
                default => $this->l10n->t('Wrong'),
            };
            $k = ($kOf[$poll->getId()] ?? 0) + 1;
            $name = $names[$t] ?? '';
            $entries[] = [$k, $name, [
                CsvFormat::cell($name),
                $k,
                $poll->getQuestion(),
                CsvFormat::typeLabel($poll->getType(), $this->l10n),
                $this->answerText($poll, $d['value'] ?? null),
                $result,
                $final ? (int)($d['points'] ?? 0) : '',
                (int)($d['limit'] ?? 0) > 0 ? max(0, (int)($d['elapsed'] ?? 0)) : '',
                $this->date($vote->getCreatedAt()),
            ]];
        }
        usort($entries, static fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: strcasecmp($a[1], $b[1]));

        $lines = [[
            $this->l10n->t('Name'),
            $this->l10n->t('No.'),
            $this->l10n->t('Question'),
            $this->l10n->t('Type'),
            $this->l10n->t('Answer'),
            $this->l10n->t('Result'),
            $this->l10n->t('Points'),
            $this->l10n->t('Time (s)'),
            $this->l10n->t('Answered at'),
        ]];
        foreach ($entries as $entry) {
            $lines[] = $entry[2];
        }
        return $lines;
    }

    /** Answer in readable form: option texts instead of IDs, number in the export's language. */
    private function answerText(Poll $poll, mixed $value): string {
        $labels = [];
        foreach ($poll->getOptionsArray() as $option) {
            if (is_array($option)) {
                $labels[(string)($option['id'] ?? '')] = (string)($option['label'] ?? '');
            }
        }
        $label = static fn (mixed $id): string => $labels[(string)$id] ?? (string)$id;
        $list = static fn (mixed $v): array => is_array($v) ? array_values(array_filter($v, 'is_scalar')) : [];
        switch ($poll->getType()) {
            case 'choice':
            case 'truefalse':
                return is_scalar($value) ? $label($value) : '';
            case 'multi':
                return implode(', ', array_map($label, $list($value)));
            case 'rank':
                return implode(' > ', array_map($label, $list($value)));
            case 'match':
                $cfg = $poll->getMatchConfig();
                $targets = array_column($cfg['targets'], 'label', 'id');
                $pairs = [];
                foreach ($cfg['items'] as $item) {
                    $pick = is_array($value) ? ($value[$item['id']] ?? null) : null;
                    if (is_scalar($pick)) {
                        $pairs[] = $this->l10n->t('%1$s → %2$s', [$item['label'], $targets[(string)$pick] ?? (string)$pick]);
                    }
                }
                return implode('; ', $pairs);
            case 'number':
                return is_numeric($value) ? CsvFormat::number($value, $this->l10n->getLocaleCode()) : '';
            default:
                // Free text raw (only defused against formulas, see CsvFormat::cell()).
                return is_string($value) ? CsvFormat::cell($value) : '';
        }
    }

    // ── Building blocks ─────────────────────────────────────────────────────

    /**
     * Verdict on one's own vote — ONE function for state and
     * summary. Never a solution, never a distribution, only one's own verdict:
     * not final -> none yet; feedback "At the end" before the release ->
     * only "Answer saved"; free text without grading -> "Being checked".
     *
     * @param ?Progress $row the person's row for this question (for correctable)
     * @return array{answered:bool, final:bool, verdict:?string, correct:?bool, points:?int}
     */
    private function verdict(Room $room, Vote $vote, ?Progress $row, int $now, bool $released): array {
        $d = self::payload($vote);
        $final = PaceService::isFinal($d, $vote->getCreatedAt(), $now, PaceService::correctable($room, $now, $row));
        $out = ['answered' => true, 'final' => $final, 'verdict' => null, 'correct' => null, 'points' => null];
        if (!$final) {
            return $out;
        }
        if (PaceService::effectiveFeedback($room) === PaceService::FEEDBACK_END && !$released) {
            $out['verdict'] = 'saved';
        } elseif (!empty($d['pending'])) {
            $out['verdict'] = 'pending';
        } elseif (!empty($d['correct'])) {
            $out['verdict'] = 'correct';
            $out['correct'] = true;
            $out['points'] = (int)($d['points'] ?? 0);
        } else {
            $out['verdict'] = 'wrong';
            $out['correct'] = false;
            $out['points'] = 0;
        }
        return $out;
    }

    /**
     * Own points: sum of the final votes (free text "Being checked"
     * counts 0).
     *
     * @param Progress[] $rows own rows
     * @param array<int, Vote> $votes own votes per pollId
     */
    private function scoreOf(Room $room, array $rows, array $votes, int $now): int {
        $rowFor = [];
        foreach ($rows as $row) {
            $rowFor[$row->getPollId()] = $row;
        }
        $sum = 0;
        foreach ($votes as $pollId => $vote) {
            $d = self::payload($vote);
            $correctable = PaceService::correctable($room, $now, $rowFor[$pollId] ?? null);
            if (empty($d['pending']) && PaceService::isFinal($d, $vote->getCreatedAt(), $now, $correctable)) {
                $sum += (int)($d['points'] ?? 0);
            }
        }
        return $sum;
    }

    /**
     * Question for the public view from this person's perspective: before the
     * release shuffled and without a solution (PublicView::poll), their own clock
     * instead of the cursor's, without a timer limit 0 (no countdown), position =
     * their own place in the frozen order.
     */
    private function selfPoll(Room $room, Poll $poll, Progress $row, bool $revealed): array {
        $data = PublicView::poll($room, $poll, $revealed, $this->secret());
        $data['startedAt'] = $row->getStartedAt();
        $data['timeLimit'] = $this->limitFor($room, $poll);
        $data['position'] = $row->getSeq();
        $data['status'] = 'active';
        $data['revealed'] = $revealed;
        if ($revealed) {
            $data['correctOption'] = $poll->getCorrectOption();
            $data['answerKey'] = $poll->getAnswerKeyArray();
        }
        return $data;
    }

    /** Window for state and summary; the deck size is only needed in draft. */
    private function window(Room $room, int $now): array {
        $deckCount = $room->getOpenedAt() > 0 ? 0 : $this->pollMapper->countByRoom($room->getId());
        return PaceService::windowView($room, $now, $deckCount);
    }

    /** Time limit in the window: without a timer 0 (no limit, flat points). */
    private function limitFor(Room $room, Poll $poll): int {
        return $room->getTimed() ? $poll->getTimeLimit() : 0;
    }

    /**
     * Own votes for the reached questions, in ONE query.
     *
     * @param Progress[] $rows
     * @return array<int, Vote> per pollId
     */
    private function votesByPoll(array $rows, string $voterToken): array {
        $ids = array_map(static fn (Progress $r): int => $r->getPollId(), $rows);
        $out = [];
        foreach ($this->voteMapper->findByPolls($ids, $voterToken) as $vote) {
            $out[$vote->getPollId()] = $vote;
        }
        return $out;
    }

    /**
     * Progress rows of a room per token and per "pollId:token".
     *
     * @param Progress[] $rows
     * @return array{0: array<string, Progress[]>, 1: array<string, Progress>}
     */
    private function indexRows(array $rows): array {
        $byToken = [];
        $rowAt = [];
        foreach ($rows as $row) {
            $byToken[$row->getVoterToken()][] = $row;
            $rowAt[$row->getPollId() . ':' . $row->getVoterToken()] = $row;
        }
        return [$byToken, $rowAt];
    }

    /** "Question k of n": the open one, otherwise the last one reached, otherwise 0. */
    private static function kOf(?Progress $open, ?Progress $last): int {
        if ($open !== null) {
            return $open->getSeq() + 1;
        }
        return $last !== null ? $last->getSeq() + 1 : 0;
    }

    /** @param Progress[] $rows */
    private static function firstStart(array $rows): int {
        $starts = array_map(static fn (Progress $r): int => $r->getStartedAt(), $rows);
        return $starts === [] ? 0 : min($starts);
    }

    /** @param Progress[] $rows */
    private static function lastRowActivity(array $rows): int {
        $last = 0;
        foreach ($rows as $row) {
            $last = max($last, $row->getStartedAt(), $row->getLeftAt());
        }
        return $last;
    }

    /** @return array<string, mixed> */
    private static function payload(Vote $vote): array {
        $d = json_decode($vote->getPayload(), true);
        return is_array($d) ? $d : [];
    }

    /** Timestamp for the CSV, empty for 0. */
    private function date(int $ts): string {
        return $ts > 0 ? (string)$this->dateTimeFormatter->formatDateTime($ts, 'short', 'medium') : '';
    }

    private function secret(): string {
        return $this->config->getSystemValueString('secret', '');
    }
}
