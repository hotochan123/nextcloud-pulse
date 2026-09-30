<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Db\VoteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * A room's deck: create, edit, sort and delete questions, set the
 * cursor and build the owner's view of it. Contains the complete
 * input validation per question type (applyPollData + build* helpers) — new
 * question types and new composer fields go here.
 * Raw client values go through Input: lists, objects and non-numbers count as
 * not sent.
 */
class DeckService {
    private const TYPES_POLL = ['choice', 'words', 'scale', 'rank', 'match'];
    private const TYPES_QUIZ = ['choice', 'truefalse', 'multi', 'number', 'text', 'rank', 'match'];
    /** Free text: upper limit for one answer (characters). AnswerRules (the vote) and VoteService (grading) truncate to it as well. */
    public const TEXT_MAX = 100;
    private const MIN_OPTIONS = 2;
    private const MAX_OPTIONS = 8;
    /**
     * Characters of one answer option (choice, multi, rank). Longer labels
     * are cut, like every other label here — a sentence of an answer fits
     * easily, a megabyte of text in the options column does not get in.
     */
    public const OPTION_MAX = 200;
    /**
     * Free text: accepted answers stored with the question. The composer
     * offers eight; grading can add more, and editing the question sends
     * them back — the rest beyond this is dropped.
     */
    public const ACCEPTED_MAX = 50;
    /** Time limit of a quiz question in seconds (lower/upper bound). */
    private const QUIZ_MIN_LIMIT = 5;
    private const QUIZ_MAX_LIMIT = 300;

    public function __construct(
        private RoomMapper $roomMapper,
        private PollMapper $pollMapper,
        private VoteMapper $voteMapper,
        private CodeGenerator $codeGenerator,
        private PollImageService $imageService,
        private ITimeFactory $timeFactory,
        private IL10N $l10n,
        private Limits $limits,
    ) {
    }

    // ── Polls ─────────────────────────────────────────────────────────────────

    /**
     * Append a question to the end of the deck. Does NOT make it active — the presenter
     * navigates via setCurrent(). That way a deck can be built in advance.
     *
     * @param array{type?:string, question?:string, options?:array, maxWords?:int} $data
     * @throws \InvalidArgumentException on invalid input, or when the room
     *                                   already holds Limits::POLLS_PER_ROOM questions
     */
    public function addPoll(Room $room, array $data): Poll {
        $max = $this->limits->get(Limits::POLLS_PER_ROOM);
        if ($this->pollMapper->countByRoom($room->getId()) >= $max) {
            throw $this->tooManyPolls($max);
        }
        $poll = new Poll();
        $poll->setRoomId($room->getId());
        $poll->setStatus('active');
        $poll->setPosition($this->pollMapper->nextPosition($room->getId()));
        $poll->setCreatedAt($this->timeFactory->getTime());
        $this->applyPollData($poll, $data, $room->getMode() === 'quiz');
        $poll = $this->pollMapper->insert($poll);
        // Counted again: parallel requests all passed the check above before
        // any of them had written. Over the cap, this one takes its question back.
        if ($this->pollMapper->countByRoom($room->getId()) > $max) {
            $this->pollMapper->delete($poll);
            throw $this->tooManyPolls($max);
        }
        return $poll;
    }

    private function tooManyPolls(int $max): \InvalidArgumentException {
        return new \InvalidArgumentException($this->l10n->t('A room can hold at most %d questions.', [$max]));
    }

