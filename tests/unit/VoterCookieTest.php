<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Controller\PublicVoteController;
use OCP\AppFramework\Http;
use OCP\Exceptions\AppConfigTypeConflictException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The voter cookie against cookie tossing: a sibling subdomain can set a
 * `pulse_vt` for the whole domain with a token it knows, PHP keeps the first
 * of two equal names, and the owner of that token then reads the visitor's
 * answers. On https with an empty webroot the cookie is `__Host-pulse_vt`,
 * which browsers accept only from this origin; a legacy `pulse_vt` is adopted
 * for a limited time only (LEGACY_ADOPT_PERIOD), and a name sent twice counts
 * as no cookie at all.
 */
#[CoversClass(PublicVoteController::class)]
class VoterCookieTest extends PublicControllerTestCase {

    private const DAY = 86400;

    // ── Which name ─────────────────────────────────────────────────────────

    public static function cookieNames(): array {
        return [
            'https, empty webroot' => ['https', '', '__Host-pulse_vt'],
            'http' => ['http', '', 'pulse_vt'],
            'https below a webroot (Path would not be /)' => ['https', '/nextcloud', 'pulse_vt'],
        ];
    }

    #[DataProvider('cookieNames')]
    public function testNewCookieName(string $protocol, string $webroot, string $name): void {
        $this->protocol = $protocol;
        $this->webroot = $webroot;

        $response = $this->voteController()->state('ABCDEF');

        $cookies = $this->cookiesOf($response);
        $this->assertSame([$name], array_keys($cookies));
        $this->assertSame(self::FRESH, $cookies[$name]['value']);
        $this->assertSame('Lax', $cookies[$name]['sameSite']);
        $this->assertSame(self::NOW + 365 * self::DAY, $cookies[$name]['expireDate']->getTimestamp());
    }

    #[DataProvider('cookieNames')]
    public function testJoinAndVoteSetTheSameName(string $protocol, string $webroot, string $name): void {
        $this->protocol = $protocol;
        $this->webroot = $webroot;
        $this->params = ['nickname' => 'Anna', 'value' => 'AA'];

        foreach (['join', 'vote'] as $method) {
            $response = $this->voteController()->$method('ABCDEF');
            $this->assertSame([$name], array_keys($this->cookiesOf($response)), $method);
        }
    }

    public function testHostCookieIsUsed(): void {
        $this->sendCookies('__Host-pulse_vt=' . self::ANNA);

        $response = $this->voteController()->state('ABCDEF');

        $this->assertSame([[self::ANNA]], $this->callsTo('heartbeat'));
        $this->assertNoCookies($response);
        $this->assertSame(0, $this->issued);
    }

    public function testLegacyModeIgnoresAHostCookie(): void {
        // http: a __Host- cookie could not even exist there; only pulse_vt counts.
        $this->protocol = 'http';
        $this->sendCookies('__Host-pulse_vt=' . self::ANNA);

        $this->voteController()->state('ABCDEF');

        $this->assertSame(1, $this->issued);
        $this->assertSame([], $this->callsTo('setValueInt'), 'no adoption period without __Host-');
    }

    // ── Tossing ────────────────────────────────────────────────────────────

    public function testHostCookieWinsOverATossedLegacyCookie(): void {
        // The tossed Domain cookie comes first (longer path) — PHP would pick it.
        $this->hostSince = self::NOW - self::DAY;
        $this->sendCookies('pulse_vt=' . self::EVE . '; __Host-pulse_vt=' . self::ANNA);

        $response = $this->voteController()->state('ABCDEF');

        $this->assertSame([[self::ANNA]], $this->callsTo('heartbeat'));
        $this->assertSame([[self::ANNA, false]], $this->callsTo('publicState'));
        // The legacy cookie is expired; the __Host- one keeps its token.
        $this->assertSame(['__Host-pulse_vt', 'pulse_vt'], array_keys($this->cookiesOf($response)));
        $this->assertSame(self::ANNA, $this->cookiesOf($response)['__Host-pulse_vt']['value']);
        $this->assertExpired($response, 'pulse_vt');
    }

    public function testUnusableHostCookieDoesNotFallBackToLegacy(): void {
        $this->hostSince = self::NOW - self::DAY;
        $this->sendCookies('__Host-pulse_vt=broken; pulse_vt=' . self::EVE);

        $response = $this->voteController()->state('ABCDEF');

        $this->assertSame(1, $this->issued);
        $this->assertSame(self::FRESH, $this->cookiesOf($response)['__Host-pulse_vt']['value']);
        $this->assertSame([], $this->callsTo('heartbeat'));
    }

