<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Ein Quiz-Spieler: Nickname je (Raum, Voter-Token). Der Token stammt aus dem
 * anonymen Cookie — er identifiziert keine Nextcloud-Person, sondern erkennt
 * denselben Browser wieder. Die Punkte werden NICHT hier gehalten, sondern aus
 * den Stimmen (payload) berechnet; so bleibt alles nach Reset/Löschen konsistent.
 *
 * @method int getRoomId()
 * @method void setRoomId(int $roomId)
 * @method string getVoterToken()
 * @method void setVoterToken(string $voterToken)
 * @method string getNickname()
 * @method void setNickname(string $nickname)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Player extends Entity implements \JsonSerializable {
    protected $roomId = 0;
    protected $voterToken = '';
    protected $nickname = '';
    protected $createdAt = 0;

    public function __construct() {
        $this->addType('roomId', 'integer');
        $this->addType('createdAt', 'integer');
    }

    /** Bewusst OHNE voter_token — der ist das Cookie-Geheimnis dieser Person. */
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'nickname' => $this->getNickname(),
        ];
    }
}
