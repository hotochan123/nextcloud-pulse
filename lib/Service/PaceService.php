<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\PresenceMapper;
use OCA\Pulse\Db\Progress;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Db\VoteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Self-paced quiz: every person runs alone through the deck that was frozen
 * on opening; their clock for each question only starts with /next. The
 * moderator no longer steers a cursor, only the window (open, close,
 * extend, release) — without a deadline a "race", with a deadline a
 * "homework".
 *
 * Two halves:
 * - static and without DI: derive the state, read the order, finality.
 *   Paths that also run in moderated mode need these too (they branch via
 *   isSelf), and this way the reflection tests of the existing services
 *   don't have to inject anything extra.
 * - instance: window actions and guards under the room lock (locked()),
 *   plus /next.
 *
 * The window state is not stored anywhere; it is derived from the timestamps
 * (deriveState) — an expiring deadline needs no background job.
 */
class PaceService {
    use TTransactional;

    public const PACE_LIVE = 'live';
    public const PACE_SELF = 'self';
    public const FEEDBACK_EACH = 'each';
    public const FEEDBACK_END = 'end';
    public const STATE_DRAFT = 'draft';
    public const STATE_OPEN = 'open';
    public const STATE_CLOSED = 'closed';
    public const STATE_RELEASED = 'released';
    /** Deadline at least this far in the future (shorter is almost always a typo). */
    public const MIN_LEAD = 60;
    /** Deadline at most this far in the future. */
    public const MAX_LEAD = 30 * 86400;
    /**
     * Maximum number of players per self-paced room who have started (a
     * progress row) — the spam cap on joining. Joins that never start do not
     * count (VoteService::makeRoomForNewPlayer).
     */
    public const MAX_PLAYERS = 300;
    /**
     * Ceiling of joined players per quiz room: self-paced started or not,
     * and in a moderated quiz as well (VoteService::liveJoin). The default of
     * Limits::PLAYERS_PER_ROOM, which an instance can change.
     */
    public const MAX_JOINED = 2 * self::MAX_PLAYERS;
    /** At the ceiling, a player who never started makes room after this many seconds. */
    public const STALE_JOIN = 600;
    /**
     * /join requests per IP and quiz room in JOIN_PERIOD (PublicVoteController),
     * in both modes: only a flood guard. Every join takes the room lock and
     * compares the name with every player, so an unbounded stream from one
     * address would hold up everyone else's joins. Far above what a class or
     * a conference behind one NAT address sends — at most MAX_JOINED players,
     * each joining a few times. What bounds new names is NEW_PLAYER_LIMIT
     * (self-paced) and the caps, which count successful joins, not requests
     * (security review L4). An instance that raises the ceiling
     * (Limits::PLAYERS_PER_ROOM) raises this with it: joinLimit().
     */
    public const JOIN_LIMIT = 4 * self::MAX_JOINED;
    public const JOIN_PERIOD = 600;
    /**
     * NEW players per IP and self-paced room in JOIN_PERIOD
     * (VoteService::countNewPlayer) — the default of
     * Limits::NEW_PLAYERS_PER_ADDRESS. As many as the 120 join requests the
     * old flood guard allowed, so no class behind one NAT address that could
     * join before is turned away now; every retry is free on top.
     */
    public const NEW_PLAYER_LIMIT = 120;

    public function __construct(
        private RoomMapper $roomMapper,
        private PollMapper $pollMapper,
        private PlayerMapper $playerMapper,
        private ProgressMapper $progressMapper,
        private VoteMapper $voteMapper,          // /next preview lock, removePlayer
        private PresenceMapper $presenceMapper,  // removePlayer
        private RoomService $roomService,
        private IDBConnection $db,               // locked()
        private ITimeFactory $timeFactory,
        private IL10N $l10n,
    ) {
    }

    // ── Pure functions ──────────────────────────────────────────────────────

