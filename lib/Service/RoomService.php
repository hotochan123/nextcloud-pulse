<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\PresenceMapper;
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
 * Rooms: create, rename, copy, empty, delete — plus presence
 * ("N here") and the "My rooms" list. Everything that concerns the room as a
 * whole; the questions in it belong to the DeckService.
 */
class RoomService {
    use TTransactional;

    private const MODES = ['poll', 'quiz'];
    /**
     * Whoever's heartbeat is at most this many seconds old counts as "currently here".
     * Public so that "online" in the progress view (PaceStateService) uses the same window.
     */
    public const PRESENCE_WINDOW = 15;

    public function __construct(
        private RoomMapper $roomMapper,
        private PollMapper $pollMapper,
        private VoteMapper $voteMapper,
        private PresenceMapper $presenceMapper,
        private PlayerMapper $playerMapper,
        private ProgressMapper $progressMapper,
        private CodeGenerator $codeGenerator,
        private PollImageService $imageService,
        private ITimeFactory $timeFactory,
        private IL10N $l10n,
        private IDBConnection $db,
    ) {
    }

    // ── Rooms ───────────────────────────────────────────────────────────────

    public function createRoom(string $uid, string $mode = 'poll', string $title = ''): Room {
        if (!in_array($mode, self::MODES, true)) {
            $mode = 'poll';
        }
        $room = new Room();
        $room->setCode($this->codeGenerator->uniqueRoomCode());
        $room->setTitle($this->sanitizeTitle($title));
        $room->setOwnerUid($uid);
        $room->setActivePollId(0);
        $room->setMode($mode);
        $room->setCreatedAt($this->timeFactory->getTime());
        return $this->roomMapper->insert($room);
    }

    /**
     * Load a room by code and enforce ownership.
     *
     * @throws DoesNotExistException room unknown
     * @throws NotOwnerException     room belongs to someone else
     */
    public function getOwnedRoom(string $code, string $uid): Room {
        $room = $this->roomMapper->findByCode($code);
        if ($room->getOwnerUid() !== $uid) {
            throw new NotOwnerException();
        }
        return $room;
    }

    /**
     * Rename a room. An empty title is allowed and means "no title" —
     * the UI then shows only the code again.
     */
    public function setTitle(Room $room, string $title): void {
        $room->setTitle($this->sanitizeTitle($title));
        $this->roomMapper->update($room);
    }

    /**
     * Clean up a title: control characters (including line breaks) out, surrounding
     * whitespace gone, cut to 80 characters — matching the column width, multibyte-safe.
     */
    private function sanitizeTitle(string $title): string {
        $clean = preg_replace('/[\p{C}]+/u', ' ', $title) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
        return mb_substr($clean, 0, 80);
    }

    /**
     * Copy a room as a template: new code, the same questions in the same
     * order (including solutions, time limits and the accepted/rejected lists
     * learned while grading free text) — but NO votes, participants
     * or presence. The copy's cursor is idle, every question starts neutral.
     *
     * `reveal_at_end` moves along (a flow decision of the deck); `practice`
     * deliberately not — the copy is the real run, not the practice run.
     * Likewise the self-paced format (`pace`, `timed`, `feedback`) — it
     * describes the next run. Window, frozen order, join lock and owner
     * visit do not: the copy is a fresh draft.
     */
    public function duplicateRoom(Room $src, string $uid): Room {
        $copy = $this->createRoom($uid, $src->getMode(), $this->copyTitle($src->titleOrEmpty()));
        // Only write if something differs from the default — this way a moderated
        // room with the standard flow gets no extra UPDATE.
        $changed = false;
        if ($src->getRevealAtEnd()) {
            $copy->setRevealAtEnd(true);
            $changed = true;
        }
        if ($src->getPace() !== $copy->getPace()
            || $src->getTimed() !== $copy->getTimed()
            || $src->getFeedback() !== $copy->getFeedback()) {
            $copy->setPace($src->getPace());
            $copy->setTimed($src->getTimed());
            $copy->setFeedback($src->getFeedback());
            $changed = true;
        }
        if ($changed) {
            $this->roomMapper->update($copy);
        }
        $now = $this->timeFactory->getTime();
        foreach ($this->pollMapper->findByRoom($src->getId()) as $i => $poll) {
            $new = new Poll();
            $new->setRoomId($copy->getId());
            $new->setType($poll->getType());
            $new->setQuestion($poll->getQuestion());
            $new->setOptions($poll->getOptions());
            $new->setMaxWords($poll->getMaxWords());
            $new->setCorrectOption($poll->getCorrectOption());
            $new->setAnswerKey($poll->getAnswerKey());
            $new->setTimeLimit($poll->getTimeLimit());
            // Fresh: nothing running, nothing revealed, positions without gaps.
            $new->setStatus('active');
            $new->setStartedAt(0);
            $new->setPosition($i);
            $new->setCreatedAt($now);
            $new = $this->pollMapper->insert($new);
            // The image gets its own file — otherwise deleting one room
            // would take the image away from the copy.
            $this->imageService->copy($poll, $new);
        }
        return $copy;
    }

