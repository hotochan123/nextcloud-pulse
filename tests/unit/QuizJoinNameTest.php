<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Player;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Joining a quiz: names are unique per room, case-insensitively.
 *
 * Otherwise there are two "Anna"s in the leaderboard and nobody knows which row is
 * their own. One's own token may keep its name or change its spelling —
 * someone called "anna" who corrects it to "Anna" does not collide with themselves.
 */
#[CoversClass(VoteService::class)]
class QuizJoinNameTest extends TestCase {

    private const TAKEN = 'This name is already taken. Please choose another one.';

    private PlayerMapper&MockObject $players;
    private VoteService $service;

    protected function setUp(): void {
        $this->players = $this->createMock(PlayerMapper::class);
        $this->players->method('findByRoom')->willReturn([
            $this->player('tok-anna', 'Anna'),
            $this->player('tok-oezil', 'Özil'),
        ]);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1000);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        // Joining runs under the room lock (here: straight through) and looks
        // at the room's questions for the name freeze (none here).
        $pace = $this->createMock(PaceService::class);
        $pace->method('locked')->willReturnCallback(static fn (Room $room, callable $fn): mixed => $fn($room));
        $polls = $this->createMock(PollMapper::class);
        $polls->method('findByRoom')->willReturn([]);
        $votes = $this->createMock(VoteMapper::class);
        $votes->method('findByPolls')->willReturn([]);

        $this->service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        foreach ([
            'playerMapper' => $this->players,
            'pollMapper' => $polls,
            'voteMapper' => $votes,
            'paceService' => $pace,
            'limits' => new \OCA\Pulse\Service\Limits($this->createMock(\OCP\IAppConfig::class)),
            'timeFactory' => $time,
            'l10n' => $l10n,
        ] as $name => $value) {
            (new ReflectionProperty(VoteService::class, $name))->setValue($this->service, $value);
        }
    }

    public static function vergebeneNamen(): array {
        return [
            'gleich geschrieben' => ['Anna'],
            'mit Leerraum und klein' => [' anna '],
            'ganz groß' => ['ANNA'],
            'Umlaut groß' => ['ÖZIL'],
        ];
    }

    #[DataProvider('vergebeneNamen')]
    public function testNameEinesAnderenTokensWirdAbgelehnt(string $name): void {
        $this->players->expects($this->never())->method('register');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(self::TAKEN);
        $this->service->quizJoin($this->room(), 'tok-neu', $name);
    }

    public function testEigenesTokenDarfDenNamenUmschreiben(): void {
        $this->players->expects($this->once())->method('register')
            ->with(1, 'tok-anna', 'ANNA', 1000)
            ->willReturn($this->player('tok-anna', 'ANNA'));

        $this->assertSame('ANNA', $this->service->quizJoin($this->room(), 'tok-anna', 'ANNA')->getNickname());
    }

    public function testEigenesTokenDarfDenNamenBehalten(): void {
        $this->players->expects($this->once())->method('register')
            ->with(1, 'tok-anna', 'Anna', 1000)
            ->willReturn($this->player('tok-anna', 'Anna'));

        $this->service->quizJoin($this->room(), 'tok-anna', 'Anna');
    }

    public function testFreierNameWirdBereinigtRegistriert(): void {
        // The check runs on the cleaned-up name — and exactly that one is stored.
        $this->players->expects($this->once())->method('register')
            ->with(1, 'tok-neu', 'Anna Lena', 1000)
            ->willReturn($this->player('tok-neu', 'Anna Lena'));

        $this->service->quizJoin($this->room(), 'tok-neu', "  Anna \t Lena ");
    }

    public function testUmbenennenAufVergebenenNamenWirdAbgelehnt(): void {
        // Renaming is joining too: Özil must not become "anna".
        $this->players->expects($this->never())->method('register');

        $this->expectExceptionMessage(self::TAKEN);
        $this->service->quizJoin($this->room(), 'tok-oezil', 'anna');
    }

    public function testMeldungIstUebersetzt(): void {
        $de = json_decode((string)file_get_contents(__DIR__ . '/../../l10n/de.json'), true);

        $this->assertArrayHasKey(self::TAKEN, $de['translations'] ?? []);
    }

    private function room(): Room {
        $room = new Room();
        $room->setId(1);
        $room->setMode('quiz');
        return $room;
    }

    private function player(string $token, string $nickname): Player {
        $player = new Player();
        $player->setRoomId(1);
        $player->setVoterToken($token);
        $player->setNickname($nickname);
        return $player;
    }
}
