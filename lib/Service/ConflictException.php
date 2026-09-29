<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

/**
 * Die Aktion passt nicht zum Zustand des Raums (z. B. Fragen bearbeiten,
 * während das Quiz im eigenen Tempo geöffnet ist). Vom Controller auf HTTP 409
 * abgebildet, nur in der Moderator-API; Eingabefehler bleiben
 * \InvalidArgumentException (400).
 */
class ConflictException extends \RuntimeException {
}
