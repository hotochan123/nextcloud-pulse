<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Service\AiPolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;

/**
 * openPolicy(): an AiPolicy that allows everything (AI on, notice
 * confirmed, no daily limit), for tests of the AI features themselves.
 * realPolicy(): the real rules over in-memory app config ($appValues) and
 * user values ($userValues, "<uid>/<key>") at the time $policyNow.
 */
trait AiPolicyStub {
    /** @var array<string, string> */
    private array $appValues = [];
    /** @var array<string, string> */
    private array $userValues = [];
    private int $policyNow = 1793880000; // 2026-11-05 12:00 UTC

    private function openPolicy(): AiPolicy {
        $policy = $this->createMock(AiPolicy::class);
        $policy->method('enabled')->willReturn(true);
        $policy->method('noticeConfirmed')->willReturn(true);
        $policy->method('dailyLimit')->willReturn(AiPolicy::DAILY_MAX);
        return $policy;
    }

    private function realPolicy(): AiPolicy {
        $app = $this->createMock(IAppConfig::class);
        $app->method('getValueString')->willReturnCallback(fn (string $a, string $key, string $d = ''): string => $this->appValues[$key] ?? $d);
        $app->method('setValueString')->willReturnCallback(function (string $a, string $key, string $v): bool {
            $this->appValues[$key] = $v;
            return true;
        });
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturnCallback(fn (string $u, string $a, string $key, mixed $d = ''): mixed => $this->userValues[$u . '/' . $key] ?? $d);
        $config->method('setUserValue')->willReturnCallback(function (string $u, string $a, string $key, mixed $v): void {
            $this->userValues[$u . '/' . $key] = (string)$v;
        });
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->policyNow);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(fn (string $s, array $p = []): string => vsprintf($s, $p));
        $l10n->method('n')->willReturnCallback(fn (string $one, string $many, int $n): string => str_replace('%n', (string)$n, $n === 1 ? $one : $many));
        return new AiPolicy($app, $config, $time, $l10n);
    }
}
