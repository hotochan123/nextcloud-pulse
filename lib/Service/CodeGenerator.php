<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\RoomMapper;
use OCP\Security\ISecureRandom;

/**
 * Generates the various IDs from an alphabet that is hard to confuse
 * (no I/O/0/1 → robust when read aloud or typed in):
 * - Room code alphanumeric, 6 characters → 32^6 ≈ 1.07 billion (instead of 10^6 numeric),
 *   so that brute-forcing the code becomes expensive.
 * - Option IDs from the same alphabet.
 * - Voter token cryptographic, internal only (cookie).
 */
class CodeGenerator {
    /** hard to confuse when read aloud: no I, O, 0, 1 */
    private const SAFE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Length of the voter token; all token columns are varchar(32). */
    public const VOTER_TOKEN_LENGTH = 32;

    public function __construct(
        private ISecureRandom $secureRandom,
        private RoomMapper $roomMapper,
    ) {
    }

    /**
     * 6-character, collision-free, alphanumeric room code (upper case).
     */
    public function uniqueRoomCode(): string {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = $this->secureRandom->generate(6, self::SAFE_ALPHABET);
            if (!$this->roomMapper->codeExists($code)) {
                return $code;
            }
        }
        // Practically impossible with ~1 billion codes; a clear error instead of an endless loop.
        throw new \RuntimeException('Could not generate a free room code.');
    }

    public function optionId(): string {
        return $this->secureRandom->generate(4, self::SAFE_ALPHABET);
    }

    public function voterToken(): string {
        return $this->secureRandom->generate(self::VOTER_TOKEN_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
    }

    /**
     * Does a value have the shape that voterToken() hands out (32 characters A–Z, a–z,
     * 0–9)? `\z` instead of `$`: `$` would let a trailing newline through.
     */
    public static function isVoterToken(string $s): bool {
        return preg_match('/^[A-Za-z0-9]{' . self::VOTER_TOKEN_LENGTH . '}\z/', $s) === 1;
    }
}
