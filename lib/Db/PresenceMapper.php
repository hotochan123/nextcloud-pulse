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
 * @extends QBMapper<Presence>
 */
class PresenceMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'pulse_presence', Presence::class);
    }

    /**
     * Heartbeat: set this token's last_seen to now (upsert on
     * room_id+voter_token). Called on every participant poll.
     *
     * @param ?\Closure(): bool $mayInsert asked only when the token has no row
     *        yet; false = add none (RoomService::heartbeat)
     */
    public function touch(int $roomId, #[\SensitiveParameter] string $voterToken, int $now, ?\Closure $mayInsert = null): void {
        try {
            $row = $this->findByRoomAndToken($roomId, $voterToken);
            $row->setLastSeen($now);
            $this->update($row);
            return;
        } catch (DoesNotExistException) {
            // no row yet -> create one below
        }
        if ($mayInsert !== null && !$mayInsert()) {
            return;
        }

        $row = new Presence();
        $row->setRoomId($roomId);
        $row->setVoterToken($voterToken);
        $row->setLastSeen($now);
        try {
            $this->insert($row);
        } catch (Exception $e) {
            // Race: a parallel poll of the same token created the row between
            // find and insert (UNIQUE). Then simply update it.
            if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                $existing = $this->findByRoomAndToken($roomId, $voterToken);
                $existing->setLastSeen($now);
                $this->update($existing);
            } else {
                throw $e;
            }
        }
    }

    /**
     * @throws DoesNotExistException this token has never been active in this room
     */
    public function findByRoomAndToken(int $roomId, #[\SensitiveParameter] string $voterToken): Presence {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('voter_token', $qb->createNamedParameter($voterToken)));
        return $this->findEntity($qb);
    }

    /**
     * Number of a room's participants active since $since (Unix time).
     */
    public function countActive(int $roomId, int $since): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'c'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('last_seen', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * Last activity of any participant in the room (MAX(last_seen)),
     * 0 without rows — for the retention rule of the clean-up job.
     */
    public function lastSeen(int $roomId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->max('last_seen'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $max = $result->fetchOne();
        $result->closeCursor();
        return $max === null || $max === false ? 0 : (int)$max;
    }

    /**
     * @return Presence[] all rows of a room ("Last active" in the progress view)
     */
    public function findByRoom(int $roomId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    public function deleteByRoom(int $roomId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /** Remove a person from the room (PaceService::removePlayer). */
    public function deleteByRoomAndToken(int $roomId, #[\SensitiveParameter] string $voterToken): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('voter_token', $qb->createNamedParameter($voterToken)));
        $qb->executeStatement();
    }
}
