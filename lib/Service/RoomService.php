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
use Psr\Log\LoggerInterface;

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
    /**
     * Retention: participant heartbeats keep a room alive only while its
     * owner has shown up there within this many days (lastActivity).
     * Otherwise anyone who knows the code could keep a room — and the
     * answers and nicknames in it — alive for ever just by polling it.
     */
    public const PRESENCE_NEEDS_OWNER_DAYS = 180;

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
        private Limits $limits,
        private LoggerInterface $logger,
    ) {
    }

    // ── Rooms ───────────────────────────────────────────────────────────────

    /**
     * @throws \InvalidArgumentException the account already has as many rooms
     *                                   as Limits::ROOMS_PER_OWNER allows
     */
    public function createRoom(string $uid, string $mode = 'poll', string $title = ''): Room {
        $max = $this->limits->get(Limits::ROOMS_PER_OWNER);
        if (count($this->roomMapper->findByOwner($uid)) >= $max) {
            throw $this->tooManyRooms($max);
        }
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
        $room = $this->roomMapper->insert($room);
        // Counted again: parallel requests all passed the check above before
        // any of them had written. Over the cap, this one takes its room back.
        if (count($this->roomMapper->findByOwner($uid)) > $max) {
            $this->roomMapper->delete($room);
            throw $this->tooManyRooms($max);
        }
        return $room;
    }

    private function tooManyRooms(int $max): \InvalidArgumentException {
        return new \InvalidArgumentException($this->l10n->t('You can have at most %d rooms. Delete rooms you no longer need.', [$max]));
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
     *
     * The caps (Limits) are checked before the first write: questions per
     * room, the image budget of the account (the copy's images are new
     * files) and, in createRoom, the number of rooms. Should a copy still
     * fail halfway (a parallel upload used up the budget meanwhile), the
     * half-finished copy is deleted again. With images, the budget is counted
     * once more after the copy: parallel copies each passed the first check,
     * and a copy that finds the account over the budget deletes itself.
     *
     * @throws \InvalidArgumentException a cap would be exceeded
     */
    public function duplicateRoom(Room $src, string $uid): Room {
        $polls = $this->pollMapper->findByRoom($src->getId());
        $maxPolls = $this->limits->get(Limits::POLLS_PER_ROOM);
        if (count($polls) > $maxPolls) {
            throw new \InvalidArgumentException($this->l10n->t('A room can hold at most %d questions.', [$maxPolls]));
        }
        $needed = 0;
        foreach ($polls as $poll) {
            $needed += $this->imageService->sizeOf($poll);
        }
        $remaining = $needed > 0 ? $this->imageService->remainingBudget($uid) : PHP_INT_MAX;
        if ($needed > $remaining) {
            throw $this->imageService->budgetExceeded();
        }

        $copy = $this->createRoom($uid, $src->getMode(), $this->copyTitle($src->titleOrEmpty()));
        try {
            $this->fillCopy($src, $copy, $polls, $remaining);
            if ($needed > 0 && $this->imageService->remainingBudget($uid) < 0) {
                throw $this->imageService->budgetExceeded();
            }
        } catch (\Throwable $e) {
            try {
                $this->deleteRoom($copy);
            } catch (\Throwable) {
                // the original error is the one worth reporting
            }
            throw $e;
        }
        return $copy;
    }

    /**
     * The writing half of duplicateRoom: flow settings and questions.
     *
     * @param Poll[] $polls the source room's deck
     */
    private function fillCopy(Room $src, Room $copy, array $polls, int $remaining): void {
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
        foreach (array_values($polls) as $i => $poll) {
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
            $remaining -= $this->imageService->copy($poll, $new, $remaining);
        }
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
     * A room that cannot be deleted is logged and skipped; the others still
     * go (deleteRoom is one transaction, so the skipped room stays complete
     * and is tried again on the next run).
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
            if ($this->tryDelete($room, 'stale')) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * Delete the rooms of accounts that no longer exist — whatever their
     * presence says. UserDeletedListener does this when an account is
     * deleted, but only while Pulse is enabled: an account deleted while the
     * app was disabled (say, during a Nextcloud upgrade) left its rooms
     * behind, and a new account that later got the same user ID took them
     * over, answers, nicknames and CSV export included.
     *
     * Which account counts as gone is up to the caller ($ownerGone, see
     * CleanupStaleRoomsJob::ownerGone) — this method only walks the owners
     * and deletes. Called by the daily background job.
     *
     * @param callable(string): bool $ownerGone
     * @return int number of deleted rooms
     */
    public function cleanupOrphanedRooms(callable $ownerGone): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('owner_uid')->from('pulse_rooms');
        $result = $qb->executeQuery();
        $owners = [];
        while (($uid = $result->fetchOne()) !== false) {
            $owners[] = (string)$uid;
        }
        $result->closeCursor();

        $deleted = 0;
        foreach ($owners as $uid) {
            if (!$ownerGone($uid)) {
                continue;
            }
            foreach ($this->roomMapper->findByOwner($uid) as $room) {
                if ($this->tryDelete($room, 'orphaned')) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    /** deleteRoom for the cleanup: log and go on instead of aborting the run. */
    private function tryDelete(Room $room, string $reason): bool {
        try {
            $this->deleteRoom($room);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Pulse: could not delete {reason} room {code}.', [
                'reason' => $reason,
                'code' => $room->getCode(),
                'exception' => $e,
            ]);
            return false;
        }
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
     *
     * Participant heartbeats count only while the owner has touched (or
     * created) the room within PRESENCE_NEEDS_OWNER_DAYS: anonymous
     * polling alone must not keep a room and its personal data for ever.
     * A room its owner has abandoned thus goes on the first daily run after
     * that period, however many phones still poll it (unless a self-paced
     * deadline still lies ahead).
     */
    private function lastActivity(Room $room): int {
        $owner = max($room->getCreatedAt(), $room->getTouchedAt());
        $presenceCounts = $owner >= $this->timeFactory->getTime() - self::PRESENCE_NEEDS_OWNER_DAYS * 86_400;
        return max(
            $room->getOpenedAt(),
            $room->getClosedAt(),
            $room->getClosesAt(),
            $room->getReleasedAt(),
            $room->getTouchedAt(),
            $presenceCounts ? $this->presenceMapper->lastSeen($room->getId()) : 0,
        );
    }

    // ── Presence ──────────────────────────────────────────────────────────────

    /**
     * Heartbeat of an anonymous participant. Called on every participant poll
     * (even without a vote), so that "currently here" also counts spectators.
     *
     * @param ?\Closure(): bool $mayAdd asked before a token gets its first
     *        presence row in this room; false = no row this time (the caller's
     *        limit per address). null = always.
     */
    public function heartbeat(Room $room, #[\SensitiveParameter] string $voterToken, ?\Closure $mayAdd = null): void {
        $this->presenceMapper->touch($room->getId(), $voterToken, $this->timeFactory->getTime(), $mayAdd);
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
