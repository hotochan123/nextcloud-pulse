<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Presence;
use OCA\Pulse\Db\PresenceMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The heartbeat's upsert (PresenceMapper::touch) asks its guard only when it
 * would add a row. Voter tokens are not signed: a script can send a made-up
 * one with every /state, and each used to add a presence row without any
 * limit (and change the lobby's "N here", sending every phone into a full
 * refetch). The controller's guard counts new rows per address and room;
 * a phone that already has its row is never asked.
 */
#[CoversClass(PresenceMapper::class)]
class PresenceTouchTest extends TestCase {

    private PresenceMapper&MockObject $mapper;
    private ?Presence $row = null;
    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void {
        $this->mapper = $this->getMockBuilder(PresenceMapper::class)
            ->setConstructorArgs([$this->createMock(IDBConnection::class)])
            ->onlyMethods(['findByRoomAndToken', 'insert', 'update'])
            ->getMock();
        $this->mapper->method('findByRoomAndToken')->willReturnCallback(function (): Presence {
            if ($this->row === null) {
                throw new DoesNotExistException('');
            }
            return $this->row;
        });
        $this->mapper->method('insert')->willReturnCallback(function (Entity $e): Entity {
            $this->calls[] = 'insert';
            return $e;
        });
        $this->mapper->method('update')->willReturnCallback(function (Entity $e): Entity {
            $this->calls[] = 'update';
            return $e;
        });
    }

    public function testExistingRowIsUpdatedWithoutAskingTheGuard(): void {
        $this->row = new Presence();

        $this->mapper->touch(5, 'tok', 1000, function (): bool {
            $this->calls[] = 'asked';
            return false;
        });

        $this->assertSame(['update'], $this->calls);
        $this->assertSame(1000, $this->row->getLastSeen());
    }

    public function testNewRowOnlyWhenTheGuardAllowsIt(): void {
        $this->mapper->touch(5, 'tok', 1000, function (): bool {
            $this->calls[] = 'asked';
            return true;
        });

        $this->assertSame(['asked', 'insert'], $this->calls);
    }

    public function testRefusedGuardAddsNoRow(): void {
        $this->mapper->touch(5, 'tok', 1000, fn (): bool => false);

        $this->assertSame([], $this->calls);
    }

    public function testWithoutGuardAsBefore(): void {
        $this->mapper->touch(5, 'tok', 1000);

        $this->assertSame(['insert'], $this->calls);
    }
}
