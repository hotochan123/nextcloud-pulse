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
 * Deck feature: polls get a position (ordered list of questions per room).
 * The cursor stays room.active_poll_id.
 */
class Version000000Date20260716120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('pulse_polls')) {
            $table = $schema->getTable('pulse_polls');
            if (!$table->hasColumn('position')) {
                $table->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            }
            if (!$table->hasIndex('pulse_polls_pos_idx')) {
                $table->addIndex(['room_id', 'position'], 'pulse_polls_pos_idx');
            }
        }

        return $schema;
    }
}
