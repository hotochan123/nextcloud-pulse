<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\PresenceMapper;
use OCA\Pulse\Db\Progress;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Db\VoteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Quiz im eigenen Tempo: Jede Person läuft allein durch das beim Öffnen
 * eingefrorene Deck, ihre Uhr startet je Frage erst mit /next. Der Moderator
 * steuert keinen Cursor mehr, sondern nur das Fenster (öffnen, schließen,
 * verlängern, freigeben) — ohne Frist ein „Rennen", mit Frist eine
 * „Hausaufgabe".
 *
 * Zwei Hälften:
 * - statisch und ohne DI: Zustand ableiten, Reihenfolge lesen, Endgültigkeit.
 *   Das brauchen auch Pfade, die im moderierten Betrieb durchlaufen (sie
 *   verzweigen über isSelf), und die Reflection-Tests der bestehenden Dienste
 *   müssen so nichts zusätzlich einspritzen.
 * - Instanz: Fenster-Aktionen und Wächter unter der Raumsperre (locked()),
 *   dazu /next.
 *
 * Der Fensterzustand steht nirgends, er wird aus den Zeitstempeln abgeleitet
 * (deriveState) — eine ablaufende Frist braucht keinen Hintergrund-Job.
 */
class PaceService {
    use TTransactional;

    public const PACE_LIVE = 'live';
    public const PACE_SELF = 'self';
    public const FEEDBACK_EACH = 'each';
    public const FEEDBACK_END = 'end';
    public const STATE_DRAFT = 'draft';
    public const STATE_OPEN = 'open';
    public const STATE_CLOSED = 'closed';
    public const STATE_RELEASED = 'released';
    /** Frist frühestens so weit in der Zukunft (kürzer ist fast immer ein Tippfehler). */
    public const MIN_LEAD = 60;
    /** Frist höchstens so weit in der Zukunft. */
    public const MAX_LEAD = 30 * 86400;
    /** Höchstzahl Spieler je Raum im eigenen Tempo (Spam-Deckel beim Beitritt). */
    public const MAX_PLAYERS = 300;
    /** Beitritte je IP und Raum im eigenen Tempo: 120 in 10 min. */
    public const JOIN_LIMIT = 120;
    public const JOIN_PERIOD = 600;

    public function __construct(
        private RoomMapper $roomMapper,
        private PollMapper $pollMapper,
        private PlayerMapper $playerMapper,
        private ProgressMapper $progressMapper,
        private VoteMapper $voteMapper,          // /next-Vorschausperre, removePlayer
        private PresenceMapper $presenceMapper,  // removePlayer
        private RoomService $roomService,
        private IDBConnection $db,               // locked()
        private ITimeFactory $timeFactory,
        private IL10N $l10n,
    ) {
    }

    // ── Reine Funktionen ────────────────────────────────────────────────────

    /** Läuft der Raum im eigenen Tempo? Nur Quiz-Räume können das. */
    public static function isSelf(Room $room): bool {
        return $room->getMode() === 'quiz' && $room->getPace() === self::PACE_SELF;
    }

    /**
     * Fensterzustand aus den Zeitstempeln. Die Freigabe schlägt alles; in der
     * Sekunde closes_at ist das Fenster schon zu (dieselbe Grenze überall).
     */
    public static function deriveState(Room $room, int $now): string {
        if ($room->getReleasedAt() > 0) {
            return self::STATE_RELEASED;
        }
        if ($room->getOpenedAt() === 0) {
            return self::STATE_DRAFT;
        }
        if (self::effectiveClosedAt($room, $now) > 0) {
            return self::STATE_CLOSED;
        }
        return self::STATE_OPEN;
    }

    /**
     * Wann das Fenster zuging: manuell (closed_at) oder durch die abgelaufene
     * Frist. 0 = noch nicht zu.
     */
    public static function effectiveClosedAt(Room $room, int $now): int {
        if ($room->getClosedAt() > 0) {
            return $room->getClosedAt();
        }
        $closes = $room->getClosesAt();
        return ($closes > 0 && $now >= $closes) ? $closes : 0;
    }

