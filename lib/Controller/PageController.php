<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Controller;

use OCA\Pulse\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class PageController extends Controller {
    public function __construct(IRequest $request) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Entry point of the moderator SPA. Any signed-in user may create rooms.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        $response = new TemplateResponse(Application::APP_ID, 'index');
        // The default CSP is enough: the app only talks same-origin to its own API.
        $response->setContentSecurityPolicy(new ContentSecurityPolicy());
        return $response;
    }
}