    /**
     * /join requests per IP and quiz room in JOIN_PERIOD for a ceiling of
     * $maxJoined players (Limits::PLAYERS_PER_ROOM): four per possible
     * player, never below JOIN_LIMIT.
     */
    public static function joinLimit(int $maxJoined): int {
        return max(self::JOIN_LIMIT, 4 * $maxJoined);
    }

    /** Does the room run self-paced? Only quiz rooms can. */
    public static function isSelf(Room $room): bool {
        return $room->getMode() === 'quiz' && $room->getPace() === self::PACE_SELF;
    }

    /**
     * Window state from the timestamps. The release beats everything; in the
     * second of closes_at the window is already closed (the same boundary everywhere).
     */
    public static function deriveState(Room $room, int $now): string {
        if ($room->getReleasedAt() > 0) {
            return self::STATE_RELEASED;
        }
        if ($room->getOpenedAt() === 0) {
            return self::STATE_DRAFT;
        }
        if (self::effectiveClosedAt($room, $now) > 0) {
            return self::STATE_CLOSED;
        }
        return self::STATE_OPEN;
    }

    /**
     * When the window closed: manually (closed_at) or through the expired
     * deadline. 0 = not closed yet.
     */
    public static function effectiveClosedAt(Room $room, int $now): int {
        if ($room->getClosedAt() > 0) {
            return $room->getClosedAt();
        }
        $closes = $room->getClosesAt();
        return ($closes > 0 && $now >= $closes) ? $closes : 0;
    }

    /** In a practice run the verdict is always immediate (openWindow stores it that way anyway). */
    public static function effectiveFeedback(Room $room): string {
        return $room->getPractice() ? self::FEEDBACK_EACH : $room->getFeedback();
    }

    /**
     * Is a vote final — does it count for points, verdict and leaderboard?
     * ONE rule for all views: corrected (`fixed`), no longer
     * correctable, or the correction window of the first answer (`fw`) is
     * over. Strictly ">": in the second created+fw a correction still works
     * (the same boundary as in the moderated quiz).
     *
     * $correctable = window open AND the person's row for this question is
     * still open. Whatever can no longer be corrected is final immediately —
     * otherwise the final standings would keep re-sorting for fw seconds after
     * closing, and a CSV taken right then would be missing points. No leak:
     * correcting is no longer possible from that point anyway.
     */
    public static function isFinal(#[\SensitiveParameter] array $payload, int $createdAt, int $now, bool $correctable = true): bool {
        return !empty($payload['fixed'])
            || !$correctable
            || ($now - $createdAt) > (int)($payload['fw'] ?? VoteService::FIX_WINDOW);
    }

    /** Can the person still correct their answer to this row's question? */
    public static function correctable(Room $room, int $now, ?Progress $row): bool {
        return self::deriveState($room, $now) === self::STATE_OPEN
            && $row !== null && $row->getLeftAt() === 0;
    }

    /**
     * Is the person through? They reached the last question and either left
     * it (tapped "I’m done"), answered it, or the window is closed. So whoever
     * gave the last answer but did not tap "I’m done" any more is
     * finished. ONE definition for phone, projector, progress and CSV.
     *
     * @param int $n questions in the frozen order
     * @param ?Progress $last the person's row with the highest seq
     */
    public static function isFinished(int $n, ?Progress $last, bool $answeredLast, string $state): bool {
        return $last !== null && $last->getSeq() === $n - 1
            && ($last->getLeftAt() > 0 || $answeredLast || $state !== self::STATE_OPEN);
    }

    /**
     * The value the client sends as /next {after}: the open question,
     * otherwise the last one reached, otherwise 0. Also catches a request that
     * broke off between closing the old row and starting the new one.
     *
     * @param Progress[] $rows rows of ONE person
     */
    public static function afterFor(array $rows): int {
        $open = self::openOf($rows);
        if ($open !== null) {
            return $open->getPollId();
        }
        return self::lastOf($rows)?->getPollId() ?? 0;
    }

