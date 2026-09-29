<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getCode()
 * @method void setCode(string $code)
 * @method ?string getTitle()
 * @method void setTitle(?string $title)
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $ownerUid)
 * @method int getActivePollId()
 * @method void setActivePollId(int $activePollId)
 * @method string getMode()
 * @method void setMode(string $mode)
 * @method bool getPractice()
 * @method void setPractice(bool $practice)
 * @method bool getRevealAtEnd()
 * @method void setRevealAtEnd(bool $revealAtEnd)
 * @method string getPace()
 * @method void setPace(string $pace)
 * @method int getOpenedAt()
 * @method void setOpenedAt(int $openedAt)
 * @method int getClosesAt()
 * @method void setClosesAt(int $closesAt)
 * @method int getClosedAt()
 * @method void setClosedAt(int $closedAt)
 * @method int getReleasedAt()
 * @method void setReleasedAt(int $releasedAt)
 * @method bool getTimed()
 * @method void setTimed(bool $timed)
 * @method string getFeedback()
 * @method void setFeedback(string $feedback)
 * @method ?string getDeckOrder()
 * @method void setDeckOrder(?string $deckOrder)
 * @method bool getJoinsLocked()
 * @method void setJoinsLocked(bool $joinsLocked)
 * @method int getTouchedAt()
 * @method void setTouchedAt(int $touchedAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Room extends Entity implements \JsonSerializable {
    protected $code = '';
    protected $title = '';      // free-form room name, '' = none (code only)
    protected $ownerUid = '';
    protected $activePollId = 0;
    protected $mode = 'poll';   // 'poll' | 'quiz'
    protected $practice = false; // practice run: votes do not count towards the leaderboard
    protected $revealAtEnd = false; // quiz: reveal only at the end instead of after each question
    // self-paced quiz (see migration 20260927120000); timestamp 0 = has not happened yet
    protected $pace = 'live';   // 'live' (moderated) | 'self' (self-paced)
    protected $openedAt = 0;    // window first opened (0 = draft)
    protected $closesAt = 0;    // deadline (0 = open until closed manually)
    protected $closedAt = 0;    // closed manually
    protected $releasedAt = 0;  // solutions released
    protected $timed = true;    // time limits + speed points in the window
    protected $feedback = 'each'; // 'each' | 'end'
    protected $deckOrder = null;  // frozen order, JSON [12,15,13]; null in draft
    protected $joinsLocked = false; // "Lock joining"
    protected $touchedAt = 0;   // owner's last activity, any room mode (retention)
    protected $createdAt = 0;

    public function __construct() {
        $this->addType('activePollId', 'integer');
        $this->addType('practice', 'boolean');
        $this->addType('revealAtEnd', 'boolean');
        $this->addType('openedAt', 'integer');
        $this->addType('closesAt', 'integer');
        $this->addType('closedAt', 'integer');
        $this->addType('releasedAt', 'integer');
        $this->addType('timed', 'boolean');
        $this->addType('joinsLocked', 'boolean');
        $this->addType('touchedAt', 'integer');
        $this->addType('createdAt', 'integer');
    }

    /** Never expose the title as NULL (the column is nullable, see the migration). */
    public function titleOrEmpty(): string {
        return (string)($this->getTitle() ?? '');
    }

    /**
     * Only for the moderator JSON (create/show a room); participants and the projector
     * get their own narrow views. deckOrder/touchedAt stay out.
     */
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'code' => $this->getCode(),
            'title' => $this->titleOrEmpty(),
            'activePollId' => $this->getActivePollId(),
            'mode' => $this->getMode(),
            'practice' => $this->getPractice(),
            'revealAtEnd' => $this->getRevealAtEnd(),
            'pace' => $this->getPace(),
            'timed' => $this->getTimed(),
            'feedback' => $this->getFeedback(),
            'openedAt' => $this->getOpenedAt(),
            'closesAt' => $this->getClosesAt(),
            'closedAt' => $this->getClosedAt(),
            'releasedAt' => $this->getReleasedAt(),
            'joinsLocked' => $this->getJoinsLocked(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
