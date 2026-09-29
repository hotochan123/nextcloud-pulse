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
 * Live-Präsenz: wie viele anonyme Teilnehmer sind gerade im Raum.
 *
 * Eine Zeile pro (Raum, Voter-Token). Der Heartbeat aktualisiert last_seen bei
 * jedem Teilnehmer-Poll (~2,5 s); "gerade dabei" = last_seen innerhalb eines
 * kleinen Fensters. UNIQUE(room_id, voter_token) wie bei den Stimmen: ein
 * Upsert pro Person, kein gemeinsamer Zähler.
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
            // Zählabfrage: WHERE room_id = ? AND last_seen >= ?
            $table->addIndex(['room_id', 'last_seen'], 'pulse_presence_active_idx');
        }

        return $schema;
    }
}
