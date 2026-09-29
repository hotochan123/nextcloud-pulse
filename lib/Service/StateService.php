<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\AppInfo\Application;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\VoteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Lesesichten auf einen Raum: Live-Ergebnisse für den Moderator, der öffentliche
 * Teilnehmer-Zustand, die Gesamtauswertung (intern wie öffentlich), der
 * CSV-Export und die billigen Änderungs-Fingerabdrücke fürs adaptive Polling.
 * Schreibt nichts.
 *
 * Quiz im eigenen Tempo (PaceService::isSelf): die öffentlichen Sichten, die
 * Moderator-Ergebnisse und ihre Versionen delegieren in der ersten Zeile an
 * PaceStateService — der moderierte Code hier bleibt unberührt.
 */
class StateService {
    public function __construct(
        private PollMapper $pollMapper,
        private VoteMapper $voteMapper,
        private TallyService $tallyService,
        private DeckService $deckService,
        private VoteService $voteService,
        private RoomService $roomService,
        private ITimeFactory $timeFactory,
        private IL10N $l10n,
        private IConfig $config,
        // nur im eigenen Tempo angefasst:
        private PaceStateService $paceState,
    ) {
    }

    /**
     * Gesamt-Zusammenfassung: jede Frage des Decks mit ihrer Auszählung.
     *
     * @return list<array{poll:array, results:array}>
     */
    public function summary(Room $room): array {
        $out = [];
        foreach ($this->deckService->deck($room) as $poll) {
            $out[] = [
                'poll' => $this->deckService->ownerPoll($poll),
                'results' => $this->tallyService->tally($poll, $this->voteMapper->findByPoll($poll->getId())),
            ];
        }
        return $out;
    }

    /**
     * Ergebnisse aller Fragen als CSV (Long-Format, eine Zeile je Antwort).
     * `;`-getrennt + UTF-8-BOM → öffnet in Excel korrekt mit Umlauten; Zahlen
     * in der Sprache des Exports (siehe num()). Anführungszeichen nach RFC 4180
     * verdoppelt, ohne Backslash-Escape (wie PaceStateService; PHP 8.4 will das
     * Escape-Zeichen ausdrücklich).
     */
    public function exportCsv(Room $room): string {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, [
            $this->l10n->t('No.'),
            $this->l10n->t('Question'),
            $this->l10n->t('Type'),
            $this->l10n->t('Answer'),
            $this->l10n->t('Votes'),
            $this->l10n->t('Share'),
        ], ';', '"', '');

