<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Listener;

use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Service\RoomService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * A deleted Nextcloud account takes its rooms with it. Each room goes through
 * RoomService::deleteRoom, so questions, votes, players, presence, progress
 * and question images go as well, and the public room code stops working.
 * Otherwise the rooms would stay reachable under their codes with nobody
 * left to manage them, and a new account that later got the same user ID
 * would take them over.
 *
 * Runs after the account is gone (UserDeletedEvent). Nothing may escape from
 * here: an exception would abort Nextcloud's own clean-up of the account
 * halfway. A room that cannot be deleted is logged and skipped; the others
 * still go. deleteRoom removes the rows in one transaction (retried on a
 * deadlock), so a skipped room stays complete rather than half deleted.
 *
 * This listener only runs while Pulse is enabled. The daily cleanup job
 * catches what it misses — a skipped room, or the rooms of an account
 * deleted while Pulse was disabled: it deletes the rooms of every owner
 * that no longer exists, however recently participants polled them
 * (CleanupStaleRoomsJob::ownerGone, RoomService::cleanupOrphanedRooms).
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
    public function __construct(
        private RoomMapper $roomMapper,
        private RoomService $roomService,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof UserDeletedEvent)) {
            return;
        }
        $uid = $event->getUid();
        try {
            $rooms = $this->roomMapper->findByOwner($uid);
        } catch (\Throwable $e) {
            $this->logger->warning('Pulse: could not look up the rooms of deleted user {uid}.', [
                'uid' => $uid,
                'exception' => $e,
            ]);
            return;
        }
        $deleted = 0;
        foreach ($rooms as $room) {
            try {
                $this->roomService->deleteRoom($room);
                $deleted++;
            } catch (\Throwable $e) {
                $this->logger->warning('Pulse: could not delete room {code} of deleted user {uid}.', [
                    'code' => $room->getCode(),
                    'uid' => $uid,
                    'exception' => $e,
                ]);
            }
        }
        // Like the cleanup job: only a deletion is worth a line; most
        // accounts never had a room.
        if ($deleted > 0) {
            $this->logger->info('Pulse: deleted {count} rooms of deleted user {uid}.', [
                'count' => $deleted,
                'uid' => $uid,
            ]);
        }
    }
}
