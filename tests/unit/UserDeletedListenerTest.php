<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\AppInfo\Application;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\PresenceMapper;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Listener\UserDeletedListener;
use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\RoomService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * A deleted Nextcloud account takes its rooms with it (UserDeletedListener):
 * every room of that uid through RoomService::deleteRoom, so nothing of it
 * stays reachable under its public code. Nothing may escape from the
 * listener — an exception would abort Nextcloud's own clean-up of the
 * account halfway.
 */
#[CoversClass(UserDeletedListener::class)]
#[CoversClass(Application::class)]
class UserDeletedListenerTest extends TestCase {

    /** @var array<string, list<Room>> uid -> rooms that findByOwner returns */
    private array $owned = [];
    /** @var list<string> uids that findByOwner was asked for */
    private array $lookedUp = [];
    /** @var list<string> codes that RoomService::deleteRoom received */
    private array $deleted = [];
    /** @var list<string> codes whose deletion fails */
    private array $broken = [];
    /** @var list<array{0: string, 1: string, 2: array}> [level, message, context] */
    private array $log = [];

    public function testDeletesEveryRoomOfTheAccount(): void {
        $this->owned['anna'] = [$this->room(1, 'AAAAAA'), $this->room(2, 'BBBBBB')];
        $this->owned['ben'] = [$this->room(3, 'CCCCCC')];

        $this->listener($this->mockedRooms())->handle($this->deletedEvent('anna'));

        $this->assertSame(['anna'], $this->lookedUp);
        $this->assertSame(['AAAAAA', 'BBBBBB'], $this->deleted, 'only the deleted account\'s rooms');
        $this->assertSame([
            ['info', 'Pulse: deleted {count} rooms of deleted user {uid}.', ['count' => 2, 'uid' => 'anna']],
        ], $this->log);
    }

    public function testRoomGoesWithEverythingThatBelongsToIt(): void {
        // Through the real RoomService::deleteRoom: questions, votes, players,
        // presence, progress and images go along — not just the room row. The
        // rows in one transaction, so a failure leaves the room complete.
        $this->owned['anna'] = [$this->room(7, 'AAAAAA')];
        $gone = [];
        $poll = new Poll();
        $poll->setId(70);

        $roomMapper = $this->roomMapper();
        $roomMapper->method('delete')->willReturnCallback(function (Room $r) use (&$gone): Room {
            $gone[] = 'room ' . $r->getId();
            return $r;
        });
        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturn([$poll]);
        $polls->method('delete')->willReturnCallback(function (Poll $p) use (&$gone): Poll {
            $gone[] = 'poll ' . $p->getId();
            return $p;
        });
        $votes = $this->createMock(VoteMapper::class);
        $votes->method('deleteByPoll')->willReturnCallback(function (int $id) use (&$gone): void {
            $gone[] = 'votes ' . $id;
        });
        $presence = $this->createMock(PresenceMapper::class);
        $presence->method('deleteByRoom')->willReturnCallback(function (int $id) use (&$gone): void {
            $gone[] = 'presence ' . $id;
        });
        $players = $this->createMock(PlayerMapper::class);
        $players->method('deleteByRoom')->willReturnCallback(function (int $id) use (&$gone): void {
            $gone[] = 'players ' . $id;
        });
        $progress = $this->createMock(ProgressMapper::class);
        $progress->method('deleteByRoom')->willReturnCallback(function (int $id) use (&$gone): void {
            $gone[] = 'progress ' . $id;
        });
        $images = $this->createMock(PollImageService::class);
        $images->method('discard')->willReturnCallback(function (Poll $p) use (&$gone): void {
            $gone[] = 'image ' . $p->getId();
        });
        $db = $this->createMock(IDBConnection::class);
        foreach (['beginTransaction' => 'begin', 'commit' => 'commit', 'rollBack' => 'rollBack'] as $method => $what) {
            $db->method($method)->willReturnCallback(function () use (&$gone, $what): bool {
                $gone[] = $what;
                return true;
            });
        }

        $service = (new \ReflectionClass(RoomService::class))->newInstanceWithoutConstructor();
        foreach ([
            'roomMapper' => $roomMapper,
            'pollMapper' => $polls,
            'voteMapper' => $votes,
            'presenceMapper' => $presence,
            'playerMapper' => $players,
            'progressMapper' => $progress,
            'imageService' => $images,
            'db' => $db,
        ] as $name => $value) {
            (new ReflectionProperty(RoomService::class, $name))->setValue($service, $value);
        }

        (new UserDeletedListener($roomMapper, $service, $this->logger()))->handle($this->deletedEvent('anna'));

        $this->assertSame(
            ['begin', 'room 7', 'presence 7', 'players 7', 'votes 70', 'poll 70', 'progress 7', 'commit', 'image 70'],
            $gone,
        );
    }