        $nr = 0;
        foreach ($this->deckService->deck($room) as $poll) {
            $nr++;
            $tally = $this->tallyService->tally($poll, $this->voteMapper->findByPoll($poll->getId()));
            $total = (int)$tally['total'];
            $question = $poll->getQuestion();
            $typeLabel = $this->typeLabel($poll->getType());

            // Spektrum: eine Zeile je Aspekt (Ø + Antwortzahl) statt Wert/Anteil.
            if ($poll->getType() === 'scale' && ($tally['mode'] ?? 'single') === 'spectrum') {
                foreach ($tally['results'] as $row) {
                    $avg = $this->num($row['average']);
                    $label = $this->l10n->t('%s · average', [$row['label']]);
                    fputcsv($fh, [$nr, $question, $typeLabel, $label, $avg, $row['n']], ';', '"', '');
                }
                if (count($tally['results']) === 0) {
                    fputcsv($fh, [$nr, $question, $typeLabel, $this->l10n->t('(no aspects)'), 0, ''], ';', '"', '');
                }
                continue;
            }

            // Kompass: Schwerpunkt + je Stimme ein Punkt (X / Y).
            if ($poll->getType() === 'scale' && ($tally['mode'] ?? 'single') === 'compass') {
                $c = $tally['centroid'] ?? null;
                $ct = $c
                    ? $this->num($c['x']) . ' / ' . $this->num($c['y'])
                    : '—';
                fputcsv($fh, [$nr, $question, $typeLabel, $this->l10n->t('Centre of gravity (X / Y)'), $ct, $tally['total']], ';', '"', '');
                foreach ($tally['points'] as $p) {
                    fputcsv($fh, [$nr, $question, $typeLabel, $this->l10n->t('Point (X / Y)'), $p['x'] . ' / ' . $p['y'], ''], ';', '"', '');
                }
                continue;
            }

            // Zuordnung: eine Zeile je tatsächlich gewählter Paarung „Item → Ziel".
            // Alle Kombinationen wären bei acht Paaren 64 Zeilen Rauschen.
            if ($poll->getType() === 'match') {
                if ($total === 0) {
                    fputcsv($fh, [$nr, $question, $typeLabel, $this->l10n->t('(no answers)'), 0, ''], ';', '"', '');
                    continue;
                }
                foreach ($tally['results'] as $row) {
                    foreach ($row['targets'] as $target) {
                        if ($target['count'] === 0) {
                            continue;
                        }
                        $share = $row['n'] > 0 ? round($target['count'] / $row['n'] * 100) . '%' : '';
                        $label = $this->l10n->t('%1$s → %2$s', [$row['label'], $target['label']]);
                        fputcsv($fh, [$nr, $question, $typeLabel, $label, $target['count'], $share], ';', '"', '');
                    }
                }
                continue;
            }

            // Reihenfolge: eine Zeile je Option mit Ø-Platz und Zahl der Erstplätze.
            if ($poll->getType() === 'rank') {
                foreach ($tally['results'] as $rank => $row) {
                    $avg = $this->num($row['average']);
                    fputcsv($fh, [
                        $nr, $question, $typeLabel,
                        $this->l10n->t('%1$s. %2$s · average place %3$s', [$rank + 1, $row['label'], $avg]),
                        $row['first'], $row['n'],
                    ], ';', '"', '');
                }
                if (count($tally['results']) === 0) {
                    fputcsv($fh, [$nr, $question, $typeLabel, $this->l10n->t('(no answers)'), 0, ''], ';', '"', '');
                }
                continue;
            }

            // Freitext: eine Zeile je Antwortgruppe (gleiche Normalform) in der
            // Schreibweise der ersten Antwort, wie beim Bewerten, gegen Formeln
            // entschärft (CsvFormat::cell). Die Auszählung heißt hier `answers`,
            // nicht `results` — der allgemeine Zweig unten lief deshalb in einen
            // TypeError (500).
            if ($poll->getType() === 'text') {
                foreach ($tally['answers'] as $row) {
                    $count = (int)$row['count'];
                    $share = $total > 0 ? round($count / $total * 100) . '%' : '';
                    fputcsv($fh, [$nr, $question, $typeLabel, CsvFormat::cell((string)$row['sample']), $count, $share], ';', '"', '');
                }
                if (count($tally['answers']) === 0) {
                    fputcsv($fh, [$nr, $question, $typeLabel, $this->l10n->t('(no answers)'), 0, ''], ';', '"', '');
                }
                continue;
            }

            foreach ($tally['results'] as $row) {
                $count = (int)$row['count'];
                $share = $total > 0 ? round($count / $total * 100) . '%' : '';
                fputcsv($fh, [$nr, $question, $typeLabel, $this->answerLabel($poll->getType(), $row), $count, $share], ';', '"', '');
            }
            if ($poll->getType() === 'scale') {
                fputcsv($fh, [$nr, $question, $typeLabel, $this->l10n->t('Average'), $this->num($tally['average']), ''], ';', '"', '');
            }
            if (count($tally['results']) === 0) {
                // Wortwolke ohne Antworten -> Frage trotzdem im Export sichtbar.
                fputcsv($fh, [$nr, $question, $typeLabel, $this->l10n->t('(no answers)'), 0, ''], ';', '"', '');
            }
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return "\xEF\xBB\xBF" . $csv; // UTF-8-BOM
    }

