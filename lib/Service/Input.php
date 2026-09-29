<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

/**
 * Rohe Client-Werte (Request-Parameter und die Felder darin) in die Form
 * bringen, die der Aufrufer erwartet — ohne PHP-Warnung und ohne TypeError.
 * Jeder Parameter kann als Liste oder Objekt ankommen (`action[]=x`, JSON):
 * `(string)` darauf schreibt „Array to string conversion“ ins Log, ein
 * string-Parameter wirft TypeError (500), und `(int)` auf 1e100 warnt
 * („not representable as an int“).
 *
 * Regel: Was nicht passt, gilt als nicht gesendet (null bzw. $default). Ob das
 * die Vorgabe heißt oder 400, entscheidet der Aufrufer — meist liefert die
 * vorhandene Prüfung dieselbe 400 wie für einen leeren Wert. Statisch und ohne
 * DI wie CsvFormat und PublicView.
 */
class Input {
    /**
     * Skalar als Text. Listen/Objekte, null, Unendlich/NAN und kaputtes UTF-8
     * (PostgreSQL lehnt es ab -> 500) ergeben $default; NUL-Bytes fallen weg
     * (ein Textfeld in PostgreSQL kann sie nicht halten).
     */
    public static function str(mixed $v, string $default = ''): string {
        return str_replace("\0", '', self::rawStr($v, $default));
    }

    /**
     * Wie str(), aber NUL-Bytes bleiben stehen. Nur für Text, der mit
     * Gespeichertem verglichen und höchstens JSON-kodiert gespeichert wird
     * (json_encode schreibt \u0000 — PostgreSQL sieht nie ein NUL): die
     * Freitextantwort beim Bewerten. Die Stimme behält ihr NUL
     * (VoteService::normalizeValue), die Moderation schickt genau diesen Text
     * zurück — ohne NUL träfe die Normalform die Gruppe nie, und die Antwort
     * bliebe für immer „wird geprüft“.
     */
    public static function rawStr(mixed $v, string $default = ''): string {
        if (!is_scalar($v) || (is_float($v) && !is_finite($v))) {
            return $default;
        }
        $s = (string)$v;
        return mb_check_encoding($s, 'UTF-8') ? $s : $default;
    }

    /**
     * Endliche Zahl aus int, float oder numerischem Text („12“, „1.5“, „1e3“).
     * „1e999“ und JSON 1e999 werden in PHP INF — keine Zahl, die sich speichern
     * ließe (json_encode scheitert daran): null. Ebenso bool, Listen, Objekte.
     */
    public static function number(mixed $v): int|float|null {
        if (is_string($v) && is_numeric($v)) {
            $v = $v + 0;
        }
        if (is_int($v)) {
            return $v;
        }
        return is_float($v) && is_finite($v) ? $v : null;
    }

    /**
     * Ganzzahl wie bisher per (int) — Nachkommastellen werden abgeschnitten —,
     * aber nur aus einer endlichen Zahl im sicheren Bereich (±1e18), sonst null.
     */
    public static function int(mixed $v): ?int {
        $n = self::number($v);
        if (is_float($n)) {
            return $n > -1e18 && $n < 1e18 ? (int)$n : null;
        }
        return $n;
    }

    /**
     * Schalter: bool, int und die Wörter, die filter_var kennt („true“, „off“,
     * „yes“ …; '' = false). Alles andere (Liste, Kommazahl, „vielleicht“, 2) -> null.
     */
    public static function flag(mixed $v): ?bool {
        if (is_bool($v)) {
            return $v;
        }
        if (!is_int($v) && !is_string($v)) {
            return null;
        }
        return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
