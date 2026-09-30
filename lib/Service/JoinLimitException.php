<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

/**
 * Self-paced join refused because this address has already added
 * PaceService::NEW_PLAYER_LIMIT new players to the room within
 * PaceService::JOIN_PERIOD (VoteService::countNewPlayer).
 *
 * An InvalidArgumentException, so a caller that only knows those still
 * answers 400 with the message; the join endpoint can answer 429 instead.
 */
class JoinLimitException extends \InvalidArgumentException {
}
