<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\AppInfo\Application;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\PresenceMapper;
use OCA\Pulse\Db\Progress;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IL10N;

/**
 * Lesesichten des Quiz im eigenen Tempo an einer Stelle: Handy- und
 * Beamer-Zustand, die Gesamtauswertung fürs Handy, die Bildfreigabe, die
 * Moderator-Ergebnisse, der Fortschritt je Person und Frage und die beiden
 * CSV-Sichten. StateService delegiert in der ersten Zeile hierher (über
 * PaceService::isSelf) — dessen moderierter Code bleibt so unberührt.
 * Schreibt nichts.
 *
 * Regeln für alles, was öffentlich rausgeht:
 * - Urteil, Punkte und Rangliste nur aus endgültigen Stimmen
 *   (PaceService::isFinal mit correctable je Zeile). Nie eine Lösung und nie
 *   eine Verteilung vor der Freigabe, nie die Reihenfolge des Decks.
 * - Die Version ist ein Schlüssel-Hash über den fertig gebauten Zustand ohne
 *   serverNow. Alles Zeitgetriebene (Frist, Endgültigkeit, Zeitablauf) steht
 *   als Zustand im Payload — die Version springt also genau dann, ohne Job
 *   und ohne zweite Rechenvorschrift, die auseinanderlaufen könnte. Dafür
 *   darf außer serverNow nichts im Sekundentakt wechseln (keine Restzeit).
 * - Die teuren Raum-Aggregate (Beamer, öffentliche Rangliste) liegen 2 s im
 *   lokalen Cache; das Handy liest nur eigene Zeilen und Stimmen.
 */
class PaceStateService {
    /** Beamer-Zustand: fertiger Payload so viele Sekunden je Eimer im lokalen Cache. */
    private const BEAMER_BUCKET = 2;
    /** Beamer während des Rennens: höchstens so viele Ranglistenzeilen. */
    private const BEAMER_TOP = 8;

    public function __construct(
        private PaceService $paceService,
        private VoteService $voteService,
        private TallyService $tallyService,
        private RoomService $roomService,        // presentCount
        private DeckService $deckService,        // requirePollInRoom (results)
        private PollMapper $pollMapper,
        private VoteMapper $voteMapper,
        private PlayerMapper $playerMapper,
        private ProgressMapper $progressMapper,
        private PresenceMapper $presenceMapper,
        private ITimeFactory $timeFactory,
        private IDateTimeFormatter $dateTimeFormatter,
        private IConfig $config,                 // Geheimnis für PublicView
        private ICacheFactory $cacheFactory,     // Beamer-Zustand 2 s
        private IL10N $l10n,
    ) {
    }

    // ── Öffentlicher Zustand (/state) ───────────────────────────────────────

    /**
     * Zustand fürs Handy bzw. (spectate) für den Beamer. `results` ist im
     * eigenen Tempo immer null — Verteilungen gibt es nur in /summary nach der
     * Freigabe.
     *
     * Der Beamer-Zustand darf von jedem abgerufen werden und liest das ganze
     * Raum-Aggregat; der fertige Payload liegt deshalb je Raum und 2-s-Eimer
     * im lokalen Cache. Fensterwechsel stehen im Schlüssel und greifen sofort
     * (auch die ablaufende Frist, über den effektiven Schluss), Stimmen und
     * Fortschritt erscheinen höchstens 2 s später. serverNow wird erst nach
     * dem Cache eingesetzt; die Version (ohne serverNow) bleibt im Eimer
     * gleich, 204 wirkt weiter. Ohne APCu wird jedes Mal gebaut.
     */
    public function publicState(Room $room, ?string $voterToken, bool $spectate): array {
        $now = $this->timeFactory->getTime();
        if (!$spectate) {
            return $this->phoneState($room, $voterToken, $now);
        }
        $cache = $this->cacheFactory->createLocal('pulse');
        $key = 'beamer:' . $room->getId() . ':' . $room->getOpenedAt()
            . ':' . PaceService::effectiveClosedAt($room, $now) . ':' . $room->getClosesAt()
            . ':' . $room->getReleasedAt() . ':' . intdiv($now, self::BEAMER_BUCKET);
        $state = $cache->get($key);
        if (!is_array($state)) {
            $state = $this->beamerState($room, $now);
            $cache->set($key, $state, self::BEAMER_BUCKET + 1);
        }
        $state['serverNow'] = $now;
        return $state;
    }

    /**
     * Version eines fertig gebauten Payloads (Handy, Beamer, Moderator-
     * Ergebnisse, Fortschritt): undurchsichtiger Schlüssel-Hash ohne serverNow.
     * Im Fortschritt bleibt außerdem „zuletzt gesehen" draußen — das wechselt
     * mit jedem Handy-Heartbeat (~1,3 s), und der Moderator bekäme im Rennen
     * nie ein 204. Im Hash steht nur `online`.
     */
    public function version(array $payload): string {
        unset($payload['serverNow'], $payload['version']);
        if (isset($payload['players']) && is_array($payload['players'])) {
            foreach ($payload['players'] as &$player) {
                if (is_array($player)) {
                    unset($player['lastSeen']);
                }
            }
            unset($player);
        }
        return PublicView::opaque('self:' . json_encode($payload, JSON_PARTIAL_OUTPUT_ON_ERROR), $this->secret());
    }

