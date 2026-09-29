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
 * Bild zur Frage. In der Spalte steht NUR der Dateiname innerhalb des
 * App-Datenordners (`<token>.<ext>`), nicht das Bild selbst — die Datei liegt im
 * AppData-Bereich von Nextcloud und wird über einen eigenen Endpunkt
 * ausgeliefert. Der Token im Namen wirkt zugleich als Cache-Buster, wenn ein
 * Bild ersetzt wird.
 *
 * Spalte nullable mit Default '' — NC lehnt NOT-NULL-Textspalten mit
 * Leerstring-Default ab (Oracle behandelt '' wie NULL).
 */
class Version000000Date20260726120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $polls = $schema->getTable('pulse_polls');
        if (!$polls->hasColumn('image')) {
            $polls->addColumn('image', Types::STRING, [
                'notnull' => false,
                'length' => 64,
                'default' => '',
            ]);
        }

        return $schema;
    }
}