    /**
     * Edit an existing question. Since choice options get new IDs, the
     * existing votes of this question are discarded (otherwise they would point nowhere).
     *
     * @throws PollNotFoundException the question is missing or belongs to another room
     * @throws \InvalidArgumentException invalid input
     */
    public function updatePoll(Room $room, int $pollId, array $data): Poll {
        $poll = $this->requirePollInRoom($room, $pollId);
        $this->applyPollData($poll, $data, $room->getMode() === 'quiz');
        // New content, votes gone — then the old progress state no longer
        // applies either. If 'locked'/startedAt stayed, the edited question
        // and its solution would show up as "revealed" in the overall summary at once.
        // Exceptions, so that fixing a typo does not upset anything in the room:
        //  · 'ended' stays — the quiz stays over, the room stays on the final standings.
        //    (The votes of this question are lost as with every edit,
        //    and so are their points in the final standings.)
        //  · the running question stays as it is; only a running
        //    quiz timer restarts (the votes are gone, after all).
        $current = $room->getActivePollId() === $poll->getId();
        if (!$current && $poll->getStatus() !== 'ended') {
            $poll->setStatus('active');
            $poll->setStartedAt(0);
        } elseif ($current && $room->getMode() === 'quiz' && $poll->getStatus() === 'active') {
            $poll->setStartedAt($this->timeFactory->getTime());
        }
        $poll = $this->pollMapper->update($poll);
        $this->voteMapper->deleteByPoll($poll->getId());
        return $poll;
    }