    /**
     * Window for the room JSON and the state. After opening `total` comes from the
     * frozen order, before that from the current deck.
     */
    public static function windowView(Room $room, int $now, int $deckCount): array {
        return [
            'state' => self::deriveState($room, $now),
            'openedAt' => $room->getOpenedAt(),
            'closesAt' => $room->getClosesAt(),
            'closedAt' => self::effectiveClosedAt($room, $now),
            'releasedAt' => $room->getReleasedAt(),
            'timed' => (bool)$room->getTimed(),
            'feedback' => self::effectiveFeedback($room),
            'total' => $room->getOpenedAt() > 0 ? count(self::order($room)) : $deckCount,
        ];
    }

    /**
     * Frozen order (written on opening). The only source for
     * "Question k of n" and "next question" — never `position`. Robust against
     * garbage: only integer entries > 0, duplicates removed.
     *
     * @return list<int> poll IDs; [] in the draft
     */
    public static function order(Room $room): array {
        $raw = $room->getDeckOrder();
        if ($raw === null || $raw === '') {
            return [];
        }
        $list = json_decode($raw, true);
        if (!is_array($list)) {
            return [];
        }
        $ids = [];
        foreach ($list as $v) {
            if (is_int($v) || (is_string($v) && preg_match('/^\d+$/', $v) === 1)) {
                $id = (int)$v;
                if ($id > 0 && !isset($ids[$id])) {
                    $ids[$id] = true;
                }
            }
        }
        return array_keys($ids);
    }

    /**
     * Successor of $after in the frozen order. $after = 0 ->
     * first question. Unknown $after or last question -> null.
     *
     * $existing (poll IDs that still exist): missing entries are
     * skipped. Pure defence — since the deck is locked from opening on,
     * this should never kick in. seq stays the index in the FULL order.
     *
     * @param ?list<int> $existing null = do not filter
     * @return ?array{pollId:int, seq:int}
     */
    public static function nextAfter(Room $room, int $after, ?array $existing = null): ?array {
        $order = self::order($room);
        $start = 0;
        if ($after !== 0) {
            $idx = array_search($after, $order, true);
            if ($idx === false) {
                return null;
            }
            $start = $idx + 1;
        }
        $keep = $existing === null ? null : array_flip(array_map('intval', $existing));
        for ($i = $start, $n = count($order); $i < $n; $i++) {
            if ($keep === null || isset($keep[$order[$i]])) {
                return ['pollId' => $order[$i], 'seq' => $i];
            }
        }
        return null;
    }

    /**
     * A person's open row (left_at = 0). The algorithm in next() keeps
     * at most one open; if there are several, the first one applies. Public
     * because the read views (PaceStateService) have to make the same choice.
     *
     * @param Progress[] $rows
     */
    public static function openOf(array $rows): ?Progress {
        foreach ($rows as $row) {
            if ($row->getLeftAt() === 0) {
                return $row;
            }
        }
        return null;
    }

    /**
     * A person's last reached row (highest seq), null without rows.
     *
     * @param Progress[] $rows
     */
    public static function lastOf(array $rows): ?Progress {
        $last = null;
        foreach ($rows as $row) {
            if ($last === null || $row->getSeq() >= $last->getSeq()) {
                $last = $row;
            }
        }
        return $last;
    }

    // ── Lock and guards ─────────────────────────────────────────────────────

    /**
     * $fn($freshlyLockedRoom) in a transaction under SELECT … FOR UPDATE
     * on the room row. $fn checks guards and state on THIS row, not on
     * the room passed in (possibly stale) — so there is no check-then-act
     * between two tabs, add-in and browser. Every exception rolls
     * back and propagates. No unique violations inside $fn (on
     * PostgreSQL that would abort the whole transaction).
     *
     * If the room has been deleted in the meantime (other tab, cleanup job),
     * there is no row left to lock: RoomGoneException, which the controllers
     * turn into 404 — not a bare DoesNotExistException and thus 500.
     *
     * @return mixed return value of $fn
     * @throws RoomGoneException
     */
    public function locked(Room $room, callable $fn): mixed {
        return $this->atomic(function () use ($room, $fn): mixed {
            try {
                $fresh = $this->roomMapper->lockForUpdate($room->getId());
            } catch (DoesNotExistException) {
                throw new RoomGoneException();
            }
            return $fn($fresh);
        }, $this->db);
    }

