<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Service\PaceStateService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Progress for the moderator (PaceStateService::progress).
 *
 * The laptop is often connected to the projector: with feedback "At the end", points,
 * hits and leaderboard stay hidden until the release, unless the
 * explicit switch is on. `skipped` exposes throwaway players who only
 * click through; `online` uses the same 15 s window as "N here".
 */
#[CoversClass(PaceStateService::class)]
class SelfProgressTest extends PaceStateTestCase {

    public function testRueckmeldungAmEndeVerdecktPunkte(): void {
        $this->room->setFeedback('end');
        $this->race();

        $data = $this->service->progress($this->room);

        $this->assertTrue($data['scoresHidden']);
        $this->assertNull($data['leaderboard']);
        foreach ($data['players'] as $p) {
            $this->assertNull($p['correct'], $p['nickname']);
            $this->assertNull($p['score'], $p['nickname']);
        }
        foreach ($data['questions'] as $q) {
            $this->assertNull($q['correct']);
        }
        $this->assertSame(2, $data['players'][0]['answered'], 'Beantwortet bleibt sichtbar');
    }

    public function testSchalterZeigtPunkte(): void {
        $this->room->setFeedback('end');
        $this->race();

        $data = $this->service->progress($this->room, true);

        $this->assertFalse($data['scoresHidden']);
        $this->assertSame(['Anna' => 900, 'Ben' => 950, 'Cem' => 0], array_column($data['players'], 'score', 'nickname'));
        $this->assertSame(['Anna' => 1, 'Ben' => 1, 'Cem' => 0], array_column($data['players'], 'correct', 'nickname'));
        $this->assertSame([2, 0, 0], array_column($data['questions'], 'correct'));
        $this->assertSame(['Ben' => 950, 'Anna' => 900, 'Cem' => 0], self::column($data['leaderboard'], 'score'));
    }

    public function testNachDerFreigabeImmerSichtbar(): void {
        $this->room->setFeedback('end');
        $this->race();
        $this->release();

        $data = $this->service->progress($this->room);

        $this->assertFalse($data['scoresHidden']);
        $this->assertNotNull($data['leaderboard']);
        $this->assertSame(900, $data['players'][0]['score']);
    }

    public function testNurEndgueltigeStimmenZaehlen(): void {
        $this->race();
        // Cem has just answered correctly: still inside the correction window.
        $this->vote(11, 'tok-cem', 'AA', 990, true, self::NOW - 1);

        $data = $this->service->progress($this->room);

        $this->assertSame(0, array_column($data['players'], 'score', 'nickname')['Cem']);
        $this->assertSame(1, array_column($data['players'], 'answered', 'nickname')['Cem']);
        $this->assertSame(2, $data['questions'][0]['correct'], 'Cems Treffer noch nicht');
        $this->assertSame(3, $data['questions'][0]['answered']);
    }

    public function testUebersprungenZaehltVerlasseneOhneStimme(): void {
        $this->race();
        // Throwaway player clicks through without answering.
        $this->players[] = $this->player(34, 'tok-dora', 'Dora');
        $this->row(11, 'tok-dora', self::NOW - 90, self::NOW - 60);
        $this->row(12, 'tok-dora', self::NOW - 60, self::NOW - 30);
        $this->row(13, 'tok-dora', self::NOW - 30);

        $players = array_column($this->service->progress($this->room)['players'], null, 'nickname');

        $this->assertSame(2, $players['Dora']['skipped'], 'die offene zählt nicht');
        $this->assertSame(0, $players['Anna']['skipped']);
        $this->assertSame(1, $players['Ben']['skipped'], 'Q2 ohne Antwort verlassen');
    }

    public function testOnlineAusDemPraesenzFenster(): void {
        $this->race();
        $this->seen = ['tok-anna' => self::NOW - 15, 'tok-ben' => self::NOW - 16];

        $players = array_column($this->service->progress($this->room)['players'], null, 'nickname');

        $this->assertTrue($players['Anna']['online']);
        $this->assertSame(self::NOW - 15, $players['Anna']['lastSeen']);
        $this->assertFalse($players['Ben']['online']);
        $this->assertFalse($players['Cem']['online'], 'nie gesehen');
        $this->assertSame(0, $players['Cem']['lastSeen']);
    }

    public function testSpielerzeilen(): void {
        $this->race();

        $data = $this->service->progress($this->room);
        $anna = $data['players'][0];

        $this->assertSame(['Anna', 'Ben', 'Cem'], array_column($data['players'], 'nickname'), 'nach Name');
        $this->assertSame(31, $anna['id']);
        $this->assertSame(2, $anna['k']);
        $this->assertTrue($anna['started']);
        $this->assertFalse($anna['finished']);
        $this->assertSame(12, $anna['currentPollId']);
        $this->assertSame(self::NOW - 40, $anna['currentStartedAt']);
        $this->assertSame(self::NOW - 60, $anna['startedAt']);
        $this->assertSame(self::NOW - 20, $anna['lastActivity'], 'die Antwort auf Q2 ist das Jüngste');
        $this->assertArrayNotHasKey('token', $anna);
        $this->assertStringNotContainsString('tok-', json_encode($data), 'kein Token verlässt den Server');
    }

    public function testFragenzeilenUndOffeneFreitexte(): void {
        $this->race();
        $this->row(13, 'tok-ben', self::NOW - 10);
        $this->vote(13, 'tok-ben', 'Saturn', 0, false, self::NOW - 8, pending: true);
        $this->players[] = $this->player(34, 'tok-dora', 'Dora');
        $this->row(11, 'tok-dora', self::NOW - 90, self::NOW - 60);
        $this->row(12, 'tok-dora', self::NOW - 60, self::NOW - 30);
        $this->row(13, 'tok-dora', self::NOW - 30);
        $this->vote(13, 'tok-dora', ' saturn ', 0, false, self::NOW - 25, pending: true);

        $data = $this->service->progress($this->room);

        $this->assertSame(3, $data['n']);
        $this->assertSame(
            ['pollId' => 13, 'k' => 3, 'type' => 'text', 'question' => 'Größter Planet?', 'reached' => 2, 'answered' => 2, 'correct' => 0, 'pending' => 2],
            $data['questions'][2],
        );
        $this->assertSame([['pollId' => 13, 'k' => 3, 'answer' => 'Saturn', 'count' => 2]], $data['pendingAnswers'], 'erste Schreibweise, keine Lösung');
    }

    public function testProbelaufRanglisteLeer(): void {
        $this->room->setPractice(true);
        $this->race();

        $this->assertSame([], $this->service->progress($this->room)['leaderboard']);
    }

    /**
     * Anna: Q1 correct (left), answered Q2 (wrong, final).
     * Ben: Q1 correct (left), left Q2 without answering, no Q3.
     * Cem: is on Q1.
     */
    private function race(): void {
        $this->row(11, 'tok-anna', self::NOW - 60, self::NOW - 40);
        $this->vote(11, 'tok-anna', 'AA', 900, true, self::NOW - 50);
        $this->row(12, 'tok-anna', self::NOW - 40);
        $this->vote(12, 'tok-anna', ['SA02', 'JU01', 'NE03'], 0, false, self::NOW - 20);
        $this->row(11, 'tok-ben', self::NOW - 60, self::NOW - 50);
        $this->vote(11, 'tok-ben', 'AA', 950, true, self::NOW - 55);
        $this->row(12, 'tok-ben', self::NOW - 50, self::NOW - 10);
        $this->row(11, 'tok-cem', self::NOW - 5);
    }
}
