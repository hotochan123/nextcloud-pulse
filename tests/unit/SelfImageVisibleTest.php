<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Service\PaceStateService;
use OCA\Pulse\Service\StateService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Image visibility in self-paced mode (StateService::imageVisible -> PaceStateService).
 *
 * A question image gives the question away. There is no cursor, so all that counts
 * is whether THIS person has reached the question — in every window state, even after
 * the release. In moderated mode the rule stays "currently running or revealed".
 */
#[CoversClass(PaceStateService::class)]
#[CoversClass(StateService::class)]
class SelfImageVisibleTest extends PaceStateTestCase {

    public function testWithoutCookieNever(): void {
        $this->row(11, 'tok-anna', self::NOW - 5);

        $this->assertFalse($this->visible(11, null));
        $this->assertFalse($this->visible(11, ''));
    }

    public function testWithAReachedQuestion(): void {
        $this->row(11, 'tok-anna', self::NOW - 20, self::NOW - 10);
        $this->row(12, 'tok-anna', self::NOW - 10);

        $this->assertTrue($this->visible(11, 'tok-anna'), 'verlassen');
        $this->assertTrue($this->visible(12, 'tok-anna'), 'offen');
        $this->assertFalse($this->visible(13, 'tok-anna'), 'noch nicht erreicht');
        $this->assertFalse($this->visible(12, 'tok-ben'), 'fremde Zeile zählt nicht');
    }

    public function testAfterTheReleaseOnlyReachedOnes(): void {
        $this->row(11, 'tok-anna', self::NOW - 20);
        $this->release();

        $this->assertTrue($this->visible(11, 'tok-anna'));
        $this->assertFalse($this->visible(13, 'tok-anna'));
        $this->assertFalse($this->visible(11, 'tok-fremd'));
    }

    public function testModeratedUnchanged(): void {
        // Without a cookie as before: running question yes, next no, revealed yes.
        $paceState = $this->createMock(PaceStateService::class);
        $paceState->expects($this->never())->method($this->anything());
        $state = self::build(StateService::class, ['paceState' => $paceState]);
        $live = new Room();
        $live->setMode('quiz');
        $live->setActivePollId(11);
        $locked = new Poll();
        $locked->setId(12);
        $locked->setStatus('locked');

        $this->assertTrue($state->imageVisible($live, $this->polls[11]));
        $this->assertTrue($state->imageVisible($live, $this->polls[11], 'tok-anna'));
        $this->assertFalse($state->imageVisible($live, $this->polls[13], 'tok-anna'));
        $this->assertTrue($state->imageVisible($live, $locked));
    }

    private function visible(int $pollId, ?string $token): bool {
        $state = self::build(StateService::class, ['paceState' => $this->service]);
        return $state->imageVisible($this->room, $this->polls[$pollId], $token);
    }
}
