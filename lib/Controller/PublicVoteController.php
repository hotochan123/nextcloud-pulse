<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Controller;

use OCA\Pulse\AppInfo\Application;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Service\CodeGenerator;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\Input;
use OCA\Pulse\Service\JoinLimitException;
use OCA\Pulse\Service\Limits;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\RoomGoneException;
use OCA\Pulse\Service\RoomService;
use OCA\Pulse\Service\StateService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Exceptions\AppConfigException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\Bruteforce\MaxDelayReached;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;

/**
 * Public participant API — no login, no CSRF token (the audience has
 * no Nextcloud session). Voting twice is discouraged by an anonymous cookie
 * token, not prevented by identity. The same pattern as for share links.
 *
 * Self-paced quiz (PaceService::isSelf): /state builds the state before
 * the version (there is no cheap fingerprint), new players are counted per
 * IP and room, and /next starts the person's next question. Moderated
 * rooms work as before.
 *
 * Parameters and cookie come raw from the client: Input and voterCookie() turn
 * anything unusable into "not sent" (never a PHP warning, never a 500).
 *
 * Because #[NoCSRFRequired] switches off Nextcloud's CSRF and strict-cookie
 * checks, the controller does its own browser-side checks:
 *  - Every POST (join, vote, next) must come from a script of this origin:
 *    `X-Requested-With: XMLHttpRequest` (@nextcloud/axios, which the public
 *    bundle uses, always sends it) or `Sec-Fetch-Site: same-origin|none`.
 *    A plain HTML form cannot set that header, and a cross-origin fetch with it
 *    needs a CORS preflight that Nextcloud does not grant. So another site
 *    (a sibling subdomain included) can neither vote or rename in a visitor's
 *    name nor replace their cookie with a fresh one (403, no cookie).
 *  - The voter cookie is `__Host-pulse_vt` on https with an empty webroot:
 *    browsers accept such a cookie only from this very origin (Secure, Path=/,
 *    no Domain), so a sibling subdomain cannot plant a token it knows
 *    (cookie tossing). Elsewhere it stays `pulse_vt`. See voterCookie().
 *  - Unknown room codes are throttled by hand (throttleUnknownCode()), not
 *    with #[BruteForceProtection]: the attribute consults the throttle before
 *    every request, whatever the code — ten wrong codes from one address locked
 *    every phone behind it (a school NAT) out of every room.
 *  - New voter tokens (a /join or /vote without a cookie) are limited per
 *    address and room, and a /state without a cookie issues one but writes no
 *    presence row: a script that never sends the cookie back cannot pump up the
 *    tables that every phone and the projector read. Tokens are not signed, so
 *    a script can also send made-up ones; the presence rows those would add
 *    are limited per address and room as well (NEW_PRESENCE_LIMIT).
 *
 * Section references (§…) point to the design notes of the redesign, which are
 * not in the public repository (see "References in code comments" in the
 * README).
 */
class PublicVoteController extends Controller {
    /** Brute-force action for unknown room codes (IThrottler). */
    public const CODE_ACTION = 'pulseRoomCode';

    /** Name of the voter cookie on https with an empty webroot (see hostCookie()). */
    public const HOST_COOKIE = '__Host-' . Application::VOTER_COOKIE;

    /**
     * New voter tokens per address and room within NEW_TOKEN_PERIOD seconds.
     * Only requests WITHOUT a cookie count: a phone gets its cookie from its
     * first /state, so real participants never get here — only scripts and
     * browsers that refuse cookies. Generous for a class behind one NAT address.
     */
    public const NEW_TOKEN_LIMIT = 300;
    public const NEW_TOKEN_PERIOD = 600;

    /**
     * New presence rows ("N here", a player's last activity) per address and
     * room within NEW_TOKEN_PERIOD seconds. Every phone adds one, with its
     * second poll; a script that makes up a token for every request would add
     * one per request, and in the lobby every change of the count sends each
     * phone into a full refetch. As many as a quiz room takes players by
     * default (PaceService::MAX_JOINED). A phone beyond it works as usual; it
     * is only counted as present once the window has moved on.
     */
    public const NEW_PRESENCE_LIMIT = 600;

    /**
     * How long after the switch to __Host-pulse_vt a single legacy pulse_vt
     * cookie is still adopted, in seconds: long enough for a 30-day homework
     * running during the update plus its review. After that a legacy cookie is
     * ignored — it is exactly the cookie a sibling subdomain could plant.
     */
    public const LEGACY_ADOPT_PERIOD = 60 * 86400;

