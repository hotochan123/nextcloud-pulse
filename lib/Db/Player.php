<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A quiz player: nickname per (room, voter token). The token comes from the
 * anonymous cookie — it does not identify a Nextcloud person but recognises
 * the same browser again. The points are NOT kept here but computed from
 * the votes (payload); that way everything stays consistent after reset/delete.
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

    /** Deliberately WITHOUT voter_token — that is this person's cookie secret. */
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'nickname' => $this->getNickname(),
        ];
    }
}
