<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Caps per account and per quiz room, so that a single Nextcloud user cannot
 * fill the data directory or the database. Pulse stores question images in the app's
 * AppData area and its rows in its own tables — no user quota counts either.
 * Without these caps one account could store gigabytes with a few scripted
 * requests (re-encoded images are several times larger than the upload,
 * duplicating a room copies every image).
 *
 * Every cap has a default that suits a class or a conference and can be
 * changed per instance, for example
 *
 *   occ config:app:set pulse max_rooms_per_owner --value 500
 *
 * Only a positive whole number overrides a default; anything else (unset,
 * 0, text) keeps it, so a typo never means "unlimited". A change takes
 * effect within a few seconds (Nextcloud caches the app config per worker).
 *
 * The two player caps bound what anonymous participants can add to one quiz
 * room (VoteService); their defaults suit a class or a conference behind one
 * NAT address, and an instance that runs larger events raises them.
 *
 * The rate limits (RoomApiController::rateLimited) are requests per account
 * within RATE_PERIOD seconds, overridable the same way under the key
 * `rate_limit_<action>`. Under parallel requests every limit here is
 * approximate: each request checks against what was stored when it started
 * (Nextcloud's rate limiter as well). The image budget and the room and
 * question caps therefore count again after writing and undo their own write
 * when it went over (PollImageService, RoomService, DeckService).
 */
class Limits {
    /** Sum of the stored image files of all rooms of one account (bytes). */
    public const IMAGE_BYTES_PER_OWNER = 'max_image_bytes_per_owner';
    /** Questions in one room. */
    public const POLLS_PER_ROOM = 'max_polls_per_room';
    /** Rooms of one account. */
    public const ROOMS_PER_OWNER = 'max_rooms_per_owner';
    /** Characters of a question text. */
    public const QUESTION_LENGTH = 'max_question_length';
    /**
     * Players who have joined one quiz room: every player of a moderated
     * quiz, started or not in a self-paced one (VoteService).
     */
    public const PLAYERS_PER_ROOM = 'max_players_per_room';
    /** New players per address and self-paced room within PaceService::JOIN_PERIOD. */
    public const NEW_PLAYERS_PER_ADDRESS = 'max_new_players_per_address';

    /** Window of the rate limits in seconds. */
    public const RATE_PERIOD = 60;

    // Rate-limited moderator actions (suffix of the app config key).
    public const RATE_CREATE = 'create';
    public const RATE_DUPLICATE = 'duplicate';
    public const RATE_ADD_POLL = 'add_poll';
    public const RATE_UPLOAD_IMAGE = 'upload_image';
    public const RATE_DEMO = 'demo';

    private const DEFAULTS = [
        self::IMAGE_BYTES_PER_OWNER => 200 * 1024 * 1024,
        self::POLLS_PER_ROOM => 100,
        self::ROOMS_PER_OWNER => 200,
        // Enough for any question on a projector; far below the 64 KB of a
        // TEXT column on MySQL/MariaDB (4 bytes per character at most).
        self::QUESTION_LENGTH => 1000,
        self::PLAYERS_PER_ROOM => PaceService::MAX_JOINED,
        self::NEW_PLAYERS_PER_ADDRESS => PaceService::NEW_PLAYER_LIMIT,
    ];

    /**
     * Requests per account and RATE_PERIOD. Far above what a person clicks —
     * building a deck by hand is one question every few seconds — but a
     * script can no longer write hundreds per second. Adding questions is the
     * most generous one: dev/sim adds its decks in bursts.
     */
    private const RATE_DEFAULTS = [
        self::RATE_CREATE => 30,
        self::RATE_DUPLICATE => 10,
        self::RATE_ADD_POLL => 120,
        self::RATE_UPLOAD_IMAGE => 30,
        self::RATE_DEMO => 30,
    ];

    public function __construct(
        private IAppConfig $appConfig,
    ) {
    }

    /** @param self::IMAGE_BYTES_PER_OWNER|self::POLLS_PER_ROOM|self::ROOMS_PER_OWNER|self::QUESTION_LENGTH|self::PLAYERS_PER_ROOM|self::NEW_PLAYERS_PER_ADDRESS $key */
    public function get(string $key): int {
        return $this->read($key, self::DEFAULTS[$key] ?? throw new \InvalidArgumentException('Unknown limit ' . $key));
    }

    /** Requests per RATE_PERIOD for one of the RATE_* actions. */
    public function rate(string $action): int {
        return $this->read('rate_limit_' . $action, self::RATE_DEFAULTS[$action] ?? throw new \InvalidArgumentException('Unknown rate limit ' . $action));
    }

    private function read(string $key, int $default): int {
        try {
            $value = $this->appConfig->getValueInt(Application::APP_ID, $key, $default);
        } catch (\Throwable) {
            // Stored with an explicit non-integer type, or the config is not
            // readable right now: the default is always a safe answer.
            return $default;
        }
        return $value > 0 ? $value : $default;
    }
}
