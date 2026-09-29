<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Service\TallyService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Auszählung aller Fragetypen. Bekommt Poll + Vote[] herein, keine DB.
 */
#[CoversClass(TallyService::class)]
class TallyServiceTest extends TestCase {

    private TallyService $service;

    protected function setUp(): void {
        $this->service = new TallyService();
    }

    // ── normalizeText: EINE Quelle für Bewertung und Gruppierung ────────────

    public function testNormalizeTextZiehtWhitespaceZusammenUndSchreibtKlein(): void {
        $this->assertSame('hallo welt', TallyService::normalizeText("  Hallo \n\t WELT  "));
    }

    public function testNormalizeTextIstMehrbyteSicher(): void {
        $this->assertSame('grüße über äpfel', TallyService::normalizeText('Grüße   Über  Äpfel'));
    }

    public function testNormalizeTextAufLeerstringBleibtLeer(): void {
        $this->assertSame('', TallyService::normalizeText("   \n "));
    }

    // ── choice / truefalse ─────────────────────────────────────────────────

    public function testChoiceZaehltJeOptionUndBehaeltDieReihenfolge(): void {
        $poll = $this->poll('choice', [['id' => 'AA', 'label' => 'Ja'], ['id' => 'BB', 'label' => 'Nein']]);
        $tally = $this->service->tally($poll, [$this->vote('AA'), $this->vote('BB'), $this->vote('AA')]);

        $this->assertSame('choice', $tally['type']);
        $this->assertSame(3, $tally['total']);
        $this->assertSame([
            ['id' => 'AA', 'label' => 'Ja', 'count' => 2],
            ['id' => 'BB', 'label' => 'Nein', 'count' => 1],
        ], $tally['results']);
    }

    public function testChoiceIgnoriertUnbekannteOptionsIdsZaehltSieAberInTotal(): void {
        // total = abgegebene Stimmen, nicht Summe der Balken (Altstimmen nach
        // dem Bearbeiten einer Frage zeigen ins Leere).
        $poll = $this->poll('choice', [['id' => 'AA', 'label' => 'Ja']]);
        $tally = $this->service->tally($poll, [$this->vote('AA'), $this->vote('WEG')]);

        $this->assertSame(2, $tally['total']);
        $this->assertSame(1, $tally['results'][0]['count']);
    }

    public function testOhneStimmenStehenAlleOptionenAufNull(): void {
        $poll = $this->poll('choice', [['id' => 'AA', 'label' => 'Ja'], ['id' => 'BB', 'label' => 'Nein']]);
        $tally = $this->service->tally($poll, []);

        $this->assertSame(0, $tally['total']);
        $this->assertSame([0, 0], array_column($tally['results'], 'count'));
    }

    // ── words ──────────────────────────────────────────────────────────────

    public function testWortwolkeZaehltKleingeschriebenNachHaeufigkeit(): void {
        $poll = $this->poll('words', []);
        $tally = $this->service->tally($poll, [
            $this->vote(['Kaffee', 'Tee']),
            $this->vote(['kaffee', '  KAFFEE ']),
        ]);

        $this->assertSame('words', $tally['type']);
        $this->assertSame(2, $tally['total'], 'total zählt Personen, nicht Wörter');
        // Die zweite Person schrieb „kaffee“ und „KAFFEE“ — das ist EIN Wort,
        // sie zählt dafür einmal, nicht zweimal.
        $this->assertSame([['word' => 'kaffee', 'count' => 2], ['word' => 'tee', 'count' => 1]], $tally['results']);
    }

    public function testWortwolkeUeberspringtLeereUndNichtStrings(): void {
        $poll = $this->poll('words', []);
        $tally = $this->service->tally($poll, [$this->vote(['', '   ', 42, 'Tee'])]);

        $this->assertSame([['word' => 'tee', 'count' => 1]], $tally['results']);
    }

    // ── scale: single ──────────────────────────────────────────────────────