    /**
     * Title of the copy: "… (copy)". The suffix has to fit into the 80 characters,
     * otherwise sanitizeTitle would cut it off itself.
     */
    private function copyTitle(string $title): string {
        if ($title === '') {
            return '';
        }
        $suffix = ' ' . $this->l10n->t('(copy)');
        $room = 80 - mb_strlen($suffix);
        return mb_substr($title, 0, $room) . $suffix;
    }

    /**
     * Delete a room with everything in it. The rows go in one transaction,
     * retried on a deadlock or lock timeout (atomicRetry): if a statement
     * fails, the room stays complete and can be deleted again. Without it the
     * room row would be gone and the questions, votes, players and progress
     * after the failed statement would stay behind for good — the cleanup job,
     * the delete button and the account deletion all start from the room.
     *
     * The order closes races with the self-paced mode, where /join, /vote and
     * /next write without a transaction around this call:
     * - the room row first: a join that is currently holding its lock
     *   (PaceService::locked) finishes first and is then deleted along with it; a
     *   later one waits for the commit and then no longer finds a room (404);
     * - players before votes and progress: /vote and /next check after
     *   writing, with a locking read, whether the player still exists
     *   (PaceService::assertStillJoined); that read waits for this
     *   transaction. If the check still sees them, the deletion afterwards
     *   takes what was written with it; otherwise the check cleans up itself;
     * - the image files last, after the commit: a storage error then leaves
     *   no rows behind without a room that nobody would ever find again, and
     *   a rolled-back deletion leaves no question without its image.
     */
    public function deleteRoom(Room $room): void {
        $polls = $this->atomicRetry(function () use ($room): array {
            $this->roomMapper->delete($room);
            $this->presenceMapper->deleteByRoom($room->getId());
            $this->playerMapper->deleteByRoom($room->getId());
            $polls = $this->pollMapper->findByRoom($room->getId());
            foreach ($polls as $poll) {
                $this->voteMapper->deleteByPoll($poll->getId());
                $this->pollMapper->delete($poll);
            }
            $this->progressMapper->deleteByRoom($room->getId());
            return $polls;
        }, $this->db);
        foreach ($polls as $poll) {
            $this->imageService->discard($poll);
        }
    }

    /**
     * Empty a room for a fresh run: discard all votes (of all questions),
     * participants/nicknames, presence and with them the leaderboard (derived
     * from the votes). The questions stay; the cursor goes to "idle" and
     * every question gets its timer/status neutralised, so that nothing is
     * left hanging as "revealed" or with expired time.
     *
     * Self-paced additionally: everyone's progress, window, frozen order and
     * join lock — afterwards the room is a draft again. The format (`pace`,
     * `timed`, `feedback`) and `touched_at` (retention) stay. The reset always
     * empties the progress, even with `pace='live'`: when switching
     * self → live, `pace` is already live by the time the reset runs.
     */
    public function resetRoom(Room $room): void {
        foreach ($this->pollMapper->findByRoom($room->getId()) as $poll) {
            $this->voteMapper->deleteByPoll($poll->getId());
            if ($poll->getStartedAt() !== 0 || $poll->getStatus() !== 'active') {
                $poll->setStartedAt(0);
                $poll->setStatus('active');
                $this->pollMapper->update($poll);
            }
        }
        $this->presenceMapper->deleteByRoom($room->getId());
        $this->playerMapper->deleteByRoom($room->getId());
        $this->progressMapper->deleteByRoom($room->getId());
        // Only write the room row if there really is something to reset.
        // A moderated room never has a window and so gets no extra
        // UPDATE (as before, only when the cursor is running).
        $changed = false;
        if ($room->getActivePollId() !== 0) {
            $room->setActivePollId(0);
            $changed = true;
        }
        if ($room->getOpenedAt() !== 0 || $room->getClosesAt() !== 0
            || $room->getClosedAt() !== 0 || $room->getReleasedAt() !== 0
            || $room->getDeckOrder() !== null || $room->getJoinsLocked()) {
            $room->setOpenedAt(0);
            $room->setClosesAt(0);
            $room->setClosedAt(0);
            $room->setReleasedAt(0);
            $room->setDeckOrder(null);
            $room->setJoinsLocked(false);
            $changed = true;
        }
        if ($changed) {
            $this->roomMapper->update($room);
        }
    }

    /**
     * Switch the practice run on/off. Switching empties the room in both
     * directions (fresh practice run or clean real start) — so you can test
     * as often as you like without test votes leaking into the real run.
     * As long as the practice run is on, the server hides the leaderboard (see
     * results()/publicState()).
     */
    public function setPractice(Room $room, bool $on): void {
        $room->setPractice($on);
        // resetRoom persists the room (roomMapper->update) as well — but even
        // when no question is currently active, the practice flag must be written.
        $this->roomMapper->update($room);
        $this->resetRoom($room);
    }

