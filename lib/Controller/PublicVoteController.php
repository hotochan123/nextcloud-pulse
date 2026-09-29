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
 * Öffentliche Teilnehmer-API — kein Login, kein CSRF-Token (das Publikum hat
 * keine Nextcloud-Session). Doppelabstimmung wird über ein anonymes Cookie-Token
 * verhindert, nicht über die Identität. Dasselbe Muster wie bei Freigabe-Links.
 *
 * Quiz im eigenen Tempo (PaceService::isSelf): /state baut den Zustand vor
 * der Version (es gibt keinen billigen Fingerabdruck), /join ist je IP und
 * Raum begrenzt, und /next startet die nächste Frage der Person. Moderierte
 * Räume laufen wie bisher.
 *
 * Parameter und Cookie kommen roh vom Client: Input bzw. voterToken() machen
 * aus Unbrauchbarem „nicht gesendet“ (nie eine PHP-Warnung, nie 500).
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
        // nur im eigenen Tempo angefasst:
        private PaceService $paceService,
        private ILimiter $limiter,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Live-Zustand für die Teilnehmer-Seite (adaptives Polling). Der Client
     * schickt die zuletzt gesehene `version` mit; hat sich nichts geändert,
     * antworten wir mit 204 (leer) statt den vollen Zustand zu berechnen.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: 'pulseRoomCode')]
    public function state(string $code): Response {
        $room = $this->loadRoom($code);
        if ($room === null) {
            return $this->notFound();
        }

        // Beamer-/Publikumsansicht pollt mit ?spectate=1: reiner Zuschauer, zählt
        // nicht als Teilnehmer und bekommt kein Cookie (sonst verfälscht der
        // projizierende Rechner die „dabei"-Zahl).
        $spectate = $this->request->getParam('spectate') === '1';
        $token = $this->voterToken();
        $isNewToken = $token === null;
        if ($spectate) {
            $isNewToken = false; // kein Cookie vergeben, kein Heartbeat
        } else {
            // Auch reine Zuschauer (noch keine Stimme) sollen mitzählen: fehlt das
            // Cookie, hier eins vergeben und mitschicken.
            if ($isNewToken) {
                $token = $this->codeGenerator->voterToken();
            }
            $this->roomService->heartbeat($room, $token);
        }

        $clientVersion = Input::str($this->request->getParam('v'));
        if (PaceService::isSelf($room)) {
            // Eigenes Tempo: kein billiger Fingerabdruck — Zustand bauen, die
            // Version ist sein Hash (Frist, Endgültigkeit, Zeitablauf stehen darin).
            $state = $this->stateService->publicState($room, $token, $spectate);
            $version = $this->stateService->selfVersion($state);
            if (!$isNewToken && $clientVersion !== '' && $clientVersion === $version) {
                $unchanged = new Response();
                $unchanged->setStatus(Http::STATUS_NO_CONTENT);
                return $unchanged;
            }
        } else {
            // Unverändert seit dem letzten Poll? -> 204, ohne Tally zu bauen.
            // (Neues Token bekommt immer den vollen Zustand + Cookie.)
            // Zuschauer sehen die Präsenz mit (Eingangs-Anzeige der Bühne) und
            // brauchen sie deshalb auch im Fingerabdruck.
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
     * Gesamtauswertung für Teilnehmende: alle Fragen mit Ergebnissen, eigener
     * Antwort und (Quiz) Auflösung. Wird nur auf Knopfdruck geladen, nicht
     * gepollt. Rein lesend — kein Cookie wird vergeben, kein Heartbeat: wer die
     * Auswertung liest, ist nicht „gerade dabei".
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
     * Bild einer Frage ausliefern. Sichtbar ist es nur, solange die Frage läuft
     * oder schon aufgelöst ist — dieselbe Schranke wie für die Frage selbst,
     * sonst verriete das Bild die nächste Frage im Deck. Kein Cookie, kein
     * Heartbeat; die Antwort darf gecacht werden (der Dateiname im Pfad der
     * Frage wechselt bei jedem neuen Bild). Im eigenen Tempo nur für Personen,
     * die die Frage erreicht haben — das <img> schickt das Cookie mit.
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
     * Quiz-Beitritt: Nickname wählen. Vergibt (falls nötig) das anonyme
     * Voter-Cookie und verankert den Namen daran.
     *
     * Im eigenen Tempo je IP und Raum begrenzt (PaceService::JOIN_LIMIT in
     * JOIN_PERIOD): dort kostet eine Wegwerf-Identität sonst nichts. Moderiert
     * nicht — eine Konferenz hinter einer NAT-IP kann mehr Beitritte haben.
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
            // Eigenes Tempo: gelöscht, bevor die Raumsperre griff.
            return $this->notFound();
        }

        $response = new JSONResponse($this->stateWithVersion($room, $token));
        $expires = $this->timeFactory->getDateTime()->add(new \DateInterval('P1Y'));
        $response->addCookie(Application::VOTER_COOKIE, $token, $expires, 'Lax');
        return $response;
    }

    /**
     * Stimme abgeben. Setzt bei Bedarf ein anonymes Voter-Cookie und gibt den
     * aktualisierten Zustand zurück.
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
            // `keyboard` verlängert nur das Korrekturfenster (§8.0). Der Wert
            // kommt vom Client und ist damit fälschbar — er verschafft aber
            // keinen Vorteil: die Korrektur setzt den Zeitstempel ohnehin neu.
            // Unbrauchbar (Liste, 1e100) = wie ein älteres Handy ohne das Feld.
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
        // Cookie (erneut) setzen: httpOnly, 1 Jahr, Lax reicht für same-site-POST.
        $expires = $this->timeFactory->getDateTime()->add(new \DateInterval('P1Y'));
        $response->addCookie(Application::VOTER_COOKIE, $token, $expires, 'Lax');
        return $response;
    }

    /**
     * Eigenes Tempo: nächste Frage holen — die einzige Stelle, an der eine Uhr
     * startet (PaceService::next). Body: { after } = progress.after aus dem
     * letzten Zustand; erst senden, wenn ein laufendes /vote zurück ist.
     * Idempotent: ein veraltetes `after` (Doppeltipp, zweiter Tab) ist ein
     * No-op mit unverändertem Zustand. Ohne Cookie gibt es keinen Spieler und
     * nichts zu starten — hier wird deshalb keins vergeben.
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
        // JSON liefert eine Zahl, ein Formular/Query einen String aus Ziffern.
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
     * Voller Zustand samt aktueller Änderungs-Version (für den adaptiven
     * Client). Im eigenen Tempo ist die Version der Hash des gebauten Zustands.
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
     * Voter-Token aus dem Cookie — nur in der Form, die CodeGenerator::voterToken
     * vergibt. Alles andere zählt als „kein Cookie“ (state/join/vote vergeben ein
     * neues): `pulse_vt[x]=…` kommt bei PHP als Liste an (TypeError, 500), ein
     * überlanges oder kaputtes Token scheiterte an der varchar(32)-Spalte bzw. am
     * UTF-8 der Datenbank (500).
     */
    private function voterToken(): ?string {
        $token = $this->request->getCookie(Application::VOTER_COOKIE);
        return is_string($token) && CodeGenerator::isVoterToken($token) ? $token : null;
    }

    /**
     * 404 für einen unbekannten Code — als Brute-Force-Fehlversuch markiert.
     * Greift NUR bei falschen Codes; gültige Codes (echte Teilnehmer) werden
     * nie gethrottelt. Nach ~10 Fehlversuchen pro IP wächst die Verzögerung.
     */
    private function notFound(): JSONResponse {
        $response = new JSONResponse(['message' => $this->l10n->t('Room not found.')], Http::STATUS_NOT_FOUND);
        $response->throttle(['action' => 'pulseRoomCode']);
        return $response;
    }
}
