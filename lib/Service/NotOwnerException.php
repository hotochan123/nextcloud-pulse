<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

/**
 * A room was accessed by someone who does not own it.
 * RoomApiController reports it exactly like an unknown code: 404 "Room not
 * found." (security review L5).
 */
class NotOwnerException extends \RuntimeException {
}
