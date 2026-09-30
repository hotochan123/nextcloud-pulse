<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Service\PaceService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The deadline dialog and the server state the same bounds. src/util/pace.js
 * mirrors PaceService::MIN_LEAD / MAX_LEAD as DEADLINE_MIN / DEADLINE_MAX; if
 * one side changed alone, the dialog would offer deadlines the server
 * rejects with 400 (or refuse ones it accepts). dev/unit/pace.test.mjs only
 * compares the JS side with fixed numbers. Reads the JS source statically,
 * like ProtocolConstantTest.
 */
#[CoversClass(PaceService::class)]
class PaceDeadlineConstantTest extends TestCase {

    public static function bounds(): array {
        return [
            'lower bound' => ['DEADLINE_MIN', 'MIN_LEAD'],
            'upper bound' => ['DEADLINE_MAX', 'MAX_LEAD'],
        ];
    }

    #[DataProvider('bounds')]
    public function testDialogStatesTheServerBound(string $js, string $php): void {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/util/pace.js');
        $this->assertNotFalse($source, 'src/util/pace.js not readable');

        $this->assertSame(1, preg_match_all('/^export const ' . $js . ' = (\d+)\s*$/m', $source, $m),
            'exactly one line "export const ' . $js . ' = <number>"');
        $this->assertSame((new \ReflectionClassConstant(PaceService::class, $php))->getValue(), (int)$m[1][0]);
    }
}
