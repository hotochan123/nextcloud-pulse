<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Db\VoteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Das Deck eines Raums: Fragen anlegen, bearbeiten, sortieren, löschen, den
 * Cursor setzen und die Eigentümer-Sicht darauf bauen. Enthält die gesamte
 * Eingabe-Validierung je Fragetyp (applyPollData + build*-Helfer) — hier landen
 * neue Fragetypen und neue Composer-Felder.
 * Rohe Client-Werte laufen durch Input: Listen, Objekte und Unzahlen gelten als
 * nicht gesendet.
 */
class DeckService {
    private const TYPES_POLL = ['choice', 'words', 'scale', 'rank', 'match'];
    private const TYPES_QUIZ = ['choice', 'truefalse', 'multi', 'number', 'text', 'rank', 'match'];
    /** Freitext: Obergrenze für eine Antwort (Zeichen). Auch der VoteService kürzt darauf. */
    public const TEXT_MAX = 100;
    private const MIN_OPTIONS = 2;
    private const MAX_OPTIONS = 8;
    /** Zeitlimit einer Quizfrage in Sekunden (Ober-/Untergrenze). */
    private const QUIZ_MIN_LIMIT = 5;
    private const QUIZ_MAX_LIMIT = 300;

    public function __construct(
        private RoomMapper $roomMapper,
        private PollMapper $pollMapper,
        private VoteMapper $voteMapper,
        private CodeGenerator $codeGenerator,
        private PollImageService $imageService,
        private ITimeFactory $timeFactory,
        private IL10N $l10n,
    ) {
    }

    // ── Umfragen ──────────────────────────────────────────────────────────────

    /**
     * Frage ans Ende des Decks hängen. Setzt sie NICHT aktiv — die vortragende
     * Person navigiert per setCurrent(). So lässt sich ein Deck vorab bauen.
     *
     * @param array{type?:string, question?:string, options?:array, maxWords?:int} $data
     * @throws \InvalidArgumentException bei ungültiger Eingabe
     */
    public function addPoll(Room $room, array $data): Poll {
        $poll = new Poll();
        $poll->setRoomId($room->getId());
        $poll->setStatus('active');
        $poll->setPosition($this->pollMapper->nextPosition($room->getId()));
        $poll->setCreatedAt($this->timeFactory->getTime());
        $this->applyPollData($poll, $data, $room->getMode() === 'quiz');
        return $this->pollMapper->insert($poll);
    }

    /**
     * Bestehende Frage bearbeiten. Da choice-Options neue IDs bekommen, werden
     * die bisherigen Stimmen dieser Frage verworfen (sonst zeigen sie ins Leere).
     *
     * @throws \InvalidArgumentException
     */
    public function updatePoll(Room $room, int $pollId, array $data): Poll {
        $poll = $this->requirePollInRoom($room, $pollId);
        $this->applyPollData($poll, $data, $room->getMode() === 'quiz');
        // Neuer Inhalt, Stimmen weg — dann gilt auch der alte Ablauf-Stand
        // nicht mehr. Bliebe 'locked'/startedAt stehen, stünde die geänderte
        // Frage samt Lösung sofort als „aufgelöst" in der Gesamtauswertung.
        // Ausnahmen, damit ein Tippfehler-Fix nichts im Saal umwirft:
        //  · 'ended' bleibt — das Quiz bleibt vorbei, der Saal im Endstand.
        //    (Die Stimmen dieser Frage gehen wie bei jeder Bearbeitung verloren,
        //    ihre Punkte also auch aus dem Endstand.)
        //  · die laufende Frage bleibt, wie sie ist; nur ein laufender
        //    Quiz-Timer startet neu (die Stimmen sind ja weg).
        $current = $room->getActivePollId() === $poll->getId();
        if (!$current && $poll->getStatus() !== 'ended') {
            $poll->setStatus('active');
            $poll->setStartedAt(0);
        } elseif ($current && $room->getMode() === 'quiz' && $poll->getStatus() === 'active') {
            $poll->setStartedAt($this->timeFactory->getTime());
        }
        $poll = $this->pollMapper->update($poll);
        $this->voteMapper->deleteByPoll($poll->getId());
        return $poll;
    }