    /**
     * Cursor control (current question, lock, end, demo) does not exist in
     * self-paced mode.
     *
     * @throws ConflictException
     */
    public function assertLiveControl(Room $room): void {
        if (self::isSelf($room)) {
            throw new ConflictException($this->l10n->t('Not available while the quiz runs at its own pace.'));
        }
    }

    /**
     * From opening until a reset the deck is locked — so the
     * frozen order also stays physically stable.
     *
     * @throws ConflictException
     */
    public function assertDeckEditable(Room $room): void {
        if (self::isSelf($room) && $room->getOpenedAt() > 0) {
            throw new ConflictException($this->l10n->t('The questions are locked since the quiz was opened. Reset the room to edit them.'));
        }
    }

    /**
     * Reset, practice run and switching the pace empty the room — not
     * while people are in the middle of the quiz.
     *
     * @throws ConflictException
     */
    public function assertNotOpen(Room $room): void {
        if (self::isSelf($room) && self::deriveState($room, $this->timeFactory->getTime()) === self::STATE_OPEN) {
            throw new ConflictException($this->l10n->t('Close the quiz first.'));
        }
    }

    // ── Moderator actions (all under the room lock) ─────────────────────────

    /**
     * Switch the pace. Empties the room like the practice-run toggle (votes,
     * players, presence, progress, window). The same value is a no-op —
     * a double click must not delete results.
     *
     * @return Room freshly loaded
     * @throws \InvalidArgumentException unknown pace / not a quiz room
     * @throws ConflictException         window currently open
     */
    public function setPace(Room $room, string $pace): Room {
        return $this->locked($room, function (Room $r) use ($pace): Room {
            if ($pace !== self::PACE_LIVE && $pace !== self::PACE_SELF) {
                throw new \InvalidArgumentException($this->l10n->t('Unknown pace.'));
            }
            if ($pace === self::PACE_SELF && $r->getMode() !== 'quiz') {
                throw new \InvalidArgumentException($this->l10n->t('Only quiz rooms can run at their own pace.'));
            }
            $this->assertNotOpen($r);
            if ($r->getPace() === $pace) {
                return $this->reload($r);
            }
            $r->setPace($pace);
            $this->roomMapper->update($r);
            $this->roomService->resetRoom($r);
            return $this->reload($r);
        });
    }

    /**
     * Open the window and freeze the order. Only from the draft.
     *
     * @param int $closesAt 0 = until closed manually (race), otherwise Unix seconds (homework)
     * @param ?bool $timed null = default (without a deadline with timer, with a deadline without)
     * @param ?string $feedback null = default (without a deadline 'each', with a deadline 'end')
     * @return Room freshly loaded
     * @throws ConflictException         not a self room / already opened
     * @throws \InvalidArgumentException empty deck / deadline out of range / unknown feedback
     */
    public function openWindow(Room $room, int $closesAt, ?bool $timed, ?string $feedback): Room {
        return $this->locked($room, function (Room $r) use ($closesAt, $timed, $feedback): Room {
            $now = $this->timeFactory->getTime();
            $this->assertSelf($r);
            if (self::deriveState($r, $now) !== self::STATE_DRAFT) {
                throw new ConflictException($this->l10n->t('The quiz has already been opened. Reset the room to start over.'));
            }
            $deck = $this->pollMapper->findByRoom($r->getId());
            if ($deck === []) {
                throw new \InvalidArgumentException($this->l10n->t('Add at least one question first.'));
            }
            $this->assertDeadline($closesAt, $now);
            $timed ??= $closesAt === 0;
            $feedback ??= $closesAt === 0 ? self::FEEDBACK_EACH : self::FEEDBACK_END;
            if ($feedback !== self::FEEDBACK_EACH && $feedback !== self::FEEDBACK_END) {
                throw new \InvalidArgumentException($this->l10n->t('Unknown feedback setting.'));
            }
            if ($r->getPractice()) {
                // Practice run: verdict always immediate, otherwise you see nothing while testing.
                $feedback = self::FEEDBACK_EACH;
            }
            $order = json_encode(array_map(static fn (Poll $p): int => $p->getId(), $deck));
            // Belt and braces on top of the lock: only from the draft of a self room.
            if (!$this->roomMapper->openIfDraft($r->getId(), $order, $now, $closesAt, $timed, $feedback)) {
                throw new ConflictException($this->l10n->t('The quiz has already been opened. Reset the room to start over.'));
            }
            return $this->reload($r);
        });
    }