    public static function duplicates(): array {
        return [
            'two __Host-' => ['https', '__Host-pulse_vt=' . self::ANNA . '; __Host-pulse_vt=' . self::EVE],
            'two legacy (https, adoption)' => ['https', 'pulse_vt=' . self::ANNA . '; pulse_vt=' . self::EVE],
            'two legacy (http)' => ['http', 'pulse_vt=' . self::ANNA . '; pulse_vt=' . self::EVE],
            'pulse.vt is pulse_vt for PHP' => ['http', 'pulse_vt=' . self::ANNA . '; pulse.vt=' . self::EVE],
            'pulse vt with a blank' => ['http', 'pulse_vt=' . self::ANNA . ';pulse vt=' . self::EVE],
            'pulse[vt without ]' => ['http', 'pulse_vt=' . self::ANNA . '; pulse[vt=' . self::EVE],
            'array form after the real one' => ['http', 'pulse_vt=' . self::ANNA . '; pulse_vt[x]=' . self::EVE],
            // The prefix is really there before PHP's mangling: PHP keeps both.
            '__Host-pulse.vt is __Host-pulse_vt for PHP' => ['https', '__Host-pulse_vt=' . self::ANNA . '; __Host-pulse.vt=' . self::EVE],
        ];
    }

    /**
     * Names that only become `__Host-pulse_vt` through PHP's mangling. Browsers
     * apply no prefix rule to them, so any sibling subdomain can set one for
     * the whole domain; PHP drops them (CVE-2022-31629) and so must the count,
     * or the sibling turns every request of the visitor into "no cookie".
     */
    public static function mangledPrefixes(): array {
        return [
            'dot' => ['_.Host-pulse_vt'],
            'blank' => ['_ Host-pulse_vt'],
            'open bracket' => ['_[Host-pulse_vt'],
            'array form' => ['_.Host-pulse_vt[x]'],
        ];
    }

    #[DataProvider('mangledPrefixes')]
    public function testMangledPrefixNameIsNoSecondCookie(string $name): void {
        foreach ([$name . '=' . self::EVE . '; __Host-pulse_vt=' . self::ANNA, '__Host-pulse_vt=' . self::ANNA . '; ' . $name . '=' . self::EVE] as $raw) {
            $this->calls = [];
            $this->sendCookies($raw);

            $response = $this->voteController()->state('ABCDEF');

            $this->assertSame([[self::ANNA]], $this->callsTo('heartbeat'), $raw);
            $this->assertSame(0, $this->issued, $raw);
            $this->assertNoCookies($response);
        }
    }

    #[DataProvider('mangledPrefixes')]
    public function testMangledPrefixNameAloneIsNoCookie(string $name): void {
        $this->sendCookies($name . '=' . self::EVE);

        $response = $this->voteController()->state('ABCDEF');

        $this->assertSame([], $this->cookies, 'PHP drops it');
        $this->assertSame(1, $this->issued);
        $this->assertSame(self::FRESH, $this->cookiesOf($response)['__Host-pulse_vt']['value']);
        $this->assertSame([], $this->callsTo('heartbeat'));
    }

