<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\PresenceMapper;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\RoomGoneException;
use OCA\Pulse\Service\RoomService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Races with removal and deletion: /vote and /next do not lock the room.
 * If removePlayer (or deleting the room) commits between their player check
 * and the write, a vote or row would be left without a player; it would count
 * in progress and tallying, and the cookie would inherit it on rejoining.
 * assertStillJoined afterwards re-reads the player under a lock and cleans up;
 * locked() turns a deleted room into RoomGoneException (404) instead of
 * a 500.
 */
#[CoversClass(PaceService::class)]
class PaceStillJoinedTest extends TestCase {

    private const NOW = 1_800_000_000;
    private const TOK = 'tok-anna';

    private PaceService $service;
    private RoomMapper&MockObject $rooms;
    private PlayerMapper&MockObject $players;
    /** @var list<string> Delete calls */
    private array $calls = [];
    /** @var list<string> Transaction sequence */
    private array $tx = [];
    /** @var list<bool> Answers from existsForUpdate, in order */
    private array $joined = [];

    protected function setUp(): void {
        $this->rooms = $this->createMock(RoomMapper::class);

        $this->players = $this->createMock(PlayerMapper::class);
        $this->players->method('existsForUpdate')->willReturnCallback(fn (): bool => array_shift($this->joined) ?? false);

        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturn([]);

        $votes = $this->createMock(VoteMapper::class);
        $votes->method('deleteByPollsAndToken')->willReturnCallback(function (array $ids, string $token): void {
            $this->calls[] = 'votes:' . implode(',', $ids) . ':' . $token;
        });
        $progress = $this->createMock(ProgressMapper::class);
        $progress->method('deleteByRoomAndToken')->willReturnCallback(function (int $roomId, string $token): void {
            $this->calls[] = 'progress:' . $roomId . ':' . $token;
        });
        $presence = $this->createMock(PresenceMapper::class);
        $presence->expects($this->never())->method('deleteByRoomAndToken');

        $db = $this->createMock(IDBConnection::class);
        foreach (['beginTransaction', 'commit', 'rollBack'] as $step) {
            $db->method($step)->willReturnCallback(function () use ($step): void {
                $this->tx[] = $step;
            });
        }

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->service = (new \ReflectionClass(PaceService::class))->newInstanceWithoutConstructor();
        foreach ([
            'roomMapper' => $this->rooms,
            'pollMapper' => $polls,
            'playerMapper' => $this->players,
            'progressMapper' => $progress,
            'voteMapper' => $votes,
            'presenceMapper' => $presence,
            'roomService' => $this->createMock(RoomService::class),
            'db' => $db,
            'timeFactory' => $time,
            'l10n' => $l10n,
        ] as $name => $value) {
            (new ReflectionProperty(PaceService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── assertStillJoined ──────────────────────────────────────────────────

    public function testNochDabeiKostetKeineRaumsperre(): void {
        $this->joined = [true];
        $this->rooms->expects($this->never())->method('lockForUpdate');

        $this->service->assertStillJoined($this->room(), self::TOK);

        $this->assertSame([], $this->calls);
        $this->assertSame([], $this->tx);
    }

    public function testEntferntRaeumtStimmenUndFortschrittUnterDerSperre(): void {
        $this->joined = [false, false];
        $this->rooms->expects($this->once())->method('lockForUpdate')->with(5)
            ->willReturnCallback(fn (): Room => $this->room());

        $this->assertRejected();

        $this->assertSame(['votes:11,12,13:' . self::TOK, 'progress:5:' . self::TOK], $this->calls);
        $this->assertSame(['beginTransaction', 'commit'], $this->tx);
    }

    public function testInzwischenNeuBeigetretenBleibtUnberuehrt(): void {
        // The cookie is already back in: selfJoin has cleaned up, and whatever is
        // there now belongs to the new identity. The old request still fails.
        $this->joined = [false, true];
        $this->rooms->method('lockForUpdate')->willReturnCallback(fn (): Room => $this->room());

        $this->assertRejected();

        $this->assertSame([], $this->calls);
    }

    public function testRaumGeloeschtRaeumtOhneSperreUndMeldetNichtGefunden(): void {
        // deleteRoom has already deleted players, votes and progress; the request
        // only cleans up what it wrote afterwards itself (order taken from the
        // passed-in room, which no longer exists).
        $this->joined = [false];
        $this->rooms->method('lockForUpdate')->willThrowException(new DoesNotExistException('weg'));

        try {
            $this->service->assertStillJoined($this->room(), self::TOK);
            $this->fail('RoomGoneException erwartet');
        } catch (RoomGoneException) {
        }

        $this->assertSame(['votes:11,12,13:' . self::TOK, 'progress:5:' . self::TOK], $this->calls);
        $this->assertSame(['beginTransaction', 'rollBack'], $this->tx);
    }

    // ── locked ─────────────────────────────────────────────────────────────

    public function testGesperrtWirdDieFrischeZeile(): void {
        $fresh = $this->room();
        $fresh->setJoinsLocked(true);
        $this->rooms->method('lockForUpdate')->willReturn($fresh);

        $seen = $this->service->locked($this->room(), fn (Room $r): Room => $r);

        $this->assertSame($fresh, $seen);
        $this->assertSame(['beginTransaction', 'commit'], $this->tx);
    }

    public function testGeloeschterRaumIstNichtGefundenStattFehler(): void {
        // Another tab or the cleanup job deleted the room after the request
        // had loaded it: 404 in the controllers, not a 500.
        $this->rooms->method('lockForUpdate')->willThrowException(new DoesNotExistException('weg'));
        $called = false;

        try {
            $this->service->locked($this->room(), function () use (&$called): void {
                $called = true;
            });
            $this->fail('RoomGoneException erwartet');
        } catch (RoomGoneException) {
        }

        $this->assertFalse($called);
        $this->assertSame(['beginTransaction', 'rollBack'], $this->tx);
    }

    // ── forgetToken ────────────────────────────────────────────────────────

    public function testVergessenLaesstSpielerUndPraesenzStehen(): void {
        $this->players->expects($this->never())->method('delete');

        $this->service->forgetToken($this->room(), self::TOK);

        $this->assertSame(['votes:11,12,13:' . self::TOK, 'progress:5:' . self::TOK], $this->calls);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function assertRejected(): void {
        try {
            $this->service->assertStillJoined($this->room(), self::TOK);
            $this->fail('InvalidArgumentException erwartet');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Please choose a name first.', $e->getMessage());
        }
    }

    private function room(): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('ABCDEF');
        $room->setMode('quiz');
        $room->setPace('self');
        $room->setOpenedAt(self::NOW - 600);
        $room->setDeckOrder('[11,12,13]');
        return $room;
    }
}