    /** Im Probelauf gibt es das Urteil immer sofort (openWindow speichert es ohnehin so). */
    public static function effectiveFeedback(Room $room): string {
        return $room->getPractice() ? self::FEEDBACK_EACH : $room->getFeedback();
    }

    /**
     * Ist eine Stimme endgültig — zählt sie für Punkte, Urteil und Rangliste?
     * EINE Regel für alle Sichten: korrigiert (`fixed`), nicht mehr
     * korrigierbar, oder das Korrekturfenster der ersten Antwort (`fw`) ist
     * vorbei. Strikt „>": in der Sekunde created+fw geht die Korrektur noch
     * (dieselbe Grenze wie im moderierten Quiz).
     *
     * $correctable = Fenster offen UND die Zeile der Person zu dieser Frage ist
     * noch offen. Was nicht mehr korrigiert werden kann, ist sofort endgültig —
     * sonst sortierte sich der Endstand nach dem Schließen noch fw Sekunden lang
     * um, und einer CSV direkt danach fehlten Punkte. Kein Leck: korrigieren
     * geht ab da ohnehin nicht mehr.
     */
    public static function isFinal(array $payload, int $createdAt, int $now, bool $correctable = true): bool {
        return !empty($payload['fixed'])
            || !$correctable
            || ($now - $createdAt) > (int)($payload['fw'] ?? VoteService::FIX_WINDOW);
    }

    /** Kann die Person ihre Antwort auf die Frage dieser Zeile noch korrigieren? */
    public static function correctable(Room $room, int $now, ?Progress $row): bool {
        return self::deriveState($room, $now) === self::STATE_OPEN
            && $row !== null && $row->getLeftAt() === 0;
    }

    /**
     * Ist die Person durch? Sie hat die letzte Frage erreicht und sie entweder
     * verlassen („Fertig" getippt), beantwortet oder das Fenster ist zu. Wer die
     * letzte Antwort gegeben, aber nicht mehr „Fertig" getippt hat, ist also
     * fertig. EINE Definition für Handy, Beamer, Fortschritt und CSV.
     *
     * @param int $n Fragen in der eingefrorenen Reihenfolge
     * @param ?Progress $last Zeile mit dem höchsten seq der Person
     */
    public static function isFinished(int $n, ?Progress $last, bool $answeredLast, string $state): bool {
        return $last !== null && $last->getSeq() === $n - 1
            && ($last->getLeftAt() > 0 || $answeredLast || $state !== self::STATE_OPEN);
    }

    /**
     * Der Wert, den der Client als /next {after} schickt: die offene Frage,
     * sonst die zuletzt erreichte, sonst 0. Fängt auch eine Anfrage, die
     * zwischen Schließen der alten und Starten der neuen Zeile abbrach.
     *
     * @param Progress[] $rows Zeilen EINER Person
     */
    public static function afterFor(array $rows): int {
        $open = self::openOf($rows);
        if ($open !== null) {
            return $open->getPollId();
        }
        return self::lastOf($rows)?->getPollId() ?? 0;
    }

    /**
     * Fenster für Raum-JSON und Zustand. `total` kommt nach dem Öffnen aus der
     * eingefrorenen Reihenfolge, davor aus dem aktuellen Deck.
     */
    public static function windowView(Room $room, int $now, int $deckCount): array {
        return [
            'state' => self::deriveState($room, $now),
            'openedAt' => $room->getOpenedAt(),
            'closesAt' => $room->getClosesAt(),
            'closedAt' => self::effectiveClosedAt($room, $now),
            'releasedAt' => $room->getReleasedAt(),
            'timed' => (bool)$room->getTimed(),
            'feedback' => self::effectiveFeedback($room),
            'total' => $room->getOpenedAt() > 0 ? count(self::order($room)) : $deckCount,
        ];
    }

