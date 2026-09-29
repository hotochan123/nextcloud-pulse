<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Migration;

use Closure;
use OCA\Pulse\BackgroundJob\CleanupStaleRoomsJob;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Registers the daily cleanup job for abandoned rooms. No schema change —
 * just one entry in the job list (idempotent, add() checks for duplicates).
 */
class Version000000Date20260717000000 extends SimpleMigrationStep {

    public function __construct(
        private IJobList $jobList,
    ) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        if (!$this->jobList->has(CleanupStaleRoomsJob::class, null)) {
            $this->jobList->add(CleanupStaleRoomsJob::class);
        }
    }
}
