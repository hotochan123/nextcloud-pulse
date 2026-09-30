<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Controller\PublicVoteController;
use OCA\Pulse\Service\JoinLimitException;
use OCA\Pulse\Service\Limits;
use OCA\Pulse\Service\PaceService;
use OCP\AppFramework\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A script that never sends the cookie back used to get a new identity — and
 * a new presence row — with every request, in moderated rooms without any
 * limit. Every phone and the projector then refetch the grown state after
 * each such vote. Now: /state without a cookie issues one but writes no
 * presence (the phone counts once the cookie comes back), and a /join or
 * /vote without a cookie is limited per address and room (NEW_TOKEN_LIMIT),
 * in every room mode. Requests WITH a cookie never touch that limit.
 *
 * Every /join, with a cookie or without, also counts towards a flood guard
 * per address and room (PaceService::JOIN_LIMIT) in both modes — far above
 * what a class behind one NAT sends (security review L4: what bounds new
 * names is counted on successful joins, not on requests). It grows with an
 * instance's player ceiling (Limits::PLAYERS_PER_ROOM).
 *
 * Tokens are not signed, so a script can also make one up for every /state;
 * each would add a presence row. New presence rows are therefore limited
 * per address and room too (NEW_PRESENCE_LIMIT) — asked only when the token
 * has no row yet, so a phone's ordinary poll never counts.
 */
#[CoversClass(PublicVoteController::class)]
class PublicNewTokenTest extends PublicControllerTestCase {

    private const TOKEN_ID = 'pulse-token-5';
    private const JOIN_ID = 'pulse-join-5';
    private const PRESENCE_ID = 'pulse-presence-5';

    // ── Presence ───────────────────────────────────────────────────────────

    public function testFirstStateIssuesACookieButNoPresence(): void {
        $response = $this->voteController()->state('ABCDEF');

        $this->assertSame(self::FRESH, $this->cookiesOf($response)['__Host-pulse_vt']['value']);
        $this->assertSame([], $this->callsTo('heartbeat'));
        // The new phone still sees its full state (never a 204).
        $this->assertSame([[self::FRESH, false]], $this->callsTo('publicState'));
    }

    public function testPresenceOnceTheCookieComesBack(): void {
        $this->sendCookies('__Host-pulse_vt=' . self::FRESH);

        $response = $this->voteController()->state('ABCDEF');

        $this->assertSame([[self::FRESH]], $this->callsTo('heartbeat'));
        $this->assertNoCookies($response);
    }

    public function testFirstStateInASelfPacedRoomWritesNoPresenceEither(): void {
        $this->room = $this->selfRoom();

        $this->voteController()->state('ABCDEF');

        $this->assertSame([], $this->callsTo('heartbeat'));
        $this->assertSame(1, $this->issued);
    }

