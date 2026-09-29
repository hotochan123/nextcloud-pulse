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
 * Quiz-Modus: Räume bekommen einen Modus ('poll' | 'quiz'), Quizfragen eine
 * richtige Antwort, ein Zeitlimit und einen Startzeitpunkt (für Countdown +
 * Tempo-Wertung). Neue Tabelle pulse_players hält je Voter-Token einen
 * Nickname pro Raum — weiterhin ohne Nextcloud-Konto.
 */
class Version000000Date20260716160000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── Räume: Modus ────────────────────────────────────────────────────
        $rooms = $schema->getTable('pulse_rooms');
        if (!$rooms->hasColumn('mode')) {
            $rooms->addColumn('mode', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'poll']);
        }

        // ── Umfragen: Quiz-Felder ───────────────────────────────────────────
        $polls = $schema->getTable('pulse_polls');
        if (!$polls->hasColumn('correct_option')) {
            // Options-ID der richtigen Antwort (null/leer außerhalb des Quiz-Modus).
            // Nullable, weil NC einen NOT-NULL-Text mit Leerstring-Default ablehnt (Oracle: '' = NULL).
            $polls->addColumn('correct_option', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => '']);
        }
        if (!$polls->hasColumn('time_limit')) {
            // Sekunden; 0 = kein Limit.
            $polls->addColumn('time_limit', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        }
        if (!$polls->hasColumn('started_at')) {
            // Unix-Zeit, wann die Frage aktiv geschaltet wurde (Basis für Countdown/Tempo).
            $polls->addColumn('started_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        }

        // ── Spieler (Quiz) ──────────────────────────────────────────────────
        // Ein Nickname je (Raum, Voter-Token). Der Token bleibt anonym; er
        // identifiziert keine Nextcloud-Person, nur denselben Browser wieder.
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
