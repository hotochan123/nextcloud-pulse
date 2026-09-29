<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCP\IL10N;

/**
 * Formatierung für die CSV-Exporte. Statisch, damit moderierter und
 * selbstgetakteter Export dieselben Zahlen und Bezeichnungen schreiben.
 */
class CsvFormat {
    /**
     * Zahl für den Export in der Sprache des Exports formatiert. Ohne das
     * schreibt der deutsche Export einen Punkt und Excel liest die Spalte als
     * Text (und umgekehrt beim englischen Export ein Komma).
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
     * Freier Text aus dem Publikum (Namen, Freitext-Antworten, Wörter) für die
     * CSV: beginnt er wie eine Formel, stellt ein Apostroph davor — sonst
     * führte Excel „=HYPERLINK(…)" aus einem Spielernamen beim Öffnen aus.
     */
    public static function cell(string $s): string {
        return preg_match('/^[=+\-@\t\r]/', $s) === 1 ? "'" . $s : $s;
    }

    /** Lesbarer Name eines Fragetyps für die Spalte „Typ". */
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
