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
 * Self-paced quiz. A quiz room runs either moderated (`pace='live'`,
 * as before: the presenter advances question by question) or self-paced
 * (`pace='self'`): every person plays through the deck on their own, within
 * a window that the teacher opens, closes and releases.
 *
 * The window is stored as timestamps on the room (0 = has not happened yet):
 * `opened_at` (0 = draft), `closes_at` (deadline, 0 = open until closed
 * manually), `closed_at` (closed manually), `released_at` (solutions
 * released). Plus the settings chosen when opening (`timed`, `feedback`),
 * the join lock `joins_locked` and `touched_at` (the owner's last visit,
 * so that the cleanup job does not delete a running homework assignment).
 *
 * `deck_order` freezes the order when the window opens (JSON list of
 * poll IDs): "question k of n" and "next question" come only from it, never
 * from `position`.
 *
 * `pulse_progress` holds, per (question, person), the personal start (the clock
 * only runs from "Next") and when the question was left. Deliberately NO
 * placeholder votes in `pulse_votes`: every row there counts everywhere as an
 * answer (tally, leaderboard, CSV, projector).
 *
 * Caution: this schema is final. Once this version has run
 * (app upgrade or, during development, `MigrationService::executeStep()`;
 * `occ migrations:execute` only exists with `debug=true`), it is recorded in the
 * `migrations` table, and the later app upgrade skips it as well. Every
 * further column/index change belongs in a NEW Version… class, otherwise
 * the development DB silently drifts away from fresh installations.
 */
class Version000000Date20260927120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── Rooms: pace and window ──────────────────────────────────────────
        $rooms = $schema->getTable('pulse_rooms');
        if (!$rooms->hasColumn('pace')) {
            // 'live' | 'self'
            $rooms->addColumn('pace', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'live']);
        }
        if (!$rooms->hasColumn('opened_at')) {
            // first opening of the window (0 = draft)
            $rooms->addColumn('opened_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        }
        if (!$rooms->hasColumn('closes_at')) {
            // deadline (0 = open until closed manually)
            $rooms->addColumn('closes_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        }
        if (!$rooms->hasColumn('closed_at')) {
            // closed manually (0 = not closed manually)
            $rooms->addColumn('closed_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        }
        if (!$rooms->hasColumn('released_at')) {
            // solutions released (0 = not released)
            $rooms->addColumn('released_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        }
        if (!$rooms->hasColumn('timed')) {
            // time limits + speed points in the window
            $rooms->addColumn('timed', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
        }
        if (!$rooms->hasColumn('feedback')) {
            // 'each' (verdict after every question) | 'end' (only with the release)
            $rooms->addColumn('feedback', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'each']);
        }
        if (!$rooms->hasColumn('deck_order')) {
            // frozen order as JSON, e.g. [12,15,13]; null while in draft
            $rooms->addColumn('deck_order', Types::TEXT, ['notnull' => false, 'default' => null]);
        }
        if (!$rooms->hasColumn('joins_locked')) {
            // "Lock joining": new tokens are rejected
            $rooms->addColumn('joins_locked', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        }
        if (!$rooms->hasColumn('touched_at')) {
            // owner's last visit (self only, throttled to hourly)
            $rooms->addColumn('touched_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        }

        // ── Progress per (question, person) ─────────────────────────────────
        // One row per question this person has REACHED. At most one
        // open row (left_at = 0) per (room_id, voter_token) – the flow in
        // PaceService::next guarantees that, not the DB.
        if (!$schema->hasTable('pulse_progress')) {
            $table = $schema->createTable('pulse_progress');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('room_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('poll_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('voter_token', Types::STRING, ['notnull' => true, 'length' => 32]);
            // index in deck_order (0-based); "question k of n" is seq + 1
            $table->addColumn('seq', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            // personal start: the clock runs from "Next", not from the opening
            $table->addColumn('started_at', Types::BIGINT, ['notnull' => true]);
            // question left (0 = currently open)
            $table->addColumn('left_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            // carries the idempotency of "Next" (double tap, second tab)
            $table->addUniqueIndex(['poll_id', 'voter_token'], 'pulse_progress_uniq_idx');
            // rows of one person; as a prefix also all rows of a room
            $table->addIndex(['room_id', 'voter_token'], 'pulse_progress_tok_idx');
        }

        return $schema;
    }
}
