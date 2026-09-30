<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Controller\PublicVoteController;
use OCP\AppFramework\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Participant POSTs carry #[NoCSRFRequired], so Nextcloud checks neither the
 * request token nor the strict cookie. Without a check of their own, any
 * site could vote, rename or start the clock in a visitor's name with an
 * auto-submitted form — or replace their voter cookie with a fresh one.
 * Every participant POST therefore wants `X-Requested-With: XMLHttpRequest`
 * (the public bundle sends it through @nextcloud/axios) or
 * `Sec-Fetch-Site: same-origin|none`; otherwise 403, before anything else
 * happens and without a cookie.
 */
#[CoversClass(PublicVoteController::class)]
class PublicCrossSiteTest extends PublicControllerTestCase {

    /** Every POST route of the participant API, straight from routes.php. */
    public static function postRoutes(): array {
        $routes = require dirname(__DIR__, 2) . '/appinfo/routes.php';
        $rows = [];
        foreach ($routes['routes'] as $route) {
            if (str_starts_with($route['name'], 'publicVote#') && strtoupper($route['verb'] ?? 'GET') === 'POST') {
                $method = substr($route['name'], strlen('publicVote#'));
                $rows[$method] = [$method];
            }
        }
        return $rows;
    }

    public static function refusedHeaders(): array {
        return [
            'no header at all (a plain form)' => [[]],
            'Sec-Fetch-Site: cross-site' => [['Sec-Fetch-Site' => 'cross-site']],
            'Sec-Fetch-Site: same-site (a sibling subdomain)' => [['Sec-Fetch-Site' => 'same-site']],
            'another X-Requested-With' => [['X-Requested-With' => 'fetch']],
            'X-Requested-With empty' => [['X-Requested-With' => '']],
        ];
    }

    public static function acceptedHeaders(): array {
        return [
            'X-Requested-With (@nextcloud/axios)' => [['X-Requested-With' => 'XMLHttpRequest']],
            'X-Requested-With in other case' => [['x-requested-with' => 'xmlhttprequest']],
            'Sec-Fetch-Site: same-origin' => [['Sec-Fetch-Site' => 'same-origin']],
            'Sec-Fetch-Site: none' => [['Sec-Fetch-Site' => 'none']],
            'cross-site, but with the header' => [['X-Requested-With' => 'XMLHttpRequest', 'Sec-Fetch-Site' => 'cross-site']],
        ];
    }

    public function testThePostRoutesAreKnown(): void {
        // A new participant POST shows up here and in the data providers below.
        $this->assertSame(['join', 'vote', 'next'], array_keys(self::postRoutes()));
    }

    /** @return iterable<string, array{0: string, 1: array<string, string>}> */
    public static function refusedPosts(): iterable {
        foreach (self::postRoutes() as $method => [$m]) {
            foreach (self::refusedHeaders() as $label => [$headers]) {
                yield "$method, $label" => [$m, $headers];
            }
        }
    }

    /** @return iterable<string, array{0: string, 1: array<string, string>}> */
    public static function acceptedPosts(): iterable {
        foreach (self::postRoutes() as $method => [$m]) {
            foreach (self::acceptedHeaders() as $label => [$headers]) {
                yield "$method, $label" => [$m, $headers];
            }
        }
    }

    #[DataProvider('refusedPosts')]
    public function testCrossSitePostIs403WithoutCookie(string $method, array $headers): void {
        $this->headers = $headers;
        $this->room = $this->selfRoom();
        $this->params = ['nickname' => 'Eve', 'value' => 'AA', 'after' => 0];

        $response = $this->voteController()->$method('ABCDEF');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame(['message' => 'Invalid request.'], $response->getData());
        $this->assertNoCookies($response);
        $this->assertSame(0, $this->issued, 'no new token');
        // Nothing reached a service, and not even the code was looked up.
        $this->assertSame([], $this->calls);
    }

    #[DataProvider('refusedPosts')]
    public function testCrossSitePostWithTheVictimsCookieChangesNothing(string $method, array $headers): void {
        // A sibling subdomain whose request carries the visitor's Lax cookie.
        $this->headers = $headers;
        $this->room = $this->selfRoom();
        $this->sendCookies('__Host-pulse_vt=' . self::ANNA);
        $this->params = ['nickname' => 'Eve', 'value' => 'AA', 'after' => 0];

        $response = $this->voteController()->$method('ABCDEF');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame([], $this->callsTo('quizJoin'));
        $this->assertSame([], $this->callsTo('recordVote'));
        $this->assertSame([], $this->callsTo('next'));
    }

    #[DataProvider('acceptedPosts')]
    public function testSameOriginPostGoesThrough(string $method, array $headers): void {
        $this->headers = $headers;
        $this->room = $this->selfRoom();
        $this->sendCookies('__Host-pulse_vt=' . self::ANNA);
        $this->params = ['nickname' => 'Anna', 'value' => 'AA', 'after' => 0];

        $response = $this->voteController()->$method('ABCDEF');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, array_merge($this->callsTo('quizJoin'), $this->callsTo('recordVote'), $this->callsTo('next')));
    }

    public function testReadingNeedsNoHeader(): void {
        // /state, /summary and the image are GETs: a plain poll and an <img>.
        $this->headers = [];
        $this->sendCookies('__Host-pulse_vt=' . self::ANNA);
        $controller = $this->voteController();

        $this->assertSame(Http::STATUS_OK, $controller->state('ABCDEF')->getStatus());
        $this->assertSame(Http::STATUS_OK, $controller->summary('ABCDEF')->getStatus());
        $this->assertSame([[self::ANNA]], $this->callsTo('heartbeat'));
    }

    /**
     * The check only works because the phone sends the header: the public
     * bundle posts through @nextcloud/axios, whose client sets
     * X-Requested-With. If a rebuild ever lost it, every vote would be a 403.
     */
    public function testThePublicBundleSendsTheHeader(): void {
        $root = dirname(__DIR__, 2);
        $bundle = (string)file_get_contents($root . '/js/pulse-public.js');
        $this->assertStringContainsString('"X-Requested-With":"XMLHttpRequest"', $bundle);
        foreach (['src/Participant.vue', 'src/mixins/pace-phone.js'] as $file) {
            $source = (string)file_get_contents($root . '/' . $file);
            $this->assertStringContainsString("import axios from '@nextcloud/axios'", $source, $file);
            $this->assertDoesNotMatchRegularExpression('/\bfetch\(|sendBeacon|XMLHttpRequest\(/', $source, $file);
        }
    }
}
