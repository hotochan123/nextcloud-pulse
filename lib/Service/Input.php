<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

/**
 * Brings raw client values (request parameters and the fields inside them)
 * into the shape the caller expects — without a PHP warning and without a TypeError.
 * Any parameter can arrive as a list or an object (`action[]=x`, JSON):
 * `(string)` on it writes "Array to string conversion" to the log, a
 * string parameter throws a TypeError (500), and `(int)` on 1e100 warns
 * ("not representable as an int").
 *
 * Rule: whatever does not fit counts as not sent (null or $default). Whether
 * that means the default or a 400 is up to the caller — usually the existing
 * check returns the same 400 as for an empty value. Static and without
 * DI, like CsvFormat and PublicView.
 */
class Input {
    /**
     * Scalar as text. Lists/objects, null, infinity/NAN and broken UTF-8
     * (PostgreSQL rejects it -> 500) yield $default; NUL bytes are dropped
     * (a text column in PostgreSQL cannot hold them).
     */
    public static function str(mixed $v, string $default = ''): string {
        return str_replace("\0", '', self::rawStr($v, $default));
    }

    /**
     * Like str(), but NUL bytes are kept. Only for text that is compared with
     * stored data and at most stored JSON-encoded
     * (json_encode writes \u0000 — PostgreSQL never sees a NUL): the
     * free-text answer when grading. The vote keeps its NUL
     * (VoteService::normalizeValue), and the moderator sends exactly that text
     * back — without the NUL the normal form would never match the group, and
     * the answer would stay "Being checked" forever.
     */
    public static function rawStr(mixed $v, string $default = ''): string {
        if (!is_scalar($v) || (is_float($v) && !is_finite($v))) {
            return $default;
        }
        $s = (string)$v;
        return mb_check_encoding($s, 'UTF-8') ? $s : $default;
    }

    /**
     * Finite number from an int, a float or numeric text ("12", "1.5", "1e3").
     * "1e999" and JSON 1e999 become INF in PHP — not a number that could be
     * stored (json_encode fails on it): null. Likewise bool, lists, objects.
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
     * Integer via (int) as before — decimals are truncated —
     * but only from a finite number in the safe range (±1e18), otherwise null.
     */
    public static function int(mixed $v): ?int {
        $n = self::number($v);
        if (is_float($n)) {
            return $n > -1e18 && $n < 1e18 ? (int)$n : null;
        }
        return $n;
    }

    /**
     * Switch: bool, int and the words filter_var knows ("true", "off",
     * "yes" …; '' = false). Anything else (list, decimal, "maybe", 2) -> null.
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
