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
    protected $title = '';      // frei wählbarer Raumname, '' = keiner (nur Code)
    protected $ownerUid = '';
    protected $activePollId = 0;
    protected $mode = 'poll';   // 'poll' | 'quiz'
    protected $practice = false; // Probelauf: Stimmen zählen nicht in die Rangliste
    protected $revealAtEnd = false; // Quiz: Auflösung erst am Ende statt je Frage
    // Quiz im eigenen Tempo (s. Migration 20260927120000); Zeitstempel 0 = noch nicht passiert
    protected $pace = 'live';   // 'live' (moderiert) | 'self' (eigenes Tempo)
    protected $openedAt = 0;    // Fenster zuerst geöffnet (0 = Entwurf)
    protected $closesAt = 0;    // Frist (0 = offen bis manuell geschlossen)
    protected $closedAt = 0;    // manuell geschlossen
    protected $releasedAt = 0;  // Lösungen freigegeben
    protected $timed = true;    // Zeitlimits + Tempopunkte im Fenster
    protected $feedback = 'each'; // 'each' | 'end'
    protected $deckOrder = null;  // eingefrorene Reihenfolge, JSON [12,15,13]; null im Entwurf
    protected $joinsLocked = false; // „Beitritt sperren"
    protected $touchedAt = 0;   // letzter Besuch des Besitzers (Aufbewahrung)
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

    /** Titel nie als NULL nach außen (Spalte ist nullable, s. Migration). */
    public function titleOrEmpty(): string {
        return (string)($this->getTitle() ?? '');
    }

    /**
     * Nur fürs Moderator-JSON (Raum anlegen/anzeigen); Teilnehmende und Beamer
     * bekommen eigene, schmale Sichten. deckOrder/touchedAt bleiben draußen.
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
