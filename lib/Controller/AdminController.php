<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Controller;

use OCA\Pulse\AppInfo\Application;
use OCA\Pulse\Service\AiPolicy;
use OCA\Pulse\Service\Input;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Admin section "Pulse": the switch "Allow AI in Pulse" and the daily
 * limit of AI requests per account (AiPolicy). Deliberately WITHOUT
 * #[NoAdminRequired]: Nextcloud answers anyone but an admin with 403 before
 * this code runs. CSRF on, like every other route.
 */
class AdminController extends Controller {
    public function __construct(
        IRequest $request,
        private AiPolicy $policy,
        private IL10N $l10n,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /** Body {enabled: bool, dailyLimit: 0–1000}; 200 {enabled, dailyLimit}, invalid 400. */
    public function saveAi(): JSONResponse {
        $enabled = Input::flag($this->request->getParam('enabled'));
        if ($enabled === null) {
            return new JSONResponse(['message' => $this->l10n->t('Invalid request.')], Http::STATUS_BAD_REQUEST);
        }
        try {
            $this->policy->setSettings($enabled, $this->request->getParam('dailyLimit'));
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
        return new JSONResponse(['enabled' => $this->policy->enabled(), 'dailyLimit' => $this->policy->dailyLimit()]);
    }
}