    /**
     * Eingefrorene Reihenfolge (beim Öffnen geschrieben). Einzige Quelle für
     * „Frage k von n" und „nächste Frage" — nie `position`. Robust gegen Müll:
     * nur ganzzahlige Einträge > 0, Dubletten raus.
     *
     * @return list<int> Poll-IDs; [] im Entwurf
     */
    public static function order(Room $room): array {
        $raw = $room->getDeckOrder();
        if ($raw === null || $raw === '') {
            return [];
        }
        $list = json_decode($raw, true);
        if (!is_array($list)) {
            return [];
        }
        $ids = [];
        foreach ($list as $v) {
            if (is_int($v) || (is_string($v) && preg_match('/^\d+$/', $v) === 1)) {
                $id = (int)$v;
                if ($id > 0 && !isset($ids[$id])) {
                    $ids[$id] = true;
                }
            }
        }
        return array_keys($ids);
    }

    /**
     * Nachfolger von $after in der eingefrorenen Reihenfolge. $after = 0 ->
     * erste Frage. Unbekanntes $after oder letzte Frage -> null.
     *
     * $existing (Poll-IDs, die es noch gibt): fehlende Einträge werden
     * übersprungen. Reine Abwehr — seit das Deck ab dem Öffnen gesperrt ist,
     * sollte das nie greifen. seq bleibt der Index in der VOLLEN Reihenfolge.
     *
     * @param ?list<int> $existing null = nicht filtern
     * @return ?array{pollId:int, seq:int}
     */
    public static function nextAfter(Room $room, int $after, ?array $existing = null): ?array {
        $order = self::order($room);
        $start = 0;
        if ($after !== 0) {
            $idx = array_search($after, $order, true);
            if ($idx === false) {
                return null;
            }
            $start = $idx + 1;
        }
        $keep = $existing === null ? null : array_flip(array_map('intval', $existing));
        for ($i = $start, $n = count($order); $i < $n; $i++) {
            if ($keep === null || isset($keep[$order[$i]])) {
                return ['pollId' => $order[$i], 'seq' => $i];
            }
        }
        return null;
    }

