<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Controller\PublicController;
use OCA\Pulse\Controller\PublicVoteController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\TooManyRequestsResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Brute-force protection for room codes: ONLY an unknown code consults the
 * throttler (action 'pulseRoomCode'), and from the eleventh miss the answer
 * is 429. A request for an existing code never touches it — with
 * #[BruteForceProtection] Nextcloud checked the throttle before every
 * request, so ten wrong codes from a school NAT locked every phone behind it
 * out of every room for 30 minutes.
 *
 * Order: check, attempt, check. An address that is already blocked gets its
 * 429 without another attempt; otherwise a phone still polling a deleted room
 * kept the block alive for ever and grew the attempt list Nextcloud reads on
 * every brute-force check of that address.
 */
#[CoversClass(PublicVoteController::class)]
#[CoversClass(PublicController::class)]
class PublicCodeThrottleTest extends PublicControllerTestCase {

    /** Every public participant endpoint that looks a code up. */
    public static function endpoints(): array {
        return [
            'state' => ['state', ['ABCDEF']],
            'summary' => ['summary', ['ABCDEF']],
            'image' => ['image', ['ABCDEF', 11]],
            'join' => ['join', ['ABCDEF']],
            'vote' => ['vote', ['ABCDEF']],
            'next' => ['next', ['ABCDEF']],
        ];
    }

    public static function pages(): array {
        return ['show' => ['show'], 'screen' => ['screen']];
    }

    #[DataProvider('endpoints')]
    public function testUnknownCodeIsAFailedAttemptAnd404(string $method, array $args): void {
        $this->room = null;
        $this->sendCookies('__Host-pulse_vt=' . self::ANNA);

        $response = $this->voteController()->$method(...$args);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(['message' => 'Room not found.'], $response->getData());
        $this->assertSame([['pulseRoomCode', self::IP]], $this->callsTo('registerAttempt'));
        $this->assertSame([[self::IP, 'pulseRoomCode'], [self::IP, 'pulseRoomCode']], $this->callsTo('sleepDelayOrThrowOnMax'));
        $this->assertThrottleOrder();
        $this->assertNoCookies($response);
        // No longer marked for the middleware: without the attribute it would only log.
        $this->assertFalse($response->isThrottled());
    }

    #[DataProvider('endpoints')]
    public function testUnknownCodeFromABlockedAddressIs429(string $method, array $args): void {
        $this->room = null;
        $this->blocked = true;

        $response = $this->voteController()->$method(...$args);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
        $this->assertSame(['message' => 'Too many attempts. Please wait a moment.'], $response->getData());
        $this->assertSame([], $this->callsTo('registerAttempt'), 'a blocked address gets no further attempt');
        $this->assertNoCookies($response);
        $this->assertSame(0, $this->issued);
    }

    #[DataProvider('endpoints')]
    public function testTheEleventhMissIsTheFirst429AndBlockedMissesAreNotRecorded(string $method, array $args): void {
        $this->room = null;
        $this->attempts = 0;
        $statuses = [];

        for ($i = 0; $i < 14; $i++) {
            $statuses[] = $this->voteController()->$method(...$args)->getStatus();
        }

        $this->assertSame(array_merge(array_fill(0, 10, 404), array_fill(0, 4, 429)), $statuses);
        // Ten misses and the eleventh that tipped it over; the three blocked
        // requests after it add nothing, so the block ends 30 minutes after
        // the last real miss however long a phone keeps polling.
        $this->assertSame(11, $this->attempts);
    }

