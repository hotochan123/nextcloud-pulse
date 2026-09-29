<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\Vote;

/**
 * Zählt Stimmen aus. Reine Rechenlogik, 1:1 aus dem Artefakt übernommen —
 * keine DB-Zugriffe (bekommt Poll + Vote[] herein), damit leicht testbar.
 */
class TallyService {

    /**
     * @param Vote[] $votes
     * @return array{type:string, total:int, results:array}
     */
    public function tally(Poll $poll, array $votes): array {
        return match ($poll->getType()) {
            'words' => $this->tallyWords($poll, $votes),
            'scale' => $this->tallyScale($poll, $votes),
            'multi' => $this->tallyMulti($poll, $votes),
            'rank' => $this->tallyRank($poll, $votes),
            'match' => $this->tallyMatch($poll, $votes),
            'number' => $this->tallyNumber($poll, $votes),
            'text' => $this->tallyText($poll, $votes),
            default => $this->tallyChoice($poll, $votes), // choice + truefalse
        };
    }

    /**
     * Freitext-Normalform für den Abgleich: Whitespace zusammengezogen,
     * getrimmt, kleingeschrieben. EINE Quelle — auch der VoteService nutzt sie,
     * damit Bewertung und Auszählung dieselbe Gruppierung sehen.
     */
    public static function normalizeText(string $s): string {
        $s = preg_replace('/\s+/u', ' ', $s) ?? '';
        return mb_strtolower(trim($s));
    }

