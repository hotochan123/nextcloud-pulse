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
 * Room title: a freely chosen name ("Retrospective week 30") so that "My rooms"
 * stays easy to tell apart with several rooms; the 6-digit code alone says
 * nothing. Optional: an empty title keeps the previous behaviour (code only).
 *
 * The column is deliberately NULLABLE with default '': NC rejects NOT NULL text
 * columns with an empty-string default (Oracle treats '' as NULL); the same trap
 * as back then with `correct_option`. The entity normalises NULL to ''.
 */
class Version000000Date20260725120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $rooms = $schema->getTable('pulse_rooms');
        if (!$rooms->hasColumn('title')) {
            $rooms->addColumn('title', Types::STRING, [
                'notnull' => false,
                'length' => 80,
                'default' => '',
            ]);
        }

        return $schema;
    }
}
