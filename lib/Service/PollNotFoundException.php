<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

/**
 * The question does not exist, or belongs to another room
 * (DeckService::requirePollInRoom).
 *
 * An InvalidArgumentException, so a caller that only knows those keeps
 * answering with its own status and the message; the moderator API's error
 * map (RoomApiController::withRoom) answers 404 instead.
 */
class PollNotFoundException extends \InvalidArgumentException {
}
