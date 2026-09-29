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
 * @extends QBMapper<Vote>
 */
class VoteMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'pulse_votes', Vote::class);
    }

    /**
     * Nach id sortiert, also in der Reihenfolge des Eingangs — die Auszählung
     * zeigt je Wort die erste Schreibweise (TallyService). Ohne ORDER BY
     * lieferte PostgreSQL die Zeilen in Speicherreihenfolge, und die weicht ab,
     * sobald neue Stimmen in frei gewordene Plätze gelöschter fallen.
     *
     * @return Vote[]
     */
    public function findByPoll(int $pollId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Stimmen mehrerer Fragen in EINER Abfrage (Rangliste/Fortschritt im
     * eigenen Tempo statt einer Abfrage je Frage), optional nur eines Tokens.
     * Leere Liste -> [] ohne Abfrage. Über 1000 IDs wird gestückelt (Oracle
     * erlaubt nicht mehr Einträge in einer IN-Liste), das Ergebnis bleibt nach
     * id sortiert.
     *
     * @param list<int> $pollIds
     * @return Vote[]
     */
    public function findByPolls(array $pollIds, ?string $voterToken = null): array {
        $chunks = array_chunk(array_values(array_unique(array_map('intval', $pollIds))), 1000);
        $votes = [];
        foreach ($chunks as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from($this->getTableName())
                ->where($qb->expr()->in('poll_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->orderBy('id', 'ASC');
            if ($voterToken !== null) {
                $qb->andWhere($qb->expr()->eq('voter_token', $qb->createNamedParameter($voterToken)));
            }
            array_push($votes, ...$this->findEntities($qb));
        }
        if (count($chunks) > 1) {
            usort($votes, fn (Vote $a, Vote $b) => $a->getId() <=> $b->getId());
        }
        return $votes;
    }

    public function countByPoll(int $pollId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'c'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * Inhalts-Fingerabdruck der Stimmen einer Frage: crc32 über alle
     * (id:payload)-Paare (nach id sortiert). Ändert sich bei neuer Stimme,
     * geänderter Stimme (Upsert lässt die Anzahl gleich!) und gelöschter Stimme
     * — zeit-unabhängig, anders als ein created_at-Stempel. Nötig, damit der
     * Versions-Fingerabdruck eine geänderte Umfrage-Antwort erkennt (sonst 204
     * „unverändert" → Beamer/Live-Sicht aktualisiert nicht). '0', wenn leer.
     */
    public function changeStamp(int $pollId): string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'payload')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');
        $result = $qb->executeQuery();
        $buf = '';
        while ($row = $result->fetch()) {
            $buf .= $row['id'] . ':' . $row['payload'] . "\n";
        }
        $result->closeCursor();
        return (string)crc32($buf);
    }

    /**
     * @throws DoesNotExistException wenn diese Person noch nicht abgestimmt hat
     */
    public function findByPollAndToken(int $pollId, string $voterToken): Vote {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('voter_token', $qb->createNamedParameter($voterToken)));
        return $this->findEntity($qb);
    }

    public function deleteByPoll(int $pollId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Eine Person aus dem Raum entfernen: ihre Stimmen in diesen Fragen löschen.
     * Leere Liste -> nichts.
     *
     * @param list<int> $pollIds
     */
    public function deleteByPollsAndToken(array $pollIds, string $voterToken): void {
        $ids = array_values(array_unique(array_map('intval', $pollIds)));
        foreach (array_chunk($ids, 1000) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($this->getTableName())
                ->where($qb->expr()->in('poll_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->eq('voter_token', $qb->createNamedParameter($voterToken)));
            $qb->executeStatement();
        }
    }

    /**
     * Nur Demo-/Test-Stimmen einer Frage löschen. Erkennbar am Token-Präfix
     * „demo:" — echte Voter-Token sind 32 alphanumerische Zeichen ohne Doppelpunkt,
     * können also nie mitgetroffen werden. Gibt die Anzahl gelöschter Zeilen zurück.
     */
    public function deleteDemoByPoll(int $pollId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->like('voter_token', $qb->createNamedParameter('demo:%')));
        return $qb->executeStatement();
    }
}
