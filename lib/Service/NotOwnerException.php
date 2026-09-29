<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

/**
 * Ein Raum wurde von jemandem angesprochen, dem er nicht gehört.
 * Vom Controller auf HTTP 403 abgebildet.
 */
class NotOwnerException extends \RuntimeException {
}