    /**
     * Close the window. Whether that also releases the results is decided by
     * $release: null = rule based on the deadline (without a deadline, i.e. in a race, yes;
     * with a deadline, i.e. as homework, no — the release stays a separate
     * step); false = never ("Stop without releasing" in ONE call: afterwards
     * grade, release, reopen or reset); true = always. The
     * deadline stays as it is. $release only counts if this call closes an
     * open window: already closed = no-op, even with true
     * (releasing is then done with `release`).
     *
     * @param ?bool $release null = rule based on the deadline (clients without the parameter)
     * @return Room freshly loaded
     * @throws ConflictException not a self room / draft / already released
     */
    public function closeWindow(Room $room, ?bool $release = null): Room {
        return $this->locked($room, function (Room $r) use ($release): Room {
            $now = $this->timeFactory->getTime();
            $this->assertSelf($r);
            $state = self::deriveState($r, $now);
            if ($state === self::STATE_DRAFT) {
                throw new ConflictException($this->l10n->t('The quiz is not open.'));
            }
            if ($state === self::STATE_RELEASED) {
                throw new ConflictException($this->l10n->t('Results have already been released.'));
            }
            if ($state === self::STATE_OPEN) {
                $r->setClosedAt($now);
                if ($release ?? ($r->getClosesAt() === 0)) {
                    $r->setReleasedAt($now);
                }
                $this->roomMapper->update($r);
            }
            return $this->reload($r);
        });
    }

    /**
     * Extend the deadline or reopen a closed window.
     * Settings (timed, feedback) and order stay.
     *
     * @param int $closesAt new deadline; 0 = open until closed manually
     *        (after that `close` without `release` releases at the same time again)
     * @return Room freshly loaded
     * @throws ConflictException         not a self room / draft / already released
     * @throws \InvalidArgumentException deadline out of bounds
     */
    public function extendWindow(Room $room, int $closesAt): Room {
        return $this->locked($room, function (Room $r) use ($closesAt): Room {
            $now = $this->timeFactory->getTime();
            $this->assertSelf($r);
            $state = self::deriveState($r, $now);
            if ($state === self::STATE_DRAFT) {
                throw new ConflictException($this->l10n->t('The quiz is not open.'));
            }
            if ($state === self::STATE_RELEASED) {
                throw new ConflictException($this->l10n->t('Results have already been released.'));
            }
            $this->assertDeadline($closesAt, $now);
            $r->setClosesAt($closesAt);
            $r->setClosedAt(0);
            $this->roomMapper->update($r);
            return $this->reload($r);
        });
    }

