<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\AppInfo\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Server und Bundle nennen dieselbe Protokollnummer. Weichen sie ab, hielte
 * jedes frisch gebaute Handy den Server für fremd und lüde sich (einmal je Tab)
 * grundlos neu — oder ein Sprung auf dem Server bliebe unbemerkt, weil das
 * Bundle schon die neue Zahl trägt. Liest die JS-Quelle statisch, wie
 * PollParamsTest den DeckService.
 */
#[CoversClass(Application::class)]
class ProtocolConstantTest extends TestCase {

    public function testBundleNenntDieServerNummer(): void {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/util/protocol.js');
        $this->assertNotFalse($source, 'src/util/protocol.js nicht lesbar');

        $this->assertSame(1, preg_match_all('/^export const PROTOCOL = (\d+)\s*$/m', $source, $m),
            'genau eine Zeile „export const PROTOCOL = <Zahl>"');
        $this->assertSame(Application::PROTOCOL, (int)$m[1][0]);
    }

    public function testNummerLiegtUeberDemAltstand(): void {
        // 1 = alles vor 0.19.0 (Bundles ohne Protokollfeld).
        $this->assertGreaterThanOrEqual(2, Application::PROTOCOL);
    }
}
