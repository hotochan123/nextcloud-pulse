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
 * Explanation for a quiz question: plain text (line breaks allowed, at most
 * DeckService::EXPLANATION_MAX characters) that the reveal shows together
 * with the correct answer — "it is the right answer because …". Like the
 * solution, it never reaches participants before the reveal.
 *
 * TEXT, nullable, no default: NULL = no explanation. Doctrine drops defaults
 * of TEXT columns on MySQL anyway (see Poll::__construct), and a nullable
 * column needs none — an INSERT without it stores NULL. Existing questions
 * keep NULL, so nothing changes for them.
 *
 * Caution: once this version has run it is recorded in the `migrations`
 * table. Every further column change belongs in a NEW Version… class.
 */
class Version000000Date20261103120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $polls = $schema->getTable('pulse_polls');
        if (!$polls->hasColumn('explanation')) {
            $polls->addColumn('explanation', Types::TEXT, [
                'notnull' => false,
            ]);
            return $schema;
        }
        return null;
    }
}