    /** Zahl in der Sprache des Exports (Regel in CsvFormat::number). */
    private function num(float|int|string $value): string {
        return CsvFormat::number($value, $this->l10n->getLocaleCode());
    }

    /** Typ-Bezeichnung (Regel in CsvFormat::typeLabel, auch für den Export im eigenen Tempo). */
    private function typeLabel(string $type): string {
        return CsvFormat::typeLabel($type, $this->l10n);
    }

    /**
     * Wörter kommen aus dem Publikum und laufen durch CsvFormat::cell; eine
     * Schätzung trägt ihre Zahl in `value` (kein `label` — die Spalte blieb leer).
     *
     * @param array $row Ergebniszeile aus TallyService
     */
    private function answerLabel(string $type, array $row): string {
        return match ($type) {
            'words' => CsvFormat::cell((string)($row['word'] ?? '')),
            'scale' => (string)($row['value'] ?? ''),
            'number' => $this->num($row['value'] ?? 0),
            default => (string)($row['label'] ?? ''),
        };
    }

    /**
     * @return array{type:string, total:int, results:array, present:int}
     * @throws \InvalidArgumentException Frage gehört nicht zum Raum
     */
    public function results(Room $room, int $pollId): array {
        if (PaceService::isSelf($room)) {
            return $this->paceState->results($room, $pollId);
        }
        $poll = $this->deckService->requirePollInRoom($room, $pollId);
        $tally = $this->tallyService->tally($poll, $this->voteMapper->findByPoll($poll->getId()));
        // Live-Teilnehmerzahl fürs Moderator-Badge huckepack im Poll (2,5 s).
        $tally['present'] = $this->roomService->presentCount($room);
        if ($room->getMode() === 'quiz') {
            // Serverzeit + Frage-Timing für den Countdown, Status + Live-Rangliste.
            $tally['serverNow'] = $this->timeFactory->getTime();
            $tally['startedAt'] = $poll->getStartedAt();
            $tally['timeLimit'] = $poll->getTimeLimit();
            $tally['status'] = $poll->getStatus();
            $tally['practice'] = $room->getPractice();
            // Keine mitlaufende Rangliste im Probelauf (keine Wertung) und bei
            // „Auflösung am Ende" (die gibt es erst beim Endstand).
            $tally['leaderboard'] = ($room->getPractice() || $room->getRevealAtEnd())
                ? [] : $this->voteService->leaderboardFor($room, null);
        }
        return $tally;
    }

    // ── Änderungs-Version (adaptives Polling) ───────────────────────────────

