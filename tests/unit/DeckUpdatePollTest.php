<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\CodeGenerator;
use OCA\Pulse\Service\DeckService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Frage bearbeiten (DeckService::updatePoll) und ihr Ablauf-Stand.
 *
 * Neuer Inhalt, Stimmen weg — dann gilt der alte Stand auch nicht mehr. Eine
 * nicht laufende Frage zählt danach als „nie gezeigt" ('active', Startzeit 0),
 * sonst stünde die geänderte Frage samt Lösung sofort als aufgelöst in der
 * Gesamtauswertung. Ausnahmen, damit ein Tippfehler-Fix im Saal nichts umwirft:
 * 'ended' bleibt immer (das Quiz ist vorbei), und die laufende Frage bleibt,
 * wie sie ist — nur ein offener Quiz-Timer startet neu. Gesperrt bleibt
 * gesperrt, in beiden Modi.
 */
#[CoversClass(DeckService::class)]
class DeckUpdatePollTest extends TestCase {

    private PollMapper&MockObject $polls;
    private VoteMapper&MockObject $votes;
    private DeckService $service;
    private Poll $poll;

    protected function setUp(): void {
        $this->poll = new Poll();
        $this->poll->setId(7);
        $this->poll->setRoomId(1);
        $this->poll->setType('choice');

        $this->polls = $this->createMock(PollMapper::class);
        $this->polls->method('find')->willReturnCallback(fn (): Poll => $this->poll);
        $this->polls->method('update')->willReturnArgument(0);

        $this->votes = $this->createMock(VoteMapper::class);

        $ids = ['OPT1', 'OPT2', 'OPT3', 'OPT4'];
        $codes = $this->createMock(CodeGenerator::class);
        $codes->method('optionId')->willReturnCallback(static function () use (&$ids): string {
            return array_shift($ids) ?? 'OPTX';
        });

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(5000);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->service = (new \ReflectionClass(DeckService::class))->newInstanceWithoutConstructor();
        foreach ([
            'pollMapper' => $this->polls,
            'voteMapper' => $this->votes,
            'codeGenerator' => $codes,
            'timeFactory' => $time,
            'l10n' => $l10n,
        ] as $name => $value) {
            (new ReflectionProperty(DeckService::class, $name))->setValue($this->service, $value);
        }
    }

    public function testNichtLaufendeQuizfrageGiltDanachAlsNieGezeigt(): void {
        $this->given('locked', 1234);
        $this->votes->expects($this->once())->method('deleteByPoll')->with(7);

        $this->service->updatePoll($this->room('quiz', active: 3), 7, $this->quizData());

        $this->assertSame('active', $this->poll->getStatus());
        $this->assertSame(0, $this->poll->getStartedAt());
    }

    public function testNichtLaufendeBeendeteFrageBehaeltDasEnde(): void {
        // Nach /end und „Zurück ins Deck": ein Tippfehler-Fix an der beendeten
        // Frage darf das Quiz nicht wieder aufmachen. Die Stimmen gehen
        // trotzdem — der Inhalt ist neu.
        $this->given('ended', 1234);
        $this->votes->expects($this->once())->method('deleteByPoll')->with(7);

        $this->service->updatePoll($this->room('quiz', active: 0), 7, $this->quizData());

        $this->assertSame('ended', $this->poll->getStatus());
        $this->assertSame(1234, $this->poll->getStartedAt());
    }

    public function testNichtLaufendeUmfragefrageGiltDanachAlsNieGezeigt(): void {
        $this->given('locked', 1234);

        $this->service->updatePoll($this->room('poll', active: 3), 7, $this->pollData());

        $this->assertSame('active', $this->poll->getStatus());
        $this->assertSame(0, $this->poll->getStartedAt());
    }

    public function testLaufendeOffeneQuizfrageStartetNeu(): void {
        // Die Stimmen sind weg — wer schon geantwortet hatte, bekommt die
        // volle Zeit noch einmal.
        $this->given('active', 1234);

        $this->service->updatePoll($this->room('quiz', active: 7), 7, $this->quizData());

        $this->assertSame('active', $this->poll->getStatus());
        $this->assertSame(5000, $this->poll->getStartedAt(), 'Timer neu gestartet');
    }

