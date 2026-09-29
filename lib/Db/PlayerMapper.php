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
     * Beitritt/Umbenennen: Nickname dieses Tokens setzen (Upsert auf
     * room_id+voter_token). Race-sicher wie PresenceMapper::touch().
     */
    public function register(int $roomId, string $voterToken, string $nickname, int $now): Player {
        try {
            $row = $this->findByRoomAndToken($roomId, $voterToken);
            $row->setNickname($nickname);
            return $this->update($row);
        } catch (DoesNotExistException) {
            // noch keine Zeile -> unten anlegen
        }

        $row = new Player();
        $row->setRoomId($roomId);
        $row->setVoterToken($voterToken);
        $row->setNickname($nickname);
        $row->setCreatedAt($now);
        try {
            return $this->insert($row);
        } catch (Exception $e) {
            // Race: paralleler Beitritt desselben Tokens legte die Zeile an (UNIQUE).
            if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                $existing = $this->findByRoomAndToken($roomId, $voterToken);
                $existing->setNickname($nickname);
                return $this->update($existing);
            }
            throw $e;
        }
    }

    /**
     * @throws DoesNotExistException dieses Token ist dem Raum noch nicht beigetreten
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
     * Gibt es dieses Token (noch) im Raum? Sperrend gelesen (SELECT … FOR
     * UPDATE): löscht removePlayer die Zeile gerade, wartet die Abfrage auf
     * dessen Commit und findet sie danach nicht mehr (PaceService::assertStillJoined).
     * Ohne Transaktion gilt die Sperre nur für diese eine Abfrage. SQLite kennt
     * kein FOR UPDATE — dort ein normales SELECT (wie RoomMapper::lockForUpdate).
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
     * @return Player[] alle Spieler eines Raums
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

    /** Nur Demo-/Test-Spieler eines Raums löschen (Token-Präfix „demo:"). */
    public function deleteDemoByRoom(int $roomId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->like('voter_token', $qb->createNamedParameter('demo:%')));
        $qb->executeStatement();
    }
}
