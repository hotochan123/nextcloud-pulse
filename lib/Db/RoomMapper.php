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
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'pulse_rooms', Room::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findByCode(string $code): Room {
        // Codes werden in Großbuchstaben gespeichert; Eingaben aus URLs
        // (evtl. klein) hier normalisieren, damit die Suche case-insensitiv ist.
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
     * Räume, die vor $ts (Unix-Zeit) angelegt wurden — Kandidaten für den
     * Aufräum-Job. Wird selten (täglich) aufgerufen.
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
     * Raumzeile sperren und frisch lesen (SELECT … FOR UPDATE). Nur innerhalb
     * einer Transaktion sinnvoll (PaceService::locked). SQLite kennt kein
     * FOR UPDATE (DBAL wirft „not supported") — dort bleibt es ein normales
     * SELECT, die Schreibsperre der Datenbank serialisiert ohnehin.
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
     * Fenster öffnen, nur aus dem Entwurf eines self-Raums. Läuft unter
     * lockForUpdate; das Compare-and-set (opened_at = 0, pace = 'self') ist
     * der zweite Gurt gegen einen parallelen Tab.
     *
     * @param string $orderJson eingefrorene Reihenfolge, JSON-Liste der Poll-IDs
     * @return bool true genau dann, wenn diese Anfrage das Fenster geöffnet hat
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
     * Besuch des Besitzers vermerken (Aufbewahrung), höchstens stündlich —
     * sonst schriebe jeder Fortschritts-Poll der Lehrkraft die Raumzeile.
     */
    public function touch(int $roomId, int $now): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('touched_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('touched_at', $qb->createNamedParameter($now - 3600, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
