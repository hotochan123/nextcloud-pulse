<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\AppInfo\Application;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\Room;

/**
 * Reine Bausteine der öffentlichen Sicht (Handy, Beamer, öffentliche API):
 * undurchsichtige Versionen und die spoilerfreie Frage. Statisch und ohne DI,
 * damit moderierter und selbstgetakteter Pfad dieselbe Rechenvorschrift
 * benutzen und die Reflection-Tests nichts zusätzlich einspritzen müssen.
 * Das Instanz-Geheimnis reicht der Aufrufer herein.
 */
class PublicView {
    /**
     * Der Fingerabdruck geht an jedes Handy und an den Beamer, also nur als
     * undurchsichtiger Schlüssel-Hash. Roh verriete er die Lösung: ein CRC32
     * über den answerKey lässt sich bei bekannten Options-IDs durchprobieren
     * (Reihenfolge n!, Mehrfachauswahl 2^n, Zahl, Freitext-Wörterbuch), und der
     * Stimmen-Stempel ist ein CRC über alle Payloads samt correct/points. Die
     * Clients vergleichen die Version nur auf Gleichheit.
     *
     * Die Protokollnummer steht mit im Hash: ein Sprung ändert jede Version,
     * sodass kein Client mit einem 204 auf dem alten Zustand sitzen bleibt.
     */
    public static function opaque(string $raw, string $secret): string {
        return substr(hash_hmac('sha256', 'v' . Application::PROTOCOL . ':' . $raw, 'pulse-state:' . $secret), 0, 24);
    }

    /**
     * Frage für die öffentliche Sicht serialisieren, mit `revealed` — ob die
     * Frage für den Saal aufgelöst ist. Handy und Beamer richten ihre Ansicht
     * danach, nicht nach dem Status: bei „Auflösung am Ende" ist 'locked' NICHT
     * aufgelöst (entsteht, wenn der Schalter nach dem Auflösen umgelegt wird).
     *
     * Im Quiz ist bei „Reihenfolge" die gespeicherte Optionsfolge die Lösung und
     * bei „Zuordnung" steht Ziel i neben Item i — beides darf vor dem Auflösen
     * weder auf dem Beamer noch in der API so ankommen. Gemischt wird über einen
     * Schlüssel-Hash (Server-Geheimnis + Frage + Option): für alle Betrachter und
     * jeden Poll gleich (der Beamer springt nicht) und unabhängig von der
     * Lösung. Dass er sie gelegentlich trifft (1/n!), verrät nichts. Jede Regel
     * „nie gleich der Lösung" dagegen verriete sie: bei zwei Optionen wäre die
     * Anzeige immer genau die Umkehrung.
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
