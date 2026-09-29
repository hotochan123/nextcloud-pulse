<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\PresenceMapper;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\CodeGenerator;
use OCA\Pulse\Service\DemoService;
use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\RoomService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Zurücksetzen, Kopieren, Löschen und Demo-Räumen kennen das eigene Tempo:
 * Fortschritt, Fenster, eingefrorene Reihenfolge und Beitrittssperre gehen mit.
 *
 * Genauso wichtig ist die Gegenrichtung: ein moderierter Raum bekommt keine
 * zusätzliche Schreiboperation auf die Raumzeile — Reset schreibt sie nur beim
 * laufenden Cursor (und dann nur active_poll_id), die Kopie nur bei
 * „Auflösung am Ende", genau wie vorher.
 */
#[CoversClass(RoomService::class)]
#[CoversClass(DemoService::class)]
class RoomPaceLifecycleTest extends TestCase {

    private const NOW = 1_800_000_000;

    private RoomService $service;
    private RoomMapper&MockObject $rooms;
    private ProgressMapper&MockObject $progress;

    protected function setUp(): void {
        $this->rooms = $this->createMock(RoomMapper::class);
        $this->rooms->method('insert')->willReturnCallback(function (Room $room): Room {
            $room->setId(99);
            return $room;
        });
        $this->progress = $this->createMock(ProgressMapper::class);

        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturn([]);

        $codes = $this->createMock(CodeGenerator::class);
        $codes->method('uniqueRoomCode')->willReturn('NEWCODE');

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->service = (new \ReflectionClass(RoomService::class))->newInstanceWithoutConstructor();
        foreach ([
            'roomMapper' => $this->rooms,
            'pollMapper' => $polls,
            'voteMapper' => $this->createMock(VoteMapper::class),
            'presenceMapper' => $this->createMock(PresenceMapper::class),
            'playerMapper' => $this->createMock(PlayerMapper::class),
            'progressMapper' => $this->progress,
            'codeGenerator' => $codes,
            'imageService' => $this->createMock(PollImageService::class),
            'timeFactory' => $time,
            'l10n' => $l10n,
        ] as $name => $value) {
            (new ReflectionProperty(RoomService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── resetRoom ──────────────────────────────────────────────────────────

    public function testResetModeriertOhneCursorSchreibtDenRaumNicht(): void {
        $room = $this->room();
        $this->rooms->expects($this->never())->method('update');
        $this->progress->expects($this->once())->method('deleteByRoom')->with(5);

        $this->service->resetRoom($room);
    }

    public function testResetModeriertMitCursorSchreibtNurDenCursor(): void {
        $room = $this->room();
        $room->setActivePollId(7);
        $room->resetUpdatedFields();

        $this->rooms->expects($this->once())->method('update')->willReturnCallback(function (Room $r): Room {
            // Dasselbe UPDATE wie vor dem eigenen Tempo: nur active_poll_id.
            $this->assertSame(['activePollId' => true], $r->getUpdatedFields());
            return $r;
        });

        $this->service->resetRoom($room);
        $this->assertSame(0, $room->getActivePollId());
    }

    public function testResetImEigenenTempoLeertFensterUndBehaeltFormat(): void {
        $room = $this->room();
        $room->setPace('self');
        $room->setTimed(false);
        $room->setFeedback('end');
        $room->setOpenedAt(self::NOW - 500);
        $room->setClosesAt(self::NOW + 3600);
        $room->setClosedAt(self::NOW - 10);
        $room->setReleasedAt(self::NOW - 10);
        $room->setDeckOrder('[3,1,2]');
        $room->setJoinsLocked(true);
        $room->setTouchedAt(self::NOW - 100);
        $room->resetUpdatedFields();

        $this->rooms->expects($this->once())->method('update')->willReturnArgument(0);
        $this->progress->expects($this->once())->method('deleteByRoom')->with(5);

        $this->service->resetRoom($room);

        $this->assertSame(0, $room->getOpenedAt());
        $this->assertSame(0, $room->getClosesAt());
        $this->assertSame(0, $room->getClosedAt());
        $this->assertSame(0, $room->getReleasedAt());
        $this->assertNull($room->getDeckOrder());
        $this->assertFalse($room->getJoinsLocked());
        // Format des Durchgangs und Aufbewahrung bleiben.
        $this->assertSame('self', $room->getPace());
        $this->assertFalse($room->getTimed());
        $this->assertSame('end', $room->getFeedback());
        $this->assertSame(self::NOW - 100, $room->getTouchedAt());
    }

    public function testResetImEntwurfOhneFensterSchreibtDenRaumNicht(): void {
        $room = $this->room();
        $room->setPace('self');
        $room->setTouchedAt(self::NOW - 100);
        $room->resetUpdatedFields();

        $this->rooms->expects($this->never())->method('update');

        $this->service->resetRoom($room);
    }

    public function testResetHebtAuchEineAlleinigeBeitrittssperreAuf(): void {
        $room = $this->room();
        $room->setPace('self');
        $room->setJoinsLocked(true);
        $room->resetUpdatedFields();

        $this->rooms->expects($this->once())->method('update')->willReturnArgument(0);

        $this->service->resetRoom($room);
        $this->assertFalse($room->getJoinsLocked());
    }

    // ── duplicateRoom ──────────────────────────────────────────────────────

    public function testKopieEinesModeriertenRaumsOhneExtraUpdate(): void {
        $this->rooms->expects($this->never())->method('update');

        $copy = $this->service->duplicateRoom($this->room(), 'alice');

        $this->assertSame('live', $copy->getPace());
    }

    public function testKopieMitAufloesungAmEndeSchreibtWieBisherEinmal(): void {
        $src = $this->room();
        $src->setRevealAtEnd(true);
        $this->rooms->expects($this->once())->method('update')->willReturnArgument(0);

        $copy = $this->service->duplicateRoom($src, 'alice');

        $this->assertTrue($copy->getRevealAtEnd());
        $this->assertSame('live', $copy->getPace());
    }

    public function testKopieImEigenenTempoNimmtNurDasFormatMit(): void {
        $src = $this->room();
        $src->setPace('self');
        $src->setTimed(false);
        $src->setFeedback('end');
        $src->setOpenedAt(self::NOW - 500);
        $src->setClosesAt(self::NOW + 3600);
        $src->setClosedAt(self::NOW - 10);
        $src->setReleasedAt(self::NOW - 10);
        $src->setDeckOrder('[3,1,2]');
        $src->setJoinsLocked(true);
        $src->setTouchedAt(self::NOW - 100);

        $this->rooms->expects($this->once())->method('update')->willReturnArgument(0);

        $copy = $this->service->duplicateRoom($src, 'alice');

        $this->assertSame('self', $copy->getPace());
        $this->assertFalse($copy->getTimed());
        $this->assertSame('end', $copy->getFeedback());
        // Die Kopie ist ein frischer Entwurf.
        $this->assertSame(0, $copy->getOpenedAt());
        $this->assertSame(0, $copy->getClosesAt());
        $this->assertSame(0, $copy->getClosedAt());
        $this->assertSame(0, $copy->getReleasedAt());
        $this->assertNull($copy->getDeckOrder());
        $this->assertFalse($copy->getJoinsLocked());
        $this->assertSame(0, $copy->getTouchedAt());
    }

    // ── deleteRoom ─────────────────────────────────────────────────────────

    public function testLoeschenNimmtDenFortschrittMit(): void {
        $room = $this->room();
        $this->progress->expects($this->once())->method('deleteByRoom')->with(5);
        $this->rooms->expects($this->once())->method('delete')->with($room)->willReturnArgument(0);

        $this->service->deleteRoom($room);
    }

    public function testLoeschenInDerReihenfolgeGegenWettlaeufe(): void {
        // Raumzeile zuerst (ein self-Beitritt unter der Raumsperre wird erst
        // fertig und dann mitgelöscht, ein späterer bekommt 404), Spieler vor
        // Stimmen und Fortschritt (/vote und /next prüfen danach sperrend nach,
        // s. PaceService::assertStillJoined), Bilddateien zuletzt.
        $calls = [];
        $log = function (string $what) use (&$calls): \Closure {
            return function (...$args) use ($what, &$calls) {
                $calls[] = $what;
                return $args[0] ?? null;
            };
        };
        $poll = new Poll();
        $poll->setId(41);
        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturnCallback(function () use (&$calls, $poll): array {
            $calls[] = 'findPolls';
            return [$poll];
        });
        $polls->method('delete')->willReturnCallback($log('poll'));
        $votes = $this->createMock(VoteMapper::class);
        $votes->method('deleteByPoll')->willReturnCallback($log('votes'));
        $presence = $this->createMock(PresenceMapper::class);
        $presence->method('deleteByRoom')->willReturnCallback($log('presence'));
        $players = $this->createMock(PlayerMapper::class);
        $players->method('deleteByRoom')->willReturnCallback($log('players'));
        $images = $this->createMock(PollImageService::class);
        $images->method('discard')->willReturnCallback($log('image'));
        $this->progress->method('deleteByRoom')->willReturnCallback($log('progress'));
        $this->rooms->method('delete')->willReturnCallback($log('room'));
        foreach (['pollMapper' => $polls, 'voteMapper' => $votes, 'presenceMapper' => $presence, 'playerMapper' => $players, 'imageService' => $images] as $name => $value) {
            (new ReflectionProperty(RoomService::class, $name))->setValue($this->service, $value);
        }

        $this->service->deleteRoom($this->room());

        $this->assertSame(['room', 'presence', 'players', 'findPolls', 'votes', 'poll', 'progress', 'image'], $calls);
    }

    // ── DemoService::clearDemoVotes ────────────────────────────────────────

    public function testDemoRaeumenNimmtDenDemoFortschrittMit(): void {
        $poll = new Poll();
        $poll->setId(41);
        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturn([$poll]);
        $votes = $this->createMock(VoteMapper::class);
        $votes->expects($this->once())->method('deleteDemoByPoll')->with(41)->willReturn(3);
        $players = $this->createMock(PlayerMapper::class);
        $players->expects($this->once())->method('deleteDemoByRoom')->with(5);
        $this->progress->expects($this->once())->method('deleteDemoByRoom')->with(5);
        $this->progress->expects($this->never())->method('deleteByRoom');

        $demo = (new \ReflectionClass(DemoService::class))->newInstanceWithoutConstructor();
        foreach ([
            'pollMapper' => $polls,
            'voteMapper' => $votes,
            'playerMapper' => $players,
            'progressMapper' => $this->progress,
        ] as $name => $value) {
            (new ReflectionProperty(DemoService::class, $name))->setValue($demo, $value);
        }

        $this->assertSame(['removed' => 3], $demo->clearDemoVotes($this->room()));
    }

    // ── Helfer ─────────────────────────────────────────────────────────────

    private function room(): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('ABCDEF');
        $room->setTitle('');
        $room->setOwnerUid('alice');
        $room->setMode('quiz');
        $room->setCreatedAt(self::NOW - 1000);
        return $room;
    }
}
