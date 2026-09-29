<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\Player;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Progress;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\ICacheFactory;
use OCP\IL10N;

/**
 * Stimmen: eingehende Werte gegen den Fragetyp prüfen, speichern (Umfrage:
 * änderbar per Upsert; Quiz: sofort mit Tempo-Punkten, eine Korrektur im
 * Fenster), Freitext nachträglich bewerten und die Rangliste aus den Stimmen
 * ableiten. Dazu der Quiz-Beitritt (Nickname am anonymen Token).
 *
 * Quiz im eigenen Tempo (PaceService::isSelf): Stimme, Beitritt und Rangliste
 * verzweigen jeweils in der ersten Zeile in einen eigenen Zweig. Die dafür
 * nötigen Abhängigkeiten (paceService, progressMapper, cacheFactory) fassen
 * nur diese Zweige an — der moderierte Pfad läuft Zeile für Zeile wie vorher.
 */
class VoteService {
    /**
     * Korrekturfenster einer Quiz-Antwort in Sekunden (§8.0). Bei Tastatur-
     * oder Screenreader-Bedienung länger: dort ist die Auswahl ein bewusster
     * Tastendruck, aber der Weg zurück zur Karte dauert länger.
     * Öffentlich, weil PaceService::isFinal dieselben Werte als Vorgabe nimmt.
     */
    public const FIX_WINDOW = 3;
    public const FIX_WINDOW_KEYBOARD = 6;

    /** Öffentliche Rangliste im eigenen Tempo: Rohzeilen so lange im lokalen Cache (s. selfLeaderboard). */
    private const LEADERBOARD_BUCKET = 2;

    public function __construct(
        private PollMapper $pollMapper,
        private VoteMapper $voteMapper,
        private PlayerMapper $playerMapper,
        private QuizService $quizService,
        private DeckService $deckService,
        private ITimeFactory $timeFactory,
        private IL10N $l10n,
        // nur im eigenen Tempo angefasst:
        private PaceService $paceService,
        private ProgressMapper $progressMapper,
        private ICacheFactory $cacheFactory,
    ) {
    }

