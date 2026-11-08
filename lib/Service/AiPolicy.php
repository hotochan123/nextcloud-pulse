<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;

/**
 * The admin's rules for AI in Pulse, in one place:
 *
 * - the switch "Allow AI in Pulse" (app config ai_enabled, default on): off
 *   means no AI anywhere in Pulse, every feature asks enabled() through
 *   AiDraftService::available, ModerationService::aiAvailable and aiHelp;
 * - a daily limit per account (app config ai_daily_limit, default 30,
 *   0–1000) for the AI tasks a moderator starts; the automatic check of
 *   answers for the projector is not counted. Counted in the user value
 *   ai_day = "YYYY-MM-DD:n" (server time zone). Approximate like the other
 *   limits: two starts at the same moment may both pass;
 * - the notice an account confirms once before its first AI use (user
 *   value ai_notice = '1').
 */
class AiPolicy {
    public const KEY_ENABLED = 'ai_enabled';
    public const KEY_DAILY = 'ai_daily_limit';
    public const PREF_NOTICE = 'ai_notice';
    public const PREF_DAY = 'ai_day';
    public const DAILY_DEFAULT = 30;
    public const DAILY_MAX = 1000;

    public function __construct(
        private IAppConfig $appConfig,
        private IConfig $config,
        private ITimeFactory $timeFactory,
        private IL10N $l10n,
    ) {
    }

    /** The admin allows AI in Pulse. */
    public function enabled(): bool {
        try {
            return $this->appConfig->getValueString(Application::APP_ID, self::KEY_ENABLED, '1') !== '0';
        } catch (\Throwable) {
            return true;
        }
    }

    /** AI tasks per account and day, 0–1000. */
    public function dailyLimit(): int {
        try {
            $raw = $this->appConfig->getValueString(Application::APP_ID, self::KEY_DAILY, '');
        } catch (\Throwable) {
            return self::DAILY_DEFAULT;
        }
        $n = self::wholeNumber($raw);
        if ($n === null) {
            return self::DAILY_DEFAULT;
        }
        return max(0, min(self::DAILY_MAX, $n));
    }

    /**
     * @param mixed $limit an integer 0–1000 (a digit string counts)
     * @throws \InvalidArgumentException anything else; nothing is stored then
     */
    public function setSettings(bool $enabled, mixed $limit): void {
        $n = self::wholeNumber($limit);
        if ($n === null || $n < 0 || $n > self::DAILY_MAX) {
            throw new \InvalidArgumentException($this->l10n->t('Enter a whole number from 0 to %d.', [self::DAILY_MAX]));
        }
        $this->appConfig->setValueString(Application::APP_ID, self::KEY_ENABLED, $enabled ? '1' : '0');
        $this->appConfig->setValueString(Application::APP_ID, self::KEY_DAILY, (string)$n);
    }

    public function noticeConfirmed(string $uid): bool {
        return $uid !== '' && $this->config->getUserValue($uid, Application::APP_ID, self::PREF_NOTICE, '') === '1';
    }

    public function confirmNotice(string $uid): void {
        if ($uid !== '') {
            $this->config->setUserValue($uid, Application::APP_ID, self::PREF_NOTICE, '1');
        }
    }

    /** @throws AiDraftException 409 (notice: true) while the notice is not confirmed */
    public function assertNotice(string $uid): void {
        if (!$this->noticeConfirmed($uid)) {
            throw new AiDraftException($this->l10n->t('Please confirm the AI notice first.'), 409, ['notice' => true]);
        }
    }

    /** AI tasks this account started today. */
    public function usedToday(string $uid): int {
        $raw = $this->config->getUserValue($uid, Application::APP_ID, self::PREF_DAY, '');
        if (!is_string($raw) || !preg_match('/^(\d{4}-\d{2}-\d{2}):(\d{1,9})$/', $raw, $m) || $m[1] !== $this->today()) {
            return 0;
        }
        return (int)$m[2];
    }

    /** @throws AiDraftException 429 once today's tasks reached the limit */
    public function assertDaily(string $uid): void {
        $limit = $this->dailyLimit();
        if ($this->usedToday($uid) >= $limit) {
            throw new AiDraftException($this->l10n->n(
                'You have used all %n AI request for today. The limit resets tomorrow.',
                'You have used all %n AI requests for today. The limit resets tomorrow.',
                $limit,
            ), 429);
        }
    }

    /** One more AI task started by this account today. */
    public function countDaily(string $uid): void {
        $this->config->setUserValue($uid, Application::APP_ID, self::PREF_DAY, $this->today() . ':' . ($this->usedToday($uid) + 1));
    }

    /** An int, or a string of digits; anything else (decimals, "", bool) null. */
    private static function wholeNumber(mixed $v): ?int {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^\s*(-?\d{1,9})\s*$/', $v, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    private function today(): string {
        return date('Y-m-d', $this->timeFactory->getTime());
    }
}
