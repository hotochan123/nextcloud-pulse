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
 * Moderator-API. Login-pflichtig; CSRF-Schutz bleibt an (die SPA sendet den
 * Nextcloud-Requesttoken automatisch mit). #[NoAdminRequired], damit nicht nur
 * Admins Räume anlegen können.
 *
 * Quiz im eigenen Tempo (PaceService::isSelf): jede schreibende Aktion läuft
 * unter der Raumsperre (PaceService::locked), ihr Wächter prüft die frisch
 * gesperrte Zeile — nicht den Raum, den withRoom vorher gelesen hat. So gibt
 * es kein Prüfen-dann-Handeln zwischen zwei Tabs oder Add-in und Browser.
 * Moderierte Räume laufen unverändert ohne Transaktion.
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
        // nur im eigenen Tempo angefasst:
        private PaceService $paceService,
        private PaceStateService $paceState,
        private RoomMapper $roomMapper,          // touch (Besuch des Besitzers)
        private ITimeFactory $timeFactory,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function index(): JSONResponse {
        return new JSONResponse($this->roomService->listRooms($this->uid()));
    }

    /** Gesamt-Zusammenfassung: alle Fragen mit Auszählung. */
    #[NoAdminRequired]
    public function summary(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            return new JSONResponse($this->stateService->summary($room));
        });
    }

    /**
     * Ergebnisse als CSV-Download (kein withRoom: liefert DataDownloadResponse).
     * Im eigenen Tempo zusätzlich `?view=players` (eine Zeile je Person) und
     * `?view=answers` (eine Zeile je Stimme, mit Zeiten); moderiert gibt es
     * keine Zeiten je Person, dort wird der Parameter ignoriert.
     */
    #[NoAdminRequired]
    public function exportCsv(string $code): Response {
        try {
            $room = $this->roomService->getOwnedRoom($code, $this->uid());
        } catch (DoesNotExistException) {
            return new JSONResponse(['message' => $this->l10n->t('Room not found.')], Http::STATUS_NOT_FOUND);
        } catch (NotOwnerException) {
            return new JSONResponse(['message' => $this->l10n->t('No access to this room.')], Http::STATUS_FORBIDDEN);
        }
        $view = Input::str($this->request->getParam('view'));
        if (PaceService::isSelf($room) && ($view === 'players' || $view === 'answers')) {
            // Je Wort ein literales t() — sonst meldet build/l10n-check.js die
            // Übersetzung als verwaist.
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

    /** Raum als Vorlage kopieren: Fragen ja, Stimmen und Teilnehmende nein. */
    #[NoAdminRequired]
    public function duplicate(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            $copy = $this->roomService->duplicateRoom($room, $this->uid());
            return new JSONResponse($this->deckService->roomView($copy), Http::STATUS_CREATED);
        });
    }

    /** Raum umbenennen; leerer Titel entfernt ihn wieder. */
    #[NoAdminRequired]
    public function rename(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            $this->roomService->setTitle($room, Input::str($this->request->getParam('title')));
            return new JSONResponse($this->deckService->roomView($room));
        });
    }

    /** Quiz-Rangliste (Moderator; ohne „me"-Markierung). */
    #[NoAdminRequired]
    public function leaderboard(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            return new JSONResponse($this->voteService->leaderboardFor($room, null));
        });
    }

    #[NoAdminRequired]
    public function show(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            $this->touch($room);
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

    /** Ganzen Raum leeren: Stimmen + Teilnehmer + Rangliste weg, Fragen bleiben. */
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

    /** Probelauf ein-/ausschalten. Body: { on: bool }. Leert den Raum beim Umschalten. */
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

    /** Auflösung erst am Ende (statt je Frage) ein-/ausschalten. Body: { on: bool }. */
    #[NoAdminRequired]
    public function reveal(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            $on = Input::flag($this->request->getParam('on')) ?? false;
            $this->roomService->setRevealAtEnd($room, $on);
            return new JSONResponse($this->deckService->roomView($room));
        });
    }

    /** Quiz beenden: aktuelle Frage als beendet markieren -> gibt den Endstand frei. */
    #[NoAdminRequired]
    public function endQuiz(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            $this->liveControl($room, fn (Room $r) => $this->roomService->endQuiz($r));
            return new JSONResponse([]);
        });
    }

    /**
     * Quiz im eigenen Tempo steuern. Body: { action, … } mit
     * `set` {pace} · `open` {closesAt, timed, feedback} · `close` {release} ·
     * `extend` {closesAt} · `release` · `lockJoins` · `unlockJoins` ·
     * `removePlayer` {playerId}. Fehlende Einstellungen beim Öffnen nehmen die
     * Vorgabe (ohne Frist ein Rennen, mit Frist eine Hausaufgabe). `close` ohne
     * `release` gibt wie bisher genau dann frei, wenn keine Frist gilt (alte
     * Tabs, API-Clients); `release: false` schließt ohne Freigabe („Stoppen ohne
     * Freigabe“, ein Aufruf), `release: true` gibt mit dem Schließen immer frei.
     * Jede Aktion läuft unter der Raumsperre (PaceService). Antwort: der Raum
     * wie bei GET /rooms/{code}, samt `window`.
     *
     * Jeder Parameter wird erst im Zweig seiner Aktion gelesen — was eine andere
     * Aktion mitschickt, stört nicht. Unbrauchbares (Liste, Objekt, 1e999) ist
     * 400, bevor die Raumsperre greift; Text, den der Dienst ohnehin prüft
     * (`pace`, `feedback`), kommt als '' an und scheitert dort („Unknown …“).
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
                        // fehlt = Vorgabe; eine Liste wird '' -> „Unknown feedback setting.“
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
            $this->touch($room);
            return new JSONResponse($this->deckService->roomView($room));
        });
    }

    /**
     * Fortschritt im eigenen Tempo: je Person und je Frage (Rennen wie
     * Hausaufgabe), adaptiv gepollt mit `?v=` wie results(). `?scores=1` zeigt
     * Punkte auch bei „Rückmeldung am Ende" — ausdrücklicher Schalter, denn der
     * Laptop hängt oft am Beamer. Kein withRoom: liefert auch 204.
     */
    #[NoAdminRequired]
    public function progress(string $code): Response {
        try {
            $room = $this->roomService->getOwnedRoom($code, $this->uid());
        } catch (DoesNotExistException) {
            return new JSONResponse(['message' => $this->l10n->t('Room not found.')], Http::STATUS_NOT_FOUND);
        } catch (NotOwnerException) {
            return new JSONResponse(['message' => $this->l10n->t('No access to this room.')], Http::STATUS_FORBIDDEN);
        }
        if (!PaceService::isSelf($room)) {
            return new JSONResponse(['message' => $this->l10n->t('This room is not self-paced.')], Http::STATUS_CONFLICT);
        }
        $this->touch($room);
        $scores = Input::flag($this->request->getParam('scores')) ?? false;
        $data = $this->paceState->progress($room, $scores);
        // Version aus dem fertigen Payload — ohne serverNow und „zuletzt
        // gesehen" (jeder Handy-Heartbeat änderte sie sonst, s. PaceStateService::version).
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

    /** Deck umsortieren. Body: { order: [pollId, pollId, …] }. */
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

    /** Cursor setzen (Deck-Navigation). pollId=0 oder fehlt => Präsentation ruht. */
    #[NoAdminRequired]
    public function setCurrent(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            // 0 oder fehlt = Präsentation ruht; Unbrauchbares (Liste, Text, 1e100)
            // darf das nicht still bedeuten.
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
            $room = $this->roomService->getOwnedRoom($code, $this->uid());
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
        // Unverändert seit dem letzten Poll? -> 204, ohne Tally/Rangliste zu bauen.
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

    /** Freitext bewerten. Body: { answer: string, correct: bool }. */
    #[NoAdminRequired]
    public function gradeAnswer(string $code, int $pollId): JSONResponse {
        return $this->withRoom($code, function ($room) use ($pollId) {
            // Mit NUL, genau wie die Stimme gespeichert ist (Input::rawStr).
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
     * Bild zur Frage hochladen (multipart, Feldname `image`). Ersetzt ein
     * vorhandenes. Das Bild wird serverseitig neu kodiert — siehe
     * PollImageService.
     */
    #[NoAdminRequired]
    public function uploadImage(string $code, int $pollId): JSONResponse {
        return $this->withRoom($code, function ($room) use ($pollId) {
            try {
                // null = kein Bild mitgeschickt (Antwort unten)
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

    /** Bild der Frage entfernen. */
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
     * Bild ausliefern — Moderator-Sicht (Deck-Editor, Vorschau): ohne die
     * Sichtbarkeitsschranke der öffentlichen Route, denn wem der Raum gehört,
     * der kennt seine Fragen ohnehin.
     *
     * NoCSRFRequired ist hier Pflicht, kein Bequemlichkeits-Schalter: die URL
     * steht in einem <img src>, und der Browser hängt an so eine Anfrage keinen
     * Requesttoken -> Nextcloud antwortete mit 412 „CSRF check failed", das Bild
     * blieb im Moderator leer. Ungefährlich, weil die Route nur liest und den
     * Besitzer prüft.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function showImage(string $code, int $pollId): Response {
        try {
            $room = $this->roomService->getOwnedRoom($code, $this->uid());
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
     * Demo-/Test-Stimmen für die aktive Frage erzeugen (Vorschau der Darstellung
     * gegen viele Teilnehmende). Body: { count }.
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

    /** Alle Demo-/Test-Stimmen des Raums wieder entfernen. */
    #[NoAdminRequired]
    public function demoClear(string $code): JSONResponse {
        return $this->withRoom($code, function ($room) {
            return new JSONResponse($this->demoService->clearDemoVotes($room));
        });
    }

    // ── Helfer ────────────────────────────────────────────────────────────────

    private function uid(): string {
        return $this->userSession->getUser()?->getUID() ?? '';
    }

    /**
     * Frist aus der Anfrage (`open`, `extend`): fehlt = keine (0). Unbrauchbar
     * (Liste, Text, 1e999) ist 400 statt still „ohne Frist“ — beim Verlängern
     * hieße das „offen, bis jemand schließt“.
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
     * Optionaler Schalter in /pace: fehlt = null (die Vorgabe des Dienstes).
     * Wahrheitswerte wie filter_var (true/false, 1/0, „on“/„off“, „yes“/„no“,
     * '' = false). Alles andere ist 400 — nie still die Vorgabe.
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
     * Raum laden, Eigentümerschaft prüfen, Fehler einheitlich auf HTTP abbilden.
     * Passt eine Aktion nicht zum Zustand des Raums (eigenes Tempo, s.
     * PaceService), wird daraus 409. Verschwindet der Raum, bevor die
     * Raumsperre greift (anderer Tab, Aufräum-Job), wird daraus 404 wie beim
     * Laden.
     */
    private function withRoom(string $code, callable $fn): JSONResponse {
        try {
            $room = $this->roomService->getOwnedRoom($code, $this->uid());
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
     * Deck-Änderung (Fragen, Reihenfolge, Bilder, Stimmen einer Frage leeren):
     * im eigenen Tempo ab dem Öffnen gesperrt — die eingefrorene Reihenfolge
     * bleibt so auch physisch stabil.
     */
    private function deckChange(Room $room, callable $fn): mixed {
        return $this->paced($room, fn (Room $r) => $this->paceService->assertDeckEditable($r), $fn);
    }

    /** Cursor-Steuerung (aktuelle Frage, sperren, beenden, Demo): im eigenen Tempo nie. */
    private function liveControl(Room $room, callable $fn): mixed {
        return $this->paced($room, fn (Room $r) => $this->paceService->assertLiveControl($r), $fn);
    }

    /** Raum leeren (Zurücksetzen, Probelauf): im eigenen Tempo nicht bei offenem Fenster. */
    private function notOpen(Room $room, callable $fn): mixed {
        return $this->paced($room, fn (Room $r) => $this->paceService->assertNotOpen($r), $fn);
    }

    /**
     * Schreibende Moderator-Aktion. Moderiert: $fn($room) wie bisher, ohne
     * Transaktion. Im eigenen Tempo unter der Raumsperre: Wächter und $fn
     * bekommen die frisch gesperrte Zeile. Jede Ausnahme rollt zurück und
     * fliegt weiter (ConflictException des Wächters -> 409 in withRoom).
     *
     * @param callable(Room): void $guard wirft ConflictException
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
     * Besuch des Besitzers vermerken (show, /progress, /pace) — nur im eigenen
     * Tempo. Sonst gibt der Besitzer einer Hausaufgabe nie ein Lebenszeichen,
     * und der Aufräum-Job löschte sie samt Ergebnissen, während er noch
     * auswertet. Gedrosselt im Mapper (höchstens stündlich).
     */
    private function touch(Room $room): void {
        if (PaceService::isSelf($room)) {
            $this->roomMapper->touch($room->getId(), $this->timeFactory->getTime());
        }
    }
}
