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
    // Poll: 'choice' | 'words' | 'scale' · Quiz: 'choice' | 'truefalse' | 'multi' | 'number' | 'text'
    protected $type = '';
    protected $question = '';
    protected $options = '[]';  // JSON: [{"id":"AB12","label":"…"}]
    protected $maxWords = 3;
    protected $status = 'active'; // 'active' | 'locked' | 'ended'
    protected $position = 0;      // order in the deck (0-based)
    protected $correctOption = ''; // quiz choice/truefalse: option ID of the correct answer
    // Quiz, type-specific solution as JSON (server-side, never sent to participants before the reveal):
    //  multi   -> {"correct":["id1","id2"]}
    //  number  -> {"target":1969,"tolerance":2}
    //  text    -> {"accepted":["paris"],"rejected":["berlin"]}  (grows through moderator grading)
    protected $answerKey = null;
    // File name of the question image in the AppData area ('' = none), see PollImageService
    protected $image = '';
    protected $timeLimit = 0;      // quiz: seconds (0 = no limit)
    protected $startedAt = 0;      // quiz: when it was made active (Unix time)
    protected $createdAt = 0;

    public function __construct() {
        $this->addType('roomId', 'integer');
        $this->addType('maxWords', 'integer');
        $this->addType('position', 'integer');
        $this->addType('timeLimit', 'integer');
        $this->addType('startedAt', 'integer');
        $this->addType('createdAt', 'integer');
        // Every INSERT carries `options`. The migration declares it TEXT NOT NULL
        // DEFAULT '[]', but Doctrine drops defaults of TEXT columns on MySQL
        // (MariaDB, PostgreSQL and SQLite keep it). The generic setter skips a
        // value equal to the current one, so setOptions('[]') on a new poll is a
        // no-op: a word cloud, number or free-text question left the column out
        // of its INSERT and strict MySQL refused it (error 1364). fromRow()
        // clears this mark again, so loaded polls UPDATE only what changed.
        $this->markFieldUpdated('options');
    }

    /** Never expose the image file name as NULL (the column is nullable). */
    public function imageOrEmpty(): string {
        return (string)($this->getImage() ?? '');
    }

    /** @return list<array{id:string,label:string}> (choice/truefalse/multi) */
    public function getOptionsArray(): array {
        $decoded = json_decode($this->getOptions(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Type-specific solution as an array (multi/number/text); {} when empty/unset. */
    public function getAnswerKeyArray(): array {
        $decoded = json_decode((string)$this->getAnswerKey(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Matching (only for type 'match'); like the scale it lives in the options JSON:
     * the items on the left (fixed order), the targets on the right (the client shuffles
     * them). The solution is NOT stored here but in answerKey — otherwise it would
     * already be on every phone before the reveal.
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

    /** Scale config (only for type 'scale'); also lives in the options JSON.
     *  mode 'single' (1..X, histogram) or 'spectrum' (0..X per aspect, radar). */
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
     * Note: correctOption is NOT included — the correct answer must not reach
     * participants before the reveal. Moderator views attach it explicitly
     * via DeckService::ownerPoll().
     */
    public function jsonSerialize(): array {
        $isScale = $this->getType() === 'scale';
        $isMatch = $this->getType() === 'match';
        return [
            'id' => $this->getId(),
            'roomId' => $this->getRoomId(),
            'type' => $this->getType(),
            'question' => $this->getQuestion(),
            // Scale and matching use the options JSON differently from an
            // option list — they come out through their own field.
            'options' => ($isScale || $isMatch) ? [] : $this->getOptionsArray(),
            'scale' => $isScale ? $this->getScaleConfig() : null,
            'match' => $isMatch ? $this->getMatchConfig() : null,
            'maxWords' => $this->getMaxWords(),
            // Only the file name (= cache buster); the client builds the URL.
            'image' => $this->imageOrEmpty(),
            'status' => $this->getStatus(),
            'position' => $this->getPosition(),
            'timeLimit' => $this->getTimeLimit(),
            'startedAt' => $this->getStartedAt(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
