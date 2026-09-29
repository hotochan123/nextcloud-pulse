<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Poll>
 */
class PollMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'pulse_polls', Poll::class);
    }

    /**
     * QBMapper offers no generic find($id) (only the old, deprecated
     * Mapper did) — hence spelled out here.
     *
     * @throws DoesNotExistException
     */
    public function find(int $id): Poll {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * Like find(), but read with a lock (SELECT … FOR UPDATE): if the moderator
     * is grading right now (VoteService::gradeTextAnswer holds the row until the
     * commit), the query waits and then sees their answer key.
     * Without a transaction the lock only applies to this one query. SQLite: without
     * FOR UPDATE (like RoomMapper::lockForUpdate).
     *
     * @throws DoesNotExistException
     */
    public function findForUpdate(int $id): Poll {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_SQLITE) {
            $qb->forUpdate();
        }
        return $this->findEntity($qb);
    }

    /**
     * A room's deck in order (position, then ID as tiebreaker).
     *
     * @return Poll[]
     */
    public function findByRoom(int $roomId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->orderBy('position', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    public function countByRoom(int $roomId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'c'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    public function setPosition(int $pollId, int $position): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('position', $qb->createNamedParameter($position, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /** Next free position in the deck (max+1, safe against gaps from deletions). */
    public function nextPosition(int $roomId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->max('position'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $max = $result->fetchOne();
        $result->closeCursor();
        return $max === null ? 0 : ((int)$max + 1);
    }

    public function markEnded(int $pollId): void {
        $this->setStatus($pollId, 'ended');
    }

    /**
     * Undo the quiz end: every other 'ended' question of the room becomes
     * a revealed one ('locked') again. Once a question starts anew, the
     * earlier final standings are over.
     */
    public function unmarkEnded(int $roomId, int $exceptPollId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter('locked'))
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('ended')))
            ->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($exceptPollId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /** 'active' | 'locked' | 'ended' */
    public function setStatus(int $pollId, string $status): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter($status))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
