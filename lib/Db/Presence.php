<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Presence of an anonymous participant in a room. The voter_token comes
 * from the same cookie as the votes (see {@see Vote}) — it counts devices,
 * not people, and identifies nobody.
 *
 * @method int getRoomId()
 * @method void setRoomId(int $roomId)
 * @method string getVoterToken()
 * @method void setVoterToken(string $voterToken)
 * @method int getLastSeen()
 * @method void setLastSeen(int $lastSeen)
 */
class Presence extends Entity {
    protected $roomId = 0;
    protected $voterToken = '';
    protected $lastSeen = 0;

    public function __construct() {
        $this->addType('roomId', 'integer');
        $this->addType('lastSeen', 'integer');
    }
}
