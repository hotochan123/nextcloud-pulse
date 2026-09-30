<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\Player;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Progress;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;

/**
 * Votes: check incoming values against the question type, store them (poll:
 * changeable via upsert; quiz: immediately with speed points, one correction
 * within the window), grade free text afterwards and derive the leaderboard
 * from the votes. Plus joining a quiz (nickname on the anonymous token).
 *
 * Self-paced quiz (PaceService::isSelf): vote, join and leaderboard each
 * branch off into their own path in the first line. The dependencies this
 * needs (progressMapper, cacheFactory, limiter) are touched only by those
 * branches. Joining takes the room lock (paceService->locked) in both
 * modes; apart from that the moderated path does not use PaceService. The
 * player caps of joining come from Limits (instance-wide app config).
 *
 * Section references (§…) point to the design notes of the redesign, which are
 * not in the public repository (see "References in code comments" in the
 * README).
 */
class VoteService {
    /**
     * Correction window of a quiz answer in seconds (§8.0). Longer for keyboard
     * or screen-reader use: there the selection is a deliberate key press,
     * but the way back to the card takes longer.
     * Public because PaceService::isFinal uses the same values as its default.
     */
    public const FIX_WINDOW = 3;
    public const FIX_WINDOW_KEYBOARD = 6;

    /** Public self-paced leaderboard: raw rows stay this long in the local cache (see selfLeaderboard). */
    private const LEADERBOARD_BUCKET = 2;

    /** Longest nickname in characters (the join form's maxlength as well). */
    private const NICKNAME_MAX = 24;
    /** Word cloud: a single raw word is cut to this many characters before it is cleaned. */
    private const WORD_RAW_MAX = 200;
    /** Word cloud: more list entries than this (or 2 × maxWords) are not a vote. */
    private const WORD_ITEMS_MIN = 20;

    public function __construct(
        private PollMapper $pollMapper,
        private VoteMapper $voteMapper,
        private PlayerMapper $playerMapper,
        private QuizService $quizService,
        private DeckService $deckService,
        private ITimeFactory $timeFactory,
        private IL10N $l10n,
        // joining (room lock); everything else only in self-paced mode:
        private PaceService $paceService,
        private ProgressMapper $progressMapper,
        private ICacheFactory $cacheFactory,
        private ILimiter $limiter,
        // joining: the player caps
        private Limits $limits,
    ) {
    }

