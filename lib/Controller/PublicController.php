<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Controller;

use OCA\Pulse\AppInfo\Application;
use OCA\Pulse\Db\RoomMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;

/**
 * Public participant page: no Nextcloud account needed. The room code in the
 * URL (/apps/pulse/s/{code}) acts like the token of a share link.
 */
class PublicController extends Controller {
    public function __construct(
        IRequest $request,
        private RoomMapper $roomMapper,
        private IInitialState $initialState,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Join page without a code: participants type in the 6-digit code.
     * Renders the same bundle; empty code => Participant.vue shows the input.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function join(): TemplateResponse {
        $this->initialState->provideInitialState('code', '');
        $this->initialState->provideInitialState('roomExists', false);

        $response = new TemplateResponse(
            Application::APP_ID,
            'public',
            [],
            TemplateResponse::RENDER_AS_PUBLIC,
        );
        $response->setContentSecurityPolicy(new ContentSecurityPolicy());
        return $response;
    }

    #[PublicPage]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: 'pulseRoomCode')]
    public function show(string $code): TemplateResponse {
        $exists = true;
        try {
            $this->roomMapper->findByCode($code);
        } catch (DoesNotExistException) {
            $exists = false;
        }

        // The SPA fetches the state itself; pass code + existence up front
        // so it starts without URL parsing and a missing room is visible immediately.
        $this->initialState->provideInitialState('code', $code);
        $this->initialState->provideInitialState('roomExists', $exists);

        $response = new TemplateResponse(
            Application::APP_ID,
            'public',
            [],
            TemplateResponse::RENDER_AS_PUBLIC,
        );
        $response->setContentSecurityPolicy(new ContentSecurityPolicy());
        // Count a call with an unknown code as a failed brute-force attempt.
        if (!$exists) {
            $response->throttle(['action' => 'pulseRoomCode']);
        }
        return $response;
    }

    /**
     * Projector/audience view (public, no account): the same SPA, but the
     * `screen` flag switches the bundle to the big-screen presentation instead of
     * the voting UI. Read-only — uses the same secured
     * publicState, which only hands out the correct answer after the reveal.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: 'pulseRoomCode')]
    public function screen(string $code): TemplateResponse {
        $exists = true;
        try {
            $this->roomMapper->findByCode($code);
        } catch (DoesNotExistException) {
            $exists = false;
        }

        $this->initialState->provideInitialState('code', $code);
        $this->initialState->provideInitialState('roomExists', $exists);
        $this->initialState->provideInitialState('screen', true);

        $response = new TemplateResponse(
            Application::APP_ID,
            'public',
            [],
            TemplateResponse::RENDER_AS_PUBLIC,
        );
        // The projector/audience view is read-only (no action that could be
        // abused via clickjacking) and should embed in presentation tools like a
        // Mentimeter slide — above all the
        // "Web Viewer" add-in in PowerPoint. Hence frame-ancestors open.
        // Caution: the second framing blocker, X-Frame-Options: SAMEORIGIN, comes
        // from the core .htaccess (Header always set) and can NOT be
        // overridden from PHP — it is removed for /screen in Traefik
        // (dynamic_conf/http.routers.pulse-embed.yml). The voting pages
        // (show/join) deliberately stay strictly on 'self'.
        $csp = new ContentSecurityPolicy();
        $csp->addAllowedFrameAncestorDomain('*');
        $response->setContentSecurityPolicy($csp);
        if (!$exists) {
            $response->throttle(['action' => 'pulseRoomCode']);
        }
        return $response;
    }

    /**
     * Embed shell for the Office/PowerPoint add-in (content add-in). Loads office.js,
     * asks once for the room code (persisted in the document settings of the .pptx)
     * and then frames /screen/{code} same-origin. Must be frameable by Office
     * (frame-ancestors open) and allowed to load office.js from Microsoft's CDN — a
     * read-only shell, no sensitive NC content. The second framing blocker,
     * X-Frame-Options, is additionally removed for this path in Traefik
     * (dynamic_conf/http.routers.pulse-embed.yml).
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function embed(): TemplateResponse {
        // RENDER_AS_PUBLIC (not BLANK!): only the public layout includes the assets
        // registered via addScript/addStyle together with the CSP nonce. BLANK returns
        // nothing but the template content (without <head> → office.js/JS
        // would never load). embed.css hides the NC guest chrome.
        $response = new TemplateResponse(
            Application::APP_ID,
            'embed',
            [],
            TemplateResponse::RENDER_AS_PUBLIC,
        );
        $csp = new ContentSecurityPolicy();
        $csp->addAllowedFrameAncestorDomain('*');
        // office.js + its dynamically loaded resources from Microsoft's CDN
        $csp->addAllowedScriptDomain('https://appsjs.microsoft.com');
        $csp->addAllowedScriptDomain('https://*.microsoft.com');
        $csp->addAllowedConnectDomain('https://appsjs.microsoft.com');
        $csp->addAllowedConnectDomain('https://*.microsoft.com');
        $csp->addAllowedConnectDomain('https://*.office.com');
        $csp->addAllowedImageDomain('https://appsjs.microsoft.com');
        $csp->addAllowedImageDomain('https://*.microsoft.com');
        $response->setContentSecurityPolicy($csp);
        return $response;
    }
}
