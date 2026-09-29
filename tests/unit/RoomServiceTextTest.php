<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Service\RoomService;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The text-processing parts of RoomService — room title and copy suffix.
 * Both are private and DB-free; the instance is therefore created without a constructor
 * (newInstanceWithoutConstructor), so that no mapper/connection is needed.
 * Only the translation is injected afterwards: copyTitle appends a translated suffix,
 * and the app's source language is English — so the stub double returns
 * the source text.
 * Everything that really touches the database (duplicateRoom, createRoom …)
 * is left to the integration harnesses — see tests/README.md.
 */
#[CoversClass(RoomService::class)]
class RoomServiceTextTest extends TestCase {

    private RoomService $service;

    protected function setUp(): void {
        $this->service = (new \ReflectionClass(RoomService::class))->newInstanceWithoutConstructor();
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
        );
        $property = new \ReflectionProperty(RoomService::class, 'l10n');
        $property->setValue($this->service, $l10n);
    }

    // ── sanitizeTitle ──────────────────────────────────────────────────────

    public function testTitelWirdGetrimmt(): void {
        $this->assertSame('Retrospektive KW 30', $this->sanitize('   Retrospektive KW 30  '));
    }

    public function testZeilenumbrueccheUndSteuerzeichenWerdenZuEinemLeerzeichen(): void {
        $this->assertSame('Zeile1 Zeile2 Ende', $this->sanitize("Zeile1\nZeile2\t\tEnde\x00"));
    }

    public function testMehrfacheLeerzeichenWerdenZusammengezogen(): void {
        $this->assertSame('A B', $this->sanitize('A     B'));
    }

    public function testTitelWirdAufAchtzigZeichenGekuerzt(): void {
        $this->assertSame(80, mb_strlen($this->sanitize(str_repeat('x', 200))));
    }

    public function testKuerzungZaehltZeichenNichtBytes(): void {
        // The column is varchar(80) -> 80 CHARACTERS, umlauts must not count twice.
        $this->assertSame(str_repeat('ä', 80), $this->sanitize(str_repeat('ä', 100)));
    }

    public function testLeererTitelBleibtLeer(): void {
        $this->assertSame('', $this->sanitize('   '));
    }

    // ── copyTitle ──────────────────────────────────────────────────────────

    public function testKopieBekommtSuffix(): void {
        $this->assertSame('Schulung (copy)', $this->copyTitle('Schulung'));
    }

    public function testKopieOhneTitelBleibtOhneTitel(): void {
        $this->assertSame('', $this->copyTitle(''));
    }

    public function testLangerTitelBehaeltDenVollstaendigenSuffix(): void {
        // It is not the suffix that gets cut off, but the name before it.
        $copy = $this->copyTitle(str_repeat('a', 80));
        $this->assertSame(80, mb_strlen($copy));
        $this->assertSame(' (copy)', mb_substr($copy, -7));
    }

    public function testKopieDerKopieBleibtInnerhalbDerSpaltenbreite(): void {
        $copy = $this->copyTitle($this->copyTitle(str_repeat('a', 80)));
        $this->assertLessThanOrEqual(80, mb_strlen($copy));
        $this->assertSame(' (copy)', mb_substr($copy, -7));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function sanitize(string $title): string {
        return $this->call('sanitizeTitle', $title);
    }

    private function copyTitle(string $title): string {
        return $this->call('copyTitle', $title);
    }

    private function call(string $method, string $arg): string {
        $m = new ReflectionMethod(RoomService::class, $method);
        return $m->invoke($this->service, $arg);
    }
}
