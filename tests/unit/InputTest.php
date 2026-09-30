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
            'text stays' => ['Grüß Gott', 'Grüß Gott'],
            'integer' => [12, '12'],
            'float' => [1.5, '1.5'],
            'true' => [true, '1'],
            'false' => [false, ''],
            'NUL is dropped' => ["a\0b", 'ab'],
            'list' => [['x'], 'DEF'],
            'nested object' => [['a' => ['b']], 'DEF'],
            'null' => [null, 'DEF'],
            'broken UTF-8' => ["\xFF\xFE", 'DEF'],
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
            'integer' => [5, 5],
            'Text' => ['5', 5],
            'leading whitespace' => [' 5', 5],
            'float as text' => ['1.5', 1.5],
            'Exponent' => ['1e3', 1000.0],
            'negative float' => [-2.5, -2.5],
            '1e999 as text' => ['1e999', null],
            'INF' => [INF, null],
            '-INF' => [-INF, null],
            'NAN' => [NAN, null],
            'true' => [true, null],
            'null' => [null, null],
            'list' => [['5'], null],
            'non-numeric text' => ['abc', null],
            'empty' => ['', null],
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
            'float as text is truncated' => ['5.9', 5],
            'negative float' => [-2.7, -2],
            '1e17 fits' => [1e17, 100000000000000000],
            'minus zero' => ['-0', 0],
            'integer' => [7, 7],
            '1e100' => [1e100, null],
            '-1e100' => [-1e100, null],
            'twenty digits' => ['99999999999999999999', null],
            '1e999 as text' => ['1e999', null],
            'INF' => [INF, null],
            'NAN' => [NAN, null],
            'list' => [['1'], null],
            'true' => [true, null],
            'non-numeric text' => ['abc', null],
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
            'empty' => ['', false],
            "'abc'" => ['abc', null],
            '2' => [2, null],
            'list' => [['1'], null],
            'null' => [null, null],
            'float' => [1.0, null],
        ];
    }

    #[DataProvider('flags')]
    public function testFlag(mixed $raw, ?bool $expected): void {
        $this->assertSame($expected, Input::flag($raw));
    }

    // ── clip: raw text bounded before the Unicode work ─────────────────────

    public static function clips(): array {
        return [
            'short text stays' => ['Kaffee', 10, 'Kaffee'],
            'exactly max bytes stays' => [str_repeat('x', 10), 10, str_repeat('x', 10)],
            'cut to max characters' => [str_repeat('x', 11), 10, str_repeat('x', 10)],
            'more bytes than max, not more characters' => [str_repeat('ä', 10), 10, str_repeat('ä', 10)],
            'multibyte cut by characters' => [str_repeat('ä', 15), 10, str_repeat('ä', 10)],
            'short broken UTF-8 is left to the caller' => ["\xFF\xFE", 10, "\xFF\xFE"],
            'long broken UTF-8 becomes empty, not a question mark' => [str_repeat("\xFF", 11), 10, ''],
            'NUL counts like any character' => ["a\0b\0c", 3, "a\0b"],
        ];
    }

    #[DataProvider('clips')]
    public function testClip(string $raw, int $max, string $expected): void {
        $this->assertSame($expected, Input::clip($raw, $max));
    }
}
