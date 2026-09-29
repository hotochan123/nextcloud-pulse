<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getRoomId()
 * @method void setRoomId(int $roomId)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getQuestion()
 * @method void setQuestion(string $question)
 * @method string getOptions()
 * @method void setOptions(string $options)
 * @method int getMaxWords()
 * @method void setMaxWords(int $maxWords)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getPosition()
 * @method void setPosition(int $position)
 * @method string getCorrectOption()
 * @method void setCorrectOption(string $correctOption)
 * @method ?string getAnswerKey()
 * @method void setAnswerKey(?string $answerKey)
 * @method ?string getImage()
 * @method void setImage(?string $image)
 * @method int getTimeLimit()
 * @method void setTimeLimit(int $timeLimit)
 * @method int getStartedAt()
 * @method void setStartedAt(int $startedAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Poll extends Entity implements \JsonSerializable {
    protected $roomId = 0;
    // Umfrage: 'choice' | 'words' | 'scale' · Quiz: 'choice' | 'truefalse' | 'multi' | 'number' | 'text'
    protected $type = '';
    protected $question = '';
    protected $options = '[]';  // JSON: [{"id":"AB12","label":"…"}]
    protected $maxWords = 3;
    protected $status = 'active'; // 'active' | 'locked' | 'ended'
    protected $position = 0;      // Reihenfolge im Deck (0-basiert)
    protected $correctOption = ''; // Quiz choice/truefalse: Options-ID der richtigen Antwort
    // Quiz, typ-abhängige Lösung als JSON (serverseitig, nie an Teilnehmer vor Auflösen):
    //  multi   -> {"correct":["id1","id2"]}
    //  number  -> {"target":1969,"tolerance":2}
    //  text    -> {"accepted":["paris"],"rejected":["berlin"]}  (wächst durch Moderator-Bewertung)
    protected $answerKey = null;
    // Dateiname des Fragebildes im AppData-Bereich ('' = keins), s. PollImageService
    protected $image = '';
    protected $timeLimit = 0;      // Quiz: Sekunden (0 = kein Limit)
    protected $startedAt = 0;      // Quiz: wann aktiv geschaltet (Unix-Zeit)
    protected $createdAt = 0;

    public function __construct() {
        $this->addType('roomId', 'integer');
        $this->addType('maxWords', 'integer');
        $this->addType('position', 'integer');
        $this->addType('timeLimit', 'integer');
        $this->addType('startedAt', 'integer');
        $this->addType('createdAt', 'integer');
    }

    /** Bild-Dateiname nie als NULL nach außen (Spalte ist nullable). */
    public function imageOrEmpty(): string {
        return (string)($this->getImage() ?? '');
    }

    /** @return list<array{id:string,label:string}> (choice/truefalse/multi) */
    public function getOptionsArray(): array {
        $decoded = json_decode($this->getOptions(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Typ-abhängige Lösung als Array (multi/number/text); {} wenn leer/ungesetzt. */
    public function getAnswerKeyArray(): array {
        $decoded = json_decode((string)$this->getAnswerKey(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Zuordnung (nur für type 'match'); liegt wie die Skala im options-JSON:
     * links die Items (feste Reihenfolge), rechts die Ziele (der Client mischt
     * sie). Die Lösung steht NICHT hier, sondern im answerKey — sonst läge sie
     * schon vor dem Auflösen auf jedem Handy.
     *
     * @return array{items:list<array{id:string,label:string}>, targets:list<array{id:string,label:string}>}
     */
    public function getMatchConfig(): array {
        $d = json_decode($this->getOptions(), true);
        $d = is_array($d) ? $d : [];
        $clean = static function (mixed $list): array {
            $out = [];
            foreach ((array)$list as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $out[] = ['id' => (string)($entry['id'] ?? ''), 'label' => (string)($entry['label'] ?? '')];
            }
            return $out;
        };
        return ['items' => $clean($d['items'] ?? []), 'targets' => $clean($d['targets'] ?? [])];
    }

    /** Skalen-Config (nur für type 'scale'); liegt ebenfalls im options-JSON.
     *  mode 'single' (1..X, Histogramm) oder 'spectrum' (0..X je Aspekt, Radar). */
    public function getScaleConfig(): array {
        $d = json_decode($this->getOptions(), true);
        if (!is_array($d)) {
            $d = [];
        }
        $mode = $d['mode'] ?? 'single';
        if (!in_array($mode, ['single', 'spectrum', 'compass'], true)) {
            $mode = 'single';
        }
        $cfg = [
            'mode' => $mode,
            'min' => (int)($d['min'] ?? ($mode === 'spectrum' ? 0 : 1)),
            'max' => (int)($d['max'] ?? 5),
            'minLabel' => (string)($d['minLabel'] ?? ''),
            'maxLabel' => (string)($d['maxLabel'] ?? ''),
        ];
        if ($mode === 'spectrum') {
            $aspects = [];
            foreach ((array)($d['aspects'] ?? []) as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $aspects[] = [
                    'id' => (string)($a['id'] ?? ''),
                    'label' => (string)($a['label'] ?? ''),
                    'poleLow' => (string)($a['poleLow'] ?? ''),
                    'poleHigh' => (string)($a['poleHigh'] ?? ''),
                ];
            }
            $cfg['aspects'] = $aspects;
        }
        if ($mode === 'compass') {
            $range = (int)($d['range'] ?? 5);
            $ax = static function ($a): array {
                $a = is_array($a) ? $a : [];
                return [
                    'title' => (string)($a['title'] ?? ''),
                    'poleLow' => (string)($a['poleLow'] ?? ''),
                    'poleHigh' => (string)($a['poleHigh'] ?? ''),
                ];
            };
            $corners = [];
            foreach ((array)($d['cornerLabels'] ?? []) as $c) {
                $corners[] = (string)$c;
            }
            $cfg['range'] = $range;
            $cfg['min'] = -$range;
            $cfg['max'] = $range;
            $cfg['axisX'] = $ax($d['axisX'] ?? []);
            $cfg['axisY'] = $ax($d['axisY'] ?? []);
            $cfg['cornerLabels'] = $corners;
            $cfg['heatmapThreshold'] = (int)($d['heatmapThreshold'] ?? 45);
        }
        return $cfg;
    }

    /**
     * Achtung: correctOption ist NICHT enthalten — die richtige Antwort darf
     * Teilnehmern nicht vor dem Auflösen zufließen. Moderator-Sichten hängen
     * sie über DeckService::ownerPoll() explizit an.
     */
    public function jsonSerialize(): array {
        $isScale = $this->getType() === 'scale';
        $isMatch = $this->getType() === 'match';
        return [
            'id' => $this->getId(),
            'roomId' => $this->getRoomId(),
            'type' => $this->getType(),
            'question' => $this->getQuestion(),
            // Skala und Zuordnung belegen das options-JSON anders als eine
            // Optionsliste — sie kommen über ihr eigenes Feld heraus.
            'options' => ($isScale || $isMatch) ? [] : $this->getOptionsArray(),
            'scale' => $isScale ? $this->getScaleConfig() : null,
            'match' => $isMatch ? $this->getMatchConfig() : null,
            'maxWords' => $this->getMaxWords(),
            // Nur der Dateiname (= Cache-Buster); die URL baut der Client.
            'image' => $this->imageOrEmpty(),
            'status' => $this->getStatus(),
            'position' => $this->getPosition(),
            'timeLimit' => $this->getTimeLimit(),
            'startedAt' => $this->getStartedAt(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