    /**
     * Sichtbarer Text ohne Unsichtbares: Unicode-NFC (ein „é" aus macOS ist
     * sonst zwei Zeichen), unsichtbare Zeichen raus (weiches Trennzeichen,
     * Nullbreiten-Leerzeichen, Richtungs-Steuerzeichen, Wortverbinder, BOM) und
     * an den Rändern alles Leere, auch NBSP und U+3000, die PHPs trim() stehen
     * lässt. Bewusst eine Liste statt \p{Cf}: ZWNJ/ZWJ (U+200C/D) braucht
     * Persisch bzw. Emoji, die Tag-Zeichen U+E0020–E007F die Regionsflaggen —
     * aber nur dort: außerhalb einer Flagge (🏴 … U+E007F) fliegen sie raus.
     * Hangul-Füllzeichen, die arabische Richtungsmarke, das leere
     * Braille-Feld (U+2800, der übliche „unsichtbare Name"), die veralteten
     * Formatzeichen U+206A–206F, die Anmerkungszeichen U+FFF9–FFFB und das
     * Sprach-Tag U+E0001 sind ebenfalls unsichtbar und stehen auf der Liste.
     * Sonst sind „Kaffee" und „Kaffee\u{200B}" zwei Wörter und „Anna" zweimal
     * in der Rangliste.
     */
    public static function cleanText(string $s): string {
        if (class_exists(\Normalizer::class)) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_C) ?: $s;
        }
        $s = preg_replace('/[\x{00AD}\x{061C}\x{115F}\x{1160}\x{180E}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{2800}\x{3164}\x{FEFF}\x{FFA0}\x{FFF9}-\x{FFFB}\x{E0001}]/u', '', $s) ?? '';
        $s = preg_replace_callback(
            '/(\x{1F3F4}[\x{E0020}-\x{E007E}]+\x{E007F})|[\x{E0020}-\x{E007F}]/u',
            static fn (array $m): string => $m[1] ?? '',
            $s,
        ) ?? '';
        return preg_replace('/^[\s\p{Z}\x{200C}\x{200D}]+|[\s\p{Z}\x{200C}\x{200D}]+$/u', '', $s) ?? '';
    }

    /**
     * Wortwolken-Anzeigeform: bereinigt (cleanText), Leerraum im Inneren zu
     * einem Leerzeichen, kleingeschrieben.
     */
    public static function normalizeWord(string $s): string {
        return mb_strtolower(self::squash(self::cleanText($s)));
    }

    /**
     * Wortwolken-Schlüssel: die Anzeigeform ohne Zeichen, die nur die Darstellung
     * wählen (Variantenwähler wie das Emoji-U+FE0F, Graphem-Verbinder U+034F und
     * Verwandte). Gespeichert und angezeigt werden sie weiter — „❤️" soll bunt
     * bleiben —, aber „❤️" und „❤" sind ein Wort. EINE Quelle für Auszählung und
     * Stimmprüfung — sonst zählt „Kaffee“ + „KAFFEE“ einer Person doppelt.
     */
    public static function wordKey(string $s): string {
        return self::squash(self::nfc(preg_replace(
            '/[\x{034F}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{FE00}-\x{FE0F}\x{1D173}-\x{1D17A}\x{E0100}-\x{E01EF}]/u',
            '',
            self::normalizeWord($s),
        ) ?? ''));
    }

    /**
     * Vergleichsform für Namen: wie wordKey, zusätzlich ohne ZWNJ/ZWJ.
     * Die bleiben im gespeicherten Namen (Persisch, Emoji), dürfen aber nicht
     * aus „Anna" einen zweiten, gleich aussehenden Namen machen.
     */
    public static function nameKey(string $s): string {
        return self::squash(self::nfc(preg_replace('/[\x{200C}\x{200D}]/u', '', self::wordKey($s)) ?? ''));
    }

    /**
     * Noch einmal NFC nach dem Entfernen: ein Verbinder zwischen Buchstabe und
     * Akzent blockiert die Zusammensetzung — ohne ihn wäre „Rene" + U+0301
     * sonst ein anderer Schlüssel als „René".
     */
    private static function nfc(string $s): string {
        if (class_exists(\Normalizer::class)) {
            return \Normalizer::normalize($s, \Normalizer::FORM_C) ?: $s;
        }
        return $s;
    }

    /** Leerraum-Folgen zu einem Leerzeichen, Ränder weg. */
    private static function squash(string $s): string {
        return trim(preg_replace('/[\s\p{Z}]+/u', ' ', $s) ?? '', ' ');
    }

    /**
     * Skala: Verteilung je Wert + Durchschnitt.
     *
     * @param Vote[] $votes
     * @return array{type:string, total:int, average:float, min:int, max:int, minLabel:string, maxLabel:string, results:list<array{value:int,count:int}>}
     */
    private function tallyScale(Poll $poll, array $votes): array {
        $cfg = $poll->getScaleConfig();
        if (($cfg['mode'] ?? 'single') === 'spectrum') {
            return $this->tallySpectrum($cfg, $votes);
        }
        if (($cfg['mode'] ?? 'single') === 'compass') {
            return $this->tallyCompass($cfg, $votes);
        }
        $counts = [];
        for ($v = $cfg['min']; $v <= $cfg['max']; $v++) {
            $counts[$v] = 0;
        }
        $sum = 0;
        $n = 0;
        foreach ($votes as $vote) {
            $val = $vote->getValue();
            if (!is_numeric($val)) {
                continue;
            }
            $iv = (int)$val;
            if (array_key_exists($iv, $counts)) {
                $counts[$iv]++;
                $sum += $iv;
                $n++;
            }
        }
        $results = [];
        foreach ($counts as $value => $count) {
            $results[] = ['value' => $value, 'count' => $count];
        }
        return [
            'type' => 'scale',
            'mode' => 'single',
            'total' => count($votes),
            'average' => $n > 0 ? round($sum / $n, 2) : 0.0,
            'min' => $cfg['min'],
            'max' => $cfg['max'],
            'minLabel' => $cfg['minLabel'],
            'maxLabel' => $cfg['maxLabel'],
            'results' => $results,
        ];
    }

    /**
     * Spektrum: je Aspekt Ø, min, max und n über alle Stimmen. Eine Stimme ist
     * eine Map aspectId -> Wert (0..max). Fehlt ein Aspekt, zählt er für diese
     * Stimme nicht mit.
     *
     * @param array $cfg getScaleConfig() (mode=spectrum, mit aspects[])
     * @param Vote[] $votes
     * @return array{type:string, mode:string, total:int, min:int, max:int, results:list<array{id:string,label:string,average:float,min:int,max:int,n:int}>}
     */
    private function tallySpectrum(array $cfg, array $votes): array {
        $aspects = $cfg['aspects'] ?? [];
        $acc = [];
        foreach ($aspects as $a) {
            $acc[$a['id']] = ['sum' => 0, 'n' => 0, 'min' => null, 'max' => null];
        }
        foreach ($votes as $vote) {
            $val = $vote->getValue();
            if (!is_array($val)) {
                continue;
            }
            foreach ($aspects as $a) {
                $id = $a['id'];
                if (!isset($val[$id]) || !is_numeric($val[$id])) {
                    continue;
                }
                $iv = (int)$val[$id];
                if ($iv < $cfg['min'] || $iv > $cfg['max']) {
                    continue;
                }
                $acc[$id]['sum'] += $iv;
                $acc[$id]['n']++;
                $acc[$id]['min'] = $acc[$id]['min'] === null ? $iv : min($acc[$id]['min'], $iv);
                $acc[$id]['max'] = $acc[$id]['max'] === null ? $iv : max($acc[$id]['max'], $iv);
            }
        }
        $results = [];
        foreach ($aspects as $a) {
            $id = $a['id'];
            $n = $acc[$id]['n'];
            $results[] = [
                'id' => $id,
                'label' => $a['label'],
                'average' => $n > 0 ? round($acc[$id]['sum'] / $n, 2) : 0.0,
                'min' => $n > 0 ? (int)$acc[$id]['min'] : 0,
                'max' => $n > 0 ? (int)$acc[$id]['max'] : 0,
                'n' => $n,
            ];
        }
        return [
            'type' => 'scale',
            'mode' => 'spectrum',
            'total' => count($votes),
            'min' => $cfg['min'],
            'max' => $cfg['max'],
            'results' => $results,
        ];
    }

    /**
     * Kompass: alle Punkte {x,y} (für Scatter/Heatmap) + Schwerpunkt (Centroid).
     * Der Client entscheidet Scatter vs. Heatmap anhand total >= heatmapThreshold.
     *
     * @param array $cfg getScaleConfig() (mode=compass, range/axisX/axisY/…)
     * @param Vote[] $votes
     * @return array{type:string, mode:string, total:int, range:int, heatmapThreshold:int, axisX:array, axisY:array, cornerLabels:array, centroid:?array, points:list<array{x:int,y:int}>, results:array}
     */
    private function tallyCompass(array $cfg, array $votes): array {
        $r = (int)$cfg['range'];
        $points = [];
        $sx = 0;
        $sy = 0;
        $n = 0;
        foreach ($votes as $vote) {
            $val = $vote->getValue();
            if (!is_array($val) || !isset($val['x'], $val['y']) || !is_numeric($val['x']) || !is_numeric($val['y'])) {
                continue;
            }
            $x = (int)$val['x'];
            $y = (int)$val['y'];
            if ($x < -$r || $x > $r || $y < -$r || $y > $r) {
                continue;
            }
            $points[] = ['x' => $x, 'y' => $y];
            $sx += $x;
            $sy += $y;
            $n++;
        }
        $centroid = $n > 0 ? ['x' => round($sx / $n, 2), 'y' => round($sy / $n, 2)] : null;
        return [
            'type' => 'scale',
            'mode' => 'compass',
            'total' => count($votes),
            'range' => $r,
            'heatmapThreshold' => (int)($cfg['heatmapThreshold'] ?? 45),
            'axisX' => $cfg['axisX'] ?? ['title' => '', 'poleLow' => '', 'poleHigh' => ''],
            'axisY' => $cfg['axisY'] ?? ['title' => '', 'poleLow' => '', 'poleHigh' => ''],
            'cornerLabels' => $cfg['cornerLabels'] ?? [],
            'centroid' => $centroid,
            'points' => $points,
            'results' => [],
        ];
    }

    /**
     * @param Vote[] $votes
     * @return array{type:string, total:int, results:list<array{id:string,label:string,count:int}>}
     */
    private function tallyChoice(Poll $poll, array $votes): array {
        $counts = [];
        foreach ($poll->getOptionsArray() as $opt) {
            $counts[$opt['id']] = 0;
        }
        foreach ($votes as $vote) {
            $value = $vote->getValue();
            if (is_string($value) && array_key_exists($value, $counts)) {
                $counts[$value]++;
            }
        }
        $results = [];
        foreach ($poll->getOptionsArray() as $opt) {
            $results[] = [
                'id' => $opt['id'],
                'label' => $opt['label'],
                'count' => $counts[$opt['id']],
            ];
        }
        return [
            'type' => 'choice',
            'total' => count($votes),
            'results' => $results,
        ];
    }

    /**
     * Wortwolke: alle Wörter aller Stimmen einsammeln, kleinschreiben,
     * nach Häufigkeit absteigend. total = Zahl der abstimmenden Personen.
     *
     * @param Vote[] $votes
     * @return array{type:string, total:int, results:list<array{word:string,count:int}>}
     */
    private function tallyWords(Poll $poll, array $votes): array {
        $freq = [];
        $label = [];
        foreach ($votes as $vote) {
            $value = $vote->getValue();
            if (!is_array($value)) {
                continue;
            }
            // Je Person zählt ein Wort höchstens einmal — auch für Stimmen, die
            // vor der Prüfung ohne Groß/Klein gespeichert wurden.
            $seen = [];
            foreach ($value as $raw) {
                if (!is_string($raw)) {
                    continue;
                }
                $key = self::wordKey($raw);
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $freq[$key] = ($freq[$key] ?? 0) + 1;
                // Angezeigt wird die erste Schreibweise (mit Variantenwähler).
                $label[$key] ??= self::normalizeWord($raw);
            }
        }
        arsort($freq);
        $results = [];
        foreach ($freq as $key => $count) {
            $results[] = ['word' => $label[$key], 'count' => $count];
        }
        return [
            'type' => 'words',
            'total' => count($votes),
            'results' => $results,
        ];
    }

    /**
     * Mehrfachauswahl: wie Choice, aber je Stimme können mehrere IDs gezählt
     * werden (Wert ist eine Liste). total = Zahl der Personen.
     *
     * @param Vote[] $votes
     * @return array{type:string, total:int, results:list<array{id:string,label:string,count:int}>}
     */
    private function tallyMulti(Poll $poll, array $votes): array {
        $counts = [];
        foreach ($poll->getOptionsArray() as $opt) {
            $counts[$opt['id']] = 0;
        }
        foreach ($votes as $vote) {
            $value = $vote->getValue();
            if (!is_array($value)) {
                continue;
            }
            foreach ($value as $id) {
                if (is_string($id) && array_key_exists($id, $counts)) {
                    $counts[$id]++;
                }
            }
        }
        $results = [];
        foreach ($poll->getOptionsArray() as $opt) {
            $results[] = ['id' => $opt['id'], 'label' => $opt['label'], 'count' => $counts[$opt['id']]];
        }
        return ['type' => 'multi', 'total' => count($votes), 'results' => $results];
    }

    /**
     * Reihenfolge: Konsens-Ranking. Je Option der Durchschnitt der vergebenen
     * Plätze (1 = ganz oben, kleiner ist besser) und wie oft sie auf Platz 1
     * stand. Sortiert nach Durchschnitt — das ist die Reihenfolge, auf die sich
     * das Publikum geeinigt hat. Optionen ohne Stimmen behalten ihre Editor-
     * Position und stehen mit average 0 am Ende.
     *
     * `places` trägt zusätzlich die volle Verteilung (Index k = Platz k+1). Der
     * Durchschnitt allein verschweigt, ob ein Element unstrittig ist oder
     * polarisiert: 2,4 kann „alle sagen Platz 2 oder 3" heißen oder „die Hälfte
     * Platz 1, die Hälfte Platz 4". Erst die Verteilung macht das lesbar (§7.5).
     * Sortierung, Durchschnitt und `first` bleiben unverändert.
     *
     * @param Vote[] $votes
     * @return array{type:string, total:int, results:list<array{id:string,label:string,average:float,first:int,n:int,places:list<int>}>}
     */
    private function tallyRank(Poll $poll, array $votes): array {
        $acc = [];
        $slots = count($poll->getOptionsArray());
        foreach ($poll->getOptionsArray() as $opt) {
            $acc[$opt['id']] = ['sum' => 0, 'n' => 0, 'first' => 0, 'places' => array_fill(0, $slots, 0)];
        }
        foreach ($votes as $vote) {
            $order = $vote->getValue();
            if (!is_array($order)) {
                continue;
            }
            foreach (array_values($order) as $pos => $id) {
                if (!is_string($id) || !isset($acc[$id])) {
                    continue;
                }
                $acc[$id]['sum'] += $pos + 1; // 1-basierte Plätze
                $acc[$id]['n']++;
                if ($pos === 0) {
                    $acc[$id]['first']++;
                }
                if ($pos < $slots) {
                    $acc[$id]['places'][$pos]++;
                }
            }
        }
        $results = [];
        foreach ($poll->getOptionsArray() as $i => $opt) {
            $a = $acc[$opt['id']];
            $results[] = [
                'id' => $opt['id'],
                'label' => $opt['label'],
                'average' => $a['n'] > 0 ? round($a['sum'] / $a['n'], 2) : 0.0,
                'first' => $a['first'],
                'n' => $a['n'],
                'places' => $a['places'],
                'pos' => $i, // Editor-Position, nur als stabiler Tiebreaker
            ];
        }
        // Kleinerer Durchschnitt zuerst; ohne Stimmen (average 0) ans Ende.
        usort($results, static function (array $x, array $y): int {
            if ($x['n'] === 0 || $y['n'] === 0) {
                return ($y['n'] <=> $x['n']) ?: ($x['pos'] <=> $y['pos']);
            }
            return ($x['average'] <=> $y['average'])
                ?: ($y['first'] <=> $x['first'])
                ?: ($x['pos'] <=> $y['pos']);
        });
        foreach ($results as &$row) {
            unset($row['pos']);
        }
        unset($row);
        return ['type' => 'rank', 'total' => count($votes), 'results' => $results];
    }

    /**
     * Zuordnung: je Item die Verteilung über die Ziele. Das Publikum einigt
     * sich pro Zeile — deshalb bleibt die Item-Reihenfolge des Editors stehen
     * (sie ist die Leserichtung), und nur die Ziele werden gezählt. `top` ist
     * das meistgewählte Ziel der Zeile (leer bei Gleichstand oder ohne Stimmen)
     * und trägt die Konsens-Markierung in der Umfrage.
     *
     * @param Vote[] $votes
     * @return array{type:string, total:int, targets:list<array{id:string,label:string}>, results:list<array{id:string,label:string,n:int,top:string,targets:list<array{id:string,label:string,count:int}>}>}
     */
    private function tallyMatch(Poll $poll, array $votes): array {
        $cfg = $poll->getMatchConfig();
        $counts = [];
        foreach ($cfg['items'] as $item) {
            foreach ($cfg['targets'] as $target) {
                $counts[$item['id']][$target['id']] = 0;
            }
        }
        foreach ($votes as $vote) {
            $assign = $vote->getValue();
            if (!is_array($assign)) {
                continue;
            }
            foreach ($assign as $itemId => $targetId) {
                if (is_string($targetId) && isset($counts[$itemId][$targetId])) {
                    $counts[$itemId][$targetId]++;
                }
            }
        }
        $results = [];
        foreach ($cfg['items'] as $item) {
            $row = [];
            $n = 0;
            $best = 0;
            $top = '';
            foreach ($cfg['targets'] as $target) {
                $c = $counts[$item['id']][$target['id']] ?? 0;
                $row[] = ['id' => $target['id'], 'label' => $target['label'], 'count' => $c];
                $n += $c;
                if ($c > $best) {
                    $best = $c;
                    $top = $target['id'];
                } elseif ($c === $best && $c > 0) {
                    $top = ''; // Gleichstand ist kein Konsens
                }
            }
            $results[] = [
                'id' => $item['id'],
                'label' => $item['label'],
                'n' => $n,
                'top' => $top,
                'targets' => $row,
            ];
        }
        return ['type' => 'match', 'total' => count($votes), 'targets' => $cfg['targets'], 'results' => $results];
    }

    /**
     * Schätzfrage: Verteilung der Zahlen + wie viele im Toleranzband lagen.
     * Die Zielzahl selbst steckt NICHT im Tally (kommt beim Auflösen via answerKey).
     *
     * @param Vote[] $votes
     * @return array{type:string, total:int, correct:int, results:list<array{value:int|float,count:int}>}
     */
    private function tallyNumber(Poll $poll, array $votes): array {
        $key = $poll->getAnswerKeyArray();
        $target = (float)($key['target'] ?? 0);
        $tol = (float)($key['tolerance'] ?? 0);
        $freq = [];
        $correct = 0;
        foreach ($votes as $vote) {
            $val = $vote->getValue();
            if (!is_numeric($val)) {
                continue;
            }
            $num = $val + 0;
            $k = (string)$num;
            $freq[$k] = ($freq[$k] ?? 0) + 1;
            if (abs((float)$num - $target) <= $tol) {
                $correct++;
            }
        }
        uksort($freq, static fn ($a, $b): int => ($a + 0) <=> ($b + 0));
        $results = [];
        foreach ($freq as $value => $count) {
            $results[] = ['value' => $value + 0, 'count' => $count];
        }
        return ['type' => 'number', 'total' => count($votes), 'correct' => $correct, 'results' => $results];
    }

    /**
     * Freitext: Antworten nach Normalform gruppieren (Häufigkeit absteigend),
     * je Gruppe ein roher Beispieltext + Status (accepted/rejected/pending) aus
     * der Akzeptanzliste. Grundlage für die Moderator-Bewertung.
     *
     * @param Vote[] $votes
     * @return array{type:string, total:int, answers:list<array{norm:string,sample:string,count:int,status:string}>, accepted:list<string>}
     */
    private function tallyText(Poll $poll, array $votes): array {
        $key = $poll->getAnswerKeyArray();
        $acceptedNorms = [];
        foreach (($key['accepted'] ?? []) as $a) {
            $acceptedNorms[self::normalizeText((string)$a)] = true;
        }
        $rejectedNorms = [];
        foreach (($key['rejected'] ?? []) as $a) {
            $rejectedNorms[self::normalizeText((string)$a)] = true;
        }

        $groups = [];
        foreach ($votes as $vote) {
            $raw = $vote->getValue();
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $norm = self::normalizeText($raw);
            if (!isset($groups[$norm])) {
                $groups[$norm] = ['sample' => $raw, 'count' => 0];
            }
            $groups[$norm]['count']++;
        }
        uasort($groups, static fn ($a, $b): int => $b['count'] <=> $a['count']);

        $answers = [];
        foreach ($groups as $norm => $g) {
            $status = isset($acceptedNorms[$norm]) ? 'accepted'
                : (isset($rejectedNorms[$norm]) ? 'rejected' : 'pending');
            $answers[] = ['norm' => $norm, 'sample' => $g['sample'], 'count' => $g['count'], 'status' => $status];
        }
        return [
            'type' => 'text',
            'total' => count($votes),
            'answers' => $answers,
            'accepted' => array_values($key['accepted'] ?? []),
        ];
    }
}
