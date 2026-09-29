<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Room;
use OCA\Pulse\Service\PaceService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Eingefrorene Reihenfolge: die einzige Quelle für „Frage k von n" und die
 * nächste Frage. `seq` ist immer der Index in der VOLLEN Reihenfolge — auch
 * wenn eine Frage fehlt (Abwehr), damit k/n auf allen Sichten übereinstimmen.
 */
#[CoversClass(PaceService::class)]
class PaceOrderTest extends TestCase {

    public static function muell(): array {
        return [
            'null (Entwurf)' => [null, []],
            'leer' => ['', []],
            'kein JSON' => ['[1,2', []],
            'Skalar' => ['7', []],
            'String' => ['"12,13"', []],
            'JSON null' => ['null', []],
            'gemischt' => ['[3,"5","x",3,-1,0,2.5,null,[7],true,"5","08"]', [3, 5, 8]],
        ];
    }

    #[DataProvider('muell')]
    public function testReihenfolgeNimmtNurGanzzahligeIdsOhneDubletten(?string $json, array $want): void {
        $this->assertSame($want, PaceService::order($this->room($json)));
    }

    public function testErsteFrageNachNull(): void {
        $this->assertSame(['pollId' => 12, 'seq' => 0], PaceService::nextAfter($this->room('[12,15,13]'), 0));
    }

    public function testNachfolgerInDerMitte(): void {
        $this->assertSame(['pollId' => 13, 'seq' => 2], PaceService::nextAfter($this->room('[12,15,13]'), 15));
    }

    public function testNachDerLetztenFrageNichts(): void {
        $this->assertNull(PaceService::nextAfter($this->room('[12,15,13]'), 13));
    }

    public function testUnbekannteFrageNichts(): void {
        $this->assertNull(PaceService::nextAfter($this->room('[12,15,13]'), 99));
    }

    public function testImEntwurfGibtEsKeineErsteFrage(): void {
        $this->assertNull(PaceService::nextAfter($this->room(null), 0));
    }

    public function testFehlendeFrageWirdUebersprungenSeqBleibtVoll(): void {
        $room = $this->room('[11,12,13]');
        $this->assertSame(['pollId' => 13, 'seq' => 2], PaceService::nextAfter($room, 11, [11, 13]));
        // Die gelöschte Frage selbst bleibt ein gültiges $after (offene Zeile einer gelöschten Frage).
        $this->assertSame(['pollId' => 13, 'seq' => 2], PaceService::nextAfter($room, 12, [11, 13]));
        // Fehlt die erste Frage, beginnt es bei der zweiten mit seq 1.
        $this->assertSame(['pollId' => 12, 'seq' => 1], PaceService::nextAfter($room, 0, [12, 13]));
        // Ohne Filter zählt die volle Reihenfolge.
        $this->assertSame(['pollId' => 12, 'seq' => 1], PaceService::nextAfter($room, 11));
        // Nichts mehr übrig.
        $this->assertNull(PaceService::nextAfter($room, 12, [11, 12]));
    }

    private function room(?string $order): Room {
        $room = new Room();
        $room->setId(5);
        $room->setMode('quiz');
        $room->setPace('self');
        $room->setDeckOrder($order);
        return $room;
    }
}
