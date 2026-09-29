<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

/**
 * The room was deleted while the request was in flight (another tab, a
 * cleanup job): it was still loaded, but by the time it is locked
 * (PaceService::locked) its row is gone. The controllers map this to
 * HTTP 404 "Room not found." — the same answer as a few milliseconds later.
 */
class RoomGoneException extends \RuntimeException {
}
