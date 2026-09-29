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
 * Image for a question. The column holds ONLY the file name inside the
 * app data folder (`<token>.<ext>`), not the image itself — the file lives in
 * Nextcloud's AppData area and is served through a dedicated endpoint.
 * The token in the name doubles as a cache buster when an
 * image is replaced.
 *
 * Column nullable with default '' — NC rejects NOT NULL text columns with an
 * empty-string default (Oracle treats '' like NULL).
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
