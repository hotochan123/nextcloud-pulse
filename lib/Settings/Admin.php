<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Settings;

use OCA\Pulse\AppInfo\Application;
use OCA\Pulse\Service\AiPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

/** The admin form in the section "Pulse" (src/admin.js). */
class Admin implements ISettings {
    public function __construct(
        private AiPolicy $policy,
        private IInitialState $initialState,
    ) {
    }

    public function getForm(): TemplateResponse {
        $this->initialState->provideInitialState('admin', [
            'enabled' => $this->policy->enabled(),
            'dailyLimit' => $this->policy->dailyLimit(),
            'dailyMax' => AiPolicy::DAILY_MAX,
        ]);
        return new TemplateResponse(Application::APP_ID, 'admin', [], '');
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 10;
    }
}
