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
 * Live presence: how many anonymous participants are in the room right now.
 *
 * One row per (room, voter token). The heartbeat updates last_seen on every
 * participant poll (~2.5 s); "currently here" = last_seen within a
 * small window. UNIQUE(room_id, voter_token) as with the votes: one
 * upsert per person, no shared counter.
 */
class Version000000Date20260716140000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('pulse_presence')) {
            $table = $schema->createTable('pulse_presence');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('room_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('voter_token', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('last_seen', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['room_id', 'voter_token'], 'pulse_presence_unique_idx');
            // Count query: WHERE room_id = ? AND last_seen >= ?
            $table->addIndex(['room_id', 'last_seen'], 'pulse_presence_active_idx');
        }

        return $schema;
    }
}
