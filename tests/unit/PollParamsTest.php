<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Controller\RoomApiController;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\RoomService;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Der Controller reicht die Frage-Felder einzeln durch — eine Liste von
 * getParam()-Aufrufen. Ein neuer Fragetyp bringt ein neues Feld mit, und wird
 * dieses in der Liste vergessen, kommt der Wert nie im DeckService an: der
 * Composer meldet dann „Feld fehlt", obwohl das Formular gefüllt war (genau so
 * passiert mit `pairs` beim Fragetyp Zuordnung).
 *
 * Der Test liest deshalb aus dem Quelltext des DeckService, welche Schlüssel er
 * aus $data zieht, und vergleicht sie mit dem, was der Controller weiterreicht.
 * Rein statisch, ohne Datenbank.
 */
#[CoversClass(RoomApiController::class)]
class PollParamsTest extends TestCase {

    /**
     * Schlüssel, die NICHT aus einem HTTP-Request stammen: sie entstehen
     * app-intern beim Duplizieren/Importieren eines Decks — oder sind Ausgabe
     * der Eigentümer-Sicht (`window`: Fenster im eigenen Tempo, roomView).
     */
    private const INTERNAL = ['answerKey', 'correctOption', 'polls', 'window'];

    /** @return list<string> Felder, die DeckService aus $data liest. */
    private function fieldsReadByDeckService(): array {
        $source = file_get_contents(dirname(__DIR__, 2) . '/lib/Service/DeckService.php');
        $this->assertNotFalse($source, 'DeckService.php nicht lesbar');
        preg_match_all("/data\['([a-zA-Z]+)'\]/", $source, $m);
        $fields = array_values(array_diff(array_unique($m[1]), self::INTERNAL));
        sort($fields);
        return $fields;
    }

    /**
     * @param 'addPoll'|'updatePoll' $method
     * @return array<string, mixed> das an den DeckService übergebene $data
     */
    private function capture(string $method): array {
        $controller = (new \ReflectionClass(RoomApiController::class))->newInstanceWithoutConstructor();

        $request = $this->createMock(IRequest::class);
        // Jedes Feld liefert seinen eigenen Namen — so ist im Ergebnis
        // sichtbar, ob wirklich der passende Parameter gelesen wurde.
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null): string => 'value:' . $key
        );

        $room = new Room();
        $roomService = $this->createMock(RoomService::class);
        $roomService->method('getOwnedRoom')->willReturn($room);

        $captured = [];
        $deckService = $this->createMock(DeckService::class);
        $deckService->method($method === 'addPoll' ? 'addPoll' : 'updatePoll')
            ->willReturnCallback(function (...$args) use (&$captured) {
                $captured = end($args);
                return new \OCA\Pulse\Db\Poll();
            });
        $deckService->method('ownerPoll')->willReturn([]);

        $this->inject($controller, 'request', $request);
        $this->inject($controller, 'userSession', $this->createMock(IUserSession::class));
        $this->inject($controller, 'roomService', $roomService);
        $this->inject($controller, 'deckService', $deckService);
        // Deck-Änderungen laufen über den Tempo-Wächter (nur im eigenen Tempo
        // unter der Raumsperre); hier ein moderierter Raum, der Mock bleibt stumm.
        $this->inject($controller, 'paceService', $this->createMock(PaceService::class));

        $method === 'addPoll'
            ? $controller->addPoll('ABC123')
            : $controller->updatePoll('ABC123', 1);

        return $captured;
    }

    private function inject(object $target, string $name, object $value): void {
        $property = new \ReflectionProperty($target, $name);
        $property->setValue($target, $value);
    }

    public function testAddPollReichtJedesFeldDurch(): void {
        $sent = array_keys($this->capture('addPoll'));
        sort($sent);
        $this->assertSame([], array_diff($this->fieldsReadByDeckService(), $sent),
            'DeckService liest Felder, die addPoll() nicht weiterreicht');
    }

    public function testUpdatePollReichtJedesFeldDurch(): void {
        $sent = array_keys($this->capture('updatePoll'));
        sort($sent);
        $this->assertSame([], array_diff($this->fieldsReadByDeckService(), $sent),
            'DeckService liest Felder, die updatePoll() nicht weiterreicht');
    }

    public function testPaareKommenAlsEigenesFeldAn(): void {
        $this->assertSame('value:pairs', $this->capture('addPoll')['pairs'] ?? null);
        $this->assertSame('value:pairs', $this->capture('updatePoll')['pairs'] ?? null);
    }
}
