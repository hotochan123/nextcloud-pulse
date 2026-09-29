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
 * Raum-Titel: frei wählbarer Name („Retrospektive KW 30"), damit „Meine Räume"
 * bei mehreren Räumen unterscheidbar bleibt — der 6-stellige Code allein sagt
 * nichts. Optional: leerer Titel = bisheriges Verhalten (nur Code).
 *
 * Spalte bewusst NULLABLE mit Default '' — NC lehnt NOT-NULL-Textspalten mit
 * Leerstring-Default ab (Oracle behandelt '' wie NULL); dieselbe Falle wie
 * seinerzeit bei `correct_option`. Die Entity normalisiert NULL zu ''.
 */
class Version000000Date20260725120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $rooms = $schema->getTable('pulse_rooms');
        if (!$rooms->hasColumn('title')) {
            $rooms->addColumn('title', Types::STRING, [
                'notnull' => false,
                'length' => 80,
                'default' => '',
            ]);
        }

        return $schema;
    }
}
