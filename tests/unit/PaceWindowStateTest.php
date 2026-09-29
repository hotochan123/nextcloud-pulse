<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Room;
use OCA\Pulse\Service\PaceService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Fensterzustand im eigenen Tempo: steht nirgends, sondern folgt aus den
 * Zeitstempeln. Kritisch ist die Grenze der Frist — in der Sekunde closes_at
 * ist das Fenster ZU, überall gleich (Zustand, Stimmen, Versionen).
 */
#[CoversClass(PaceService::class)]
class PaceWindowStateTest extends TestCase {

    private const NOW = 1_800_000_000;

    public function testEntwurfSolangeNieGeoeffnet(): void {
        $room = $this->room();
        $this->assertSame('draft', PaceService::deriveState($room, self::NOW));
    }

    public function testOffenOhneFrist(): void {
        $room = $this->room(openedAt: self::NOW - 100);
        $this->assertSame('open', PaceService::deriveState($room, self::NOW));
    }

    public function testEineSekundeVorDerFristNochOffen(): void {
        $room = $this->room(openedAt: self::NOW - 100, closesAt: self::NOW + 1);
        $this->assertSame('open', PaceService::deriveState($room, self::NOW));
    }

    public function testInDerSekundeDerFristGeschlossen(): void {
        $room = $this->room(openedAt: self::NOW - 100, closesAt: self::NOW);
        $this->assertSame('closed', PaceService::deriveState($room, self::NOW));
    }

    public function testManuellGeschlossenTrotzKuenftigerFrist(): void {
        $room = $this->room(openedAt: self::NOW - 100, closesAt: self::NOW + 3600, closedAt: self::NOW - 5);
        $this->assertSame('closed', PaceService::deriveState($room, self::NOW));
    }

    public function testFreigabeSchlaegtAlles(): void {
        // Offen gelassene Frist, kein closed_at — freigegeben ist freigegeben.
        $room = $this->room(openedAt: self::NOW - 100, closesAt: self::NOW + 3600, releasedAt: self::NOW - 1);
        $this->assertSame('released', PaceService::deriveState($room, self::NOW));
        // Auch ein Raum, der nach Zeitstempeln wie ein Entwurf aussieht.
        $this->assertSame('released', PaceService::deriveState($this->room(releasedAt: self::NOW), self::NOW));
    }

    // ── effectiveClosedAt ──────────────────────────────────────────────────

    public function testSchlussManuellGeschlossen(): void {
        // closed_at gewinnt, auch wenn die Frist danach abgelaufen ist.
        $room = $this->room(openedAt: self::NOW - 100, closesAt: self::NOW - 10, closedAt: self::NOW - 50);
        $this->assertSame(self::NOW - 50, PaceService::effectiveClosedAt($room, self::NOW));
    }

    public function testSchlussDurchAbgelaufeneFrist(): void {
        $room = $this->room(openedAt: self::NOW - 100, closesAt: self::NOW);
        $this->assertSame(self::NOW, PaceService::effectiveClosedAt($room, self::NOW));
    }

    public function testKeinSchlussVorDerFrist(): void {
        $room = $this->room(openedAt: self::NOW - 100, closesAt: self::NOW + 1);
        $this->assertSame(0, PaceService::effectiveClosedAt($room, self::NOW));
    }

    public function testKeinSchlussOhneFristUndOhneSchliessen(): void {
        $room = $this->room(openedAt: self::NOW - 100);
        $this->assertSame(0, PaceService::effectiveClosedAt($room, self::NOW));
    }

    // ── windowView / effectiveFeedback / isSelf ────────────────────────────

    public function testFensterZaehltNachDemOeffnenDieEingefroreneReihenfolge(): void {
        $room = $this->room(openedAt: self::NOW - 100, closesAt: self::NOW - 1);
        $room->setDeckOrder('[12,15,13]');
        $room->setTimed(false);
        $room->setFeedback('end');

        $view = PaceService::windowView($room, self::NOW, 9);

        $this->assertSame([
            'state' => 'closed',
            'openedAt' => self::NOW - 100,
            'closesAt' => self::NOW - 1,
            'closedAt' => self::NOW - 1,
            'releasedAt' => 0,
            'timed' => false,
            'feedback' => 'end',
            'total' => 3,
        ], $view);
    }

    public function testFensterZaehltImEntwurfDasAktuelleDeck(): void {
        $view = PaceService::windowView($this->room(), self::NOW, 4);
        $this->assertSame('draft', $view['state']);
        $this->assertSame(4, $view['total']);
    }

    public function testProbelaufGibtImmerSofortRueckmeldung(): void {
        $room = $this->room();
        $room->setFeedback('end');
        $this->assertSame('end', PaceService::effectiveFeedback($room));

        $room->setPractice(true);
        $this->assertSame('each', PaceService::effectiveFeedback($room));
        $this->assertSame('each', PaceService::windowView($room, self::NOW, 1)['feedback']);
    }

    public function testNurQuizRaeumeLaufenImEigenenTempo(): void {
        $room = $this->room();
        $this->assertTrue(PaceService::isSelf($room));

        $room->setPace('live');
        $this->assertFalse(PaceService::isSelf($room));

        $poll = $this->room();
        $poll->setMode('poll');
        $this->assertFalse(PaceService::isSelf($poll));
    }

    // ── Helfer ─────────────────────────────────────────────────────────────

    private function room(int $openedAt = 0, int $closesAt = 0, int $closedAt = 0, int $releasedAt = 0): Room {
        $room = new Room();
        $room->setId(5);
        $room->setMode('quiz');
        $room->setPace('self');
        $room->setOpenedAt($openedAt);
        $room->setClosesAt($closesAt);
        $room->setClosedAt($closedAt);
        $room->setReleasedAt($releasedAt);
        return $room;
    }
}
