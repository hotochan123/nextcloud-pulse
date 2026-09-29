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
     * QBMapper bietet kein generisches find($id) (nur der alte, deprecated
     * Mapper tat das) — daher hier explizit.
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
     * Wie find(), aber sperrend gelesen (SELECT … FOR UPDATE): bewertet der
     * Moderator gerade (VoteService::gradeTextAnswer hält die Zeile bis zum
     * Commit), wartet die Abfrage und sieht danach dessen Antwortschlüssel.
     * Ohne Transaktion gilt die Sperre nur für diese eine Abfrage. SQLite: ohne
     * FOR UPDATE (wie RoomMapper::lockForUpdate).
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
     * Deck eines Raums in Reihenfolge (Position, dann ID als Tiebreaker).
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

    /** Nächste freie Position im Deck (max+1, lückensicher gegen Löschungen). */
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
     * Das Quiz-Ende zurücknehmen: jede andere 'ended'-Frage des Raums wird
     * wieder zur aufgelösten ('locked'). Läuft eine Frage neu an, ist der
     * frühere Endstand vorbei.
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
