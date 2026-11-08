<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

/**
 * An AI task cannot start or be read (AiDraftService, AiSummaryService,
 * AiPolicy); the message is translated and shown on the desk, the code is
 * the HTTP status, $data goes into the JSON answer next to the message
 * (notice: true = the AI notice is not confirmed yet).
 */
class AiDraftException extends \RuntimeException {
    /** @param array<string, mixed> $data */
    public function __construct(string $message, private int $status, private array $data = []) {
        parent::__construct($message);
    }

    public function status(): int {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function data(): array {
        return $this->data;
    }
}
