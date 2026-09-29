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
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Umfrage-Stimme (VoteService::recordVote, moderiert, kein Quiz): Upsert auf
 * (poll_id, voter_token). Zwei erste Stimmen desselben Tokens zugleich
 * (Doppeltipp, Wiederholung nach Netzfehler) finden beide keine Stimme, und
 * die zweite Einfügung scheitert am UNIQUE-Index. Das war eine 500; jetzt wird
 * sie zur Änderung der gerade eingefügten Stimme (last-write-wins wie sonst).
 */
#[CoversClass(VoteService::class)]
class PollVoteRaceTest extends TestCase {

    private VoteMapper $votes;
    private VoteService $service;

    protected function setUp(): void {
        $this->votes = $this->createMock(VoteMapper::class);

        $polls = $this->createMock(PollMapper::class);
        $polls->method('find')->willReturnCallback(function (): Poll {
            $poll = new Poll();
            $poll->setId(7);
            $poll->setType('choice');
            $poll->setStatus('active');
            $poll->setOptions(json_encode([['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B']]));
            return $poll;
        });

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1000);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        foreach ([
            'voteMapper' => $this->votes,
            'pollMapper' => $polls,
            'timeFactory' => $time,
            'l10n' => $l10n,
        ] as $name => $value) {
            (new ReflectionProperty(VoteService::class, $name))->setValue($this->service, $value);
        }
    }

    public function testGleichzeitigeErsteStimmeWirdZurAenderung(): void {
        $other = new Vote();
        $other->setPollId(7);
        $other->setVoterToken('tok');
        $other->setPayload(json_encode(['value' => 'AA']));
        $other->setCreatedAt(999);
        // Erst keine Stimme, nach dem gescheiterten Einfügen die der anderen Anfrage.
        $this->votes->method('findByPollAndToken')->willReturnOnConsecutiveCalls(
            $this->throwException(new DoesNotExistException('keine Stimme')),
            $other,
        );
        $this->votes->expects($this->once())->method('insert')->willThrowException($this->dbError(Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION));
        $saved = null;
        $this->votes->expects($this->once())->method('update')->willReturnCallback(function (Vote $vote) use (&$saved): Vote {
            $saved = $vote;
            return $vote;
        });

        $this->service->recordVote($this->room(), 'tok', 'BB');

        $this->assertSame($other, $saved);
        $this->assertSame(['value' => 'BB'], json_decode($saved->getPayload(), true));
        $this->assertSame(1000, $saved->getCreatedAt());
    }

    public function testAndererDatenbankfehlerBleibtEinFehler(): void {
        $this->votes->method('findByPollAndToken')->willThrowException(new DoesNotExistException('keine Stimme'));
        $this->votes->method('insert')->willThrowException($this->dbError(Exception::REASON_CONNECTION_LOST));
        $this->votes->expects($this->never())->method('update');

        $this->expectException(Exception::class);
        $this->service->recordVote($this->room(), 'tok', 'BB');
    }

    private function room(): Room {
        $room = new Room();
        $room->setId(1);
        $room->setMode('poll');
        $room->setActivePollId(7);
        return $room;
    }

    private function dbError(int $reason): Exception {
        $e = $this->createMock(Exception::class);
        $e->method('getReason')->willReturn($reason);
        return $e;
    }
}