    #[DataProvider('duplicates')]
    public function testNameSentTwiceCountsAsNoCookie(string $protocol, string $raw): void {
        $this->protocol = $protocol;
        $this->hostSince = self::NOW - self::DAY;
        $this->sendCookies($raw);

        $response = $this->voteController()->state('ABCDEF');

        $this->assertSame(1, $this->issued, 'a new token instead of either of the two');
        $this->assertSame([], $this->callsTo('heartbeat'));
        $this->assertSame([[self::FRESH, false]], $this->callsTo('publicState'));
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testOtherCookiesDoNotCount(): void {
        $this->protocol = 'http';
        $this->sendCookies('oc_sessionPassphrase=x; pulse_vtx=' . self::EVE . '; xpulse_vt=' . self::EVE . '; pulse_vt=' . self::ANNA);

        $this->voteController()->state('ABCDEF');

        $this->assertSame([[self::ANNA]], $this->callsTo('heartbeat'));
    }

    // ── Adopting the legacy cookie ─────────────────────────────────────────

    public function testLegacyCookieIsAdoptedOnState(): void {
        $this->hostSince = self::NOW - 59 * self::DAY;
        $this->sendCookies('pulse_vt=' . self::ANNA);

        $response = $this->voteController()->state('ABCDEF');

        $this->assertSame([[self::ANNA]], $this->callsTo('heartbeat'));
        $this->assertSame(0, $this->issued);
        $cookies = $this->cookiesOf($response);
        $this->assertSame(self::ANNA, $cookies['__Host-pulse_vt']['value']);
        $this->assertExpired($response, 'pulse_vt');
    }

    public function testAdoptionAlsoOnAnUnchangedPoll(): void {
        $this->hostSince = self::NOW - self::DAY;
        $this->sendCookies('pulse_vt=' . self::ANNA);
        $this->params = ['v' => 'v1'];

        $response = $this->voteController()->state('ABCDEF');

        $this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
        $this->assertSame(self::ANNA, $this->cookiesOf($response)['__Host-pulse_vt']['value']);
        $this->assertExpired($response, 'pulse_vt');
    }

    public function testAdoptionOnJoinVoteAndNext(): void {
        $this->hostSince = self::NOW - self::DAY;
        $this->sendCookies('pulse_vt=' . self::ANNA);
        $this->params = ['nickname' => 'Anna', 'value' => 'AA', 'after' => 0];

        $join = $this->voteController()->join('ABCDEF');
        $vote = $this->voteController()->vote('ABCDEF');
        $this->room = $this->selfRoom();
        $next = $this->voteController()->next('ABCDEF');

        $this->assertSame([[self::ANNA, 'Anna']], $this->callsTo('quizJoin'));
        $this->assertSame([[self::ANNA, 'AA']], $this->callsTo('recordVote'));
        $this->assertSame([[self::ANNA, 0]], $this->callsTo('next'));
        foreach (['join' => $join, 'vote' => $vote, 'next' => $next] as $label => $response) {
            $this->assertSame(self::ANNA, $this->cookiesOf($response)['__Host-pulse_vt']['value'] ?? null, $label);
            $this->assertExpired($response, 'pulse_vt');
        }
        $this->assertSame(0, $this->issued);
        // Only the join's flood guard counts; an adopted cookie is no new token.
        $this->assertSame(['pulse-join-5'], array_column($this->callsTo('registerAnonRequest'), 0));
    }

    public function testNextWithTheHostCookieSetsNoCookie(): void {
        $this->room = $this->selfRoom();
        $this->sendCookies('__Host-pulse_vt=' . self::ANNA);
        $this->params = ['after' => 0];

        $this->assertNoCookies($this->voteController()->next('ABCDEF'));
    }

    public function testReadingWithALegacyCookieSetsNoCookie(): void {
        $this->hostSince = self::NOW - self::DAY;
        $this->sendCookies('pulse_vt=' . self::ANNA);

        $summary = $this->voteController()->summary('ABCDEF');
        $image = $this->voteController()->image('ABCDEF', 11);

        // Read with the adopted token; the move to the new name waits for /state.
        $this->assertSame([[self::ANNA]], $this->callsTo('publicSummary'));
        $this->assertSame([[self::ANNA]], $this->callsTo('imageVisible'));
        $this->assertNoCookies($summary);
        $this->assertNoCookies($image);
    }

    public function testLegacyCookieIsIgnoredAfterThePeriod(): void {
        $this->hostSince = self::NOW - PublicVoteController::LEGACY_ADOPT_PERIOD;
        $this->sendCookies('pulse_vt=' . self::ANNA);

        $response = $this->voteController()->state('ABCDEF');

        $this->assertSame(1, $this->issued);
        $this->assertSame([], $this->callsTo('heartbeat'));
        $this->assertSame(self::FRESH, $this->cookiesOf($response)['__Host-pulse_vt']['value']);
        $this->assertExpired($response, 'pulse_vt');
    }

    public function testThePeriodStartsWithTheFirstHostRequest(): void {
        // A fresh update: nothing stored yet -> now, exactly once.
        $this->voteController()->state('ABCDEF');
        $this->voteController()->state('ABCDEF');

        $this->assertSame([['pulse', 'voter_cookie_host_since', self::NOW]], $this->callsTo('setValueInt'));
        $this->assertSame(self::NOW, $this->hostSince);
    }

    public function testFirstRequestAdoptsRightAway(): void {
        $this->sendCookies('pulse_vt=' . self::ANNA);

        $this->voteController()->state('ABCDEF');

        $this->assertSame([[self::ANNA]], $this->callsTo('heartbeat'));
    }

    public function testBrokenConfigValueMeansNoAdoptionButNo500(): void {
        $this->configError = new AppConfigTypeConflictException('conflict with value type from database');
        $this->sendCookies('pulse_vt=' . self::ANNA);

        $response = $this->voteController()->state('ABCDEF');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(1, $this->issued);
        $this->assertSame(self::FRESH, $this->cookiesOf($response)['__Host-pulse_vt']['value']);
    }

    public function testSpectatorGetsNoCookieEvenWithALegacyOne(): void {
        $this->hostSince = self::NOW - self::DAY;
        $this->sendCookies('pulse_vt=' . self::ANNA);
        $this->params = ['spectate' => '1'];

        $response = $this->voteController()->state('ABCDEF');

        $this->assertNoCookies($response);
        $this->assertSame([], $this->callsTo('heartbeat'));
    }

    /** Guard: the cookie is read in one place only, and never cast raw. */
    public function testOneCookieReader(): void {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PublicVoteController.php');
        $this->assertSame(1, substr_count($source, '->getCookie('));
        $this->assertSame('__Host-pulse_vt', PublicVoteController::HOST_COOKIE);
    }
}
