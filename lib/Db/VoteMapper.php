<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IMemcache;

/**
 * Every write to `pulse_votes` goes through this mapper — the plain entity
 * writes (insert, update, delete, insertOrUpdate) as well as the bulk
 * deletes below. Each of them invalidates the change generation of the
 * questions it touched (see changeStamp), so the cheap version stamp cannot
 * miss a write. A new write path belongs in here, not in raw SQL elsewhere.
 *
 * @extends QBMapper<Vote>
 */
class VoteMapper extends QBMapper {
    /** Cache key of a question's change generation: GEN_KEY . pollId. */
    private const GEN_KEY = 'gen:';
    /** Cache key of the "written inside a transaction" marker: TX_KEY . pollId. */
    private const TX_KEY = 'tx:';
    /**
     * A generation lives a day. When it expires or is evicted, the next
     * poll seeds a fresh random one — every client refetches once, nobody
     * misses anything.
     */
    private const GEN_TTL = 86400;
    /**
     * How long a write inside a transaction keeps the exact stamp in charge.
     * Far longer than any transaction in this app (they hold a row lock for
     * milliseconds); only the commit has to happen within it.
     */
    private const TX_TTL = 120;

    /** Resolved once per request; false = no usable shared cache. */
    private IMemcache|false|null $stampCache = null;

