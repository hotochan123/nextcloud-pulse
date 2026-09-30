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
 *
 * Public routes never throw it: there the same kind of refusal is an
 * \InvalidArgumentException and answers 400 (PaceService::next in a room that
 * is not self-paced, while the moderator's /pace and /progress answer 409).
 * The public controllers have no catch for it, so it would be a 500 there.
 */
class ConflictException extends \RuntimeException {
}