    /**
     * Offene Zeile (left_at = 0) einer Person. Der Algorithmus in next() hält
     * höchstens eine offen; bei mehreren gilt die erste. Öffentlich, weil die
     * Lesesichten (PaceStateService) dieselbe Auswahl treffen müssen.
     *
     * @param Progress[] $rows
     */
    public static function openOf(array $rows): ?Progress {
        foreach ($rows as $row) {
            if ($row->getLeftAt() === 0) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Zuletzt erreichte Zeile (höchstes seq) einer Person, null ohne Zeilen.
     *
     * @param Progress[] $rows
     */
    public static function lastOf(array $rows): ?Progress {
        $last = null;
        foreach ($rows as $row) {
            if ($last === null || $row->getSeq() >= $last->getSeq()) {
                $last = $row;
            }
        }
        return $last;
    }

    // ── Sperre und Wächter ──────────────────────────────────────────────────

    /**
     * $fn($frischGesperrterRaum) in einer Transaktion unter SELECT … FOR UPDATE
     * auf die Raumzeile. Wächter und Zustand prüft $fn an DIESER Zeile, nicht
     * am übergebenen (evtl. veralteten) Raum — so gibt es kein Prüfen-dann-
     * Handeln zwischen zwei Tabs, Add-in und Browser. Jede Ausnahme rollt
     * zurück und fliegt weiter. Innerhalb von $fn keine Unique-Verstöße (auf
     * PostgreSQL bräche das die ganze Transaktion ab).
     *
     * Ist der Raum inzwischen gelöscht (anderer Tab, Aufräum-Job), gibt es
     * keine Zeile mehr zu sperren: RoomGoneException, die Controller machen
     * daraus 404 — nicht ein nacktes DoesNotExistException und damit 500.
     *
     * @return mixed Rückgabe von $fn
     * @throws RoomGoneException
     */
    public function locked(Room $room, callable $fn): mixed {
        return $this->atomic(function () use ($room, $fn): mixed {
            try {
                $fresh = $this->roomMapper->lockForUpdate($room->getId());
            } catch (DoesNotExistException) {
                throw new RoomGoneException();
            }
            return $fn($fresh);
        }, $this->db);
    }

    /**
     * Cursor-Steuerung (aktuelle Frage, sperren, beenden, Demo) gibt es im
     * eigenen Tempo nicht.
     *
     * @throws ConflictException
     */
    public function assertLiveControl(Room $room): void {
        if (self::isSelf($room)) {
            throw new ConflictException($this->l10n->t('Not available while the quiz runs at its own pace.'));
        }
    }

    /**
     * Ab dem Öffnen ist das Deck bis zum Zurücksetzen gesperrt — die
     * eingefrorene Reihenfolge bleibt so auch physisch stabil.
     *
     * @throws ConflictException
     */
    public function assertDeckEditable(Room $room): void {
        if (self::isSelf($room) && $room->getOpenedAt() > 0) {
            throw new ConflictException($this->l10n->t('The questions are locked since the quiz was opened. Reset the room to edit them.'));
        }
    }

    /**
     * Zurücksetzen, Probelauf und Tempo umschalten leeren den Raum — nicht,
     * solange Leute mitten im Quiz sind.
     *
     * @throws ConflictException
     */
    public function assertNotOpen(Room $room): void {
        if (self::isSelf($room) && self::deriveState($room, $this->timeFactory->getTime()) === self::STATE_OPEN) {
            throw new ConflictException($this->l10n->t('Close the quiz first.'));
        }
    }

    // ── Moderator-Aktionen (alle unter der Raumsperre) ──────────────────────

    /**
     * Tempo umschalten. Leert den Raum wie der Probelauf-Schalter (Stimmen,
     * Spieler, Präsenz, Fortschritt, Fenster). Derselbe Wert ist ein No-op —
     * ein doppelter Klick darf keine Ergebnisse löschen.
     *
     * @return Room frisch geladen
     * @throws \InvalidArgumentException unbekanntes Tempo / kein Quiz-Raum
     * @throws ConflictException         Fenster gerade offen
     */
    public function setPace(Room $room, string $pace): Room {
        return $this->locked($room, function (Room $r) use ($pace): Room {
            if ($pace !== self::PACE_LIVE && $pace !== self::PACE_SELF) {
                throw new \InvalidArgumentException($this->l10n->t('Unknown pace.'));
            }
            if ($pace === self::PACE_SELF && $r->getMode() !== 'quiz') {
                throw new \InvalidArgumentException($this->l10n->t('Only quiz rooms can run at their own pace.'));
            }
            $this->assertNotOpen($r);
            if ($r->getPace() === $pace) {
                return $this->reload($r);
            }
            $r->setPace($pace);
            $this->roomMapper->update($r);
            $this->roomService->resetRoom($r);
            return $this->reload($r);
        });
    }

    /**
     * Fenster öffnen und die Reihenfolge einfrieren. Nur aus dem Entwurf.
     *
     * @param int $closesAt 0 = bis manuell geschlossen (Rennen), sonst Unix-Sekunden (Hausaufgabe)
     * @param ?bool $timed null = Vorgabe (ohne Frist mit Timer, mit Frist ohne)
     * @param ?string $feedback null = Vorgabe (ohne Frist 'each', mit Frist 'end')
     * @return Room frisch geladen
     * @throws ConflictException         kein self-Raum / schon geöffnet
     * @throws \InvalidArgumentException leeres Deck / Frist außerhalb / unbekannte Rückmeldung
     */
    public function openWindow(Room $room, int $closesAt, ?bool $timed, ?string $feedback): Room {
        return $this->locked($room, function (Room $r) use ($closesAt, $timed, $feedback): Room {
            $now = $this->timeFactory->getTime();
            $this->assertSelf($r);
            if (self::deriveState($r, $now) !== self::STATE_DRAFT) {
                throw new ConflictException($this->l10n->t('The quiz has already been opened. Reset the room to start over.'));
            }
            $deck = $this->pollMapper->findByRoom($r->getId());
            if ($deck === []) {
                throw new \InvalidArgumentException($this->l10n->t('Add at least one question first.'));
            }
            $this->assertDeadline($closesAt, $now);
            $timed ??= $closesAt === 0;
            $feedback ??= $closesAt === 0 ? self::FEEDBACK_EACH : self::FEEDBACK_END;
            if ($feedback !== self::FEEDBACK_EACH && $feedback !== self::FEEDBACK_END) {
                throw new \InvalidArgumentException($this->l10n->t('Unknown feedback setting.'));
            }
            if ($r->getPractice()) {
                // Probelauf: Urteil immer sofort, sonst sieht man beim Testen nichts.
                $feedback = self::FEEDBACK_EACH;
            }
            $order = json_encode(array_map(static fn (Poll $p): int => $p->getId(), $deck));
            // Zweiter Gurt neben der Sperre: nur aus dem Entwurf eines self-Raums.
            if (!$this->roomMapper->openIfDraft($r->getId(), $order, $now, $closesAt, $timed, $feedback)) {
                throw new ConflictException($this->l10n->t('The quiz has already been opened. Reset the room to start over.'));
            }
            return $this->reload($r);
        });
    }

    /**
     * Fenster schließen. Ob das zugleich die Ergebnisse freigibt, sagt
     * $release: null = Regel nach der Frist (ohne Frist, also im Rennen, ja;
     * mit Frist, also als Hausaufgabe, nein — die Freigabe bleibt ein eigener
     * Schritt); false = nie („Stoppen ohne Freigabe“ in EINEM Aufruf: danach
     * bewerten, freigeben, wieder öffnen oder zurücksetzen); true = immer. Die
     * Frist bleibt, wie sie ist. $release zählt nur, wenn dieser Aufruf ein
     * offenes Fenster schließt: schon geschlossen = No-op, auch mit true
     * (freigeben heißt dann `release`).
     *
     * @param ?bool $release null = Regel nach der Frist (Clients ohne den Parameter)
     * @return Room frisch geladen
     * @throws ConflictException kein self-Raum / Entwurf / schon freigegeben
     */
    public function closeWindow(Room $room, ?bool $release = null): Room {
        return $this->locked($room, function (Room $r) use ($release): Room {
            $now = $this->timeFactory->getTime();
            $this->assertSelf($r);
            $state = self::deriveState($r, $now);
            if ($state === self::STATE_DRAFT) {
                throw new ConflictException($this->l10n->t('The quiz is not open.'));
            }
            if ($state === self::STATE_RELEASED) {
                throw new ConflictException($this->l10n->t('Results have already been released.'));
            }
            if ($state === self::STATE_OPEN) {
                $r->setClosedAt($now);
                if ($release ?? ($r->getClosesAt() === 0)) {
                    $r->setReleasedAt($now);
                }
                $this->roomMapper->update($r);
            }
            return $this->reload($r);
        });
    }

    /**
     * Frist verlängern bzw. ein geschlossenes Fenster wieder öffnen.
     * Einstellungen (timed, feedback) und Reihenfolge bleiben.
     *
     * @param int $closesAt neue Frist; 0 = offen bis manuell geschlossen
     *        (danach gibt `close` ohne `release` wieder zugleich frei)
     * @return Room frisch geladen
     * @throws ConflictException         kein self-Raum / Entwurf / schon freigegeben
     * @throws \InvalidArgumentException Frist außerhalb der Grenzen
     */
    public function extendWindow(Room $room, int $closesAt): Room {
        return $this->locked($room, function (Room $r) use ($closesAt): Room {
            $now = $this->timeFactory->getTime();
            $this->assertSelf($r);
            $state = self::deriveState($r, $now);
            if ($state === self::STATE_DRAFT) {
                throw new ConflictException($this->l10n->t('The quiz is not open.'));
            }
            if ($state === self::STATE_RELEASED) {
                throw new ConflictException($this->l10n->t('Results have already been released.'));
            }
            $this->assertDeadline($closesAt, $now);
            $r->setClosesAt($closesAt);
            $r->setClosedAt(0);
            $this->roomMapper->update($r);
            return $this->reload($r);
        });
    }

    /**
     * Lösungen und Endstand freigeben. Aus dem offenen Fenster in einem Klick:
     * es wird zugleich geschlossen, also kein Leck. Nach abgelaufener Frist
     * wird deren Zeitpunkt als Schluss festgeschrieben. Schon freigegeben = No-op.
     *
     * @return Room frisch geladen
     * @throws ConflictException kein self-Raum / Entwurf
     */
    public function releaseWindow(Room $room): Room {
        return $this->locked($room, function (Room $r): Room {
            $now = $this->timeFactory->getTime();
            $this->assertSelf($r);
            $state = self::deriveState($r, $now);
            if ($state === self::STATE_DRAFT) {
                throw new ConflictException($this->l10n->t('The quiz is not open.'));
            }
            if ($state === self::STATE_OPEN) {
                $r->setClosedAt($now);
                $r->setReleasedAt($now);
                $this->roomMapper->update($r);
            } elseif ($state === self::STATE_CLOSED) {
                if ($r->getClosedAt() === 0) {
                    $r->setClosedAt($r->getClosesAt());
                }
                $r->setReleasedAt($now);
                $this->roomMapper->update($r);
            }
            return $this->reload($r);
        });
    }

    /**
     * „Beitritt sperren": neue Tokens werden abgewiesen, bekannte Spieler
     * kommen weiter rein. Gegenmittel gegen Wegwerf-Spieler, in jedem
     * Fensterzustand erlaubt.
     *
     * @return Room frisch geladen
     * @throws ConflictException kein self-Raum
     */
    public function setJoinsLocked(Room $room, bool $locked): Room {
        return $this->locked($room, function (Room $r) use ($locked): Room {
            $this->assertSelf($r);
            $r->setJoinsLocked($locked);
            $this->roomMapper->update($r);
            return $this->reload($r);
        });
    }

    /**
     * Person entfernen (Wegwerf-Spieler, Namensbesetzer): zuerst der Spieler —
     * der Name wird frei —, dann Stimmen im Deck, Fortschritt, Präsenz. In jedem
     * Fensterzustand erlaubt, auch nach der Freigabe (ein Besetzer soll nicht
     * im Endstand/CSV stehen bleiben). Das Cookie steht danach ohne Namen da
     * und darf, solange offen und nicht gesperrt, neu beitreten — dann aber
     * ab Frage 1 mit neuer Uhr.
     *
     * Der Spieler zuerst, weil seine Zeile damit bis zum Commit gesperrt ist:
     * ein /vote oder /next derselben Person, das gerade schreibt, wartet in
     * assertStillJoined darauf, statt eine verwaiste Stimme oder Zeile zu
     * hinterlassen.
     *
     * @return Room frisch geladen
     * @throws ConflictException         kein self-Raum
     * @throws \InvalidArgumentException die ID gehört zu keinem Spieler dieses Raums
     */
    public function removePlayer(Room $room, int $playerId): Room {
        return $this->locked($room, function (Room $r) use ($playerId): Room {
            $this->assertSelf($r);
            $player = null;
            foreach ($this->playerMapper->findByRoom($r->getId()) as $p) {
                if ((int)$p->getId() === $playerId) {
                    $player = $p;
                    break;
                }
            }
            if ($player === null) {
                throw new \InvalidArgumentException($this->l10n->t('Player not found.'));
            }
            $token = $player->getVoterToken();
            $this->playerMapper->delete($player);
            $this->forgetToken($r, $token);
            $this->presenceMapper->deleteByRoomAndToken($r->getId(), $token);
            return $this->reload($r);
        });
    }

    /**
     * Stimmen im Deck und Fortschritt eines Tokens löschen; Spieler und
     * Präsenz bleiben. Für removePlayer, den erneuten Beitritt eines entfernten
     * Cookies (VoteService::selfJoin) und assertStillJoined.
     */
    public function forgetToken(Room $room, string $voterToken): void {
        // Geöffnet: das eingefrorene Deck; im Entwurf gibt es keine self-Stimmen.
        $pollIds = $room->getOpenedAt() > 0
            ? self::order($room)
            : array_map(static fn (Poll $p): int => $p->getId(), $this->pollMapper->findByRoom($room->getId()));
        $this->voteMapper->deleteByPollsAndToken($pollIds, $voterToken);
        $this->progressMapper->deleteByRoomAndToken($room->getId(), $voterToken);
    }

    // ── Teilnehmende ────────────────────────────────────────────────────────

    /**
     * Weiter zur nächsten Frage — die EINZIGE Stelle, an der eine Uhr startet.
     * Idempotent: ein zweites /next mit demselben $after tut nichts, ebenso
     * ein veraltetes $after (Doppeltipp, zweiter Tab). Zurück geht es nie.
     *
     * Ohne Raumsperre: alles ist je Token, der Wettlauf zweier Tabs entscheidet
     * sich am Compare-and-set (closeIfOpen) bzw. am Unique-Index (start). Bricht
     * eine Anfrage zwischen beiden ab, heilt das nächste /next mit der zuletzt
     * verlassenen Frage als $after (der Client nimmt dafür progress.after).
     * Gegen das Entfernen der Person mitten in der Anfrage: assertStillJoined.
     *
     * @throws \InvalidArgumentException kein self-Raum / Entwurf / geschlossen /
     *         ohne Namen / $after < 0
     * @throws RoomGoneException          der Raum wurde währenddessen gelöscht
     */
    public function next(Room $room, string $voterToken, int $after): void {
        if (!self::isSelf($room)) {
            throw new \InvalidArgumentException($this->l10n->t('This room is not self-paced.'));
        }
        if ($after < 0) {
            throw new \InvalidArgumentException($this->l10n->t('Invalid request.'));
        }
        $now = $this->timeFactory->getTime();
        $state = self::deriveState($room, $now);
        if ($state === self::STATE_DRAFT) {
            throw new \InvalidArgumentException($this->l10n->t('The quiz has not started yet.'));
        }
        if ($state !== self::STATE_OPEN) {
            throw new \InvalidArgumentException($this->l10n->t('The quiz is closed.'));
        }
        try {
            $this->playerMapper->findByRoomAndToken($room->getId(), $voterToken);
        } catch (DoesNotExistException) {
            throw new \InvalidArgumentException($this->l10n->t('Please choose a name first.'));
        }

        $rows = $this->rows($room, $voterToken);
        $open = self::openOf($rows);
        // Fragen, die es noch gibt (Abwehr gelöschter Fragen, s. nextAfter).
        $deck = [];
        foreach ($this->pollMapper->findByRoom($room->getId()) as $poll) {
            $deck[$poll->getId()] = $poll;
        }

        if ($open !== null) {
            if ($open->getPollId() !== $after) {
                return; // Doppeltipp oder veralteter Stand
            }
            $poll = $deck[$after] ?? null;
            if ($poll !== null && $this->previewLocked($room, $poll, $open, $voterToken, $now)) {
                return;
            }
            if (!$this->progressMapper->closeIfOpen($open->getId(), $now)) {
                return; // ein anderer Tab war schneller
            }
        } else {
            // Keine offene Zeile: ganz am Anfang (after = 0) oder Selbstheilung
            // nach einem Abbruch zwischen Schließen und Starten.
            $lastId = self::lastOf($rows)?->getPollId() ?? 0;
            if ($after !== $lastId) {
                return;
            }
        }

        $succ = self::nextAfter($room, $after, array_keys($deck));
        // start() false = Unique-Verstoß: die Frage läuft schon (paralleler Tab) — gut so.
        if ($succ !== null && $this->progressMapper->start($room->getId(), $succ['pollId'], $voterToken, $succ['seq'], $now)) {
            $this->assertStillJoined($room, $voterToken);
        }
    }

    /**
     * Nach dem Schreiben einer Stimme oder Zeile (/vote, /next — beide ohne
     * Raumsperre): Wurde die Person zwischen Spielerprüfung und Schreiben
     * entfernt (removePlayer, oder der ganze Raum gelöscht), wäre das eben
     * Geschriebene verwaist. Es zählte in Fortschritt, Auszählung und CSV, und
     * träte das Cookie wieder bei, erbte die neue Identität Frage, Uhr und
     * Antwort der alten. Dann ist alles dieses Tokens wieder weg, mit derselben
     * Antwort wie bei einer Entfernung Millisekunden früher.
     *
     * Lückenlos, weil die Spielerzeile sperrend gelesen wird: removePlayer
     * löscht sie als Erstes und hält die Sperre bis zum Commit, deleteRoom
     * löscht Spieler vor Stimmen und Fortschritt. Sieht diese Prüfung den
     * Spieler noch, trifft das spätere Löschen das Geschriebene mit.
     *
     * @throws \InvalidArgumentException 'Please choose a name first.'
     * @throws RoomGoneException          der Raum wurde währenddessen gelöscht
     */
    public function assertStillJoined(Room $room, string $voterToken): void {
        if ($this->playerMapper->existsForUpdate($room->getId(), $voterToken)) {
            return;
        }
        try {
            $this->locked($room, function (Room $r) use ($voterToken): void {
                // Inzwischen neu beigetreten? Dann hat selfJoin schon geräumt,
                // und was jetzt da ist, gehört der neuen Identität.
                if (!$this->playerMapper->existsForUpdate($r->getId(), $voterToken)) {
                    $this->forgetToken($r, $voterToken);
                }
            });
        } catch (RoomGoneException $e) {
            // Raum samt Spielern gelöscht: nur noch das eben Geschriebene.
            $this->forgetToken($room, $voterToken);
            throw $e;
        }
        throw new \InvalidArgumentException($this->l10n->t('Please choose a name first.'));
    }

    /**
     * Vorschau-Sperre: Mit Timer darf eine UNBEANTWORTETE Frage erst nach
     * Zeitablauf verlassen werden (Grenze wie /vote: elapsed > limit). Sonst
     * blättert ein Wegwerf-Spieler mit n Anfragen durchs ganze Deck, während
     * die Uhr seines Haupt-Cookies noch gar nicht läuft. Ohne Timer gibt es
     * keine Tempopunkte zu gewinnen — dort sofort weiter.
     */
    private function previewLocked(Room $room, Poll $poll, Progress $open, string $voterToken, int $now): bool {
        $limit = $poll->getTimeLimit();
        if (!$room->getTimed() || $limit <= 0 || $now - $open->getStartedAt() > $limit) {
            return false;
        }
        try {
            $this->voteMapper->findByPollAndToken($poll->getId(), $voterToken);
            return false; // beantwortet -> sofort weiter
        } catch (DoesNotExistException) {
            return true;
        }
    }

    /**
     * @return Progress[] Zeilen einer Person, nach seq aufsteigend
     */
    public function rows(Room $room, string $voterToken): array {
        return $this->progressMapper->findByRoomAndToken($room->getId(), $voterToken);
    }

    /** Die gerade offene Zeile einer Person (left_at = 0), null ohne. */
    public function openRow(Room $room, string $voterToken): ?Progress {
        return self::openOf($this->rows($room, $voterToken));
    }

    // ── Helfer ──────────────────────────────────────────────────────────────

    /** @throws ConflictException */
    private function assertSelf(Room $room): void {
        if (!self::isSelf($room)) {
            throw new ConflictException($this->l10n->t('This room is not self-paced.'));
        }
    }

    /**
     * Frist 0 = keine; sonst zwischen einer Minute und 30 Tagen ab jetzt.
     *
     * @throws \InvalidArgumentException
     */
    private function assertDeadline(int $closesAt, int $now): void {
        if ($closesAt !== 0 && ($closesAt < $now + self::MIN_LEAD || $closesAt > $now + self::MAX_LEAD)) {
            throw new \InvalidArgumentException($this->l10n->t('The deadline must be between one minute and 30 days from now.'));
        }
    }

    /**
     * Raum nach dem Schreiben frisch laden — noch in der Transaktion, also
     * genau mit dem Stand, den diese Aktion geschrieben hat (openIfDraft
     * schreibt an der Entität vorbei).
     */
    private function reload(Room $room): Room {
        return $this->roomMapper->findByCode($room->getCode());
    }
}
