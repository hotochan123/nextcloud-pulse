<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Room;
use OCA\Pulse\Service\PaceStateService;
use OCA\Pulse\Service\RoomService;
use OCA\Pulse\Service\StateService;
use OCA\Pulse\Service\VoteService;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Versionen im eigenen Tempo (PaceStateService::version).
 *
 * Die Version ist ein Schlüssel-Hash über den fertig gebauten Zustand ohne
 * serverNow. Sie muss springen, wenn die Zeit allein etwas ändert — Frist
 * abgelaufen, Stimme endgültig, Zeitlimit um — obwohl in der Datenbank nichts
 * passiert. Und sie darf NICHT springen, solange nur die Sekunden vergehen,
 * sonst gäbe es nie ein 204. Im Fortschritt des Moderators zählt `online`,
 * nicht „zuletzt gesehen" (das wechselt mit jedem Heartbeat).
 */
#[CoversClass(PaceStateService::class)]
#[CoversClass(StateService::class)]
class SelfVersionTest extends PaceStateTestCase {

    public function testVersionIst24HexZeichen(): void {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $this->phoneVersion());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $this->beamerVersion());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $this->service->version($this->service->progress($this->room)));
    }

    public function testFristLaeuftAbOhneDatenbankAenderung(): void {
        $this->room->setClosesAt(self::NOW + 1);
        $this->row(11, 'tok-anna', self::NOW - 5);
        $before = $this->phoneVersion();

        $this->now = self::NOW + 1;

        $this->assertNotSame($before, $this->phoneVersion(), 'Handy');
        $this->now = self::NOW;
        $beamerBefore = $this->beamerVersion();
        $this->now = self::NOW + 1;
        $this->assertNotSame($beamerBefore, $this->beamerVersion(), 'Beamer: die Frist steht im Cache-Schlüssel');
    }

    public function testStimmeWirdEndgueltig(): void {
        $this->row(11, 'tok-anna', self::NOW - 10);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW);

        $this->now = self::NOW + 3;
        $pending = $this->phoneVersion();
        $this->now = self::NOW + 4;

        $this->assertNotSame($pending, $this->phoneVersion(), 'created+fw -> +fw+1');
    }

    public function testSekundenOhneEreignisAendernNichts(): void {
        $this->row(11, 'tok-anna', self::NOW - 5);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 30);
        $first = $this->phoneVersion();

        $this->now = self::NOW + 1;

        $this->assertSame($first, $this->phoneVersion());
        $this->assertNotSame(self::NOW, $this->phone()['serverNow'], 'nur serverNow läuft');
    }

    public function testZeitUmKipptGenauEinmal(): void {
        $this->row(11, 'tok-anna', self::NOW - 20);
        $running = $this->phoneVersion();

        $this->now = self::NOW + 1;
        $up = $this->phoneVersion();
        $this->now = self::NOW + 2;

        $this->assertNotSame($running, $up);
        $this->assertSame($up, $this->phoneVersion());
    }

    public function testPraesenzNurImEntwurfUndAufDemBeamer(): void {
        $this->row(11, 'tok-anna', self::NOW - 5);
        $phone = $this->phoneVersion();
        $beamer = $this->beamerVersion();

        $this->present = 5;
        $this->cacheStore = [];

        $this->assertSame($phone, $this->phoneVersion(), 'Handy im Rennen: Heartbeats treiben die Version nicht');
        $this->assertNotSame($beamer, $this->beamerVersion(), 'Beamer sieht, wer dazukommt');

        $this->room->setOpenedAt(0);
        $this->room->setDeckOrder(null);
        $this->rows = [];
        $lobby = $this->phoneVersion();
        $this->present = 6;
        $this->assertNotSame($lobby, $this->phoneVersion(), 'Wartezustand: „N dabei"');
    }

    public function testFortschrittZuletztGesehenNichtImHash(): void {
        $this->row(11, 'tok-anna', self::NOW - 5);
        $this->seen = ['tok-anna' => self::NOW - 1];
        $first = $this->service->version($this->service->progress($this->room));

        $this->seen = ['tok-anna' => self::NOW];
        $this->assertSame($first, $this->service->version($this->service->progress($this->room)), 'Heartbeat, weiter online');

        $this->seen = ['tok-anna' => self::NOW - 16];
        $this->assertNotSame($first, $this->service->version($this->service->progress($this->room)), 'offline');
    }

    public function testBeamerImSelbenEimerEinBau(): void {
        $this->row(11, 'tok-anna', self::NOW - 5);
        $first = $this->beamer();
        $built = $this->calls['progress.findByRoom']; // Beamer-Aggregat + Rangliste

        $this->now = self::NOW + 1;
        $this->row(12, 'tok-ben', self::NOW); // Änderung im selben Eimer erscheint erst im nächsten
        $second = $this->beamer();

        $this->assertSame($built, $this->calls['progress.findByRoom'], 'kein zweiter Bau');
        $this->assertSame($this->service->version($first), $this->service->version($second));
        $this->assertSame(self::NOW, $first['serverNow']);
        $this->assertSame(self::NOW + 1, $second['serverNow'], 'die Uhr ist je Antwort frisch');

        $this->now = self::NOW + 2;
        $third = $this->beamer();
        $this->assertGreaterThan($built, $this->calls['progress.findByRoom'], 'nächster Eimer');
        $this->assertNotSame($this->service->version($first), $this->service->version($third));
    }

    public function testVersionOhneServerNowUndVersion(): void {
        $state = $this->phone();

        $this->assertSame(
            $this->service->version($state),
            $this->service->version(['serverNow' => 1, 'version' => 'x'] + $state),
        );
    }

    // ── StateService ───────────────────────────────────────────────────────

    public function testStateServiceDelegiertImEigenenTempo(): void {
        $this->row(11, 'tok-anna', self::NOW - 5);
        $state = $this->stateService();

        $this->assertSame($this->phoneVersion(), $state->stateVersion($this->room, false, 'tok-anna'));
        $this->assertSame($this->phoneVersion(), $state->selfVersion($this->phone()));
        $this->assertNotSame($this->phoneVersion(), $state->stateVersion($this->room, false, 'tok-ben'), 'je Token');
        $this->assertSame($this->phone(), $state->publicState($this->room, 'tok-anna'));
    }

    public function testModeriertFasstDenNeuenDienstNieAn(): void {
        $paceState = $this->createMock(PaceStateService::class);
        $paceState->expects($this->never())->method($this->anything());
        $roomService = $this->createMock(RoomService::class);
        $roomService->method('presentCount')->willReturn(3);
        $voteService = $this->createMock(VoteService::class);
        $voteService->method('playerCount')->willReturn(2);
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->willReturn(self::SECRET);
        $state = self::build(StateService::class, [
            'roomService' => $roomService,
            'voteService' => $voteService,
            'config' => $config,
            'paceState' => $paceState,
        ]);
        $live = new Room();
        $live->setMode('quiz');

        // Lobby: das Token spielt moderiert keine Rolle.
        $this->assertSame($state->stateVersion($live), $state->stateVersion($live, false, 'tok-anna'));
    }

    private function stateService(): StateService {
        return self::build(StateService::class, [
            'paceState' => $this->service,
        ]);
    }

    private function phoneVersion(string $token = 'tok-anna'): string {
        return $this->service->version($this->phone($token));
    }

    private function beamerVersion(): string {
        return $this->service->version($this->beamer());
    }
}
