<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\Player;

/**
 * Quiz-Wertung (Kahoot-artig): richtige Antwort bringt Punkte, schneller mehr.
 * Reine Rechenlogik ohne DB — testbar, und leicht zu justieren.
 */
class QuizService {
    /** Volle Punktzahl für eine sofort-richtige Antwort. */
    public const BASE_POINTS = 1000;

    /**
     * Tempo-Punkte für eine RICHTIGE Antwort (Korrektheit prüft der Aufrufer je
     * Fragetyp). Sofort → BASE, am Zeitlimit → BASE/2. Ohne Zeitlimit flache BASE.
     *
     * @param int $elapsed    vergangene Sekunden seit Fragestart
     * @param int $timeLimit  Zeitlimit der Frage (0 = keins)
     */
    public function points(int $elapsed, int $timeLimit): int {
        if ($timeLimit <= 0) {
            return self::BASE_POINTS;
        }
        $frac = max(0.0, min(1.0, $elapsed / $timeLimit));
        return (int)round(self::BASE_POINTS * (1 - 0.5 * $frac));
    }

    /**
     * Rangliste bauen. Tie-Break bei Punktgleichstand über die kürzere Gesamt-
     * Antwortzeit (summiertes elapsed) -> echte Gleichstände selten. Nur wenn
     * Punkte UND Zeit exakt gleich sind, wird der Rang geteilt (1,2,2,4 …); der
     * nächste Rang springt entsprechend. Restsortierung alphabetisch. Jede Zeile
     * trägt intern Token + Zeit mit; vor dem Ausliefern MUSS das Token entfernt
     * werden (fremdes Cookie-Geheimnis), die Zeit ist rein intern.
     *
     * @param Player[]            $players
     * @param array<string,int>   $pointsByToken  Punktsumme je Token
     * @param array<string,int>   $correctByToken Anzahl richtiger Antworten je Token
     * @param array<string,int>   $timeByToken    Summierte Antwortzeit (elapsed) je Token
     * @return list<array{token:string, rank:int, nickname:string, score:int, correct:int, time:int}>
     */
    public function leaderboard(array $players, array $pointsByToken, array $correctByToken, array $timeByToken = []): array {
        $rows = [];
        foreach ($players as $p) {
            $token = $p->getVoterToken();
            $rows[] = [
                'token' => $token,
                'nickname' => $p->getNickname(),
                'score' => $pointsByToken[$token] ?? 0,
                'correct' => $correctByToken[$token] ?? 0,
                'time' => $timeByToken[$token] ?? 0,
            ];
        }
        // Punkte absteigend, dann kürzere Gesamtzeit, dann Name. Die Zeit zählt NUR
        // bei Punkten > 0: sonst stünde, wer nie geantwortet hat (Zeit 0), vor
        // jemandem, der falsch — aber schnell — geantwortet hat. Alle Punktlosen
        // teilen sich daher einen Rang.
        usort($rows, static function (array $a, array $b): int {
            $ta = $a['score'] > 0 ? $a['time'] : 0;
            $tb = $b['score'] > 0 ? $b['time'] : 0;
            return ($b['score'] <=> $a['score'])
                ?: ($ta <=> $tb)
                ?: strcasecmp($a['nickname'], $b['nickname']);
        });
        // Geteilter Rang NUR bei exakt gleichem (Punkte, Zeit) -> nächster springt.
        $rank = 0;
        $prevKey = null;
        foreach ($rows as $i => &$row) {
            $key = $row['score'] . ':' . ($row['score'] > 0 ? $row['time'] : 0);
            if ($key !== $prevKey) {
                $rank = $i + 1;
                $prevKey = $key;
            }
            $row['rank'] = $rank;
        }
        unset($row);
        return $rows;
    }
}
