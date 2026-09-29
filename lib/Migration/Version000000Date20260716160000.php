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
 * Quiz mode: rooms get a mode ('poll' | 'quiz'), quiz questions get a
 * correct answer, a time limit and a start time (for the countdown +
 * speed scoring). The new table pulse_players holds one nickname per
 * voter token and room — still without a Nextcloud account.
 */
class Version000000Date20260716160000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── Rooms: mode ─────────────────────────────────────────────────────
        $rooms = $schema->getTable('pulse_rooms');
        if (!$rooms->hasColumn('mode')) {
            $rooms->addColumn('mode', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'poll']);
        }

        // ── Polls: quiz fields ──────────────────────────────────────────────
        $polls = $schema->getTable('pulse_polls');
        if (!$polls->hasColumn('correct_option')) {
            // Option ID of the correct answer (null/empty outside quiz mode).
            // Nullable, because NC rejects a NOT NULL text column with an empty-string default (Oracle: '' = NULL).
            $polls->addColumn('correct_option', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => '']);
        }
        if (!$polls->hasColumn('time_limit')) {
            // Seconds; 0 = no limit.
            $polls->addColumn('time_limit', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        }
        if (!$polls->hasColumn('started_at')) {
            // Unix time when the question was made active (basis for countdown/speed).
            $polls->addColumn('started_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        }

        // ── Players (quiz) ──────────────────────────────────────────────────
        // One nickname per (room, voter token). The token stays anonymous; it
        // identifies no Nextcloud user, it only recognises the same browser again.
        if (!$schema->hasTable('pulse_players')) {
            $table = $schema->createTable('pulse_players');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('room_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('voter_token', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('nickname', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['room_id', 'voter_token'], 'pulse_players_uniq_idx');
            $table->addIndex(['room_id'], 'pulse_players_room_idx');
        }

        return $schema;
    }
}