    public function testSkalaEinzelLiefertVerteilungUndDurchschnitt(): void {
        $poll = $this->poll('scale', ['mode' => 'single', 'min' => 1, 'max' => 3, 'minLabel' => 'kalt', 'maxLabel' => 'heiß']);
        $tally = $this->service->tally($poll, [$this->vote(1), $this->vote(3), $this->vote(3)]);

        $this->assertSame('single', $tally['mode']);
        $this->assertSame(2.33, $tally['average']);
        $this->assertSame([
            ['value' => 1, 'count' => 1],
            ['value' => 2, 'count' => 0],
            ['value' => 3, 'count' => 2],
        ], $tally['results']);
        $this->assertSame('kalt', $tally['minLabel']);
    }

    public function testSkalaEinzelIgnoriertWerteAusserhalbDerSkala(): void {
        $poll = $this->poll('scale', ['mode' => 'single', 'min' => 1, 'max' => 3]);
        $tally = $this->service->tally($poll, [$this->vote(2), $this->vote(9), $this->vote('quatsch')]);

        $this->assertSame(2.0, $tally['average']);
        $this->assertSame(3, $tally['total']);
    }

    public function testSkalaOhneStimmenHatDurchschnittNull(): void {
        $poll = $this->poll('scale', ['mode' => 'single', 'min' => 1, 'max' => 5]);
        $this->assertSame(0.0, $this->service->tally($poll, [])['average']);
    }

    // ── scale: spectrum ────────────────────────────────────────────────────

    public function testSpektrumRechnetJeAspektDurchschnittMinMax(): void {
        $poll = $this->poll('scale', [
            'mode' => 'spectrum',
            'min' => 0,
            'max' => 6,
            'aspects' => [
                ['id' => 'a1', 'label' => 'Tempo', 'poleLow' => 'langsam', 'poleHigh' => 'schnell'],
                ['id' => 'a2', 'label' => 'Klarheit', 'poleLow' => 'unklar', 'poleHigh' => 'klar'],
            ],
        ]);
        $tally = $this->service->tally($poll, [
            $this->vote(['a1' => 2, 'a2' => 6]),
            $this->vote(['a1' => 5, 'a2' => 4]),
        ]);

        $this->assertSame('spectrum', $tally['mode']);
        $this->assertSame(
            [['average' => 3.5, 'min' => 2, 'max' => 5, 'n' => 2], ['average' => 5.0, 'min' => 4, 'max' => 6, 'n' => 2]],
            array_map(static fn (array $r): array => [
                'average' => $r['average'], 'min' => $r['min'], 'max' => $r['max'], 'n' => $r['n'],
            ], $tally['results']),
        );
    }

    public function testSpektrumUeberspringtFehlendeUndAusserhalbLiegendeAspekte(): void {
        $poll = $this->poll('scale', [
            'mode' => 'spectrum', 'min' => 0, 'max' => 4,
            'aspects' => [['id' => 'a1', 'label' => 'Tempo', 'poleLow' => '', 'poleHigh' => '']],
        ]);
        $tally = $this->service->tally($poll, [
            $this->vote(['a1' => 3]),
            $this->vote(['a1' => 99]),   // ausserhalb
            $this->vote([]),             // Aspekt fehlt
        ]);

        $this->assertSame(1, $tally['results'][0]['n']);
        $this->assertSame(3.0, $tally['results'][0]['average']);
        $this->assertSame(3, $tally['total'], 'total bleibt die Zahl der Personen');
    }

    // ── scale: compass ─────────────────────────────────────────────────────

    public function testKompassLiefertPunkteUndSchwerpunkt(): void {
        $poll = $this->poll('scale', ['mode' => 'compass', 'range' => 5, 'heatmapThreshold' => 40]);
        $tally = $this->service->tally($poll, [
            $this->vote(['x' => 2, 'y' => -2]),
            $this->vote(['x' => 4, 'y' => 0]),
        ]);

        $this->assertSame('compass', $tally['mode']);
        $this->assertSame([['x' => 2, 'y' => -2], ['x' => 4, 'y' => 0]], $tally['points']);
        $this->assertSame(['x' => 3.0, 'y' => -1.0], $tally['centroid']);
    }

    public function testKompassVerwirftPunkteAusserhalbDesFeldes(): void {
        $poll = $this->poll('scale', ['mode' => 'compass', 'range' => 3]);
        $tally = $this->service->tally($poll, [
            $this->vote(['x' => 1, 'y' => 1]),
            $this->vote(['x' => 9, 'y' => 0]),
            $this->vote(['x' => 0]),
        ]);

        $this->assertCount(1, $tally['points']);
    }

