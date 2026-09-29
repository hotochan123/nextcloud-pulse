<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\QuizService;
use OCA\Pulse\Service\VoteService;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Freitext nachbewerten (VoteService::gradeTextAnswer) im eigenen Tempo.
 *
 * Die Tempo-Punkte rechnen mit dem Limit, das beim Antworten galt (`limit` im
 * Payload — 0 ohne Timer, dann flach 1000), nicht mit dem der Frage. Bewertet
 * heißt: nicht mehr „wird geprüft", `pending` fällt weg. Moderierte Payloads
 * kennen weder `limit` noch `pending` und kommen bytegleich zum bisherigen
 * Ergebnis heraus. Im eigenen Tempo wird bewertet, während Leute antworten:
 * dort läuft das Bewerten als eine Transaktion unter der Raumsperre.
 */
#[CoversClass(VoteService::class)]
class SelfGradeTest extends TestCase {

    private VoteService $service;
    private Poll $poll;
    /** @var Vote[] */
    private array $votes = [];
    /** @var array<int, string> Payload je Stimmen-ID nach dem Bewerten */
    private array $saved = [];

    protected function setUp(): void {
        $this->poll = new Poll();
        $this->poll->setId(13);
        $this->poll->setRoomId(5);
        $this->poll->setType('text');
        $this->poll->setTimeLimit(20);
        $this->poll->setAnswerKey(json_encode(['accepted' => ['Jupiter'], 'rejected' => []]));

        $deck = $this->createMock(DeckService::class);
        $deck->method('requirePollInRoom')->willReturnCallback(fn (): Poll => $this->poll);

        $polls = $this->createMock(PollMapper::class);
        $polls->method('update')->willReturnArgument(0);

        $voteMapper = $this->createMock(VoteMapper::class);
        $voteMapper->method('findByPoll')->willReturnCallback(fn (): array => $this->votes);
        $voteMapper->method('update')->willReturnCallback(function (Vote $vote): Vote {
            $this->saved[$vote->getId()] = $vote->getPayload();
            return $vote;
        });

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        foreach ([
            'deckService' => $deck,
            'pollMapper' => $polls,
            'voteMapper' => $voteMapper,
            'quizService' => new QuizService(),
            'l10n' => $l10n,
        ] as $name => $value) {
            (new ReflectionProperty(VoteService::class, $name))->setValue($this->service, $value);
        }
    }

    public function testOhneTimerGibtEsFlachePunkte(): void {
        // Frage hat 20 s, gespeichert ist aber limit 0 (Raum ohne Timer).
        $this->votes = [$this->vote(1, ['value' => 'Saturn', 'points' => 0, 'correct' => false, 'elapsed' => 10, 'norm' => 'saturn', 'pending' => true, 'limit' => 0, 'fw' => 3])];

        $this->service->gradeTextAnswer($this->room(), 13, 'Saturn', true);

        $this->assertSame(
            ['value' => 'Saturn', 'points' => QuizService::BASE_POINTS, 'correct' => true, 'elapsed' => 10, 'norm' => 'saturn', 'limit' => 0, 'fw' => 3],
            json_decode($this->saved[1], true),
        );
    }

    public function testGespeichertesLimitStattDemDerFrage(): void {
        $this->poll->setTimeLimit(40);
        $this->votes = [$this->vote(1, ['value' => 'Saturn', 'points' => 0, 'correct' => false, 'elapsed' => 10, 'norm' => 'saturn', 'pending' => true, 'limit' => 20, 'fw' => 3])];

        $this->service->gradeTextAnswer($this->room(), 13, 'saturn', true);

        $this->assertSame((new QuizService())->points(10, 20), json_decode($this->saved[1], true)['points']);
    }

    public function testFalschBewertetEntferntPendingUndGibtNull(): void {
        $this->votes = [$this->vote(1, ['value' => 'Saturn', 'points' => 0, 'correct' => false, 'elapsed' => 10, 'norm' => 'saturn', 'pending' => true, 'limit' => 20, 'fw' => 3])];

        $this->service->gradeTextAnswer($this->room(), 13, 'Saturn', false);

        $saved = json_decode($this->saved[1], true);
        $this->assertArrayNotHasKey('pending', $saved);
        $this->assertFalse($saved['correct']);
        $this->assertSame(0, $saved['points']);
    }

    public function testKorrekturflagUndFensterBleiben(): void {
        // Bewerten ist keine Korrektur der Person: fixed/fw bleiben, created_at auch.
        $vote = $this->vote(1, ['value' => 'Saturn', 'points' => 0, 'correct' => false, 'elapsed' => 10, 'norm' => 'saturn', 'pending' => true, 'limit' => 20, 'fw' => 6, 'fixed' => true]);
        $vote->setCreatedAt(1234);
        $this->votes = [$vote];

        $this->service->gradeTextAnswer($this->room(), 13, 'Saturn', true);

        $saved = json_decode($this->saved[1], true);
        $this->assertSame(6, $saved['fw']);
        $this->assertTrue($saved['fixed']);
        $this->assertSame(1234, $vote->getCreatedAt());
    }

