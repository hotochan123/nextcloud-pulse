<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\BackgroundJob;

use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\RoomService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Deletes, once a day, rooms that nobody has used for more than RETENTION_DAYS.
 * Rooms are session artefacts (one talk, one meeting) and should not live
 * forever: that would keep old codes valid (brute-force surface) and fill the DB.
 * "Used" means: a participant heartbeat, any request of the owner on the room
 * (opening it, editing the deck, presenting — in every room mode), and in
 * self-paced mode also opening, deadline, closing and release; so a deck
 * prepared weeks ahead survives, and so does a homework assignment with a
 * deadline on day 0 that is only evaluated on day 32. Heartbeats count only
 * while the owner has been there within RoomService::PRESENCE_NEEDS_OWNER_DAYS,
 * so polling phones alone cannot keep a room for ever.
 * The actual logic lives in RoomService::cleanupStaleRooms (testable).
 *
 * Two more sweeps in the same run:
 * - rooms of accounts that no longer exist (ownerGone), whatever their
 *   presence says — left behind when an account was deleted while Pulse was
 *   disabled, and otherwise inherited by a new account with the same user ID;
 * - image files that no question references (PollImageService::sweepOrphans).
 * Each step runs on its own: one that fails is logged and the next one
 * still runs.
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
    /**
     * Image files younger than this are never swept: an upload writes its
     * file a moment before the question row points to it.
     */
    private const ORPHAN_FILE_MIN_AGE = 60 * 60;

    public function __construct(
        ITimeFactory $time,
        private RoomService $roomService,
        private PollImageService $imageService,
        private IUserManager $userManager,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 60 * 60);
        // Not time-critical: may run batched/delayed.
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $this->step('stale rooms', function (): void {
            $deleted = $this->roomService->cleanupStaleRooms(self::RETENTION_DAYS * 24 * 60 * 60);
            if ($deleted > 0) {
                $this->logger->info('Pulse: cleaned up {count} stale rooms.', ['count' => $deleted]);
            }
        });
        $this->step('rooms of deleted accounts', function (): void {
            $deleted = $this->roomService->cleanupOrphanedRooms(fn (string $uid): bool => $this->ownerGone($uid));
            if ($deleted > 0) {
                $this->logger->info('Pulse: deleted {count} rooms of accounts that no longer exist.', ['count' => $deleted]);
            }
        });
        $this->step('orphaned images', function (): void {
            $deleted = $this->imageService->sweepOrphans(self::ORPHAN_FILE_MIN_AGE);
            if ($deleted > 0) {
                $this->logger->info('Pulse: removed {count} unreferenced question images.', ['count' => $deleted]);
            }
        });
    }

    /**
     * Has this account been deleted for good? Two conditions, because a
     * wrong "yes" deletes every room of a real person:
     * - no user backend knows the ID (IUserManager::userExists), and
     * - Nextcloud holds no login record for it. Deleting an account wipes
     *   all its preferences, `login/lastLogin` included; an account whose
     *   backend is merely unavailable — the OIDC or LDAP app disabled for
     *   an upgrade, a directory that does not answer — keeps them, and so
     *   keeps its rooms. Rooms can only be created after a login, so every
     *   real owner has that record.
     */
    public function ownerGone(string $uid): bool {
        if ($uid !== '' && $this->userManager->userExists($uid)) {
            return false;
        }
        return $uid === '' || $this->config->getUserValue($uid, 'login', 'lastLogin', '') === '';
    }

    private function step(string $name, callable $fn): void {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->logger->warning('Pulse: cleanup step "{step}" failed.', ['step' => $name, 'exception' => $e]);
        }
    }
}
