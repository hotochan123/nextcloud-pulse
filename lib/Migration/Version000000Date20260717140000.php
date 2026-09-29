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
 * Weitere Quiz-Fragetypen (Wahr/Falsch, Mehrfachauswahl, Schätzfrage, Freitext).
 * Deren korrekte Antwort passt nicht mehr in das kurze `correct_option` (eine
 * Options-ID): Mehrfachauswahl braucht mehrere IDs, Schätzfrage Ziel+Toleranz,
 * Freitext eine wachsende Liste akzeptierter Antworten. Dafür eine flexible
 * TEXT-Spalte `answer_key` (JSON, je Typ) — bleibt serverseitig (nie an
 * Teilnehmer vor dem Auflösen). `correct_option` bleibt für choice/truefalse.
 */
class Version000000Date20260717140000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $polls = $schema->getTable('pulse_polls');
        if (!$polls->hasColumn('answer_key')) {
            $polls->addColumn('answer_key', Types::TEXT, ['notnull' => false, 'default' => null]);
        }

        return $schema;
    }
}
