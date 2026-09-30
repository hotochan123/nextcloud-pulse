<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Service\Input;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Raw client values (Input): any parameter can arrive as a list, an object, 1e999
 * or broken UTF-8. Whatever does not fit counts as not sent —
 * without a PHP warning (phpunit.xml: failOnWarning) and without a TypeError.
 */
#[CoversClass(Input::class)]
class InputTest extends TestCase {

    // ── str ────────────────────────────────────────────────────────────────

    public static function texts(): array {
        return [
            'Text bleibt' => ['Grüß Gott', 'Grüß Gott'],
            'Ganzzahl' => [12, '12'],
            'Kommazahl' => [1.5, '1.5'],
            'true' => [true, '1'],
            'false' => [false, ''],
            'NUL fällt weg' => ["a\0b", 'ab'],
            'Liste' => [['x'], 'DEF'],
            'verschachteltes Objekt' => [['a' => ['b']], 'DEF'],
            'null' => [null, 'DEF'],
            'kaputtes UTF-8' => ["\xFF\xFE", 'DEF'],
            'INF' => [INF, 'DEF'],
            'NAN' => [NAN, 'DEF'],
        ];
    }

    #[DataProvider('texts')]
    public function testStr(mixed $raw, string $expected): void {
        $this->assertSame($expected, Input::str($raw, 'DEF'));
    }

    // ── rawStr: like str, except that NUL stays ────────────────────────────

    #[DataProvider('texts')]
    public function testRawStrLikeStrExceptNul(mixed $raw, string $expected): void {
        if (is_string($raw) && str_contains($raw, "\0")) {
            $expected = $raw;
        }
        $this->assertSame($expected, Input::rawStr($raw, 'DEF'));
    }

    public function testRawStrKeepsNulInsideAndAtTheEdges(): void {
        $this->assertSame("\0Pa\0ris\0", Input::rawStr("\0Pa\0ris\0"));
    }

    // ── number ─────────────────────────────────────────────────────────────

    public static function numbers(): array {
        return [
            'Ganzzahl' => [5, 5],
            'Text' => ['5', 5],
            'Leerraum vorn' => [' 5', 5],
            'Kommazahl als Text' => ['1.5', 1.5],
            'Exponent' => ['1e3', 1000.0],
            'negative Kommazahl' => [-2.5, -2.5],
            '1e999 als Text' => ['1e999', null],
            'INF' => [INF, null],
            '-INF' => [-INF, null],
            'NAN' => [NAN, null],
            'true' => [true, null],
            'null' => [null, null],
            'Liste' => [['5'], null],
            'kein Zahlentext' => ['abc', null],
            'leer' => ['', null],
            'Hex' => ['0x1A', null],
        ];
    }

    #[DataProvider('numbers')]
    public function testNumber(mixed $raw, int|float|null $expected): void {
        $this->assertSame($expected, Input::number($raw));
    }

    // ── int ────────────────────────────────────────────────────────────────

    public static function integers(): array {
        return [
            'Kommazahl als Text wird abgeschnitten' => ['5.9', 5],
            'negative Kommazahl' => [-2.7, -2],
            '1e17 passt' => [1e17, 100000000000000000],
            'minus null' => ['-0', 0],
            'Ganzzahl' => [7, 7],
            '1e100' => [1e100, null],
            '-1e100' => [-1e100, null],
            'zwanzig Ziffern' => ['99999999999999999999', null],
            '1e999 als Text' => ['1e999', null],
            'INF' => [INF, null],
            'NAN' => [NAN, null],
            'Liste' => [['1'], null],
            'true' => [true, null],
            'kein Zahlentext' => ['abc', null],
        ];
    }

    #[DataProvider('integers')]
    public function testInt(mixed $raw, ?int $expected): void {
        $this->assertSame($expected, Input::int($raw));
    }

    // ── flag ───────────────────────────────────────────────────────────────

    public static function flags(): array {
        return [
            'true' => [true, true],
            'false' => [false, false],
            "'true'" => ['true', true],
            "'false'" => ['false', false],
            "'1'" => ['1', true],
            "'0'" => ['0', false],
            '1' => [1, true],
            '0' => [0, false],
            "'on'" => ['on', true],
            "'no'" => ['no', false],
            "'yes'" => ['yes', true],
            "'off'" => ['off', false],
            'leer' => ['', false],
            "'abc'" => ['abc', null],
            '2' => [2, null],
            'Liste' => [['1'], null],
            'null' => [null, null],
            'Kommazahl' => [1.0, null],
        ];
    }

    #[DataProvider('flags')]
    public function testFlag(mixed $raw, ?bool $expected): void {
        $this->assertSame($expected, Input::flag($raw));
    }
}