    public function testAndereNormalformBleibtUnberuehrt(): void {
        $this->votes = [
            $this->vote(1, ['value' => 'Saturn', 'points' => 0, 'correct' => false, 'elapsed' => 10, 'norm' => 'saturn', 'pending' => true, 'limit' => 20, 'fw' => 3]),
            $this->vote(2, ['value' => 'Venus', 'points' => 0, 'correct' => false, 'elapsed' => 4, 'norm' => 'venus', 'pending' => true, 'limit' => 20, 'fw' => 3]),
        ];

        $this->service->gradeTextAnswer($this->room(), 13, 'Saturn', true);

        $this->assertSame([1], array_keys($this->saved), 'Venus wartet weiter auf ihre Bewertung');
    }

    public function testModeriertePayloadBleibtBytegleich(): void {
        // So rechnete gradeTextAnswer vor dem eigenen Tempo: Punkte aus dem
        // Limit der Frage, sonst nichts angefasst.
        $old = ['value' => 'Saturn', 'points' => 0, 'correct' => false, 'elapsed' => 5, 'norm' => 'saturn'];
        $fixedOld = ['value' => 'saturn ', 'points' => 0, 'correct' => false, 'elapsed' => 9, 'norm' => 'saturn', 'fixed' => true];
        $this->votes = [$this->vote(1, $old), $this->vote(2, $fixedOld)];
        $quiz = new QuizService();
        $expected = [
            1 => json_encode(array_merge($old, ['correct' => true, 'points' => $quiz->points(5, 20)])),
            2 => json_encode(array_merge($fixedOld, ['correct' => true, 'points' => $quiz->points(9, 20)])),
        ];

        $this->service->gradeTextAnswer($this->room(), 13, 'Saturn', true);

        $this->assertSame($expected, $this->saved);
    }

    public function testModeriertFalschBleibtBytegleich(): void {
        $old = ['value' => 'Saturn', 'points' => 0, 'correct' => false, 'elapsed' => 5, 'norm' => 'saturn'];
        $this->votes = [$this->vote(1, $old)];

        $this->service->gradeTextAnswer($this->room(), 13, 'Saturn', false);

        $this->assertSame([1 => json_encode($old)], $this->saved);
    }

    public function testEigenesTempoBewertetUnterDerRaumsperre(): void {
        $fresh = $this->room();
        $fresh->setPace('self');
        $room = $this->room();
        $room->setPace('self');
        $this->votes = [$this->vote(1, ['value' => 'Saturn', 'points' => 0, 'correct' => false, 'elapsed' => 10, 'norm' => 'saturn', 'pending' => true, 'limit' => 20, 'fw' => 3])];
        $inLock = false;
        $pace = $this->createMock(PaceService::class);
        $pace->expects($this->once())->method('locked')->with($this->identicalTo($room))
            ->willReturnCallback(function (Room $r, callable $fn) use ($fresh, &$inLock): mixed {
                $inLock = true;
                $out = $fn($fresh);
                $inLock = false;
                return $out;
            });
        (new ReflectionProperty(VoteService::class, 'paceService'))->setValue($this->service, $pace);
        $deck = $this->createMock(DeckService::class);
        $deck->expects($this->once())->method('requirePollInRoom')->with($this->identicalTo($fresh), 13)
            ->willReturnCallback(function () use (&$inLock): Poll {
                $this->assertTrue($inLock, 'Frage erst unter der Sperre gelesen');
                return $this->poll;
            });
        (new ReflectionProperty(VoteService::class, 'deckService'))->setValue($this->service, $deck);

        $this->service->gradeTextAnswer($room, 13, 'Saturn', true);

        $this->assertTrue(json_decode($this->saved[1], true)['correct']);
    }

    public function testModeriertOhneRaumsperre(): void {
        $pace = $this->createMock(PaceService::class);
        $pace->expects($this->never())->method($this->anything());
        (new ReflectionProperty(VoteService::class, 'paceService'))->setValue($this->service, $pace);
        $this->votes = [$this->vote(1, ['value' => 'Saturn', 'points' => 0, 'correct' => false, 'elapsed' => 5, 'norm' => 'saturn'])];

        $this->service->gradeTextAnswer($this->room(), 13, 'Saturn', true);

        $this->assertArrayHasKey(1, $this->saved);
    }

    private function room(): Room {
        $room = new Room();
        $room->setId(5);
        $room->setMode('quiz');
        return $room;
    }

    private function vote(int $id, array $payload): Vote {
        $vote = new Vote();
        $vote->setId($id);
        $vote->setPollId(13);
        $vote->setVoterToken('tok-' . $id);
        $vote->setPayload(json_encode($payload));
        return $vote;
    }
}