    public function testKompassOhneStimmenHatKeinenSchwerpunkt(): void {
        $poll = $this->poll('scale', ['mode' => 'compass', 'range' => 5]);
        $this->assertNull($this->service->tally($poll, [])['centroid']);
    }

    // ── multi ──────────────────────────────────────────────────────────────

    public function testMehrfachauswahlZaehltJedeAngekreuzteOption(): void {
        $poll = $this->poll('multi', [
            ['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B'], ['id' => 'CC', 'label' => 'C'],
        ]);
        $tally = $this->service->tally($poll, [
            $this->vote(['AA', 'BB']),
            $this->vote(['BB']),
        ]);

        $this->assertSame([1, 2, 0], array_column($tally['results'], 'count'));
        $this->assertSame(2, $tally['total'], 'total zählt Personen, nicht Kreuze');
    }

    // ── rank ───────────────────────────────────────────────────────────────

    public function testReihenfolgeSortiertNachDurchschnittlichemPlatz(): void {
        $poll = $this->poll('rank', [
            ['id' => 'AA', 'label' => 'Tempo'], ['id' => 'BB', 'label' => 'Preis'], ['id' => 'CC', 'label' => 'Qualität'],
        ]);
        $tally = $this->service->tally($poll, [
            $this->vote(['CC', 'BB', 'AA']),
            $this->vote(['CC', 'AA', 'BB']),
            $this->vote(['BB', 'CC', 'AA']),
        ]);

        $this->assertSame('rank', $tally['type']);
        $this->assertSame(3, $tally['total']);
        // Qualität 1,1,2 -> 1.33 · Preis 2,3,1 -> 2.0 · Tempo 3,2,3 -> 2.67
        $this->assertSame(['Qualität', 'Preis', 'Tempo'], array_column($tally['results'], 'label'));
        $this->assertSame([1.33, 2.0, 2.67], array_column($tally['results'], 'average'));
    }

    public function testReihenfolgeLiefertDiePlatzverteilung(): void {
        $poll = $this->poll('rank', [
            ['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B'], ['id' => 'CC', 'label' => 'C'],
        ]);
        // A polarisiert: zweimal ganz oben, zweimal ganz unten. Der Durchschnitt
        // (2.0) verschweigt das — die Verteilung zeigt es (§7.5).
        $tally = $this->service->tally($poll, [
            $this->vote(['AA', 'BB', 'CC']),
            $this->vote(['AA', 'BB', 'CC']),
            $this->vote(['CC', 'BB', 'AA']),
            $this->vote(['CC', 'BB', 'AA']),
        ]);

        $rows = array_column($tally['results'], 'places', 'label');
        $this->assertSame([2, 0, 2], $rows['A'], 'zweimal Platz 1, zweimal Platz 3');
        $this->assertSame([0, 4, 0], $rows['B'], 'immer in der Mitte');
        $this->assertSame([2, 0, 2], $rows['C']);
        // Die Summe je Zeile ist die Zahl der Stimmen für dieses Element.
        foreach ($tally['results'] as $row) {
            $this->assertSame($row['n'], array_sum($row['places']));
        }
    }

    public function testReihenfolgeZaehltErstplaetze(): void {
        $poll = $this->poll('rank', [['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B']]);
        $tally = $this->service->tally($poll, [
            $this->vote(['AA', 'BB']), $this->vote(['AA', 'BB']), $this->vote(['BB', 'AA']),
        ]);

        $this->assertSame([2, 1], array_column($tally['results'], 'first'));
    }

    public function testReihenfolgeBeiGleichemDurchschnittEntscheidenErstplaetze(): void {
        $poll = $this->poll('rank', [
            ['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B'], ['id' => 'CC', 'label' => 'C'],
        ]);
        // Alle drei landen im Schnitt auf 2.0: A und C je zweimal ganz oben und
        // zweimal ganz unten, B immer in der Mitte. Wer öfter Platz 1 hatte,
        // steht vorn; A vor C entscheidet dann die Editor-Position.
        $tally = $this->service->tally($poll, [
            $this->vote(['AA', 'BB', 'CC']),
            $this->vote(['CC', 'BB', 'AA']),
            $this->vote(['AA', 'BB', 'CC']),
            $this->vote(['CC', 'BB', 'AA']),
        ]);

        $this->assertSame([2.0, 2.0, 2.0], array_column($tally['results'], 'average'));
        $this->assertSame(['A', 'C', 'B'], array_column($tally['results'], 'label'), 'mehr Erstplätze zuerst');
        $this->assertSame([2, 2, 0], array_column($tally['results'], 'first'));
    }