    #[DataProvider('endpoints')]
    public function testExistingCodeNeverConsultsTheThrottler(string $method, array $args): void {
        // Even while the address is "blocked" for misses.
        $this->blocked = true;
        $this->sendCookies('__Host-pulse_vt=' . self::ANNA);
        $this->params = ['after' => 0, 'nickname' => 'Anna', 'value' => 'AA'];
        if ($method === 'next') {
            $this->room = $this->selfRoom();
        }

        $response = $this->voteController()->$method(...$args);

        // (image: 404 "Not found." — the question is not visible, the room is found)
        $this->assertNotSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus(), $method);
        if ($response instanceof JSONResponse) {
            $this->assertNotSame(['message' => 'Room not found.'], $response->getData(), $method);
        }
        $this->assertSame([], $this->callsTo('registerAttempt'));
        $this->assertSame([], $this->callsTo('sleepDelayOrThrowOnMax'));
    }

    public function testImageOfAnUnknownQuestionIsNoCodeMiss(): void {
        // The room exists; only the question/image is missing — not a guess at a code.
        $response = $this->voteController()->image('ABCDEF', 11);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame([], $this->callsTo('registerAttempt'));
    }

    #[DataProvider('pages')]
    public function testPageForAnExistingCodeNeverConsultsTheThrottler(string $method): void {
        $this->blocked = true;

        $response = $this->pageController()->$method('ABCDEF');

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertContains(['roomExists', true], $this->callsTo('initialState'));
        $this->assertSame([], $this->callsTo('registerAttempt'));
        $this->assertSame([], $this->callsTo('sleepDelayOrThrowOnMax'));
    }

    #[DataProvider('pages')]
    public function testPageForAnUnknownCodeIsAFailedAttempt(string $method): void {
        $this->room = null;

        $response = $this->pageController()->$method('ZZZZZZ');

        // The SPA shows "room not found" itself (roomExists = false).
        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertContains(['roomExists', false], $this->callsTo('initialState'));
        $this->assertSame([['pulseRoomCode', self::IP]], $this->callsTo('registerAttempt'));
        $this->assertThrottleOrder();
    }

    #[DataProvider('pages')]
    public function testPageForAnUnknownCodeFromABlockedAddressIs429(string $method): void {
        $this->room = null;
        $this->blocked = true;

        $response = $this->pageController()->$method('ZZZZZZ');

        $this->assertInstanceOf(TooManyRequestsResponse::class, $response);
        $this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
        $this->assertSame([], $this->callsTo('initialState'));
        $this->assertSame([], $this->callsTo('registerAttempt'));
    }

    #[DataProvider('pages')]
    public function testPagesShareTheCountAndStopRecordingOnceBlocked(string $method): void {
        $this->room = null;
        $this->attempts = 0;
        $blocked = 0;

        for ($i = 0; $i < 13; $i++) {
            $page = $i % 2 === 0 ? $this->pageController()->$method('ZZZZZZ') : $this->voteController()->state('ZZZZZZ');
            if ($page->getStatus() === Http::STATUS_TOO_MANY_REQUESTS) {
                $blocked++;
            }
        }

        $this->assertSame(3, $blocked);
        $this->assertSame(11, $this->attempts);
    }

    /** Neither controller may bring the attribute back (it throttles valid codes too). */
    public function testNoBruteForceAttributeOnPublicControllers(): void {
        foreach ([PublicVoteController::class, PublicController::class] as $class) {
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                $this->assertSame(
                    [],
                    $method->getAttributes(BruteForceProtection::class),
                    "$class::{$method->getName()} must throttle unknown codes by hand",
                );
            }
            $file = (string)(new \ReflectionClass($class))->getFileName();
            $this->assertStringNotContainsString('@BruteForceProtection', (string)file_get_contents($file));
        }
    }

    /**
     * Check first (a blocked address records nothing more), then the attempt,
     * then the check again, so that the eleventh miss is the first 429.
     */
    private function assertThrottleOrder(): void {
        $order = array_values(array_filter(
            array_map(static fn (array $c): string => $c[0], $this->calls),
            static fn (string $name): bool => in_array($name, ['registerAttempt', 'sleepDelayOrThrowOnMax'], true),
        ));
        $this->assertSame(['sleepDelayOrThrowOnMax', 'registerAttempt', 'sleepDelayOrThrowOnMax'], $order);
    }
}
