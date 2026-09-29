<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

/**
 * Der Raum wurde gelöscht, während die Anfrage unterwegs war (anderer Tab,
 * Aufräum-Job): geladen war er noch, beim Sperren (PaceService::locked) gibt
 * es die Zeile nicht mehr. Von den Controllern auf HTTP 404 „Room not found."
 * abgebildet — dieselbe Antwort wie ein paar Millisekunden später.
 */
class RoomGoneException extends \RuntimeException {
}
