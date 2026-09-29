<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\AppInfo\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Server and bundle state the same protocol number. If they differed, every
 * freshly built phone would consider the server foreign and reload (once per tab)
 * for no reason, or a bump on the server would go unnoticed because the
 * bundle already carries the new number. Reads the JS source statically, like
 * PollParamsTest does with DeckService.
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
        // 1 = everything before 0.19.0 (bundles without a protocol field).
        $this->assertGreaterThanOrEqual(2, Application::PROTOCOL);
    }
}
