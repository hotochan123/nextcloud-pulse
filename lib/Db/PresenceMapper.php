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
     * Heartbeat: last_seen dieses Tokens auf jetzt setzen (Upsert auf
     * room_id+voter_token). Wird bei jedem Teilnehmer-Poll aufgerufen.
     */
    public function touch(int $roomId, string $voterToken, int $now): void {
        try {
            $row = $this->findByRoomAndToken($roomId, $voterToken);
            $row->setLastSeen($now);
            $this->update($row);
            return;
        } catch (DoesNotExistException) {
            // noch keine Zeile -> unten anlegen
        }

        $row = new Presence();
        $row->setRoomId($roomId);
        $row->setVoterToken($voterToken);
        $row->setLastSeen($now);
        try {
            $this->insert($row);
        } catch (Exception $e) {
            // Race: ein paralleler Poll desselben Tokens hat die Zeile zwischen
            // find und insert angelegt (UNIQUE). Dann einfach aktualisieren.
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
     * @throws DoesNotExistException dieses Token war in diesem Raum noch nie aktiv
     */
    public function findByRoomAndToken(int $roomId, string $voterToken): Presence {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('voter_token', $qb->createNamedParameter($voterToken)));
        return $this->findEntity($qb);
    }

    /**
     * Anzahl seit $since (Unix-Zeit) aktiver Teilnehmer eines Raums.
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
     * Letzte Aktivität irgendeines Teilnehmers im Raum (MAX(last_seen)),
     * 0 ohne Zeilen — für die Aufbewahrungsregel des Aufräum-Jobs.
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
     * @return Presence[] alle Zeilen eines Raums („zuletzt gesehen" im Fortschritt)
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

    /** Eine Person aus dem Raum entfernen (PaceService::removePlayer). */
    public function deleteByRoomAndToken(int $roomId, string $voterToken): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('voter_token', $qb->createNamedParameter($voterToken)));
        $qb->executeStatement();
    }
}