    public function __construct(
        IDBConnection $db,
        private ICacheFactory $cacheFactory,
    ) {
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
    public function findByPolls(array $pollIds, #[\SensitiveParameter] ?string $voterToken = null): array {
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

    // ── Change stamp (adaptive polling) ─────────────────────────────────────

    /**
     * Stamp that changes whenever a question's votes change: a new vote, a
     * changed vote (an upsert leaves the count unchanged!), a graded or a
     * deleted one. The version fingerprints (StateService) are built from
     * it, and every phone and the projector ask for them every few seconds —
     * so it must not read the votes.
     *
     * Normally it is a random generation in the shared cache (the
     * distributed one, which falls back to the local one): the first poll
     * after a write seeds a new one, every write removes it again (after its
     * statement, see written()). The stamp only ever becomes a value no
     * client has seen before, so a missing, expired or evicted entry costs
     * one full refetch, never a missed update. That holds as long as the
     * caller builds the version BEFORE it reads the state it pairs the
     * version with (as /state, the state sent back after a vote or join, and
     * the moderator's results do): a write committed after the state read
     * removes the generation that was handed out with it.
     *
     * The exact stamp over all votes (contentStamp, the former
     * implementation) is used instead when there is no shared cache (it is
     * a NullCache, or it throws) and while a write inside a still open
     * transaction may not be visible yet (TX_KEY marker, see writing()).
     * The generation is read BEFORE the marker: a transaction that sets the
     * marker after that read removes the generation after its statement.
     */
    public function changeStamp(int $pollId): string {
        $cache = $this->stampCache();
        if ($cache !== null) {
            try {
                $gen = $cache->get(self::GEN_KEY . $pollId);
                if (!is_string($gen) || $gen === '') {
                    $seed = bin2hex(random_bytes(8));
                    // add, not set: two parallel first polls agree on one seed.
                    $gen = $cache->add(self::GEN_KEY . $pollId, $seed, self::GEN_TTL)
                        ? $seed
                        : $cache->get(self::GEN_KEY . $pollId);
                }
                if (is_string($gen) && $gen !== '' && $cache->get(self::TX_KEY . $pollId) === null) {
                    return 'g' . $gen;
                }
            } catch (\Throwable) {
                // A broken cache must not break polling — exact stamp below.
            }
        }
        return $this->contentStamp($pollId);
    }

    /**
     * Exact content fingerprint of a question's votes: crc32 over all
     * (id:payload) pairs (sorted by id). Changes on a new vote,
     * a changed vote and a deleted vote — independent of time, unlike a
     * created_at stamp. Reads every payload of the question, which is why it
     * is only the fallback of changeStamp. '0'-like value when empty.
     */
    public function contentStamp(int $pollId): string {
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
        return 'c' . crc32($buf);
    }

    /** The shared cache for the stamp, or null (NullCache: nothing is shared). */
    private function stampCache(): ?IMemcache {
        if ($this->stampCache === null) {
            $this->stampCache = false;
            try {
                if ($this->cacheFactory->isAvailable()) {
                    $cache = $this->cacheFactory->createDistributed('pulse-votes/');
                    if ($cache instanceof IMemcache) {
                        $this->stampCache = $cache;
                    }
                }
            } catch (\Throwable) {
                // stays false: exact stamp
            }
        }
        return $this->stampCache === false ? null : $this->stampCache;
    }

    /**
     * Before a write: inside a transaction the write only becomes visible
     * with the commit, and there is no commit hook to invalidate after it.
     * The marker hands the stamp to the exact contentStamp until well after
     * the commit; a generation seeded meanwhile is never handed out.
     *
     * @param list<int> $pollIds
     */
    private function writing(array $pollIds): void {
        $cache = $this->stampCache();
        if ($cache === null || !$this->db->inTransaction()) {
            return;
        }
        foreach ($pollIds as $pollId) {
            try {
                $cache->set(self::TX_KEY . $pollId, 1, self::TX_TTL);
            } catch (\Throwable) {
                // a cache outage: the pollers fall back to the exact stamp anyway
            }
        }
    }

    /**
     * After a write (outside a transaction: after its commit): drop the
     * generation, the next poll seeds a new one.
     *
     * @param list<int> $pollIds
     */
    private function written(array $pollIds): void {
        $cache = $this->stampCache();
        if ($cache === null) {
            return;
        }
        foreach ($pollIds as $pollId) {
            try {
                $cache->remove(self::GEN_KEY . $pollId);
            } catch (\Throwable) {
                // see writing()
            }
        }
    }

    // ── Writes (each one invalidates the stamp) ─────────────────────────────

    /**
     * @param Vote $entity
     * @return Vote
     */
    public function insert(Entity $entity): Entity {
        $ids = [(int)$entity->getPollId()];
        $this->writing($ids);
        $entity = parent::insert($entity);
        $this->written($ids);
        return $entity;
    }

    /**
     * @param Vote $entity
     * @return Vote
     */
    public function update(Entity $entity): Entity {
        $ids = [(int)$entity->getPollId()];
        $this->writing($ids);
        $entity = parent::update($entity);
        $this->written($ids);
        return $entity;
    }

    /**
     * @param Vote $entity
     * @return Vote
     */
    public function delete(Entity $entity): Entity {
        $ids = [(int)$entity->getPollId()];
        $this->writing($ids);
        $entity = parent::delete($entity);
        $this->written($ids);
        return $entity;
    }

    /**
     * @throws DoesNotExistException if this person has not voted yet
     */
    public function findByPollAndToken(int $pollId, #[\SensitiveParameter] string $voterToken): Vote {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('voter_token', $qb->createNamedParameter($voterToken)));
        return $this->findEntity($qb);
    }

    public function deleteByPoll(int $pollId): void {
        $this->writing([$pollId]);
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
        $this->written([$pollId]);
    }

    /**
     * Remove a person from the room: delete their votes in these questions.
     * Empty list -> nothing.
     *
     * @param list<int> $pollIds
     */
    public function deleteByPollsAndToken(array $pollIds, #[\SensitiveParameter] string $voterToken): void {
        $ids = array_values(array_unique(array_map('intval', $pollIds)));
        $this->writing($ids);
        foreach (array_chunk($ids, 1000) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($this->getTableName())
                ->where($qb->expr()->in('poll_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->eq('voter_token', $qb->createNamedParameter($voterToken)));
            $qb->executeStatement();
        }
        $this->written($ids);
    }

    /**
     * Delete only the demo/test votes of a question. Recognisable by the token prefix
     * "demo:" — real voter tokens are 32 alphanumeric characters without a colon,
     * so they can never be hit as well. Returns the number of deleted rows.
     */
    public function deleteDemoByPoll(int $pollId): int {
        $this->writing([$pollId]);
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->like('voter_token', $qb->createNamedParameter('demo:%')));
        $removed = $qb->executeStatement();
        $this->written([$pollId]);
        return $removed;
    }
}
