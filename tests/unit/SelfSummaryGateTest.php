<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Service\PaceStateService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Gesamtauswertung fürs Handy im eigenen Tempo (PaceStateService::publicSummary).
 *
 * /summary darf nie ein Lösungsschlüssel sein: ohne Cookie oder ohne erreichte
 * Frage höchstens der Endstand. Vor der Freigabe nur die eigenen Fragen ohne
 * Lösung (im offenen Fenster nur die verlassenen), nach der Freigabe genau die
 * erreichten — mit Lösung und Auszählung. Ein Tipp auf „Start" darf nicht für
 * das ganze Deck reichen. Im Probelauf gibt es nie eine Rangliste.
 */
#[CoversClass(PaceStateService::class)]
class SelfSummaryGateTest extends PaceStateTestCase {

    public static function cookies(): array {
        return ['ohne Cookie' => [null], 'leeres Cookie' => [''], 'Cookie ohne Zeilen' => ['tok-cem']];
    }

    #[DataProvider('cookies')]
    public function testOhneZeilenVorDerFreigabeNichts(?string $token): void {
        $this->annaReachedTwo();

        foreach (['offen' => fn () => null, 'geschlossen' => fn () => $this->close()] as $state => $apply) {
            $apply();
            $summary = $this->service->publicSummary($this->room, $token);

            $this->assertSame([], $summary['items'], $state);
            $this->assertNull($summary['leaderboard'], $state);
            $this->assertFalse($summary['available'], $state);
            $this->assertNull($summary['myScore'], $state);
        }
    }

    #[DataProvider('cookies')]
    public function testOhneZeilenNachDerFreigabeNurDerEndstand(?string $token): void {
        $this->annaReachedTwo();
        $this->release();

        $summary = $this->service->publicSummary($this->room, $token);

        $this->assertSame([], $summary['items']);
        $this->assertTrue($summary['available']);
        $this->assertSame(['Anna' => 900, 'Ben' => 0, 'Cem' => 0], self::column($summary['leaderboard'], 'score'));
        $this->assertNotContains(true, array_column($summary['leaderboard'], 'me'));
    }

    public function testOffenNurVerlasseneFragenOhneLoesung(): void {
        $this->annaReachedTwo();

        $summary = $this->service->publicSummary($this->room, 'tok-anna');

        $this->assertSame([11], array_map(static fn (array $i): int => $i['poll']['id'], $summary['items']), 'die offene Q2 steht auf dem Hauptbildschirm');
        $item = $summary['items'][0];
        $this->assertFalse($item['revealed']);
        $this->assertNull($item['results']);
        $this->assertSame(
            ['value' => 'AA', 'answered' => true, 'final' => true, 'verdict' => 'correct', 'correct' => true, 'points' => 900],
            $item['mine'],
        );
        $this->assertNull($summary['leaderboard']);
        $this->assertSame(900, $summary['myScore']);
        $this->assertTrue($summary['available']);
        $this->assertNoSolution($summary);
    }

    public function testGeschlossenAlleErreichtenOhneLoesung(): void {
        $this->annaReachedTwo();
        $this->close();

        $summary = $this->service->publicSummary($this->room, 'tok-anna');

        $this->assertSame([11, 12], array_map(static fn (array $i): int => $i['poll']['id'], $summary['items']));
        $this->assertNull($summary['items'][1]['mine'], 'Q2 unbeantwortet');
        $this->assertNull($summary['leaderboard'], 'geschlossen ist nicht freigegeben');
        $this->assertNoSolution($summary);
    }

    public function testRueckmeldungAmEndeOhneUrteil(): void {
        $this->room->setFeedback('end');
        $this->annaReachedTwo();

        $summary = $this->service->publicSummary($this->room, 'tok-anna');

        $this->assertSame('saved', $summary['items'][0]['mine']['verdict']);
        $this->assertNull($summary['items'][0]['mine']['correct']);
        $this->assertNull($summary['myScore']);
    }

    public function testNachDerFreigabeGenauDieErreichtenMitLoesungUndAuszaehlung(): void {
        $this->annaReachedTwo();
        $this->release();

        $summary = $this->service->publicSummary($this->room, 'tok-anna');

        $this->assertSame([11, 12], array_map(static fn (array $i): int => $i['poll']['id'], $summary['items']), 'Q3 nie erreicht: fehlt ganz');
        $first = $summary['items'][0];
        $this->assertTrue($first['revealed']);
        $this->assertTrue($first['poll']['revealed']);
        $this->assertSame('AA', $first['poll']['correctOption']);
        $this->assertArrayHasKey('answerKey', $first['poll']);
        $this->assertSame(2, $first['results']['total'], 'alle Stimmen der Frage, auch fremde');
        $this->assertSame(['JU01', 'SA02', 'NE03'], array_column($summary['items'][1]['poll']['options'], 'id'), 'aufgelöst: gespeicherte Folge');
        $this->assertSame(['order' => ['JU01', 'SA02', 'NE03']], $summary['items'][1]['poll']['answerKey']);
        $this->assertSame(['Anna' => true, 'Ben' => false, 'Cem' => false], self::column($summary['leaderboard'], 'me'));
        $this->assertStringNotContainsString('Größter Planet', json_encode($summary, JSON_UNESCAPED_UNICODE));
    }

    public function testProbelaufNieEineRangliste(): void {
        $this->room->setPractice(true);
        $this->annaReachedTwo();
        $this->release();

        $this->assertNull($this->service->publicSummary($this->room, 'tok-anna')['leaderboard']);
        $withoutCookie = $this->service->publicSummary($this->room, null);
        $this->assertNull($withoutCookie['leaderboard']);
        $this->assertFalse($withoutCookie['available']);
    }

    public function testKopf(): void {
        $summary = $this->service->publicSummary($this->room, null);

        $this->assertSame('quiz', $summary['mode']);
        $this->assertSame('self', $summary['pace']);
        $this->assertSame('Planeten', $summary['title']);
        $this->assertSame('open', $summary['window']['state']);
    }

    /** Anna: Q1 richtig beantwortet und verlassen, steht auf Q2. Ben: Q1 falsch. */
    private function annaReachedTwo(): void {
        $this->row(11, 'tok-anna', self::NOW - 60, self::NOW - 40);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 50);
        $this->row(12, 'tok-anna', self::NOW - 40);
        $this->row(11, 'tok-ben', self::NOW - 60, self::NOW - 40);
        $this->vote(11, 'tok-ben', 'BB', 0, false, self::NOW - 50);
    }

    private function assertNoSolution(array $summary): void {
        $json = json_encode($summary);
        $this->assertStringNotContainsString('correctOption', $json);
        $this->assertStringNotContainsString('answerKey', $json);
        foreach ($summary['items'] as $item) {
            $this->assertFalse($item['revealed']);
            $this->assertNull($item['results']);
        }
    }
}
