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
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\RoomGoneException;
use OCA\Pulse\Service\RoomService;
use OCA\Pulse\Service\StateService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;

/**
 * Public participant API — no login, no CSRF token (the audience has
 * no Nextcloud session). Voting twice is prevented by an anonymous cookie token,
 * not by identity. The same pattern as for share links.
 *
 * Self-paced quiz (PaceService::isSelf): /state builds the state before
 * the version (there is no cheap fingerprint), /join is limited per IP and
 * room, and /next starts the person's next question. Moderated
 * rooms work as before.
 *
 * Parameters and cookie come raw from the client: Input and voterToken() turn
 * anything unusable into "not sent" (never a PHP warning, never a 500).
 *
 * Section references (§…) point to the design notes of the redesign, which are
 * not in the public repository (see "References in code comments" in the
 * README).
 */
class PublicVoteController extends Controller {
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
    #[BruteForceProtection(action: 'pulseRoomCode')]
    public function state(string $code): Response {
        $room = $this->loadRoom($code);
        if ($room === null) {
            return $this->notFound();
        }

        // The projector/audience view polls with ?spectate=1: a pure spectator, does
        // not count as a participant and gets no cookie (otherwise the
        // projecting computer would skew the "here" count).
        $spectate = $this->request->getParam('spectate') === '1';
        $token = $this->voterToken();
        $isNewToken = $token === null;
        if ($spectate) {
            $isNewToken = false; // no cookie issued, no heartbeat
        } else {
            // People who are only watching (no vote yet) should count too: if the
            // cookie is missing, issue one here and send it along.
            if ($isNewToken) {
                $token = $this->codeGenerator->voterToken();
            }
            $this->roomService->heartbeat($room, $token);
        }

        $clientVersion = Input::str($this->request->getParam('v'));
        if (PaceService::isSelf($room)) {
            // Self-paced: no cheap fingerprint — build the state; the
            // version is its hash (deadline, finality, time-out are part of it).
            $state = $this->stateService->publicState($room, $token, $spectate);
            $version = $this->stateService->selfVersion($state);
            if (!$isNewToken && $clientVersion !== '' && $clientVersion === $version) {
                $unchanged = new Response();
                $unchanged->setStatus(Http::STATUS_NO_CONTENT);
                return $unchanged;
            }
        } else {
            // Unchanged since the last poll? -> 204, without building the tally.
            // (A new token always gets the full state + cookie.)
            // Spectators also see the presence (the stage's intake display) and
            // therefore need it in the fingerprint as well.
            $version = $this->stateService->stateVersion($room, $spectate);
            if (!$isNewToken && $clientVersion !== '' && $clientVersion === $version) {
                $unchanged = new Response();
                $unchanged->setStatus(Http::STATUS_NO_CONTENT);
                return $unchanged;
            }
            $state = $this->stateService->publicState($room, $token, $spectate);
        }
        $state['version'] = $version;
        $response = new JSONResponse($state);
        if ($isNewToken) {
            $expires = $this->timeFactory->getDateTime()->add(new \DateInterval('P1Y'));
            $response->addCookie(Application::VOTER_COOKIE, $token, $expires, 'Lax');
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
    #[BruteForceProtection(action: 'pulseRoomCode')]
    public function summary(string $code): JSONResponse {
        $room = $this->loadRoom($code);
        if ($room === null) {
            return $this->notFound();
        }
        return new JSONResponse($this->stateService->publicSummary($room, $this->voterToken()));
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
    #[BruteForceProtection(action: 'pulseRoomCode')]
    public function image(string $code, int $pollId): Response {
        $room = $this->loadRoom($code);
        if ($room === null) {
            return $this->notFound();
        }
        try {
            $poll = $this->deckService->requirePollInRoom($room, $pollId);
        } catch (\InvalidArgumentException) {
            return new JSONResponse(['message' => $this->l10n->t('Not found.')], Http::STATUS_NOT_FOUND);
        }
        if (!$this->stateService->imageVisible($room, $poll, $this->voterToken())) {
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
     * In self-paced mode limited per IP and room (PaceService::JOIN_LIMIT per
     * JOIN_PERIOD): otherwise a throwaway identity costs nothing there. Not in
     * moderated mode — a conference behind one NAT IP can have more joins.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: 'pulseRoomCode')]
    public function join(string $code): JSONResponse {
        $room = $this->loadRoom($code);
        if ($room === null) {
            return $this->notFound();
        }
        if (PaceService::isSelf($room)) {
            try {
                $this->limiter->registerAnonRequest(
                    'pulse-join-' . $room->getId(),
                    PaceService::JOIN_LIMIT,
                    PaceService::JOIN_PERIOD,
                    $this->request->getRemoteAddress(),
                );
            } catch (IRateLimitExceededException) {
                return new JSONResponse(['message' => $this->l10n->t('Too many attempts. Please wait a moment.')], Http::STATUS_TOO_MANY_REQUESTS);
            }
        }

        $token = $this->voterToken() ?? $this->codeGenerator->voterToken();

        try {
            $this->voteService->quizJoin($room, $token, Input::str($this->request->getParam('nickname')));
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (RoomGoneException) {
            // Self-paced: deleted before the room lock took hold.
            return $this->notFound();
        }

        $response = new JSONResponse($this->stateWithVersion($room, $token));
        $expires = $this->timeFactory->getDateTime()->add(new \DateInterval('P1Y'));
        $response->addCookie(Application::VOTER_COOKIE, $token, $expires, 'Lax');
        return $response;
    }

    /**
     * Cast a vote. Sets an anonymous voter cookie if needed and returns the
     * updated state.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: 'pulseRoomCode')]
    public function vote(string $code): JSONResponse {
        $room = $this->loadRoom($code);
        if ($room === null) {
            return $this->notFound();
        }

        $token = $this->voterToken() ?? $this->codeGenerator->voterToken();

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
            return $this->notFound();
        }

        $response = new JSONResponse($this->stateWithVersion($room, $token));
        // Set the cookie (again): httpOnly, 1 year, Lax is enough for a same-site POST.
        $expires = $this->timeFactory->getDateTime()->add(new \DateInterval('P1Y'));
        $response->addCookie(Application::VOTER_COOKIE, $token, $expires, 'Lax');
        return $response;
    }

    /**
     * Self-paced: fetch the next question — the only place where a clock
     * starts (PaceService::next). Body: { after } = progress.after from the
     * last state; only send it once a pending /vote has returned.
     * Idempotent: a stale `after` (double tap, second tab) is a
     * no-op with an unchanged state. Without a cookie there is no player and
     * nothing to start — so none is issued here.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: 'pulseRoomCode')]
    public function next(string $code): JSONResponse {
        $room = $this->loadRoom($code);
        if ($room === null) {
            return $this->notFound();
        }
        $token = $this->voterToken();
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
            return $this->notFound();
        }
        return new JSONResponse($this->stateWithVersion($room, $token));
    }

    /**
     * Full state including the current change version (for the adaptive
     * client). In self-paced mode the version is the hash of the built state.
     */
    private function stateWithVersion(Room $room, string $token): array {
        $state = $this->stateService->publicState($room, $token);
        $state['version'] = PaceService::isSelf($room)
            ? $this->stateService->selfVersion($state)
            : $this->stateService->stateVersion($room);
        return $state;
    }

    private function loadRoom(string $code): ?Room {
        try {
            return $this->roomMapper->findByCode($code);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Voter token from the cookie — only in the form that CodeGenerator::voterToken
     * issues. Anything else counts as "no cookie" (state/join/vote issue a
     * new one): `pulse_vt[x]=…` arrives in PHP as a list (TypeError, 500), an
     * overlong or broken token failed on the varchar(32) column or on the
     * database's UTF-8 (500).
     */
    private function voterToken(): ?string {
        $token = $this->request->getCookie(Application::VOTER_COOKIE);
        return is_string($token) && CodeGenerator::isVoterToken($token) ? $token : null;
    }

    /**
     * 404 for an unknown code — marked as a failed brute-force attempt.
     * Applies ONLY to wrong codes; valid codes (real participants) are
     * never throttled. After ~10 failed attempts per IP the delay grows.
     */
    private function notFound(): JSONResponse {
        $response = new JSONResponse(['message' => $this->l10n->t('Room not found.')], Http::STATUS_NOT_FOUND);
        $response->throttle(['action' => 'pulseRoomCode']);
        return $response;
    }
}