    /**
     * Billiger Fingerabdruck des Teilnehmer-Zustands: ändert sich genau dann,
     * wenn sich für Teilnehmer etwas zu sehen ändert (Frage gewechselt, gesperrt/
     * aufgelöst, Timer neu gestartet, neue Stimme). Kostet einen Row-Fetch + ein
     * COUNT — kein Tally. Der Client pollt mit `?v=<version>`; stimmt sie überein,
     * antwortet der Controller mit 204 (winzig), sonst mit dem vollen Zustand.
     * Bewusst OHNE Präsenz (die zählt nur der Moderator; sonst triebe jeder
     * Heartbeat die Version hoch → alle würden dauernd voll pollen).
     *
     * Im eigenen Tempo gibt es keinen billigen Fingerabdruck: die Version ist
     * ein Hash über den fertig gebauten Zustand dieses Tokens (selfVersion).
     * $voterToken zählt nur dort; moderiert ist die Version für alle gleich.
     */
    public function stateVersion(Room $room, bool $withPresence = false, ?string $voterToken = null): string {
        if (PaceService::isSelf($room)) {
            return $this->selfVersion($this->paceState->publicState($room, $voterToken, $withPresence));
        }
        $activeId = $room->getActivePollId();
        if ($activeId === 0) {
            // Lobby: der „N dabei"-Zähler soll live sein -> Präsenzzahl in den
            // Fingerabdruck, sonst quittiert der 204-Cache jede Änderung weg.
            // Dazu, was die Lobby sonst zeigt: Titel, Probelauf-Banner und ob
            // der eigene Name noch steht — Zurücksetzen und der Probelauf-
            // Schalter löschen alle Spielenden, und ohne die Anzahl hielte ein
            // Handy „Bereit, Anna" fest, bis die erste Frage schon läuft.
            return $this->opaque('idle:' . $this->roomService->presentCount($room)
                . ':' . $this->roomFlags($room) . ':' . $this->voteService->playerCount($room));
        }
        try {
            $poll = $this->pollMapper->find($activeId);
        } catch (DoesNotExistException) {
            return $this->opaque('idle');
        }
        // Inhalts-Fingerabdruck statt reiner Anzahl: eine GEÄNDERTE Umfrage-
        // Antwort (Upsert) lässt die Anzahl gleich, würde also mit 204
        // „unverändert" quittiert und Beamer/Live-Sicht nicht aktualisieren.
        // Freitext-Bewerten wiederum ändert answerKey.
        $stamp = $this->voteMapper->changeStamp($activeId);
        // Für die Zuschauer-Ansicht gehört die Präsenz in den Fingerabdruck:
        // ihre Eingangs-Anzeige zeigt „12 von 24", und ohne die Zahl im
        // Fingerabdruck quittiert der 204-Cache eine neu beigetretene Person
        // weg, bis zufällig jemand abstimmt. Für Abstimmende bleibt sie draußen
        // — dort würde jeder Heartbeat alle zu einem vollen Poll zwingen.
        $presence = $withPresence ? ':p' . $this->roomService->presentCount($room) : '';
        // Aufgelöst ja/nein und Probelauf gehören hinein: „Auflösung am Ende"
        // nachträglich umschalten ändert keinen Status, aber was Handy und
        // Beamer zeigen dürfen — sonst hielte der 204 die alte Auflösung fest.
        // Dazu der Inhalt der Frage: eine bearbeitete Umfrage-Frage ohne
        // Stimmen ändert sonst weder Status noch Startzeit noch Stempel — die
        // Handys behielten die alten Options-IDs, und jede Stimme scheiterte.
        $flags = ':' . ($this->isRevealed($room, $poll) ? 'r' : 'h') . ':' . $this->roomFlags($room)
            . ':' . crc32((string)json_encode($poll->jsonSerialize()));
        return $this->opaque($activeId . ':' . $poll->getStatus() . ':' . $poll->getStartedAt() . ':' . $stamp
            . ':' . crc32((string)$poll->getAnswerKey()) . $flags . $presence);
    }

    /**
     * Version eines fertig gebauten Zustands im eigenen Tempo (ohne serverNow).
     * Der Controller baut den Zustand einmal und leitet die Version daraus ab —
     * alles Zeitgetriebene (Frist, Endgültigkeit, Zeitablauf) steht darin.
     */
    public function selfVersion(array $state): string {
        return $this->paceState->version($state);
    }

    /** Raum-Einstellungen, die das Publikum sieht: Probelauf-Banner und Titel. */
    private function roomFlags(Room $room): string {
        return ($room->getPractice() ? 'p' : '-') . crc32($room->titleOrEmpty());
    }

    /** Fingerabdruck als undurchsichtiger Schlüssel-Hash (warum: PublicView::opaque). */
    private function opaque(string $raw): string {
        return PublicView::opaque($raw, $this->config->getSystemValueString('secret', ''));
    }

    /**
     * Wie stateVersion, aber für den Moderator inkl. Präsenzzahl (die er anzeigt).
     *
     * @throws \InvalidArgumentException Frage gehört nicht zum Raum
     */
    public function resultsVersion(Room $room, int $pollId): string {
        if (PaceService::isSelf($room)) {
            // Baut doppelt, wenn sich etwas geändert hat — nur ein Moderator, akzeptiert.
            return $this->paceState->version($this->paceState->results($room, $pollId));
        }
        $poll = $this->deckService->requirePollInRoom($room, $pollId);
        $stamp = $this->voteMapper->changeStamp($pollId);
        $present = $this->roomService->presentCount($room);
        return $pollId . ':' . $poll->getStatus() . ':' . $poll->getStartedAt() . ':' . $stamp . ':' . $present
            . ':' . crc32((string)$poll->getAnswerKey())
            . ':' . (int)$room->getRevealAtEnd() . (int)$room->getPractice();
    }

