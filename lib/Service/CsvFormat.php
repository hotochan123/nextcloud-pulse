<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCP\IL10N;

/**
 * Formatting for the CSV exports. Static, so that the moderated and the
 * self-paced export write the same numbers and labels.
 */
class CsvFormat {
    /**
     * Formats a number for the export in the export's language. Without
     * this the German export writes a dot and Excel reads the column as
     * text (and the English export a comma, the other way round).
     */
    public static function number(float|int|string $v, string $locale): string {
        $n = (float)$v;
        if (class_exists(\NumberFormatter::class)) {
            $formatted = (new \NumberFormatter($locale, \NumberFormatter::DECIMAL))->format($n);
            if ($formatted !== false) {
                return $formatted;
            }
        }
        return (string)$n;
    }

    /**
     * Free text from the audience (names, free-text answers, words) for the
     * CSV: if it starts like a formula, prefix an apostrophe — otherwise
     * Excel would run "=HYPERLINK(…)" from a player name when opening the file.
     */
    public static function cell(string $s): string {
        return preg_match('/^[=+\-@\t\r]/', $s) === 1 ? "'" . $s : $s;
    }

    /** Readable name of a question type for the "Type" column. */
    public static function typeLabel(string $type, IL10N $l10n): string {
        return match ($type) {
            'words' => $l10n->t('Word cloud'),
            'scale' => $l10n->t('Scale'),
            'rank' => $l10n->t('Ranking'),
            'match' => $l10n->t('Matching'),
            'truefalse' => $l10n->t('True/False'),
            'multi' => $l10n->t('Multiple answers'),
            'number' => $l10n->t('Number guess'),
            'text' => $l10n->t('Free text'),
            default => $l10n->t('Multiple choice'),
        };
    }
}