    /**
     * Record a vote. Upsert on (poll_id, voter_token): voting again changes
     * your own vote (last-write-wins per person) instead of adding a second one.
     *
     * $pollId is the question the phone answered. If it differs from the running
     * one, the moderator has moved on in the meantime — then do not silently
     * book the vote on the new question (a number, a word, a scale value often
     * fits there just as well). null = older phone without the field.
     *
     * Self-paced there is no cursor: what counts there is the person's open
     * row (recordSelfVote).
     *
     * @throws \InvalidArgumentException no active question / invalid value
     * @throws RoomGoneException         self-paced: room deleted in the meantime
     */
    public function recordVote(Room $room, #[\SensitiveParameter] string $voterToken, #[\SensitiveParameter] mixed $value, bool $keyboard = false, ?int $pollId = null): void {
        if (PaceService::isSelf($room)) {
            $this->recordSelfVote($room, $voterToken, $value, $keyboard, $pollId);
            return;
        }
        $activeId = $room->getActivePollId();
        if ($activeId === 0) {
            throw new \InvalidArgumentException($this->l10n->t('No question is running right now.'));
        }
        if ($pollId !== null && $pollId !== $activeId) {
            throw new \InvalidArgumentException($this->l10n->t('This question is closed.'));
        }
        try {
            $poll = $this->pollMapper->find($activeId);
        } catch (DoesNotExistException) {
            throw new \InvalidArgumentException($this->l10n->t('No question is running right now.'));
        }

        if ($room->getMode() === 'quiz') {
            $this->recordQuizVote($room, $poll, $voterToken, $value, $keyboard);
            return;
        }

        $status = $poll->getStatus();
        if ($status === 'locked') {
            throw new \InvalidArgumentException($this->l10n->t('The question is paused right now.'));
        }
        if ($status !== 'active') {
            throw new \InvalidArgumentException($this->l10n->t('This question is closed.'));
        }

        $payload = json_encode(['value' => $this->normalizeValue($poll, $value)]);

        try {
            $existing = $this->voteMapper->findByPollAndToken($poll->getId(), $voterToken);
            $existing->setPayload($payload);
            $existing->setCreatedAt($this->timeFactory->getTime());
            $this->voteMapper->update($existing);
        } catch (DoesNotExistException) {
            $vote = new Vote();
            $vote->setPollId($poll->getId());
            $vote->setVoterToken($voterToken);
            $vote->setPayload($payload);
            $vote->setCreatedAt($this->timeFactory->getTime());
            try {
                $this->voteMapper->insert($vote);
            } catch (Exception $e) {
                if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    throw $e;
                }
                // Two first votes of the same token at once (double tap, retry after
                // a network error): the other one has just inserted.
                // Then this one is a change — last-write-wins as above, no 500.
                $existing = $this->voteMapper->findByPollAndToken($poll->getId(), $voterToken);
                $existing->setPayload($payload);
                $existing->setCreatedAt($this->timeFactory->getTime());
                $this->voteMapper->update($existing);
            }
        }
    }

    // ── Quiz ────────────────────────────────────────────────────────────────

    /**
     * Quiz join: set (or change) the nickname for this token in this room.
     *
     * Both modes run under the room lock (PaceService::locked) and branch on
     * the freshly locked row: checking the name and registering it are
     * serialised, so two simultaneous "Anna"s no longer both get the name.
     * Inside the lock no unique violation is possible (the same token is
     * serialised as well; on PostgreSQL one would abort the transaction).
     *
     * @param ?string $remoteAddress the participant's address: self-paced,
     *        new players are counted per address and room
     *        (Limits::NEW_PLAYERS_PER_ADDRESS); null = not counted
     * @throws \InvalidArgumentException empty name, not a quiz room, name taken or frozen, joining closed, quiz full
     * @throws JoinLimitException        self-paced: too many new players from this address
     * @throws RoomGoneException         room deleted in the meantime
     */
    public function quizJoin(Room $room, #[\SensitiveParameter] string $voterToken, #[\SensitiveParameter] string $nickname, ?string $remoteAddress = null): Player {
        if ($room->getMode() !== 'quiz') {
            throw new \InvalidArgumentException($this->l10n->t('This room is not a quiz.'));
        }
        return $this->paceService->locked($room, fn (Room $r): Player => PaceService::isSelf($r)
            ? $this->selfJoin($r, $voterToken, $nickname, $remoteAddress)
            : $this->liveJoin($r, $voterToken, $nickname));
    }

    /**
     * Moderated (live) join, under the room lock (quizJoin), $room is the
     * freshly locked row.
     *
     * - Names are unique within the room, compared on TallyService::namesClash
     *   (case, invisible characters, lookalikes) — otherwise two identical
     *   looking rows show up in the leaderboard and nobody knows which one is
     *   theirs, or a name is impersonated on the podium.
     * - "Lock joining" (PaceService::setJoinsLocked) turns new tokens away;
     *   anyone already playing still gets back in.
     * - The name is fixed once the token has answered a question of this room
     *   or the quiz is over (a question at 'ended'): otherwise a name vetted
     *   in the lobby could be swapped for another one on the podium after the
     *   final standings. The spelling of one's own name (same nameKey) stays
     *   changeable, and re-sending it always works.
     * - A new token starts empty: a vote that a removed player's request wrote
     *   while PaceService::removePlayer ran does not come back under a new name.
     *   Only such left-overs are deleted — a delete that finds nothing would
     *   still count as a vote write and change the stamp of the running
     *   question (VoteMapper::changeStamp), so every join would send every
     *   phone and the projector into a full refetch.
     * - At most Limits::PLAYERS_PER_ROOM players (default
     *   PaceService::MAX_JOINED): new tokens beyond that get "This quiz is
     *   full." Voter tokens cost nothing to make up, so the cap is what bounds
     *   a script's players (and the leaderboards every view reads); anyone
     *   already playing still gets back in.
     *
     * No count per address: a conference behind one NAT address can have
     * many players. Against a script, the host locks joining once the room
     * is in, and removes what got through.
     *
     * @throws \InvalidArgumentException
     */
    private function liveJoin(Room $room, #[\SensitiveParameter] string $voterToken, #[\SensitiveParameter] string $nickname): Player {
        $nickname = $this->sanitizeNickname($nickname);
        $players = $this->playerMapper->findByRoom($room->getId());
        $mine = self::ownPlayer($players, $voterToken);
        if ($mine === null && $room->getJoinsLocked()) {
            throw new \InvalidArgumentException($this->l10n->t('Joining is closed for this quiz.'));
        }
        if ($mine === null && count($players) >= $this->limits->get(Limits::PLAYERS_PER_ROOM)) {
            throw new \InvalidArgumentException($this->l10n->t('This quiz is full.'));
        }
        $renames = $mine !== null && TallyService::nameKey($mine->getNickname()) !== TallyService::nameKey($nickname);
        $pollIds = [];
        $ended = false;
        if ($mine === null || $renames) {
            foreach ($this->pollMapper->findByRoom($room->getId()) as $poll) {
                $pollIds[] = $poll->getId();
                $ended = $ended || $poll->getStatus() === 'ended';
            }
        }
        if ($renames && ($ended || $this->voteMapper->findByPolls($pollIds, $voterToken) !== [])) {
            throw new \InvalidArgumentException($this->l10n->t('You can\'t change your name after starting.'));
        }
        $this->assertNameFree($players, $voterToken, $nickname, $mine);
        if ($mine === null && $this->voteMapper->findByPolls($pollIds, $voterToken) !== []) {
            $this->voteMapper->deleteByPollsAndToken($pollIds, $voterToken);
        }
        return $this->playerMapper->register($room->getId(), $voterToken, $nickname, $this->timeFactory->getTime());
    }

    /**
     * Self-paced join, under the room lock (quizJoin), $room is the freshly
     * locked row: the cap cannot be overrun either.
     *
     * Allowed in draft and in the open window; after closing, the next group
     * can no longer get at the final standings and solutions. "Lock joining"
     * and the caps only affect new tokens — anyone already playing still gets
     * back in (makeRoomForNewPlayer). After starting, the name is fixed:
     * otherwise a token could slip into a freed-up or someone else's name
     * after answering, and the teacher grades by name. The case of one's own
     * name stays changeable (same nameKey). A new player counts against its
     * address only once everything else has passed (countNewPlayer), so
     * retries and taken names cost nothing.
     *
     * @throws \InvalidArgumentException
     * @throws JoinLimitException
     */
    private function selfJoin(Room $room, #[\SensitiveParameter] string $voterToken, #[\SensitiveParameter] string $nickname, ?string $remoteAddress): Player {
        $now = $this->timeFactory->getTime();
        $state = PaceService::deriveState($room, $now);
        if ($state === PaceService::STATE_CLOSED || $state === PaceService::STATE_RELEASED) {
            throw new \InvalidArgumentException($this->l10n->t('The quiz is closed.'));
        }
        $nickname = $this->sanitizeNickname($nickname);
        $players = $this->playerMapper->findByRoom($room->getId());
        $mine = self::ownPlayer($players, $voterToken);
        if ($mine === null && $room->getJoinsLocked()) {
            throw new \InvalidArgumentException($this->l10n->t('Joining is closed for this quiz.'));
        }
        if ($mine === null) {
            $players = $this->makeRoomForNewPlayer($room, $players, $state, $now);
        }
        if ($mine !== null && TallyService::nameKey($mine->getNickname()) !== TallyService::nameKey($nickname)
            && $this->progressMapper->findByRoomAndToken($room->getId(), $voterToken) !== []) {
            throw new \InvalidArgumentException($this->l10n->t('You can\'t change your name after starting.'));
        }
        $this->assertNameFree($players, $voterToken, $nickname, $mine);
        if ($mine === null) {
            $this->countNewPlayer($room, $remoteAddress);
            if ($room->getOpenedAt() > 0) {
                // A new identity starts empty, even if something from this cookie's
                // removed player is still lying around (a request that aborted between
                // writing and PaceService::assertStillJoined): question 1, a new
                // clock, no old answer.
                $this->paceService->forgetToken($room, $voterToken);
            }
        }
        return $this->playerMapper->register($room->getId(), $voterToken, $nickname, $now);
    }

    /**
     * Room for one more self-paced player? PaceService::MAX_PLAYERS counts
     * only players who have started (a progress row): joins that never start
     * can no longer fill the quiz for the class. So that those rows stay
     * bounded all the same, at most Limits::PLAYERS_PER_ROOM players (the
     * ceiling, default PaceService::MAX_JOINED) may be joined at once. At that
     * ceiling, in the open window, players who joined more than STALE_JOIN
     * seconds ago and never started make room: they have no answer and no
     * progress, only the name goes (their phone shows the name form again).
     * In the draft nobody can have started, so there the ceiling alone
     * applies — the host can lock joining.
     *
     * Below both MAX_PLAYERS and the ceiling (the usual case) nothing more is read.
     *
     * @param Player[] $players all players of the room
     * @return Player[] the players that remain
     * @throws \InvalidArgumentException 'This quiz is full.'
     */
    private function makeRoomForNewPlayer(Room $room, array $players, string $state, int $now): array {
        $ceiling = $this->limits->get(Limits::PLAYERS_PER_ROOM);
        if (count($players) < min(PaceService::MAX_PLAYERS, $ceiling)) {
            return $players;
        }
        $started = [];
        foreach ($this->progressMapper->findByRoom($room->getId()) as $row) {
            $started[$row->getVoterToken()] = true;
        }
        $running = 0;
        foreach ($players as $p) {
            if (isset($started[$p->getVoterToken()])) {
                $running++;
            }
        }
        if ($running < PaceService::MAX_PLAYERS && count($players) >= $ceiling
            && $state === PaceService::STATE_OPEN) {
            $keep = [];
            foreach ($players as $p) {
                if (!isset($started[$p->getVoterToken()]) && $p->getCreatedAt() <= $now - PaceService::STALE_JOIN) {
                    $this->playerMapper->delete($p);
                } else {
                    $keep[] = $p;
                }
            }
            $players = $keep;
        }
        if ($running >= PaceService::MAX_PLAYERS || count($players) >= $ceiling) {
            throw new \InvalidArgumentException($this->l10n->t('This quiz is full.'));
        }
        return $players;
    }

    /**
     * Count a new self-paced player against its address and room: at most
     * Limits::NEW_PLAYERS_PER_ADDRESS (default PaceService::NEW_PLAYER_LIMIT)
     * per JOIN_PERIOD. Only successful new
     * players count (this is the last check before registering) — a class
     * behind one NAT address is not locked out by someone else's failed or
     * repeated requests, only by that many new names, which the host sees and
     * can remove. The limiter keys on the address's subnet (/32 IPv4, /56 IPv6).
     *
     * @throws JoinLimitException
     */
    private function countNewPlayer(Room $room, ?string $remoteAddress): void {
        if ($remoteAddress === null || $remoteAddress === '') {
            return;
        }
        try {
            $this->limiter->registerAnonRequest(
                'pulse-player-' . $room->getId(),
                $this->limits->get(Limits::NEW_PLAYERS_PER_ADDRESS),
                PaceService::JOIN_PERIOD,
                $remoteAddress,
            );
        } catch (IRateLimitExceededException) {
            throw new JoinLimitException($this->l10n->t('Too many attempts. Please wait a moment.'));
        }
    }

    /**
     * Is $nickname free for this token? It must not clash with another
     * player's name (TallyService::namesClash: case, invisible characters,
     * lookalikes). Re-sending one's own name exactly never clashes.
     *
     * Any other spelling is checked — also one with the same nameKey, which
     * the rename freeze lets through: nameKey folds far less than namesClash
     * ("paui" and "PauI" share a key, but only "PauI" passes for "Paul"), so a
     * player could otherwise join as "paui" and turn into "PauI" after the
     * final standings. Names that the stored one already clashes with are
     * left out: an older rule let those lookalikes in, and both keep their
     * spelling.
     *
     * @param Player[] $players
     * @throws \InvalidArgumentException 'This name is already taken. …'
     */
    private function assertNameFree(array $players, #[\SensitiveParameter] string $voterToken, #[\SensitiveParameter] string $nickname, ?Player $mine): void {
        $stored = $mine?->getNickname();
        if ($stored === $nickname) {
            return;
        }
        $others = [];
        foreach ($players as $p) {
            if ($p->getVoterToken() !== $voterToken
                && ($stored === null || !TallyService::namesClash($stored, $p->getNickname()))) {
                $others[] = $p->getNickname();
            }
        }
        if (TallyService::clashIn($nickname, $others) !== null) {
            throw new \InvalidArgumentException($this->l10n->t('This name is already taken. Please choose another one.'));
        }
    }

    /** @param Player[] $players */
    private static function ownPlayer(array $players, #[\SensitiveParameter] string $voterToken): ?Player {
        foreach ($players as $p) {
            if ($p->getVoterToken() === $voterToken) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Record a quiz answer: one tap submits immediately, time limit enforced,
     * points computed immediately and stored in the payload (the leaderboard
     * is derived from it — so reset/delete stays consistent).
     *
     * Exactly ONE correction is allowed, and only within the window from
     * §8.0. The correction resets the timestamp — so the time gained is lost,
     * and quick blind tapping brings no advantage. The window is checked
     * here, not in the browser: the endpoint is public.
     *
     * @throws \InvalidArgumentException
     */
    private function recordQuizVote(Room $room, Poll $poll, #[\SensitiveParameter] string $voterToken, #[\SensitiveParameter] mixed $value, bool $keyboard = false): void {
        try {
            $this->playerMapper->findByRoomAndToken($room->getId(), $voterToken);
        } catch (DoesNotExistException) {
            throw new \InvalidArgumentException($this->l10n->t('Please choose a name first.'));
        }
        if ($poll->getStatus() !== 'active') {
            throw new \InvalidArgumentException($this->l10n->t('This question is not accepting answers right now.'));
        }

        $now = $this->timeFactory->getTime();
        $elapsed = max(0, $now - $poll->getStartedAt());
        if ($poll->getTimeLimit() > 0 && $elapsed > $poll->getTimeLimit()) {
            throw new \InvalidArgumentException($this->l10n->t('Time is up.'));
        }

        // normalizeValue checks the value against the question type; quizPayload grades it.
        $normalized = $this->normalizeValue($poll, $value);
        $payload = json_encode($this->quizPayload($poll, $normalized, $elapsed));

        $vote = new Vote();
        $vote->setPollId($poll->getId());
        $vote->setVoterToken($voterToken);
        $vote->setPayload($payload);
        $vote->setCreatedAt($now);
        try {
            $this->voteMapper->insert($vote);
        } catch (Exception $e) {
            if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
            // There is already an answer: at most one correction within the window.
            $this->correctQuizVote($poll, $voterToken, $payload, $now, $keyboard);
        }
    }

    /**
     * The one allowed correction (§8.0). It is tied to three conditions:
     * the previous answer is not a correction, it lies within the window,
     * and the question still accepts answers. Otherwise it stays at "already
     * answered".
     *
     * @throws \InvalidArgumentException
     */
    private function correctQuizVote(Poll $poll, #[\SensitiveParameter] string $voterToken, #[\SensitiveParameter] string $payload, int $now, bool $keyboard): void {
        try {
            $existing = $this->voteMapper->findByPollAndToken($poll->getId(), $voterToken);
        } catch (DoesNotExistException) {
            // Deleted between insert and lookup — then that's that.
            throw new \InvalidArgumentException($this->l10n->t('You have already answered this question.'));
        }
        $old = json_decode($existing->getPayload(), true) ?: [];
        $window = $keyboard ? self::FIX_WINDOW_KEYBOARD : self::FIX_WINDOW;
        if (!empty($old['fixed']) || $now - $existing->getCreatedAt() > $window) {
            throw new \InvalidArgumentException($this->l10n->t('You have already answered this question.'));
        }
        $data = json_decode($payload, true) ?: [];
        $data['fixed'] = true;
        $existing->setPayload(json_encode($data));
        $existing->setCreatedAt($now);
        $this->voteMapper->update($existing);
    }

    /**
     * Self-paced quiz answer. Instead of the cursor, the person's open row
     * counts (PaceService::next); instead of poll.startedAt, their personal clock.
     * Without a timer (room.timed = false) there is no limit and flat points.
     *
     * The payload additionally carries what the read views need without room
     * context: `limit` (speed points when grading later, empty time column
     * without a timer), `fw` (correction window of the FIRST answer —
     * PaceService::isFinal uses it) and for free text `pending` (neither accepted
     * nor rejected: 0 points until the moderator grades it). Key order:
     * value, points, correct, elapsed, [norm], [pending], limit, fw, [fixed].
     *
     * Unlike the moderated mode, the correction is decided by the window of the
     * first answer, not by the flag of the correcting request: otherwise it would
     * be "tap first, look at the verdict after 3 s, then correct with
     * keyboard:true" — self-paced, the verdict is not hidden until the
     * reveal.
     *
     * After saving: assertStillJoined (removed in the middle of the request)
     * and for free text settleText (computed in the middle of a grading).
     *
     * @throws \InvalidArgumentException
     * @throws RoomGoneException
     */
    private function recordSelfVote(Room $room, #[\SensitiveParameter] string $voterToken, #[\SensitiveParameter] mixed $value, bool $keyboard, ?int $pollId): void {
        $now = $this->timeFactory->getTime();
        $state = PaceService::deriveState($room, $now);
        if ($state === PaceService::STATE_DRAFT) {
            throw new \InvalidArgumentException($this->l10n->t('The quiz has not started yet.'));
        }
        if ($state !== PaceService::STATE_OPEN) {
            throw new \InvalidArgumentException($this->l10n->t('The quiz is closed.'));
        }
        try {
            $this->playerMapper->findByRoomAndToken($room->getId(), $voterToken);
        } catch (DoesNotExistException) {
            throw new \InvalidArgumentException($this->l10n->t('Please choose a name first.'));
        }
        if ($pollId === null) {
            // Old bundle without pollId: without a cursor we would not know which question is meant.
            throw new \InvalidArgumentException($this->l10n->t('Please reload the page.'));
        }
        $open = $this->paceService->openRow($room, $voterToken);
        if ($open === null || $open->getPollId() !== $pollId) {
            throw new \InvalidArgumentException($this->l10n->t('This question is closed.'));
        }
        try {
            $poll = $this->pollMapper->find($pollId);
        } catch (DoesNotExistException) {
            // Defensive: the open row's question no longer exists.
            throw new \InvalidArgumentException($this->l10n->t('This question is closed.'));
        }

        // The 5–300 s clamp still applies in the deck; without a timer, no limit.
        $limit = $room->getTimed() ? $poll->getTimeLimit() : 0;
        $elapsed = max(0, $now - $open->getStartedAt());
        if ($limit > 0 && $elapsed > $limit) {
            throw new \InvalidArgumentException($this->l10n->t('Time is up.'));
        }

        $normalized = $this->normalizeValue($poll, $value);
        $data = $this->selfPayload($poll, $normalized, $elapsed, $limit, $keyboard ? self::FIX_WINDOW_KEYBOARD : self::FIX_WINDOW);

        $vote = new Vote();
        $vote->setPollId($poll->getId());
        $vote->setVoterToken($voterToken);
        $vote->setPayload(json_encode($data));
        $vote->setCreatedAt($now);
        try {
            $this->voteMapper->insert($vote);
        } catch (Exception $e) {
            if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
            // There is already an answer: at most one correction. Free text under
            // the room lock like grading (gradeTextAnswer) and with a freshly
            // read answer key — otherwise a simultaneous grading would write its
            // old state over the correction or vice versa.
            if ($poll->getType() === 'text') {
                $this->paceService->locked($room, fn () => $this->correctSelfVote(
                    $this->pollMapper->find($pollId), $voterToken, $normalized, $elapsed, $limit, $now,
                ));
            } else {
                $this->correctSelfVote($poll, $voterToken, $normalized, $elapsed, $limit, $now);
            }
            return;
        }
        // Removed in the middle of the request? Then the vote is gone again (400).
        $this->paceService->assertStillJoined($room, $voterToken);
        if ($poll->getType() === 'text') {
            $this->settleText($room, $pollId, $voterToken, $data);
        }
    }

    /**
     * Payload of a self-paced vote, keys in a fixed order
     * (see recordSelfVote).
     *
     * @param int $fw correction window of the first answer
     */
    private function selfPayload(Poll $poll, #[\SensitiveParameter] mixed $normalized, int $elapsed, int $limit, int $fw): array {
        $data = $this->quizPayload($poll, $normalized, $elapsed, $limit);
        if ($poll->getType() === 'text') {
            $data['pending'] = !$data['correct'] && !$this->textRejected($poll, (string)$data['norm']);
        }
        $data['limit'] = $limit;
        $data['fw'] = $fw;
        return $data;
    }

    /**
     * The one self-paced correction, as long as the previous answer is not
     * final. It is correctable (window open, row open — checked by
     * recordSelfVote).
     *
     * @throws \InvalidArgumentException
     */
    private function correctSelfVote(Poll $poll, #[\SensitiveParameter] string $voterToken, #[\SensitiveParameter] mixed $normalized, int $elapsed, int $limit, int $now): void {
        try {
            $existing = $this->voteMapper->findByPollAndToken($poll->getId(), $voterToken);
        } catch (DoesNotExistException) {
            throw new \InvalidArgumentException($this->l10n->t('You have already answered this question.'));
        }
        $old = json_decode($existing->getPayload(), true);
        $old = is_array($old) ? $old : [];
        if (PaceService::isFinal($old, $existing->getCreatedAt(), $now, true)) {
            throw new \InvalidArgumentException($this->l10n->t('You have already answered this question.'));
        }
        // Window of the FIRST answer — the keyboard flag of the correction does not count.
        $data = $this->selfPayload($poll, $normalized, $elapsed, $limit, (int)($old['fw'] ?? self::FIX_WINDOW));
        $data['fixed'] = true;
        $existing->setPayload(json_encode($data));
        $existing->setCreatedAt($now);
        $this->voteMapper->update($existing);
    }

    /**
     * Self-paced free text: if the moderator grades the same answer while it
     * is in flight, it may be computed with the old answer key and only saved
     * after the grading's pass — it would then stay at "Being checked" with
     * 0 points although its normalised form has already been graded. So after
     * saving, read the key once more, with a lock: a running grading (one
     * transaction under the room lock) holds the question row until commit,
     * after which its key is visible; if it had not reached the row yet, its
     * pass finds this vote itself. If the verdict differs, recompute it freshly
     * under the room lock.
     *
     * @param array $data the payload just saved
     */
    private function settleText(Room $room, int $pollId, #[\SensitiveParameter] string $voterToken, #[\SensitiveParameter] array $data): void {
        try {
            $poll = $this->pollMapper->findForUpdate($pollId);
        } catch (DoesNotExistException) {
            return; // room just deleted — the vote goes with it
        }
        if ($this->textVerdict($poll, (string)($data['norm'] ?? '')) === [$data['correct'], !empty($data['pending'])]) {
            return;
        }
        $this->paceService->locked($room, function () use ($pollId, $voterToken): void {
            try {
                $vote = $this->voteMapper->findByPollAndToken($pollId, $voterToken);
            } catch (DoesNotExistException) {
                return; // removed in the meantime
            }
            $d = json_decode($vote->getPayload(), true);
            if (!is_array($d)) {
                return;
            }
            $poll = $this->pollMapper->find($pollId);
            [$correct, $pending] = $this->textVerdict($poll, (string)($d['norm'] ?? ''));
            if ($correct === !empty($d['correct']) && $pending === !empty($d['pending'])) {
                return; // a grading was faster and has already updated it
            }
            $d['correct'] = $correct;
            $d['points'] = $correct ? $this->quizService->points((int)($d['elapsed'] ?? 0), (int)($d['limit'] ?? $poll->getTimeLimit())) : 0;
            $d['pending'] = $pending;
            $vote->setPayload(json_encode($d));
            $this->voteMapper->update($vote);
        });
    }

    /**
     * Vote payload of a quiz answer: checks correctness per type, awards
     * speed points. Free text whose answer is not (yet) in the accepted list
     * starts as correct=false and is updated when grading.
     *
     * @param ?int $limit time limit for the speed points; null = the question's
     *        (moderated). Self-paced 0 without a timer -> flat points.
     * @return array{value:mixed, points:int, correct:bool, elapsed:int, norm?:string}
     */
    public function quizPayload(Poll $poll, #[\SensitiveParameter] mixed $normalized, int $elapsed, ?int $limit = null): array {
        $limit ??= $poll->getTimeLimit();
        $type = $poll->getType();
        $correct = false;
        $extra = [];
        if ($type === 'choice' || $type === 'truefalse') {
            $correct = is_string($normalized) && $normalized !== '' && $normalized === $poll->getCorrectOption();
        } elseif ($type === 'multi') {
            $want = $poll->getAnswerKeyArray()['correct'] ?? [];
            sort($want);
            $got = is_array($normalized) ? $normalized : [];
            sort($got);
            $correct = $got === $want; // all-or-nothing
        } elseif ($type === 'rank') {
            // All-or-nothing as with multiple choice: the order must match
            // exactly. Partial credit (e.g. Kendall tau) would water down "correct"
            // in the leaderboard — deliberately not.
            $want = $poll->getAnswerKeyArray()['order'] ?? [];
            $correct = is_array($normalized) && $normalized === $want && $want !== [];
        } elseif ($type === 'match') {
            // Like multiple choice and ranking: all-or-nothing. Every
            // assignment has to be right, otherwise there is no point.
            $want = $poll->getAnswerKeyArray()['map'] ?? [];
            $got = is_array($normalized) ? $normalized : [];
            ksort($want);
            ksort($got);
            $correct = $want !== [] && $got === $want;
        } elseif ($type === 'number') {
            $key = $poll->getAnswerKeyArray();
            $target = (float)($key['target'] ?? 0);
            $tol = (float)($key['tolerance'] ?? 0);
            $correct = is_numeric($normalized) && abs((float)$normalized - $target) <= $tol;
        } elseif ($type === 'text') {
            $norm = TallyService::normalizeText((string)$normalized);
            $correct = $this->textAccepted($poll, $norm);
            $extra['norm'] = $norm;
        }
        $points = $correct ? $this->quizService->points($elapsed, $limit) : 0;
        return array_merge([
            'value' => $normalized,
            'points' => $points,
            'correct' => $correct,
            'elapsed' => $elapsed,
        ], $extra);
    }

    /** Free text: is the normalised form in the (growing) accepted list? */
    private function textAccepted(Poll $poll, string $norm): bool {
        foreach (($poll->getAnswerKeyArray()['accepted'] ?? []) as $a) {
            if (TallyService::normalizeText((string)$a) === $norm) {
                return true;
            }
        }
        return false;
    }

    /**
     * Counterpart: already graded as wrong? Self-paced, anything that is in
     * neither of the two lists is "Being checked" (pending).
     */
    private function textRejected(Poll $poll, string $norm): bool {
        foreach (($poll->getAnswerKeyArray()['rejected'] ?? []) as $r) {
            if (TallyService::normalizeText((string)$r) === $norm) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verdict on a free-text normalised form according to the current key, as
     * selfPayload stores it.
     *
     * @return array{0: bool, 1: bool} [correct, being checked]
     */
    private function textVerdict(Poll $poll, string $norm): array {
        $correct = $this->textAccepted($poll, $norm);
        return [$correct, !$correct && !$this->textRejected($poll, $norm)];
    }

    /**
     * Grade free text: mark a (non-obvious) answer as correct/wrong.
     * "Correct" adds it to the accepted list; ALL votes with the same
     * normalised form are retroactively counted as correct (speed points
     * from their stored elapsed). "Wrong" -> rejected list, votes to 0.
     * Idempotent and can be toggled at will; keeps the leaderboard (payload-based)
     * consistent because the points are updated directly in the votes.
     *
     * Self-paced, grading happens while people are answering: there it runs as
     * one transaction under the room lock. Corrections do the same (otherwise
     * grading and correction would overwrite each other), and an answer just
     * saved re-reads the key afterwards with a lock (settleText).
     *
     * @throws \InvalidArgumentException question does not belong to the room / not free text
     * @throws RoomGoneException         self-paced: room deleted in the meantime
     */
    public function gradeTextAnswer(Room $room, int $pollId, #[\SensitiveParameter] string $answer, bool $correct): void {
        if (PaceService::isSelf($room)) {
            $this->paceService->locked($room, fn (Room $r) => $this->gradeText($r, $pollId, $answer, $correct));
            return;
        }
        $this->gradeText($room, $pollId, $answer, $correct);
    }

    /** Body of gradeTextAnswer (self-paced under the room lock). */
    private function gradeText(Room $room, int $pollId, #[\SensitiveParameter] string $answer, bool $correct): void {
        $poll = $this->deckService->requirePollInRoom($room, $pollId);
        if ($poll->getType() !== 'text') {
            throw new \InvalidArgumentException($this->l10n->t('Grading only exists for free-text questions.'));
        }
        $norm = TallyService::normalizeText($answer);
        if ($norm === '') {
            throw new \InvalidArgumentException($this->l10n->t('Empty answer.'));
        }
        $key = $poll->getAnswerKeyArray();
        // Remove the same normalised form from both lists, then sort it in again.
        $strip = static fn (array $list): array => array_values(array_filter(
            $list,
            static fn ($a): bool => TallyService::normalizeText((string)$a) !== $norm,
        ));
        $accepted = $strip(array_values($key['accepted'] ?? []));
        $rejected = $strip(array_values($key['rejected'] ?? []));
        $sample = mb_substr(trim($answer), 0, DeckService::TEXT_MAX);
        if ($correct) {
            $accepted[] = $sample;
        } else {
            $rejected[] = $sample;
        }
        $poll->setAnswerKey(json_encode(['accepted' => $accepted, 'rejected' => $rejected]));
        $this->pollMapper->update($poll);

        // Re-grade the affected votes (same normalised form). Self-paced with
        // the limit that applied when answering (0 without a timer -> flat points);
        // moderated votes have none and take the question's as before.
        $limit = $poll->getTimeLimit();
        foreach ($this->voteMapper->findByPoll($pollId) as $vote) {
            $d = json_decode($vote->getPayload(), true);
            if (!is_array($d)) {
                continue;
            }
            $voteNorm = $d['norm'] ?? TallyService::normalizeText((string)($d['value'] ?? ''));
            if ($voteNorm !== $norm) {
                continue;
            }
            $d['correct'] = $correct;
            $d['points'] = $correct ? $this->quizService->points((int)($d['elapsed'] ?? 0), (int)($d['limit'] ?? $limit)) : 0;
            // Graded = no longer "Being checked". Moderated payloads never
            // have the key and so stay byte-identical.
            unset($d['pending']);
            $vote->setPayload(json_encode($d));
            $this->voteMapper->update($vote);
        }
    }

    /** Nickname of this token in the room, or null (not joined yet). */
    public function playerNickname(Room $room, #[\SensitiveParameter] ?string $voterToken): ?string {
        if ($voterToken === null || $voterToken === '') {
            return null;
        }
        try {
            return $this->playerMapper->findByRoomAndToken($room->getId(), $voterToken)->getNickname();
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Leaderboard for a viewer: rows without other people's tokens, one's own
     * row marked with `me: true` ($meToken = null in the moderator view).
     * $skipPollId leaves one question out of the scoring — the running, still
     * hidden one, when the leaderboard goes out publicly.
     *
     * Self-paced there is no running question for everyone: there
     * selfLeaderboard applies (final votes only), $skipPollIds plays no role.
     *
     * $withPlayerIds (moderator views only): every row additionally carries
     * `playerId`, the ID PaceService::removePlayer takes — so the host can
     * remove a player from the live leaderboard. Never for public views.
     * Self-paced the progress view carries the IDs, so it is ignored there.
     *
     * @return list<array{rank:int, nickname:string, score:int, correct:int, me:bool, playerId?:int}>
     */
    public function leaderboardFor(Room $room, #[\SensitiveParameter] ?string $meToken, array $skipPollIds = [], bool $withPlayerIds = false): array {
        if (PaceService::isSelf($room)) {
            return $this->selfLeaderboard($room, $meToken);
        }
        [$points, $correct, $time] = $this->quizPointsByToken($room, $skipPollIds);
        $players = $this->playerMapper->findByRoom($room->getId());
        $rows = $this->quizService->leaderboard($players, $points, $correct, $time);
        $out = self::forViewer($rows, $meToken);
        if ($withPlayerIds) {
            $ids = [];
            foreach ($players as $p) {
                $ids[$p->getVoterToken()] = (int)$p->getId();
            }
            foreach ($rows as $i => $r) {
                $out[$i]['playerId'] = $ids[$r['token']] ?? 0;
            }
        }
        return $out;
    }

    /**
     * Self-paced leaderboard: ONE query for the whole frozen deck, final
     * votes only (PaceService::isFinal with correctable per row) — so an
     * answer within the correction window does not give itself away through
     * the score, and after closing the final standings are settled at once.
     * Without a timer, a tie is not decided by the sum of the times (it
     * includes the idle time between /next and the answer, hours for
     * homework); instead the rank stays shared.
     *
     * Public callers (projector, phone/summary after release) set $cached:
     * after release every phone fetches the final standings on every poll,
     * i.e. a JSON decode of all votes of the deck. The raw rows are therefore
     * kept in the local cache per room and 2-s bucket. Window changes are
     * part of the key and take effect immediately (including the expiring
     * deadline, via the effective close), votes appear at most 2 s later.
     * Without APCu (NullCache) it is simply computed every time. Moderator and
     * CSV always compute freshly.
     *
     * @param int $limit 0 = all, otherwise only the first $limit rows (projector: 8)
     * @return list<array{rank:int, nickname:string, score:int, correct:int, me:bool}>
     */
    public function selfLeaderboard(Room $room, #[\SensitiveParameter] ?string $meToken, int $limit = 0, bool $cached = false): array {
        $now = $this->timeFactory->getTime();
        if ($cached) {
            $cache = $this->cacheFactory->createLocal('pulse');
            $key = 'lb:' . $room->getId() . ':' . $room->getOpenedAt()
                . ':' . PaceService::effectiveClosedAt($room, $now) . ':' . $room->getClosesAt()
                . ':' . $room->getReleasedAt() . ':' . intdiv($now, self::LEADERBOARD_BUCKET);
            $rows = $cache->get($key);
            if (!is_array($rows)) {
                $rows = $this->selfLeaderboardRows($room, $now);
                $cache->set($key, $rows, self::LEADERBOARD_BUCKET + 1);
            }
        } else {
            $rows = $this->selfLeaderboardRows($room, $now);
        }
        $rows = self::forViewer($rows, $meToken);
        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    /**
     * Raw rows of the self-paced leaderboard (with token, server-internal
     * only and in the local cache). Public only for the CSV export
     * (PaceStateService), which appends further columns per token — the token
     * itself never leaves the server.
     *
     * @return list<array{token:string, rank:int, nickname:string, score:int, correct:int, time:int}>
     */
    public function selfLeaderboardRows(Room $room, int $now): array {
        // Row per (question, token) — whether the answer is still correctable depends on it.
        /** @var array<string, Progress> $progress */
        $progress = [];
        foreach ($this->progressMapper->findByRoom($room->getId()) as $row) {
            $progress[$row->getPollId() . ':' . $row->getVoterToken()] = $row;
        }
        $points = [];
        $correct = [];
        $time = [];
        foreach ($this->voteMapper->findByPolls(PaceService::order($room)) as $vote) {
            $d = json_decode($vote->getPayload(), true);
            if (!is_array($d)) {
                continue;
            }
            $t = $vote->getVoterToken();
            $row = $progress[$vote->getPollId() . ':' . $t] ?? null;
            if (!PaceService::isFinal($d, $vote->getCreatedAt(), $now, PaceService::correctable($room, $now, $row))) {
                continue;
            }
            $points[$t] = ($points[$t] ?? 0) + (int)($d['points'] ?? 0);
            $time[$t] = ($time[$t] ?? 0) + max(0, (int)($d['elapsed'] ?? 0));
            if (!empty($d['correct'])) {
                $correct[$t] = ($correct[$t] ?? 0) + 1;
            }
        }
        $players = $this->playerMapper->findByRoom($room->getId());
        return $this->quizService->leaderboard($players, $points, $correct, $room->getTimed() ? $time : []);
    }

    /**
     * Leaderboard rows for a viewer: other people's tokens removed, one's own
     * row with `me: true` ($meToken = null in the moderator view).
     *
     * @param list<array{token:string, rank:int, nickname:string, score:int, correct:int, time:int}> $rows
     * @return list<array{rank:int, nickname:string, score:int, correct:int, me:bool}>
     */
    private static function forViewer(#[\SensitiveParameter] array $rows, #[\SensitiveParameter] ?string $meToken): array {
        return array_map(static function (array $r) use ($meToken): array {
            return [
                'rank' => $r['rank'],
                'nickname' => $r['nickname'],
                'score' => $r['score'],
                'correct' => $r['correct'],
                'me' => $meToken !== null && $r['token'] === $meToken,
            ];
        }, $rows);
    }

    /**
     * Point total + number of correct answers + summed answer time (elapsed)
     * per token across all questions of the room. The time only serves as a
     * tie-break in the leaderboard (QuizService::leaderboard) and never leaves the server.
     *
     * @return array{0: array<string,int>, 1: array<string,int>, 2: array<string,int>}
     */
    private function quizPointsByToken(Room $room, array $skipPollIds = []): array {
        $points = [];
        $correct = [];
        $time = [];
        foreach ($this->pollMapper->findByRoom($room->getId()) as $poll) {
            if (in_array($poll->getId(), $skipPollIds, true)) {
                continue;
            }
            foreach ($this->voteMapper->findByPoll($poll->getId()) as $vote) {
                $d = json_decode($vote->getPayload(), true);
                if (!is_array($d)) {
                    continue;
                }
                $t = $vote->getVoterToken();
                $points[$t] = ($points[$t] ?? 0) + (int)($d['points'] ?? 0);
                $time[$t] = ($time[$t] ?? 0) + max(0, (int)($d['elapsed'] ?? 0));
                if (!empty($d['correct'])) {
                    $correct[$t] = ($correct[$t] ?? 0) + 1;
                }
            }
        }
        return [$points, $correct, $time];
    }

    /** Number of players in the room (for the lobby fingerprint). */
    public function playerCount(Room $room): int {
        return $this->playerMapper->countByRoom($room->getId());
    }

    private function sanitizeNickname(#[\SensitiveParameter] string $nickname): string {
        // Raw input cut to four times the length first (clip): the regexes
        // below must not run over a body of megabytes.
        // Control characters out, invisible ones gone (cleanText), THEN whitespace
        // (including NBSP & co.) collapsed to one space — the other way round,
        // "Anna ␣ZWSP␣ Bob" would leave a double space that HTML shows as one.
        // Then cut to 24 characters and clean once more: if the cut ends on a
        // space, "Anna " and "Anna" would otherwise be two names.
        $nickname = self::clip($nickname, 4 * self::NICKNAME_MAX);
        $nickname = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $nickname) ?? '';
        $nickname = preg_replace('/[\s\p{Z}]+/u', ' ', TallyService::cleanText($nickname)) ?? '';
        $nickname = TallyService::cleanText(mb_substr($nickname, 0, self::NICKNAME_MAX));
        // A name made only of variation selectors or the like is invisible too.
        if ($nickname === '' || TallyService::nameKey($nickname) === '') {
            throw new \InvalidArgumentException($this->l10n->t('Please enter a name.'));
        }
        return $nickname;
    }

    /**
     * Checks and normalises the incoming vote value against the question type.
     *
     * @return string|list<string> optionId (choice) or words (words)
     * @throws \InvalidArgumentException
     */
    public function normalizeValue(Poll $poll, #[\SensitiveParameter] mixed $value): mixed {
        $type = $poll->getType();

        // choice + true/false: one valid option ID.
        if ($type === 'choice' || $type === 'truefalse') {
            if (!is_string($value)) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid selection.'));
            }
            $validIds = array_column($poll->getOptionsArray(), 'id');
            if (!in_array($value, $validIds, true)) {
                throw new \InvalidArgumentException($this->l10n->t('No such option.'));
            }
            return $value;
        }

        // Multiple choice: subset of valid IDs, deduplicated + sorted.
        if ($type === 'multi') {
            if (!is_array($value)) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid selection.'));
            }
            $validIds = array_column($poll->getOptionsArray(), 'id');
            $chosen = [];
            foreach ($value as $v) {
                if (is_string($v) && in_array($v, $validIds, true)) {
                    $chosen[$v] = true;
                }
            }
            $chosen = array_keys($chosen);
            if (count($chosen) === 0) {
                throw new \InvalidArgumentException($this->l10n->t('Please choose at least one option.'));
            }
            sort($chosen);
            return $chosen;
        }

        // Ranking: a complete permutation of the option IDs. Incomplete or
        // with duplicates is not a ranking — better reject than guess.
        if ($type === 'rank') {
            if (!is_array($value)) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid order.'));
            }
            $validIds = array_column($poll->getOptionsArray(), 'id');
            $order = [];
            foreach ($value as $v) {
                if (!is_string($v) || !in_array($v, $validIds, true) || in_array($v, $order, true)) {
                    throw new \InvalidArgumentException($this->l10n->t('Invalid order.'));
                }
                $order[] = $v;
            }
            if (count($order) !== count($validIds)) {
                throw new \InvalidArgumentException($this->l10n->t('Please sort all answers.'));
            }
            return $order;
        }

        // Matching: exactly one target per item. Targets may occur several times
        // (in a poll that is a statement, not a mistake), but no item may be
        // left open — a half assignment could not be evaluated.
        if ($type === 'match') {
            if (!is_array($value)) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid assignment.'));
            }
            $cfg = $poll->getMatchConfig();
            $targetIds = array_column($cfg['targets'], 'id');
            $assigned = [];
            foreach ($cfg['items'] as $item) {
                $pick = $value[$item['id']] ?? null;
                if (!is_string($pick) || !in_array($pick, $targetIds, true)) {
                    throw new \InvalidArgumentException($this->l10n->t('Please assign every item.'));
                }
                $assigned[$item['id']] = $pick;
            }
            if ($assigned === []) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid assignment.'));
            }
            return $assigned;
        }

        // Estimate question: a finite number (int or float). "1e999" and JSON 1e999
        // become INF in PHP — not an estimate, and json_encode fails on it.
        if ($type === 'number') {
            $n = Input::number($value);
            if ($n === null) {
                throw new \InvalidArgumentException($this->l10n->t('Please enter a number.'));
            }
            return $n;
        }

        // Free text: trimmed, whitespace normalised, truncated (stored raw).
        // The raw input is cut to four times the length before the regex (clip).
        if ($type === 'text') {
            if (!is_string($value)) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid answer.'));
            }
            $t = trim(preg_replace('/\s+/u', ' ', self::clip($value, 4 * DeckService::TEXT_MAX)) ?? '');
            if ($t === '') {
                throw new \InvalidArgumentException($this->l10n->t('Please enter an answer.'));
            }
            return mb_substr($t, 0, DeckService::TEXT_MAX);
        }

        if ($type === 'scale') {
            $cfg = $poll->getScaleConfig();
            // Spectrum: map aspectId -> value (each aspect within min..max).
            if (($cfg['mode'] ?? 'single') === 'spectrum') {
                if (!is_array($value)) {
                    throw new \InvalidArgumentException($this->l10n->t('Invalid answer.'));
                }
                $out = [];
                foreach ($cfg['aspects'] as $asp) {
                    $v = Input::int($value[$asp['id']] ?? null);
                    if ($v === null) {
                        throw new \InvalidArgumentException($this->l10n->t('Invalid value.'));
                    }
                    if ($v < $cfg['min'] || $v > $cfg['max']) {
                        throw new \InvalidArgumentException($this->l10n->t('Value is outside the scale.'));
                    }
                    $out[$asp['id']] = $v;
                }
                return $out;
            }
            // Compass: one point {x,y}, each -range..range (integer).
            if (($cfg['mode'] ?? 'single') === 'compass') {
                if (!is_array($value)) {
                    throw new \InvalidArgumentException($this->l10n->t('Invalid position.'));
                }
                $r = (int)$cfg['range'];
                $ix = Input::int($value['x'] ?? null);
                $iy = Input::int($value['y'] ?? null);
                if ($ix === null || $iy === null) {
                    throw new \InvalidArgumentException($this->l10n->t('Invalid position.'));
                }
                if ($ix < -$r || $ix > $r || $iy < -$r || $iy > $r) {
                    throw new \InvalidArgumentException($this->l10n->t('Position is outside the field.'));
                }
                return ['x' => $ix, 'y' => $iy];
            }
            $v = Input::int($value);
            if ($v === null) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid value.'));
            }
            if ($v < $cfg['min'] || $v > $cfg['max']) {
                throw new \InvalidArgumentException($this->l10n->t('Value is outside the scale.'));
            }
            return $v;
        }

        // words
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException($this->l10n->t('Invalid words.'));
        }
        // The phone sends one field per word (maxWords, at most 5). A list far
        // longer than that is not a vote — rejected before any per-word work:
        // cleaning costs a dozen Unicode passes per entry, and an anonymous
        // body of millions of one-letter words would otherwise pin a PHP worker
        // for seconds. Each entry is cut before it is cleaned (clip), and the
        // loop stops at maxWords distinct words — the same words as cutting
        // afterwards, since the first spelling in order wins either way.
        $max = max(1, $poll->getMaxWords());
        if (count($value) > max(self::WORD_ITEMS_MIN, 2 * $max)) {
            throw new \InvalidArgumentException($this->l10n->t('Invalid words.'));
        }
        $words = [];
        foreach ($value as $raw) {
            if (!is_scalar($raw)) {
                continue; // nested arrays and the like are not a word
            }
            $word = TallyService::cleanText(self::clip((string)$raw, self::WORD_RAW_MAX));
            if ($word !== '') {
                // Display truncation (clean again afterwards, otherwise the word
                // ends on a space); lowercasing only happens when counting.
                $word = TallyService::cleanText(mb_substr($word, 0, 40));
                // Detect duplicates via the counting key, so that
                // "Coffee" + "COFFEE" do not go into the cloud twice. The first
                // spelling stays stored. If nothing remains without variation selectors
                // and joiners, it is not a word and takes up no
                // slot (the same test as for the name).
                if (TallyService::nameKey($word) !== '') {
                    $words[TallyService::wordKey($word)] ??= $word;
                    if (count($words) >= $max) {
                        break;
                    }
                }
            }
        }
        $words = array_values($words);
        if (count($words) === 0) {
            throw new \InvalidArgumentException($this->l10n->t('Please enter at least one word.'));
        }
        return $words;
    }

    /**
     * Raw client text cut to $max characters BEFORE the Unicode regexes and
     * normalisers run over it: their cost grows with the input, and the body
     * of a public request is bounded nowhere else. What fits in $max bytes
     * fits in $max characters and stays untouched. Longer broken UTF-8
     * becomes '' — as before, when the /u regexes dropped it (mb_substr
     * would turn it into '?' instead).
     */
    private static function clip(string $s, int $max): string {
        if (strlen($s) <= $max) {
            return $s;
        }
        return mb_check_encoding($s, 'UTF-8') ? mb_substr($s, 0, $max, 'UTF-8') : '';
    }
}
