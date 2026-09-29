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
 * Öffentliche Teilnehmer-Seite: kein Nextcloud-Konto nötig. Der Raumcode in der
 * URL (/apps/pulse/s/{code}) wirkt wie das Token eines Freigabe-Links.
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
     * Beitritts-Seite ohne Code: Teilnehmende tippen den 6-stelligen Code ein.
     * Rendert dasselbe Bundle; leerer Code => Participant.vue zeigt die Eingabe.
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

        // Die SPA holt sich den Zustand selbst; Code + Existenz vorab mitgeben,
        // damit sie ohne URL-Parsing startet und ein fehlender Raum sofort sichtbar ist.
        $this->initialState->provideInitialState('code', $code);
        $this->initialState->provideInitialState('roomExists', $exists);

        $response = new TemplateResponse(
            Application::APP_ID,
            'public',
            [],
            TemplateResponse::RENDER_AS_PUBLIC,
        );
        $response->setContentSecurityPolicy(new ContentSecurityPolicy());
        // Aufruf mit unbekanntem Code als Brute-Force-Fehlversuch werten.
        if (!$exists) {
            $response->throttle(['action' => 'pulseRoomCode']);
        }
        return $response;
    }

    /**
     * Beamer-/Publikumsansicht (öffentlich, ohne Konto): dieselbe SPA, aber das
     * Flag `screen` schaltet das Bundle auf die Großbild-Präsentation statt auf
     * die Abstimm-Oberfläche. Rein lesend — nutzt denselben abgesicherten
     * publicState, der die richtige Antwort erst nach dem Auflösen herausgibt.
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
        // Die Beamer-/Publikumsansicht ist rein lesend (keine Aktion, die sich
        // per Clickjacking missbrauchen ließe) und soll sich wie eine Mentimeter-
        // Folie in Präsentations-Tools einbetten lassen — allen voran das
        // „Web Viewer"-Add-in in PowerPoint. Darum frame-ancestors offen.
        // Achtung: der zweite Framing-Blocker X-Frame-Options: SAMEORIGIN kommt
        // aus der Core-.htaccess (Header always set) und ist per PHP NICHT
        // überschreibbar — er wird für /screen im Traefik entfernt
        // (dynamic_conf/http.routers.pulse-embed.yml). Die Abstimm-Seiten
        // (show/join) bleiben bewusst hart auf 'self'.
        $csp = new ContentSecurityPolicy();
        $csp->addAllowedFrameAncestorDomain('*');
        $response->setContentSecurityPolicy($csp);
        if (!$exists) {
            $response->throttle(['action' => 'pulseRoomCode']);
        }
        return $response;
    }

    /**
     * Einbett-Shell fürs Office-/PowerPoint-Add-in (Content-Add-in). Lädt office.js,
     * fragt einmalig den Raumcode ab (persistiert in den Dokument-Settings der .pptx)
     * und rahmt dann /screen/{code} same-origin ein. Muss von Office framebar sein
     * (frame-ancestors offen) und office.js aus Microsofts CDN laden dürfen — rein
     * lesende Shell, keine sensiblen NC-Inhalte. Der zweite Framing-Blocker
     * X-Frame-Options wird für diesen Pfad zusätzlich im Traefik entfernt
     * (dynamic_conf/http.routers.pulse-embed.yml).
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function embed(): TemplateResponse {
        // RENDER_AS_PUBLIC (nicht BLANK!): nur der Public-Layout bindet die per
        // addScript/addStyle registrierten Assets samt CSP-Nonce ein. BLANK gibt
        // ausschließlich den Template-Inhalt zurück (ohne <head> → office.js/JS
        // würden nie laden). Die NC-Gast-Chrome versteckt embed.css.
        $response = new TemplateResponse(
            Application::APP_ID,
            'embed',
            [],
            TemplateResponse::RENDER_AS_PUBLIC,
        );
        $csp = new ContentSecurityPolicy();
        $csp->addAllowedFrameAncestorDomain('*');
        // office.js + dessen dynamisch nachgeladene Ressourcen aus Microsofts CDN
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