    public function testReihenfolgeOhneStimmenBehaeltDieEditorReihenfolge(): void {
        $poll = $this->poll('rank', [['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B']]);
        $tally = $this->service->tally($poll, []);

        $this->assertSame(['A', 'B'], array_column($tally['results'], 'label'));
        $this->assertSame([0.0, 0.0], array_column($tally['results'], 'average'));
        $this->assertSame([0, 0], array_column($tally['results'], 'n'));
    }

    public function testReihenfolgeStelltUnbewerteteAntwortenHintenAn(): void {
        // Antwort C kam erst nach den Stimmen dazu (theoretisch) — ohne Platzierung
        // darf sie nicht mit average 0 an die Spitze rutschen.
        $poll = $this->poll('rank', [
            ['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B'], ['id' => 'CC', 'label' => 'C'],
        ]);
        $tally = $this->service->tally($poll, [$this->vote(['BB', 'AA'])]);

        $this->assertSame(['B', 'A', 'C'], array_column($tally['results'], 'label'));
        $this->assertSame(0, $tally['results'][2]['n']);
    }

    public function testReihenfolgeIgnoriertFremdeIdsInDerStimme(): void {
        $poll = $this->poll('rank', [['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B']]);
        $tally = $this->service->tally($poll, [$this->vote(['AA', 'WEG', 'BB'])]);

        // Die fremde ID belegt trotzdem Platz 2 — B rutscht dadurch auf Platz 3.
        $this->assertSame(1.0, $tally['results'][0]['average']);
        $this->assertSame(3.0, $tally['results'][1]['average']);
    }

    // ── number ─────────────────────────────────────────────────────────────

    public function testSchaetzfrageZaehltImToleranzbandUndSortiertAufsteigend(): void {
        $poll = $this->poll('number', []);
        $poll->setAnswerKey(json_encode(['target' => 100, 'tolerance' => 5]));
        $tally = $this->service->tally($poll, [
            $this->vote(120), $this->vote(98), $this->vote(105), $this->vote(98),
        ]);

        $this->assertSame(3, $tally['correct'], '98, 98 und 105 liegen im Band');
        $this->assertSame([98, 105, 120], array_column($tally['results'], 'value'));
        $this->assertSame([2, 1, 1], array_column($tally['results'], 'count'));
    }

    public function testSchaetzfrageOhneToleranzVerlangtDenExaktenWert(): void {
        $poll = $this->poll('number', []);
        $poll->setAnswerKey(json_encode(['target' => 42, 'tolerance' => 0]));
        $tally = $this->service->tally($poll, [$this->vote(42), $this->vote(43)]);

        $this->assertSame(1, $tally['correct']);
    }

    // ── text ───────────────────────────────────────────────────────────────

    public function testFreitextGruppiertNachNormalformUndKenntDenStatus(): void {
        $poll = $this->poll('text', []);
        $poll->setAnswerKey(json_encode(['accepted' => ['Paris'], 'rejected' => ['Berlin']]));
        $tally = $this->service->tally($poll, [
            $this->vote('Paris'), $this->vote('  paris '), $this->vote('Berlin'), $this->vote('Lyon'),
        ]);

        $byNorm = [];
        foreach ($tally['answers'] as $a) {
            $byNorm[$a['norm']] = $a;
        }
        $this->assertSame(2, $byNorm['paris']['count']);
        $this->assertSame('accepted', $byNorm['paris']['status']);
        $this->assertSame('rejected', $byNorm['berlin']['status']);
        $this->assertSame('pending', $byNorm['lyon']['status'], 'unbewertet bis der Moderator entscheidet');
        $this->assertSame('Paris', $byNorm['paris']['sample'], 'Rohtext der ersten Nennung als Beispiel');
    }

