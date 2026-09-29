<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Service\DeckService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Cursor setzen (DeckService::setCurrent) und der Startzeitpunkt.
 *
 * Quiz: jeder Sprung auf eine Frage startet den Timer neu und öffnet sie.
 * Umfrage: kein Timer — aber der ERSTE Sprung vermerkt „wurde gezeigt", damit
 * die öffentliche Gesamtauswertung nie gezeigte Fragen weglassen kann
 * (StateService::wasShown). Spätere Sprünge lassen den Zeitpunkt stehen.
 */
#[CoversClass(DeckService::class)]
class DeckSetCurrentTest extends TestCase {

    private PollMapper&MockObject $polls;
    private RoomMapper&MockObject $rooms;
    private DeckService $service;
    private Poll $poll;

    protected function setUp(): void {
        $this->poll = new Poll();
        $this->poll->setId(7);
        $this->poll->setRoomId(1);
        $this->poll->setType('choice');

        $this->polls = $this->createMock(PollMapper::class);
        $this->polls->method('find')->willReturnCallback(fn (): Poll => $this->poll);
        $this->rooms = $this->createMock(RoomMapper::class);
        $this->rooms->method('update')->willReturnArgument(0);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(5000);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->service = (new \ReflectionClass(DeckService::class))->newInstanceWithoutConstructor();
        foreach ([
            'pollMapper' => $this->polls,
            'roomMapper' => $this->rooms,
            'timeFactory' => $time,
            'l10n' => $l10n,
        ] as $name => $value) {
            (new ReflectionProperty(DeckService::class, $name))->setValue($this->service, $value);
        }
    }

    public function testUmfrageErsterSprungSetztStartzeitpunkt(): void {
        $this->given('active', 0);
        $this->polls->expects($this->once())->method('update')->willReturnArgument(0);

        $room = $this->room('poll');
        $this->service->setCurrent($room, 7);

        $this->assertSame(5000, $this->poll->getStartedAt());
        $this->assertSame('active', $this->poll->getStatus());
        $this->assertSame(7, $room->getActivePollId());
    }

    public function testUmfrageSpaetererSprungLaesstStartzeitpunktStehen(): void {
        $this->given('locked', 1234);
        $this->polls->expects($this->never())->method('update');

        $this->service->setCurrent($this->room('poll'), 7);

        $this->assertSame(1234, $this->poll->getStartedAt(), 'erster Zeigezeitpunkt bleibt');
        $this->assertSame('locked', $this->poll->getStatus(), 'Umfrage öffnet eine gesperrte Frage nicht');
    }

    public function testQuizStartetTimerJedesMalNeuUndOeffnet(): void {
        $this->given('locked', 1234);
        $this->polls->expects($this->once())->method('update')->willReturnArgument(0);

        $this->service->setCurrent($this->room('quiz'), 7);

        $this->assertSame(5000, $this->poll->getStartedAt());
        $this->assertSame('active', $this->poll->getStatus());
    }

    public function testQuizErsterSprungSetztEbenfallsStartzeitpunkt(): void {
        $this->given('active', 0);
        $this->polls->expects($this->once())->method('update')->willReturnArgument(0);

        $this->service->setCurrent($this->room('quiz'), 7);

        $this->assertSame(5000, $this->poll->getStartedAt());
    }

    public function testCursorAufNullFasstKeineFrageAn(): void {
        $this->polls->expects($this->never())->method('find');
        $this->polls->expects($this->never())->method('update');
        $this->rooms->expects($this->once())->method('update');

        $room = $this->room('poll');
        $room->setActivePollId(7);
        $this->service->setCurrent($room, 0);

        $this->assertSame(0, $room->getActivePollId());
    }

    // ── Neuer Lauf beendet das alte Ende ───────────────────────────────────

    public function testQuizSprungNimmtAltesEndeZurueck(): void {
        // Ein zweiter Lauf (oder ein Schritt zurück nach dem Endstand): jede
        // ANDERE 'ended'-Frage wird wieder 'locked' — sonst hieße es weiter
        // „Quiz vorbei" und /summary gäbe ab Frage 1 alle Lösungen frei.
        $this->given('ended', 1234);
        $this->polls->method('update')->willReturnArgument(0);
        $this->polls->expects($this->once())->method('unmarkEnded')->with(1, 7);

        $this->service->setCurrent($this->room('quiz'), 7);

        $this->assertSame('active', $this->poll->getStatus(), 'die Zielfrage selbst läuft wieder');
    }

    public function testQuizNimmtEndeErstNachDemOeffnenZurueck(): void {
        // Reihenfolge: erst die Zielfrage öffnen, dann die übrigen zurücksetzen —
        // die Zielfrage ist dabei ausgenommen und steht schon auf 'active'.
        $this->given('active', 0);
        $calls = [];
        $this->polls->method('update')->willReturnCallback(function (Poll $p) use (&$calls): Poll {
            $calls[] = 'update:' . $p->getStatus();
            return $p;
        });
        $this->polls->method('unmarkEnded')->willReturnCallback(function () use (&$calls): void {
            $calls[] = 'unmarkEnded';
        });

        $this->service->setCurrent($this->room('quiz'), 7);

        $this->assertSame(['update:active', 'unmarkEnded'], $calls);
    }

    public function testUmfrageFasstEndeNichtAn(): void {
        $this->given('active', 0);
        $this->polls->method('update')->willReturnArgument(0);
        $this->polls->expects($this->never())->method('unmarkEnded');

        $this->service->setCurrent($this->room('poll'), 7);
    }

    public function testQuizCursorAufNullBehaeltDasEnde(): void {
        // „Zurück ins Deck" nach /end: der Endstand soll stehen bleiben.
        $this->polls->expects($this->never())->method('unmarkEnded');

        $room = $this->room('quiz');
        $room->setActivePollId(7);
        $this->service->setCurrent($room, 0);

        $this->assertSame(0, $room->getActivePollId());
    }

    public function testFrageAusFremdemRaumWirdAbgelehnt(): void {
        $this->poll->setRoomId(99);
        $this->polls->expects($this->never())->method('update');
        $this->rooms->expects($this->never())->method('update');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->setCurrent($this->room('poll'), 7);
    }

    private function given(string $status, int $startedAt): void {
        $this->poll->setStatus($status);
        $this->poll->setStartedAt($startedAt);
    }

    private function room(string $mode): Room {
        $room = new Room();
        $room->setId(1);
        $room->setMode($mode);
        return $room;
    }
}
