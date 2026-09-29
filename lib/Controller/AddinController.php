<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Controller;

use OCA\Pulse\AppInfo\Application;
use OCA\Pulse\Service\AddinManifest;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Gibt das Office-Manifest des PowerPoint-Add-ins heraus, gefüllt mit den
 * Adressen dieser Instanz (siehe AddinManifest).
 */
class AddinController extends Controller {
    public function __construct(
        IRequest $request,
        private AddinManifest $manifest,
        private IURLGenerator $urlGenerator,
        private IAppManager $appManager,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Kein Adminrecht nötig: das Manifest enthält nichts, was nicht ohnehin in
     * der Adresszeile steht. Wer eine Präsentation halten darf, soll das
     * Add-in einrichten können, ohne dafür Administration zu brauchen.
     *
     * NoCSRFRequired, weil die Datei direkt über einen Link geladen wird —
     * dabei schickt der Browser keinen Requesttoken.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function manifest(): DataDownloadResponse {
        // getAbsoluteURL/linkToRouteAbsolute nehmen den Host der laufenden
        // Anfrage (bzw. overwrite.cli.url). Das Manifest trägt damit genau die
        // Adresse, unter der die Person gerade angemeldet ist — erreicht der
        // Vortrags-Laptop diese Adresse, erreicht sie auch das Add-in.
        $origin = $this->manifest->origin($this->urlGenerator->getAbsoluteURL('/'));
        $embed = $this->urlGenerator->linkToRouteAbsolute('pulse.public.embed');
        $version = $this->appManager->getAppVersion(Application::APP_ID);

        return new DataDownloadResponse(
            $this->manifest->build($origin, $embed, $version),
            'pulse-addin.xml',
            'application/xml',
        );
    }
}
