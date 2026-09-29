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
 * Ausgangsschema für Pulse: Räume, Umfragen und Stimmen.
 */
class Version000000Date20260716000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── Räume ───────────────────────────────────────────────────────────
        // Der Raum ist die stabile Freigabe-Einheit: Der 6-stellige Code bleibt
        // gleich, während die vortragende Person Fragen wechselt.
        if (!$schema->hasTable('pulse_rooms')) {
            $table = $schema->createTable('pulse_rooms');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 6]);
            $table->addColumn('owner_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            // aktuell aktive Umfrage (0 = keine); FK-frei, wie in NC üblich.
            $table->addColumn('active_poll_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['code'], 'pulse_rooms_code_idx');
            $table->addIndex(['owner_uid'], 'pulse_rooms_owner_idx');
        }

        // ── Umfragen ────────────────────────────────────────────────────────
        if (!$schema->hasTable('pulse_polls')) {
            $table = $schema->createTable('pulse_polls');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('room_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16]); // 'choice' | 'words'
            $table->addColumn('question', Types::TEXT, ['notnull' => true]);
            // Optionen als JSON: [{"id":"AB12","label":"…"}]; bei 'words' leer.
            $table->addColumn('options', Types::TEXT, ['notnull' => true, 'default' => '[]']);
            $table->addColumn('max_words', Types::INTEGER, ['notnull' => true, 'default' => 3]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']); // 'active' | 'ended'
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['room_id'], 'pulse_polls_room_idx');
        }

        // ── Stimmen ─────────────────────────────────────────────────────────
        // Kernentscheidung aus dem Artefakt: eine Zeile pro (Umfrage, Voter-Token),
        // ausgezählt per COUNT/Aggregat — kein gemeinsam hochgezählter Zähler
        // (der würde bei gleichzeitigem Abstimmen Stimmen verschlucken).
        // Re-Vote = Upsert auf diesen UNIQUE-Schlüssel (last-write-wins pro Person).
        if (!$schema->hasTable('pulse_votes')) {
            $table = $schema->createTable('pulse_votes');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('poll_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('voter_token', Types::STRING, ['notnull' => true, 'length' => 32]);
            // Payload als JSON: {"value":"AB12"} bei 'choice', {"value":["wort",…]} bei 'words'.
            $table->addColumn('payload', Types::TEXT, ['notnull' => true]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['poll_id', 'voter_token'], 'pulse_votes_unique_idx');
        }

        return $schema;
    }
}
