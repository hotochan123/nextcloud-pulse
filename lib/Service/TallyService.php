<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\Vote;

/**
 * Counts votes. Pure calculation logic, taken over 1:1 from the prototype artifact —
 * no DB access (it is handed Poll + Vote[]), so that it is easy to test.
 *
 * Section references (§…) point to the design notes of the redesign, which are
 * not in the public repository (see "References in code comments" in the
 * README).
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
     * Free-text normal form for matching: whitespace collapsed,
     * trimmed, lower-cased. ONE source — VoteService uses it too,
     * so that grading and tallying see the same grouping.
     */
    public static function normalizeText(string $s): string {
        $s = preg_replace('/\s+/u', ' ', $s) ?? '';
        return mb_strtolower(trim($s));
    }

    /**
     * Visible text without the invisible: Unicode NFC (an "é" from macOS is
     * otherwise two characters), invisible characters removed (soft hyphen,
     * zero-width space, direction control characters, word joiner, BOM) and
     * everything blank at the edges, including NBSP and U+3000, which PHP's trim()
     * leaves in place. Deliberately a list instead of \p{Cf}: ZWNJ/ZWJ (U+200C/D) are needed
     * by Persian and by emoji respectively, the tag characters U+E0020–E007F by the regional flags —
     * but only there: outside a flag (🏴 … U+E007F) they are removed.
     * Hangul fillers, the Arabic letter mark, the blank
     * Braille pattern (U+2800, the usual "invisible name"), the deprecated
     * format characters U+206A–206F, the annotation characters U+FFF9–FFFB and the
     * language tag U+E0001 are invisible as well and are on the list.
     * So is every code point that Unicode reserves as default-ignorable
     * without assigning it yet — U+2065, U+FFF0–FFF8 and the unassigned rest
     * of the tag and selector plane (U+E0000, E0002–E001F, E0080–E00FF,
     * E01F0–E0FFF) — and the shorthand format controls U+1BCA0–1BCA3:
     * browsers render them as nothing, and the Spoofchecker ignores them.
     * Otherwise "coffee" and "coffee\u{200B}" are two words and "Anna" appears twice
     * in the leaderboard.
     */
    public static function cleanText(string $s): string {
        if (class_exists(\Normalizer::class)) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_C) ?: $s;
        }
        $s = preg_replace('/[\x{00AD}\x{061C}\x{115F}\x{1160}\x{180E}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{2800}\x{3164}\x{FEFF}\x{FFA0}\x{FFF0}-\x{FFFB}\x{1BCA0}-\x{1BCA3}\x{E0000}-\x{E001F}\x{E0080}-\x{E00FF}\x{E01F0}-\x{E0FFF}]/u', '', $s) ?? '';
        $s = preg_replace_callback(
            '/(\x{1F3F4}[\x{E0020}-\x{E007E}]+\x{E007F})|[\x{E0020}-\x{E007F}]/u',
            static fn (array $m): string => $m[1] ?? '',
            $s,
        ) ?? '';
        return preg_replace('/^[\s\p{Z}\x{200C}\x{200D}]+|[\s\p{Z}\x{200C}\x{200D}]+$/u', '', $s) ?? '';
    }

    /**
     * Word-cloud display form: cleaned (cleanText), inner whitespace collapsed to
     * one space, lower-cased.
     */
    public static function normalizeWord(string $s): string {
        return mb_strtolower(self::squash(self::cleanText($s)));
    }

    /**
     * Word-cloud key: the display form without characters that only select the
     * presentation (variation selectors such as the emoji U+FE0F, the combining grapheme joiner U+034F and
     * relatives). They are still stored and displayed — "❤️" should stay
     * colourful — but "❤️" and "❤" are one word. ONE source for tallying and
     * vote validation — otherwise "coffee" + "COFFEE" from one person counts twice.
     */
    public static function wordKey(string $s): string {
        return self::squash(self::nfc(preg_replace(
            '/[' . self::SELECTORS . ']/u',
            '',
            self::normalizeWord($s),
        ) ?? ''));
    }

    /**
     * Comparison form for names: cleaned like wordKey (invisible characters,
     * presentation selectors, whitespace), additionally without ZWNJ/ZWJ —
     * they stay in the stored name (Persian, emoji), but must not turn
     * "Anna" into a second, identical-looking name. On top of that it folds
     * what only LOOKS like another name:
     *
     * - NFKC: fullwidth "Ａｎｎａ", ligatures, circled and mathematical letters
     *   become the plain letters;
     * - a small table of Greek, Cyrillic and Latin lookalikes
     *   (CONFUSABLE_FOLD: Greek "Αnna", Cyrillic "Аnna", dotless "ı") becomes
     *   the Latin letter. It runs on the decomposed form before AND after
     *   lower-casing: Cyrillic "Н" looks like "H", its lower case "н" like
     *   nothing Latin — so the capital has to be folded first.
     *
     * Deterministic and without intl's Spoofchecker; namesClash() adds the
     * full Unicode confusables check where it exists. Only used to compare
     * names and to recognise "nothing visible left" — the stored name keeps
     * its spelling.
     */
    public static function nameKey(#[\SensitiveParameter] string $s): string {
        $s = preg_replace('/[\x{200C}\x{200D}' . self::SELECTORS . ']/u', '', self::cleanText($s)) ?? '';
        $s = strtr(self::normalize($s, 'kd'), self::CONFUSABLE_FOLD);
        $s = strtr(mb_strtolower($s), self::CONFUSABLE_FOLD);
        return self::squash(self::normalize($s, 'kc'));
    }

    /**
     * Do two names clash — would a new player with name $a be mistaken for
     * the existing $b? Equal nameKey (case, invisible characters, NFKC and
     * the lookalike table), or — where PHP's intl extension provides the
     * Spoofchecker — confusable according to Unicode's confusables data
     * (UTS #39: "PauI" with a capital I for "Paul", "rn" for "m", scripts the
     * table does not cover). The Spoofchecker compares the names as shown
     * (case kept): lower-casing first would turn the capital I into an
     * innocent "i".
     *
     * Only for new names (join, rename): names that already exist keep
     * their spelling, even if an older rule let a lookalike through.
     */
    public static function namesClash(#[\SensitiveParameter] string $a, #[\SensitiveParameter] string $b): bool {
        return self::clashIn($a, [$b]) !== null;
    }

    /**
     * The first of $others that clashes with $name (namesClash), or null.
     * Computes $name's forms once — a room can have hundreds of players.
     *
     * @param array<array-key, string> $others
     * @return int|string|null key of the first clashing name in $others
     */
    public static function clashIn(#[\SensitiveParameter] string $name, #[\SensitiveParameter] array $others): int|string|null {
        $key = self::nameKey($name);
        $checker = self::spoofchecker();
        $shape = $checker !== null ? self::nameShape($name) : '';
        foreach ($others as $i => $other) {
            if (self::nameKey($other) === $key
                || ($checker !== null && $checker->areConfusable($shape, self::nameShape($other)))) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Characters that only select a presentation (see wordKey): variation
     * selectors, the combining grapheme joiner and relatives. As a character
     * class body for preg (/u).
     */
    private const SELECTORS = '\x{034F}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{FE00}-\x{FE0F}\x{1D173}-\x{1D17A}\x{E0100}-\x{E01EF}';

    /**
     * Lookalikes -> Latin letter, applied to the decomposed form (so "Ё" is
     * "Е" plus diaeresis and folds to "Ë"). Deliberately small and
     * unambiguous: letters that look like a Latin letter in common fonts.
     * Not "I"/"l" or "0"/"O" — those are distinct characters people really
     * type; the Spoofchecker (namesClash) covers them where available.
     */
    private const CONFUSABLE_FOLD = [
        // Greek capitals
        "\u{0391}" => 'A', "\u{0392}" => 'B', "\u{0395}" => 'E', "\u{0396}" => 'Z',
        "\u{0397}" => 'H', "\u{0399}" => 'I', "\u{039A}" => 'K', "\u{039C}" => 'M',
        "\u{039D}" => 'N', "\u{039F}" => 'O', "\u{03A1}" => 'P', "\u{03A4}" => 'T',
        "\u{03A5}" => 'Y', "\u{03A7}" => 'X', "\u{03F9}" => 'C', "\u{037F}" => 'J',
        "\u{03DC}" => 'F',
        // Greek small letters
        "\u{03B1}" => 'a', "\u{03B3}" => 'y', "\u{03B9}" => 'i', "\u{03BA}" => 'k',
        "\u{03BD}" => 'v', "\u{03BF}" => 'o', "\u{03C1}" => 'p', "\u{03C5}" => 'u',
        "\u{03C7}" => 'x', "\u{03F2}" => 'c', "\u{03F3}" => 'j',
        // Cyrillic capitals
        "\u{0405}" => 'S', "\u{0406}" => 'I', "\u{0408}" => 'J', "\u{0410}" => 'A',
        "\u{0412}" => 'B', "\u{0415}" => 'E', "\u{041A}" => 'K', "\u{041C}" => 'M',
        "\u{041D}" => 'H', "\u{041E}" => 'O', "\u{0420}" => 'P', "\u{0421}" => 'C',
        "\u{0422}" => 'T', "\u{0423}" => 'Y', "\u{0425}" => 'X', "\u{04AE}" => 'Y',
        "\u{04BA}" => 'H', "\u{04C0}" => 'I', "\u{051A}" => 'Q', "\u{051C}" => 'W',
        // Cyrillic small letters
        "\u{0430}" => 'a', "\u{0435}" => 'e', "\u{043E}" => 'o', "\u{0440}" => 'p',
        "\u{0441}" => 'c', "\u{0443}" => 'y', "\u{0445}" => 'x', "\u{0455}" => 's',
        "\u{0456}" => 'i', "\u{0458}" => 'j', "\u{04AF}" => 'y', "\u{04BB}" => 'h',
        "\u{04CF}" => 'l', "\u{0501}" => 'd', "\u{051B}" => 'q', "\u{051D}" => 'w',
        // Latin lookalikes of plain letters
        "\u{0131}" => 'i', "\u{0237}" => 'j', "\u{0251}" => 'a', "\u{0261}" => 'g',
        "\u{01C0}" => 'l',
    ];

    /** @var \Spoofchecker|false|null false = intl without Spoofchecker (checked once) */
    private static \Spoofchecker|false|null $spoofchecker = null;

    private static function spoofchecker(): ?\Spoofchecker {
        if (self::$spoofchecker === null) {
            self::$spoofchecker = class_exists(\Spoofchecker::class) ? new \Spoofchecker() : false;
        }
        return self::$spoofchecker ?: null;
    }

    /**
     * A name as it is shown, for the Spoofchecker: cleaned, without joiners
     * and presentation selectors, NFKC, whitespace collapsed — case kept.
     */
    private static function nameShape(string $s): string {
        $s = preg_replace('/[\x{200C}\x{200D}' . self::SELECTORS . ']/u', '', self::cleanText($s)) ?? '';
        return self::squash(self::normalize($s, 'kc'));
    }

    /** Unicode normalisation ('kd' or 'kc'); unchanged without intl. */
    private static function normalize(string $s, string $form): string {
        if (!class_exists(\Normalizer::class)) {
            return $s;
        }
        return \Normalizer::normalize($s, $form === 'kd' ? \Normalizer::FORM_KD : \Normalizer::FORM_KC) ?: $s;
    }

    /**
     * NFC once more after the removal: a joiner between letter and
     * accent blocks the composition — without this, "Rene" + U+0301 would
     * otherwise be a different key from "René".
     */
    private static function nfc(string $s): string {
        if (class_exists(\Normalizer::class)) {
            return \Normalizer::normalize($s, \Normalizer::FORM_C) ?: $s;
        }
        return $s;
    }

    /** Runs of whitespace to one space, edges removed. */
    private static function squash(string $s): string {
        return trim(preg_replace('/[\s\p{Z}]+/u', ' ', $s) ?? '', ' ');
    }

    /**
     * Scale: distribution per value + average.
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
     * Spectrum: per aspect the mean, min, max and n over all votes. A vote is
     * a map aspectId -> value (0..max). If an aspect is missing, it does not count for
     * that vote.
     *
     * @param array $cfg getScaleConfig() (mode=spectrum, with aspects[])
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
     * Compass: all points {x,y} (for scatter/heatmap) + centre of gravity (centroid).
     * The client decides scatter vs. heatmap: the phone and moderator view on
     * total >= heatmapThreshold, the projector (StageCompass) on the number of
     * valid points, max(pointsTotal, points.length), because the public tally
     * only carries a sample of them (PublicPayload). heatmapThreshold is always
     * set; the default 45 is also HEATMAP_THRESHOLD in src/util/format.js.
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
     * Word cloud: collect all words of all votes, lower-case them,
     * in descending order of frequency. total = number of people who voted.
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
            // A word counts at most once per person — also for votes that were
            // stored case-sensitively before this check existed.
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
                // The first spelling is displayed (with variation selector).
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
     * Multiple choice: like choice, but several IDs can be counted per vote
     * (the value is a list). total = number of people.
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
     * Ranking: consensus ranking. Per option the average of the places given
     * (1 = top, smaller is better) and how often it was in first place.
     * Sorted by average — that is the order the audience has agreed
     * on. Options without votes keep their editor
     * position and come last with average 0.
     *
     * `places` additionally carries the full distribution (index k = place k+1). The
     * average alone hides whether an item is uncontroversial or
     * polarising: 2.4 can mean "everyone says place 2 or 3" or "half say
     * place 1, half place 4". Only the distribution makes that readable (§7.5).
     * Sorting, average and `first` stay unchanged.
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
                $acc[$id]['sum'] += $pos + 1; // 1-based places
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
                'pos' => $i, // editor position, only as a stable tiebreaker
            ];
        }
        // Smaller average first; without votes (average 0) to the end.
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
     * Matching: per item the distribution over the targets. The audience agrees
     * row by row — which is why the editor's item order stays as it is
     * (it is the reading direction), and only the targets are counted. `top` is
     * the most chosen target of the row (empty on a tie or without votes)
     * and carries the consensus marker in the poll.
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
                    $top = ''; // a tie is no consensus
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
     * Estimation question: distribution of the numbers + how many were within the tolerance band.
     * The target number itself is NOT in the tally (it arrives with the reveal via answerKey).
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
     * Free text: group answers by normal form (descending frequency),
     * one raw sample text per group + status (accepted/rejected/pending) from
     * the acceptance list. The basis for the moderator's grading.
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
