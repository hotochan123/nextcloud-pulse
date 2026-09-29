<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One vote per (poll, voter token). The voter_token comes from an
 * anonymous cookie on the public participant page — it prevents
 * voting twice but does not identify a person (no link to a user).
 *
 * @method int getPollId()
 * @method void setPollId(int $pollId)
 * @method string getVoterToken()
 * @method void setVoterToken(string $voterToken)
 * @method string getPayload()
 * @method void setPayload(string $payload)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Vote extends Entity implements \JsonSerializable {
    protected $pollId = 0;
    protected $voterToken = '';
    protected $payload = '';   // JSON, structure depends on the question type
    protected $createdAt = 0;

    public function __construct() {
        $this->addType('pollId', 'integer');
        $this->addType('createdAt', 'integer');
    }

    /** @return mixed decoded payload.value (string for choice, list for words) */
    public function getValue() {
        $decoded = json_decode($this->getPayload(), true);
        return is_array($decoded) && array_key_exists('value', $decoded) ? $decoded['value'] : null;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'pollId' => $this->getPollId(),
            'value' => $this->getValue(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