    /**
     * Release solutions and final standings. From the open window in one click:
     * it is closed at the same time, so no leak. After an expired deadline
     * its moment is pinned as the close. Already released = no-op.
     *
     * @return Room freshly loaded
     * @throws ConflictException not a self room / draft
     */
    public function releaseWindow(Room $room): Room {
        return $this->locked($room, function (Room $r): Room {
            $now = $this->timeFactory->getTime();
            $this->assertSelf($r);
            $state = self::deriveState($r, $now);
            if ($state === self::STATE_DRAFT) {
                throw new ConflictException($this->l10n->t('The quiz is not open.'));
            }
            if ($state === self::STATE_OPEN) {
                $r->setClosedAt($now);
                $r->setReleasedAt($now);
                $this->roomMapper->update($r);
            } elseif ($state === self::STATE_CLOSED) {
                if ($r->getClosedAt() === 0) {
                    $r->setClosedAt($r->getClosesAt());
                }
                $r->setReleasedAt($now);
                $this->roomMapper->update($r);
            }
            return $this->reload($r);
        });
    }

    /**
     * "Lock joining": new tokens are rejected, known players
     * still get in. A countermeasure against throwaway players, allowed in
     * every window state — and in a moderated (live) quiz as well, where
     * VoteService::liveJoin honours it the same way.
     *
     * @return Room freshly loaded
     * @throws ConflictException not a quiz room
     */
    public function setJoinsLocked(Room $room, bool $locked): Room {
        return $this->locked($room, function (Room $r) use ($locked): Room {
            $this->assertQuiz($r);
            $r->setJoinsLocked($locked);
            $this->roomMapper->update($r);
            return $this->reload($r);
        });
    }

    /**
     * Remove a person (throwaway player, name squatter): the player first —
     * the name becomes free —, then votes in the deck, progress, presence. Allowed in
     * every window state, even after the release (a squatter should not
     * remain in the final standings/CSV). The cookie is left without a name
     * afterwards and may join again as long as the quiz is open and not locked —
     * but then from question 1 with a new clock.
     *
     * The player first, because that keeps their row locked until the commit:
     * a /vote or /next by the same person that is currently writing waits for
     * it in assertStillJoined instead of leaving an orphaned vote or row
     * behind.
     *
     * Also in a moderated (live) quiz: there it takes the player and their
     * votes on every question of the room off the leaderboard, at any time,
     * also after the final standings. A live /vote does not re-check after
     * writing (no assertStillJoined there): a vote written in the same
     * instant can remain without a player — it adds one answer to that
     * question's distribution, never a leaderboard row, and a new name for
     * the same cookie starts empty (VoteService::liveJoin).
     *
     * @return Room freshly loaded
     * @throws ConflictException         not a quiz room
     * @throws \InvalidArgumentException the ID belongs to no player of this room
     */
    public function removePlayer(Room $room, int $playerId): Room {
        return $this->locked($room, function (Room $r) use ($playerId): Room {
            $this->assertQuiz($r);
            $player = null;
            foreach ($this->playerMapper->findByRoom($r->getId()) as $p) {
                if ((int)$p->getId() === $playerId) {
                    $player = $p;
                    break;
                }
            }
            if ($player === null) {
                throw new \InvalidArgumentException($this->l10n->t('Player not found.'));
            }
            $token = $player->getVoterToken();
            $this->playerMapper->delete($player);
            $this->forgetToken($r, $token);
            $this->presenceMapper->deleteByRoomAndToken($r->getId(), $token);
            return $this->reload($r);
        });
    }

    /**
     * Delete a token's votes in the deck and its progress; player and
     * presence stay. For removePlayer, the re-join of a removed
     * cookie (VoteService::selfJoin) and assertStillJoined.
     */
    public function forgetToken(Room $room, #[\SensitiveParameter] string $voterToken): void {
        // Opened: the frozen deck; in the draft there are no self votes.
        // A moderated room is never opened: every question of the room.
        $pollIds = $room->getOpenedAt() > 0
            ? self::order($room)
            : array_map(static fn (Poll $p): int => $p->getId(), $this->pollMapper->findByRoom($room->getId()));
        $this->voteMapper->deleteByPollsAndToken($pollIds, $voterToken);
        $this->progressMapper->deleteByRoomAndToken($room->getId(), $voterToken);
    }

