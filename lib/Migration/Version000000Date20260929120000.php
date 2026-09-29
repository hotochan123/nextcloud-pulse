<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Migration;

use Closure;
use OCA\Pulse\BackgroundJob\CleanupStaleRoomsJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * One grace period for existing rooms on installs where the cleanup job has
 * never run. No schema change.
 *
 * Owner activity (`touched_at`) counts for retention in every room mode
 * since this version (RoomService::lastActivity), but it used to be
 * recorded for self-paced rooms only, so every other existing room has
 * `touched_at` = 0: what its owner did before the update is unknown.
 *
 * - Where the job already ran, nothing changes: the old rule (participants
 *   alone) has been deciding all along, and owner activity counts from the
 *   update on.
 * - Where it never ran — an install set up fresh on an earlier version, whose
 *   migration step for the job was skipped (Version000000Date20260717000000)
 *   — the update registers the job through info.xml, and its first run a few
 *   minutes later would delete every room older than 30 days that no
 *   participant used within them, even a deck its owner edited the day
 *   before. There, the existing rooms without owner activity count as used on
 *   the day of the update instead.
 *
 * Runs on update only. Nextcloud registers the <background-jobs> of info.xml
 * after the migrations (AppManager::upgradeApp), so the job list still shows
 * the state before the update here; a fresh install runs the migrations
 * schema-only and has no rooms anyway.
 */
class Version000000Date20260929120000 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db,
        private IJobList $jobList,
        private ITimeFactory $timeFactory,
    ) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        if ($this->cleanupHasRun()) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->update('pulse_rooms')
            ->set('touched_at', $qb->createNamedParameter($this->timeFactory->getTime(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('touched_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        $rooms = $qb->executeStatement();
        if ($rooms > 0) {
            $output->info("Pulse: $rooms existing rooms count as used today, so the room clean-up deletes none of them in the next 30 days.");
        }
    }

    /** Has the cleanup job ever run on this instance? */
    private function cleanupHasRun(): bool {
        foreach ($this->jobList->getJobsIterator(CleanupStaleRoomsJob::class, null, 0) as $job) {
            if ($job->getLastRun() > 0) {
                return true;
            }
        }
        return false;
    }
}
