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
 * Initial schema for Pulse: rooms, polls and votes.
 */
class Version000000Date20260716000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── Rooms ───────────────────────────────────────────────────────────
        // The room is the stable unit of sharing: the 6-digit code stays the
        // same while the presenter switches questions.
        if (!$schema->hasTable('pulse_rooms')) {
            $table = $schema->createTable('pulse_rooms');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 6]);
            $table->addColumn('owner_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            // currently active poll (0 = none); no FKs, as usual in NC.
            $table->addColumn('active_poll_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['code'], 'pulse_rooms_code_idx');
            $table->addIndex(['owner_uid'], 'pulse_rooms_owner_idx');
        }

        // ── Polls ───────────────────────────────────────────────────────────
        if (!$schema->hasTable('pulse_polls')) {
            $table = $schema->createTable('pulse_polls');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('room_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16]); // 'choice' | 'words'
            $table->addColumn('question', Types::TEXT, ['notnull' => true]);
            // Options as JSON: [{"id":"AB12","label":"…"}]; empty for 'words'.
            $table->addColumn('options', Types::TEXT, ['notnull' => true, 'default' => '[]']);
            $table->addColumn('max_words', Types::INTEGER, ['notnull' => true, 'default' => 3]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']); // 'active' | 'ended'
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['room_id'], 'pulse_polls_room_idx');
        }

        // ── Votes ───────────────────────────────────────────────────────────
        // Core decision taken from the artifact: one row per (poll, voter token),
        // tallied via COUNT/aggregate — no shared counter that gets incremented
        // (that would swallow votes when people vote at the same time).
        // Re-vote = upsert on this UNIQUE key (last write wins per person).
        if (!$schema->hasTable('pulse_votes')) {
            $table = $schema->createTable('pulse_votes');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('poll_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('voter_token', Types::STRING, ['notnull' => true, 'length' => 32]);
            // Payload as JSON: {"value":"AB12"} for 'choice', {"value":["word",…]} for 'words'.
            $table->addColumn('payload', Types::TEXT, ['notnull' => true]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['poll_id', 'voter_token'], 'pulse_votes_unique_idx');
        }

        return $schema;
    }
}
