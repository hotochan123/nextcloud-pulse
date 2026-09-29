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

    /** Name des anonymen Voter-Cookies auf der öffentlichen Teilnehmer-Seite. */
    public const VOTER_COOKIE = 'pulse_vt';

    /**
     * Protokollnummer zwischen Server und Handy-/Beamer-Bundle. Hochzählen,
     * sobald Server und Bundle nicht mehr zueinander passen; 1 = alles vor
     * 0.19.0. `/state` trägt sie, und ein Tab mit älterem Skript aus dem
     * Browser-Cache lädt sich daraufhin einmal neu (src/util/protocol.js,
     * dort steht dieselbe Zahl — ProtocolConstantTest hält beide gleich).
     * 3 = Quiz im eigenen Tempo: ein Bundle von davor zeigte einen solchen
     * Raum für immer als „Waiting for the next question …".
     */
    public const PROTOCOL = 3;

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Alle Abhängigkeiten werden per Konstruktor-Autowiring aufgelöst;
        // manuell registriert wird nur, was Nextcloud von sich aus nicht findet.
        $context->registerSetupCheck(EmbedFraming::class);
    }

    public function boot(IBootContext $context): void {
    }
}
