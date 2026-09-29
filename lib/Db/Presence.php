<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Anwesenheit eines anonymen Teilnehmers in einem Raum. Der voter_token stammt
 * aus demselben Cookie wie die Stimmen (siehe {@see Vote}) — er zählt Geräte,
 * nicht Personen, und identifiziert niemanden.
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
