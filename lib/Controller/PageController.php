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
     * Einstieg der Moderator-SPA. Jede angemeldete Person darf Räume anlegen.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        $response = new TemplateResponse(Application::APP_ID, 'index');
        // Standard-CSP reicht: die App spricht nur same-origin mit der eigenen API.
        $response->setContentSecurityPolicy(new ContentSecurityPolicy());
        return $response;
    }
}
