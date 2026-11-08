<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\PulseFakeAi;

use OCP\TaskProcessing\ISynchronousProvider;

/**
 * Canned text generation for Pulse's browser and integration tests.
 *
 * The AI quiz draft (the prompt carries Pulse's material markers); the material
 * decides the answer:
 * - contains "BROKEN": prose, no JSON (Pulse must say the answer was unusable);
 * - contains "FAILME": the task fails;
 * - anything else: six questions (four single, two multiple choice) about
 *   the material's first line, tagged with a fresh draft number so that
 *   "Try again" shows a different draft.
 *
 * The AI check of answers (Pulse's ModerationService::prompt, the numbered
 * lines under "Answers:"): every answer that contains "badword" is flagged;
 * when one contains "slowpoke" the reply waits 15 seconds (past Pulse's
 * 10-second hold-back, so the answers go through unchecked).
 *
 * "Group with AI" (Pulse's ThemeService::prompt, the numbered lines under
 * "Answers to group:"): one theme per first letter, titled "Theme <letter>",
 * at most six; an answer containing "failgroup" makes the task fail, one
 * containing "longtitle" makes every title longer than 60 characters.
 *
 * The AI grading suggestion (Pulse's GradingSuggestionService::prompt, the
 * numbered lines under "Answers to grade:"): an answer is correct when it starts
 * with the first four letters of an expected answer (case ignored); an answer
 * containing "failgrade" makes the task fail.
 *
 * The AI summary (Pulse's AiSummaryService::prompt, between the results
 * markers): three key points per question, the last one "Seen: …" with the
 * answers that went out.
 */
class FakeProvider implements ISynchronousProvider {
    public function getId(): string {
        return 'pulse_fakeai';
    }

    public function getName(): string {
        return 'Pulse fake AI';
    }

    public function getTaskTypeId(): string {
        return 'core:text2text';
    }

    public function getExpectedRuntime(): int {
        return 1;
    }

    public function getOptionalInputShape(): array {
        return [];
    }

    public function getOptionalOutputShape(): array {
        return [];
    }

    public function getInputShapeEnumValues(): array {
        return [];
    }

    public function getInputShapeDefaults(): array {
        return [];
    }

    public function getOptionalInputShapeEnumValues(): array {
        return [];
    }

    public function getOptionalInputShapeDefaults(): array {
        return [];
    }

    public function getOutputShapeEnumValues(): array {
        return [];
    }

    public function getOptionalOutputShapeEnumValues(): array {
        return [];
    }

    public function process(?string $userId, array $input, callable $reportProgress): array {
        $prompt = (string)($input['input'] ?? '');
        if (preg_match('/<<<BEGIN RESULTS>>>\n(.*)<<<END RESULTS>>>/s', $prompt, $m) === 1) {
            return $this->summary($m[1]);
        }
        if (preg_match('/<<<BEGIN MATERIAL>>>\n(.*)\n<<<END MATERIAL>>>/s', $prompt, $m) === 1) {
            return $this->draft($m[1]);
        }
        $grade = strpos($prompt, "\nAnswers to grade:\n");
        if ($grade !== false) {
            return $this->grade($prompt, substr($prompt, $grade + 19));
        }
        $group = strpos($prompt, "\nAnswers to group:\n");
        if ($group !== false) {
            return $this->group(substr($prompt, $group + 19));
        }
        $at = strpos($prompt, "\nAnswers:\n");
        if ($at === false) {
            return ['output' => '{}'];
        }
        $flagged = [];
        $slow = false;
        foreach (explode("\n", substr($prompt, $at + 10)) as $line) {
            if (preg_match('/^(\d+)\. (.*)$/', $line, $m) !== 1) {
                continue;
            }
            $text = strtolower((string)json_decode($m[2]));
            if (str_contains($text, 'badword')) {
                $flagged[] = (int)$m[1];
            }
            $slow = $slow || str_contains($text, 'slowpoke');
        }
        if ($slow) {
            sleep(15);
        }
        return ['output' => json_encode(['flagged' => $flagged])];
    }

