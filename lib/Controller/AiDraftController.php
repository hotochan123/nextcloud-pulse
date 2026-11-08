<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Controller;

use OCA\Pulse\AppInfo\Application;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Service\AiDraftException;
use OCA\Pulse\Service\AiPolicy;
use OCA\Pulse\Service\AiDraftService;
use OCA\Pulse\Service\ConflictException;
use OCA\Pulse\Service\Limits;
use OCA\Pulse\Service\NotOwnerException;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\RoomService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;

/**
 * AI quiz draft of the composer (AiDraftService). Login required, CSRF on,
 * like the rest of the moderator API. Someone else's room answers exactly
 * like an unknown code (404 "Room not found."), as in RoomApiController.
 * Starting a draft is rate-limited per account (Limits::RATE_AI_DRAFT).
 */
class AiDraftController extends Controller {
    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private RoomService $roomService,
        private PaceService $paceService,
        private AiDraftService $drafts,
        private IL10N $l10n,
        private ILimiter $limiter,
        private Limits $limits,
        private AiPolicy $policy,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * {available, notice}: available = the admin allows AI and a provider for
     * text generation is set up (the composer shows the AI button only then);
     * notice = this account confirmed the AI notice.
     */
    #[NoAdminRequired]
    public function available(): JSONResponse {
        return new JSONResponse(['available' => $this->drafts->available($this->uid()), 'notice' => $this->policy->noticeConfirmed($this->uid())]);
    }

    /** The account confirms the AI notice (once, before its first AI use). {notice: true} */
    #[NoAdminRequired]
    public function notice(): JSONResponse {
        $this->policy->confirmNotice($this->uid());
        return new JSONResponse(['notice' => true]);
    }

    /** Body {topic?, path?, count?}; 201 {task}. */
    #[NoAdminRequired]
    public function start(string $code): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user !== null) {
            try {
                $this->limiter->registerUserRequest('pulse-' . Limits::RATE_AI_DRAFT, $this->limits->rate(Limits::RATE_AI_DRAFT), Limits::RATE_PERIOD, $user);
            } catch (IRateLimitExceededException) {
                return new JSONResponse(['message' => $this->l10n->t('Too many attempts. Please wait a moment.')], Http::STATUS_TOO_MANY_REQUESTS);
            }
        }
        return $this->withRoom($code, function (Room $room): JSONResponse {
            // a self-paced quiz that is open (or was opened) takes no new questions
            if (PaceService::isSelf($room)) {
                $this->paceService->assertDeckEditable($room);
            }
            $data = $this->drafts->start($room, $this->uid(),
                $this->request->getParam('topic'), $this->request->getParam('path'), $this->request->getParam('count'));
            return new JSONResponse($data, Http::STATUS_CREATED);
        });
    }

    /** {status: running|failed|done, message?, questions?} */
    #[NoAdminRequired]
    public function status(string $code, int $task): JSONResponse {
        return $this->withRoom($code, fn (Room $room): JSONResponse => new JSONResponse($this->drafts->status($room, $this->uid(), $task)));
    }

    #[NoAdminRequired]
    public function cancel(string $code, int $task): JSONResponse {
        return $this->withRoom($code, function (Room $room) use ($task): JSONResponse {
            $this->drafts->cancel($room, $this->uid(), $task);
            return new JSONResponse([]);
        });
    }

    private function uid(): string {
        return $this->userSession->getUser()?->getUID() ?? '';
    }

    /** @param callable(Room): JSONResponse $fn */
    private function withRoom(string $code, callable $fn): JSONResponse {
        try {
            $room = $this->roomService->getOwnedRoom($code, $this->uid());
        } catch (NotOwnerException|DoesNotExistException) {
            return new JSONResponse(['message' => $this->l10n->t('Room not found.')], Http::STATUS_NOT_FOUND);
        }
        try {
            return $fn($room);
        } catch (AiDraftException $e) {
            return new JSONResponse(['message' => $e->getMessage()] + $e->data(), $e->status());
        } catch (ConflictException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
        }
    }
}
