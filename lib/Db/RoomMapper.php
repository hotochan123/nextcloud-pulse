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
 * @extends QBMapper<Room>
 */
class RoomMapper extends QBMapper {
    /** touch() writes `touched_at` at most once per this many seconds. */
    public const TOUCH_INTERVAL = 3600;

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'pulse_rooms', Room::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findByCode(string $code): Room {
        // Codes are stored in upper case; normalise input from URLs
        // (possibly lower case) here so that the lookup is case-insensitive.
        $code = strtoupper($code);
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
        return $this->findEntity($qb);
    }

    public function codeExists(string $code): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row !== false;
    }

    /**
     * @return Room[]
     */
    public function findByOwner(string $ownerUid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
            ->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }

    /**
     * Rooms created before $ts (Unix time) — candidates for the
     * cleanup job. Called rarely (daily).
     *
     * @return Room[]
     */
    public function findOlderThan(int $ts): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->lt('created_at', $qb->createNamedParameter($ts, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    /**
     * Lock the room row and read it fresh (SELECT … FOR UPDATE). Only useful inside
     * a transaction (PaceService::locked). SQLite has no
     * FOR UPDATE (DBAL throws "not supported") — there it stays a plain
     * SELECT; the database's write lock serialises anyway.
     *
     * @throws DoesNotExistException
     */
    public function lockForUpdate(int $roomId): Room {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
        if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_SQLITE) {
            $qb->forUpdate();
        }
        return $this->findEntity($qb);
    }

    /**
     * Open the window, only from the draft state of a self-paced room. Runs under
     * lockForUpdate; the compare-and-set (opened_at = 0, pace = 'self') is
     * the second safety belt against a parallel tab.
     *
     * @param string $orderJson frozen order, JSON list of poll IDs
     * @return bool true exactly when this request opened the window
     */
    public function openIfDraft(int $roomId, string $orderJson, int $now, int $closesAt, bool $timed, string $feedback): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('opened_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('closes_at', $qb->createNamedParameter($closesAt, IQueryBuilder::PARAM_INT))
            ->set('closed_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('released_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('timed', $qb->createNamedParameter($timed, IQueryBuilder::PARAM_BOOL))
            ->set('feedback', $qb->createNamedParameter($feedback))
            ->set('deck_order', $qb->createNamedParameter($orderJson))
            ->set('active_poll_id', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('opened_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('pace', $qb->createNamedParameter('self')));
        return $qb->executeStatement() === 1;
    }

    /**
     * Record owner activity on the room (retention, any room mode), at most
     * once per TOUCH_INTERVAL — otherwise every results or progress poll of
     * the presenter would write the room row. The WHERE keeps the throttle
     * race-safe between parallel tabs.
     */
    public function touch(int $roomId, int $now): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('touched_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('touched_at', $qb->createNamedParameter($now - self::TOUCH_INTERVAL, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
