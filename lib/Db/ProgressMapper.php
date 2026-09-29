<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Self-paced progress. No transaction block: on PostgreSQL a unique
 * violation aborts the whole transaction — start() therefore runs outside of one
 * and treats the violation as "already there". The gap between closeIfOpen()
 * and start() is closed by the self-healing in PaceService::next.
 *
 * @extends QBMapper<Progress>
 */
class ProgressMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'pulse_progress', Progress::class);
    }

    /**
     * @return Progress[] one person's rows, ascending by seq
     */
    public function findByRoomAndToken(int $roomId, string $voterToken): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('voter_token', $qb->createNamedParameter($voterToken)))
            ->orderBy('seq', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * @return Progress[] all rows of a room, by token, then seq
     */
    public function findByRoom(int $roomId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->orderBy('voter_token', 'ASC')
            ->addOrderBy('seq', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Start a question for this person. Unique violation (poll_id, voter_token)
     * -> false (double tap or a parallel tab was faster), otherwise true.
     * Other DB errors are rethrown. Do not call inside a transaction
     * (see the class comment).
     */
    public function start(int $roomId, int $pollId, string $voterToken, int $seq, int $now): bool {
        $row = new Progress();
        $row->setRoomId($roomId);
        $row->setPollId($pollId);
        $row->setVoterToken($voterToken);
        $row->setSeq($seq);
        $row->setStartedAt($now);
        // left_at stays 0 (default) = open
        try {
            $this->insert($row);
            return true;
        } catch (Exception $e) {
            if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Compare-and-set: close the row only if it is still open.
     * @return bool true exactly when THIS request closed the row
     */
    public function closeIfOpen(int $id, int $now): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('left_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('left_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement() === 1;
    }

    /** Has this person ever reached the question? (image release) */
    public function hasRow(int $pollId, string $voterToken): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('voter_token', $qb->createNamedParameter($voterToken)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row !== false;
    }

    public function deleteByRoom(int $roomId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /** Remove a person from the room (PaceService::removePlayer). */
    public function deleteByRoomAndToken(int $roomId, string $voterToken): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('voter_token', $qb->createNamedParameter($voterToken)));
        $qb->executeStatement();
    }

    /** Delete only the demo/test progress of a room (token prefix "demo:"). */
    public function deleteDemoByRoom(int $roomId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->like('voter_token', $qb->createNamedParameter('demo:%')));
        $qb->executeStatement();
    }
}