    /**
     * Reorder the deck: position = index in the given ID list.
     * All IDs must belong to the room (otherwise aborting before any change would be nicer,
     * but requirePollInRoom throws per foreign ID -> the caller sends the real deck).
     *
     * @param array<mixed> $pollIds
     * @throws PollNotFoundException a missing or foreign ID
     * @throws \InvalidArgumentException a non-number in the deck
     */
    public function reorder(Room $room, array $pollIds): void {
        // Check all first, then write -> no partial change on a foreign ID.
        // array_values: if a JSON object arrives instead of the list, the keys would be
        // text, and setPosition(int, int) would break with a TypeError (500).
        $ids = [];
        foreach (array_values($pollIds) as $raw) {
            $id = Input::int($raw);
            if ($id === null) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid order.'));
            }
            $ids[] = $id;
        }
        foreach ($ids as $id) {
            $this->requirePollInRoom($room, $id);
        }
        foreach ($ids as $pos => $id) {
            $this->pollMapper->setPosition($id, $pos);
        }
    }

    /**
     * Validates type/question/options and writes them into the entity (without persisting).
     *
     * @throws \InvalidArgumentException
     */
    private function applyPollData(Poll $poll, array $data, bool $quiz = false): void {
        $type = Input::str($data['type'] ?? null);
        $allowed = $quiz ? self::TYPES_QUIZ : self::TYPES_POLL;
        if (!in_array($type, $allowed, true)) {
            throw new \InvalidArgumentException($quiz ? $this->l10n->t('Unknown quiz question type.') : $this->l10n->t('Unknown question type.'));
        }
        $question = trim(Input::str($data['question'] ?? null));
        if ($question === '') {
            throw new \InvalidArgumentException($this->l10n->t('The question must not be empty.'));
        }
        // Rejected rather than cut: the composer keeps the text open, and a
        // question silently losing its end would change what it asks.
        $maxLength = $this->limits->get(Limits::QUESTION_LENGTH);
        if (mb_strlen($question) > $maxLength) {
            throw new \InvalidArgumentException($this->l10n->t('The question can be at most %d characters long.', [$maxLength]));
        }
        $poll->setType($type);
        $poll->setQuestion($question);
        // Reset the type fields first, then set them per type.
        $poll->setCorrectOption('');
        $poll->setAnswerKey(null);
        $poll->setMaxWords(0);
        $poll->setOptions('[]');
        $poll->setTimeLimit(0);

        switch ($type) {
            case 'choice':
                $options = $this->buildOptions($data['options'] ?? []);
                $poll->setOptions(json_encode($options));
                if ($quiz) {
                    $idx = Input::int($data['correctIndex'] ?? null);
                    if ($idx === null || !isset($options[$idx])) {
                        throw new \InvalidArgumentException($this->l10n->t('Please mark the correct answer.'));
                    }
                    $poll->setCorrectOption($options[$idx]['id']);
                }
                break;
            case 'truefalse':
                // The labels are stored as well — they are in the language
                // in which the question was created.
                $options = $this->buildOptions([$this->l10n->t('True'), $this->l10n->t('False')]);
                $poll->setOptions(json_encode($options));
                $idx = Input::int($data['correctIndex'] ?? null);
                if ($idx !== 0 && $idx !== 1) {
                    throw new \InvalidArgumentException($this->l10n->t('Please mark either True or False as correct.'));
                }
                $poll->setCorrectOption($options[$idx]['id']);
                break;
            case 'multi':
                $options = $this->buildOptions($data['options'] ?? []);
                $poll->setOptions(json_encode($options));
                $correct = $this->pickCorrectIds($options, $data['correctIndexes'] ?? []);
                $poll->setAnswerKey(json_encode(['correct' => $correct]));
                break;
            case 'rank':
                // Ordering: the same options as for choice — in the quiz the input
                // order in the composer IS the correct solution, so no extra
                // field is needed. A poll has no solution; there only the
                // audience's order counts.
                $options = $this->buildOptions($data['options'] ?? []);
                $poll->setOptions(json_encode($options));
                if ($quiz) {
                    $poll->setAnswerKey(json_encode(['order' => array_column($options, 'id')]));
                }
                break;
            case 'match':
                // Matching: the pairs from the composer are split into two lists
                // (items on the left, targets on the right). In the quiz the pairing
                // is the solution; in a poll it only shows how the audience
                // matches — no answerKey is stored there.
                [$config, $map] = $this->buildMatch($data['pairs'] ?? []);
                $poll->setOptions(json_encode($config));
                if ($quiz) {
                    $poll->setAnswerKey(json_encode(['map' => $map]));
                }
                break;
            case 'number':
                [$target, $tol] = $this->buildNumberKey($data);
                $poll->setAnswerKey(json_encode(['target' => $target, 'tolerance' => $tol]));
                break;
            case 'text':
                $accepted = $this->buildAcceptedAnswers($data['answers'] ?? []);
                $poll->setAnswerKey(json_encode(['accepted' => $accepted, 'rejected' => []]));
                break;
            case 'scale':
                $poll->setOptions(json_encode($this->buildScale($data)));
                break;
            case 'words':
                $poll->setMaxWords(max(1, min(5, Input::int($data['maxWords'] ?? null) ?? 3)));
                break;
        }

        if ($quiz) {
            $limit = Input::int($data['timeLimit'] ?? null) ?? 30;
            $poll->setTimeLimit(max(self::QUIZ_MIN_LIMIT, min(self::QUIZ_MAX_LIMIT, $limit)));
        }
    }

    /**
     * Multi: take the matching option IDs from a list of option indexes
     * (at least one, all within the valid range).
     *
     * @param list<array{id:string,label:string}> $options
     * @return list<string>
     * @throws \InvalidArgumentException
     */
    private function pickCorrectIds(array $options, mixed $rawIndexes): array {
        if (!is_array($rawIndexes)) {
            $rawIndexes = [];
        }
        $ids = [];
        foreach ($rawIndexes as $raw) {
            $i = Input::int($raw);
            if ($i !== null && isset($options[$i])) {
                $ids[$options[$i]['id']] = true;
            }
        }
        $ids = array_keys($ids);
        if (count($ids) === 0) {
            throw new \InvalidArgumentException($this->l10n->t('Please mark at least one correct answer.'));
        }
        sort($ids);
        return $ids;
    }

    /**
     * Estimation question: target number (required) + tolerance (≥ 0). Both stored as numbers.
     *
     * @return array{0: int|float, 1: int|float}
     * @throws \InvalidArgumentException
     */
    private function buildNumberKey(array $data): array {
        // Finite: "1e999" would be INF, and json_encode fails on that.
        $target = Input::number($data['target'] ?? null);
        if ($target === null) {
            throw new \InvalidArgumentException($this->l10n->t('Please enter a target number.'));
        }
        $tol = Input::number($data['tolerance'] ?? null);
        if ($tol === null || $tol < 0) {
            $tol = 0;
        }
        return [$target, abs($tol)];
    }

    /**
     * Free text: the accepted answers given by the author. Stored raw
     * (for a nice display), deduplicated via the normal form. At least one.
     *
     * @return list<string>
     * @throws \InvalidArgumentException
     */
    private function buildAcceptedAnswers(mixed $raw): array {
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            $raw = [];
        }
        $seen = [];
        $out = [];
        foreach ($raw as $entry) {
            $val = trim(Input::str($entry));
            if ($val === '') {
                continue;
            }
            $val = mb_substr($val, 0, self::TEXT_MAX);
            $norm = TallyService::normalizeText($val);
            if (isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;
            $out[] = $val;
            if (count($out) >= self::ACCEPTED_MAX) {
                break;
            }
        }
        if (count($out) === 0) {
            throw new \InvalidArgumentException($this->l10n->t('Please provide at least one correct answer.'));
        }
        return $out;
    }

    /**
     * Scale config. Mode 'single' (min fixed at 1, scaleMax 2–20, histogram) or
     * 'spectrum' (min fixed at 0, 3–8 aspects each 0..X, radar).
     *
     * @return array
     */
    private function buildScale(array $data): array {
        $max = Input::int($data['scaleMax'] ?? null) ?? 5;
        $max = max(2, min(20, $max));
        $mode = $data['scaleMode'] ?? 'single';
        if (!in_array($mode, ['single', 'spectrum', 'compass'], true)) {
            $mode = 'single';
        }
        if ($mode === 'spectrum') {
            return [
                'mode' => 'spectrum',
                'min' => 0,
                'max' => $max,
                'aspects' => $this->buildAspects($data['aspects'] ?? []),
            ];
        }
        if ($mode === 'compass') {
            $range = max(2, min(20, Input::int($data['range'] ?? null) ?? 5));
            return [
                'mode' => 'compass',
                'range' => $range,
                'axisX' => $this->buildAxis($data['axisX'] ?? []),
                'axisY' => $this->buildAxis($data['axisY'] ?? []),
                'cornerLabels' => $this->buildCorners($data['cornerLabels'] ?? []),
                'heatmapThreshold' => max(2, min(9999, Input::int($data['heatmapThreshold'] ?? null) ?? 45)),
            ];
        }
        return [
            'mode' => 'single',
            'min' => 1,
            'max' => $max,
            'minLabel' => mb_substr(trim(Input::str($data['minLabel'] ?? null)), 0, 40),
            'maxLabel' => mb_substr(trim(Input::str($data['maxLabel'] ?? null)), 0, 40),
        ];
    }

    /**
     * Spectrum aspects: 3–8 entries, each with a stable ID, a name (required) and
     * optional pole labels. Empty names are dropped; > 8 is capped.
     *
     * @return list<array{id:string,label:string,poleLow:string,poleHigh:string}>
     * @throws \InvalidArgumentException
     */
    private function buildAspects(mixed $raw): array {
        if (!is_array($raw)) {
            $raw = [];
        }
        $out = [];
        foreach ($raw as $a) {
            if (!is_array($a)) {
                continue;
            }
            $label = mb_substr(trim(Input::str($a['label'] ?? null)), 0, 40);
            if ($label === '') {
                continue;
            }
            $out[] = [
                'id' => $this->codeGenerator->optionId(),
                'label' => $label,
                'poleLow' => mb_substr(trim(Input::str($a['poleLow'] ?? null)), 0, 40),
                'poleHigh' => mb_substr(trim(Input::str($a['poleHigh'] ?? null)), 0, 40),
            ];
            if (count($out) >= 8) {
                break;
            }
        }
        if (count($out) < 3) {
            throw new \InvalidArgumentException($this->l10n->t('Please name at least three aspects.'));
        }
        return $out;
    }

    /**
     * Compass axis: title + two pole labels, all three required.
     *
     * @return array{title:string,poleLow:string,poleHigh:string}
     * @throws \InvalidArgumentException
     */
    private function buildAxis(mixed $a): array {
        $a = is_array($a) ? $a : [];
        $title = mb_substr(trim(Input::str($a['title'] ?? null)), 0, 40);
        $lo = mb_substr(trim(Input::str($a['poleLow'] ?? null)), 0, 40);
        $hi = mb_substr(trim(Input::str($a['poleHigh'] ?? null)), 0, 40);
        if ($title === '' || $lo === '' || $hi === '') {
            throw new \InvalidArgumentException($this->l10n->t('Please give both axes a title and two pole labels each.'));
        }
        return ['title' => $title, 'poleLow' => $lo, 'poleHigh' => $hi];
    }

    /**
     * Compass corner labels: up to four, optional. Order
     * [bottom-left, bottom-right, top-left, top-right].
     *
     * @return list<string>
     */
    private function buildCorners(mixed $raw): array {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $c) {
            $out[] = mb_substr(trim(Input::str($c)), 0, 40);
            if (count($out) >= 4) {
                break;
            }
        }
        return $out;
    }

    /**
     * Set the cursor: which question is on right now (0 = none / presentation at rest).
     * Only the current question accepts votes (recordVote resolves via active_poll_id).
     *
     * @throws PollNotFoundException the question is missing or belongs to another room
     */
    public function setCurrent(Room $room, int $pollId): void {
        if ($pollId !== 0) {
            $poll = $this->requirePollInRoom($room, $pollId);
            if ($room->getMode() === 'quiz') {
                // (Re)start the timer and open the question — so even when jumping back
                // to a question that was already revealed, it runs again.
                $poll->setStartedAt($this->timeFactory->getTime());
                $poll->setStatus('active');
                $this->pollMapper->update($poll);
                // A new run (or a step back after the final standings)
                // ends the end: a leftover 'ended' question would otherwise
                // still mean "quiz over" — with "Reveal at the end" the
                // overall summary would then give away every solution from question 1 on.
                $this->pollMapper->unmarkEnded($room->getId(), $poll->getId());
            } elseif ($poll->getStartedAt() === 0) {
                // Poll: no timer, but the timestamp marks "has been shown".
                // The overall summary for the phone only includes questions that were shown
                // (StateService::wasShown) — otherwise the whole deck would be in it in advance.
                $poll->setStartedAt($this->timeFactory->getTime());
                $this->pollMapper->update($poll);
            }
        }
        $room->setActivePollId($pollId);
        $this->roomMapper->update($room);
    }

    public function deletePoll(Room $room, int $pollId): void {
        $poll = $this->requirePollInRoom($room, $pollId);
        $this->voteMapper->deleteByPoll($poll->getId());
        $this->imageService->discard($poll);
        $this->pollMapper->delete($poll);
        if ($room->getActivePollId() === $pollId) {
            $room->setActivePollId(0);
            $this->roomMapper->update($room);
        }
    }

    /** @return Poll[] ordered deck */
    public function deck(Room $room): array {
        return $this->pollMapper->findByRoom($room->getId());
    }

    /**
     * Question including the correct answer — only for owner views (deck editor,
     * presentation, summary). Participants only get correctOption
     * at the reveal (see publicState).
     */
    public function ownerPoll(Poll $poll): array {
        $data = $poll->jsonSerialize();
        $data['correctOption'] = $poll->getCorrectOption();
        $data['answerKey'] = $poll->getAnswerKeyArray();
        return $data;
    }

    /**
     * Room including its deck as a serialisable array (for GET /rooms/{code}). In
     * self-paced mode including the window (state, deadline, question count), otherwise
     * `window: null`.
     */
    public function roomView(Room $room): array {
        $polls = $this->deck($room);
        $data = $room->jsonSerialize();
        $data['window'] = PaceService::isSelf($room)
            ? PaceService::windowView($room, $this->timeFactory->getTime(), count($polls))
            : null;
        $data['polls'] = array_map(
            fn (Poll $p) => $this->ownerPoll($p),
            $polls,
        );
        return $data;
    }

    public function resetPoll(Room $room, int $pollId): void {
        $poll = $this->requirePollInRoom($room, $pollId);
        $this->voteMapper->deleteByPoll($poll->getId());
    }

    /** Pause voting (no new votes), results are kept. */
    public function lockPoll(Room $room, int $pollId): void {
        $poll = $this->requirePollInRoom($room, $pollId);
        if ($poll->getStatus() === 'active') {
            $this->pollMapper->setStatus($poll->getId(), 'locked');
        }
    }

    public function unlockPoll(Room $room, int $pollId): void {
        $poll = $this->requirePollInRoom($room, $pollId);
        if ($poll->getStatus() === 'locked') {
            $this->pollMapper->setStatus($poll->getId(), 'active');
        }
    }

    // ── internal ────────────────────────────────────────────────────────────

    /** @throws PollNotFoundException */
    public function requirePollInRoom(Room $room, int $pollId): Poll {
        try {
            $poll = $this->pollMapper->find($pollId);
        } catch (DoesNotExistException) {
            throw new PollNotFoundException($this->l10n->t('Question not found.'));
        }
        if ($poll->getRoomId() !== $room->getId()) {
            throw new PollNotFoundException($this->l10n->t('This question does not belong to this room.'));
        }
        return $poll;
    }

    /**
     * Matching: build the two lists from the composer's pairs [{left, right}, …].
     * Both sides get their own IDs; the solution is the
     * mapping item ID -> target ID. Identical target labels are NOT
     * merged: two items may point to the same target, in which case the
     * target simply appears twice in the choice — otherwise the shorter list
     * would give the duplicate away.
     *
     * @param mixed $raw list of {left, right}
     * @return array{0: array{items:list<array{id:string,label:string}>, targets:list<array{id:string,label:string}>}, 1: array<string,string>}
     * @throws \InvalidArgumentException
     */
    private function buildMatch(mixed $raw): array {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException($this->l10n->t('Please provide at least two pairs.'));
        }
        $pairs = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $left = trim(Input::str($entry['left'] ?? null));
            $right = trim(Input::str($entry['right'] ?? null));
            // Half-filled rows are a trap: "left without right" could not be
            // matched, "right without left" would be a target without a purpose.
            if ($left === '' xor $right === '') {
                throw new \InvalidArgumentException($this->l10n->t('Please fill in both sides of every pair.'));
            }
            if ($left !== '') {
                $pairs[] = [mb_substr($left, 0, self::TEXT_MAX), mb_substr($right, 0, self::TEXT_MAX)];
            }
        }
        if (count($pairs) < self::MIN_OPTIONS || count($pairs) > self::MAX_OPTIONS) {
            throw new \InvalidArgumentException($this->l10n->t('Please provide between %1$d and %2$d pairs.', [self::MIN_OPTIONS, self::MAX_OPTIONS]));
        }
        $items = [];
        $targets = [];
        $map = [];
        foreach ($pairs as [$left, $right]) {
            $itemId = $this->codeGenerator->optionId();
            $targetId = $this->codeGenerator->optionId();
            $items[] = ['id' => $itemId, 'label' => $left];
            $targets[] = ['id' => $targetId, 'label' => $right];
            $map[$itemId] = $targetId;
        }
        return [['items' => $items, 'targets' => $targets], $map];
    }

    /**
     * @param mixed $raw list of labels (strings)
     * @return list<array{id:string,label:string}>
     */
    private function buildOptions(mixed $raw): array {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException($this->l10n->t('Options are missing.'));
        }
        $labels = [];
        foreach ($raw as $entry) {
            // accepts both ["A","B"] and [{"label":"A"}, …]
            $label = trim(Input::str(is_array($entry) ? ($entry['label'] ?? null) : $entry));
            if ($label !== '') {
                $labels[] = mb_substr($label, 0, self::OPTION_MAX);
            }
        }
        if (count($labels) < self::MIN_OPTIONS || count($labels) > self::MAX_OPTIONS) {
            throw new \InvalidArgumentException($this->l10n->t('Please provide between %1$d and %2$d options.', [self::MIN_OPTIONS, self::MAX_OPTIONS]));
        }
        $options = [];
        foreach ($labels as $label) {
            $options[] = ['id' => $this->codeGenerator->optionId(), 'label' => $label];
        }
        return $options;
    }
}
