<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\PulseFakeAi\AppInfo;

use OCA\PulseFakeAi\FakeProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/** Test-only app of dev/e2e: registers FakeProvider for core:text2text. */
class Application extends App implements IBootstrap {
    public function __construct() {
        parent::__construct('pulse_fakeai');
    }

    public function register(IRegistrationContext $context): void {
        $context->registerTaskProcessingProvider(FakeProvider::class);
    }

    public function boot(IBootContext $context): void {
    }
}