    /**
     * Handy: eigene Frage mit persönlicher Uhr, eigener Fortschritt, eigenes
     * Urteil. Nach der Freigabe bekommt jedes Cookie den Endstand (auch ohne
     * Spieler — mehr als den gibt es für Nicht-Teilnehmende nicht).
     */
    private function phoneState(Room $room, ?string $voterToken, int $now): array {
        $state = PaceService::deriveState($room, $now);
        $released = $state === PaceService::STATE_RELEASED;
        $out = $this->head($room, $now);
        if ($state === PaceService::STATE_DRAFT) {
            // Wartezustand: „N dabei" wie in der moderierten Lobby. Nur hier —
            // danach triebe jeder Heartbeat die Version aller Handys hoch.
            $out['present'] = $this->roomService->presentCount($room);
        }
        $out['nickname'] = $this->voteService->playerNickname($room, $voterToken);
        $out['progress'] = null;
        $out['myResult'] = null;
        $out['myScore'] = null;
        if ($released && !$room->getPractice()) {
            $out['leaderboard'] = $this->voteService->selfLeaderboard($room, $voterToken, 0, true);
        }
        if ($voterToken === null || $out['nickname'] === null || $room->getOpenedAt() === 0) {
            return $out;
        }

        $rows = $this->paceService->rows($room, $voterToken);
        $votes = $this->votesByPoll($rows, $voterToken);
        $open = PaceService::openOf($rows);
        $last = PaceService::lastOf($rows);
        $n = count(PaceService::order($room));
        // Abwehr: die Frage der offenen Zeile gibt es nicht mehr -> kein poll,
        // aber `after` zeigt weiter darauf, und /next geht mit ihr weiter.
        $poll = null;
        if ($open !== null) {
            try {
                $poll = $this->pollMapper->find($open->getPollId());
            } catch (DoesNotExistException) {
                $poll = null;
            }
        }
        $limit = $poll !== null ? $this->limitFor($room, $poll) : 0;

        $out['progress'] = [
            'k' => self::kOf($open, $last),
            'n' => $n,
            'started' => $rows !== [],
            'finished' => PaceService::isFinished($n, $last, $last !== null && isset($votes[$last->getPollId()]), $state),
            // Kippt genau einmal (keine Restzeit im Payload, sonst gäbe es nie 204).
            'timeUp' => $open !== null && $limit > 0 && $now - $open->getStartedAt() > $limit,
            'currentPollId' => $open?->getPollId() ?? 0,
            // Der Wert für /next {after}: ohne ihn fände ein neu geladenes Handy
            // nach einem Abbruch zwischen Schließen und Starten nie weiter.
            'after' => PaceService::afterFor($rows),
        ];
        if ($released || PaceService::effectiveFeedback($room) === PaceService::FEEDBACK_EACH) {
            $out['myScore'] = $this->scoreOf($room, $rows, $votes, $now);
        }
        if ($state === PaceService::STATE_OPEN && $open !== null && $poll !== null) {
            $out['poll'] = $this->selfPoll($room, $poll, $open, false);
            $vote = $votes[$poll->getId()] ?? null;
            if ($vote !== null) {
                $out['hasVoted'] = true;
                $out['myValue'] = $vote->getValue();
                $out['myResult'] = $this->verdict($room, $vote, $open, $now, false);
            }
        }
        return $out;
    }

