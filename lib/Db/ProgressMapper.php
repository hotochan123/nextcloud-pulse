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
 * Fortschritt im eigenen Tempo. Kein Transaktionsblock: Auf PostgreSQL bricht
 * ein Unique-Verstoß die ganze Transaktion ab — start() läuft deshalb außerhalb
 * und wertet den Verstoß als „war schon da". Die Lücke zwischen closeIfOpen()
 * und start() schließt die Selbstheilung in PaceService::next.
 *
 * @extends QBMapper<Progress>
 */
class ProgressMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'pulse_progress', Progress::class);
    }

    /**
     * @return Progress[] Zeilen einer Person, nach seq aufsteigend
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
     * @return Progress[] alle Zeilen eines Raums, nach Token, dann seq
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
     * Frage für diese Person starten. Unique-Verstoß (poll_id, voter_token)
     * -> false (Doppeltipp oder paralleler Tab war schneller), sonst true.
     * Andere DB-Fehler werfen weiter. Nicht innerhalb einer Transaktion rufen
     * (s. Klassenkommentar).
     */
    public function start(int $roomId, int $pollId, string $voterToken, int $seq, int $now): bool {
        $row = new Progress();
        $row->setRoomId($roomId);
        $row->setPollId($pollId);
        $row->setVoterToken($voterToken);
        $row->setSeq($seq);
        $row->setStartedAt($now);
        // left_at bleibt 0 (Default) = offen
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
     * Compare-and-set: Zeile nur schließen, wenn sie noch offen ist.
     * @return bool true genau dann, wenn DIESE Anfrage die Zeile geschlossen hat
     */
    public function closeIfOpen(int $id, int $now): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('left_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('left_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement() === 1;
    }

    /** Hat diese Person die Frage je erreicht? (Bildfreigabe) */
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

    /** Eine Person aus dem Raum entfernen (PaceService::removePlayer). */
    public function deleteByRoomAndToken(int $roomId, string $voterToken): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('voter_token', $qb->createNamedParameter($voterToken)));
        $qb->executeStatement();
    }

    /** Nur Demo-/Test-Fortschritt eines Raums löschen (Token-Präfix „demo:"). */
    public function deleteDemoByRoom(int $roomId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->like('voter_token', $qb->createNamedParameter('demo:%')));
        $qb->executeStatement();
    }
}