    // ── Participants ────────────────────────────────────────────────────────

    /**
     * On to the next question — the ONLY place where a clock starts.
     * Idempotent: a second /next with the same $after does nothing, and neither
     * does a stale $after (double tap, second tab). There is never a way back.
     *
     * Without the room lock: everything is per token, the race of two tabs is decided
     * by the compare-and-set (closeIfOpen) or the unique index (start). If
     * a request breaks off between the two, the next /next heals it with the last
     * question left as $after (the client uses progress.after for that).
     * Against the person being removed in the middle of the request: assertStillJoined.
     *
     * @throws \InvalidArgumentException not a self room / draft / closed /
     *         without a name / $after < 0
     * @throws RoomGoneException          the room was deleted in the meantime
     */
    public function next(Room $room, #[\SensitiveParameter] string $voterToken, int $after): void {
        if (!self::isSelf($room)) {
            throw new \InvalidArgumentException($this->l10n->t('This room is not self-paced.'));
        }
        if ($after < 0) {
            throw new \InvalidArgumentException($this->l10n->t('Invalid request.'));
        }
        $now = $this->timeFactory->getTime();
        $state = self::deriveState($room, $now);
        if ($state === self::STATE_DRAFT) {
            throw new \InvalidArgumentException($this->l10n->t('The quiz has not started yet.'));
        }
        if ($state !== self::STATE_OPEN) {
            throw new \InvalidArgumentException($this->l10n->t('The quiz is closed.'));
        }
        try {
            $this->playerMapper->findByRoomAndToken($room->getId(), $voterToken);
        } catch (DoesNotExistException) {
            throw new \InvalidArgumentException($this->l10n->t('Please choose a name first.'));
        }

        $rows = $this->rows($room, $voterToken);
        $open = self::openOf($rows);
        // Questions that still exist (defence against deleted questions, see nextAfter).
        $deck = [];
        foreach ($this->pollMapper->findByRoom($room->getId()) as $poll) {
            $deck[$poll->getId()] = $poll;
        }

        if ($open !== null) {
            if ($open->getPollId() !== $after) {
                return; // double tap or stale state
            }
            $poll = $deck[$after] ?? null;
            if ($poll !== null && $this->previewLocked($room, $poll, $open, $voterToken, $now)) {
                return;
            }
            if (!$this->progressMapper->closeIfOpen($open->getId(), $now)) {
                return; // another tab was faster
            }
        } else {
            // No open row: right at the start (after = 0) or self-healing
            // after a break-off between closing and starting.
            $lastId = self::lastOf($rows)?->getPollId() ?? 0;
            if ($after !== $lastId) {
                return;
            }
        }

        $succ = self::nextAfter($room, $after, array_keys($deck));
        // start() false = unique violation: the question is already running (parallel tab) — fine.
        if ($succ !== null && $this->progressMapper->start($room->getId(), $succ['pollId'], $voterToken, $succ['seq'], $now)) {
            $this->assertStillJoined($room, $voterToken);
        }
    }

    /**
     * After writing a vote or a row (/vote, /next — both without the
     * room lock): if the person was removed between the player check and the write
     * (removePlayer, or the whole room deleted), what was just written would
     * be orphaned. It would count in progress, tally and CSV, and
     * if the cookie joined again, the new identity would inherit question, clock and
     * answer of the old one. Then everything of this token is gone again, with the same
     * response as for a removal milliseconds earlier.
     *
     * Without gaps, because the player row is read with a lock: removePlayer
     * deletes it first and holds the lock until the commit, deleteRoom
     * deletes players before votes and progress. If this check still sees the
     * player, the later deletion takes what was written with it.
     *
     * @throws \InvalidArgumentException 'Please choose a name first.'
     * @throws RoomGoneException          the room was deleted in the meantime
     */
    public function assertStillJoined(Room $room, #[\SensitiveParameter] string $voterToken): void {
        if ($this->playerMapper->existsForUpdate($room->getId(), $voterToken)) {
            return;
        }
        try {
            $this->locked($room, function (Room $r) use ($voterToken): void {
                // Joined again in the meantime? Then selfJoin has already cleaned up,
                // and what is there now belongs to the new identity.
                if (!$this->playerMapper->existsForUpdate($r->getId(), $voterToken)) {
                    $this->forgetToken($r, $voterToken);
                }
            });
        } catch (RoomGoneException $e) {
            // Room deleted along with its players: only what was just written is left.
            $this->forgetToken($room, $voterToken);
            throw $e;
        }
        throw new \InvalidArgumentException($this->l10n->t('Please choose a name first.'));
    }

