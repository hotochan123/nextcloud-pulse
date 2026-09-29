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
 * Auflösungs-Zeitpunkt im Quiz: Standard löst jede Frage einzeln auf (richtige
 * Antwort + Rangliste direkt danach). Mit `reveal_at_end=true` fließen die Fragen
 * ohne Zwischen-Auflösung durch; erst am Quiz-Ende gibt es den Stand. Nur ein
 * Ablauf-/Anzeige-Flag — kein Einfluss auf Stimmen/Wertung.
 */
class Version000000Date20260717130000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $rooms = $schema->getTable('pulse_rooms');
        if (!$rooms->hasColumn('reveal_at_end')) {
            $rooms->addColumn('reveal_at_end', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        }

        return $schema;
    }
}
