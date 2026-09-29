<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * More quiz question types (true/false, multiple choice, estimate, free text).
 * Their correct answer no longer fits into the short `correct_option` (one
 * option ID): multiple choice needs several IDs, estimate target+tolerance,
 * free text a growing list of accepted answers. Hence a flexible
 * TEXT column `answer_key` (JSON, per type) — stays server-side (never sent to
 * participants before the reveal). `correct_option` remains for choice/truefalse.
 */
class Version000000Date20260717140000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $polls = $schema->getTable('pulse_polls');
        if (!$polls->hasColumn('answer_key')) {
            $polls->addColumn('answer_key', Types::TEXT, ['notnull' => false, 'default' => null]);
        }

        return $schema;
    }
}
