<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Quiz im eigenen Tempo: eine Zeile je (Frage, Voter-Token), die diese Person
 * erreicht hat. `startedAt` ist ihr persönlicher Start (Countdown/Tempo),
 * `leftAt` das Verlassen der Frage (0 = gerade offen). Bewusst ohne
 * JsonSerializable — der voter_token verlässt den Server nie.
 *
 * @method int getRoomId()
 * @method void setRoomId(int $roomId)
 * @method int getPollId()
 * @method void setPollId(int $pollId)
 * @method string getVoterToken()
 * @method void setVoterToken(string $voterToken)
 * @method int getSeq()
 * @method void setSeq(int $seq)
 * @method int getStartedAt()
 * @method void setStartedAt(int $startedAt)
 * @method int getLeftAt()
 * @method void setLeftAt(int $leftAt)
 */
class Progress extends Entity {
    protected $roomId = 0;
    protected $pollId = 0;
    protected $voterToken = '';
    protected $seq = 0;         // Index in der eingefrorenen Reihenfolge (0-basiert)
    protected $startedAt = 0;
    protected $leftAt = 0;

    public function __construct() {
        $this->addType('roomId', 'integer');
        $this->addType('pollId', 'integer');
        $this->addType('seq', 'integer');
        $this->addType('startedAt', 'integer');
        $this->addType('leftAt', 'integer');
    }
}
