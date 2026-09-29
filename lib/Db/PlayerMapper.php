<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Player>
 */
class PlayerMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'pulse_players', Player::class);
    }

    /**
     * Join/rename: set this token's nickname (upsert on
     * room_id+voter_token). Race-safe like PresenceMapper::touch().
     */
    public function register(int $roomId, string $voterToken, string $nickname, int $now): Player {
        try {
            $row = $this->findByRoomAndToken($roomId, $voterToken);
            $row->setNickname($nickname);
            return $this->update($row);
        } catch (DoesNotExistException) {
            // no row yet -> create one below
        }

        $row = new Player();
        $row->setRoomId($roomId);
        $row->setVoterToken($voterToken);
        $row->setNickname($nickname);
        $row->setCreatedAt($now);
        try {
            return $this->insert($row);
        } catch (Exception $e) {
            // Race: a parallel join of the same token created the row (UNIQUE).
            if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                $existing = $this->findByRoomAndToken($roomId, $voterToken);
                $existing->setNickname($nickname);
                return $this->update($existing);
            }
            throw $e;
        }
    }

    /**
     * @throws DoesNotExistException this token has not joined the room yet
     */
    public function findByRoomAndToken(int $roomId, string $voterToken): Player {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('voter_token', $qb->createNamedParameter($voterToken)));
        return $this->findEntity($qb);
    }

    /**
     * Does this token (still) exist in the room? Read with a lock (SELECT … FOR
     * UPDATE): if removePlayer is deleting the row right now, the query waits for
     * its commit and then no longer finds it (PaceService::assertStillJoined).
     * Without a transaction the lock only applies to this one query. SQLite has
     * no FOR UPDATE — a plain SELECT there (like RoomMapper::lockForUpdate).
     */
    public function existsForUpdate(int $roomId, string $voterToken): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('voter_token', $qb->createNamedParameter($voterToken)));
        if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_SQLITE) {
            $qb->forUpdate();
        }
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row !== false;
    }

    /**
     * @return Player[] all players of a room
     */
    public function findByRoom(int $roomId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    public function countByRoom(int $roomId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'n'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $n = (int)$result->fetchOne();
        $result->closeCursor();
        return $n;
    }

    public function deleteByRoom(int $roomId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /** Delete only the demo/test players of a room (token prefix "demo:"). */
    public function deleteDemoByRoom(int $roomId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->like('voter_token', $qb->createNamedParameter('demo:%')));
        $qb->executeStatement();
    }
}