    /**
     * Beamer: das Rennen als Zahlen (wer ist auf welcher Frage, wer ist
     * durch) und die Rangliste — nie Fragen, Optionen oder Verteilungen.
     *
     * Jede gestartete Person zählt genau einmal: fertig (PaceService::isFinished —
     * auch wer die letzte Frage beantwortet, aber „Fertig“ nicht getippt hat, und
     * nach Schluss jede auf der letzten Frage) oder auf ihrer Frage k (kOf: offene
     * Zeile, sonst die zuletzt erreichte — dieselbe Zahl wie am Handy und in der
     * Laufansicht). Nicht gestartet (joined − started) + je Frage + fertig =
     * beigetreten; der Beamer bezieht jeden Balken darauf.
     */
    private function beamerState(Room $room, int $now): array {
        $state = PaceService::deriveState($room, $now);
        $out = $this->head($room, $now);
        // Zuschauer zählen mit, wer gerade da ist (Eingangs-Anzeige), in jedem Zustand.
        $out['present'] = $this->roomService->presentCount($room);

        $order = PaceService::order($room);
        $n = count($order);
        /** @var array<string, Progress[]> $byToken */
        $byToken = [];
        $lastPolls = [];
        foreach ($this->progressMapper->findByRoom($room->getId()) as $row) {
            $byToken[$row->getVoterToken()][] = $row;
            if ($row->getLeftAt() === 0 && $row->getSeq() === $n - 1) {
                $lastPolls[$row->getPollId()] = true;
            }
        }
        // Nur wer offen auf der letzten Frage steht, braucht für isFinished die
        // Stimme — eine Abfrage über diese eine Frage statt über das ganze Deck.
        $answeredLast = [];
        if ($state === PaceService::STATE_OPEN && $lastPolls !== []) {
            foreach ($this->voteMapper->findByPolls(array_keys($lastPolls)) as $vote) {
                $answeredLast[$vote->getPollId() . ':' . $vote->getVoterToken()] = true;
            }
        }
        $onQuestion = array_fill(0, $n, 0);
        $finished = 0;
        foreach ($byToken as $token => $rows) {
            $last = PaceService::lastOf($rows);
            $answered = $last !== null && isset($answeredLast[$last->getPollId() . ':' . $token]);
            if (PaceService::isFinished($n, $last, $answered, $state)) {
                $finished++;
                continue; // durch — nicht zugleich auf der letzten Frage
            }
            // Offene Zeile, sonst die zuletzt erreichte (Abbruch zwischen Schließen
            // und Starten in /next) — nie in keinem Balken.
            $k = self::kOf(PaceService::openOf($rows), $last);
            if ($k >= 1 && $k <= $n) {
                $onQuestion[$k - 1]++; // Abwehr: seq außerhalb der Reihenfolge zählt nirgends
            }
        }
        $out['race'] = [
            'n' => $n,
            'joined' => $this->playerMapper->countByRoom($room->getId()),
            'started' => count($byToken),
            'finished' => $finished,
            'onQuestion' => $onQuestion,
        ];

        // Probelauf: nie eine Rangliste. Nach der Freigabe der volle Endstand;
        // während des Rennens (nur mit Rückmeldung je Frage) die Spitze.
        if ($room->getPractice()) {
            $out['leaderboard'] = null;
        } elseif ($state === PaceService::STATE_RELEASED) {
            $out['leaderboard'] = $this->voteService->selfLeaderboard($room, null, 0, true);
        } elseif (($state === PaceService::STATE_OPEN || $state === PaceService::STATE_CLOSED)
            && PaceService::effectiveFeedback($room) === PaceService::FEEDBACK_EACH) {
            $out['leaderboard'] = $this->voteService->selfLeaderboard($room, null, self::BEAMER_TOP, true);
        }
        return $out;
    }

    /** Gemeinsamer Kopf von Handy- und Beamer-Zustand. */
    private function head(Room $room, int $now): array {
        return [
            'room' => [
                'code' => $room->getCode(),
                'title' => $room->titleOrEmpty(),
                'mode' => $room->getMode(),
                'pace' => $room->getPace(),
            ],
            // Passt das Bundle im Tab nicht mehr zum Server, lädt es sich einmal neu.
            'protocol' => Application::PROTOCOL,
            'serverNow' => $now,
            'practice' => $room->getPractice(),
            'window' => $this->window($room, $now),
            'present' => 0,
            'poll' => null,
            'results' => null,
            'hasVoted' => false,
            'myValue' => null,
            'answered' => 0,
            'leaderboard' => null,
        ];
    }

    // ── Gesamtauswertung fürs Handy (/summary) ──────────────────────────────

    /**
     * Rückblick fürs Handy. Ohne Cookie oder ohne erreichte Frage höchstens der
     * Endstand — /summary ist so nie ein Lösungsschlüssel. Mit Zeilen vor der
     * Freigabe nur die eigenen Fragen (im offenen Fenster nur die verlassenen;
     * die offene steht auf dem Hauptbildschirm), gemischt und ohne Lösung.
     * Nach der Freigabe genau die erreichten Fragen mit Lösung und Auszählung —
     * nur die: sonst reichte ein Tipp auf „Start" für den ganzen Schlüssel.
     *
     * @return array{available:bool, mode:string, pace:string, practice:bool, title:string, window:array, myScore:?int, leaderboard:?list<array>, items:list<array{poll:array, revealed:bool, results:?array, mine:?array}>}
     */
    public function publicSummary(Room $room, ?string $voterToken): array {
        $now = $this->timeFactory->getTime();
        $state = PaceService::deriveState($room, $now);
        $released = $state === PaceService::STATE_RELEASED;
        $out = [
            'available' => false,
            'mode' => $room->getMode(),
            'pace' => $room->getPace(),
            'practice' => $room->getPractice(),
            'title' => $room->titleOrEmpty(),
            'window' => $this->window($room, $now),
            'myScore' => null,
            'leaderboard' => null,
            'items' => [],
        ];
        $rows = ($voterToken !== null && $voterToken !== '') ? $this->paceService->rows($room, $voterToken) : [];
        if ($rows === []) {
            if ($released && !$room->getPractice()) {
                $out['leaderboard'] = $this->voteService->selfLeaderboard($room, null, 0, true);
            }
            $out['available'] = $out['leaderboard'] !== null;
            return $out;
        }

        $votes = $this->votesByPoll($rows, (string)$voterToken);
        if ($released || PaceService::effectiveFeedback($room) === PaceService::FEEDBACK_EACH) {
            $out['myScore'] = $this->scoreOf($room, $rows, $votes, $now);
        }
        $shown = $state === PaceService::STATE_OPEN
            ? array_values(array_filter($rows, static fn (Progress $r): bool => $r->getLeftAt() > 0))
            : $rows;
        $polls = [];
        foreach ($this->pollMapper->findByRoom($room->getId()) as $poll) {
            $polls[$poll->getId()] = $poll;
        }
        // Nach der Freigabe die Auszählung aller Stimmen — in EINER Abfrage für
        // die erreichten Fragen.
        $all = [];
        if ($released) {
            $ids = array_map(static fn (Progress $r): int => $r->getPollId(), $shown);
            foreach ($this->voteMapper->findByPolls($ids) as $vote) {
                $all[$vote->getPollId()][] = $vote;
            }
        }
        foreach ($shown as $row) {
            $poll = $polls[$row->getPollId()] ?? null;
            if ($poll === null) {
                continue; // Abwehr: gelöschte Frage
            }
            $vote = $votes[$poll->getId()] ?? null;
            $out['items'][] = [
                'poll' => $this->selfPoll($room, $poll, $row, $released),
                'revealed' => $released,
                'results' => $released ? $this->tallyService->tally($poll, $all[$poll->getId()] ?? []) : null,
                'mine' => $vote === null ? null
                    : ['value' => $vote->getValue()] + $this->verdict($room, $vote, $row, $now, $released),
            ];
        }
        if ($released && !$room->getPractice()) {
            $out['leaderboard'] = $this->voteService->selfLeaderboard($room, $voterToken, 0, true);
        }
        $out['available'] = $out['items'] !== [] || $out['leaderboard'] !== null;
        return $out;
    }

