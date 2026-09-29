<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\BackgroundJob;

use OCA\Pulse\Service\RoomService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Löscht täglich Räume, die seit über RETENTION_DAYS niemand mehr genutzt hat.
 * Räume sind Session-Artefakte (ein Vortrag, ein Meeting) — sie sollen nicht
 * ewig leben: das hielte alte Codes gültig (Brute-Force-Fläche) und die DB voll.
 * „Genutzt" heißt: Teilnehmer-Heartbeat, im eigenen Tempo auch Öffnen, Frist,
 * Schließen, Freigabe oder ein Besuch des Besitzers — eine Hausaufgabe mit
 * Frist Tag 0, die erst an Tag 32 ausgewertet wird, überlebt so.
 * Die eigentliche Logik liegt in RoomService::cleanupStaleRooms (testbar).
 */
class CleanupStaleRoomsJob extends TimedJob {
    /** Räume ohne Nutzung seit so vielen Tagen werden entfernt. */
    private const RETENTION_DAYS = 30;

    public function __construct(
        ITimeFactory $time,
        private RoomService $roomService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 60 * 60);
        // Nicht zeitkritisch: darf gebündelt/verzögert laufen.
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $deleted = $this->roomService->cleanupStaleRooms(self::RETENTION_DAYS * 24 * 60 * 60);
        if ($deleted > 0) {
            $this->logger->info('Pulse: cleaned up {count} stale rooms.', ['count' => $deleted]);
        }
    }
}