    public function testLaufendeGesperrteQuizfrageBleibtGesperrt(): void {
        // Gesperrt heißt im Quiz: Antworten zu, ggf. schon aufgelöst. Das
        // Bearbeiten öffnet sie nicht wieder und startet keinen Timer.
        $this->given('locked', 1234);

        $this->service->updatePoll($this->room('quiz', active: 7), 7, $this->quizData());

        $this->assertSame('locked', $this->poll->getStatus());
        $this->assertSame(1234, $this->poll->getStartedAt(), 'Startzeit unverändert');
    }

    public function testLaufendeBeendeteQuizfrageBleibtBeendet(): void {
        // /end steht auf der laufenden Frage — der Endstand bleibt stehen.
        $this->given('ended', 1234);

        $this->service->updatePoll($this->room('quiz', active: 7), 7, $this->quizData());

        $this->assertSame('ended', $this->poll->getStatus());
        $this->assertSame(1234, $this->poll->getStartedAt(), 'Startzeit unverändert');
    }

    public function testLaufendeUmfragefrageBehaeltIhrenStatus(): void {
        // Pausiert bleibt pausiert; der erste Zeigezeitpunkt bleibt ebenfalls.
        $this->given('locked', 1234);

        $this->service->updatePoll($this->room('poll', active: 7), 7, $this->pollData());

        $this->assertSame('locked', $this->poll->getStatus());
        $this->assertSame(1234, $this->poll->getStartedAt());
    }

    public function testLaufendeOffeneUmfragefrageBleibtOffen(): void {
        $this->given('active', 1234);

        $this->service->updatePoll($this->room('poll', active: 7), 7, $this->pollData());

        $this->assertSame('active', $this->poll->getStatus());
        $this->assertSame(1234, $this->poll->getStartedAt());
    }

    public function testStandWirdMitGespeichert(): void {
        // Der Reset muss VOR dem update() passieren, sonst landet er nie in der DB.
        $this->given('locked', 1234);
        $this->polls = $this->createMock(PollMapper::class);
        $this->polls->method('find')->willReturnCallback(fn (): Poll => $this->poll);
        $this->polls->expects($this->once())->method('update')
            ->with($this->callback(static fn (Poll $p): bool => $p->getStatus() === 'active' && $p->getStartedAt() === 0))
            ->willReturnArgument(0);
        (new ReflectionProperty(DeckService::class, 'pollMapper'))->setValue($this->service, $this->polls);

        $this->service->updatePoll($this->room('quiz', active: 3), 7, $this->quizData());
    }

    public function testUngueltigeEingabeAendertNichts(): void {
        $this->given('locked', 1234);
        $this->polls = $this->createMock(PollMapper::class);
        $this->polls->method('find')->willReturnCallback(fn (): Poll => $this->poll);
        $this->polls->expects($this->never())->method('update');
        (new ReflectionProperty(DeckService::class, 'pollMapper'))->setValue($this->service, $this->polls);
        $this->votes->expects($this->never())->method('deleteByPoll');

        try {
            $this->service->updatePoll($this->room('quiz', active: 3), 7, ['type' => 'choice', 'question' => '']);
            $this->fail('leere Frage muss abgelehnt werden');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame('locked', $this->poll->getStatus());
        $this->assertSame(1234, $this->poll->getStartedAt());
    }

    private function given(string $status, int $startedAt): void {
        $this->poll->setStatus($status);
        $this->poll->setStartedAt($startedAt);
    }

    private function quizData(): array {
        return ['type' => 'choice', 'question' => 'Hauptstadt?', 'options' => ['Berlin', 'Bonn'], 'correctIndex' => 0, 'timeLimit' => 20];
    }

    private function pollData(): array {
        return ['type' => 'choice', 'question' => 'Kaffee oder Tee?', 'options' => ['Kaffee', 'Tee']];
    }

    private function room(string $mode, int $active): Room {
        $room = new Room();
        $room->setId(1);
        $room->setMode($mode);
        $room->setActivePollId($active);
        return $room;
    }
}