    /**
     * Preview lock: with a timer an UNANSWERED question may only be left after
     * the time has run out (same boundary as /vote: elapsed > limit), so that
     * skipping a question is never faster than answering it. Without a timer
     * there are no speed points to win — there it moves on immediately.
     *
     * What it does NOT stop: an ANSWERED question is left at once (a race
     * must not hold back whoever answers fast). A second cookie that answers
     * anything therefore reads the whole timed deck in 2n requests, one
     * /vote and one /next per question, before the clock of the main cookie
     * has started. And since leaving makes an answer final at once
     * (isFinal: no longer correctable), with feedback 'each' every such
     * answer's verdict shows immediately — k-1 throwaway cookies find the
     * right option of a k-option question. Only the number of cookies is
     * bounded (join limits, caps, "Lock joining"). Holding answered questions
     * until the limit would break the race; the README's security notes
     * recommend feedback 'end' and identifiable participants for graded use.
     */
    private function previewLocked(Room $room, Poll $poll, Progress $open, #[\SensitiveParameter] string $voterToken, int $now): bool {
        $limit = $poll->getTimeLimit();
        if (!$room->getTimed() || $limit <= 0 || $now - $open->getStartedAt() > $limit) {
            return false;
        }
        try {
            $this->voteMapper->findByPollAndToken($poll->getId(), $voterToken);
            return false; // answered -> move on immediately
        } catch (DoesNotExistException) {
            return true;
        }
    }

    /**
     * @return Progress[] a person's rows, ascending by seq
     */
    public function rows(Room $room, #[\SensitiveParameter] string $voterToken): array {
        return $this->progressMapper->findByRoomAndToken($room->getId(), $voterToken);
    }

    /** A person's currently open row (left_at = 0), null if there is none. */
    public function openRow(Room $room, #[\SensitiveParameter] string $voterToken): ?Progress {
        return self::openOf($this->rows($room, $voterToken));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @throws ConflictException */
    private function assertSelf(Room $room): void {
        if (!self::isSelf($room)) {
            throw new ConflictException($this->l10n->t('This room is not self-paced.'));
        }
    }

    /**
     * Player administration (lock joining, remove) exists in every quiz
     * room, self-paced or moderated.
     *
     * @throws ConflictException
     */
    private function assertQuiz(Room $room): void {
        if ($room->getMode() !== 'quiz') {
            throw new ConflictException($this->l10n->t('This room is not a quiz.'));
        }
    }

    /**
     * Deadline 0 = none; otherwise between one minute and 30 days from now.
     *
     * @throws \InvalidArgumentException
     */
    private function assertDeadline(int $closesAt, int $now): void {
        if ($closesAt !== 0 && ($closesAt < $now + self::MIN_LEAD || $closesAt > $now + self::MAX_LEAD)) {
            throw new \InvalidArgumentException($this->l10n->t('The deadline must be between one minute and 30 days from now.'));
        }
    }

    /**
     * Reload the room fresh after writing — still inside the transaction, so
     * exactly with the state this action wrote (openIfDraft
     * writes past the entity).
     */
    private function reload(Room $room): Room {
        return $this->roomMapper->findByCode($room->getCode());
    }
}
