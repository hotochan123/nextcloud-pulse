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
 * Frozen order: the single source for "Question k of n" and the
 * next question. `seq` is always the index in the FULL order — even
 * when a question is missing (defensive), so that k/n agree across all views.
 */
#[CoversClass(PaceService::class)]
class PaceOrderTest extends TestCase {

    public static function garbage(): array {
        return [
            'null (draft)' => [null, []],
            'empty' => ['', []],
            'not JSON' => ['[1,2', []],
            'scalar' => ['7', []],
            'String' => ['"12,13"', []],
            'JSON null' => ['null', []],
            'mixed' => ['[3,"5","x",3,-1,0,2.5,null,[7],true,"5","08"]', [3, 5, 8]],
        ];
    }

    #[DataProvider('garbage')]
    public function testOrderTakesOnlyIntegerIdsWithoutDuplicates(?string $json, array $want): void {
        $this->assertSame($want, PaceService::order($this->room($json)));
    }

    public function testFirstQuestionAfterZero(): void {
        $this->assertSame(['pollId' => 12, 'seq' => 0], PaceService::nextAfter($this->room('[12,15,13]'), 0));
    }

    public function testSuccessorInTheMiddle(): void {
        $this->assertSame(['pollId' => 13, 'seq' => 2], PaceService::nextAfter($this->room('[12,15,13]'), 15));
    }

    public function testNothingAfterTheLastQuestion(): void {
        $this->assertNull(PaceService::nextAfter($this->room('[12,15,13]'), 13));
    }

    public function testUnknownQuestionGivesNothing(): void {
        $this->assertNull(PaceService::nextAfter($this->room('[12,15,13]'), 99));
    }

    public function testInDraftThereIsNoFirstQuestion(): void {
        $this->assertNull(PaceService::nextAfter($this->room(null), 0));
    }

    public function testMissingQuestionIsSkippedSeqStaysFull(): void {
        $room = $this->room('[11,12,13]');
        $this->assertSame(['pollId' => 13, 'seq' => 2], PaceService::nextAfter($room, 11, [11, 13]));
        // The deleted question itself stays a valid $after (open row of a deleted question).
        $this->assertSame(['pollId' => 13, 'seq' => 2], PaceService::nextAfter($room, 12, [11, 13]));
        // If the first question is missing, it starts at the second one with seq 1.
        $this->assertSame(['pollId' => 12, 'seq' => 1], PaceService::nextAfter($room, 0, [12, 13]));
        // Without a filter, the full order counts.
        $this->assertSame(['pollId' => 12, 'seq' => 1], PaceService::nextAfter($room, 11));
        // Nothing left.
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
