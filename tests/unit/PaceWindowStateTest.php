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
 * Self-paced window state: it is not stored anywhere but follows from the
 * timestamps. The critical part is the deadline boundary — in the second of
 * closes_at the window is CLOSED, the same everywhere (state, votes, versions).
 */
#[CoversClass(PaceService::class)]
class PaceWindowStateTest extends TestCase {

    private const NOW = 1_800_000_000;

    public function testDraftAsLongAsNeverOpened(): void {
        $room = $this->room();
        $this->assertSame('draft', PaceService::deriveState($room, self::NOW));
    }

    public function testOpenWithoutDeadline(): void {
        $room = $this->room(openedAt: self::NOW - 100);
        $this->assertSame('open', PaceService::deriveState($room, self::NOW));
    }

    public function testStillOpenOneSecondBeforeTheDeadline(): void {
        $room = $this->room(openedAt: self::NOW - 100, closesAt: self::NOW + 1);
        $this->assertSame('open', PaceService::deriveState($room, self::NOW));
    }

    public function testClosedInTheSecondOfTheDeadline(): void {
        $room = $this->room(openedAt: self::NOW - 100, closesAt: self::NOW);
        $this->assertSame('closed', PaceService::deriveState($room, self::NOW));
    }

    public function testClosedManuallyDespiteAFutureDeadline(): void {
        $room = $this->room(openedAt: self::NOW - 100, closesAt: self::NOW + 3600, closedAt: self::NOW - 5);
        $this->assertSame('closed', PaceService::deriveState($room, self::NOW));
    }

    public function testReleaseBeatsEverything(): void {
        // Deadline left open, no closed_at — released is released.
        $room = $this->room(openedAt: self::NOW - 100, closesAt: self::NOW + 3600, releasedAt: self::NOW - 1);
        $this->assertSame('released', PaceService::deriveState($room, self::NOW));
        // Even a room that looks like a draft by its timestamps.
        $this->assertSame('released', PaceService::deriveState($this->room(releasedAt: self::NOW), self::NOW));
    }

    // ── effectiveClosedAt ──────────────────────────────────────────────────

    public function testCloseWhenClosedManually(): void {
        // closed_at wins, even if the deadline expired afterwards.
        $room = $this->room(openedAt: self::NOW - 100, closesAt: self::NOW - 10, closedAt: self::NOW - 50);
        $this->assertSame(self::NOW - 50, PaceService::effectiveClosedAt($room, self::NOW));
    }

    public function testCloseThroughAnExpiredDeadline(): void {
        $room = $this->room(openedAt: self::NOW - 100, closesAt: self::NOW);
        $this->assertSame(self::NOW, PaceService::effectiveClosedAt($room, self::NOW));
    }

    public function testNoCloseBeforeTheDeadline(): void {
        $room = $this->room(openedAt: self::NOW - 100, closesAt: self::NOW + 1);
        $this->assertSame(0, PaceService::effectiveClosedAt($room, self::NOW));
    }

    public function testNoCloseWithoutDeadlineAndWithoutClosing(): void {
        $room = $this->room(openedAt: self::NOW - 100);
        $this->assertSame(0, PaceService::effectiveClosedAt($room, self::NOW));
    }

    // ── windowView / effectiveFeedback / isSelf ────────────────────────────

    public function testWindowCountsTheFrozenOrderAfterOpening(): void {
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

    public function testWindowCountsTheCurrentDeckInDraft(): void {
        $view = PaceService::windowView($this->room(), self::NOW, 4);
        $this->assertSame('draft', $view['state']);
        $this->assertSame(4, $view['total']);
    }

    public function testPracticeRunAlwaysGivesImmediateFeedback(): void {
        $room = $this->room();
        $room->setFeedback('end');
        $this->assertSame('end', PaceService::effectiveFeedback($room));

        $room->setPractice(true);
        $this->assertSame('each', PaceService::effectiveFeedback($room));
        $this->assertSame('each', PaceService::windowView($room, self::NOW, 1)['feedback']);
    }

    public function testOnlyQuizRoomsRunInSelfPacedMode(): void {
        $room = $this->room();
        $this->assertTrue(PaceService::isSelf($room));

        $room->setPace('live');
        $this->assertFalse(PaceService::isSelf($room));

        $poll = $this->room();
        $poll->setMode('poll');
        $this->assertFalse(PaceService::isSelf($poll));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

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
