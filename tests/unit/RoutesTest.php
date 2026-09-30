<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * appinfo/routes.php, straight from the file as Nextcloud includes it.
 *
 * The room code requirement is the URL-shape and brute-force guard of every
 * public endpoint, and routes.php repeats it by hand on 37 routes; a new
 * route must not silently lose it. The route table itself is pinned as a
 * whole: a changed or new route also has to get past the APCu route cache
 * (a 404 until the container restarts), so it is never a side effect.
 */
#[CoversNothing]
class RoutesTest extends TestCase {

    /** Requirement per URL placeholder. */
    private const REQUIREMENTS = [
        'code' => '[A-Za-z0-9]{6}',
        'pollId' => '\d+',
    ];

    /** Every route as [name, url, verb], in file order. */
    private const ROUTES = [
        ['page#index', '/', 'GET'],
        ['page#index', '/room/{code}', 'GET'],
        ['roomApi#index', '/api/1.0/rooms', 'GET'],
        ['roomApi#create', '/api/1.0/rooms', 'POST'],
        ['roomApi#summary', '/api/1.0/rooms/{code}/summary', 'GET'],
        ['roomApi#leaderboard', '/api/1.0/rooms/{code}/leaderboard', 'GET'],
        ['roomApi#exportCsv', '/api/1.0/rooms/{code}/export', 'GET'],
        ['roomApi#show', '/api/1.0/rooms/{code}', 'GET'],
        ['roomApi#destroy', '/api/1.0/rooms/{code}', 'DELETE'],
        ['roomApi#rename', '/api/1.0/rooms/{code}/title', 'POST'],
        ['roomApi#duplicate', '/api/1.0/rooms/{code}/duplicate', 'POST'],
        ['roomApi#resetRoom', '/api/1.0/rooms/{code}/reset', 'POST'],
        ['roomApi#practice', '/api/1.0/rooms/{code}/practice', 'POST'],
        ['roomApi#reveal', '/api/1.0/rooms/{code}/reveal', 'POST'],
        ['roomApi#endQuiz', '/api/1.0/rooms/{code}/end', 'POST'],
        ['roomApi#addPoll', '/api/1.0/rooms/{code}/polls', 'POST'],
        ['roomApi#reorder', '/api/1.0/rooms/{code}/deck/order', 'POST'],
        ['roomApi#setCurrent', '/api/1.0/rooms/{code}/current', 'POST'],
        ['roomApi#updatePoll', '/api/1.0/rooms/{code}/polls/{pollId}', 'PUT'],
        ['roomApi#deletePoll', '/api/1.0/rooms/{code}/polls/{pollId}', 'DELETE'],
        ['roomApi#results', '/api/1.0/rooms/{code}/polls/{pollId}/results', 'GET'],
        ['roomApi#resetPoll', '/api/1.0/rooms/{code}/polls/{pollId}/reset', 'POST'],
        ['roomApi#uploadImage', '/api/1.0/rooms/{code}/polls/{pollId}/image', 'POST'],
        ['roomApi#deleteImage', '/api/1.0/rooms/{code}/polls/{pollId}/image', 'DELETE'],
        ['roomApi#showImage', '/api/1.0/rooms/{code}/polls/{pollId}/image', 'GET'],
        ['roomApi#gradeAnswer', '/api/1.0/rooms/{code}/polls/{pollId}/grade', 'POST'],
        ['roomApi#lockPoll', '/api/1.0/rooms/{code}/polls/{pollId}/lock', 'POST'],
        ['roomApi#unlockPoll', '/api/1.0/rooms/{code}/polls/{pollId}/unlock', 'POST'],
        ['roomApi#demoSeed', '/api/1.0/rooms/{code}/demo', 'POST'],
        ['roomApi#demoClear', '/api/1.0/rooms/{code}/demo', 'DELETE'],
        ['roomApi#pace', '/api/1.0/rooms/{code}/pace', 'POST'],
        ['roomApi#progress', '/api/1.0/rooms/{code}/progress', 'GET'],
        ['public#join', '/join', 'GET'],
        ['public#show', '/s/{code}', 'GET'],
        ['public#screen', '/screen/{code}', 'GET'],
        ['public#embed', '/embed', 'GET'],
        ['addin#manifest', '/addin/manifest.xml', 'GET'],
        ['publicVote#state', '/s/{code}/state', 'GET'],
        ['publicVote#summary', '/s/{code}/summary', 'GET'],
        ['publicVote#image', '/s/{code}/polls/{pollId}/image', 'GET'],
        ['publicVote#join', '/s/{code}/join', 'POST'],
        ['publicVote#vote', '/s/{code}/vote', 'POST'],
        ['publicVote#next', '/s/{code}/next', 'POST'],
    ];

    /**
     * Keys beyond name, url, verb and requirements, per 'VERB url'. The
     * postfix keeps the two page#index routes apart: Nextcloud builds the
     * route name from app, controller and action plus the postfix, so
     * without it both share one name and the later replaces the earlier.
     */
    private const EXTRA_KEYS = [
        'GET /room/{code}' => ['postfix' => 'room'],
    ];

    public function testEveryPlaceholderCarriesItsRequirement(): void {
        $withCode = 0;
        $withPollId = 0;
        foreach ($this->routes() as $route) {
            preg_match_all('/\{(\w+)\}/', $route['url'], $m);
            $expected = [];
            foreach ($m[1] as $placeholder) {
                $this->assertArrayHasKey($placeholder, self::REQUIREMENTS, $route['url'] . ': unknown placeholder');
                $expected[$placeholder] = self::REQUIREMENTS[$placeholder];
            }
            // Exactly the requirements of its placeholders: none missing, none loosened, none left over.
            $actual = $route['requirements'] ?? [];
            ksort($expected);
            ksort($actual);
            $this->assertSame($expected, $actual, $route['name'] . ' ' . $route['url']);
            $withCode += isset($expected['code']) ? 1 : 0;
            $withPollId += isset($expected['pollId']) ? 1 : 0;
        }
        $this->assertSame(37, $withCode, 'routes with {code}');
        $this->assertSame(11, $withPollId, 'routes with {pollId}');
    }

    public function testRouteTableIsUnchanged(): void {
        $actual = array_map(
            static fn (array $r): array => [$r['name'], $r['url'], $r['verb']],
            $this->routes(),
        );

        $this->assertSame(self::ROUTES, $actual);

        $extra = [];
        foreach ($this->routes() as $r) {
            $rest = array_diff_key($r, array_flip(['name', 'url', 'verb', 'requirements']));
            if ($rest !== []) {
                $extra[$r['verb'] . ' ' . $r['url']] = $rest;
            }
        }
        $this->assertSame(self::EXTRA_KEYS, $extra, 'keys beyond name, url, verb and requirements');
    }

    /** @return list<array{name: string, url: string, verb: string, requirements?: array<string, string>, postfix?: string}> */
    private function routes(): array {
        $routes = require dirname(__DIR__, 2) . '/appinfo/routes.php';
        $this->assertSame(['routes'], array_keys($routes), 'routes.php declares plain routes only');
        return $routes['routes'];
    }
}
