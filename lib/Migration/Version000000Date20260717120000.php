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
 * Probelauf-Modus: ein Raum kann als „Probelauf" laufen — Stimmen zählen nicht
 * in die Rangliste (die bleibt ausgeblendet), zum Testen von Fragen/Timing. Das
 * Umschalten leert den Raum (siehe RoomService::setPractice), die Spalte hält
 * nur, ob der Raum gerade im Probelauf ist.
 */
class Version000000Date20260717120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $rooms = $schema->getTable('pulse_rooms');
        if (!$rooms->hasColumn('practice')) {
            $rooms->addColumn('practice', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        }

        return $schema;
    }
}