    /**
     * Switch revealing only at the end (instead of per question) on/off. A pure
     * flow/display flag — empties NOTHING (unlike the practice run), the questions
     * just run without intermediate reveals. Takes effect in publicState()/results()
     * (suppress the reveal) and in the presenter (no "Reveal", timer end only closes).
     */
    public function setRevealAtEnd(Room $room, bool $on): void {
        $room->setRevealAtEnd($on);
        $this->roomMapper->update($room);
    }

    /**
     * End the quiz: mark the currently running question as "ended" (the cursor
     * stays). Only this state releases results + leaderboard to the participants
     * in "Reveal at the end" mode (see publicState()).
     */
    public function endQuiz(Room $room): void {
        $activeId = $room->getActivePollId();
        if ($activeId !== 0) {
            $this->pollMapper->markEnded($activeId);
        }
    }

    /**
     * Delete old rooms including questions/votes/players. "Old" = created more than
     * $maxAgeSeconds ago AND no sign of life (lastActivity) within the same window
     * (so a room that was still in use yesterday survives, even if it was
     * created weeks ago). Keeps the brute-force attack surface small and
     * tidies up the DB. Called by the daily background job.
     *
     * @return int number of deleted rooms
     */
    public function cleanupStaleRooms(int $maxAgeSeconds): int {
        $cutoff = $this->timeFactory->getTime() - $maxAgeSeconds;
        $deleted = 0;
        foreach ($this->roomMapper->findOlderThan($cutoff) as $room) {
            if ($this->lastActivity($room) >= $cutoff) {
                continue; // recently used -> keep
            }
            $this->deleteRoom($room);
            $deleted++;
        }
        return $deleted;
    }

    /**
     * A room's latest sign of life for retention: the last participant
     * heartbeat, the owner's last activity on the room (`touched_at`, any
     * room mode) and, for self-paced rooms, also opening, deadline, closing
     * and release. Owner activity is every request of the owner that names
     * the room — opening it, reading results, editing the deck or its
     * settings, presenting, running the window (RoomApiController::touch,
     * written at most hourly). Duplicating touches the source; the copy is a
     * new room with its own `created_at`, so the job does not even consider
     * it within the retention period. A deck prepared weeks ahead thus
     * survives even though no audience has joined yet. The deadline counts
     * so that a long homework does not vanish before it ends; release so that
     * the teacher can still grade weeks after the deadline. Moderated rooms
     * have the window fields at 0 — there presence and owner activity decide.
     * Owner activity from before this was recorded in every room mode is
     * unknown (`touched_at` = 0); Version000000Date20260929120000 covers the
     * installs where that matters.
     */
    private function lastActivity(Room $room): int {
        return max(
            $room->getOpenedAt(),
            $room->getClosedAt(),
            $room->getClosesAt(),
            $room->getReleasedAt(),
            $room->getTouchedAt(),
            $this->presenceMapper->lastSeen($room->getId()),
        );
    }

    // ── Presence ──────────────────────────────────────────────────────────────

    /**
     * Heartbeat of an anonymous participant. Called on every participant poll
     * (even without a vote), so that "currently here" also counts spectators.
     */
    public function heartbeat(Room $room, string $voterToken): void {
        $this->presenceMapper->touch($room->getId(), $voterToken, $this->timeFactory->getTime());
    }

    /** Number of currently active participants (heartbeat within the window). */
    public function presentCount(Room $room): int {
        $since = $this->timeFactory->getTime() - self::PRESENCE_WINDOW;
        return $this->presenceMapper->countActive($room->getId(), $since);
    }

    /**
     * Rooms of the presenting person (newest first), each with its question count and
     * whether a question is currently active — for the "My rooms" list. For
     * self-paced rooms also the window (open, deadline, released …), otherwise
     * `window: null`.
     *
     * @return list<array{code:string, title:string, mode:string, createdAt:int, activePollId:int, pollCount:int, pace:string, window:?array}>
     */
    public function listRooms(string $uid): array {
        return array_map(function (Room $room): array {
            $pollCount = $this->pollMapper->countByRoom($room->getId());
            return [
                'code' => $room->getCode(),
                'title' => $room->titleOrEmpty(),
                'mode' => $room->getMode(),
                'createdAt' => $room->getCreatedAt(),
                'activePollId' => $room->getActivePollId(),
                'pollCount' => $pollCount,
                'pace' => $room->getPace(),
                'window' => PaceService::isSelf($room)
                    ? PaceService::windowView($room, $this->timeFactory->getTime(), $pollCount)
                    : null,
            ];
        }, $this->roomMapper->findByOwner($uid));
    }
}
