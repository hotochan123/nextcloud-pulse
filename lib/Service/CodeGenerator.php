<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\RoomMapper;
use OCP\Security\ISecureRandom;

/**
 * Erzeugt die verschiedenen IDs aus einem verwechslungsarmen Alphabet
 * (ohne I/O/0/1 → beim Vorlesen/Abtippen robust):
 * - Raumcode alphanumerisch, 6 Zeichen → 32^6 ≈ 1,07 Mrd. (statt 10^6 numerisch),
 *   damit Durchprobieren des Codes teuer wird.
 * - Options-IDs ebenfalls aus diesem Alphabet.
 * - Voter-Token kryptografisch, nur intern (Cookie).
 */
class CodeGenerator {
    /** verwechslungsarm beim Vorlesen: kein I, O, 0, 1 */
    private const SAFE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Länge des Voter-Tokens; alle Token-Spalten sind varchar(32). */
    public const VOTER_TOKEN_LENGTH = 32;

    public function __construct(
        private ISecureRandom $secureRandom,
        private RoomMapper $roomMapper,
    ) {
    }

    /**
     * 6-stelliger, kollisionsfreier, alphanumerischer Raumcode (Großbuchstaben).
     */
    public function uniqueRoomCode(): string {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = $this->secureRandom->generate(6, self::SAFE_ALPHABET);
            if (!$this->roomMapper->codeExists($code)) {
                return $code;
            }
        }
        // Bei ~1 Mrd. Codes praktisch ausgeschlossen; klare Fehlermeldung statt Endlosschleife.
        throw new \RuntimeException('Could not generate a free room code.');
    }

    public function optionId(): string {
        return $this->secureRandom->generate(4, self::SAFE_ALPHABET);
    }

    public function voterToken(): string {
        return $this->secureRandom->generate(self::VOTER_TOKEN_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
    }

    /**
     * Hat ein Wert die Form, die voterToken() vergibt (32 Zeichen A–Z, a–z,
     * 0–9)? `\z` statt `$`: `$` ließe einen Zeilenumbruch am Ende durch.
     */
    public static function isVoterToken(string $s): bool {
        return preg_match('/^[A-Za-z0-9]{' . self::VOTER_TOKEN_LENGTH . '}\z/', $s) === 1;
    }
}
