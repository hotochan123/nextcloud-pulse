<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Controller\PublicVoteController;
use OCA\Pulse\Controller\RoomApiController;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Service\CodeGenerator;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\DemoService;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\PaceStateService;
use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\RoomGoneException;
use OCA\Pulse\Service\RoomService;
use OCA\Pulse\Service\StateService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\RateLimiting\ILimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Room deleted while the request was in flight: it was still loaded, but by
 * the time of locking (PaceService::locked) it no longer exists. That is the
 * same situation as a few milliseconds later — so 404 "Room not found.",
 * not 500. Publicly without a brute-force attempt: the code was right when the
 * request came in (only a miss at the lookup counts, PublicCodeThrottleTest).
 */
#[CoversClass(RoomApiController::class)]
#[CoversClass(PublicVoteController::class)]
class RoomGoneTest extends TestCase {

    private IRequest&MockObject $request;
    private IL10N&MockObject $l10n;
    private PaceService&MockObject $pace;
    private VoteService&MockObject $votes;

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => match ($key) {
            'action' => 'close',
            'answer' => 'Saturn',
            'correct' => true,
            'nickname' => 'Anna',
            'value' => 'AA',
            'pollId' => 11,
            'after' => 0,
            default => $default,
        });
        // Cookie in the form CodeGenerator::voterToken hands out — otherwise it counts as none
        $this->request->method('getCookie')->willReturn(str_repeat('Anna', 8));
        $this->request->method('getRemoteAddress')->willReturn('192.0.2.1');
        // Like the public bundle (@nextcloud/axios): participant POSTs need it (PublicCrossSiteTest).
        $this->request->method('getHeader')->willReturnCallback(fn (string $name): string => $name === 'X-Requested-With' ? 'XMLHttpRequest' : '');
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnArgument(0);
        $this->pace = $this->createMock(PaceService::class);
        $this->votes = $this->createMock(VoteService::class);
    }

    // ── Moderator ──────────────────────────────────────────────────────────

    public function testPaceAktionAufGeloeschtemRaum(): void {
        $this->pace->method('closeWindow')->willThrowException(new RoomGoneException());

        $this->assertNotFound($this->moderator()->pace('ABCDEF'));
    }

    public function testBewertenAufGeloeschtemRaum(): void {
        $this->votes->method('gradeTextAnswer')->willThrowException(new RoomGoneException());

        $this->assertNotFound($this->moderator()->gradeAnswer('ABCDEF', 13));
    }

    public function testDeckAenderungAufGeloeschtemRaum(): void {
        // deckChange -> paced -> locked: the room is already gone.
        $this->pace->method('locked')->willThrowException(new RoomGoneException());

        $this->assertNotFound($this->moderator()->deletePoll('ABCDEF', 13));
    }

    // ── Public ─────────────────────────────────────────────────────────────

    public function testBeitrittAufGeloeschtemRaum(): void {
        $this->votes->method('quizJoin')->willThrowException(new RoomGoneException());

        $this->assertUnthrottledNotFound($this->public()->join('ABCDEF'));
    }

    public function testStimmeAufGeloeschtemRaum(): void {
        $this->votes->method('recordVote')->willThrowException(new RoomGoneException());

        $this->assertUnthrottledNotFound($this->public()->vote('ABCDEF'));
    }

    public function testWeiterAufGeloeschtemRaum(): void {
        $this->pace->method('next')->willThrowException(new RoomGoneException());

        $this->assertUnthrottledNotFound($this->public()->next('ABCDEF'));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function assertNotFound(JSONResponse $response): void {
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(['message' => 'Room not found.'], $response->getData());
    }

    private function assertUnthrottledNotFound(JSONResponse $response): void {
        $this->assertNotFound($response);
        $this->assertFalse($response->isThrottled());
    }

    private function moderator(): RoomApiController {
        $rooms = $this->createMock(RoomService::class);
        $rooms->method('getOwnedRoom')->willReturnCallback(fn (): Room => $this->room());
        return new RoomApiController(
            $this->request,
            $this->createMock(IUserSession::class),
            $rooms,
            $this->createMock(DeckService::class),
            $this->createMock(StateService::class),
            $this->votes,
            $this->createMock(DemoService::class),
            $this->createMock(PollImageService::class),
            $this->l10n,
            $this->pace,
            $this->createMock(PaceStateService::class),
            $this->createMock(RoomMapper::class),
            $this->createMock(ITimeFactory::class),
            $this->createMock(ILimiter::class),
            new \OCA\Pulse\Service\Limits($this->createMock(\OCP\IAppConfig::class)),
        );
    }

    private function public(): PublicVoteController {
        $mapper = $this->createMock(RoomMapper::class);
        $mapper->method('findByCode')->willReturnCallback(fn (): Room => $this->room());
        return new PublicVoteController(
            $this->request,
            $mapper,
            $this->createMock(RoomService::class),
            $this->createMock(StateService::class),
            $this->votes,
            $this->createMock(DeckService::class),
            $this->createMock(PollImageService::class),
            $this->createMock(CodeGenerator::class),
            $this->createMock(ITimeFactory::class),
            $this->l10n,
            $this->pace,
            $this->createMock(ILimiter::class),
            $this->neverThrottled(),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IAppConfig::class),
            new \OCA\Pulse\Service\Limits($this->createMock(\OCP\IAppConfig::class)),
        );
    }

    /** The room was found: no brute-force attempt, whatever happens afterwards. */
    private function neverThrottled(): \OCP\Security\Bruteforce\IThrottler {
        $throttler = $this->createMock(\OCP\Security\Bruteforce\IThrottler::class);
        $throttler->expects($this->never())->method('registerAttempt');
        $throttler->expects($this->never())->method('sleepDelayOrThrowOnMax');
        return $throttler;
    }

    private function room(): Room {
        $room = new Room();
        $room->setId(5);
        $room->setCode('ABCDEF');
        $room->setOwnerUid('');
        $room->setMode('quiz');
        $room->setPace('self');
        $room->setOpenedAt(1_800_000_000);
        $room->setDeckOrder('[11,12,13]');
        return $room;
    }
}
