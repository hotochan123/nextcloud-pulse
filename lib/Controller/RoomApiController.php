<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Controller;

use OCA\Pulse\AppInfo\Application;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Service\ConflictException;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\DemoService;
use OCA\Pulse\Service\Input;
use OCA\Pulse\Service\NotOwnerException;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\PaceStateService;
use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\RoomGoneException;
use OCA\Pulse\Service\RoomService;
use OCA\Pulse\Service\StateService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Moderator API. Login required; CSRF protection stays on (the SPA sends the
 * Nextcloud request token along automatically). #[NoAdminRequired] so that not
 * only admins can create rooms.
 *
 * Self-paced quiz (PaceService::isSelf): every writing action runs
 * under the room lock (PaceService::locked); its guard checks the freshly
 * locked row — not the room that withRoom read earlier. That way there is
 * no check-then-act between two tabs or between add-in and browser.
 * Moderated rooms still run without a transaction, as before.
 *
 * Retention: every request that names one of the owner's rooms loads it
 * through ownedRoom() and thereby records owner activity (touch) — reading
 * and writing alike, in every room mode. See RoomService::lastActivity.
 */
class RoomApiController extends Controller {
    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private RoomService $roomService,
        private DeckService $deckService,
        private StateService $stateService,
        private VoteService $voteService,
        private DemoService $demoService,
        private PollImageService $imageService,
        private IL10N $l10n,
        // only touched in self-paced mode:
        private PaceService $paceService,
        private PaceStateService $paceState,
        private RoomMapper $roomMapper,          // touch (owner activity, any room)
        private ITimeFactory $timeFactory,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function index(): JSONResponse {
        return new JSONResponse($this->roomService->listRooms($this->uid()));
    }

