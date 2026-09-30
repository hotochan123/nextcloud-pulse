<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Service\PaceStateService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Self-paced rooms, task M3 of the security review: after the release every
 * phone and the projector poll the final standings. Public views get the top
 * 10 plus the viewer's own row (PublicPayload::withLeaderboard); the
 * moderator's progress view keeps the full list.
 */
#[CoversClass(PaceStateService::class)]
class SelfStateCapTest extends PaceStateTestCase {

    /** Anna answers wrongly, fourteen others correctly: Anna ends up last of 17. */
    private function crowdedRelease(): void {
        $this->row(11, 'tok-anna', self::NOW - 60, self::NOW - 50);
        $this->vote(11, 'tok-anna', 'BB', 0, false, self::NOW - 55);
        for ($i = 1; $i <= 14; $i++) {
            $token = 'tok-p' . $i;
            $this->players[] = $this->player(100 + $i, $token, sprintf('Spieler %02d', $i));
            $this->row(11, $token, self::NOW - 60, self::NOW - 50);
            $this->vote(11, $token, 'AA', 1000 - $i, true, self::NOW - 55);
        }
        $this->release();
    }

    public function testPhoneAfterReleaseTenPlusOwnRow(): void {
        $this->crowdedRelease();

        $state = $this->phone('tok-anna');

        $this->assertCount(10, $state['leaderboard']);
        $this->assertSame(17, $state['leaderboardTotal']);
        $this->assertSame('Anna', $state['leaderboardMe']['nickname']);
        $this->assertTrue($state['leaderboardMe']['me']);
        $this->assertNotContains('Anna', array_column($state['leaderboard'], 'nickname'));
        $this->assertContains('Anna', array_column($state['leaderboardAround'], 'nickname'));
    }

    public function testProjectorAndSummaryCappedModeratorFull(): void {
        $this->crowdedRelease();

        $beamer = $this->beamer();
        $this->assertCount(10, $beamer['leaderboard']);
        $this->assertSame(17, $beamer['leaderboardTotal']);
        $this->assertNull($beamer['leaderboardMe'], 'der Beamer hat kein "ich"');

        $summary = $this->service->publicSummary($this->room, 'tok-anna');
        $this->assertCount(10, $summary['leaderboard']);
        $this->assertSame('Anna', $summary['leaderboardMe']['nickname']);

        $stranger = $this->service->publicSummary($this->room, 'tok-fremd');
        $this->assertCount(10, $stranger['leaderboard']);
        $this->assertSame(17, $stranger['leaderboardTotal']);
        $this->assertNull($stranger['leaderboardMe']);

        $this->assertCount(17, $this->service->progress($this->room, true)['leaderboard'], 'Moderator: alle');
    }

    public function testBeforeReleaseEmptyExtraFields(): void {
        $state = $this->phone('tok-anna');

        $this->assertNull($state['leaderboard']);
        $this->assertSame(0, $state['leaderboardTotal']);
        $this->assertNull($state['leaderboardMe']);
        $this->assertSame([], $state['leaderboardAround']);
    }
}
