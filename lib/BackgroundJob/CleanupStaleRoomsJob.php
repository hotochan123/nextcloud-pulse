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
 * Deletes, once a day, rooms that nobody has used for more than RETENTION_DAYS.
 * Rooms are session artefacts (one talk, one meeting) and should not live
 * forever: that would keep old codes valid (brute-force surface) and fill the DB.
 * "Used" means: a participant heartbeat, any request of the owner on the room
 * (opening it, editing the deck, presenting — in every room mode), and in
 * self-paced mode also opening, deadline, closing and release; so a deck
 * prepared weeks ahead survives, and so does a homework assignment with a
 * deadline on day 0 that is only evaluated on day 32.
 * The actual logic lives in RoomService::cleanupStaleRooms (testable).
 *
 * Registered through <background-jobs> in appinfo/info.xml (fresh installs
 * and every app update); migration Version000000Date20260717000000 adds it
 * as well, for installs that predate that entry. On an install where it has
 * never run, Version000000Date20260929120000 lets the existing rooms count
 * as used on the day of the update, so its first run deletes none of them.
 */
class CleanupStaleRoomsJob extends TimedJob {
    /** Rooms unused for this many days are removed. */
    private const RETENTION_DAYS = 30;

    public function __construct(
        ITimeFactory $time,
        private RoomService $roomService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 60 * 60);
        // Not time-critical: may run batched/delayed.
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $deleted = $this->roomService->cleanupStaleRooms(self::RETENTION_DAYS * 24 * 60 * 60);
        if ($deleted > 0) {
            $this->logger->info('Pulse: cleaned up {count} stale rooms.', ['count' => $deleted]);
        }
    }
}
