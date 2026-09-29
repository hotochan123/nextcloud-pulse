<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Progress;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\VoteService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * EINE Regel für „endgültig", „korrigierbar", „fertig" und den /next-Wert —
 * Handy, Beamer, Fortschritt, Rangliste und CSV rechnen alle damit. Liefe eine
 * Sicht nach einer eigenen Regel, stünde auf dem Beamer ein anderer Punktestand
 * als auf dem Handy.
 */
#[CoversClass(PaceService::class)]
class PaceFinalRuleTest extends TestCase {

    private const NOW = 1_800_000_000;

    // ── isFinal ────────────────────────────────────────────────────────────

    public function testKorrekturfensterDreiSekundenStrikt(): void {
        $payload = ['value' => 'a', 'fw' => 3];
        $this->assertFalse(PaceService::isFinal($payload, 100, 103));
        $this->assertTrue(PaceService::isFinal($payload, 100, 104));
    }

    public function testKorrekturfensterSechsSekundenBeiTastatur(): void {
        $payload = ['value' => 'a', 'fw' => 6];
        $this->assertFalse(PaceService::isFinal($payload, 100, 106));
        $this->assertTrue(PaceService::isFinal($payload, 100, 107));
    }

    public function testKorrigierteStimmeIstSofortEndgueltig(): void {
        $this->assertTrue(PaceService::isFinal(['value' => 'a', 'fw' => 6, 'fixed' => true], 100, 100));
    }

    public function testOhneFwGiltDasNormaleFenster(): void {
        $this->assertSame(3, VoteService::FIX_WINDOW);
        $this->assertFalse(PaceService::isFinal(['value' => 'a'], 100, 103));
        $this->assertTrue(PaceService::isFinal(['value' => 'a'], 100, 104));
    }

    public function testNichtMehrKorrigierbarIstSofortEndgueltig(): void {
        // Fenster zu oder Frage verlassen: kein Warten auf fw.
        $this->assertTrue(PaceService::isFinal(['value' => 'a', 'fw' => 6], 100, 100, false));
    }

    // ── correctable ────────────────────────────────────────────────────────

    public function testKorrigierbarNurBeiOffenemFensterUndOffenerZeile(): void {
        $open = $this->room(openedAt: self::NOW - 100);
        $this->assertTrue(PaceService::correctable($open, self::NOW, $this->row(seq: 0)));
        $this->assertFalse(PaceService::correctable($open, self::NOW, $this->row(seq: 0, leftAt: self::NOW - 1)));
        $this->assertFalse(PaceService::correctable($open, self::NOW, null));
    }

    public function testNichtKorrigierbarBeiGeschlossenemOderFreigegebenemFenster(): void {
        $closed = $this->room(openedAt: self::NOW - 100, closesAt: self::NOW);
        // Rennen gestoppt, ohne Freigabe (close {release: false}): Frist 0.
        $stopped = $this->room(openedAt: self::NOW - 100, closedAt: self::NOW - 1);
        $released = $this->room(openedAt: self::NOW - 100, closedAt: self::NOW - 1, releasedAt: self::NOW - 1);
        $draft = $this->room();
        foreach ([$closed, $stopped, $released, $draft] as $room) {
            $this->assertFalse(PaceService::correctable($room, self::NOW, $this->row(seq: 0)));
        }
    }

    // ── isFinished ─────────────────────────────────────────────────────────

    public function testLetzteFrageBeantwortetOhneFertigTippenIstFertig(): void {
        $this->assertTrue(PaceService::isFinished(3, $this->row(seq: 2), true, 'open'));
    }

    public function testLetzteFrageOffenOhneStimmeBeiOffenemFensterNichtFertig(): void {
        $this->assertFalse(PaceService::isFinished(3, $this->row(seq: 2), false, 'open'));
    }

    public function testLetzteFrageOffenOhneStimmeNachSchlussFertig(): void {
        $this->assertTrue(PaceService::isFinished(3, $this->row(seq: 2), false, 'closed'));
        $this->assertTrue(PaceService::isFinished(3, $this->row(seq: 2), false, 'released'));
    }

    public function testLetzteFrageVerlassenIstFertig(): void {
        $this->assertTrue(PaceService::isFinished(3, $this->row(seq: 2, leftAt: self::NOW), false, 'open'));
    }

    public function testVorDerLetztenFrageNieFertig(): void {
        $this->assertFalse(PaceService::isFinished(3, $this->row(seq: 1, leftAt: self::NOW), true, 'closed'));
        $this->assertFalse(PaceService::isFinished(3, null, false, 'released'));
    }

    // ── afterFor ───────────────────────────────────────────────────────────

    public function testAfterIstDieOffeneFrage(): void {
        $rows = [
            $this->row(seq: 0, pollId: 11, leftAt: self::NOW - 20),
            $this->row(seq: 1, pollId: 12),
        ];
        $this->assertSame(12, PaceService::afterFor($rows));
    }

    public function testAfterOhneOffeneZeileIstDieZuletztErreichte(): void {
        // Abbruch zwischen Schließen und Starten: keine offene Zeile.
        $rows = [
            $this->row(seq: 0, pollId: 11, leftAt: self::NOW - 20),
            $this->row(seq: 1, pollId: 12, leftAt: self::NOW - 10),
        ];
        $this->assertSame(12, PaceService::afterFor($rows));
    }

    public function testAfterOhneZeilenIstNull(): void {
        $this->assertSame(0, PaceService::afterFor([]));
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

    private function row(int $seq, int $pollId = 11, int $leftAt = 0): Progress {
        $row = new Progress();
        $row->setRoomId(5);
        $row->setPollId($pollId);
        $row->setVoterToken('tok');
        $row->setSeq($seq);
        $row->setStartedAt(self::NOW - 30);
        $row->setLeftAt($leftAt);
        return $row;
    }
}