    /**
     * Bild einer Frage nur für Personen, die sie erreicht haben — in jedem
     * Fensterzustand, also auch nach der Freigabe nur für erreichte Fragen.
     */
    public function imageVisible(Room $room, Poll $poll, ?string $voterToken): bool {
        return $voterToken !== null && $voterToken !== ''
            && $this->progressMapper->hasRow($poll->getId(), $voterToken);
    }

    // ── Moderator ───────────────────────────────────────────────────────────

    /**
     * Ergebnisse einer Frage für den Moderator: Auszählung aller Stimmen,
     * dazu Fenster und Rangliste. Es gibt keine gemeinsame Uhr (startedAt 0),
     * die Frage läuft für jede Person ab ihrem /next.
     *
     * @throws \InvalidArgumentException Frage gehört nicht zum Raum
     */
    public function results(Room $room, int $pollId): array {
        $poll = $this->deckService->requirePollInRoom($room, $pollId);
        $now = $this->timeFactory->getTime();
        $tally = $this->tallyService->tally($poll, $this->voteMapper->findByPoll($poll->getId()));
        $tally['present'] = $this->roomService->presentCount($room);
        $tally['serverNow'] = $now;
        $tally['startedAt'] = 0;
        $tally['timeLimit'] = $this->limitFor($room, $poll);
        $tally['status'] = 'active';
        $tally['practice'] = $room->getPractice();
        $tally['pace'] = $room->getPace();
        $tally['window'] = $this->window($room, $now);
        $tally['leaderboard'] = $room->getPractice() ? [] : $this->voteService->selfLeaderboard($room, null);
        return $tally;
    }

