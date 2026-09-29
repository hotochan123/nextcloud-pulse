<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\AppInfo\Application;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\Room;

/**
 * Pure building blocks of the public view (phone, projector, public API):
 * opaque versions and the spoiler-free question. Static and without DI, so
 * that the moderated and the self-paced path use the same computation and
 * the reflection tests do not have to inject anything extra.
 * The caller passes in the instance secret.
 */
class PublicView {
    /**
     * The fingerprint goes to every phone and to the projector, so only as an
     * opaque keyed hash. Raw, it would give away the solution: a CRC32 over the
     * answerKey can be brute-forced when the option IDs are known (ordering n!,
     * multiple choice 2^n, number, free-text dictionary), and the vote stamp is
     * a CRC over all payloads including correct/points. The clients only
     * compare the version for equality.
     *
     * The protocol number is part of the hash: bumping it changes every version,
     * so no client stays stuck on the old state with a 204.
     */
    public static function opaque(string $raw, string $secret): string {
        return substr(hash_hmac('sha256', 'v' . Application::PROTOCOL . ':' . $raw, 'pulse-state:' . $secret), 0, 24);
    }

    /**
     * Serialise the question for the public view, with `revealed` — whether the
     * question has been revealed to the room. Phone and projector base their view
     * on this, not on the status: with "Reveal at the end", 'locked' is NOT
     * revealed (happens when the switch is flipped after revealing).
     *
     * In a quiz, for "Ranking" the stored option order is the solution, and for
     * "Matching" target i sits next to item i — neither may reach the projector or
     * the API like that before the reveal. Shuffling uses a keyed hash (server
     * secret + question + option): the same for every viewer and every poll
     * (the projector does not jump) and independent of the solution. That it
     * occasionally matches it (1/n!) gives nothing away. Any rule "never equal
     * to the solution", on the other hand, would give it away: with two options
     * the display would always be exactly the reverse.
     */
    public static function poll(Room $room, Poll $poll, bool $revealed, string $secret): array {
        $data = $poll->jsonSerialize();
        $data['revealed'] = $revealed;
        if ($room->getMode() !== 'quiz' || $revealed) {
            return $data;
        }
        $key = 'pulse-order:' . $secret;
        $prefix = $poll->getId() . ':';
        $shuffle = static function (array $list) use ($key, $prefix): array {
            $keyed = [];
            foreach ($list as $item) {
                $keyed[] = [hash_hmac('sha256', $prefix . (string)($item['id'] ?? ''), $key), $item];
            }
            usort($keyed, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
            return array_column($keyed, 1);
        };
        if ($poll->getType() === 'rank') {
            $data['options'] = $shuffle($data['options']);
        } elseif ($poll->getType() === 'match' && is_array($data['match'])) {
            $data['match']['targets'] = $shuffle($data['match']['targets']);
        }
        return $data;
    }
}