    /** App config key: when this instance first used __Host-pulse_vt (Unix time). */
    public const HOST_COOKIE_SINCE = 'voter_cookie_host_since';

    public function __construct(
        IRequest $request,
        private RoomMapper $roomMapper,
        private RoomService $roomService,
        private StateService $stateService,
        private VoteService $voteService,
        private DeckService $deckService,
        private PollImageService $imageService,
        private CodeGenerator $codeGenerator,
        private ITimeFactory $timeFactory,
        private IL10N $l10n,
        // only touched in self-paced mode:
        private PaceService $paceService,
        private ILimiter $limiter,
        private IThrottler $throttler,
        private IURLGenerator $urlGenerator,
        private IAppConfig $appConfig,
        private Limits $limits,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Live state for the participant page (adaptive polling). The client
     * sends the last `version` it saw; if nothing has changed,
     * we answer with 204 (empty) instead of computing the full state.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function state(string $code): Response {
        $room = $this->loadRoom($code);
        if ($room === null) {
            return $this->throttleUnknownCode();
        }

        // The projector/audience view polls with ?spectate=1: a pure spectator, does
        // not count as a participant and gets no cookie (otherwise the
        // projecting computer would skew the "here" count).
        $spectate = $this->request->getParam('spectate') === '1';
        $cookie = $this->voterCookie();
        $token = $cookie['token'];
        $isNewToken = $token === null;
        $sendCookie = false;
        if ($spectate) {
            $isNewToken = false; // no cookie issued, no heartbeat
        } elseif ($isNewToken) {
            // People who are only watching (no vote yet) should count too: if the
            // cookie is missing, issue one here and send it along. But no presence
            // row yet: the phone counts from its next poll, once the cookie comes
            // back (~1 s later). Otherwise every request without a cookie — a
            // script — would add a participant row.
            $token = $this->codeGenerator->voterToken();
            $sendCookie = true;
        } else {
            $this->roomService->heartbeat($room, $token, fn (): bool => $this->allowNewPresence($room));
            // A legacy cookie is moved to __Host-pulse_vt (and expired).
            $sendCookie = $cookie['adopted'] || $cookie['dropLegacy'];
        }

        $clientVersion = Input::str($this->request->getParam('v'));
        $response = null;
        if (PaceService::isSelf($room)) {
            // Self-paced: no cheap fingerprint — build the state; the
            // version is its hash (deadline, finality, time-out are part of it).
            $state = $this->stateService->publicState($room, $token, $spectate);
            $version = $this->stateService->selfVersion($state);
            if (!$isNewToken && $clientVersion !== '' && $clientVersion === $version) {
                $response = new Response();
                $response->setStatus(Http::STATUS_NO_CONTENT);
            }
        } else {
            // Unchanged since the last poll? -> 204, without building the tally.
            // (A new token always gets the full state + cookie.)
            // Spectators also see the presence (the stage's intake display) and
            // therefore need it in the fingerprint as well.
            $version = $this->stateService->stateVersion($room, $spectate);
            if (!$isNewToken && $clientVersion !== '' && $clientVersion === $version) {
                $response = new Response();
                $response->setStatus(Http::STATUS_NO_CONTENT);
            } else {
                $state = $this->stateService->publicState($room, $token, $spectate);
            }
        }
        if ($response === null) {
            $state['version'] = $version;
            $response = new JSONResponse($state);
        }
        if ($sendCookie && $token !== null) {
            $this->setVoterCookie($response, $token, $cookie);
        }
        return $response;
    }

    /**
     * Overall summary for participants: all questions with results, their own
     * answer and (quiz) the reveal. Only loaded at the press of a button, not
     * polled. Read-only — no cookie is issued, no heartbeat: whoever reads the
     * summary is not "here right now".
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function summary(string $code): JSONResponse {
        $room = $this->loadRoom($code);
        if ($room === null) {
            return $this->throttleUnknownCode();
        }
        return new JSONResponse($this->stateService->publicSummary($room, $this->voterCookie()['token']));
    }

    /**
     * Serve a question's image. It is only visible while the question is running
     * or already revealed — the same barrier as for the question itself,
     * otherwise the image would give away the next question in the deck. No cookie, no
     * heartbeat; the response may be cached (the file name in the question's
     * path changes with every new image). In self-paced mode only for people
     * who have reached the question — the <img> sends the cookie along.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function image(string $code, int $pollId): Response {
        $room = $this->loadRoom($code);
        if ($room === null) {
            return $this->throttleUnknownCode();
        }
        try {
            $poll = $this->deckService->requirePollInRoom($room, $pollId);
        } catch (\InvalidArgumentException) {
            return new JSONResponse(['message' => $this->l10n->t('Not found.')], Http::STATUS_NOT_FOUND);
        }
        if (!$this->stateService->imageVisible($room, $poll, $this->voterCookie()['token'])) {
            return new JSONResponse(['message' => $this->l10n->t('Not found.')], Http::STATUS_NOT_FOUND);
        }
        $img = $this->imageService->read($poll);
        if ($img === null) {
            return new JSONResponse(['message' => $this->l10n->t('No image.')], Http::STATUS_NOT_FOUND);
        }
        $response = new DataDisplayResponse($img['content'], Http::STATUS_OK, ['Content-Type' => $img['mime']]);
        $response->cacheFor(3600, false, true);
        return $response;
    }

    /**
     * Joining a quiz: choose a nickname. Issues the anonymous voter cookie
     * (if needed) and anchors the name to it.
     *
     * Every join counts towards a generous flood guard per IP and room
     * (PaceService::joinLimit per JOIN_PERIOD, both modes — it grows with an
     * instance's player ceiling, Limits::PLAYERS_PER_ROOM): each one takes
     * the room lock. It is far above what a class behind one NAT address
     * sends; what really bounds new names is counted on successful joins
     * only — self-paced the NEW players per address (VoteService,
     * JoinLimitException, 429), in both modes the caps. Joins WITHOUT a
     * cookie also count towards NEW_TOKEN_LIMIT.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function join(string $code): JSONResponse {
        $forbidden = $this->refuseCrossSite();
        if ($forbidden !== null) {
            return $forbidden;
        }
        $room = $this->loadRoom($code);
        if ($room === null) {
            return $this->throttleUnknownCode();
        }
        try {
            $this->limiter->registerAnonRequest(
                'pulse-join-' . $room->getId(),
                PaceService::joinLimit($this->limits->get(Limits::PLAYERS_PER_ROOM)),
                PaceService::JOIN_PERIOD,
                $this->request->getRemoteAddress(),
            );
        } catch (IRateLimitExceededException) {
            return $this->tooManyRequests();
        }

        $cookie = $this->voterCookie();
        $token = $cookie['token'];
        if ($token === null) {
            if (!$this->allowNewToken($room)) {
                return $this->tooManyRequests();
            }
            $token = $this->codeGenerator->voterToken();
        }

        try {
            // The address: self-paced, new players are counted per address and
            // room (VoteService::countNewPlayer).
            $this->voteService->quizJoin($room, $token, Input::str($this->request->getParam('nickname')), $this->request->getRemoteAddress());
        } catch (JoinLimitException $e) {
            // Self-paced: this address has added too many new players.
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_TOO_MANY_REQUESTS);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (RoomGoneException) {
            // Self-paced: deleted before the room lock took hold.
            return $this->roomNotFound();
        }

        $response = new JSONResponse($this->stateWithVersion($room, $token));
        $this->setVoterCookie($response, $token, $cookie);
        return $response;
    }

    /**
     * Cast a vote. Sets an anonymous voter cookie if needed and returns the
     * updated state. A vote without a cookie counts towards NEW_TOKEN_LIMIT.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function vote(string $code): JSONResponse {
        $forbidden = $this->refuseCrossSite();
        if ($forbidden !== null) {
            return $forbidden;
        }
        $room = $this->loadRoom($code);
        if ($room === null) {
            return $this->throttleUnknownCode();
        }

        $cookie = $this->voterCookie();
        $token = $cookie['token'];
        if ($token === null) {
            if (!$this->allowNewToken($room)) {
                return $this->tooManyRequests();
            }
            $token = $this->codeGenerator->voterToken();
        }

        try {
            // `keyboard` only extends the correction window (§8.0). The value
            // comes from the client and can therefore be forged — but it gives
            // no advantage: the correction resets the timestamp anyway.
            // Unusable (list, 1e100) = like an older phone without the field.
            $pollId = Input::int($this->request->getParam('pollId'));
            $this->voteService->recordVote(
                $room,
                $token,
                $this->request->getParam('value'),
                $this->request->getParam('keyboard') === true || $this->request->getParam('keyboard') === 'true',
                $pollId,
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (RoomGoneException) {
            return $this->roomNotFound();
        }

        $response = new JSONResponse($this->stateWithVersion($room, $token));
        // Set the cookie (again): httpOnly, 1 year, Lax is enough for a same-site POST.
        $this->setVoterCookie($response, $token, $cookie);
        return $response;
    }

    /**
     * Self-paced: fetch the next question — the only place where a clock
     * starts (PaceService::next). Body: { after } = progress.after from the
     * last state; only send it once a pending /vote has returned.
     * Idempotent: a stale `after` (double tap, second tab) is a
     * no-op with an unchanged state. Without a cookie there is no player and
     * nothing to start — so none is issued here (a legacy cookie is only
     * moved to its new name).
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function next(string $code): JSONResponse {
        $forbidden = $this->refuseCrossSite();
        if ($forbidden !== null) {
            return $forbidden;
        }
        $room = $this->loadRoom($code);
        if ($room === null) {
            return $this->throttleUnknownCode();
        }
        $cookie = $this->voterCookie();
        $token = $cookie['token'];
        if ($token === null) {
            return new JSONResponse(['message' => $this->l10n->t('Please choose a name first.')], Http::STATUS_BAD_REQUEST);
        }
        // JSON delivers a number, a form/query a string of digits.
        $after = $this->request->getParam('after');
        if (is_string($after) && preg_match('/^\d+$/', $after) === 1) {
            $after = (int)$after;
        }
        if (!is_int($after) || $after < 0) {
            return new JSONResponse(['message' => $this->l10n->t('Invalid request.')], Http::STATUS_BAD_REQUEST);
        }
        try {
            $this->paceService->next($room, $token, $after);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (RoomGoneException) {
            return $this->roomNotFound();
        }
        $response = new JSONResponse($this->stateWithVersion($room, $token));
        if ($cookie['adopted'] || $cookie['dropLegacy']) {
            $this->setVoterCookie($response, $token, $cookie);
        }
        return $response;
    }

    /**
     * Full state including the current change version (for the adaptive
     * client). In self-paced mode the version is the hash of the built state.
     * Moderated, the version is taken BEFORE the state, as in state(): a vote
     * committed in between then changes the next version (full poll) instead
     * of hiding behind a 204.
     */
    private function stateWithVersion(Room $room, #[\SensitiveParameter] string $token): array {
        if (PaceService::isSelf($room)) {
            $state = $this->stateService->publicState($room, $token);
            $state['version'] = $this->stateService->selfVersion($state);
            return $state;
        }
        $version = $this->stateService->stateVersion($room);
        $state = $this->stateService->publicState($room, $token);
        $state['version'] = $version;
        return $state;
    }

    private function loadRoom(string $code): ?Room {
        try {
            return $this->roomMapper->findByCode($code);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    // ── Cross-site requests ────────────────────────────────────────────────

    /**
     * 403 for a POST that does not come from a script of this origin (see the
     * class comment), null if it does. Runs before anything else — in
     * particular before a new token and its cookie are issued.
     */
    private function refuseCrossSite(): ?JSONResponse {
        if (strcasecmp($this->request->getHeader('X-Requested-With'), 'XMLHttpRequest') === 0) {
            return null;
        }
        $site = strtolower($this->request->getHeader('Sec-Fetch-Site'));
        if ($site === 'same-origin' || $site === 'none') {
            return null;
        }
        return new JSONResponse(['message' => $this->l10n->t('Invalid request.')], Http::STATUS_FORBIDDEN);
    }

    // ── Voter cookie ───────────────────────────────────────────────────────

    /**
     * The voter token of this request, read from the cookie — only in the form
     * that CodeGenerator::voterToken issues. Anything else counts as "no
     * cookie" (state/join/vote issue a new one): `pulse_vt[x]=…` arrives in PHP
     * as a list (TypeError, 500), an overlong or broken token failed on the
     * varchar(32) column or on the database's UTF-8 (500). A cookie name that
     * occurs more than once in the Cookie header counts as none as well: PHP
     * keeps only the first, and which one comes first is up to the browser —
     * a sibling subdomain adds a second one with a token it knows.
     *
     * On https with an empty webroot (hostCookie()) the cookie is
     * `__Host-pulse_vt`; if it was sent at all, it alone decides. Without it, a
     * single legacy `pulse_vt` is adopted during LEGACY_ADOPT_PERIOD (moved to
     * the new name by setVoterCookie()), so an update does not cost anyone
     * their name, points or progress. A legacy cookie that arrives is expired
     * with the next cookie-setting response.
     *
     * @return array{token: ?string, adopted: bool, dropLegacy: bool}
     *   token: null = no usable cookie; adopted: the token came from the legacy
     *   cookie; dropLegacy: a legacy cookie was sent and should be expired
     */
    private function voterCookie(): array {
        if (!$this->hostCookie()) {
            return ['token' => $this->cookieToken(Application::VOTER_COOKIE), 'adopted' => false, 'dropLegacy' => false];
        }
        $adoptable = $this->legacyAdoptable();
        $legacySent = $this->cookieSent(Application::VOTER_COOKIE);
        if ($this->cookieSent(self::HOST_COOKIE)) {
            return ['token' => $this->cookieToken(self::HOST_COOKIE), 'adopted' => false, 'dropLegacy' => $legacySent];
        }
        $legacy = $legacySent && $adoptable ? $this->cookieToken(Application::VOTER_COOKIE) : null;
        return ['token' => $legacy, 'adopted' => $legacy !== null, 'dropLegacy' => $legacySent];
    }

    /**
     * Cookie name `__Host-pulse_vt`? Only on https (overwriteprotocol and
     * trusted proxies included) and with an empty webroot: the prefix demands
     * Secure and Path=/, and Nextcloud sets every cookie with the webroot as
     * its path.
     */
    private function hostCookie(): bool {
        return $this->request->getServerProtocol() === 'https' && $this->urlGenerator->getWebroot() === '';
    }

    /**
     * Is a legacy cookie still adopted? The period starts with the first
     * request that uses the new name on this instance. A config value of the
     * wrong type (set by hand) ends it instead of turning every request into a 500.
     */
    private function legacyAdoptable(): bool {
        $now = $this->timeFactory->getTime();
        try {
            $since = $this->appConfig->getValueInt(Application::APP_ID, self::HOST_COOKIE_SINCE);
            if ($since <= 0) {
                $this->appConfig->setValueInt(Application::APP_ID, self::HOST_COOKIE_SINCE, $now);
                return true;
            }
        } catch (AppConfigException) {
            return false;
        }
        return $now - $since < self::LEGACY_ADOPT_PERIOD;
    }

    /** The token of cookie $name, or null if it is unusable or sent more than once. */
    private function cookieToken(string $name): ?string {
        $value = $this->cookieValue($name);
        if (!is_string($value) || !CodeGenerator::isVoterToken($value) || $this->cookieCount($name) > 1) {
            return null;
        }
        return $value;
    }

    private function cookieSent(string $name): bool {
        return $this->cookieValue($name) !== null || $this->cookieCount($name) > 0;
    }

    /** The only place that reads a voter cookie (ControllerInputTest checks this). */
    private function cookieValue(string $name): mixed {
        return $this->request->getCookie($name);
    }

    /**
     * How often cookie $name occurs in the raw Cookie header. Names are
     * compared the way PHP registers them: leading blanks dropped, ' ' and '.'
     * become '_', and `a[x]` is the array `a` (without a closing ']' the '['
     * becomes '_' as well) — so `pulse.vt` and `pulse_vt[x]` count as
     * `pulse_vt` too.
     *
     * Also like PHP (since 8.0.24, CVE-2022-31629): a name that starts with
     * `__Host-` or `__Secure-` only after that mangling is dropped, not
     * registered. Browsers apply no prefix rule to `_.Host-pulse_vt`, so any
     * sibling subdomain can set it for the whole domain; counting it as a
     * second `__Host-pulse_vt` turned every request into "no cookie" and
     * cost the participant their identity on every poll.
     */
    private function cookieCount(string $name): int {
        $header = $this->request->getHeader('Cookie');
        if ($header === '') {
            return 0;
        }
        $count = 0;
        foreach (explode(';', $header) as $pair) {
            $raw = ltrim(explode('=', $pair, 2)[0], " \t\n\r\v\f");
            $key = $raw;
            $bracket = strpos($key, '[');
            if ($bracket !== false && strpos($key, ']', $bracket) !== false) {
                $key = substr($key, 0, $bracket);
            }
            if (strtr($key, ' .[', '___') !== $name) {
                continue;
            }
            foreach (['__Host-', '__Secure-'] as $prefix) {
                if (str_starts_with($name, $prefix) && !str_starts_with($raw, $prefix)) {
                    continue 2;
                }
            }
            $count++;
        }
        return $count;
    }

    /**
     * Set the voter cookie under the current name (httpOnly, 1 year,
     * SameSite=Lax; Secure on https — Nextcloud adds those) and expire a
     * legacy cookie that came along.
     *
     * @param array{token: ?string, adopted: bool, dropLegacy: bool} $cookie
     */
    private function setVoterCookie(Response $response, #[\SensitiveParameter] string $token, array $cookie): void {
        $expires = $this->timeFactory->getDateTime()->add(new \DateInterval('P1Y'));
        $response->addCookie($this->hostCookie() ? self::HOST_COOKIE : Application::VOTER_COOKIE, $token, $expires, 'Lax');
        if ($cookie['dropLegacy']) {
            $response->invalidateCookie(Application::VOTER_COOKIE);
        }
    }

    /**
     * May this request get a NEW voter token? Counts towards NEW_TOKEN_LIMIT
     * per address and room.
     */
    private function allowNewToken(Room $room): bool {
        try {
            $this->limiter->registerAnonRequest(
                'pulse-token-' . $room->getId(),
                self::NEW_TOKEN_LIMIT,
                self::NEW_TOKEN_PERIOD,
                $this->request->getRemoteAddress(),
            );
        } catch (IRateLimitExceededException) {
            return false;
        }
        return true;
    }

    /**
     * May this request add a presence row? Asked by the heartbeat only when
     * the token has none in this room yet; counts towards NEW_PRESENCE_LIMIT
     * per address and room.
     */
    private function allowNewPresence(Room $room): bool {
        try {
            $this->limiter->registerAnonRequest(
                'pulse-presence-' . $room->getId(),
                self::NEW_PRESENCE_LIMIT,
                self::NEW_TOKEN_PERIOD,
                $this->request->getRemoteAddress(),
            );
        } catch (IRateLimitExceededException) {
            return false;
        }
        return true;
    }

    // ── Answers ────────────────────────────────────────────────────────────

    /**
     * Answer for a room code that does not exist: 404 and a failed attempt of
     * the brute-force action 'pulseRoomCode' for this address — or 429 once
     * the address has more than ten failed attempts within 30 minutes
     * (Nextcloud 34 no longer delays, it blocks). Only misses consult the
     * throttler: a request for an existing code never does, so neither typos
     * in the join form nor a deleted room can lock the phones behind one NAT
     * address out of the rooms that exist.
     *
     * See codeMissBlocked() for the order of check and attempt.
     */
    private function throttleUnknownCode(): JSONResponse {
        return self::codeMissBlocked($this->throttler, $this->request->getRemoteAddress())
            ? $this->tooManyRequests()
            : $this->roomNotFound();
    }

    /**
     * An unknown room code from $ip: true = blocked (429). Shared with
     * PublicController.
     *
     * An address that is already blocked gets its 429 WITHOUT another attempt.
     * Nextcloud's own middleware does the same; recording every blocked
     * request instead kept the block alive for as long as anything behind the
     * address kept asking — a phone or a projector still polling a deleted
     * room retries for ever — and grew the list of attempts that Nextcloud
     * reads for this address on every brute-force check, its login included.
     * Otherwise the miss is recorded and checked once more, so that the
     * eleventh miss is the first 429.
     */
    public static function codeMissBlocked(IThrottler $throttler, string $ip): bool {
        try {
            $throttler->sleepDelayOrThrowOnMax($ip, self::CODE_ACTION);
            $throttler->registerAttempt(self::CODE_ACTION, $ip);
            $throttler->sleepDelayOrThrowOnMax($ip, self::CODE_ACTION);
        } catch (MaxDelayReached) {
            return true;
        }
        return false;
    }

    /**
     * 404 "Room not found." without a brute-force attempt: the room existed
     * when the request came in and was deleted while it ran (RoomGoneException).
     * The next poll hits throttleUnknownCode() anyway.
     */
    private function roomNotFound(): JSONResponse {
        return new JSONResponse(['message' => $this->l10n->t('Room not found.')], Http::STATUS_NOT_FOUND);
    }

    private function tooManyRequests(): JSONResponse {
        return new JSONResponse(['message' => $this->l10n->t('Too many attempts. Please wait a moment.')], Http::STATUS_TOO_MANY_REQUESTS);
    }
}
