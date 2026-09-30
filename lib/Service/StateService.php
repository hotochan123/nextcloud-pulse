<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\AppInfo\Application;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\VoteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Read views on a room: live results for the moderator, the public
 * participant state, the overall results (internal and public), the
 * CSV export and the cheap change fingerprints for adaptive polling.
 * Writes nothing.
 *
 * Self-paced quiz (PaceService::isSelf): the public views, the
 * moderator results and their versions delegate to PaceStateService in their
 * first line — the moderated code here stays untouched.
 *
 * Section references (§…) point to the design notes of the redesign and of the
 * self-paced quiz, which are not in the public repository (see "References in
 * code comments" in the README).
 */
class StateService {
    public function __construct(
        private PollMapper $pollMapper,
        private VoteMapper $voteMapper,
        private TallyService $tallyService,
        private DeckService $deckService,
        private VoteService $voteService,
        private RoomService $roomService,
        private ITimeFactory $timeFactory,
        private IL10N $l10n,
        private IConfig $config,
        // only touched in self-paced mode:
        private PaceStateService $paceState,
    ) {
    }

    /**
     * Overall summary: every question of the deck with its tally.
     *
     * @return list<array{poll:array, results:array}>
     */
    public function summary(Room $room): array {
        $out = [];
        foreach ($this->deckService->deck($room) as $poll) {
            $out[] = [
                'poll' => $this->deckService->ownerPoll($poll),
                'results' => $this->tallyService->tally($poll, $this->voteMapper->findByPoll($poll->getId())),
            ];
        }
        return $out;
    }

    /**
     * Results of all questions as CSV (long format, one row per answer).
     * `;`-separated + UTF-8 BOM → opens correctly in Excel, umlauts included; numbers
     * in the export's language (see num()). Quotes doubled as per RFC 4180,
     * without backslash escaping (like PaceStateService; PHP 8.4 wants the
     * escape character stated explicitly).
     */
    public function exportCsv(Room $room): string {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, [
            $this->l10n->t('No.'),
            $this->l10n->t('Question'),
            $this->l10n->t('Type'),
            $this->l10n->t('Answer'),
            $this->l10n->t('Votes'),
            $this->l10n->t('Share'),
        ], ';', '"', '');

        $nr = 0;
        foreach ($this->deckService->deck($room) as $poll) {
            $nr++;
            $tally = $this->tallyService->tally($poll, $this->voteMapper->findByPoll($poll->getId()));
            $total = (int)$tally['total'];
            $question = $poll->getQuestion();
            $typeLabel = $this->typeLabel($poll->getType());

            // Spectrum: one row per aspect (mean + answer count) instead of value/share.
            if ($poll->getType() === 'scale' && ($tally['mode'] ?? 'single') === 'spectrum') {
                foreach ($tally['results'] as $row) {
                    $avg = $this->num($row['average']);
                    $label = $this->l10n->t('%s · average', [$row['label']]);
                    fputcsv($fh, [$nr, $question, $typeLabel, $label, $avg, $row['n']], ';', '"', '');
                }
                if (count($tally['results']) === 0) {
                    fputcsv($fh, [$nr, $question, $typeLabel, $this->l10n->t('(no aspects)'), 0, ''], ';', '"', '');
                }
                continue;
            }

            // Compass: centre of gravity + one point (X / Y) per vote.
            if ($poll->getType() === 'scale' && ($tally['mode'] ?? 'single') === 'compass') {
                $c = $tally['centroid'] ?? null;
                $ct = $c
                    ? $this->num($c['x']) . ' / ' . $this->num($c['y'])
                    : '—';
                fputcsv($fh, [$nr, $question, $typeLabel, $this->l10n->t('Centre of gravity (X / Y)'), $ct, $tally['total']], ';', '"', '');
                foreach ($tally['points'] as $p) {
                    fputcsv($fh, [$nr, $question, $typeLabel, $this->l10n->t('Point (X / Y)'), $p['x'] . ' / ' . $p['y'], ''], ';', '"', '');
                }
                continue;
            }

            // Matching: one row per pairing "item → target" that was actually chosen.
            // All combinations would be 64 rows of noise with eight pairs.
            if ($poll->getType() === 'match') {
                if ($total === 0) {
                    fputcsv($fh, [$nr, $question, $typeLabel, $this->l10n->t('(no answers)'), 0, ''], ';', '"', '');
                    continue;
                }
                foreach ($tally['results'] as $row) {
                    foreach ($row['targets'] as $target) {
                        if ($target['count'] === 0) {
                            continue;
                        }
                        $share = $row['n'] > 0 ? round($target['count'] / $row['n'] * 100) . '%' : '';
                        $label = $this->l10n->t('%1$s → %2$s', [$row['label'], $target['label']]);
                        fputcsv($fh, [$nr, $question, $typeLabel, $label, $target['count'], $share], ';', '"', '');
                    }
                }
                continue;
            }

            // Ranking: one row per option with the mean place and the number of first places.
            if ($poll->getType() === 'rank') {
                foreach ($tally['results'] as $rank => $row) {
                    $avg = $this->num($row['average']);
                    fputcsv($fh, [
                        $nr, $question, $typeLabel,
                        $this->l10n->t('%1$s. %2$s · average place %3$s', [$rank + 1, $row['label'], $avg]),
                        $row['first'], $row['n'],
                    ], ';', '"', '');
                }
                if (count($tally['results']) === 0) {
                    fputcsv($fh, [$nr, $question, $typeLabel, $this->l10n->t('(no answers)'), 0, ''], ';', '"', '');
                }
                continue;
            }

            // Free text: one row per answer group (same normal form) in the
            // spelling of the first answer, as in grading, defused against formulas
            // (CsvFormat::cell). The tally is called `answers` here,
            // not `results` — which is why the generic branch below ran into a
            // TypeError (500).
            if ($poll->getType() === 'text') {
                foreach ($tally['answers'] as $row) {
                    $count = (int)$row['count'];
                    $share = $total > 0 ? round($count / $total * 100) . '%' : '';
                    fputcsv($fh, [$nr, $question, $typeLabel, CsvFormat::cell((string)$row['sample']), $count, $share], ';', '"', '');
                }
                if (count($tally['answers']) === 0) {
                    fputcsv($fh, [$nr, $question, $typeLabel, $this->l10n->t('(no answers)'), 0, ''], ';', '"', '');
                }
                continue;
            }

            foreach ($tally['results'] as $row) {
                $count = (int)$row['count'];
                $share = $total > 0 ? round($count / $total * 100) . '%' : '';
                fputcsv($fh, [$nr, $question, $typeLabel, $this->answerLabel($poll->getType(), $row), $count, $share], ';', '"', '');
            }
            if ($poll->getType() === 'scale') {
                fputcsv($fh, [$nr, $question, $typeLabel, $this->l10n->t('Average'), $this->num($tally['average']), ''], ';', '"', '');
            }
            if (count($tally['results']) === 0) {
                // Word cloud without answers -> the question is still visible in the export.
                fputcsv($fh, [$nr, $question, $typeLabel, $this->l10n->t('(no answers)'), 0, ''], ';', '"', '');
            }
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return "\xEF\xBB\xBF" . $csv; // UTF-8 BOM
    }

    /** A number in the export's language (rule in CsvFormat::number). */
    private function num(float|int|string $value): string {
        return CsvFormat::number($value, $this->l10n->getLocaleCode());
    }

    /** Type label (rule in CsvFormat::typeLabel, also used by the self-paced export). */
    private function typeLabel(string $type): string {
        return CsvFormat::typeLabel($type, $this->l10n);
    }

    /**
     * Words come from the audience and go through CsvFormat::cell; a
     * guess carries its number in `value` (no `label` — the column stayed empty).
     *
     * @param array $row result row from TallyService
     */
    private function answerLabel(string $type, array $row): string {
        return match ($type) {
            'words' => CsvFormat::cell((string)($row['word'] ?? '')),
            'scale' => (string)($row['value'] ?? ''),
            'number' => $this->num($row['value'] ?? 0),
            default => (string)($row['label'] ?? ''),
        };
    }

    /**
     * @return array{type:string, total:int, results:array, present:int}
     * @throws \InvalidArgumentException question does not belong to the room
     */
    public function results(Room $room, int $pollId): array {
        if (PaceService::isSelf($room)) {
            return $this->paceState->results($room, $pollId);
        }
        $poll = $this->deckService->requirePollInRoom($room, $pollId);
        $tally = $this->tallyService->tally($poll, $this->voteMapper->findByPoll($poll->getId()));
        // Live participant count for the moderator badge, piggybacked on the poll (2.5 s).
        $tally['present'] = $this->roomService->presentCount($room);
        if ($room->getMode() === 'quiz') {
            // Server time + question timing for the countdown, status + live leaderboard.
            $tally['serverNow'] = $this->timeFactory->getTime();
            $tally['startedAt'] = $poll->getStartedAt();
            $tally['timeLimit'] = $poll->getTimeLimit();
            $tally['status'] = $poll->getStatus();
            $tally['practice'] = $room->getPractice();
            // No running leaderboard in a practice run (no scoring) or with
            // "Reveal at the end" (that only exists with the final standings).
            $tally['leaderboard'] = ($room->getPractice() || $room->getRevealAtEnd())
                ? [] : $this->voteService->leaderboardFor($room, null);
        }
        return $tally;
    }

    // ── Change version (adaptive polling) ─────────────────────────────────

    /**
     * Cheap fingerprint of the participant state: changes exactly when
     * something visible to participants changes (question switched, locked/
     * revealed, timer restarted, new vote). The client polls with
     * `?v=<version>`; if it matches, the controller answers with 204 (tiny),
     * otherwise with the full state.
     *
     * Cost during a question: the poll row plus the vote stamp
     * (VoteMapper::changeStamp: two reads from the shared cache — no vote is
     * read, no tally built), plus one presence COUNT for the audience view.
     * In the lobby two COUNTs (presence, players). Only without a shared cache,
     * or for two minutes after a vote was written inside a transaction, does
     * the stamp read every vote payload of the question.
     *
     * Callers must build the version BEFORE the state they send along with
     * it (see VoteMapper::changeStamp): a vote committed in between then
     * changes the next version instead of hiding behind a 204.
     *
     * Deliberately WITHOUT presence (only the moderator counts that; otherwise every
     * heartbeat would bump the version → everyone would poll in full all the time).
     *
     * In self-paced mode there is no cheap fingerprint: the version is
     * a hash over the fully built state for this token (selfVersion).
     * $voterToken only matters there; when moderated, the version is the same for everyone.
     */
    public function stateVersion(Room $room, bool $withPresence = false, #[\SensitiveParameter] ?string $voterToken = null): string {
        if (PaceService::isSelf($room)) {
            return $this->selfVersion($this->paceState->publicState($room, $voterToken, $withPresence));
        }
        $activeId = $room->getActivePollId();
        if ($activeId === 0) {
            // Lobby: the "N here" counter should be live -> presence count goes into the
            // fingerprint, otherwise the 204 cache swallows every change.
            // Plus what else the lobby shows: title, practice-run banner and whether
            // one's own name still stands — a reset and the practice-run
            // switch delete all players, and without the count a
            // phone would hold on to "Ready, Anna" until the first question is already running.
            return $this->opaque('idle:' . $this->roomService->presentCount($room)
                . ':' . $this->roomFlags($room) . ':' . $this->voteService->playerCount($room));
        }
        try {
            $poll = $this->pollMapper->find($activeId);
        } catch (DoesNotExistException) {
            return $this->opaque('idle');
        }
        // Change stamp instead of a plain count: a CHANGED poll
        // answer (upsert) leaves the count unchanged, so it would be answered with 204
        // "unchanged" and the projector/live view would not update.
        // Grading free text, in turn, changes answerKey.
        $stamp = $this->voteMapper->changeStamp($activeId);
        // For the audience view, presence belongs in the fingerprint:
        // its incoming-answers display shows "12 of 24", and without the number in the
        // fingerprint the 204 cache swallows a newly joined person
        // until someone happens to vote. For voters it stays out
        // — there every heartbeat would force everyone into a full poll.
        $presence = $withPresence ? ':p' . $this->roomService->presentCount($room) : '';
        // Revealed yes/no and the practice run belong in it: toggling "Reveal at the end"
        // afterwards changes no status, but it changes what phone and
        // projector may show — otherwise the 204 would hold on to the old reveal.
        // Plus the content of the question: an edited poll question without
        // votes otherwise changes neither status nor start time nor stamp — the
        // phones would keep the old option IDs, and every vote would fail.
        $flags = ':' . ($this->isRevealed($room, $poll) ? 'r' : 'h') . ':' . $this->roomFlags($room)
            . ':' . crc32((string)json_encode($poll->jsonSerialize()));
        return $this->opaque($activeId . ':' . $poll->getStatus() . ':' . $poll->getStartedAt() . ':' . $stamp
            . ':' . crc32((string)$poll->getAnswerKey()) . $flags . $presence);
    }

    /**
     * Version of a fully built self-paced state (without serverNow).
     * The controller builds the state once and derives the version from it —
     * everything time-driven (deadline, finality, time running out) is contained in it.
     */
    public function selfVersion(array $state): string {
        return $this->paceState->version($state);
    }

    /** Room settings the audience sees: practice-run banner and title. */
    private function roomFlags(Room $room): string {
        return ($room->getPractice() ? 'p' : '-') . crc32($room->titleOrEmpty());
    }

    /** Fingerprint as an opaque keyed hash (why: PublicView::opaque). */
    private function opaque(string $raw): string {
        return PublicView::opaque($raw, $this->config->getSystemValueString('secret', ''));
    }

    /**
     * Like stateVersion, but for the moderator, including the presence count (which it displays).
     *
     * @throws \InvalidArgumentException question does not belong to the room
     */
    public function resultsVersion(Room $room, int $pollId): string {
        if (PaceService::isSelf($room)) {
            // Builds twice when something changed — only one moderator, accepted.
            return $this->paceState->version($this->paceState->results($room, $pollId));
        }
        $poll = $this->deckService->requirePollInRoom($room, $pollId);
        $stamp = $this->voteMapper->changeStamp($pollId);
        $present = $this->roomService->presentCount($room);
        return $pollId . ':' . $poll->getStatus() . ':' . $poll->getStartedAt() . ':' . $stamp . ':' . $present
            . ':' . crc32((string)$poll->getAnswerKey())
            . ':' . (int)$room->getRevealAtEnd() . (int)$room->getPractice();
    }

    // ── Public participant view ──────────────────────────────────────────

    /**
     * May an image of this question be served publicly? Only if the
     * question is running right now or has been revealed — otherwise it would give away
     * the next question in the deck. The same reveal rule as publicState/publicSummary.
     *
     * In self-paced mode only for people who have reached the question
     * ($voterToken from the cookie that the <img> sends along same-site).
     */
    public function imageVisible(Room $room, Poll $poll, #[\SensitiveParameter] ?string $voterToken = null): bool {
        if (PaceService::isSelf($room)) {
            return $this->paceState->imageVisible($room, $poll, $voterToken);
        }
        if ($room->getActivePollId() === $poll->getId()) {
            return true;
        }
        return $this->isRevealed($room, $poll);
    }

    /**
     * Is this question revealed to the audience? Quiz with "Reveal at the end":
     * only once it has ended; otherwise as soon as it is locked or ended.
     */
    private function isRevealed(Room $room, Poll $poll): bool {
        return $this->quizRevealAtEnd($room)
            ? $poll->getStatus() === 'ended'
            : in_array($poll->getStatus(), ['locked', 'ended'], true);
    }

    /**
     * IDs of all questions of the room that are not yet revealed to the audience.
     * Their points belong in no public leaderboard: if the moderator skipped a
     * question without revealing it, the score would otherwise give away whether
     * one's own answer was right — and the question can be opened again.
     *
     * @return list<int>
     */
    private function hiddenPollIds(Room $room): array {
        $ids = [];
        foreach ($this->pollMapper->findByRoom($room->getId()) as $poll) {
            if (!$this->isRevealed($room, $poll)) {
                $ids[] = $poll->getId();
            }
        }
        return $ids;
    }

    /**
     * "Reveal at the end" only applies to quizzes. The moderator UI only offers the
     * switch there, but it can be saved for any room —
     * a poll must not be hidden by it.
     */
    private function quizRevealAtEnd(Room $room): bool {
        return $room->getMode() === 'quiz' && $room->getRevealAtEnd();
    }

    /**
     * State for the participant page: the active question (if any),
     * live results and whether this cookie has already voted.
     *
     * `$withPresence` adds the presence count during a running question
     * as well — the incoming-answers display of the projector stage puts the answers
     * received in relation to it ("12 of 24"). Only the audience view
     * asks for it; on the voting path the COUNT would be pure load.
     *
     * Public tallies are capped (PublicPayload::tally), and in a quiz the
     * leaderboard is the top 10 plus the viewer's own row in extra fields
     * (PublicPayload::withLeaderboard) — every phone refetches this after
     * each vote.
     *
     * @return array{protocol:int, room:array{code:string}, poll:?array, results:?array, hasVoted:bool, myValue:mixed}
     */
    public function publicState(Room $room, #[\SensitiveParameter] ?string $voterToken, bool $withPresence = false): array {
        if (PaceService::isSelf($room)) {
            return $this->paceState->publicState($room, $voterToken, $withPresence);
        }
        $quiz = $room->getMode() === 'quiz';
        $base = [
            // If the bundle in the tab no longer matches the server, it reloads itself
            // once (src/util/protocol.js).
            'protocol' => Application::PROTOCOL,
            'room' => ['code' => $room->getCode(), 'title' => $room->titleOrEmpty(), 'mode' => $room->getMode()],
            'poll' => null,
            'results' => null,
            'hasVoted' => false,
            'myValue' => null,
            'serverNow' => $this->timeFactory->getTime(),
            // 'present' (lobby momentum, §3) is set ONLY in the lobby branch —
            // during a running question nobody renders it, and the COUNT would be
            // pure load on the hot voting path.
            'present' => 0,
        ];
        if ($quiz) {
            $base['nickname'] = $this->voteService->playerNickname($room, $voterToken);
            $base['myResult'] = null;
            // leaderboard plus leaderboardTotal/Me/Around (PublicPayload::withLeaderboard)
            $base = PublicPayload::withLeaderboard($base, null);
            $base['practice'] = $room->getPractice();
        }

        $activeId = $room->getActivePollId();
        if ($activeId === 0) {
            // Lobby: here — and only here — the live participant count for the momentum.
            $base['present'] = $this->roomService->presentCount($room);
            return $base;
        }
        try {
            $poll = $this->pollMapper->find($activeId);
        } catch (DoesNotExistException) {
            $base['present'] = $this->roomService->presentCount($room);
            return $base;
        }

        // "Reveal at the end": individual questions stay hidden — only when the
        // quiz is ended (question marked 'ended') are there results +
        // leaderboard. Otherwise every paused/ended question reveals right away.
        $revealed = $this->isRevealed($room, $poll);

        $hasVoted = false;
        $myValue = null;
        $myVote = null;
        if ($voterToken !== null && $voterToken !== '') {
            try {
                $myVote = $this->voteMapper->findByPollAndToken($poll->getId(), $voterToken);
                $hasVoted = true;
                $myValue = $myVote->getValue();
            } catch (DoesNotExistException) {
                // not voted yet
            }
        }

        $pollData = $this->publicPoll($room, $poll, $revealed);
        if ($quiz && $revealed) {
            // Disclose the right answer(s) only on reveal: choice/truefalse
            // via correctOption, the other types via the answerKey (correct set /
            // target number + tolerance / accepted free-text answers).
            $pollData['correctOption'] = $poll->getCorrectOption();
            $pollData['answerKey'] = $poll->getAnswerKeyArray();
        }
        $base['poll'] = $pollData;
        $base['hasVoted'] = $hasVoted;
        $base['myValue'] = $myValue;
        // Presence belongs in the answer, not in the fingerprint: after submitting, the phone
        // shows "18 of 24 have answered" (§8.5), and the
        // number is fresh because every new vote triggers a full poll
        // anyway. In the fingerprint it would only be there for the audience view —
        // otherwise every heartbeat would drive all voters to a full poll.
        $base['present'] = $this->roomService->presentCount($room);
        // Plain answer count (no distribution) — for the live counter on the
        // projector view; no spoiler, matches the counter in stateVersion.
        $base['answered'] = $this->voteMapper->countByPoll($poll->getId());

        if ($quiz) {
            // Live bars only on reveal — before that the distribution would be a spoiler.
            // Public tallies are capped (PublicPayload::tally); the moderator's stay full.
            $base['results'] = $revealed
                ? PublicPayload::tally($this->tallyService->tally($poll, $this->voteMapper->findByPoll($poll->getId())))
                : null;
            if ($hasVoted) {
                $d = json_decode($myVote->getPayload(), true) ?: [];
                // Before the reveal only confirm "submitted", not yet right/points.
                $base['myResult'] = $revealed
                    ? ['answered' => true, 'correct' => !empty($d['correct']), 'points' => (int)($d['points'] ?? 0)]
                    : ['answered' => true, 'correct' => null, 'points' => null];
            }
            // Practice run: no leaderboard (the individual right/wrong feedback
            // stays, so the questions can be checked while testing).
            // Without questions that are still hidden (e.g. skipped ones) — except at the end:
            // the final standings count everything, just as for the moderator.
            // Top 10 plus the viewer's own row (PublicPayload::withLeaderboard).
            if ($revealed && !$room->getPractice()) {
                $base = PublicPayload::withLeaderboard($base, $this->voteService->leaderboardFor(
                    $room,
                    $voterToken,
                    $poll->getStatus() === 'ended' ? [] : $this->hiddenPollIds($room),
                ));
            }
        } else {
            $base['results'] = PublicPayload::tally($this->tallyService->tally($poll, $this->voteMapper->findByPoll($poll->getId())));
        }

        return $base;
    }

    /**
     * Overall results for participants ("All results"): every question of the
     * deck with its tally, one's own answer and — in a quiz — the reveal.
     * Closes the gap that at the end of a quiz only the LAST question could be seen
     * revealed; the overall view used to exist only in the moderator `summary`.
     *
     * Release rules (the same logic as publicState, just across the whole deck):
     * - Poll: results are live and public anyway -> everything visible.
     * - Quiz "after each question": every question that was paused/ended (locked|ended).
     * - Quiz "Reveal at the end": nothing until `endQuiz` has run (one question
     *   is at 'ended') — then the whole deck.
     *
     * Questions that were never shown (wasShown) are left out entirely — their text
     * would otherwise be a preview of the rest of the deck.
     *
     * `available` tells the client whether there is anything to show at all;
     * questions that are not released come out without results and without a solution.
     *
     * The leaderboard is capped like in publicState (PublicPayload::withLeaderboard).
     *
     * @return array{available:bool, mode:string, practice:bool, title:string, leaderboard:?list<array>, leaderboardTotal:int, leaderboardMe:?array, leaderboardAround:list<array>, items:list<array{poll:array, revealed:bool, results:?array, mine:?array}>}
     */
    public function publicSummary(Room $room, #[\SensitiveParameter] ?string $voterToken): array {
        if (PaceService::isSelf($room)) {
            return $this->paceState->publicSummary($room, $voterToken);
        }
        $quiz = $room->getMode() === 'quiz';
        $deck = $this->pollMapper->findByRoom($room->getId());

        // "Reveal at the end": only after endQuiz is there anything to see at all.
        // The end only counts as long as nothing new is running: if the cursor is on
        // a question, EXACTLY that one must be ended. A leftover
        // 'ended' question from an earlier run would otherwise release every
        // solution from the first question on (setCurrent clears it away too; this is the
        // second safeguard for rooms that were left in that state before).
        $activeId = $room->getActivePollId();
        $endReached = false;
        foreach ($deck as $poll) {
            if ($poll->getStatus() === 'ended' && ($activeId === 0 || $poll->getId() === $activeId)) {
                $endReached = true;
                break;
            }
        }
        $revealAll = !$quiz || ($room->getRevealAtEnd() && $endReached);

        $items = [];
        $anyRevealed = false;
        $hidden = [];
        foreach ($deck as $poll) {
            $revealed = $revealAll
                || (!$room->getRevealAtEnd() && in_array($poll->getStatus(), ['locked', 'ended'], true));
            if (!$revealed) {
                $hidden[] = $poll->getId();
            }
            // Questions never shown do not belong in here — not even as text:
            // otherwise the whole deck would be on every phone in advance (in a quiz including
            // the answer options). The same barrier as for the question image.
            if (!$this->wasShown($room, $poll)) {
                continue;
            }
            $anyRevealed = $anyRevealed || $revealed;

            $pollData = $this->publicPoll($room, $poll, $revealed);
            if ($quiz && $revealed) {
                $pollData['correctOption'] = $poll->getCorrectOption();
                $pollData['answerKey'] = $poll->getAnswerKeyArray();
            }

            $items[] = [
                'poll' => $pollData,
                'revealed' => $revealed,
                'results' => $revealed
                    ? PublicPayload::tally($this->tallyService->tally($poll, $this->voteMapper->findByPoll($poll->getId())))
                    : null,
                'mine' => $this->myAnswer($poll, $voterToken, $quiz, $revealed),
            ];
        }

        // Leaderboard only when there is something to see and it is not a practice run —
        // and without any question that is still hidden (the running one as well as a
        // skipped one): otherwise the score would show whether the answer
        // was right before the question is revealed. At the end everything counts,
        // so that phone, projector and moderator show the same final standings.
        $leaderboard = ($quiz && $anyRevealed && !$room->getPractice())
            ? $this->voteService->leaderboardFor($room, $voterToken, $endReached ? [] : $hidden)
            : null;

        // Top 10 plus the viewer's own row, like publicState.
        return PublicPayload::withLeaderboard([
            'available' => $anyRevealed,
            'mode' => $room->getMode(),
            'practice' => $room->getPractice(),
            'title' => $room->titleOrEmpty(),
            'leaderboard' => null,
            'items' => $items,
        ], $leaderboard);
    }

    /**
     * Has this question already been shown to the audience? It is running right now, has
     * a start time (setCurrent sets it in both modes) or it has been
     * revealed. Polls only set the start time since v0.18.x; their
     * questions shown earlier are recognised by their votes (voting is only possible
     * on the running question). In a quiz that is not enough as evidence — there
     * setCurrent has always set the time.
     */
    private function wasShown(Room $room, Poll $poll): bool {
        return $poll->getId() === $room->getActivePollId()
            || $poll->getStartedAt() > 0
            || in_array($poll->getStatus(), ['locked', 'ended'], true)
            || ($room->getMode() !== 'quiz' && $this->voteMapper->countByPoll($poll->getId()) > 0);
    }

    /**
     * Question for the public view, shuffled spoiler-free before the reveal
     * (rules and reasoning: PublicView::poll).
     */
    private function publicPoll(Room $room, Poll $poll, bool $revealed): array {
        return PublicView::poll($room, $poll, $revealed, $this->config->getSystemValueString('secret', ''));
    }

    /**
     * One's own answer to a question — right/points only once the question
     * has been revealed (otherwise the feedback would give away the solution).
     *
     * @return ?array{value:mixed, correct:?bool, points:?int}
     */
    private function myAnswer(Poll $poll, #[\SensitiveParameter] ?string $voterToken, bool $quiz, bool $revealed): ?array {
        if ($voterToken === null || $voterToken === '') {
            return null;
        }
        try {
            $vote = $this->voteMapper->findByPollAndToken($poll->getId(), $voterToken);
        } catch (DoesNotExistException) {
            return null;
        }
        $d = json_decode($vote->getPayload(), true) ?: [];
        return [
            'value' => $vote->getValue(),
            'correct' => ($quiz && $revealed) ? !empty($d['correct']) : null,
            'points' => ($quiz && $revealed) ? (int)($d['points'] ?? 0) : null,
        ];
    }
}