    // ── Öffentliche Teilnehmer-Sicht ────────────────────────────────────────

    /**
     * Darf ein Bild dieser Frage öffentlich ausgeliefert werden? Nur wenn die
     * Frage gerade läuft oder aufgelöst ist — sonst verriete es die nächste
     * Frage im Deck. Dieselbe Reveal-Regel wie publicState/publicSummary.
     *
     * Im eigenen Tempo nur für Personen, die die Frage erreicht haben
     * ($voterToken aus dem Cookie, das das <img> same-site mitschickt).
     */
    public function imageVisible(Room $room, Poll $poll, ?string $voterToken = null): bool {
        if (PaceService::isSelf($room)) {
            return $this->paceState->imageVisible($room, $poll, $voterToken);
        }
        if ($room->getActivePollId() === $poll->getId()) {
            return true;
        }
        return $this->isRevealed($room, $poll);
    }

    /**
     * Ist diese Frage fürs Publikum aufgelöst? Quiz mit „Auflösung am Ende":
     * erst wenn sie beendet ist; sonst, sobald sie gesperrt oder beendet ist.
     */
    private function isRevealed(Room $room, Poll $poll): bool {
        return $this->quizRevealAtEnd($room)
            ? $poll->getStatus() === 'ended'
            : in_array($poll->getStatus(), ['locked', 'ended'], true);
    }

    /**
     * IDs aller Fragen des Raums, die fürs Publikum noch nicht aufgelöst sind.
     * Ihre Punkte gehören in keine öffentliche Rangliste: übersprungene der
     * Moderator eine Frage ohne Auflösen, verriete der Punktestand sonst, ob die
     * eigene Antwort richtig war — und die Frage kann wieder geöffnet werden.
     *
     * @return list<int>
     */
    private function hiddenPollIds(Room $room): array {
        $ids = [];
        foreach ($this->pollMapper->findByRoom($room->getId()) as $poll) {
            if (!$this->isRevealed($room, $poll)) {
                $ids[] = $poll->getId();
            }
        }
        return $ids;
    }

    /**
     * „Auflösung am Ende" gilt nur im Quiz. Die Moderator-Oberfläche bietet den
     * Schalter nur dort an, gespeichert werden kann er aber für jeden Raum —
     * eine Umfrage darf davon nicht verdeckt werden.
     */
    private function quizRevealAtEnd(Room $room): bool {
        return $room->getMode() === 'quiz' && $room->getRevealAtEnd();
    }

