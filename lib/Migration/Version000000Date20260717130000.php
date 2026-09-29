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
 * Reveal timing in the quiz: by default each question is revealed on its own
 * (correct answer + leaderboard right afterwards). With `reveal_at_end=true` the
 * questions flow through without intermediate reveals; the standings only come
 * at the end of the quiz. Only a flow/display flag — no effect on votes/scoring.
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
