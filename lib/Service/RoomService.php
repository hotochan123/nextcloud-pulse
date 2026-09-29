<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\PresenceMapper;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Db\VoteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Räume: anlegen, benennen, kopieren, leeren, löschen — plus Präsenz
 * („N dabei") und die „Meine Räume"-Liste. Alles, was den Raum als Ganzes
 * betrifft; die Fragen darin gehören dem DeckService.
 */
class RoomService {
    private const MODES = ['poll', 'quiz'];
    /**
     * Als "gerade dabei" zählt, wessen Heartbeat höchstens so viele Sekunden alt ist.
     * Öffentlich: „online" im Fortschritt (PaceStateService) nimmt dasselbe Fenster.
     */
    public const PRESENCE_WINDOW = 15;

    public function __construct(
        private RoomMapper $roomMapper,
        private PollMapper $pollMapper,
        private VoteMapper $voteMapper,
        private PresenceMapper $presenceMapper,
        private PlayerMapper $playerMapper,
        private ProgressMapper $progressMapper,
        private CodeGenerator $codeGenerator,
        private PollImageService $imageService,
        private ITimeFactory $timeFactory,
        private IL10N $l10n,
    ) {
    }

    // ── Räume ───────────────────────────────────────────────────────────────

    public function createRoom(string $uid, string $mode = 'poll', string $title = ''): Room {
        if (!in_array($mode, self::MODES, true)) {
            $mode = 'poll';
        }
        $room = new Room();
        $room->setCode($this->codeGenerator->uniqueRoomCode());
        $room->setTitle($this->sanitizeTitle($title));
        $room->setOwnerUid($uid);
        $room->setActivePollId(0);
        $room->setMode($mode);
        $room->setCreatedAt($this->timeFactory->getTime());
        return $this->roomMapper->insert($room);
    }

    /**
     * Raum per Code laden und Eigentümerschaft erzwingen.
     *
     * @throws DoesNotExistException Raum unbekannt
     * @throws NotOwnerException     Raum gehört einer anderen Person
     */
    public function getOwnedRoom(string $code, string $uid): Room {
        $room = $this->roomMapper->findByCode($code);
        if ($room->getOwnerUid() !== $uid) {
            throw new NotOwnerException();
        }
        return $room;
    }

    /**
     * Raum umbenennen. Leerer Titel ist erlaubt und bedeutet „kein Titel" —
     * die Oberfläche zeigt dann wieder nur den Code.
     */
    public function setTitle(Room $room, string $title): void {
        $room->setTitle($this->sanitizeTitle($title));
        $this->roomMapper->update($room);
    }

    /**
     * Titel säubern: Steuerzeichen (auch Zeilenumbrüche) raus, Rand-Leerraum weg,
     * auf 80 Zeichen kürzen — passend zur Spaltenbreite, mehrbyte-sicher.
     */
    private function sanitizeTitle(string $title): string {
        $clean = preg_replace('/[\p{C}]+/u', ' ', $title) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
        return mb_substr($clean, 0, 80);
    }

    /**
     * Raum als Vorlage kopieren: neuer Code, dieselben Fragen in derselben
     * Reihenfolge (inklusive Lösungen, Zeitlimits und der beim Freitext-Bewerten
     * gelernten accepted/rejected-Listen) — aber KEINE Stimmen, Teilnehmenden
     * oder Präsenz. Der Cursor der Kopie ruht, jede Frage startet neutral.
     *
     * `reveal_at_end` wandert mit (Ablauf-Entscheidung des Decks); `practice`
     * bewusst nicht — die Kopie ist der echte Durchlauf, nicht der Probelauf.
     * Ebenso das Format des eigenen Tempos (`pace`, `timed`, `feedback`) — es
     * beschreibt den nächsten Durchgang. Fenster, eingefrorene Reihenfolge,
     * Beitrittssperre und Besitzerbesuch nicht: die Kopie ist ein frischer Entwurf.
     */
    public function duplicateRoom(Room $src, string $uid): Room {
        $copy = $this->createRoom($uid, $src->getMode(), $this->copyTitle($src->titleOrEmpty()));
        // Nur schreiben, wenn etwas vom Default abweicht — ein moderierter Raum
        // mit Standard-Ablauf bekommt so kein zusätzliches UPDATE.
        $changed = false;
        if ($src->getRevealAtEnd()) {
            $copy->setRevealAtEnd(true);
            $changed = true;
        }
        if ($src->getPace() !== $copy->getPace()
            || $src->getTimed() !== $copy->getTimed()
            || $src->getFeedback() !== $copy->getFeedback()) {
            $copy->setPace($src->getPace());
            $copy->setTimed($src->getTimed());
            $copy->setFeedback($src->getFeedback());
            $changed = true;
        }
        if ($changed) {
            $this->roomMapper->update($copy);
        }
        $now = $this->timeFactory->getTime();
        foreach ($this->pollMapper->findByRoom($src->getId()) as $i => $poll) {
            $new = new Poll();
            $new->setRoomId($copy->getId());
            $new->setType($poll->getType());
            $new->setQuestion($poll->getQuestion());
            $new->setOptions($poll->getOptions());
            $new->setMaxWords($poll->getMaxWords());
            $new->setCorrectOption($poll->getCorrectOption());
            $new->setAnswerKey($poll->getAnswerKey());
            $new->setTimeLimit($poll->getTimeLimit());
            // Frisch: nichts läuft, nichts ist aufgelöst, Positionen lückenlos.
            $new->setStatus('active');
            $new->setStartedAt(0);
            $new->setPosition($i);
            $new->setCreatedAt($now);
            $new = $this->pollMapper->insert($new);
            // Bild bekommt eine eigene Datei — sonst nähme das Löschen des einen
            // Raums der Kopie das Bild weg.
            $this->imageService->copy($poll, $new);
        }
        return $copy;
    }

    /**
     * Titel der Kopie: „… (Kopie)". Der Suffix muss in die 80 Zeichen passen,
     * sonst schnitte sanitizeTitle ihn selbst an.
     */
    private function copyTitle(string $title): string {
        if ($title === '') {
            return '';
        }
        $suffix = ' ' . $this->l10n->t('(copy)');
        $room = 80 - mb_strlen($suffix);
        return mb_substr($title, 0, $room) . $suffix;
    }

    /**
     * Raum samt allem löschen. Die Reihenfolge schließt Wettläufe mit dem
     * eigenen Tempo, wo /join, /vote und /next ohne Transaktion um diesen
     * Aufruf herum schreiben:
     * - die Raumzeile zuerst: ein Beitritt, der sie gerade gesperrt hält
     *   (PaceService::locked), wird erst fertig und dann mitgelöscht; ein
     *   späterer findet keinen Raum mehr (404);
     * - Spieler vor Stimmen und Fortschritt: /vote und /next prüfen nach dem
     *   Schreiben, ob es den Spieler noch gibt (PaceService::assertStillJoined).
     *   Sieht die Prüfung ihn noch, trifft das Löschen danach das Geschriebene
     *   mit, sonst räumt sie selbst;
     * - die Bilddateien zuletzt: ein Speicherfehler lässt so keine Zeilen ohne
     *   Raum zurück, die niemand mehr fände.
     */
    public function deleteRoom(Room $room): void {
        $this->roomMapper->delete($room);
        $this->presenceMapper->deleteByRoom($room->getId());
        $this->playerMapper->deleteByRoom($room->getId());
        $polls = $this->pollMapper->findByRoom($room->getId());
        foreach ($polls as $poll) {
            $this->voteMapper->deleteByPoll($poll->getId());
            $this->pollMapper->delete($poll);
        }
        $this->progressMapper->deleteByRoom($room->getId());
        foreach ($polls as $poll) {
            $this->imageService->discard($poll);
        }
    }

    /**
     * Raum für einen frischen Durchlauf leeren: alle Stimmen (aller Fragen),
     * Teilnehmer/Nicknames, Präsenz und damit die Rangliste (leitet sich aus den
     * Stimmen ab) verwerfen. Die Fragen bleiben; der Cursor geht auf „ruht" und
     * jede Frage bekommt Timer/Status neutralisiert, damit nichts als „aufgelöst"
     * oder mit abgelaufener Zeit hängenbleibt.
     *
     * Im eigenen Tempo zusätzlich: Fortschritt aller Personen, Fenster,
     * eingefrorene Reihenfolge und Beitrittssperre — der Raum ist danach wieder
     * Entwurf. Das Format (`pace`, `timed`, `feedback`) und `touched_at`
     * (Aufbewahrung) bleiben. Den Fortschritt leert der Reset immer, auch bei
     * `pace='live'`: beim Umschalten self → live ist `pace` schon live, wenn
     * der Reset läuft.
     */
    public function resetRoom(Room $room): void {
        foreach ($this->pollMapper->findByRoom($room->getId()) as $poll) {
            $this->voteMapper->deleteByPoll($poll->getId());
            if ($poll->getStartedAt() !== 0 || $poll->getStatus() !== 'active') {
                $poll->setStartedAt(0);
                $poll->setStatus('active');
                $this->pollMapper->update($poll);
            }
        }
        $this->presenceMapper->deleteByRoom($room->getId());
        $this->playerMapper->deleteByRoom($room->getId());
        $this->progressMapper->deleteByRoom($room->getId());
        // Die Raumzeile nur schreiben, wenn wirklich etwas zurückzusetzen ist.
        // Ein moderierter Raum hat nie ein Fenster und bekommt so kein
        // zusätzliches UPDATE (wie bisher nur beim laufenden Cursor).
        $changed = false;
        if ($room->getActivePollId() !== 0) {
            $room->setActivePollId(0);
            $changed = true;
        }
        if ($room->getOpenedAt() !== 0 || $room->getClosesAt() !== 0
            || $room->getClosedAt() !== 0 || $room->getReleasedAt() !== 0
            || $room->getDeckOrder() !== null || $room->getJoinsLocked()) {
            $room->setOpenedAt(0);
            $room->setClosesAt(0);
            $room->setClosedAt(0);
            $room->setReleasedAt(0);
            $room->setDeckOrder(null);
            $room->setJoinsLocked(false);
            $changed = true;
        }
        if ($changed) {
            $this->roomMapper->update($room);
        }
    }

    /**
     * Probelauf ein-/ausschalten. Das Umschalten leert den Raum in beide
     * Richtungen (frischer Probelauf bzw. sauberer Echt-Start) — so kann man
     * beliebig oft testen, ohne dass Test-Stimmen in den echten Durchlauf lecken.
     * Solange Probelauf an ist, blendet der Server die Rangliste aus (siehe
     * results()/publicState()).
     */
    public function setPractice(Room $room, bool $on): void {
        $room->setPractice($on);
        // resetRoom persistiert den Raum (roomMapper->update) mit — auch wenn
        // gerade keine aktive Frage läuft, muss das practice-Flag geschrieben werden.
        $this->roomMapper->update($room);
        $this->resetRoom($room);
    }

    /**
     * Auflösung erst am Ende (statt je Frage) ein-/ausschalten. Reines Ablauf-/
     * Anzeige-Flag — leert NICHTS (anders als Probelauf), die Fragen laufen nur
     * ohne Zwischen-Auflösung durch. Wirkt in publicState()/results() (Reveal
     * unterdrücken) und im Presenter (kein „Auflösen", Timer-Ende schließt nur).
     */
    public function setRevealAtEnd(Room $room, bool $on): void {
        $room->setRevealAtEnd($on);
        $this->roomMapper->update($room);
    }

    /**
     * Quiz beenden: die gerade laufende Frage als „ended" markieren (Cursor
     * bleibt). Erst dieser Zustand gibt im „Auflösung am Ende"-Modus die
     * Ergebnisse + Rangliste für die Teilnehmer frei (siehe publicState()).
     */
    public function endQuiz(Room $room): void {
        $activeId = $room->getActivePollId();
        if ($activeId !== 0) {
            $this->pollMapper->markEnded($activeId);
        }
    }

    /**
     * Alte Räume samt Fragen/Stimmen/Spielern löschen. „Alt" = vor mehr als
     * $maxAgeSeconds angelegt UND seither kein Lebenszeichen im selben Fenster
     * (ein Raum, der gestern noch bespielt wurde, überlebt also, auch wenn er
     * vor Wochen angelegt wurde). Hält die Brute-Force-Angriffsfläche klein und
     * räumt die DB auf. Wird vom täglichen Hintergrund-Job gerufen.
     *
     * @return int Anzahl gelöschter Räume
     */
    public function cleanupStaleRooms(int $maxAgeSeconds): int {
        $cutoff = $this->timeFactory->getTime() - $maxAgeSeconds;
        $deleted = 0;
        foreach ($this->roomMapper->findOlderThan($cutoff) as $room) {
            if ($this->lastActivity($room) >= $cutoff) {
                continue; // kürzlich noch genutzt -> behalten
            }
            $this->deleteRoom($room);
            $deleted++;
        }
        return $deleted;
    }

    /**
     * Jüngstes Lebenszeichen eines Raums für die Aufbewahrung: der letzte
     * Teilnehmer-Heartbeat, im eigenen Tempo außerdem Öffnen, Frist, Schließen,
     * Freigabe und der letzte Besuch des Besitzers (`touched_at`). Die Frist
     * zählt, damit eine lange Hausaufgabe nicht vor ihrem Ende verschwindet;
     * Freigabe und Besitzerbesuch, damit die Lehrkraft auch Wochen nach der
     * Frist noch auswerten kann. Moderierte Räume haben all diese Felder auf 0 —
     * dort entscheidet wie bisher allein die Präsenz.
     */
    private function lastActivity(Room $room): int {
        return max(
            $room->getOpenedAt(),
            $room->getClosedAt(),
            $room->getClosesAt(),
            $room->getReleasedAt(),
            $room->getTouchedAt(),
            $this->presenceMapper->lastSeen($room->getId()),
        );
    }

    // ── Präsenz ───────────────────────────────────────────────────────────────

    /**
     * Heartbeat eines anonymen Teilnehmers. Wird bei jedem Teilnehmer-Poll
     * aufgerufen (auch ohne Stimme), damit "gerade dabei" auch Zuschauer zählt.
     */
    public function heartbeat(Room $room, string $voterToken): void {
        $this->presenceMapper->touch($room->getId(), $voterToken, $this->timeFactory->getTime());
    }

    /** Anzahl gerade aktiver Teilnehmer (Heartbeat innerhalb des Fensters). */
    public function presentCount(Room $room): int {
        $since = $this->timeFactory->getTime() - self::PRESENCE_WINDOW;
        return $this->presenceMapper->countActive($room->getId(), $since);
    }

    /**
     * Räume der vortragenden Person (neueste zuerst), je mit Fragenzahl und
     * ob gerade eine Frage aktiv ist — für die „Meine Räume"-Liste. Im eigenen
     * Tempo zusätzlich das Fenster (offen, Frist, freigegeben …), sonst
     * `window: null`.
     *
     * @return list<array{code:string, title:string, mode:string, createdAt:int, activePollId:int, pollCount:int, pace:string, window:?array}>
     */
    public function listRooms(string $uid): array {
        return array_map(function (Room $room): array {
            $pollCount = $this->pollMapper->countByRoom($room->getId());
            return [
                'code' => $room->getCode(),
                'title' => $room->titleOrEmpty(),
                'mode' => $room->getMode(),
                'createdAt' => $room->getCreatedAt(),
                'activePollId' => $room->getActivePollId(),
                'pollCount' => $pollCount,
                'pace' => $room->getPace(),
                'window' => PaceService::isSelf($room)
                    ? PaceService::windowView($room, $this->timeFactory->getTime(), $pollCount)
                    : null,
            ];
        }, $this->roomMapper->findByOwner($uid));
    }
}