    /**
     * Zustand für die Teilnehmer-Seite: aktive Frage (falls vorhanden),
     * Live-Ergebnisse und ob dieses Cookie bereits abgestimmt hat.
     *
     * `$withPresence` schaltet die Präsenzzahl auch während einer laufenden
     * Frage zu — die Eingangs-Anzeige der Beamer-Bühne setzt die eingegangenen
     * Antworten dazu ins Verhältnis („12 von 24"). Nur die Zuschauer-Ansicht
     * fragt danach; auf dem Abstimmungs-Pfad bliebe der COUNT reine Last.
     *
     * @return array{protocol:int, room:array{code:string}, poll:?array, results:?array, hasVoted:bool, myValue:mixed}
     */
    public function publicState(Room $room, ?string $voterToken, bool $withPresence = false): array {
        if (PaceService::isSelf($room)) {
            return $this->paceState->publicState($room, $voterToken, $withPresence);
        }
        $quiz = $room->getMode() === 'quiz';
        $base = [
            // Passt das Bundle im Tab nicht mehr zum Server, lädt es sich einmal
            // neu (src/util/protocol.js).
            'protocol' => Application::PROTOCOL,
            'room' => ['code' => $room->getCode(), 'title' => $room->titleOrEmpty(), 'mode' => $room->getMode()],
            'poll' => null,
            'results' => null,
            'hasVoted' => false,
            'myValue' => null,
            'serverNow' => $this->timeFactory->getTime(),
            // 'present' (Lobby-Momentum, §3) wird NUR im Lobby-Zweig gesetzt —
            // während einer laufenden Frage rendert es niemand, der COUNT bliebe
            // reine Last auf dem heißen Abstimmungs-Pfad.
            'present' => 0,
        ];
        if ($quiz) {
            $base['nickname'] = $this->voteService->playerNickname($room, $voterToken);
            $base['myResult'] = null;
            $base['leaderboard'] = null;
            $base['practice'] = $room->getPractice();
        }

        $activeId = $room->getActivePollId();
        if ($activeId === 0) {
            // Lobby: hier — und nur hier — die Live-Teilnehmerzahl fürs Momentum.
            $base['present'] = $this->roomService->presentCount($room);
            return $base;
        }
        try {
            $poll = $this->pollMapper->find($activeId);
        } catch (DoesNotExistException) {
            $base['present'] = $this->roomService->presentCount($room);
            return $base;
        }

        // „Auflösung am Ende": einzelne Fragen bleiben verdeckt — erst wenn das
        // Quiz beendet wird (Frage als 'ended' markiert), gibt es Ergebnisse +
        // Rangliste. Sonst löst jede pausierte/beendete Frage direkt auf.
        $revealed = $this->isRevealed($room, $poll);

        $hasVoted = false;
        $myValue = null;
        $myVote = null;
        if ($voterToken !== null && $voterToken !== '') {
            try {
                $myVote = $this->voteMapper->findByPollAndToken($poll->getId(), $voterToken);
                $hasVoted = true;
                $myValue = $myVote->getValue();
            } catch (DoesNotExistException) {
                // noch nicht abgestimmt
            }
        }

        $pollData = $this->publicPoll($room, $poll, $revealed);
        if ($quiz && $revealed) {
            // Richtige Antwort(en) erst beim Auflösen offenlegen: choice/truefalse
            // via correctOption, die übrigen Typen über den answerKey (correct-Set /
            // Zielzahl+Toleranz / akzeptierte Freitext-Antworten).
            $pollData['correctOption'] = $poll->getCorrectOption();
            $pollData['answerKey'] = $poll->getAnswerKeyArray();
        }
        $base['poll'] = $pollData;
        $base['hasVoted'] = $hasVoted;
        $base['myValue'] = $myValue;
        // Präsenz gehört in die Antwort, nicht in den Fingerabdruck: das Handy
        // zeigt nach dem Absenden „18 von 24 haben geantwortet" (§8.5), und die
        // Zahl ist frisch, weil ohnehin jede neue Stimme einen vollen Poll
        // auslöst. Im Fingerabdruck stünde sie nur bei der Zuschauer-Ansicht —
        // sonst triebe jeder Heartbeat alle Abstimmenden zum vollen Poll.
        $base['present'] = $this->roomService->presentCount($room);
        // Reine Antwort-Anzahl (keine Verteilung) — für den Live-Zähler auf der
        // Beamer-Ansicht; kein Spoiler, deckt sich mit dem Zähler in stateVersion.
        $base['answered'] = $this->voteMapper->countByPoll($poll->getId());

        if ($quiz) {
            // Live-Balken erst beim Auflösen — vorher würde die Verteilung spoilern.
            $base['results'] = $revealed
                ? $this->tallyService->tally($poll, $this->voteMapper->findByPoll($poll->getId()))
                : null;
            if ($hasVoted) {
                $d = json_decode($myVote->getPayload(), true) ?: [];
                // Vor dem Auflösen nur "abgegeben" bestätigen, richtig/Punkte noch nicht.
                $base['myResult'] = $revealed
                    ? ['answered' => true, 'correct' => !empty($d['correct']), 'points' => (int)($d['points'] ?? 0)]
                    : ['answered' => true, 'correct' => null, 'points' => null];
            }
            // Probelauf: keine Rangliste (die Einzel-Rückmeldung richtig/falsch
            // bleibt, damit man die Fragen beim Testen prüfen kann).
            // Ohne noch verdeckte (etwa übersprungene) Fragen — außer am Ende:
            // der Endstand zählt alles, genau wie beim Moderator.
            if ($revealed && !$room->getPractice()) {
                $base['leaderboard'] = $this->voteService->leaderboardFor(
                    $room,
                    $voterToken,
                    $poll->getStatus() === 'ended' ? [] : $this->hiddenPollIds($room),
                );
            }
        } else {
            $base['results'] = $this->tallyService->tally($poll, $this->voteMapper->findByPoll($poll->getId()));
        }

        return $base;
    }

