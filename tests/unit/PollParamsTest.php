<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Controller\RoomApiController;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\RoomService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The controller passes the question fields through one by one — a list of
 * getParam() calls. A new question type brings a new field, and if that
 * field is forgotten in the list, the value never reaches DeckService: the
 * composer then reports a missing field although the form was filled in (exactly
 * what happened with `pairs` for the matching question type).
 *
 * So the test reads from the DeckService source code which keys it
 * takes from $data and compares them with what the controller passes on.
 * Purely static, no database.
 */
#[CoversClass(RoomApiController::class)]
class PollParamsTest extends TestCase {

    /**
     * Keys that do NOT come from an HTTP request: they are created
     * app-internally when duplicating/importing a deck — or are output
     * of the owner view (`window`: self-paced window, roomView).
     */
    private const INTERNAL = ['answerKey', 'correctOption', 'polls', 'window'];

    /** @return list<string> fields that DeckService reads from $data. */
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
     * @return array<string, mixed> the $data passed to DeckService
     */
    private function capture(string $method): array {
        $controller = (new \ReflectionClass(RoomApiController::class))->newInstanceWithoutConstructor();

        $request = $this->createMock(IRequest::class);
        // Every field returns its own name — so the result shows
        // whether the matching parameter was really read.
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
        // Deck changes go through the pace guard (under the room lock only when
        // self-paced); this is a moderated room, the mock stays silent.
        $this->inject($controller, 'paceService', $this->createMock(PaceService::class));
        // Every room lookup records owner activity (retention); silent here.
        $this->inject($controller, 'roomMapper', $this->createMock(RoomMapper::class));
        $this->inject($controller, 'timeFactory', $this->createMock(ITimeFactory::class));

        $method === 'addPoll'
            ? $controller->addPoll('ABC123')
            : $controller->updatePoll('ABC123', 1);

        return $captured;
    }

    private function inject(object $target, string $name, object $value): void {
        $property = new \ReflectionProperty($target, $name);
        $property->setValue($target, $value);
    }

    public function testAddPollPassesEveryFieldThrough(): void {
        $sent = array_keys($this->capture('addPoll'));
        sort($sent);
        $this->assertSame([], array_diff($this->fieldsReadByDeckService(), $sent),
            'DeckService liest Felder, die addPoll() nicht weiterreicht');
    }

    public function testUpdatePollPassesEveryFieldThrough(): void {
        $sent = array_keys($this->capture('updatePoll'));
        sort($sent);
        $this->assertSame([], array_diff($this->fieldsReadByDeckService(), $sent),
            'DeckService liest Felder, die updatePoll() nicht weiterreicht');
    }

    public function testPairsArriveAsTheirOwnField(): void {
        $this->assertSame('value:pairs', $this->capture('addPoll')['pairs'] ?? null);
        $this->assertSame('value:pairs', $this->capture('updatePoll')['pairs'] ?? null);
    }
}