    public function testAccountWithoutRoomsWritesNoLogLine(): void {
        // Most accounts never had a room — no log line for each of them.
        $this->listener($this->mockedRooms())->handle($this->deletedEvent('carla'));

        $this->assertSame(['carla'], $this->lookedUp);
        $this->assertSame([], $this->deleted);
        $this->assertSame([], $this->log);
    }

    public function testBrokenRoomDoesNotStopTheOthers(): void {
        $this->owned['anna'] = [$this->room(1, 'AAAAAA'), $this->room(2, 'BBBBBB'), $this->room(3, 'CCCCCC')];
        $this->broken = ['BBBBBB'];

        // Must not throw.
        $this->listener($this->mockedRooms())->handle($this->deletedEvent('anna'));

        $this->assertSame(['AAAAAA', 'CCCCCC'], $this->deleted);
        $this->assertCount(2, $this->log);
        [$level, $message, $context] = $this->log[0];
        $this->assertSame('warning', $level);
        $this->assertSame('Pulse: could not delete room {code} of deleted user {uid}.', $message);
        $this->assertSame('BBBBBB', $context['code']);
        $this->assertInstanceOf(\RuntimeException::class, $context['exception']);
        $this->assertSame(['info', 'Pulse: deleted {count} rooms of deleted user {uid}.', ['count' => 2, 'uid' => 'anna']], $this->log[1]);
    }

    public function testLookupFailsWithoutAnException(): void {
        $mapper = $this->createMock(RoomMapper::class);
        $mapper->method('findByOwner')->willThrowException(new \RuntimeException('database gone'));
        $rooms = $this->createMock(RoomService::class);
        $rooms->expects($this->never())->method('deleteRoom');

        (new UserDeletedListener($mapper, $rooms, $this->logger()))->handle($this->deletedEvent('anna'));

        $this->assertCount(1, $this->log);
        $this->assertSame('warning', $this->log[0][0]);
        $this->assertSame('anna', $this->log[0][2]['uid']);
    }

    public function testForeignEventIsIgnored(): void {
        $this->owned['anna'] = [$this->room(1, 'AAAAAA')];

        $this->listener($this->mockedRooms())->handle(new Event());

        $this->assertSame([], $this->lookedUp);
        $this->assertSame([], $this->deleted);
    }

    public function testApplicationRegistersTheListener(): void {
        $context = $this->createMock(IRegistrationContext::class);
        $context->expects($this->once())
            ->method('registerEventListener')
            ->with(UserDeletedEvent::class, UserDeletedListener::class);

        // Without the constructor: App::__construct needs a booted server.
        $app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $app->register($context);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function listener(RoomService $rooms): UserDeletedListener {
        return new UserDeletedListener($this->roomMapper(), $rooms, $this->logger());
    }

    /** RoomMapper whose findByOwner serves $this->owned. */
    private function roomMapper(): RoomMapper {
        $mapper = $this->createMock(RoomMapper::class);
        $mapper->method('findByOwner')->willReturnCallback(function (string $uid): array {
            $this->lookedUp[] = $uid;
            return $this->owned[$uid] ?? [];
        });
        return $mapper;
    }

    /** RoomService dummy: records deleteRoom, fails for codes in $this->broken. */
    private function mockedRooms(): RoomService {
        $rooms = $this->createMock(RoomService::class);
        $rooms->method('deleteRoom')->willReturnCallback(function (Room $room): void {
            if (in_array($room->getCode(), $this->broken, true)) {
                throw new \RuntimeException('storage error');
            }
            $this->deleted[] = $room->getCode();
        });
        return $rooms;
    }

    private function logger(): LoggerInterface {
        $logger = $this->createMock(LoggerInterface::class);
        foreach (['info', 'warning'] as $level) {
            $logger->method($level)->willReturnCallback(function (string|\Stringable $message, array $context = []) use ($level): void {
                $this->log[] = [$level, (string)$message, $context];
            });
        }
        return $logger;
    }

    private function deletedEvent(string $uid): UserDeletedEvent {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return new UserDeletedEvent($user);
    }

    private function room(int $id, string $code): Room {
        $room = new Room();
        $room->setId($id);
        $room->setCode($code);
        return $room;
    }
}