    /**
     * Gesamtauswertung für Teilnehmende („Alle Ergebnisse"): jede Frage des
     * Decks mit Auszählung, der eigenen Antwort und — im Quiz — der Auflösung.
     * Schließt die Lücke, dass am Quiz-Ende nur die LETZTE Frage aufgelöst zu
     * sehen war; der Gesamtblick gab es bisher nur im Moderator-`summary`.
     *
     * Freigabe-Regeln (dieselbe Logik wie publicState, nur übers ganze Deck):
     * - Umfrage: Ergebnisse sind ohnehin live öffentlich -> alles sichtbar.
     * - Quiz „je Frage": jede Frage, die pausiert/beendet wurde (locked|ended).
     * - Quiz „Auflösung am Ende": nichts, bis `endQuiz` gelaufen ist (eine Frage
     *   steht auf 'ended') — dann das ganze Deck.
     *
     * Fragen, die noch nie gezeigt wurden (wasShown), fehlen ganz — ihr Text
     * wäre sonst eine Vorschau aufs restliche Deck.
     *
     * `available` sagt dem Client, ob es überhaupt etwas zu zeigen gibt; nicht
     * freigegebene Fragen kommen ohne Ergebnisse und ohne Lösung heraus.
     *
     * @return array{available:bool, mode:string, practice:bool, title:string, leaderboard:?list<array>, items:list<array{poll:array, revealed:bool, results:?array, mine:?array}>}
     */
    public function publicSummary(Room $room, ?string $voterToken): array {
        if (PaceService::isSelf($room)) {
            return $this->paceState->publicSummary($room, $voterToken);
        }
        $quiz = $room->getMode() === 'quiz';
        $deck = $this->pollMapper->findByRoom($room->getId());

        // „Auflösung am Ende": erst nach endQuiz gibt es überhaupt etwas zu sehen.
        // Das Ende gilt nur, solange nichts Neues läuft: steht der Cursor auf
        // einer Frage, muss GENAU die beendet sein. Eine liegengebliebene
        // 'ended'-Frage aus einem früheren Lauf gäbe sonst ab der ersten Frage
        // jede Lösung frei (setCurrent räumt sie auch weg; das hier ist die
        // zweite Sicherung für Räume, die vorher so stehen geblieben sind).
        $activeId = $room->getActivePollId();
        $endReached = false;
        foreach ($deck as $poll) {
            if ($poll->getStatus() === 'ended' && ($activeId === 0 || $poll->getId() === $activeId)) {
                $endReached = true;
                break;
            }
        }
        $revealAll = !$quiz || ($room->getRevealAtEnd() && $endReached);

        $items = [];
        $anyRevealed = false;
        $hidden = [];
        foreach ($deck as $poll) {
            $revealed = $revealAll
                || (!$room->getRevealAtEnd() && in_array($poll->getStatus(), ['locked', 'ended'], true));
            if (!$revealed) {
                $hidden[] = $poll->getId();
            }
            // Nie gezeigte Fragen gehören nicht hinein — auch nicht als Text:
            // sonst läge das ganze Deck vorab auf jedem Handy (im Quiz samt
            // Antwortmöglichkeiten). Dieselbe Schranke wie beim Fragebild.
            if (!$this->wasShown($room, $poll)) {
                continue;
            }
            $anyRevealed = $anyRevealed || $revealed;

            $pollData = $this->publicPoll($room, $poll, $revealed);
            if ($quiz && $revealed) {
                $pollData['correctOption'] = $poll->getCorrectOption();
                $pollData['answerKey'] = $poll->getAnswerKeyArray();
            }

            $items[] = [
                'poll' => $pollData,
                'revealed' => $revealed,
                'results' => $revealed
                    ? $this->tallyService->tally($poll, $this->voteMapper->findByPoll($poll->getId()))
                    : null,
                'mine' => $this->myAnswer($poll, $voterToken, $quiz, $revealed),
            ];
        }

        return [
            'available' => $anyRevealed,
            'mode' => $room->getMode(),
            'practice' => $room->getPractice(),
            'title' => $room->titleOrEmpty(),
            // Rangliste nur, wenn es auch etwas zu sehen gibt und nicht geprobt wird —
            // und ohne jede noch verdeckte Frage (die laufende ebenso wie eine
            // übersprungene): sonst zeigte der Punktestand, ob die Antwort
            // richtig war, bevor die Frage aufgelöst ist. Am Ende zählt alles,
            // damit Handy, Beamer und Moderator denselben Endstand zeigen.
            'leaderboard' => ($quiz && $anyRevealed && !$room->getPractice())
                ? $this->voteService->leaderboardFor($room, $voterToken, $endReached ? [] : $hidden)
                : null,
            'items' => $items,
        ];
    }

