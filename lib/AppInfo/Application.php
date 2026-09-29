<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\AppInfo;

use OCA\Pulse\SetupCheck\EmbedFraming;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'pulse';

    /** Name of the anonymous voter cookie on the public participant page. */
    public const VOTER_COOKIE = 'pulse_vt';

    /**
     * Protocol number between the server and the phone/projector bundle. Bump it
     * as soon as server and bundle no longer match; 1 = everything before
     * 0.19.0. `/state` carries it, and a tab running an older script from the
     * browser cache then reloads itself once (src/util/protocol.js holds the
     * same number — ProtocolConstantTest keeps the two in sync).
     * 3 = self-paced quiz: an older bundle showed such a room forever
     * as "Waiting for the next question …".
     */
    public const PROTOCOL = 3;

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // All dependencies are resolved via constructor autowiring;
        // only what Nextcloud cannot find on its own is registered by hand.
        $context->registerSetupCheck(EmbedFraming::class);
    }

    public function boot(IBootContext $context): void {
    }
}