    /**
     * Fortschritt je Person und Frage (Rennen/Hausaufgabe) für den Moderator,
     * ohne Version (die setzt der Controller über version()).
     *
     * Bei „Rückmeldung am Ende" sind Punkte, Treffer und Rangliste bis zur
     * Freigabe verdeckt, außer mit $scores (ausdrücklicher Schalter): der
     * Laptop hängt oft am Beamer, und sähe Anna ihr `correct` springen, wäre
     * die Rückmeldung am Ende für alle Zuschauenden aufgehoben. Punkte zählen
     * nur aus endgültigen Stimmen; `pendingAnswers` trägt nie eine Lösung.
     */
    public function progress(Room $room, bool $scores = false): array {
        $now = $this->timeFactory->getTime();
        $state = PaceService::deriveState($room, $now);
        $released = $state === PaceService::STATE_RELEASED;
        $hidden = !$scores && !$released && PaceService::effectiveFeedback($room) === PaceService::FEEDBACK_END;
        $order = PaceService::order($room);
        $n = count($order);
        $kOf = array_flip($order); // pollId -> Index in der Reihenfolge

        $polls = [];
        foreach ($this->pollMapper->findByRoom($room->getId()) as $poll) {
            $polls[$poll->getId()] = $poll;
        }
        [$byToken, $rowAt] = $this->indexRows($this->progressMapper->findByRoom($room->getId()));
        $reached = [];
        foreach ($rowAt as $row) {
            $reached[$row->getPollId()] = ($reached[$row->getPollId()] ?? 0) + 1;
        }

        $perQuestion = [];
        $perToken = [];
        $pending = []; // pollId -> norm -> {answer, count}
        foreach ($this->voteMapper->findByPolls($order) as $vote) {
            $d = self::payload($vote);
            $pid = $vote->getPollId();
            $t = $vote->getVoterToken();
            $q = &$perQuestion[$pid];
            $q ??= ['answered' => 0, 'correct' => 0, 'pending' => 0];
            $s = &$perToken[$t];
            $s ??= ['answered' => 0, 'correct' => 0, 'score' => 0, 'pending' => 0, 'voted' => [], 'lastVote' => 0];
            $q['answered']++;
            $s['answered']++;
            $s['voted'][$pid] = true;
            $s['lastVote'] = max($s['lastVote'], $vote->getCreatedAt());
            if (!empty($d['pending'])) {
                $q['pending']++;
                $s['pending']++;
                $raw = is_string($d['value'] ?? null) ? $d['value'] : '';
                $norm = (string)($d['norm'] ?? TallyService::normalizeText($raw));
                $pending[$pid][$norm] ??= ['answer' => $raw, 'count' => 0];
                $pending[$pid][$norm]['count']++;
            }
            $correctable = PaceService::correctable($room, $now, $rowAt[$pid . ':' . $t] ?? null);
            if (PaceService::isFinal($d, $vote->getCreatedAt(), $now, $correctable)) {
                $s['score'] += (int)($d['points'] ?? 0);
                if (!empty($d['correct'])) {
                    $q['correct']++;
                    $s['correct']++;
                }
            }
            unset($q, $s);
        }

        $questions = [];
        foreach ($order as $i => $pid) {
            $poll = $polls[$pid] ?? null;
            if ($poll === null) {
                continue; // Abwehr: gelöschte Frage
            }
            $q = $perQuestion[$pid] ?? ['answered' => 0, 'correct' => 0, 'pending' => 0];
            $questions[] = [
                'pollId' => $pid,
                'k' => $i + 1,
                'type' => $poll->getType(),
                'question' => $poll->getQuestion(),
                'reached' => $reached[$pid] ?? 0,
                'answered' => $q['answered'],
                'correct' => $hidden ? null : $q['correct'],
                'pending' => $q['pending'],
            ];
        }

        $lastSeen = [];
        foreach ($this->presenceMapper->findByRoom($room->getId()) as $presence) {
            $lastSeen[$presence->getVoterToken()] = $presence->getLastSeen();
        }
        $players = [];
        foreach ($this->playerMapper->findByRoom($room->getId()) as $player) {
            $t = $player->getVoterToken();
            $rows = $byToken[$t] ?? [];
            $s = $perToken[$t] ?? ['answered' => 0, 'correct' => 0, 'score' => 0, 'pending' => 0, 'voted' => [], 'lastVote' => 0];
            $open = PaceService::openOf($rows);
            $last = PaceService::lastOf($rows);
            $seen = $lastSeen[$t] ?? 0;
            $players[] = [
                'id' => $player->getId(),
                'nickname' => $player->getNickname(),
                'k' => self::kOf($open, $last),
                'started' => $rows !== [],
                'finished' => PaceService::isFinished($n, $last, $last !== null && isset($s['voted'][$last->getPollId()]), $state),
                'currentPollId' => $open?->getPollId() ?? 0,
                'currentStartedAt' => $open?->getStartedAt() ?? 0,
                'answered' => $s['answered'],
                // Verlassen ohne Antwort: Signal für Wegwerf-Spieler, die nur durchblättern.
                'skipped' => count(array_filter(
                    $rows,
                    static fn (Progress $r): bool => $r->getLeftAt() > 0 && !isset($s['voted'][$r->getPollId()]),
                )),
                'correct' => $hidden ? null : $s['correct'],
                'score' => $hidden ? null : $s['score'],
                'pending' => $s['pending'],
                'startedAt' => self::firstStart($rows),
                'lastActivity' => max($s['lastVote'], self::lastRowActivity($rows)),
                'online' => $seen > 0 && $seen >= $now - RoomService::PRESENCE_WINDOW,
                'lastSeen' => $seen,
            ];
        }
        usort($players, static fn (array $a, array $b): int => strcasecmp($a['nickname'], $b['nickname']));

        $pendingAnswers = [];
        foreach ($pending as $pid => $groups) {
            foreach ($groups as $g) {
                $pendingAnswers[] = ['pollId' => $pid, 'k' => ($kOf[$pid] ?? 0) + 1, 'answer' => $g['answer'], 'count' => $g['count']];
            }
        }
        usort($pendingAnswers, static fn (array $a, array $b): int => ($a['k'] <=> $b['k']) ?: ($b['count'] <=> $a['count']));

        return [
            'pace' => $room->getPace(),
            'practice' => $room->getPractice(),
            'serverNow' => $now,
            'present' => $this->roomService->presentCount($room),
            'window' => PaceService::windowView($room, $now, count($polls)),
            'n' => $n,
            'questions' => $questions,
            'players' => $players,
            'scoresHidden' => $hidden,
            'pendingAnswers' => $pendingAnswers,
            // Probelauf: keine Wertung. Frisch gerechnet (nur ein Moderator).
            'leaderboard' => $room->getPractice() ? [] : ($hidden ? null : $this->voteService->selfLeaderboard($room, null)),
        ];
    }