    public function testFreitextSortiertNachHaeufigkeit(): void {
        $poll = $this->poll('text', []);
        $tally = $this->service->tally($poll, [
            $this->vote('selten'), $this->vote('oft'), $this->vote('oft'), $this->vote('oft'),
        ]);

        $this->assertSame(['oft', 'selten'], array_column($tally['answers'], 'norm'));
    }

    public function testFreitextUeberspringtLeereAntworten(): void {
        $poll = $this->poll('text', []);
        $tally = $this->service->tally($poll, [$this->vote(''), $this->vote('Paris')]);

        $this->assertCount(1, $tally['answers']);
        $this->assertSame(2, $tally['total'], 'total bleibt die Zahl der Stimmen');
    }

    // ── Helfer ─────────────────────────────────────────────────────────────

    /** @param array $options Options-Liste (choice/multi) oder Skalen-Config (scale) */
    // ── Zuordnung ──────────────────────────────────────────────────────────

    public function testZuordnungZaehltJeZeileUeberDieZiele(): void {
        $poll = $this->matchPoll();
        $tally = $this->service->tally($poll, [
            $this->vote(['I1' => 'T1', 'I2' => 'T2']),
            $this->vote(['I1' => 'T1', 'I2' => 'T1']),
        ]);

        $this->assertSame('match', $tally['type']);
        $this->assertSame(2, $tally['total']);
        $this->assertSame(['I1', 'I2'], array_column($tally['results'], 'id'));
        $this->assertSame([2, 0], array_column($tally['results'][0]['targets'], 'count'));
        $this->assertSame([1, 1], array_column($tally['results'][1]['targets'], 'count'));
        $this->assertSame(2, $tally['results'][0]['n']);
    }

    public function testZuordnungMarkiertDasMeistgewaehlteZielAlsKonsens(): void {
        $poll = $this->matchPoll();
        $tally = $this->service->tally($poll, [
            $this->vote(['I1' => 'T2', 'I2' => 'T2']),
            $this->vote(['I1' => 'T2', 'I2' => 'T1']),
        ]);

        $this->assertSame('T2', $tally['results'][0]['top']);
    }

    public function testZuordnungOhneKonsensLaesstTopLeer(): void {
        // Gleichstand ist keine Einigung — sonst gewönne die Editor-Reihenfolge.
        $poll = $this->matchPoll();
        $tally = $this->service->tally($poll, [
            $this->vote(['I1' => 'T1']),
            $this->vote(['I1' => 'T2']),
        ]);

        $this->assertSame('', $tally['results'][0]['top']);
        $this->assertSame(2, $tally['results'][0]['n']);
    }

    public function testZuordnungIgnoriertUnbekannteIdsZaehltDieStimmeAber(): void {
        $poll = $this->matchPoll();
        $tally = $this->service->tally($poll, [$this->vote(['I1' => 'WEG', 'WEG' => 'T1'])]);

        $this->assertSame(1, $tally['total']);
        $this->assertSame(0, $tally['results'][0]['n']);
    }

    public function testZuordnungOhneStimmenLiefertAlleZeilenMitNull(): void {
        $tally = $this->service->tally($this->matchPoll(), []);

        $this->assertSame(0, $tally['total']);
        $this->assertCount(2, $tally['results']);
        $this->assertSame([0, 0], array_column($tally['results'], 'n'));
        $this->assertSame(['', ''], array_column($tally['results'], 'top'));
    }

    /** Zwei Items, zwei Ziele — Ziel-Reihenfolge ist die des Editors. */
    private function matchPoll(): Poll {
        $poll = new Poll();
        $poll->setType('match');
        $poll->setQuestion('Was passt?');
        $poll->setOptions(json_encode([
            'items' => [['id' => 'I1', 'label' => 'Frankreich'], ['id' => 'I2', 'label' => 'Italien']],
            'targets' => [['id' => 'T1', 'label' => 'Paris'], ['id' => 'T2', 'label' => 'Rom']],
        ]));
        return $poll;
    }

    private function poll(string $type, array $options): Poll {
        $poll = new Poll();
        $poll->setType($type);
        $poll->setQuestion('Frage?');
        $poll->setOptions(json_encode($options));
        return $poll;
    }

    private function vote(mixed $value): Vote {
        $vote = new Vote();
        $vote->setPayload(json_encode(['value' => $value]));
        return $vote;
    }
}