    /**
     * Deck neu ordnen: Position = Index in der übergebenen ID-Liste.
     * Alle IDs müssen zum Raum gehören (sonst Abbruch vor Änderungen wäre schöner,
     * aber requirePollInRoom wirft je Fremd-ID -> Aufrufer schickt das echte Deck).
     *
     * @param array<mixed> $pollIds
     * @throws \InvalidArgumentException Fremd-ID oder keine Zahl im Deck
     */
    public function reorder(Room $room, array $pollIds): void {
        // Erst alle prüfen, dann schreiben -> keine Teiländerung bei Fremd-ID.
        // array_values: kommt statt der Liste ein JSON-Objekt, wären die Schlüssel
        // Text, und setPosition(int, int) bräche mit TypeError (500).
        $ids = [];
        foreach (array_values($pollIds) as $raw) {
            $id = Input::int($raw);
            if ($id === null) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid order.'));
            }
            $ids[] = $id;
        }
        foreach ($ids as $id) {
            $this->requirePollInRoom($room, $id);
        }
        foreach ($ids as $pos => $id) {
            $this->pollMapper->setPosition($id, $pos);
        }
    }

    /**
     * Validiert Typ/Frage/Optionen und schreibt sie in die Entity (ohne Persistenz).
     *
     * @throws \InvalidArgumentException
     */
    private function applyPollData(Poll $poll, array $data, bool $quiz = false): void {
        $type = Input::str($data['type'] ?? null);
        $allowed = $quiz ? self::TYPES_QUIZ : self::TYPES_POLL;
        if (!in_array($type, $allowed, true)) {
            throw new \InvalidArgumentException($quiz ? $this->l10n->t('Unknown quiz question type.') : $this->l10n->t('Unknown question type.'));
        }
        $question = trim(Input::str($data['question'] ?? null));
        if ($question === '') {
            throw new \InvalidArgumentException($this->l10n->t('The question must not be empty.'));
        }
        $poll->setType($type);
        $poll->setQuestion($question);
        // Typ-Felder erst neutralstellen, dann je Typ setzen.
        $poll->setCorrectOption('');
        $poll->setAnswerKey(null);
        $poll->setMaxWords(0);
        $poll->setOptions('[]');
        $poll->setTimeLimit(0);

        switch ($type) {
            case 'choice':
                $options = $this->buildOptions($data['options'] ?? []);
                $poll->setOptions(json_encode($options));
                if ($quiz) {
                    $idx = Input::int($data['correctIndex'] ?? null);
                    if ($idx === null || !isset($options[$idx])) {
                        throw new \InvalidArgumentException($this->l10n->t('Please mark the correct answer.'));
                    }
                    $poll->setCorrectOption($options[$idx]['id']);
                }
                break;
            case 'truefalse':
                // Beschriftungen werden mitgespeichert — sie stehen in der Sprache,
                // in der die Frage angelegt wurde.
                $options = $this->buildOptions([$this->l10n->t('True'), $this->l10n->t('False')]);
                $poll->setOptions(json_encode($options));
                $idx = Input::int($data['correctIndex'] ?? null);
                if ($idx !== 0 && $idx !== 1) {
                    throw new \InvalidArgumentException($this->l10n->t('Please mark either True or False as correct.'));
                }
                $poll->setCorrectOption($options[$idx]['id']);
                break;
            case 'multi':
                $options = $this->buildOptions($data['options'] ?? []);
                $poll->setOptions(json_encode($options));
                $correct = $this->pickCorrectIds($options, $data['correctIndexes'] ?? []);
                $poll->setAnswerKey(json_encode(['correct' => $correct]));
                break;
            case 'rank':
                // Reihenfolge: dieselben Optionen wie bei choice — die Eingabe-
                // reihenfolge im Composer IST im Quiz die richtige Lösung, deshalb
                // braucht es kein zusätzliches Feld. In der Umfrage gibt es keine
                // Lösung; dort zählt nur, wie das Publikum sortiert.
                $options = $this->buildOptions($data['options'] ?? []);
                $poll->setOptions(json_encode($options));
                if ($quiz) {
                    $poll->setAnswerKey(json_encode(['order' => array_column($options, 'id')]));
                }
                break;
            case 'match':
                // Zuordnung: die Paare aus dem Composer werden in zwei Listen
                // zerlegt (Items links, Ziele rechts). Im Quiz ist die Paarung
                // die Lösung; in der Umfrage zeigt sie nur, wie das Publikum
                // zuordnet — dort wird kein answerKey gespeichert.
                [$config, $map] = $this->buildMatch($data['pairs'] ?? []);
                $poll->setOptions(json_encode($config));
                if ($quiz) {
                    $poll->setAnswerKey(json_encode(['map' => $map]));
                }
                break;
            case 'number':
                [$target, $tol] = $this->buildNumberKey($data);
                $poll->setAnswerKey(json_encode(['target' => $target, 'tolerance' => $tol]));
                break;
            case 'text':
                $accepted = $this->buildAcceptedAnswers($data['answers'] ?? []);
                $poll->setAnswerKey(json_encode(['accepted' => $accepted, 'rejected' => []]));
                break;
            case 'scale':
                $poll->setOptions(json_encode($this->buildScale($data)));
                break;
            case 'words':
                $poll->setMaxWords(max(1, min(5, Input::int($data['maxWords'] ?? null) ?? 3)));
                break;
        }

        if ($quiz) {
            $limit = Input::int($data['timeLimit'] ?? null) ?? 30;
            $poll->setTimeLimit(max(self::QUIZ_MIN_LIMIT, min(self::QUIZ_MAX_LIMIT, $limit)));
        }
    }

    /**
     * Multi: aus einer Liste von Options-Indizes die zugehörigen Options-IDs
     * ziehen (mindestens eine, alle im gültigen Bereich).
     *
     * @param list<array{id:string,label:string}> $options
     * @return list<string>
     * @throws \InvalidArgumentException
     */
    private function pickCorrectIds(array $options, mixed $rawIndexes): array {
        if (!is_array($rawIndexes)) {
            $rawIndexes = [];
        }
        $ids = [];
        foreach ($rawIndexes as $raw) {
            $i = Input::int($raw);
            if ($i !== null && isset($options[$i])) {
                $ids[$options[$i]['id']] = true;
            }
        }
        $ids = array_keys($ids);
        if (count($ids) === 0) {
            throw new \InvalidArgumentException($this->l10n->t('Please mark at least one correct answer.'));
        }
        sort($ids);
        return $ids;
    }

    /**
     * Schätzfrage: Zielzahl (Pflicht) + Toleranz (≥ 0). Beide als Zahl gespeichert.
     *
     * @return array{0: int|float, 1: int|float}
     * @throws \InvalidArgumentException
     */
    private function buildNumberKey(array $data): array {
        // Endlich: „1e999“ wäre INF, und json_encode scheitert daran.
        $target = Input::number($data['target'] ?? null);
        if ($target === null) {
            throw new \InvalidArgumentException($this->l10n->t('Please enter a target number.'));
        }
        $tol = Input::number($data['tolerance'] ?? null);
        if ($tol === null || $tol < 0) {
            $tol = 0;
        }
        return [$target, abs($tol)];
    }

    /**
     * Freitext: die vom Autor vorgegebenen akzeptierten Antworten. Roh gespeichert
     * (für hübsche Anzeige), dedupliziert über die Normalform. Mindestens eine.
     *
     * @return list<string>
     * @throws \InvalidArgumentException
     */
    private function buildAcceptedAnswers(mixed $raw): array {
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            $raw = [];
        }
        $seen = [];
        $out = [];
        foreach ($raw as $entry) {
            $val = trim(Input::str($entry));
            if ($val === '') {
                continue;
            }
            $val = mb_substr($val, 0, self::TEXT_MAX);
            $norm = TallyService::normalizeText($val);
            if (isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;
            $out[] = $val;
        }
        if (count($out) === 0) {
            throw new \InvalidArgumentException($this->l10n->t('Please provide at least one correct answer.'));
        }
        return $out;
    }

    /**
     * Skalen-Config. Modus 'single' (min fix 1, scaleMax 2–20, Histogramm) oder
     * 'spectrum' (min fix 0, 3–8 Aspekte je 0..X, Radar).
     *
     * @return array
     */
    private function buildScale(array $data): array {
        $max = Input::int($data['scaleMax'] ?? null) ?? 5;
        $max = max(2, min(20, $max));
        $mode = $data['scaleMode'] ?? 'single';
        if (!in_array($mode, ['single', 'spectrum', 'compass'], true)) {
            $mode = 'single';
        }
        if ($mode === 'spectrum') {
            return [
                'mode' => 'spectrum',
                'min' => 0,
                'max' => $max,
                'aspects' => $this->buildAspects($data['aspects'] ?? []),
            ];
        }
        if ($mode === 'compass') {
            $range = max(2, min(20, Input::int($data['range'] ?? null) ?? 5));
            return [
                'mode' => 'compass',
                'range' => $range,
                'axisX' => $this->buildAxis($data['axisX'] ?? []),
                'axisY' => $this->buildAxis($data['axisY'] ?? []),
                'cornerLabels' => $this->buildCorners($data['cornerLabels'] ?? []),
                'heatmapThreshold' => max(2, min(9999, Input::int($data['heatmapThreshold'] ?? null) ?? 45)),
            ];
        }
        return [
            'mode' => 'single',
            'min' => 1,
            'max' => $max,
            'minLabel' => mb_substr(trim(Input::str($data['minLabel'] ?? null)), 0, 40),
            'maxLabel' => mb_substr(trim(Input::str($data['maxLabel'] ?? null)), 0, 40),
        ];
    }

    /**
     * Spektrum-Aspekte: 3–8 Einträge, je mit stabiler ID, Name (Pflicht) und
     * optionalen Pol-Labels. Leere Namen fallen raus; > 8 wird gekappt.
     *
     * @return list<array{id:string,label:string,poleLow:string,poleHigh:string}>
     * @throws \InvalidArgumentException
     */
    private function buildAspects(mixed $raw): array {
        if (!is_array($raw)) {
            $raw = [];
        }
        $out = [];
        foreach ($raw as $a) {
            if (!is_array($a)) {
                continue;
            }
            $label = mb_substr(trim(Input::str($a['label'] ?? null)), 0, 40);
            if ($label === '') {
                continue;
            }
            $out[] = [
                'id' => $this->codeGenerator->optionId(),
                'label' => $label,
                'poleLow' => mb_substr(trim(Input::str($a['poleLow'] ?? null)), 0, 40),
                'poleHigh' => mb_substr(trim(Input::str($a['poleHigh'] ?? null)), 0, 40),
            ];
            if (count($out) >= 8) {
                break;
            }
        }
        if (count($out) < 3) {
            throw new \InvalidArgumentException($this->l10n->t('Please name at least three aspects.'));
        }
        return $out;
    }

    /**
     * Kompass-Achse: Titel + zwei Pol-Labels, alle drei Pflicht.
     *
     * @return array{title:string,poleLow:string,poleHigh:string}
     * @throws \InvalidArgumentException
     */
    private function buildAxis(mixed $a): array {
        $a = is_array($a) ? $a : [];
        $title = mb_substr(trim(Input::str($a['title'] ?? null)), 0, 40);
        $lo = mb_substr(trim(Input::str($a['poleLow'] ?? null)), 0, 40);
        $hi = mb_substr(trim(Input::str($a['poleHigh'] ?? null)), 0, 40);
        if ($title === '' || $lo === '' || $hi === '') {
            throw new \InvalidArgumentException($this->l10n->t('Please give both axes a title and two pole labels each.'));
        }
        return ['title' => $title, 'poleLow' => $lo, 'poleHigh' => $hi];
    }

    /**
     * Kompass-Ecklabels: bis zu vier, optional. Reihenfolge
     * [links-unten, rechts-unten, links-oben, rechts-oben].
     *
     * @return list<string>
     */
    private function buildCorners(mixed $raw): array {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $c) {
            $out[] = mb_substr(trim(Input::str($c)), 0, 40);
            if (count($out) >= 4) {
                break;
            }
        }
        return $out;
    }

    /**
     * Cursor setzen: welche Frage ist gerade dran (0 = keine / Präsentation ruht).
     * Nur die aktuelle Frage nimmt Stimmen an (recordVote löst über active_poll_id auf).
     *
     * @throws \InvalidArgumentException Frage gehört nicht zum Raum
     */
    public function setCurrent(Room $room, int $pollId): void {
        if ($pollId !== 0) {
            $poll = $this->requirePollInRoom($room, $pollId);
            if ($room->getMode() === 'quiz') {
                // Timer (neu) starten und Frage öffnen — auch beim Zurückspringen
                // auf eine schon aufgelöste Frage läuft sie damit wieder an.
                $poll->setStartedAt($this->timeFactory->getTime());
                $poll->setStatus('active');
                $this->pollMapper->update($poll);
                // Ein neuer Lauf (oder ein Schritt zurück nach dem Endstand)
                // beendet das Ende: eine liegengebliebene 'ended'-Frage hieße
                // sonst weiter „Quiz vorbei" — bei „Auflösung am Ende" gäbe
                // die Gesamtauswertung dann ab Frage 1 jede Lösung frei.
                $this->pollMapper->unmarkEnded($room->getId(), $poll->getId());
            } elseif ($poll->getStartedAt() === 0) {
                // Umfrage: kein Timer, aber der Zeitpunkt markiert „wurde gezeigt".
                // Die Gesamtauswertung fürs Handy nimmt nur gezeigte Fragen auf
                // (StateService::wasShown) — sonst stünde das ganze Deck vorab darin.
                $poll->setStartedAt($this->timeFactory->getTime());
                $this->pollMapper->update($poll);
            }
        }
        $room->setActivePollId($pollId);
        $this->roomMapper->update($room);
    }

    public function deletePoll(Room $room, int $pollId): void {
        $poll = $this->requirePollInRoom($room, $pollId);
        $this->voteMapper->deleteByPoll($poll->getId());
        $this->imageService->discard($poll);
        $this->pollMapper->delete($poll);
        if ($room->getActivePollId() === $pollId) {
            $room->setActivePollId(0);
            $this->roomMapper->update($room);
        }
    }

    /** @return Poll[] geordnetes Deck */
    public function deck(Room $room): array {
        return $this->pollMapper->findByRoom($room->getId());
    }

    /**
     * Frage samt richtiger Antwort — nur für Eigentümer-Sichten (Deck-Editor,
     * Präsentation, Zusammenfassung). Teilnehmer bekommen correctOption erst
     * beim Auflösen (siehe publicState).
     */
    public function ownerPoll(Poll $poll): array {
        $data = $poll->jsonSerialize();
        $data['correctOption'] = $poll->getCorrectOption();
        $data['answerKey'] = $poll->getAnswerKeyArray();
        return $data;
    }

    /**
     * Raum inkl. Deck als serialisierbares Array (für GET /rooms/{code}). Im
     * eigenen Tempo samt Fenster (Zustand, Frist, Fragenzahl), sonst
     * `window: null`.
     */
    public function roomView(Room $room): array {
        $polls = $this->deck($room);
        $data = $room->jsonSerialize();
        $data['window'] = PaceService::isSelf($room)
            ? PaceService::windowView($room, $this->timeFactory->getTime(), count($polls))
            : null;
        $data['polls'] = array_map(
            fn (Poll $p) => $this->ownerPoll($p),
            $polls,
        );
        return $data;
    }

    public function resetPoll(Room $room, int $pollId): void {
        $poll = $this->requirePollInRoom($room, $pollId);
        $this->voteMapper->deleteByPoll($poll->getId());
    }

    /** Abstimmung pausieren (kein neues Abstimmen), Ergebnisse bleiben erhalten. */
    public function lockPoll(Room $room, int $pollId): void {
        $poll = $this->requirePollInRoom($room, $pollId);
        if ($poll->getStatus() === 'active') {
            $this->pollMapper->setStatus($poll->getId(), 'locked');
        }
    }

    public function unlockPoll(Room $room, int $pollId): void {
        $poll = $this->requirePollInRoom($room, $pollId);
        if ($poll->getStatus() === 'locked') {
            $this->pollMapper->setStatus($poll->getId(), 'active');
        }
    }

    // ── intern ──────────────────────────────────────────────────────────────

    /** @throws \InvalidArgumentException */
    public function requirePollInRoom(Room $room, int $pollId): Poll {
        try {
            $poll = $this->pollMapper->find($pollId);
        } catch (DoesNotExistException) {
            throw new \InvalidArgumentException($this->l10n->t('Question not found.'));
        }
        if ($poll->getRoomId() !== $room->getId()) {
            throw new \InvalidArgumentException($this->l10n->t('This question does not belong to this room.'));
        }
        return $poll;
    }

    /**
     * Zuordnung: aus den Paaren des Composers [{left, right}, …] die beiden
     * Listen bauen. Beide Seiten bekommen eigene IDs; die Lösung ist die
     * Abbildung item-ID -> ziel-ID. Gleiche Ziel-Beschriftungen werden NICHT
     * zusammengelegt: zwei Items dürfen auf dasselbe Ziel zeigen, dann steht
     * das Ziel eben zweimal in der Auswahl — sonst verriete die kürzere Liste
     * die Doppelung.
     *
     * @param mixed $raw Liste von {left, right}
     * @return array{0: array{items:list<array{id:string,label:string}>, targets:list<array{id:string,label:string}>}, 1: array<string,string>}
     * @throws \InvalidArgumentException
     */
    private function buildMatch(mixed $raw): array {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException($this->l10n->t('Please provide at least two pairs.'));
        }
        $pairs = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $left = trim(Input::str($entry['left'] ?? null));
            $right = trim(Input::str($entry['right'] ?? null));
            // Halbe Zeilen sind eine Falle: „links ohne rechts" ließe sich nicht
            // zuordnen, „rechts ohne links" wäre ein Ziel ohne Zweck.
            if ($left === '' xor $right === '') {
                throw new \InvalidArgumentException($this->l10n->t('Please fill in both sides of every pair.'));
            }
            if ($left !== '') {
                $pairs[] = [mb_substr($left, 0, self::TEXT_MAX), mb_substr($right, 0, self::TEXT_MAX)];
            }
        }
        if (count($pairs) < self::MIN_OPTIONS || count($pairs) > self::MAX_OPTIONS) {
            throw new \InvalidArgumentException($this->l10n->t('Please provide between %1$d and %2$d pairs.', [self::MIN_OPTIONS, self::MAX_OPTIONS]));
        }
        $items = [];
        $targets = [];
        $map = [];
        foreach ($pairs as [$left, $right]) {
            $itemId = $this->codeGenerator->optionId();
            $targetId = $this->codeGenerator->optionId();
            $items[] = ['id' => $itemId, 'label' => $left];
            $targets[] = ['id' => $targetId, 'label' => $right];
            $map[$itemId] = $targetId;
        }
        return [['items' => $items, 'targets' => $targets], $map];
    }

    /**
     * @param mixed $raw Liste von Labels (Strings)
     * @return list<array{id:string,label:string}>
     */
    private function buildOptions(mixed $raw): array {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException($this->l10n->t('Options are missing.'));
        }
        $labels = [];
        foreach ($raw as $entry) {
            // akzeptiert sowohl ["A","B"] als auch [{"label":"A"}, …]
            $label = trim(Input::str(is_array($entry) ? ($entry['label'] ?? null) : $entry));
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        if (count($labels) < self::MIN_OPTIONS || count($labels) > self::MAX_OPTIONS) {
            throw new \InvalidArgumentException($this->l10n->t('Please provide between %1$d and %2$d options.', [self::MIN_OPTIONS, self::MAX_OPTIONS]));
        }
        $options = [];
        foreach ($labels as $label) {
            $options[] = ['id' => $this->codeGenerator->optionId(), 'label' => $label];
        }
        return $options;
    }
}
