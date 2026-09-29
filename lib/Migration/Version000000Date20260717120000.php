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
 * Practice-run mode: a room can run as a "Practice run" — votes do not count
 * towards the leaderboard (which stays hidden), for testing questions/timing.
 * Switching empties the room (see RoomService::setPractice); the column only
 * records whether the room is currently in a practice run.
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
