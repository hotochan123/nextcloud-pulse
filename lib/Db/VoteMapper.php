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
     * Sorted by id, i.e. in the order of arrival — the tally
     * shows the first spelling of each word (TallyService). Without ORDER BY
     * PostgreSQL returned the rows in storage order, and that differs
     * as soon as new votes land in slots freed by deleted ones.
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
     * Votes of several questions in ONE query (leaderboard/progress in
     * self-paced mode instead of one query per question), optionally for one token only.
     * Empty list -> [] without a query. Above 1000 IDs the list is split (Oracle
     * allows no more entries in an IN list), the result stays sorted by
     * id.
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
     * Content fingerprint of a question's votes: crc32 over all
     * (id:payload) pairs (sorted by id). Changes on a new vote,
     * a changed vote (an upsert leaves the count unchanged!) and a deleted vote
     * — independent of time, unlike a created_at stamp. Needed so that the
     * version fingerprint recognises a changed poll answer (otherwise 204
     * "unchanged" → projector/live view does not update). '0' when empty.
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
     * @throws DoesNotExistException if this person has not voted yet
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
     * Remove a person from the room: delete their votes in these questions.
     * Empty list -> nothing.
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
     * Delete only the demo/test votes of a question. Recognisable by the token prefix
     * "demo:" — real voter tokens are 32 alphanumeric characters without a colon,
     * so they can never be hit as well. Returns the number of deleted rows.
     */
    public function deleteDemoByPoll(int $pollId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->like('voter_token', $qb->createNamedParameter('demo:%')));
        return $qb->executeStatement();
    }
}