    /**
     * Stimme aufnehmen. Upsert auf (poll_id, voter_token): Wer erneut abstimmt,
     * ändert die eigene Stimme (last-write-wins pro Person), fügt keine zweite hinzu.
     *
     * $pollId ist die Frage, die das Handy beantwortet hat. Weicht sie von der
     * laufenden ab, hat der Moderator inzwischen weitergeschaltet — dann nicht
     * stillschweigend auf die neue Frage buchen (eine Zahl, ein Wort, ein
     * Skalenwert passt dort oft genauso). null = älteres Handy ohne das Feld.
     *
     * Im eigenen Tempo gibt es keinen Cursor: dort zählt die offene Zeile der
     * Person (recordSelfVote).
     *
     * @throws \InvalidArgumentException keine aktive Frage / ungültiger Wert
     * @throws RoomGoneException         eigenes Tempo: Raum währenddessen gelöscht
     */
    public function recordVote(Room $room, string $voterToken, mixed $value, bool $keyboard = false, ?int $pollId = null): void {
        if (PaceService::isSelf($room)) {
            $this->recordSelfVote($room, $voterToken, $value, $keyboard, $pollId);
            return;
        }
        $activeId = $room->getActivePollId();
        if ($activeId === 0) {
            throw new \InvalidArgumentException($this->l10n->t('No question is running right now.'));
        }
        if ($pollId !== null && $pollId !== $activeId) {
            throw new \InvalidArgumentException($this->l10n->t('This question is closed.'));
        }
        try {
            $poll = $this->pollMapper->find($activeId);
        } catch (DoesNotExistException) {
            throw new \InvalidArgumentException($this->l10n->t('No question is running right now.'));
        }

        if ($room->getMode() === 'quiz') {
            $this->recordQuizVote($room, $poll, $voterToken, $value, $keyboard);
            return;
        }

        $status = $poll->getStatus();
        if ($status === 'locked') {
            throw new \InvalidArgumentException($this->l10n->t('The question is paused right now.'));
        }
        if ($status !== 'active') {
            throw new \InvalidArgumentException($this->l10n->t('This question is closed.'));
        }

        $payload = json_encode(['value' => $this->normalizeValue($poll, $value)]);

        try {
            $existing = $this->voteMapper->findByPollAndToken($poll->getId(), $voterToken);
            $existing->setPayload($payload);
            $existing->setCreatedAt($this->timeFactory->getTime());
            $this->voteMapper->update($existing);
        } catch (DoesNotExistException) {
            $vote = new Vote();
            $vote->setPollId($poll->getId());
            $vote->setVoterToken($voterToken);
            $vote->setPayload($payload);
            $vote->setCreatedAt($this->timeFactory->getTime());
            try {
                $this->voteMapper->insert($vote);
            } catch (Exception $e) {
                if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    throw $e;
                }
                // Zwei erste Stimmen desselben Tokens zugleich (Doppeltipp,
                // Wiederholung nach Netzfehler): die andere hat eben eingefügt.
                // Dann ist diese eine Änderung — last-write-wins wie oben, keine 500.
                $existing = $this->voteMapper->findByPollAndToken($poll->getId(), $voterToken);
                $existing->setPayload($payload);
                $existing->setCreatedAt($this->timeFactory->getTime());
                $this->voteMapper->update($existing);
            }
        }
    }

    // ── Quiz ────────────────────────────────────────────────────────────────

    /**
     * Quiz-Beitritt: Nickname für dieses Token in diesem Raum setzen (bzw. ändern).
     *
     * @throws \InvalidArgumentException leerer/zu langer Name oder kein Quiz-Raum
     * @throws RoomGoneException         eigenes Tempo: Raum währenddessen gelöscht
     */
    public function quizJoin(Room $room, string $voterToken, string $nickname): Player {
        if ($room->getMode() !== 'quiz') {
            throw new \InvalidArgumentException($this->l10n->t('This room is not a quiz.'));
        }
        if (PaceService::isSelf($room)) {
            return $this->paceService->locked($room, fn (Room $r): Player => $this->selfJoin($r, $voterToken, $nickname));
        }
        $nickname = $this->sanitizeNickname($nickname);
        // Namen sind im Raum eindeutig (ohne Groß/Klein) — sonst stehen zwei
        // gleiche Zeilen in der Rangliste und niemand weiß, welche die eigene
        // ist. Das eigene Token darf seinen Namen behalten oder umschreiben.
        $wanted = TallyService::nameKey($nickname);
        foreach ($this->playerMapper->findByRoom($room->getId()) as $other) {
            if ($other->getVoterToken() !== $voterToken && TallyService::nameKey($other->getNickname()) === $wanted) {
                throw new \InvalidArgumentException($this->l10n->t('This name is already taken. Please choose another one.'));
            }
        }
        return $this->playerMapper->register($room->getId(), $voterToken, $nickname, $this->timeFactory->getTime());
    }

    /**
     * Beitritt im eigenen Tempo. Läuft unter der Raumsperre (quizJoin ->
     * PaceService::locked), $room ist die frisch gesperrte Zeile: Prüfen und
     * Eintragen sind damit serialisiert — zwei gleichzeitige „Anna" bekommen
     * nicht mehr beide den Namen, und der Deckel lässt sich nicht überrennen.
     * Innerhalb der Sperre kein Unique-Verstoß möglich (dasselbe Token wird
     * ebenso serialisiert).
     *
     * Im Entwurf und im offenen Fenster erlaubt; nach dem Schluss kommt die
     * nächste Gruppe nicht mehr an Endstand und Lösungen. „Beitritt sperren"
     * und der Deckel treffen nur neue Tokens — wer schon mitspielt, kommt
     * weiter rein. Nach dem Start ist der Name fest: sonst schlüpfte ein Token
     * nach dem Antworten in einen frei gewordenen oder fremden Namen, und die
     * Lehrkraft bewertet nach Namen. Groß/Klein am eigenen Namen bleibt änderbar
     * (gleicher nameKey).
     *
     * @throws \InvalidArgumentException
     */
    private function selfJoin(Room $room, string $voterToken, string $nickname): Player {
        $now = $this->timeFactory->getTime();
        $state = PaceService::deriveState($room, $now);
        if ($state === PaceService::STATE_CLOSED || $state === PaceService::STATE_RELEASED) {
            throw new \InvalidArgumentException($this->l10n->t('The quiz is closed.'));
        }
        $nickname = $this->sanitizeNickname($nickname);
        $wanted = TallyService::nameKey($nickname);
        $players = $this->playerMapper->findByRoom($room->getId());
        $mine = null;
        $taken = false;
        foreach ($players as $other) {
            if ($other->getVoterToken() === $voterToken) {
                $mine = $other;
            } elseif (TallyService::nameKey($other->getNickname()) === $wanted) {
                $taken = true;
            }
        }
        if ($mine === null && $room->getJoinsLocked()) {
            throw new \InvalidArgumentException($this->l10n->t('Joining is closed for this quiz.'));
        }
        if ($mine === null && count($players) >= PaceService::MAX_PLAYERS) {
            throw new \InvalidArgumentException($this->l10n->t('This quiz is full.'));
        }
        if ($mine !== null && TallyService::nameKey($mine->getNickname()) !== $wanted
            && $this->progressMapper->findByRoomAndToken($room->getId(), $voterToken) !== []) {
            throw new \InvalidArgumentException($this->l10n->t('You can\'t change your name after starting.'));
        }
        if ($taken) {
            throw new \InvalidArgumentException($this->l10n->t('This name is already taken. Please choose another one.'));
        }
        if ($mine === null && $room->getOpenedAt() > 0) {
            // Eine neue Identität beginnt leer, auch wenn vom entfernten Spieler
            // dieses Cookies noch etwas liegt (eine Anfrage, die zwischen
            // Schreiben und PaceService::assertStillJoined abbrach): Frage 1,
            // neue Uhr, keine alte Antwort.
            $this->paceService->forgetToken($room, $voterToken);
        }
        return $this->playerMapper->register($room->getId(), $voterToken, $nickname, $now);
    }

    /**
     * Quiz-Antwort aufnehmen: ein Tipp sendet sofort, Zeitlimit erzwungen,
     * Punkte sofort berechnet und im Payload abgelegt (Rangliste leitet sich
     * daraus ab — Reset/Löschen bleibt damit konsistent).
     *
     * Genau EINE Korrektur ist erlaubt, und nur innerhalb des Fensters aus
     * §8.0. Die Korrektur setzt den Zeitstempel neu — die gewonnene Zeit
     * verfällt also, und schnelles Blind-Antippen bringt keinen Vorteil. Das
     * Fenster wird hier geprüft, nicht im Browser: der Endpunkt ist öffentlich.
     *
     * @throws \InvalidArgumentException
     */
    private function recordQuizVote(Room $room, Poll $poll, string $voterToken, mixed $value, bool $keyboard = false): void {
        try {
            $this->playerMapper->findByRoomAndToken($room->getId(), $voterToken);
        } catch (DoesNotExistException) {
            throw new \InvalidArgumentException($this->l10n->t('Please choose a name first.'));
        }
        if ($poll->getStatus() !== 'active') {
            throw new \InvalidArgumentException($this->l10n->t('This question is not accepting answers right now.'));
        }

        $now = $this->timeFactory->getTime();
        $elapsed = max(0, $now - $poll->getStartedAt());
        if ($poll->getTimeLimit() > 0 && $elapsed > $poll->getTimeLimit()) {
            throw new \InvalidArgumentException($this->l10n->t('Time is up.'));
        }

        // normalizeValue prüft den Wert gegen den Fragetyp; quizPayload wertet ihn.
        $normalized = $this->normalizeValue($poll, $value);
        $payload = json_encode($this->quizPayload($poll, $normalized, $elapsed));

        $vote = new Vote();
        $vote->setPollId($poll->getId());
        $vote->setVoterToken($voterToken);
        $vote->setPayload($payload);
        $vote->setCreatedAt($now);
        try {
            $this->voteMapper->insert($vote);
        } catch (Exception $e) {
            if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
            // Es gibt schon eine Antwort: höchstens eine Korrektur im Fenster.
            $this->correctQuizVote($poll, $voterToken, $payload, $now, $keyboard);
        }
    }

    /**
     * Die eine erlaubte Korrektur (§8.0). Sie ist an drei Bedingungen geknüpft:
     * die vorige Antwort ist keine Korrektur, sie liegt innerhalb des Fensters,
     * und die Frage nimmt noch Antworten an. Sonst bleibt es bei „schon
     * geantwortet".
     *
     * @throws \InvalidArgumentException
     */
    private function correctQuizVote(Poll $poll, string $voterToken, string $payload, int $now, bool $keyboard): void {
        try {
            $existing = $this->voteMapper->findByPollAndToken($poll->getId(), $voterToken);
        } catch (DoesNotExistException) {
            // Zwischen Insert und Nachschlagen gelöscht — dann bleibt es dabei.
            throw new \InvalidArgumentException($this->l10n->t('You have already answered this question.'));
        }
        $old = json_decode($existing->getPayload(), true) ?: [];
        $window = $keyboard ? self::FIX_WINDOW_KEYBOARD : self::FIX_WINDOW;
        if (!empty($old['fixed']) || $now - $existing->getCreatedAt() > $window) {
            throw new \InvalidArgumentException($this->l10n->t('You have already answered this question.'));
        }
        $data = json_decode($payload, true) ?: [];
        $data['fixed'] = true;
        $existing->setPayload(json_encode($data));
        $existing->setCreatedAt($now);
        $this->voteMapper->update($existing);
    }

    /**
     * Quiz-Antwort im eigenen Tempo. Statt des Cursors zählt die offene Zeile
     * der Person (PaceService::next), statt poll.startedAt ihre persönliche Uhr.
     * Ohne Timer (room.timed = false) gibt es kein Limit und flache Punkte.
     *
     * Der Payload trägt zusätzlich, was die Lesesichten ohne Raum-Kontext
     * brauchen: `limit` (Tempopunkte beim Nachbewerten, leere Zeitspalte ohne
     * Timer), `fw` (Korrekturfenster der ERSTEN Antwort — PaceService::isFinal
     * rechnet damit) und bei Freitext `pending` (weder angenommen noch
     * abgelehnt: 0 Punkte, bis der Moderator bewertet). Schlüsselreihenfolge:
     * value, points, correct, elapsed, [norm], [pending], limit, fw, [fixed].
     *
     * Anders als moderiert entscheidet über die Korrektur das Fenster der
     * ersten Antwort, nicht das Flag der korrigierenden Anfrage: sonst hieße
     * es „erst tippen, Urteil nach 3 s ansehen, dann per keyboard:true
     * korrigieren" — im eigenen Tempo ist das Urteil nicht bis zum Auflösen
     * verdeckt.
     *
     * Nach dem Speichern: assertStillJoined (mitten in der Anfrage entfernt)
     * und bei Freitext settleText (mitten in einer Bewertung gerechnet).
     *
     * @throws \InvalidArgumentException
     * @throws RoomGoneException
     */
    private function recordSelfVote(Room $room, string $voterToken, mixed $value, bool $keyboard, ?int $pollId): void {
        $now = $this->timeFactory->getTime();
        $state = PaceService::deriveState($room, $now);
        if ($state === PaceService::STATE_DRAFT) {
            throw new \InvalidArgumentException($this->l10n->t('The quiz has not started yet.'));
        }
        if ($state !== PaceService::STATE_OPEN) {
            throw new \InvalidArgumentException($this->l10n->t('The quiz is closed.'));
        }
        try {
            $this->playerMapper->findByRoomAndToken($room->getId(), $voterToken);
        } catch (DoesNotExistException) {
            throw new \InvalidArgumentException($this->l10n->t('Please choose a name first.'));
        }
        if ($pollId === null) {
            // Altes Bundle ohne pollId: ohne Cursor wüssten wir nicht, welche Frage gemeint ist.
            throw new \InvalidArgumentException($this->l10n->t('Please reload the page.'));
        }
        $open = $this->paceService->openRow($room, $voterToken);
        if ($open === null || $open->getPollId() !== $pollId) {
            throw new \InvalidArgumentException($this->l10n->t('This question is closed.'));
        }
        try {
            $poll = $this->pollMapper->find($pollId);
        } catch (DoesNotExistException) {
            // Abwehr: die Frage der offenen Zeile gibt es nicht mehr.
            throw new \InvalidArgumentException($this->l10n->t('This question is closed.'));
        }

        // Die Klemme 5–300 s gilt weiter im Deck; ohne Timer kein Limit.
        $limit = $room->getTimed() ? $poll->getTimeLimit() : 0;
        $elapsed = max(0, $now - $open->getStartedAt());
        if ($limit > 0 && $elapsed > $limit) {
            throw new \InvalidArgumentException($this->l10n->t('Time is up.'));
        }

        $normalized = $this->normalizeValue($poll, $value);
        $data = $this->selfPayload($poll, $normalized, $elapsed, $limit, $keyboard ? self::FIX_WINDOW_KEYBOARD : self::FIX_WINDOW);

        $vote = new Vote();
        $vote->setPollId($poll->getId());
        $vote->setVoterToken($voterToken);
        $vote->setPayload(json_encode($data));
        $vote->setCreatedAt($now);
        try {
            $this->voteMapper->insert($vote);
        } catch (Exception $e) {
            if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
            // Es gibt schon eine Antwort: höchstens eine Korrektur. Freitext unter
            // der Raumsperre wie das Bewerten (gradeTextAnswer) und mit frisch
            // gelesenem Antwortschlüssel — sonst schriebe eine gleichzeitige
            // Bewertung ihren alten Stand über die Korrektur oder umgekehrt.
            if ($poll->getType() === 'text') {
                $this->paceService->locked($room, fn () => $this->correctSelfVote(
                    $this->pollMapper->find($pollId), $voterToken, $normalized, $elapsed, $limit, $now,
                ));
            } else {
                $this->correctSelfVote($poll, $voterToken, $normalized, $elapsed, $limit, $now);
            }
            return;
        }
        // Mitten in der Anfrage entfernt? Dann ist die Stimme wieder weg (400).
        $this->paceService->assertStillJoined($room, $voterToken);
        if ($poll->getType() === 'text') {
            $this->settleText($room, $pollId, $voterToken, $data);
        }
    }

    /**
     * Payload einer Stimme im eigenen Tempo, Schlüssel in fester Reihenfolge
     * (s. recordSelfVote).
     *
     * @param int $fw Korrekturfenster der ersten Antwort
     */
    private function selfPayload(Poll $poll, mixed $normalized, int $elapsed, int $limit, int $fw): array {
        $data = $this->quizPayload($poll, $normalized, $elapsed, $limit);
        if ($poll->getType() === 'text') {
            $data['pending'] = !$data['correct'] && !$this->textRejected($poll, (string)$data['norm']);
        }
        $data['limit'] = $limit;
        $data['fw'] = $fw;
        return $data;
    }

    /**
     * Die eine Korrektur im eigenen Tempo, solange die vorige Antwort nicht
     * endgültig ist. Korrigierbar ist sie (Fenster offen, Zeile offen — von
     * recordSelfVote geprüft).
     *
     * @throws \InvalidArgumentException
     */
    private function correctSelfVote(Poll $poll, string $voterToken, mixed $normalized, int $elapsed, int $limit, int $now): void {
        try {
            $existing = $this->voteMapper->findByPollAndToken($poll->getId(), $voterToken);
        } catch (DoesNotExistException) {
            throw new \InvalidArgumentException($this->l10n->t('You have already answered this question.'));
        }
        $old = json_decode($existing->getPayload(), true);
        $old = is_array($old) ? $old : [];
        if (PaceService::isFinal($old, $existing->getCreatedAt(), $now, true)) {
            throw new \InvalidArgumentException($this->l10n->t('You have already answered this question.'));
        }
        // Fenster der ERSTEN Antwort — das Tastatur-Flag der Korrektur zählt nicht.
        $data = $this->selfPayload($poll, $normalized, $elapsed, $limit, (int)($old['fw'] ?? self::FIX_WINDOW));
        $data['fixed'] = true;
        $existing->setPayload(json_encode($data));
        $existing->setCreatedAt($now);
        $this->voteMapper->update($existing);
    }

    /**
     * Freitext im eigenen Tempo: Bewertet der Moderator dieselbe Antwort,
     * während sie unterwegs ist, kann sie mit dem alten Antwortschlüssel
     * gerechnet und erst nach dem Durchgang der Bewertung gespeichert sein —
     * dann bliebe sie auf „wird geprüft" mit 0 Punkten, obwohl ihre Normalform
     * schon bewertet ist. Deshalb nach dem Speichern den Schlüssel noch einmal
     * lesen, und zwar sperrend: eine laufende Bewertung (eine Transaktion unter
     * der Raumsperre) hält die Fragezeile bis zum Commit, danach ist ihr
     * Schlüssel sichtbar; war sie noch nicht an der Zeile, findet ihr Durchgang
     * diese Stimme selbst. Weicht das Urteil ab, unter der Raumsperre frisch
     * nachrechnen.
     *
     * @param array $data der eben gespeicherte Payload
     */
    private function settleText(Room $room, int $pollId, string $voterToken, array $data): void {
        try {
            $poll = $this->pollMapper->findForUpdate($pollId);
        } catch (DoesNotExistException) {
            return; // Raum gerade gelöscht — die Stimme geht mit
        }
        if ($this->textVerdict($poll, (string)($data['norm'] ?? '')) === [$data['correct'], !empty($data['pending'])]) {
            return;
        }
        $this->paceService->locked($room, function () use ($pollId, $voterToken): void {
            try {
                $vote = $this->voteMapper->findByPollAndToken($pollId, $voterToken);
            } catch (DoesNotExistException) {
                return; // inzwischen entfernt
            }
            $d = json_decode($vote->getPayload(), true);
            if (!is_array($d)) {
                return;
            }
            $poll = $this->pollMapper->find($pollId);
            [$correct, $pending] = $this->textVerdict($poll, (string)($d['norm'] ?? ''));
            if ($correct === !empty($d['correct']) && $pending === !empty($d['pending'])) {
                return; // eine Bewertung war schneller und hat sie schon nachgezogen
            }
            $d['correct'] = $correct;
            $d['points'] = $correct ? $this->quizService->points((int)($d['elapsed'] ?? 0), (int)($d['limit'] ?? $poll->getTimeLimit())) : 0;
            $d['pending'] = $pending;
            $vote->setPayload(json_encode($d));
            $this->voteMapper->update($vote);
        });
    }

    /**
     * Vote-Payload einer Quiz-Antwort: prüft Korrektheit je Typ, vergibt
     * Tempo-Punkte. Freitext, dessen Antwort (noch) nicht in der Akzeptanzliste
     * steht, startet als correct=false und wird beim Bewerten nachgezogen.
     *
     * @param ?int $limit Zeitlimit für die Tempo-Punkte; null = das der Frage
     *        (moderiert). Im eigenen Tempo 0 ohne Timer -> flache Punkte.
     * @return array{value:mixed, points:int, correct:bool, elapsed:int, norm?:string}
     */
    public function quizPayload(Poll $poll, mixed $normalized, int $elapsed, ?int $limit = null): array {
        $limit ??= $poll->getTimeLimit();
        $type = $poll->getType();
        $correct = false;
        $extra = [];
        if ($type === 'choice' || $type === 'truefalse') {
            $correct = is_string($normalized) && $normalized !== '' && $normalized === $poll->getCorrectOption();
        } elseif ($type === 'multi') {
            $want = $poll->getAnswerKeyArray()['correct'] ?? [];
            sort($want);
            $got = is_array($normalized) ? $normalized : [];
            sort($got);
            $correct = $got === $want; // alles-oder-nichts
        } elseif ($type === 'rank') {
            // Alles-oder-nichts wie bei der Mehrfachauswahl: die Reihenfolge muss
            // exakt stimmen. Teilpunkte (z. B. Kendall-Tau) würden „richtig" in
            // der Rangliste verwässern — bewusst nicht.
            $want = $poll->getAnswerKeyArray()['order'] ?? [];
            $correct = is_array($normalized) && $normalized === $want && $want !== [];
        } elseif ($type === 'match') {
            // Wie Mehrfachauswahl und Reihenfolge: alles-oder-nichts. Jede
            // Zuordnung muss sitzen, sonst gibt es keinen Punkt.
            $want = $poll->getAnswerKeyArray()['map'] ?? [];
            $got = is_array($normalized) ? $normalized : [];
            ksort($want);
            ksort($got);
            $correct = $want !== [] && $got === $want;
        } elseif ($type === 'number') {
            $key = $poll->getAnswerKeyArray();
            $target = (float)($key['target'] ?? 0);
            $tol = (float)($key['tolerance'] ?? 0);
            $correct = is_numeric($normalized) && abs((float)$normalized - $target) <= $tol;
        } elseif ($type === 'text') {
            $norm = TallyService::normalizeText((string)$normalized);
            $correct = $this->textAccepted($poll, $norm);
            $extra['norm'] = $norm;
        }
        $points = $correct ? $this->quizService->points($elapsed, $limit) : 0;
        return array_merge([
            'value' => $normalized,
            'points' => $points,
            'correct' => $correct,
            'elapsed' => $elapsed,
        ], $extra);
    }

    /** Freitext: steht die Normalform in der (wachsenden) Akzeptanzliste? */
    private function textAccepted(Poll $poll, string $norm): bool {
        foreach (($poll->getAnswerKeyArray()['accepted'] ?? []) as $a) {
            if (TallyService::normalizeText((string)$a) === $norm) {
                return true;
            }
        }
        return false;
    }

    /**
     * Gegenstück: schon als falsch bewertet? Im eigenen Tempo ist alles, was in
     * keiner der beiden Listen steht, „wird geprüft" (pending).
     */
    private function textRejected(Poll $poll, string $norm): bool {
        foreach (($poll->getAnswerKeyArray()['rejected'] ?? []) as $r) {
            if (TallyService::normalizeText((string)$r) === $norm) {
                return true;
            }
        }
        return false;
    }

    /**
     * Urteil über eine Freitext-Normalform nach dem aktuellen Schlüssel, wie
     * selfPayload es speichert.
     *
     * @return array{0: bool, 1: bool} [richtig, wird geprüft]
     */
    private function textVerdict(Poll $poll, string $norm): array {
        $correct = $this->textAccepted($poll, $norm);
        return [$correct, !$correct && !$this->textRejected($poll, $norm)];
    }

    /**
     * Freitext bewerten: eine (nicht offensichtliche) Antwort als richtig/falsch
     * markieren. „Richtig" nimmt sie in die Akzeptanzliste auf; ALLE Stimmen mit
     * gleicher Normalform werden nachträglich als richtig gewertet (Tempo-Punkte
     * aus ihrem gespeicherten elapsed). „Falsch" -> Ablehnliste, Stimmen auf 0.
     * Idempotent und beliebig umschaltbar; hält die Rangliste (Payload-basiert)
     * konsistent, weil die Punkte direkt in den Stimmen nachgezogen werden.
     *
     * Im eigenen Tempo wird bewertet, während Leute antworten: dort als eine
     * Transaktion unter der Raumsperre. Korrekturen laufen ebenso (sonst
     * überschrieben sich Bewertung und Korrektur gegenseitig), und eine gerade
     * gespeicherte Antwort liest den Schlüssel danach sperrend nach (settleText).
     *
     * @throws \InvalidArgumentException Frage gehört nicht zum Raum / kein Freitext
     * @throws RoomGoneException         eigenes Tempo: Raum währenddessen gelöscht
     */
    public function gradeTextAnswer(Room $room, int $pollId, string $answer, bool $correct): void {
        if (PaceService::isSelf($room)) {
            $this->paceService->locked($room, fn (Room $r) => $this->gradeText($r, $pollId, $answer, $correct));
            return;
        }
        $this->gradeText($room, $pollId, $answer, $correct);
    }

    /** Rumpf von gradeTextAnswer (im eigenen Tempo unter der Raumsperre). */
    private function gradeText(Room $room, int $pollId, string $answer, bool $correct): void {
        $poll = $this->deckService->requirePollInRoom($room, $pollId);
        if ($poll->getType() !== 'text') {
            throw new \InvalidArgumentException($this->l10n->t('Grading only exists for free-text questions.'));
        }
        $norm = TallyService::normalizeText($answer);
        if ($norm === '') {
            throw new \InvalidArgumentException($this->l10n->t('Empty answer.'));
        }
        $key = $poll->getAnswerKeyArray();
        // Gleiche Normalform aus beiden Listen entfernen, dann neu einsortieren.
        $strip = static fn (array $list): array => array_values(array_filter(
            $list,
            static fn ($a): bool => TallyService::normalizeText((string)$a) !== $norm,
        ));
        $accepted = $strip(array_values($key['accepted'] ?? []));
        $rejected = $strip(array_values($key['rejected'] ?? []));
        $sample = mb_substr(trim($answer), 0, DeckService::TEXT_MAX);
        if ($correct) {
            $accepted[] = $sample;
        } else {
            $rejected[] = $sample;
        }
        $poll->setAnswerKey(json_encode(['accepted' => $accepted, 'rejected' => $rejected]));
        $this->pollMapper->update($poll);

        // Betroffene Stimmen (gleiche Normalform) neu werten. Im eigenen Tempo
        // mit dem Limit, das beim Antworten galt (0 ohne Timer -> flache Punkte);
        // moderierte Stimmen haben keins und nehmen wie bisher das der Frage.
        $limit = $poll->getTimeLimit();
        foreach ($this->voteMapper->findByPoll($pollId) as $vote) {
            $d = json_decode($vote->getPayload(), true);
            if (!is_array($d)) {
                continue;
            }
            $voteNorm = $d['norm'] ?? TallyService::normalizeText((string)($d['value'] ?? ''));
            if ($voteNorm !== $norm) {
                continue;
            }
            $d['correct'] = $correct;
            $d['points'] = $correct ? $this->quizService->points((int)($d['elapsed'] ?? 0), (int)($d['limit'] ?? $limit)) : 0;
            // Bewertet = nicht mehr „wird geprüft". Moderierte Payloads haben
            // den Schlüssel nie und bleiben so bytegleich.
            unset($d['pending']);
            $vote->setPayload(json_encode($d));
            $this->voteMapper->update($vote);
        }
    }

    /** Nickname dieses Tokens im Raum, oder null (noch nicht beigetreten). */
    public function playerNickname(Room $room, ?string $voterToken): ?string {
        if ($voterToken === null || $voterToken === '') {
            return null;
        }
        try {
            return $this->playerMapper->findByRoomAndToken($room->getId(), $voterToken)->getNickname();
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Rangliste für einen Betrachter: Zeilen ohne fremde Tokens, die eigene
     * Zeile mit `me: true` markiert ($meToken = null im Moderator-Blick).
     * $skipPollId lässt eine Frage aus der Wertung — die laufende, noch
     * verdeckte, wenn die Rangliste öffentlich rausgeht.
     *
     * Im eigenen Tempo gibt es keine laufende Frage für alle: dort gilt
     * selfLeaderboard (nur endgültige Stimmen), $skipPollIds spielt keine Rolle.
     *
     * @return list<array{rank:int, nickname:string, score:int, correct:int, me:bool}>
     */
    public function leaderboardFor(Room $room, ?string $meToken, array $skipPollIds = []): array {
        if (PaceService::isSelf($room)) {
            return $this->selfLeaderboard($room, $meToken);
        }
        [$points, $correct, $time] = $this->quizPointsByToken($room, $skipPollIds);
        $players = $this->playerMapper->findByRoom($room->getId());
        $rows = $this->quizService->leaderboard($players, $points, $correct, $time);
        return self::forViewer($rows, $meToken);
    }

    /**
     * Rangliste im eigenen Tempo: EINE Abfrage fürs ganze eingefrorene Deck,
     * nur endgültige Stimmen (PaceService::isFinal mit correctable je Zeile) —
     * eine Antwort im Korrekturfenster verrät sich so nicht über den Punktestand,
     * und nach dem Schließen steht der Endstand sofort fest. Ohne Timer
     * entscheidet bei Gleichstand nicht die Summe der Zeiten (die enthält den
     * Leerlauf zwischen /next und Antwort, bei Hausaufgaben Stunden), sondern
     * es bleibt ein geteilter Rang.
     *
     * Öffentliche Aufrufer (Beamer, Handy/Summary nach der Freigabe) setzen
     * $cached: nach der Freigabe holt jedes Handy bei jedem Poll den Endstand,
     * also ein JSON-Decode aller Stimmen des Decks. Die Rohzeilen liegen deshalb
     * je Raum und 2-s-Eimer im lokalen Cache. Fensterwechsel stehen im
     * Schlüssel und greifen sofort (auch die ablaufende Frist, über den
     * effektiven Schluss), Stimmen erscheinen höchstens 2 s später. Ohne APCu
     * (NullCache) wird einfach jedes Mal gerechnet. Moderator und CSV rechnen
     * immer frisch.
     *
     * @param int $limit 0 = alle, sonst nur die ersten $limit Zeilen (Beamer: 8)
     * @return list<array{rank:int, nickname:string, score:int, correct:int, me:bool}>
     */
    public function selfLeaderboard(Room $room, ?string $meToken, int $limit = 0, bool $cached = false): array {
        $now = $this->timeFactory->getTime();
        if ($cached) {
            $cache = $this->cacheFactory->createLocal('pulse');
            $key = 'lb:' . $room->getId() . ':' . $room->getOpenedAt()
                . ':' . PaceService::effectiveClosedAt($room, $now) . ':' . $room->getClosesAt()
                . ':' . $room->getReleasedAt() . ':' . intdiv($now, self::LEADERBOARD_BUCKET);
            $rows = $cache->get($key);
            if (!is_array($rows)) {
                $rows = $this->selfLeaderboardRows($room, $now);
                $cache->set($key, $rows, self::LEADERBOARD_BUCKET + 1);
            }
        } else {
            $rows = $this->selfLeaderboardRows($room, $now);
        }
        $rows = self::forViewer($rows, $meToken);
        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    /**
     * Rohzeilen der Rangliste im eigenen Tempo (mit Token, nur serverintern
     * und im lokalen Cache). Öffentlich nur für den CSV-Export
     * (PaceStateService), der je Token weitere Spalten anhängt — das Token
     * selbst verlässt den Server nie.
     *
     * @return list<array{token:string, rank:int, nickname:string, score:int, correct:int, time:int}>
     */
    public function selfLeaderboardRows(Room $room, int $now): array {
        // Zeile je (Frage, Token) — ob die Antwort noch korrigierbar ist, hängt an ihr.
        /** @var array<string, Progress> $progress */
        $progress = [];
        foreach ($this->progressMapper->findByRoom($room->getId()) as $row) {
            $progress[$row->getPollId() . ':' . $row->getVoterToken()] = $row;
        }
        $points = [];
        $correct = [];
        $time = [];
        foreach ($this->voteMapper->findByPolls(PaceService::order($room)) as $vote) {
            $d = json_decode($vote->getPayload(), true);
            if (!is_array($d)) {
                continue;
            }
            $t = $vote->getVoterToken();
            $row = $progress[$vote->getPollId() . ':' . $t] ?? null;
            if (!PaceService::isFinal($d, $vote->getCreatedAt(), $now, PaceService::correctable($room, $now, $row))) {
                continue;
            }
            $points[$t] = ($points[$t] ?? 0) + (int)($d['points'] ?? 0);
            $time[$t] = ($time[$t] ?? 0) + max(0, (int)($d['elapsed'] ?? 0));
            if (!empty($d['correct'])) {
                $correct[$t] = ($correct[$t] ?? 0) + 1;
            }
        }
        $players = $this->playerMapper->findByRoom($room->getId());
        return $this->quizService->leaderboard($players, $points, $correct, $room->getTimed() ? $time : []);
    }

    /**
     * Ranglistenzeilen für einen Betrachter: fremde Tokens raus, die eigene
     * Zeile mit `me: true` ($meToken = null im Moderator-Blick).
     *
     * @param list<array{token:string, rank:int, nickname:string, score:int, correct:int, time:int}> $rows
     * @return list<array{rank:int, nickname:string, score:int, correct:int, me:bool}>
     */
    private static function forViewer(array $rows, ?string $meToken): array {
        return array_map(static function (array $r) use ($meToken): array {
            return [
                'rank' => $r['rank'],
                'nickname' => $r['nickname'],
                'score' => $r['score'],
                'correct' => $r['correct'],
                'me' => $meToken !== null && $r['token'] === $meToken,
            ];
        }, $rows);
    }

    /**
     * Punktsumme + Anzahl richtiger Antworten + summierte Antwortzeit (elapsed)
     * je Token über alle Fragen des Raums. Die Zeit dient nur als Tie-Break in
     * der Rangliste (QuizService::leaderboard) und verlässt den Server nicht.
     *
     * @return array{0: array<string,int>, 1: array<string,int>, 2: array<string,int>}
     */
    private function quizPointsByToken(Room $room, array $skipPollIds = []): array {
        $points = [];
        $correct = [];
        $time = [];
        foreach ($this->pollMapper->findByRoom($room->getId()) as $poll) {
            if (in_array($poll->getId(), $skipPollIds, true)) {
                continue;
            }
            foreach ($this->voteMapper->findByPoll($poll->getId()) as $vote) {
                $d = json_decode($vote->getPayload(), true);
                if (!is_array($d)) {
                    continue;
                }
                $t = $vote->getVoterToken();
                $points[$t] = ($points[$t] ?? 0) + (int)($d['points'] ?? 0);
                $time[$t] = ($time[$t] ?? 0) + max(0, (int)($d['elapsed'] ?? 0));
                if (!empty($d['correct'])) {
                    $correct[$t] = ($correct[$t] ?? 0) + 1;
                }
            }
        }
        return [$points, $correct, $time];
    }

    /** Anzahl der Spielenden im Raum (für den Lobby-Fingerabdruck). */
    public function playerCount(Room $room): int {
        return $this->playerMapper->countByRoom($room->getId());
    }

    private function sanitizeNickname(string $nickname): string {
        // Steuerzeichen raus, Unsichtbares weg (cleanText), DANACH Leerraum
        // (auch NBSP & Co.) zu einem Leerzeichen — andersherum bliebe von
        // „Anna ␣ZWSP␣ Bob" ein doppeltes Leerzeichen, das HTML als eines zeigt.
        // Dann auf 24 Zeichen kürzen und noch einmal bereinigen: endet der
        // Schnitt auf einem Leerzeichen, wären „Anna " und „Anna" sonst zwei Namen.
        $nickname = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $nickname) ?? '';
        $nickname = preg_replace('/[\s\p{Z}]+/u', ' ', TallyService::cleanText($nickname)) ?? '';
        $nickname = TallyService::cleanText(mb_substr($nickname, 0, 24));
        // Auch ein Name nur aus Variantenwählern o. Ä. ist unsichtbar.
        if ($nickname === '' || TallyService::nameKey($nickname) === '') {
            throw new \InvalidArgumentException($this->l10n->t('Please enter a name.'));
        }
        return $nickname;
    }

    /**
     * Prüft und normalisiert den eingehenden Stimmwert gegen den Fragetyp.
     *
     * @return string|list<string> optionId (choice) bzw. Wörter (words)
     * @throws \InvalidArgumentException
     */
    public function normalizeValue(Poll $poll, mixed $value): mixed {
        $type = $poll->getType();

        // choice + Wahr/Falsch: eine gültige Options-ID.
        if ($type === 'choice' || $type === 'truefalse') {
            if (!is_string($value)) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid selection.'));
            }
            $validIds = array_column($poll->getOptionsArray(), 'id');
            if (!in_array($value, $validIds, true)) {
                throw new \InvalidArgumentException($this->l10n->t('No such option.'));
            }
            return $value;
        }

        // Mehrfachauswahl: Teilmenge gültiger IDs, dedupliziert + sortiert.
        if ($type === 'multi') {
            if (!is_array($value)) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid selection.'));
            }
            $validIds = array_column($poll->getOptionsArray(), 'id');
            $chosen = [];
            foreach ($value as $v) {
                if (is_string($v) && in_array($v, $validIds, true)) {
                    $chosen[$v] = true;
                }
            }
            $chosen = array_keys($chosen);
            if (count($chosen) === 0) {
                throw new \InvalidArgumentException($this->l10n->t('Please choose at least one option.'));
            }
            sort($chosen);
            return $chosen;
        }

        // Reihenfolge: eine vollständige Permutation der Options-IDs. Unvollständig
        // oder mit Dubletten ist keine Rangfolge — lieber ablehnen als raten.
        if ($type === 'rank') {
            if (!is_array($value)) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid order.'));
            }
            $validIds = array_column($poll->getOptionsArray(), 'id');
            $order = [];
            foreach ($value as $v) {
                if (!is_string($v) || !in_array($v, $validIds, true) || in_array($v, $order, true)) {
                    throw new \InvalidArgumentException($this->l10n->t('Invalid order.'));
                }
                $order[] = $v;
            }
            if (count($order) !== count($validIds)) {
                throw new \InvalidArgumentException($this->l10n->t('Please sort all answers.'));
            }
            return $order;
        }

        // Zuordnung: je Item genau ein Ziel. Ziele dürfen mehrfach vorkommen
        // (in der Umfrage ist das eine Aussage, kein Fehler), aber kein Item
        // darf offen bleiben — eine halbe Zuordnung wäre nicht auswertbar.
        if ($type === 'match') {
            if (!is_array($value)) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid assignment.'));
            }
            $cfg = $poll->getMatchConfig();
            $targetIds = array_column($cfg['targets'], 'id');
            $assigned = [];
            foreach ($cfg['items'] as $item) {
                $pick = $value[$item['id']] ?? null;
                if (!is_string($pick) || !in_array($pick, $targetIds, true)) {
                    throw new \InvalidArgumentException($this->l10n->t('Please assign every item.'));
                }
                $assigned[$item['id']] = $pick;
            }
            if ($assigned === []) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid assignment.'));
            }
            return $assigned;
        }

        // Schätzfrage: eine endliche Zahl (int oder float). „1e999“ und JSON 1e999
        // werden in PHP INF — keine Schätzung, und json_encode scheitert daran.
        if ($type === 'number') {
            $n = Input::number($value);
            if ($n === null) {
                throw new \InvalidArgumentException($this->l10n->t('Please enter a number.'));
            }
            return $n;
        }

        // Freitext: getrimmt, Whitespace normalisiert, gekürzt (roh gespeichert).
        if ($type === 'text') {
            if (!is_string($value)) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid answer.'));
            }
            $t = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
            if ($t === '') {
                throw new \InvalidArgumentException($this->l10n->t('Please enter an answer.'));
            }
            return mb_substr($t, 0, DeckService::TEXT_MAX);
        }

        if ($type === 'scale') {
            $cfg = $poll->getScaleConfig();
            // Spektrum: Map aspectId -> Wert (jeder Aspekt im Bereich min..max).
            if (($cfg['mode'] ?? 'single') === 'spectrum') {
                if (!is_array($value)) {
                    throw new \InvalidArgumentException($this->l10n->t('Invalid answer.'));
                }
                $out = [];
                foreach ($cfg['aspects'] as $asp) {
                    $v = Input::int($value[$asp['id']] ?? null);
                    if ($v === null) {
                        throw new \InvalidArgumentException($this->l10n->t('Invalid value.'));
                    }
                    if ($v < $cfg['min'] || $v > $cfg['max']) {
                        throw new \InvalidArgumentException($this->l10n->t('Value is outside the scale.'));
                    }
                    $out[$asp['id']] = $v;
                }
                return $out;
            }
            // Kompass: ein Punkt {x,y}, je -range..range (ganzzahlig).
            if (($cfg['mode'] ?? 'single') === 'compass') {
                if (!is_array($value)) {
                    throw new \InvalidArgumentException($this->l10n->t('Invalid position.'));
                }
                $r = (int)$cfg['range'];
                $ix = Input::int($value['x'] ?? null);
                $iy = Input::int($value['y'] ?? null);
                if ($ix === null || $iy === null) {
                    throw new \InvalidArgumentException($this->l10n->t('Invalid position.'));
                }
                if ($ix < -$r || $ix > $r || $iy < -$r || $iy > $r) {
                    throw new \InvalidArgumentException($this->l10n->t('Position is outside the field.'));
                }
                return ['x' => $ix, 'y' => $iy];
            }
            $v = Input::int($value);
            if ($v === null) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid value.'));
            }
            if ($v < $cfg['min'] || $v > $cfg['max']) {
                throw new \InvalidArgumentException($this->l10n->t('Value is outside the scale.'));
            }
            return $v;
        }

        // words
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException($this->l10n->t('Invalid words.'));
        }
        $words = [];
        foreach ($value as $raw) {
            if (!is_scalar($raw)) {
                continue; // verschachtelte Arrays o. Ä. sind kein Wort
            }
            $word = TallyService::cleanText((string)$raw);
            if ($word !== '') {
                // Anzeige-Kürzung (danach erneut bereinigen, sonst endet das
                // Wort auf einem Leerzeichen); Kleinschreibung erst beim Auszählen.
                $word = TallyService::cleanText(mb_substr($word, 0, 40));
                // Doppelte über den Schlüssel der Auszählung erkennen, damit
                // „Kaffee“ + „KAFFEE“ nicht zweimal in die Wolke geht. Die erste
                // Schreibweise bleibt gespeichert. Bleibt ohne Variantenwähler
                // und Verbinder nichts übrig, ist es kein Wort und belegt keinen
                // Platz (dieselbe Probe wie beim Namen).
                if (TallyService::nameKey($word) !== '') {
                    $words[TallyService::wordKey($word)] ??= $word;
                }
            }
        }
        $words = array_values($words);
        if (count($words) === 0) {
            throw new \InvalidArgumentException($this->l10n->t('Please enter at least one word.'));
        }
        if (count($words) > $poll->getMaxWords()) {
            $words = array_slice($words, 0, $poll->getMaxWords());
        }
        return $words;
    }
}