    public function testFirstPresenceRowCountsPerAddressAndRoom(): void {
        $this->sendCookies('__Host-pulse_vt=' . self::FRESH);

        $response = $this->voteController()->state('ABCDEF');

        $this->assertSame(
            [[self::PRESENCE_ID, PublicVoteController::NEW_PRESENCE_LIMIT, PublicVoteController::NEW_TOKEN_PERIOD, self::IP]],
            $this->callsTo('registerAnonRequest'),
        );
        $this->assertSame([[true]], $this->callsTo('presenceAdded'));
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testKnownPresenceRowCountsNothing(): void {
        $this->presenceRow = true;
        $this->sendCookies('__Host-pulse_vt=' . self::ANNA);

        $this->voteController()->state('ABCDEF');

        $this->assertSame([[self::ANNA]], $this->callsTo('heartbeat'));
        $this->assertSame([], $this->callsTo('registerAnonRequest'));
    }

    public function testMadeUpTokensBeyondThePresenceLimitAddNoRow(): void {
        // The review's case: a new random token on every /state.
        $this->exhausted = [self::PRESENCE_ID];
        $this->sendCookies('__Host-pulse_vt=' . self::EVE);

        $response = $this->voteController()->state('ABCDEF');

        $this->assertSame([[false]], $this->callsTo('presenceAdded'));
        // Everything else as usual: the state, no new cookie, no 429.
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([[self::EVE, false]], $this->callsTo('publicState'));
        $this->assertNoCookies($response);
    }

    public function testPresenceLimitCoversALargeRoomBehindOneAddress(): void {
        $this->assertGreaterThanOrEqual(PaceService::MAX_JOINED, PublicVoteController::NEW_PRESENCE_LIMIT);
    }

    public function testStateNeverCountsTowardsTheTokenLimit(): void {
        // A GET cannot be limited without hurting the join page; it writes nothing now.
        $this->voteController()->state('ABCDEF');

        $this->assertSame([], $this->callsTo('registerAnonRequest'));
    }

    // ── Limit on new tokens ────────────────────────────────────────────────

    public static function rooms(): array {
        return ['moderated' => [false], 'self-paced' => [true]];
    }

    #[DataProvider('rooms')]
    public function testVoteWithoutCookieCountsTowardsTheLimit(bool $self): void {
        $this->room = $self ? $this->selfRoom() : $this->liveRoom();
        $this->params = ['value' => 'AA'];

        $response = $this->voteController()->vote('ABCDEF');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(
            [[self::TOKEN_ID, PublicVoteController::NEW_TOKEN_LIMIT, PublicVoteController::NEW_TOKEN_PERIOD, self::IP]],
            $this->callsTo('registerAnonRequest'),
        );
        $this->assertSame([[self::FRESH, 'AA']], $this->callsTo('recordVote'));
    }

    #[DataProvider('rooms')]
    public function testJoinWithoutCookieCountsTwice(bool $self): void {
        // The flood guard for every join, plus the limit for a new identity.
        $this->room = $self ? $this->selfRoom() : $this->liveRoom();
        $this->params = ['nickname' => 'Anna'];

        $this->voteController()->join('ABCDEF');

        $this->assertSame(
            [
                [self::JOIN_ID, PaceService::JOIN_LIMIT, PaceService::JOIN_PERIOD, self::IP],
                [self::TOKEN_ID, PublicVoteController::NEW_TOKEN_LIMIT, PublicVoteController::NEW_TOKEN_PERIOD, self::IP],
            ],
            $this->callsTo('registerAnonRequest'),
        );
    }

    #[DataProvider('rooms')]
    public function testJoinWithCookieCountsOnlyTowardsTheFloodGuard(bool $self): void {
        $this->room = $self ? $this->selfRoom() : $this->liveRoom();
        $this->sendCookies('__Host-pulse_vt=' . self::ANNA);
        $this->params = ['nickname' => 'Anna'];

        $this->voteController()->join('ABCDEF');

        $this->assertSame([self::JOIN_ID], array_column($this->callsTo('registerAnonRequest'), 0));
    }

    public function testVoteAndStateNeverCountTowardsTheFloodGuard(): void {
        $this->presenceRow = true;
        $this->sendCookies('__Host-pulse_vt=' . self::ANNA);
        $this->params = ['value' => 'AA'];

        $this->voteController()->vote('ABCDEF');
        $this->voteController()->state('ABCDEF');

        $this->assertSame([], $this->callsTo('registerAnonRequest'));
    }

    #[DataProvider('rooms')]
    public function testWithCookieNoTokenLimit(bool $self): void {
        $this->room = $self ? $this->selfRoom() : $this->liveRoom();
        $this->sendCookies('__Host-pulse_vt=' . self::ANNA);
        $this->params = ['nickname' => 'Anna', 'value' => 'AA'];

        $this->voteController()->join('ABCDEF');
        $this->voteController()->vote('ABCDEF');

        $this->assertNotContains(self::TOKEN_ID, array_column($this->callsTo('registerAnonRequest'), 0));
    }

    public static function exhaustedPosts(): array {
        return ['join' => ['join'], 'vote' => ['vote']];
    }

    #[DataProvider('exhaustedPosts')]
    public function testExhaustedLimitIs429WithoutTokenOrCookie(string $method): void {
        $this->exhausted = [self::TOKEN_ID];
        $this->params = ['nickname' => 'Anna', 'value' => 'AA'];

        $response = $this->voteController()->$method('ABCDEF');

        $this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
        $this->assertSame(['message' => 'Too many attempts. Please wait a moment.'], $response->getData());
        $this->assertSame(0, $this->issued);
        $this->assertNoCookies($response);
        $this->assertSame([], $this->callsTo('quizJoin'));
        $this->assertSame([], $this->callsTo('recordVote'));
    }

    #[DataProvider('exhaustedPosts')]
    public function testExhaustedLimitDoesNotStopPeopleWithACookie(string $method): void {
        $this->exhausted = [self::TOKEN_ID];
        $this->sendCookies('__Host-pulse_vt=' . self::ANNA);
        $this->params = ['nickname' => 'Anna', 'value' => 'AA'];

        $response = $this->voteController()->$method('ABCDEF');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    #[DataProvider('rooms')]
    public function testExhaustedFloodGuardIs429BeforeTheJoin(bool $self): void {
        $this->room = $self ? $this->selfRoom() : $this->liveRoom();
        $this->exhausted = [self::JOIN_ID];
        $this->sendCookies('__Host-pulse_vt=' . self::ANNA);
        $this->params = ['nickname' => 'Anna'];

        $response = $this->voteController()->join('ABCDEF');

        $this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
        $this->assertSame(['message' => 'Too many attempts. Please wait a moment.'], $response->getData());
        $this->assertSame([], $this->callsTo('quizJoin'));
        $this->assertNoCookies($response);
    }

    public function testFloodGuardIsFarAboveAClassBehindOneAddress(): void {
        // A full room of players, each joining four times within the period,
        // still passes: only a stream of requests from one address stops here.
        $this->assertGreaterThanOrEqual(4 * PaceService::MAX_JOINED, PaceService::JOIN_LIMIT);
        $this->assertSame(600, PaceService::JOIN_PERIOD);
    }

    public function testFloodGuardGrowsWithTheInstancesPlayerCeiling(): void {
        // occ config:app:set pulse max_players_per_room --value 1000
        $this->limitConfig[Limits::PLAYERS_PER_ROOM] = 1000;
        $this->sendCookies('__Host-pulse_vt=' . self::ANNA);
        $this->params = ['nickname' => 'Anna'];

        $this->voteController()->join('ABCDEF');

        $this->assertSame([[self::JOIN_ID, 4000, PaceService::JOIN_PERIOD, self::IP]], $this->callsTo('registerAnonRequest'));
    }

    public function testLowerCeilingKeepsTheFloodGuard(): void {
        $this->limitConfig[Limits::PLAYERS_PER_ROOM] = 50;
        $this->sendCookies('__Host-pulse_vt=' . self::ANNA);
        $this->params = ['nickname' => 'Anna'];

        $this->voteController()->join('ABCDEF');

        $this->assertSame(PaceService::JOIN_LIMIT, $this->callsTo('registerAnonRequest')[0][1]);
    }

    public function testJoinPassesTheAddressForTheNewPlayerCount(): void {
        $this->room = $this->selfRoom();
        $this->params = ['nickname' => 'Anna'];

        $this->voteController()->join('ABCDEF');

        $this->assertSame([[self::IP]], $this->callsTo('quizJoinAddress'));
    }

    public function testTooManyNewPlayersFromOneAddressIs429(): void {
        // VoteService::countNewPlayer (self-paced) — a 429 like the other limits, not a 400.
        $this->room = $this->selfRoom();
        $this->params = ['nickname' => 'Anna'];
        $this->joinError = new JoinLimitException('Too many attempts. Please wait a moment.');

        $response = $this->voteController()->join('ABCDEF');

        $this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
        $this->assertSame(['message' => 'Too many attempts. Please wait a moment.'], $response->getData());
        $this->assertNoCookies($response);
    }

    public function testOtherJoinErrorsStay400(): void {
        $this->params = ['nickname' => 'Anna'];
        $this->joinError = new \InvalidArgumentException('This quiz is full.');

        $response = $this->voteController()->join('ABCDEF');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertNoCookies($response);
    }

    public function testLimitIsGenerousForANat(): void {
        // One NAT address, a whole school: every phone's first /state already
        // hands out the cookie, so only scripts and cookie-less browsers count here.
        $this->assertGreaterThanOrEqual(PaceService::MAX_PLAYERS, PublicVoteController::NEW_TOKEN_LIMIT);
        $this->assertSame(600, PublicVoteController::NEW_TOKEN_PERIOD);
    }
}
