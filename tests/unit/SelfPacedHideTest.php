<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Service\ModerationService;
use OCA\Pulse\Service\PaceStateService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Free-text answers the moderator hid in a self-paced quiz
 * (ModerationService::hideable): the end view after the release leaves them
 * out of the answer list and the solution, the author still sees their own
 * answer with verdict and points, the answers CSV marks them "(hidden)",
 * and the standings do not change.
 */
#[CoversClass(PaceStateService::class)]
#[CoversClass(ModerationService::class)]
class SelfPacedHideTest extends PaceStateTestCase {

    /** Anna wrote "Secretword" (graded correct), Ben "Jupiter"; both reached question 13. */
    private function world(): void {
        $this->polls[13]->setAnswerKey(json_encode(['accepted' => ['Jupiter', 'Secretword'], 'rejected' => []]));
        foreach (['tok-anna' => 'Secretword', 'tok-ben' => 'Jupiter'] as $token => $answer) {
            $this->row(11, $token, self::NOW - 90, self::NOW - 80);
            $this->row(12, $token, self::NOW - 80, self::NOW - 70);
            $this->row(13, $token, self::NOW - 70, self::NOW - 60);
            $this->vote(13, $token, $answer, 800, true, self::NOW - 65);
        }
    }

    private function hide(string $key): void {
        $this->polls[13]->setModeration(['hidden' => [$key]]);
    }

    private function textItem(array $summary): array {
        foreach ($summary['items'] as $item) {
            if ($item['poll']['id'] === 13) {
                return $item;
            }
        }
        $this->fail('question 13 missing');
    }

    public function testEndViewLeavesTheHiddenAnswerOut(): void {
        $this->world();
        $this->release();
        $this->hide('secretword');

        $ben = $this->textItem($this->service->publicSummary($this->room, 'tok-ben'));
        $this->assertSame(['Jupiter'], array_column($ben['results']['answers'], 'sample'));
        $this->assertSame(2, $ben['results']['total'], 'total stays the number of people');
        $this->assertSame(['Jupiter'], $ben['poll']['answerKey']['accepted']);
        $this->assertStringNotContainsStringIgnoringCase('secretword', (string)json_encode($this->service->publicSummary($this->room, 'tok-ben')));

        // The author keeps their own answer with verdict and points.
        $anna = $this->textItem($this->service->publicSummary($this->room, 'tok-anna'));
        $this->assertSame('Secretword', $anna['mine']['value']);
        $this->assertTrue($anna['mine']['correct']);
        $this->assertSame(800, $anna['mine']['points']);

        // Shown again: back in the list.
        $this->polls[13]->setModeration(['shown' => ['secretword']]);
        $ben = $this->textItem($this->service->publicSummary($this->room, 'tok-ben'));
        $this->assertEqualsCanonicalizing(['Jupiter', 'Secretword'], array_column($ben['results']['answers'], 'sample'));
    }

    public function testHidingChangesTheEndViewVersion(): void {
        $this->world();
        $this->release();
        $before = $this->service->version($this->service->publicSummary($this->room, 'tok-ben'));
        $this->hide('secretword');
        $this->assertNotSame($before, $this->service->version($this->service->publicSummary($this->room, 'tok-ben')));
    }

    public function testHidingDoesNotChangeTheScore(): void {
        $this->world();
        $this->release();
        $before = $this->service->publicSummary($this->room, 'tok-anna');
        $this->hide('secretword');
        $after = $this->service->publicSummary($this->room, 'tok-anna');
        $this->assertSame($before['myScore'], $after['myScore']);
        $this->assertSame(self::column($before['leaderboard'], 'score'), self::column($after['leaderboard'], 'score'));
        $this->assertSame(['Anna' => 800, 'Ben' => 800, 'Cem' => 0], self::column($after['leaderboard'], 'score'));
    }

    public function testAnswersCsvMarksTheHiddenAnswer(): void {
        $this->world();
        $this->release();
        $plain = $this->service->exportCsv($this->room, 'answers');
        $players = $this->service->exportCsv($this->room, 'players');
        $this->hide('secretword');
        $marked = $this->service->exportCsv($this->room, 'answers');

        $this->assertStringContainsString('Secretword (hidden)', $marked);
        $this->assertStringNotContainsString('Jupiter (hidden)', $marked);
        $this->assertSame($plain, str_replace('"Secretword (hidden)"', 'Secretword', $marked), 'only the answer cell changes');
        $this->assertSame($players, $this->service->exportCsv($this->room, 'players'), 'scores in the players CSV stay');
    }
}
