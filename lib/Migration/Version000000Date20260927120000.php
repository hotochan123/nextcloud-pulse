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
 * Quiz im eigenen Tempo. Ein Quiz-Raum läuft entweder moderiert (`pace='live'`,
 * wie bisher: die vortragende Person schaltet Frage für Frage) oder im eigenen
 * Tempo (`pace='self'`): Jede Person spielt das Deck selbst durch, innerhalb
 * eines Fensters, das die Lehrkraft öffnet, schließt und freigibt.
 *
 * Das Fenster steckt in Zeitstempeln am Raum (0 = noch nicht passiert):
 * `opened_at` (0 = Entwurf), `closes_at` (Frist, 0 = offen bis manuell
 * geschlossen), `closed_at` (manuell geschlossen), `released_at` (Lösungen
 * freigegeben). Dazu die Einstellungen beim Öffnen (`timed`, `feedback`),
 * die Beitrittssperre `joins_locked` und `touched_at` (letzter Besuch des
 * Besitzers, damit der Aufräum-Job keine laufende Hausaufgabe löscht).
 *
 * `deck_order` friert die Reihenfolge beim Öffnen ein (JSON-Liste der
 * Poll-IDs): „Frage k von n" und „nächste Frage" kommen nur noch daraus, nie
 * aus `position`.
 *
 * `pulse_progress` hält je (Frage, Person) den persönlichen Start (die Uhr
 * läuft erst ab „weiter") und das Verlassen der Frage. Bewusst KEINE
 * Platzhalter-Stimmen in `pulse_votes`: Jede Zeile dort zählt überall als
 * Antwort (Auszählung, Rangliste, CSV, Beamer).
 *
 * Achtung: Dieses Schema ist endgültig. Sobald die Version einmal gelaufen ist
 * (App-Upgrade oder in der Entwicklung `MigrationService::executeStep()`;
 * `occ migrations:execute` gibt es nur mit `debug=true`), steht sie in der
 * Tabelle `migrations`, und auch der spätere App-Upgrade überspringt sie. Jede
 * weitere Spalten-/Indexänderung gehört in eine NEUE Version…-Klasse, sonst
 * driftet die Entwicklungs-DB still von Neuinstallationen weg.
 */
class Version000000Date20260927120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── Räume: Tempo und Fenster ────────────────────────────────────────
        $rooms = $schema->getTable('pulse_rooms');
        if (!$rooms->hasColumn('pace')) {
            // 'live' | 'self'
            $rooms->addColumn('pace', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'live']);
        }
        if (!$rooms->hasColumn('opened_at')) {
            // erstes Öffnen des Fensters (0 = Entwurf)
            $rooms->addColumn('opened_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        }
        if (!$rooms->hasColumn('closes_at')) {
            // Frist (0 = offen bis manuell geschlossen)
            $rooms->addColumn('closes_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        }
        if (!$rooms->hasColumn('closed_at')) {
            // manuell geschlossen (0 = nicht manuell geschlossen)
            $rooms->addColumn('closed_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        }
        if (!$rooms->hasColumn('released_at')) {
            // Lösungen freigegeben (0 = nicht freigegeben)
            $rooms->addColumn('released_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        }
        if (!$rooms->hasColumn('timed')) {
            // Zeitlimits + Tempopunkte im Fenster
            $rooms->addColumn('timed', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
        }
        if (!$rooms->hasColumn('feedback')) {
            // 'each' (Urteil nach jeder Frage) | 'end' (erst mit der Freigabe)
            $rooms->addColumn('feedback', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'each']);
        }
        if (!$rooms->hasColumn('deck_order')) {
            // eingefrorene Reihenfolge als JSON, z. B. [12,15,13]; null im Entwurf
            $rooms->addColumn('deck_order', Types::TEXT, ['notnull' => false, 'default' => null]);
        }
        if (!$rooms->hasColumn('joins_locked')) {
            // „Beitritt sperren": neue Tokens werden abgewiesen
            $rooms->addColumn('joins_locked', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        }
        if (!$rooms->hasColumn('touched_at')) {
            // letzter Besuch des Besitzers (nur self, stündlich gedrosselt)
            $rooms->addColumn('touched_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        }

        // ── Fortschritt je (Frage, Person) ──────────────────────────────────
        // Eine Zeile je Frage, die diese Person ERREICHT hat. Höchstens eine
        // offene Zeile (left_at = 0) je (room_id, voter_token) – das sichert der
        // Ablauf in PaceService::next, nicht die DB.
        if (!$schema->hasTable('pulse_progress')) {
            $table = $schema->createTable('pulse_progress');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('room_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('poll_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('voter_token', Types::STRING, ['notnull' => true, 'length' => 32]);
            // Index in deck_order (0-basiert); „Frage k von n" ist seq + 1
            $table->addColumn('seq', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            // persönlicher Start: die Uhr läuft ab „weiter", nicht ab dem Öffnen
            $table->addColumn('started_at', Types::BIGINT, ['notnull' => true]);
            // Frage verlassen (0 = gerade offen)
            $table->addColumn('left_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            // trägt die Idempotenz von „weiter" (Doppeltipp, zweiter Tab)
            $table->addUniqueIndex(['poll_id', 'voter_token'], 'pulse_progress_uniq_idx');
            // Zeilen einer Person; als Präfix auch alle Zeilen eines Raums
            $table->addIndex(['room_id', 'voter_token'], 'pulse_progress_tok_idx');
        }

        return $schema;
    }
}