    /**
     * Wurde diese Frage dem Publikum schon gezeigt? Läuft sie gerade, hat sie
     * einen Startzeitpunkt (setCurrent setzt ihn in beiden Modi) oder ist sie
     * aufgelöst. Umfragen setzen den Startzeitpunkt erst seit v0.18.x; ihre
     * früher gezeigten Fragen erkennt man an den Stimmen (abstimmen geht nur
     * auf der laufenden Frage). Im Quiz genügt das nicht als Beleg — dort
     * setzt setCurrent den Zeitpunkt schon immer.
     */
    private function wasShown(Room $room, Poll $poll): bool {
        return $poll->getId() === $room->getActivePollId()
            || $poll->getStartedAt() > 0
            || in_array($poll->getStatus(), ['locked', 'ended'], true)
            || ($room->getMode() !== 'quiz' && $this->voteMapper->countByPoll($poll->getId()) > 0);
    }

    /**
     * Frage für die öffentliche Sicht, vor dem Auflösen spoilerfrei gemischt
     * (Regeln und Begründung: PublicView::poll).
     */
    private function publicPoll(Room $room, Poll $poll, bool $revealed): array {
        return PublicView::poll($room, $poll, $revealed, $this->config->getSystemValueString('secret', ''));
    }

    /**
     * Eigene Antwort auf eine Frage — richtig/Punkte erst, wenn die Frage
     * aufgelöst ist (sonst verriete die Rückmeldung die Lösung).
     *
     * @return ?array{value:mixed, correct:?bool, points:?int}
     */
    private function myAnswer(Poll $poll, ?string $voterToken, bool $quiz, bool $revealed): ?array {
        if ($voterToken === null || $voterToken === '') {
            return null;
        }
        try {
            $vote = $this->voteMapper->findByPollAndToken($poll->getId(), $voterToken);
        } catch (DoesNotExistException) {
            return null;
        }
        $d = json_decode($vote->getPayload(), true) ?: [];
        return [
            'value' => $vote->getValue(),
            'correct' => ($quiz && $revealed) ? !empty($d['correct']) : null,
            'points' => ($quiz && $revealed) ? (int)($d['points'] ?? 0) : null,
        ];
    }
}
