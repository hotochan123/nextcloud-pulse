<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\Poll;
use OCP\IL10N;

/**
 * The per-type answer rules: check and normalise an incoming vote value
 * against the question type (normalizeValue), then grade a quiz answer
 * (quizPayload, the free-text verdict). No DB access, no clock — it is handed
 * the Poll, so tests build it directly. The counting side of the same
 * per-type knowledge is TallyService.
 *
 * The public endpoint feeds it whatever a client sends. The checks of each
 * type run in a fixed order, and the first one that fails decides which
 * message the phone shows.
 */
class AnswerRules {
    /** Word cloud: a single raw word is cut to this many characters before it is cleaned. */
    private const WORD_RAW_MAX = 200;
    /** Word cloud: more list entries than this (or 2 × maxWords) are not a vote. */
    private const WORD_ITEMS_MIN = 20;

    public function __construct(
        private IL10N $l10n,
        private QuizService $quizService,
    ) {
    }

    /**
     * Checks and normalises the incoming vote value against the question type.
     *
     * @return string|list<string> optionId (choice) or words (words)
     * @throws \InvalidArgumentException
     */
    public function normalizeValue(Poll $poll, #[\SensitiveParameter] mixed $value): mixed {
        return match ($poll->getType()) {
            'choice', 'truefalse' => $this->choice($poll, $value),
            'multi' => $this->multi($poll, $value),
            'rank' => $this->rank($poll, $value),
            'match' => $this->matching($poll, $value),
            'number' => $this->number($poll, $value),
            'text' => $this->text($poll, $value),
            'scale' => $this->scale($poll, $value),
            default => $this->words($poll, $value), // words, and any unknown type
        };
    }

    /** choice + true/false: one valid option ID. */
    private function choice(Poll $poll, #[\SensitiveParameter] mixed $value): string {
        if (!is_string($value)) {
            throw new \InvalidArgumentException($this->l10n->t('Invalid selection.'));
        }
        $validIds = array_column($poll->getOptionsArray(), 'id');
        if (!in_array($value, $validIds, true)) {
            throw new \InvalidArgumentException($this->l10n->t('No such option.'));
        }
        return $value;
    }

    /** Multiple choice: subset of valid IDs, deduplicated + sorted. */
    private function multi(Poll $poll, #[\SensitiveParameter] mixed $value): array {
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

    /**
     * Ranking: a complete permutation of the option IDs. Incomplete or
     * with duplicates is not a ranking — better reject than guess.
     */
    private function rank(Poll $poll, #[\SensitiveParameter] mixed $value): array {
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

    /**
     * Matching (type 'match'): exactly one target per item. Targets may occur
     * several times (in a poll that is a statement, not a mistake), but no item
     * may be left open — a half assignment could not be evaluated.
     */
    private function matching(Poll $poll, #[\SensitiveParameter] mixed $value): array {
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

    /**
     * Estimate question: a finite number (int or float). "1e999" and JSON 1e999
     * become INF in PHP — not an estimate, and json_encode fails on it.
     */
    private function number(Poll $poll, #[\SensitiveParameter] mixed $value): int|float {
        $n = Input::number($value);
        if ($n === null) {
            throw new \InvalidArgumentException($this->l10n->t('Please enter a number.'));
        }
        return $n;
    }

    /**
     * Free text: trimmed, whitespace normalised, truncated (stored raw).
     * The raw input is cut to four times the length before the regex (clip).
     */
    private function text(Poll $poll, #[\SensitiveParameter] mixed $value): string {
        if (!is_string($value)) {
            throw new \InvalidArgumentException($this->l10n->t('Invalid answer.'));
        }
        $t = trim(preg_replace('/\s+/u', ' ', Input::clip($value, 4 * DeckService::TEXT_MAX)) ?? '');
        if ($t === '') {
            throw new \InvalidArgumentException($this->l10n->t('Please enter an answer.'));
        }
        return mb_substr($t, 0, DeckService::TEXT_MAX);
    }

    /** Scale: one value (single), one value per aspect (spectrum) or one point (compass). */
    private function scale(Poll $poll, #[\SensitiveParameter] mixed $value): int|array {
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

    /**
     * Word cloud — and any type not listed in normalizeValue: up to maxWords
     * distinct words, each in its first spelling.
     */
    private function words(Poll $poll, #[\SensitiveParameter] mixed $value): array {
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
            $word = TallyService::cleanText(Input::clip((string)$raw, self::WORD_RAW_MAX));
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
    public function textRejected(Poll $poll, string $norm): bool {
        foreach (($poll->getAnswerKeyArray()['rejected'] ?? []) as $r) {
            if (TallyService::normalizeText((string)$r) === $norm) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verdict on a free-text normalised form according to the current key, as
     * VoteService::selfPayload stores it.
     *
     * @return array{0: bool, 1: bool} [correct, being checked]
     */
    public function textVerdict(Poll $poll, string $norm): array {
        $correct = $this->textAccepted($poll, $norm);
        return [$correct, !$correct && !$this->textRejected($poll, $norm)];
    }
}