    /**
     * "Group with AI": one theme per first letter of the answers ("Theme T"),
     * at most six; an answer "failgroup" makes the task fail.
     */
    private function group(string $lines): array {
        $themes = [];
        $long = false;
        foreach (explode("\n", $lines) as $line) {
            if (preg_match('/^(\d+)\. (.*)$/', $line, $m) !== 1) {
                continue;
            }
            $text = (string)json_decode($m[2]);
            if (str_contains(strtolower($text), 'failgroup')) {
                throw new \RuntimeException('fake provider: asked to fail the grouping');
            }
            $long = $long || str_contains(strtolower($text), 'longtitle');
            $letter = mb_strtoupper(mb_substr($text, 0, 1));
            if (!isset($themes[$letter]) && count($themes) >= 6) {
                continue;
            }
            $themes[$letter][] = (int)$m[1];
        }
        $out = [];
        foreach ($themes as $letter => $answers) {
            // "longtitle" in any answer: titles past Pulse's 60-character cut, for the layout checks
            $title = 'Theme ' . $letter . ($long ? ' ' . str_repeat('with a very long title ', 5) : '');
            $out[] = ['title' => $title, 'answers' => $answers];
        }
        return ['output' => json_encode(['themes' => $out], JSON_UNESCAPED_UNICODE)];
    }

    /**
     * AI grading suggestion: correct when the answer starts with the first four
     * letters of an expected answer; "failgrade" makes the task fail.
     */
    private function grade(string $prompt, string $lines): array {
        $prefixes = [];
        if (preg_match('/\nExpected answers:\n(.*?)\n\n/s', $prompt, $m) === 1) {
            foreach (explode("\n", $m[1]) as $line) {
                if (str_starts_with($line, '- "')) {
                    $prefixes[] = mb_strtolower(mb_substr((string)json_decode(substr($line, 2)), 0, 4));
                }
            }
        }
        $correct = [];
        $wrong = [];
        foreach (explode("\n", $lines) as $line) {
            if (preg_match('/^(\d+)\. (.*)$/', $line, $m) !== 1) {
                continue;
            }
            $text = mb_strtolower((string)json_decode($m[2]));
            if (str_contains($text, 'failgrade')) {
                throw new \RuntimeException('fake provider: asked to fail the grading suggestion');
            }
            $right = false;
            foreach ($prefixes as $p) {
                $right = $right || ($p !== '' && str_starts_with($text, $p));
            }
            if ($right) {
                $correct[] = (int)$m[1];
            } else {
                $wrong[] = (int)$m[1];
            }
        }
        return ['output' => json_encode(['correct' => $correct, 'wrong' => $wrong])];
    }

    /** The quiz draft for the material between Pulse's markers. */
    private function draft(string $material): array {
        if (str_contains($material, 'FAILME')) {
            throw new \RuntimeException('fake provider: asked to fail');
        }
        if (str_contains($material, 'BROKEN')) {
            return ['output' => 'Sorry, I would rather write a poem about that.'];
        }
        $topic = trim(strtok($material, "\n") ?: 'Topic');
        $draft = random_int(1000, 9999);
        $q = [];
        for ($i = 1; $i <= 6; $i++) {
            $multi = $i > 4;
            $q[] = [
                'type' => $multi ? 'multi' : 'choice',
                'question' => "{$topic} – question {$i} (draft {$draft})",
                'options' => ["Answer {$i}A", "Answer {$i}B", "Answer {$i}C", "Answer {$i}D"],
                'correct' => $multi ? [0, 2] : [($i - 1) % 4],
            ];
        }
        return ['output' => "```json\n" . json_encode(['questions' => $q], JSON_UNESCAPED_UNICODE) . "\n```"];
    }

    /**
     * The summary's key points: per question three points, the last one
     * echoing the answers it got (so a test sees what went out), tagged with a
     * fresh run number so that "Try again" gives a different text.
     */
    private function summary(string $material): array {
        $run = random_int(1000, 9999);
        $out = [];
        $n = 0;
        $seen = [];
        foreach (explode("\n", $material) as $line) {
            if (preg_match('/^Question (\d+): /', $line, $m) === 1) {
                if ($n > 0) {
                    $out[] = ['n' => $n, 'points' => ["Point A of question {$n} (run {$run})", "Point B of question {$n}", 'Seen: ' . implode(', ', $seen)]];
                }
                $n = (int)$m[1];
                $seen = [];
            } elseif ($n > 0 && preg_match('/^- (.*) \(×\d+\)$/u', $line, $m) === 1) {
                $seen[] = $m[1];
            }
        }
        if ($n > 0) {
            $out[] = ['n' => $n, 'points' => ["Point A of question {$n} (run {$run})", "Point B of question {$n}", 'Seen: ' . implode(', ', $seen)]];
        }
        return ['output' => json_encode(['questions' => $out], JSON_UNESCAPED_UNICODE)];
    }
}
