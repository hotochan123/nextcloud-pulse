<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

/**
 * The action does not fit the room's state (e.g. editing questions while
 * the self-paced quiz is open). The controller maps it to HTTP 409, only in
 * the moderator API; input errors stay
 * \InvalidArgumentException (400).
 */
class ConflictException extends \RuntimeException {
}