    /** Overall summary: all questions with their tally. */
    #[NoAdminRequired]
    public function summary(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            return new JSONResponse($this->stateService->summary($room));
        });
    }

    /**
     * Results as a CSV download (no withRoom: returns a DataDownloadResponse).
     * In self-paced mode additionally `?view=players` (one row per person) and
     * `?view=answers` (one row per vote, with times); moderated rooms have
     * no per-person times, so the parameter is ignored there.
     */
    #[NoAdminRequired]
    public function exportCsv(string $code): Response {
        try {
            $room = $this->ownedRoom($code);
        } catch (DoesNotExistException) {
            return new JSONResponse(['message' => $this->l10n->t('Room not found.')], Http::STATUS_NOT_FOUND);
        } catch (NotOwnerException) {
            return new JSONResponse(['message' => $this->l10n->t('No access to this room.')], Http::STATUS_FORBIDDEN);
        }
        $view = Input::str($this->request->getParam('view'));
        if (PaceService::isSelf($room) && ($view === 'players' || $view === 'answers')) {
            // One literal t() per word — otherwise build/l10n-check.js reports the
            // translation as orphaned.
            $label = match ($view) {
                'players' => $this->l10n->t('players'),
                'answers' => $this->l10n->t('answers'),
            };
            return new DataDownloadResponse(
                $this->paceState->exportCsv($room, $view),
                'pulse-' . $room->getCode() . '-' . $label . '.csv',
                'text/csv; charset=utf-8',
            );
        }
        return new DataDownloadResponse(
            $this->stateService->exportCsv($room),
            'pulse-' . $room->getCode() . '-' . $this->l10n->t('results') . '.csv',
            'text/csv; charset=utf-8',
        );
    }

    #[NoAdminRequired]
    public function create(): JSONResponse {
        $mode = Input::str($this->request->getParam('mode'), 'poll');
        $title = Input::str($this->request->getParam('title'));
        $room = $this->roomService->createRoom($this->uid(), $mode, $title);
        return new JSONResponse($room, Http::STATUS_CREATED);
    }

    /** Copy a room as a template: questions yes, votes and participants no. */
    #[NoAdminRequired]
    public function duplicate(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            $copy = $this->roomService->duplicateRoom($room, $this->uid());
            return new JSONResponse($this->deckService->roomView($copy), Http::STATUS_CREATED);
        });
    }

    /** Rename a room; an empty title removes it again. */
    #[NoAdminRequired]
    public function rename(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            $this->roomService->setTitle($room, Input::str($this->request->getParam('title')));
            return new JSONResponse($this->deckService->roomView($room));
        });
    }

    /** Quiz leaderboard (moderator; without the "me" marker). */
    #[NoAdminRequired]
    public function leaderboard(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            return new JSONResponse($this->voteService->leaderboardFor($room, null));
        });
    }

    #[NoAdminRequired]
    public function show(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            return new JSONResponse($this->deckService->roomView($room));
        });
    }

    #[NoAdminRequired]
    public function destroy(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            $this->roomService->deleteRoom($room);
            return new JSONResponse([]);
        });
    }

    /** Empty the whole room: votes + participants + leaderboard gone, questions stay. */
    #[NoAdminRequired]
    public function resetRoom(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            $room = $this->notOpen($room, function (Room $r): Room {
                $this->roomService->resetRoom($r);
                return $r;
            });
            return new JSONResponse($this->deckService->roomView($room));
        });
    }

    /** Switch the practice run on/off. Body: { on: bool }. Empties the room when switching. */
    #[NoAdminRequired]
    public function practice(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            $on = Input::flag($this->request->getParam('on')) ?? false;
            $room = $this->notOpen($room, function (Room $r) use ($on): Room {
                $this->roomService->setPractice($r, $on);
                return $r;
            });
            return new JSONResponse($this->deckService->roomView($room));
        });
    }

    /** Switch reveal only at the end (instead of per question) on/off. Body: { on: bool }. */
    #[NoAdminRequired]
    public function reveal(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            $on = Input::flag($this->request->getParam('on')) ?? false;
            $this->roomService->setRevealAtEnd($room, $on);
            return new JSONResponse($this->deckService->roomView($room));
        });
    }

    /** End the quiz: mark the current question as ended -> releases the final standings. */
    #[NoAdminRequired]
    public function endQuiz(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            $this->liveControl($room, fn (Room $r) => $this->roomService->endQuiz($r));
            return new JSONResponse([]);
        });
    }

    /**
     * Control a self-paced quiz. Body: { action, … } with
     * `set` {pace} · `open` {closesAt, timed, feedback} · `close` {release} ·
     * `extend` {closesAt} · `release` · `lockJoins` · `unlockJoins` ·
     * `removePlayer` {playerId}. Settings missing when opening take the
     * default (a race without a deadline, homework with one). `close` without
     * `release` releases, as before, exactly when there is no deadline (old
     * tabs, API clients); `release: false` closes without releasing ("Stop
     * without releasing", a single call), `release: true` always releases on closing.
     * Every action runs under the room lock (PaceService). Response: the room
     * as for GET /rooms/{code}, including `window`.
     *
     * Each parameter is read only in the branch of its action — whatever another
     * action sends along does no harm. Unusable input (list, object, 1e999) is a
     * 400 before the room lock is taken; text that the service checks anyway
     * (`pace`, `feedback`) arrives as '' and fails there ("Unknown …").
     */
    #[NoAdminRequired]
    public function pace(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            $action = Input::str($this->request->getParam('action'));
            try {
                $room = match ($action) {
                    'set' => $this->paceService->setPace($room, Input::str($this->request->getParam('pace'))),
                    'open' => $this->paceService->openWindow(
                        $room,
                        $this->closesAt(),
                        $this->optFlag('timed'),
                        // missing = default; a list becomes '' -> "Unknown feedback setting."
                        $this->request->getParam('feedback') === null ? null : Input::str($this->request->getParam('feedback')),
                    ),
                    'close' => $this->paceService->closeWindow($room, $this->optFlag('release')),
                    'extend' => $this->paceService->extendWindow($room, $this->closesAt()),
                    'release' => $this->paceService->releaseWindow($room),
                    'lockJoins' => $this->paceService->setJoinsLocked($room, true),
                    'unlockJoins' => $this->paceService->setJoinsLocked($room, false),
                    'removePlayer' => $this->paceService->removePlayer($room, Input::int($this->request->getParam('playerId')) ?? 0),
                    default => throw new \InvalidArgumentException($this->l10n->t('Unknown action.')),
                };
            } catch (\InvalidArgumentException $e) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            }
            return new JSONResponse($this->deckService->roomView($room));
        });
    }

    /**
     * Self-paced progress: per person and per question (race as well as
     * homework), polled adaptively with `?v=` like results(). `?scores=1` shows
     * points even with feedback "At the end" — an explicit switch, because the
     * laptop is often connected to the projector. No withRoom: can also return 204.
     */
    #[NoAdminRequired]
    public function progress(string $code): Response {
        try {
            $room = $this->ownedRoom($code);
        } catch (DoesNotExistException) {
            return new JSONResponse(['message' => $this->l10n->t('Room not found.')], Http::STATUS_NOT_FOUND);
        } catch (NotOwnerException) {
            return new JSONResponse(['message' => $this->l10n->t('No access to this room.')], Http::STATUS_FORBIDDEN);
        }
        if (!PaceService::isSelf($room)) {
            return new JSONResponse(['message' => $this->l10n->t('This room is not self-paced.')], Http::STATUS_CONFLICT);
        }
        $scores = Input::flag($this->request->getParam('scores')) ?? false;
        $data = $this->paceState->progress($room, $scores);
        // Version from the finished payload — without serverNow and "last
        // seen" (otherwise every phone heartbeat would change it, see PaceStateService::version).
        $version = $this->paceState->version($data);
        $clientVersion = Input::str($this->request->getParam('v'));
        if ($clientVersion !== '' && $clientVersion === $version) {
            $unchanged = new Response();
            $unchanged->setStatus(Http::STATUS_NO_CONTENT);
            return $unchanged;
        }
        $data['version'] = $version;
        return new JSONResponse($data);
    }

    #[NoAdminRequired]
    public function addPoll(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            try {
                $poll = $this->deckChange($room, fn (Room $r): Poll => $this->deckService->addPoll($r, [
                    'type' => $this->request->getParam('type'),
                    'question' => $this->request->getParam('question'),
                    'options' => $this->request->getParam('options'),
                    'maxWords' => $this->request->getParam('maxWords'),
                    'scaleMax' => $this->request->getParam('scaleMax'),
                    'scaleMode' => $this->request->getParam('scaleMode'),
                    'aspects' => $this->request->getParam('aspects'),
                    'range' => $this->request->getParam('range'),
                    'axisX' => $this->request->getParam('axisX'),
                    'axisY' => $this->request->getParam('axisY'),
                    'cornerLabels' => $this->request->getParam('cornerLabels'),
                    'heatmapThreshold' => $this->request->getParam('heatmapThreshold'),
                    'minLabel' => $this->request->getParam('minLabel'),
                    'maxLabel' => $this->request->getParam('maxLabel'),
                    'correctIndex' => $this->request->getParam('correctIndex'),
                    'correctIndexes' => $this->request->getParam('correctIndexes'),
                    'target' => $this->request->getParam('target'),
                    'tolerance' => $this->request->getParam('tolerance'),
                    'answers' => $this->request->getParam('answers'),
                    'pairs' => $this->request->getParam('pairs'),
                    'timeLimit' => $this->request->getParam('timeLimit'),
                ]));
            } catch (\InvalidArgumentException $e) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            }
            return new JSONResponse($this->deckService->ownerPoll($poll), Http::STATUS_CREATED);
        });
    }

    #[NoAdminRequired]
    public function updatePoll(string $code, int $pollId): JSONResponse {
        return $this->withRoom($code, function ($room) use ($pollId) {
            try {
                $poll = $this->deckChange($room, fn (Room $r): Poll => $this->deckService->updatePoll($r, $pollId, [
                    'type' => $this->request->getParam('type'),
                    'question' => $this->request->getParam('question'),
                    'options' => $this->request->getParam('options'),
                    'maxWords' => $this->request->getParam('maxWords'),
                    'scaleMax' => $this->request->getParam('scaleMax'),
                    'scaleMode' => $this->request->getParam('scaleMode'),
                    'aspects' => $this->request->getParam('aspects'),
                    'range' => $this->request->getParam('range'),
                    'axisX' => $this->request->getParam('axisX'),
                    'axisY' => $this->request->getParam('axisY'),
                    'cornerLabels' => $this->request->getParam('cornerLabels'),
                    'heatmapThreshold' => $this->request->getParam('heatmapThreshold'),
                    'minLabel' => $this->request->getParam('minLabel'),
                    'maxLabel' => $this->request->getParam('maxLabel'),
                    'correctIndex' => $this->request->getParam('correctIndex'),
                    'correctIndexes' => $this->request->getParam('correctIndexes'),
                    'target' => $this->request->getParam('target'),
                    'tolerance' => $this->request->getParam('tolerance'),
                    'answers' => $this->request->getParam('answers'),
                    'pairs' => $this->request->getParam('pairs'),
                    'timeLimit' => $this->request->getParam('timeLimit'),
                ]));
            } catch (\InvalidArgumentException $e) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            }
            return new JSONResponse($this->deckService->ownerPoll($poll));
        });
    }

    /** Reorder the deck. Body: { order: [pollId, pollId, …] }. */
    #[NoAdminRequired]
    public function reorder(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            $order = $this->request->getParam('order', []);
            if (!is_array($order)) {
                return new JSONResponse(['message' => $this->l10n->t('Invalid order.')], Http::STATUS_BAD_REQUEST);
            }
            try {
                $this->deckChange($room, fn (Room $r) => $this->deckService->reorder($r, $order));
            } catch (\InvalidArgumentException $e) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            }
            return new JSONResponse([]);
        });
    }

    /** Set the cursor (deck navigation). pollId=0 or missing => presentation is idle. */
    #[NoAdminRequired]
    public function setCurrent(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            // 0 or missing = presentation is idle; unusable input (list, text, 1e100)
            // must not silently mean that.
            $raw = $this->request->getParam('pollId');
            $pollId = $raw === null ? 0 : Input::int($raw);
            if ($pollId === null) {
                return new JSONResponse(['message' => $this->l10n->t('Invalid request.')], Http::STATUS_BAD_REQUEST);
            }
            try {
                $this->liveControl($room, fn (Room $r) => $this->deckService->setCurrent($r, $pollId));
            } catch (\InvalidArgumentException $e) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
            }
            return new JSONResponse(['activePollId' => $pollId]);
        });
    }

    #[NoAdminRequired]
    public function deletePoll(string $code, int $pollId): JSONResponse {
        return $this->withRoom($code, function ($room) use ($pollId) {
            try {
                $this->deckChange($room, fn (Room $r) => $this->deckService->deletePoll($r, $pollId));
            } catch (\InvalidArgumentException $e) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
            }
            return new JSONResponse([]);
        });
    }

    #[NoAdminRequired]
    public function results(string $code, int $pollId): Response {
        try {
            $room = $this->ownedRoom($code);
        } catch (DoesNotExistException) {
            return new JSONResponse(['message' => $this->l10n->t('Room not found.')], Http::STATUS_NOT_FOUND);
        } catch (NotOwnerException) {
            return new JSONResponse(['message' => $this->l10n->t('No access to this room.')], Http::STATUS_FORBIDDEN);
        }
        try {
            $version = $this->stateService->resultsVersion($room, $pollId);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
        // Unchanged since the last poll? -> 204, without building tally/leaderboard.
        $clientVersion = Input::str($this->request->getParam('v'));
        if ($clientVersion !== '' && $clientVersion === $version) {
            $unchanged = new Response();
            $unchanged->setStatus(Http::STATUS_NO_CONTENT);
            return $unchanged;
        }
        $data = $this->stateService->results($room, $pollId);
        $data['version'] = $version;
        return new JSONResponse($data);
    }

    #[NoAdminRequired]
    public function resetPoll(string $code, int $pollId): JSONResponse {
        return $this->withRoom($code, function ($room) use ($pollId) {
            try {
                $this->deckChange($room, fn (Room $r) => $this->deckService->resetPoll($r, $pollId));
            } catch (\InvalidArgumentException $e) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
            }
            return new JSONResponse([]);
        });
    }

    /** Grade a free-text answer. Body: { answer: string, correct: bool }. */
    #[NoAdminRequired]
    public function gradeAnswer(string $code, int $pollId): JSONResponse {
        return $this->withRoom($code, function ($room) use ($pollId) {
            // With NUL, exactly as the vote is stored (Input::rawStr).
            $answer = Input::rawStr($this->request->getParam('answer'));
            $correct = Input::flag($this->request->getParam('correct')) ?? false;
            try {
                $this->voteService->gradeTextAnswer($room, $pollId, $answer, $correct);
            } catch (\InvalidArgumentException $e) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            }
            return new JSONResponse($this->stateService->results($room, $pollId));
        });
    }

    /**
     * Upload an image for the question (multipart, field name `image`). Replaces an
     * existing one. The image is re-encoded on the server — see
     * PollImageService.
     */
    #[NoAdminRequired]
    public function uploadImage(string $code, int $pollId): JSONResponse {
        return $this->withRoom($code, function ($room) use ($pollId) {
            try {
                // null = no image sent along (response below)
                $poll = $this->deckChange($room, function (Room $r) use ($pollId): ?Poll {
                    $poll = $this->deckService->requirePollInRoom($r, $pollId);
                    $upload = $this->request->getUploadedFile('image');
                    return is_array($upload) ? $this->imageService->store($poll, $upload) : null;
                });
            } catch (\InvalidArgumentException $e) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            }
            if ($poll === null) {
                return new JSONResponse(['message' => $this->l10n->t('No image received.')], Http::STATUS_BAD_REQUEST);
            }
            return new JSONResponse($this->deckService->ownerPoll($poll));
        });
    }

    /** Remove the question's image. */
    #[NoAdminRequired]
    public function deleteImage(string $code, int $pollId): JSONResponse {
        return $this->withRoom($code, function ($room) use ($pollId) {
            try {
                $poll = $this->deckChange($room, fn (Room $r): Poll => $this->imageService->remove(
                    $this->deckService->requirePollInRoom($r, $pollId),
                ));
            } catch (\InvalidArgumentException $e) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
            }
            return new JSONResponse($this->deckService->ownerPoll($poll));
        });
    }

    /**
     * Serve the image — moderator view (deck editor, preview): without the
     * visibility barrier of the public route, because whoever owns the room
     * knows its questions anyway.
     *
     * NoCSRFRequired is mandatory here, not a convenience switch: the URL
     * sits in an <img src>, and the browser attaches no request token to such a
     * request -> Nextcloud answered with 412 "CSRF check failed", and the image
     * stayed empty in the moderator. Harmless, because the route only reads and
     * checks the owner.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function showImage(string $code, int $pollId): Response {
        try {
            $room = $this->ownedRoom($code);
            $poll = $this->deckService->requirePollInRoom($room, $pollId);
        } catch (DoesNotExistException | \InvalidArgumentException) {
            return new JSONResponse(['message' => $this->l10n->t('Not found.')], Http::STATUS_NOT_FOUND);
        } catch (NotOwnerException) {
            return new JSONResponse(['message' => $this->l10n->t('No access to this room.')], Http::STATUS_FORBIDDEN);
        }
        $img = $this->imageService->read($poll);
        if ($img === null) {
            return new JSONResponse(['message' => $this->l10n->t('No image.')], Http::STATUS_NOT_FOUND);
        }
        return new DataDisplayResponse($img['content'], Http::STATUS_OK, ['Content-Type' => $img['mime']]);
    }

    #[NoAdminRequired]
    public function lockPoll(string $code, int $pollId): JSONResponse {
        return $this->withRoom($code, function ($room) use ($pollId) {
            try {
                $this->liveControl($room, fn (Room $r) => $this->deckService->lockPoll($r, $pollId));
            } catch (\InvalidArgumentException $e) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
            }
            return new JSONResponse([]);
        });
    }

    #[NoAdminRequired]
    public function unlockPoll(string $code, int $pollId): JSONResponse {
        return $this->withRoom($code, function ($room) use ($pollId) {
            try {
                $this->liveControl($room, fn (Room $r) => $this->deckService->unlockPoll($r, $pollId));
            } catch (\InvalidArgumentException $e) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
            }
            return new JSONResponse([]);
        });
    }

    /**
     * Generate demo/test votes for the active question (preview of the rendering
     * with many participants). Body: { count }.
     */
    #[NoAdminRequired]
    public function demoSeed(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            $count = Input::int($this->request->getParam('count')) ?? 25;
            try {
                return new JSONResponse($this->liveControl($room, fn (Room $r): array => $this->demoService->seedDemoVotes($r, $count)));
            } catch (\InvalidArgumentException $e) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            }
        });
    }

    /** Remove all demo/test votes of the room again. */
    #[NoAdminRequired]
    public function demoClear(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            return new JSONResponse($this->demoService->clearDemoVotes($room));
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function uid(): string {
        return $this->userSession->getUser()?->getUID() ?? '';
    }

    /**
     * Deadline from the request (`open`, `extend`): missing = none (0). Unusable
     * (list, text, 1e999) is a 400 instead of silently "no deadline" — when extending
     * that would mean "open until someone closes it".
     *
     * @throws \InvalidArgumentException
     */
    private function closesAt(): int {
        $raw = $this->request->getParam('closesAt');
        if ($raw === null) {
            return 0;
        }
        return Input::int($raw)
            ?? throw new \InvalidArgumentException($this->l10n->t('The deadline must be between one minute and 30 days from now.'));
    }

    /**
     * Optional switch in /pace: missing = null (the service's default).
     * Boolean values like filter_var (true/false, 1/0, "on"/"off", "yes"/"no",
     * '' = false). Anything else is a 400 — never silently the default.
     *
     * @throws \InvalidArgumentException 'Invalid request.'
     */
    private function optFlag(string $name): ?bool {
        $raw = $this->request->getParam($name);
        if ($raw === null) {
            return null;
        }
        return Input::flag($raw) ?? throw new \InvalidArgumentException($this->l10n->t('Invalid request.'));
    }

    /**
     * Load the room, check ownership, map errors uniformly to HTTP.
     * If an action does not fit the room's state (self-paced, see
     * PaceService), it becomes a 409. If the room disappears before the
     * room lock is taken (another tab, cleanup job), it becomes a 404 as when
     * loading.
     */
    private function withRoom(string $code, callable $fn): JSONResponse {
        try {
            $room = $this->ownedRoom($code);
        } catch (DoesNotExistException) {
            return new JSONResponse(['message' => $this->l10n->t('Room not found.')], Http::STATUS_NOT_FOUND);
        } catch (NotOwnerException) {
            return new JSONResponse(['message' => $this->l10n->t('No access to this room.')], Http::STATUS_FORBIDDEN);
        }
        try {
            return $fn($room);
        } catch (ConflictException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (RoomGoneException) {
            return new JSONResponse(['message' => $this->l10n->t('Room not found.')], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Deck change (questions, order, images, clearing a question's votes):
     * locked in self-paced mode from the moment it opens — that way the frozen
     * order also stays physically stable.
     */
    private function deckChange(Room $room, callable $fn): mixed {
        return $this->paced($room, fn (Room $r) => $this->paceService->assertDeckEditable($r), $fn);
    }

    /** Cursor control (current question, lock, end, demo): never in self-paced mode. */
    private function liveControl(Room $room, callable $fn): mixed {
        return $this->paced($room, fn (Room $r) => $this->paceService->assertLiveControl($r), $fn);
    }

    /** Empty the room (reset, practice run): in self-paced mode not while the window is open. */
    private function notOpen(Room $room, callable $fn): mixed {
        return $this->paced($room, fn (Room $r) => $this->paceService->assertNotOpen($r), $fn);
    }

    /**
     * Writing moderator action. Moderated: $fn($room) as before, without a
     * transaction. Self-paced: under the room lock; the guard and $fn
     * get the freshly locked row. Every exception rolls back and
     * propagates (the guard's ConflictException -> 409 in withRoom).
     *
     * @param callable(Room): void $guard throws ConflictException
     * @param callable(Room): mixed $fn
     */
    private function paced(Room $room, callable $guard, callable $fn): mixed {
        if (!PaceService::isSelf($room)) {
            return $fn($room);
        }
        return $this->paceService->locked($room, function (Room $r) use ($guard, $fn): mixed {
            $guard($r);
            return $fn($r);
        });
    }

    /**
     * The only place where this controller looks up a room by its code:
     * enforce ownership and record the owner's activity (touch). Every action
     * that names a room goes through here exactly once, so each request
     * counts once — never per question it writes. The room list (index)
     * touches nothing: merely opening the app must not keep every room alive.
     *
     * @throws DoesNotExistException room unknown
     * @throws NotOwnerException     room belongs to someone else
     */
    private function ownedRoom(string $code): Room {
        $room = $this->roomService->getOwnedRoom($code, $this->uid());
        $this->touch($room);
        return $room;
    }

    /**
     * Record owner activity for retention (RoomService::lastActivity), for
     * moderated and self-paced rooms alike: opening the room, reading its
     * results, editing the deck, presenting, running the self-paced window.
     * Without it a deck prepared weeks ahead, or a homework quiz whose owner
     * is still grading, would be deleted by the cleanup job as soon as no
     * participant had shown up for 30 days.
     *
     * At most one write per hour: skipped here when the row just read is
     * fresh enough (so the presenter's polls of results or progress cost no
     * write), and throttled once more in the mapper's WHERE against a
     * parallel tab. `touched_at` is in no payload and no version hash, so a
     * touch changes nothing a client sees.
     */
    private function touch(Room $room): void {
        $now = $this->timeFactory->getTime();
        if ($room->getTouchedAt() >= $now - RoomMapper::TOUCH_INTERVAL) {
            return;
        }
        $this->roomMapper->touch($room->getId(), $now);
    }
}
