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
 * Serves the Office manifest of the PowerPoint add-in, filled in with the
 * addresses of this instance (see AddinManifest).
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
     * No admin rights needed: the manifest contains nothing that is not in
     * the address bar anyway. Anyone allowed to give a presentation should be
     * able to set up the add-in without needing administration rights for it.
     *
     * NoCSRFRequired because the file is loaded directly through a link —
     * the browser sends no request token in that case.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function manifest(): DataDownloadResponse {
        // getAbsoluteURL/linkToRouteAbsolute use the host of the current
        // request (or overwrite.cli.url). So the manifest carries exactly the
        // address the person is currently logged in under — if the presenting
        // laptop reaches that address, it also reaches the add-in.
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