    // ── CSV ─────────────────────────────────────────────────────────────────

    /**
     * CSV-Sicht für die Lehrkraft: `players` (eine Zeile je Person, Reihenfolge
     * der Rangliste) oder `answers` (eine Zeile je Stimme, nach Frage, dann
     * Name). `;`-getrennt + UTF-8-BOM wie der moderierte Export.
     *
     * @throws \InvalidArgumentException unbekannte Sicht
     */
    public function exportCsv(Room $room, string $view): string {
        $lines = match ($view) {
            'players' => $this->playerLines($room),
            'answers' => $this->answerLines($room),
            default => throw new \InvalidArgumentException($this->l10n->t('Invalid request.')),
        };
        $fh = fopen('php://temp', 'r+');
        foreach ($lines as $line) {
            // Ohne Backslash-Escape (RFC 4180, wie Excel liest).
            fputcsv($fh, $line, ';', '"', '');
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return "\xEF\xBB\xBF" . $csv; // UTF-8-BOM
    }

    /**
     * Rang, Punkte und Treffer kommen aus der Rangliste (frisch, nur
     * endgültige Stimmen). „Zeit" summiert nur Stimmen mit Timer — ohne Timer
     * enthält elapsed den ganzen Leerlauf zwischen /next und Antwort, bei
     * Hausaufgaben Stunden; hat keine Stimme ein Limit, bleibt die Zelle leer.
     *
     * @return list<list<int|string>>
     */
    private function playerLines(Room $room): array {
        $now = $this->timeFactory->getTime();
        $state = PaceService::deriveState($room, $now);
        $order = PaceService::order($room);
        $n = count($order);
        [$byToken, $rowAt] = $this->indexRows($this->progressMapper->findByRoom($room->getId()));

        $perToken = [];
        foreach ($this->voteMapper->findByPolls($order) as $vote) {
            $d = self::payload($vote);
            $t = $vote->getVoterToken();
            $s = &$perToken[$t];
            $s ??= ['answered' => 0, 'voted' => [], 'lastVote' => 0, 'time' => null];
            $s['answered']++;
            $s['voted'][$vote->getPollId()] = true;
            $s['lastVote'] = max($s['lastVote'], $vote->getCreatedAt());
            $correctable = PaceService::correctable($room, $now, $rowAt[$vote->getPollId() . ':' . $t] ?? null);
            if ((int)($d['limit'] ?? 0) > 0 && PaceService::isFinal($d, $vote->getCreatedAt(), $now, $correctable)) {
                $s['time'] = ($s['time'] ?? 0) + max(0, (int)($d['elapsed'] ?? 0));
            }
            unset($s);
        }

        $lines = [[
            $this->l10n->t('Rank'),
            $this->l10n->t('Name'),
            $this->l10n->t('Score'),
            $this->l10n->t('Correct'),
            $this->l10n->t('Answered'),
            $this->l10n->t('Reached'),
            $this->l10n->t('Finished'),
            $this->l10n->t('Started'),
            $this->l10n->t('Last activity'),
            $this->l10n->t('Time (s)'),
        ]];
        foreach ($this->voteService->selfLeaderboardRows($room, $now) as $lb) {
            $t = $lb['token'];
            $rows = $byToken[$t] ?? [];
            $s = $perToken[$t] ?? ['answered' => 0, 'voted' => [], 'lastVote' => 0, 'time' => null];
            $last = PaceService::lastOf($rows);
            $finished = PaceService::isFinished($n, $last, $last !== null && isset($s['voted'][$last->getPollId()]), $state);
            $lines[] = [
                $lb['rank'],
                CsvFormat::cell($lb['nickname']),
                $lb['score'],
                $lb['correct'],
                $s['answered'],
                // Mit Leerzeichen: „3/7" läse Excel als Datum.
                self::kOf(PaceService::openOf($rows), $last) . ' / ' . $n,
                $finished ? $this->l10n->t('yes') : $this->l10n->t('no'),
                $this->date(self::firstStart($rows)),
                $this->date(max($s['lastVote'], self::lastRowActivity($rows))),
                $s['time'] ?? '',
            ];
        }
        return $lines;
    }

    /**
     * Eine Zeile je Stimme. Punkte und Ergebnis nur für endgültige Stimmen
     * („—" sonst); die Zeit bleibt leer, wenn die Stimme ohne Timer kam.
     *
     * @return list<list<int|string>>
     */
    private function answerLines(Room $room): array {
        $now = $this->timeFactory->getTime();
        $order = PaceService::order($room);
        $kOf = array_flip($order);
        [, $rowAt] = $this->indexRows($this->progressMapper->findByRoom($room->getId()));
        $polls = [];
        foreach ($this->pollMapper->findByRoom($room->getId()) as $poll) {
            $polls[$poll->getId()] = $poll;
        }
        $names = [];
        foreach ($this->playerMapper->findByRoom($room->getId()) as $player) {
            $names[$player->getVoterToken()] = $player->getNickname();
        }

        $entries = [];
        foreach ($this->voteMapper->findByPolls($order) as $vote) {
            $poll = $polls[$vote->getPollId()] ?? null;
            if ($poll === null) {
                continue; // Abwehr: gelöschte Frage
            }
            $d = self::payload($vote);
            $t = $vote->getVoterToken();
            $correctable = PaceService::correctable($room, $now, $rowAt[$poll->getId() . ':' . $t] ?? null);
            $final = PaceService::isFinal($d, $vote->getCreatedAt(), $now, $correctable);
            $result = match (true) {
                !$final => '—',
                !empty($d['pending']) => $this->l10n->t('Pending'),
                !empty($d['correct']) => $this->l10n->t('Correct'),
                default => $this->l10n->t('Wrong'),
            };
            $k = ($kOf[$poll->getId()] ?? 0) + 1;
            $name = $names[$t] ?? '';
            $entries[] = [$k, $name, [
                CsvFormat::cell($name),
                $k,
                $poll->getQuestion(),
                CsvFormat::typeLabel($poll->getType(), $this->l10n),
                $this->answerText($poll, $d['value'] ?? null),
                $result,
                $final ? (int)($d['points'] ?? 0) : '',
                (int)($d['limit'] ?? 0) > 0 ? max(0, (int)($d['elapsed'] ?? 0)) : '',
                $this->date($vote->getCreatedAt()),
            ]];
        }
        usort($entries, static fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: strcasecmp($a[1], $b[1]));

        $lines = [[
            $this->l10n->t('Name'),
            $this->l10n->t('No.'),
            $this->l10n->t('Question'),
            $this->l10n->t('Type'),
            $this->l10n->t('Answer'),
            $this->l10n->t('Result'),
            $this->l10n->t('Points'),
            $this->l10n->t('Time (s)'),
            $this->l10n->t('Answered at'),
        ]];
        foreach ($entries as $entry) {
            $lines[] = $entry[2];
        }
        return $lines;
    }

    /** Antwort lesbar: Optionstexte statt IDs, Zahl in der Sprache des Exports. */
    private function answerText(Poll $poll, mixed $value): string {
        $labels = [];
        foreach ($poll->getOptionsArray() as $option) {
            if (is_array($option)) {
                $labels[(string)($option['id'] ?? '')] = (string)($option['label'] ?? '');
            }
        }
        $label = static fn (mixed $id): string => $labels[(string)$id] ?? (string)$id;
        $list = static fn (mixed $v): array => is_array($v) ? array_values(array_filter($v, 'is_scalar')) : [];
        switch ($poll->getType()) {
            case 'choice':
            case 'truefalse':
                return is_scalar($value) ? $label($value) : '';
            case 'multi':
                return implode(', ', array_map($label, $list($value)));
            case 'rank':
                return implode(' > ', array_map($label, $list($value)));
            case 'match':
                $cfg = $poll->getMatchConfig();
                $targets = array_column($cfg['targets'], 'label', 'id');
                $pairs = [];
                foreach ($cfg['items'] as $item) {
                    $pick = is_array($value) ? ($value[$item['id']] ?? null) : null;
                    if (is_scalar($pick)) {
                        $pairs[] = $this->l10n->t('%1$s → %2$s', [$item['label'], $targets[(string)$pick] ?? (string)$pick]);
                    }
                }
                return implode('; ', $pairs);
            case 'number':
                return is_numeric($value) ? CsvFormat::number($value, $this->l10n->getLocaleCode()) : '';
            default:
                // Freitext roh (nur gegen Formeln entschärft, s. CsvFormat::cell()).
                return is_string($value) ? CsvFormat::cell($value) : '';
        }
    }

    // ── Bausteine ───────────────────────────────────────────────────────────

    /**
     * Urteil über eine eigene Stimme — EINE Funktion für Zustand und
     * Zusammenfassung. Nie Lösung, nie Verteilung, nur das eigene Urteil:
     * nicht endgültig -> noch keins; „Rückmeldung am Ende" vor der Freigabe ->
     * nur „gespeichert"; Freitext ohne Bewertung -> „wird geprüft".
     *
     * @param ?Progress $row Zeile der Person zu dieser Frage (für correctable)
     * @return array{answered:bool, final:bool, verdict:?string, correct:?bool, points:?int}
     */
    private function verdict(Room $room, Vote $vote, ?Progress $row, int $now, bool $released): array {
        $d = self::payload($vote);
        $final = PaceService::isFinal($d, $vote->getCreatedAt(), $now, PaceService::correctable($room, $now, $row));
        $out = ['answered' => true, 'final' => $final, 'verdict' => null, 'correct' => null, 'points' => null];
        if (!$final) {
            return $out;
        }
        if (PaceService::effectiveFeedback($room) === PaceService::FEEDBACK_END && !$released) {
            $out['verdict'] = 'saved';
        } elseif (!empty($d['pending'])) {
            $out['verdict'] = 'pending';
        } elseif (!empty($d['correct'])) {
            $out['verdict'] = 'correct';
            $out['correct'] = true;
            $out['points'] = (int)($d['points'] ?? 0);
        } else {
            $out['verdict'] = 'wrong';
            $out['correct'] = false;
            $out['points'] = 0;
        }
        return $out;
    }

    /**
     * Eigene Punkte: Summe der endgültigen Stimmen (Freitext „wird geprüft"
     * zählt 0).
     *
     * @param Progress[] $rows eigene Zeilen
     * @param array<int, Vote> $votes eigene Stimmen je pollId
     */
    private function scoreOf(Room $room, array $rows, array $votes, int $now): int {
        $rowFor = [];
        foreach ($rows as $row) {
            $rowFor[$row->getPollId()] = $row;
        }
        $sum = 0;
        foreach ($votes as $pollId => $vote) {
            $d = self::payload($vote);
            $correctable = PaceService::correctable($room, $now, $rowFor[$pollId] ?? null);
            if (empty($d['pending']) && PaceService::isFinal($d, $vote->getCreatedAt(), $now, $correctable)) {
                $sum += (int)($d['points'] ?? 0);
            }
        }
        return $sum;
    }

    /**
     * Frage für die öffentliche Sicht aus Sicht dieser Person: vor der
     * Freigabe gemischt und ohne Lösung (PublicView::poll), eigene Uhr statt
     * der des Cursors, ohne Timer Limit 0 (kein Countdown), Position = eigene
     * Stelle in der eingefrorenen Reihenfolge.
     */
    private function selfPoll(Room $room, Poll $poll, Progress $row, bool $revealed): array {
        $data = PublicView::poll($room, $poll, $revealed, $this->secret());
        $data['startedAt'] = $row->getStartedAt();
        $data['timeLimit'] = $this->limitFor($room, $poll);
        $data['position'] = $row->getSeq();
        $data['status'] = 'active';
        $data['revealed'] = $revealed;
        if ($revealed) {
            $data['correctOption'] = $poll->getCorrectOption();
            $data['answerKey'] = $poll->getAnswerKeyArray();
        }
        return $data;
    }

    /** Fenster für Zustand und Auswertung; die Deckgröße braucht es nur im Entwurf. */
    private function window(Room $room, int $now): array {
        $deckCount = $room->getOpenedAt() > 0 ? 0 : $this->pollMapper->countByRoom($room->getId());
        return PaceService::windowView($room, $now, $deckCount);
    }

    /** Zeitlimit im Fenster: ohne Timer 0 (kein Limit, flache Punkte). */
    private function limitFor(Room $room, Poll $poll): int {
        return $room->getTimed() ? $poll->getTimeLimit() : 0;
    }

    /**
     * Eigene Stimmen zu den erreichten Fragen, in EINER Abfrage.
     *
     * @param Progress[] $rows
     * @return array<int, Vote> je pollId
     */
    private function votesByPoll(array $rows, string $voterToken): array {
        $ids = array_map(static fn (Progress $r): int => $r->getPollId(), $rows);
        $out = [];
        foreach ($this->voteMapper->findByPolls($ids, $voterToken) as $vote) {
            $out[$vote->getPollId()] = $vote;
        }
        return $out;
    }

    /**
     * Fortschrittszeilen eines Raums je Token und je „pollId:token".
     *
     * @param Progress[] $rows
     * @return array{0: array<string, Progress[]>, 1: array<string, Progress>}
     */
    private function indexRows(array $rows): array {
        $byToken = [];
        $rowAt = [];
        foreach ($rows as $row) {
            $byToken[$row->getVoterToken()][] = $row;
            $rowAt[$row->getPollId() . ':' . $row->getVoterToken()] = $row;
        }
        return [$byToken, $rowAt];
    }

    /** „Frage k von n": die offene, sonst die zuletzt erreichte, sonst 0. */
    private static function kOf(?Progress $open, ?Progress $last): int {
        if ($open !== null) {
            return $open->getSeq() + 1;
        }
        return $last !== null ? $last->getSeq() + 1 : 0;
    }

    /** @param Progress[] $rows */
    private static function firstStart(array $rows): int {
        $starts = array_map(static fn (Progress $r): int => $r->getStartedAt(), $rows);
        return $starts === [] ? 0 : min($starts);
    }

    /** @param Progress[] $rows */
    private static function lastRowActivity(array $rows): int {
        $last = 0;
        foreach ($rows as $row) {
            $last = max($last, $row->getStartedAt(), $row->getLeftAt());
        }
        return $last;
    }

    /** @return array<string, mixed> */
    private static function payload(Vote $vote): array {
        $d = json_decode($vote->getPayload(), true);
        return is_array($d) ? $d : [];
    }

    /** Zeitpunkt für die CSV, leer bei 0. */
    private function date(int $ts): string {
        return $ts > 0 ? (string)$this->dateTimeFormatter->formatDateTime($ts, 'short', 'medium') : '';
    }

    private function secret(): string {
        return $this->config->getSystemValueString('secret', '');
    }
}
