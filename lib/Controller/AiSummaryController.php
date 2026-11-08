<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Controller;

use OCA\Pulse\AppInfo\Application;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Service\AiDraftException;
use OCA\Pulse\Service\AiSummaryService;
use OCA\Pulse\Service\FilesExportException;
use OCA\Pulse\Service\FilesExportService;
use OCA\Pulse\Service\Limits;
use OCA\Pulse\Service\NotOwnerException;
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
 * AI summary on the results overview (AiSummaryService) and saving the
 * edited text into Files. Login required, CSRF on, like the rest of the
 * moderator API; someone else's room answers like an unknown code (404).
 * Starting is rate-limited per account (Limits::RATE_AI_SUMMARY), saving
 * like the CSV (Limits::RATE_SAVE_FILE).
 */
class AiSummaryController extends Controller {
    /** Characters of the text the moderator saves. */
    public const TEXT_MAX = 100000;

    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private RoomService $roomService,
        private AiSummaryService $summaries,
        private FilesExportService $filesExport,
        private IL10N $l10n,
        private ILimiter $limiter,
        private Limits $limits,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /** 201 {task}, or 200 {status: done, text} when nothing needs the AI. */
    #[NoAdminRequired]
    public function start(string $code): JSONResponse {
        if ($limited = $this->rateLimited(Limits::RATE_AI_SUMMARY)) {
            return $limited;
        }
        return $this->withRoom($code, function (Room $room): JSONResponse {
            $data = $this->summaries->start($room, $this->uid());
            return new JSONResponse($data, isset($data['task']) ? Http::STATUS_CREATED : Http::STATUS_OK);
        });
    }

    /** {status: running|failed|done, message?, text?} */
    #[NoAdminRequired]
    public function status(string $code, int $task): JSONResponse {
        return $this->withRoom($code, fn (Room $room): JSONResponse => new JSONResponse($this->summaries->status($room, $this->uid(), $task)));
    }

    #[NoAdminRequired]
    public function cancel(string $code, int $task): JSONResponse {
        return $this->withRoom($code, function (Room $room) use ($task): JSONResponse {
            $this->summaries->cancel($room, $this->uid(), $task);
            return new JSONResponse([]);
        });
    }

    /** Body {text}; 201 {fileId, name, path}, a Files problem 409. */
    #[NoAdminRequired]
    public function save(string $code): JSONResponse {
        if ($limited = $this->rateLimited(Limits::RATE_SAVE_FILE)) {
            return $limited;
        }
        return $this->withRoom($code, function (Room $room): JSONResponse {
            $text = $this->request->getParam('text');
            if (!is_string($text) || trim($text) === '' || mb_strlen($text) > self::TEXT_MAX) {
                return new JSONResponse(['message' => $this->l10n->t('The summary is empty or too long.')], Http::STATUS_BAD_REQUEST);
            }
            try {
                return new JSONResponse($this->filesExport->saveSummary($room, $this->uid(), $text), Http::STATUS_CREATED);
            } catch (FilesExportException $e) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
            }
        });
    }

    private function uid(): string {
        return $this->userSession->getUser()?->getUID() ?? '';
    }

    private function rateLimited(string $action): ?JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }
        try {
            $this->limiter->registerUserRequest('pulse-' . $action, $this->limits->rate($action), Limits::RATE_PERIOD, $user);
        } catch (IRateLimitExceededException) {
            return new JSONResponse(['message' => $this->l10n->t('Too many attempts. Please wait a moment.')], Http::STATUS_TOO_MANY_REQUESTS);
        }
        return null;
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
        }
    }
}
