# Tests

## Running them

```sh
tests/run.sh                 # all unit tests
tests/run.sh --filter Tally  # a subset (any arguments are passed on to PHPUnit)
```

On its first run the script downloads `tools/phpunit.phar` (PHPUnit 11; kept
out of version control) and runs PHPUnit **inside the Nextcloud container** —
the PHP CLI on the host lacks the `tokenizer` and `xmlwriter` extensions.
`CONTAINER` overrides the container name (default `nextcloud-nextcloud-1`),
`APP_DIR` the app path inside the container (default `/var/www/html/apps/pulse`).

## What runs here

Pure unit tests without the Nextcloud bootstrap: `tests/bootstrap.php` loads
only the server's Composer autoloader (for `OCP\…`, including the Entity base
class), a few selected namespaces from the server's `3rdparty/` directory
(`Psr\Clock`, `Psr\Log`, `Psr\EventDispatcher`, `Doctrine\DBAL`,
`Doctrine\Deprecations`, `Symfony\Component\String`,
`Symfony\Component\HttpFoundation` — needed to mock the time source, the
logger and the database connection, to build OCP events, to replay the
migrations offline and to build CSV downloads), a PSR-4 loader for
`OCA\Pulse\…` and one for shared test building blocks
(`OCA\Pulse\Tests\Unit\…` → `tests/unit/`). **No `lib/base.php`, no database,
no session** — the tests cannot touch the live instance and run in
milliseconds. `phpunit.xml` sets `failOnWarning` and `failOnNotice`, so a PHP
warning fails a test.

`NEXTCLOUD_ROOT` overrides the server path if the app does not live under
`<nextcloud>/apps/`.

| File | Covers |
| --- | --- |
| `unit/AddinManifestTest.php` | The instance generates the Office add-in manifest (a manifest has no variables); if that breaks, PowerPoint reports nothing useful and the box on the slide just stays empty |
| `unit/AnswerRulesGoldenTest.php` | Golden answers of the per-type vote rules before they move: what `VoteService::normalizeValue` returns (value, type and key order) and the exact message it rejects with, for every question type and scale mode, an unknown type included (it counts as a word cloud); `quizPayload` per quiz type with its key order; the free-text verdict (accepted, being checked, rejected) |
| `unit/CleanupOrphansTest.php` | The daily cleanup, owner side: participant heartbeats keep a room only while its owner was there within 180 days; the rooms of an account that no longer exists go whatever their presence — "no longer exists" needs both no user backend knowing the ID and no login record, so a disabled OIDC/LDAP app costs nobody their rooms; a room that cannot be deleted does not stop the run, and each step of the job runs even when an earlier one fails |
| `unit/CleanupRetentionTest.php` | Retention (`RoomService::cleanupStaleRooms`): a room stays as long as its latest sign of life is within the retention period — participant heartbeat, owner activity (`touched_at`, every room mode) and, for self-paced rooms, also opening, deadline, closing and release; a homework with a deadline that is evaluated weeks later must not vanish with its results, nor a moderated deck its owner edited yesterday; without owner activity moderated rooms keep exactly the old rule |
| `unit/CodeGeneratorTest.php` | Room code alphabet without I/O/0/1, collisions are re-rolled, token lengths |
| `unit/ControllerInputTest.php` | Every parameter of every controller action sent as a list, broken voter cookies: no 500, no warning; a source-code guard over all of `lib/` against raw casts on request values and a second voter-cookie reader; `close {release}`: missing = old rule, booleans pass, anything else is 400 without closing; the free-text answer used for grading keeps NUL like the stored vote |
| `unit/DeckSetCurrentTest.php` | Setting the cursor (`DeckService::setCurrent`): in a quiz every jump restarts the timer and opens the question; in a poll the FIRST jump records "was shown" so the public summary can leave out questions never shown |
| `unit/DeckUpdatePollTest.php` | Editing a question (`DeckService::updatePoll`): new content clears the votes and a question that is not running counts as "never shown" again; `ended` stays, the running question stays (only an open quiz timer restarts), locked stays locked |
| `unit/EmbedShellTest.php` | The PowerPoint embed shell (`js/pulse-embed.js`) frames only a real room code: a saved code from the document settings, `?code=` or localStorage is upper-cased and must match `^[A-Z0-9]{6}$`, anything else (`..` would frame `/apps/pulse/`) opens the form with the text filled in; the form checks the same before it frames, and there is no third path (a guard on the source — the container has no JavaScript runtime) |
| `unit/HostileInputTest.php` | `DeckService`, `VoteService::normalizeValue` and image upload with nested lists and objects, `true`, 1e300, INF, "1e999", twenty digits, broken UTF-8 and NUL: a storable result or `InvalidArgumentException` (400), never a warning, `TypeError` or a value `json_encode` chokes on |
| `unit/ImageRouteTest.php` | Image routes carry `#[NoCSRFRequired]` — without it an `<img src>` gets a 412 |
| `unit/ImageStorageTest.php` | Question images and their storage (`PollImageService`): the per-account image budget counts stored (re-encoded) bytes, refuses before the GD run and before writing, frees the replaced image; an upload counts again once its image is referenced and goes back to the previous image when a parallel upload or copy pushed the account over; the `image` column is a compare-and-set, so of two uploads to one question the loser deletes its own file; unreferenced files are swept only after the grace period |
| `unit/InputTest.php` | Raw client values: lists, 1e999, NAN, broken UTF-8 and NUL become "not sent" instead of a PHP warning; `rawStr` keeps NUL (grading free text) |
| `unit/InsertColumnsTest.php` | MySQL has no default for some NOT NULL columns (Doctrine drops the declared default of TEXT columns there), so every INSERT must carry them: the migrations are replayed offline and MySQL 8.4's DBAL platform decides; a new entity marks exactly the columns whose declared default MySQL drops (`pulse_polls.options`); `addPoll` for every question type and `duplicateRoom` carry every column without a MySQL default; a loaded poll still updates only what changed |
| `unit/LeaderboardSkipTest.php` | Leaderboard without one question (`VoteService::leaderboardFor`, `$skipPollIds`): the public summary leaves the running, still hidden question out of the scoring |
| `unit/LivePlayerAdminTest.php` | Moderated (live) quiz: `lockJoins`/`unlockJoins` and `removePlayer` work there too (a poll room is 409); removing takes the player and their votes on every question of the room, progress and presence, also after the final standings; the moderator leaderboard carries `playerId` only on request, public views never |
| `unit/LiveQuizJoinTest.php` | Moderated (live) join (`VoteService::quizJoin` → `liveJoin`): under the room lock and decided on the locked row, "Lock joining" before the name check, the name is fixed after the first answer or once the quiz has ended (its spelling stays changeable, re-sending it always works), a new token loses left-over votes (and deletes nothing without any: no vote write, so no refetch for everyone per join), at most `max_players_per_room` players (default `PaceService::MAX_JOINED`, the app config raises or lowers it; a known player still gets back in, a locked room says so first), no count per address |
| `unit/MatchGradingTest.php` | Matching in a quiz: all-or-nothing, row order does not matter, speed points |
| `unit/ModeratedCsvGoldenTest.php` | Golden bytes of the moderated CSV (`StateService::exportCsv`): every question type and scale mode with and without answers, the `(no aspects)` and `(no answers)` rows (also where there are votes but nothing to count, and aspects nobody rated), quoting, the BOM and numbers in the export's language (German, so the comma shows) |
| `unit/ModeratorPlayerIdsTest.php` | The moderator's `/leaderboard` asks for the player IDs (`leaderboardFor(…, withPlayerIds: true)`) that `removePlayer` takes — the live quiz's player list removes names with them |
| `unit/NameConfusableTest.php` | Nicknames that only look like a taken one are taken (`TallyService::nameKey`/`namesClash`): NFKC (fullwidth, ligatures, circled and mathematical letters) and a small Greek/Cyrillic/Latin lookalike table always, Unicode's confusables through intl's `Spoofchecker` where it exists (`PauI`); real differences stay; live and self-paced joins and renames refuse lookalikes, and so does another spelling of one's own name with the same `nameKey` ("paui" → "PauI" next to "Paul", after answering or after the end); names that already exist keep their spelling, the stored name is the one typed; every default-ignorable code point, unassigned ones included (U+FFF0, U+E0080 …), is invisible to the comparison — checked against PCRE's `\p{DI}` |
| `unit/OwnerActivityTest.php` | Retention, controller side: every moderator request that names a room records owner activity exactly once, moderated rooms included; the room list and creating a room record nothing; at most one write per hour; a source-code guard that rooms are only looked up through `ownedRoom()` |
| `unit/OwnerCapsTest.php` | Caps per account (`Limits`): defaults and app config overrides (only a positive number), rooms per account, questions per room, question length and the other bounded question fields; copying checks every cap before writing and removes a copy that fails halfway; rooms, questions and copies with images count again after writing and take their own write back when parallel requests went over |
| `unit/OwnerStorageFakes.php` | Not a test: in-memory stand-ins for the `pulse_rooms`/`pulse_polls` rows (a query-builder fake that evaluates exactly the shapes the storage code builds, compare-and-set included) and the AppData image folder |
| `unit/PaceDeadlineConstantTest.php` | The deadline dialog (`DEADLINE_MIN`/`DEADLINE_MAX` in `src/util/pace.js`) states the server's bounds (`PaceService::MIN_LEAD`/`MAX_LEAD`) |
| `unit/PaceFinalRuleTest.php` | Self-paced: ONE rule for "final", "correctable", "finished" and the `/next` value, used by phone, projector, progress, leaderboard and CSV alike |
| `unit/PaceGuardTest.php` | Self-paced guards on the existing moderator routes and switching the pace; with `pace='live'` every guard is a no-op |
| `unit/PaceNextTest.php` | Self-paced `/next`, the only place a clock starts: double taps, parallel tabs and aborts (stale `after`, compare-and-set, healing), the preview lock on timed questions, removal while `/next` is in flight |
| `unit/PaceOrderTest.php` | Self-paced frozen order: the single source for "question k of n" and the next question; `seq` is always the index in the FULL order |
| `unit/PacePlayerAdminTest.php` | Self-paced "Lock joining" and removing a person: removal clears everything tied to their token (player, votes in the frozen deck, progress, presence), also after release; only in quiz rooms (live quizzes: `LivePlayerAdminTest`) |
| `unit/PaceStateTestCase.php` | Not a test: shared base class for the self-paced read-view tests (a room with three questions in memory, mapper doubles, an adjustable clock; `PaceService`, `VoteService`, `TallyService` and `DeckService` are real) |
| `unit/PaceStillJoinedTest.php` | Self-paced races with removing and deleting: `assertStillJoined` leaves no vote or row without a player; `locked()` turns a deleted room into `RoomGoneException` (404) |
| `unit/PaceWindowStateTest.php` | Self-paced window state derived from the timestamps; at the second `closes_at` the window is CLOSED, everywhere alike (state, votes, versions) |
| `unit/PaceWindowTest.php` | Self-paced window actions (open, close, extend, release): each runs under the room lock, checks the freshly locked row, and rolls back on every exception |
| `unit/PollParamsTest.php` | The controller passes on every question field the `DeckService` reads (a forgotten field = "missing" although filled in); the guard fails instead of passing empty when it finds fewer than 15 fields or loses a known one |
| `unit/PollVoteRaceTest.php` | Two simultaneous first poll votes from the same token: the second insert fails on the UNIQUE index and becomes an update (previously 500); other database errors stay errors |
| `unit/PresenceTouchTest.php` | The heartbeat's upsert (`PresenceMapper::touch`): an existing row is updated without asking the guard; a new row only when the guard (the controller's limit on new presence rows per address and room) allows it |
| `unit/ProtocolConstantTest.php` | Server (`Application::PROTOCOL`) and bundle (`src/util/protocol.js`) state the same protocol number |
| `unit/PublicCodeThrottleTest.php` | Brute-force protection for room codes: only an unknown code consults the throttler — check, attempt, check (404, from the eleventh miss in 30 minutes 429), and a blocked address records no further attempt, so a phone polling a deleted room does not keep the block alive — on every participant endpoint and on `/s/{code}` and `/screen/{code}`; an existing code never consults the throttler, even from a blocked address (school NAT); no `#[BruteForceProtection]` on the public controllers |
| `unit/PublicControllerTestCase.php` | Not a test: shared base for the public-controller tests (request headers, a raw Cookie header turned into `$_COOKIE` the way PHP does it, protocol and webroot; recording fakes for throttler, limiter and app config) |
| `unit/PublicCrossSiteTest.php` | Every participant POST (taken from `routes.php`) is 403 without `X-Requested-With: XMLHttpRequest` or `Sec-Fetch-Site: same-origin`/`none` — before the code lookup, without a new token or cookie; GETs need no header; the built public bundle sends the header (`@nextcloud/axios`) |
| `unit/PublicNewTokenTest.php` | New voter tokens: `/state` without a cookie issues one but writes no presence row; `/join` and `/vote` without a cookie count towards a limit per address and room in every room mode (429 without token or cookie), requests with a cookie never do; every `/join` (both modes, with or without a cookie) counts towards a generous flood guard (`PaceService::JOIN_LIMIT`, at least four joins per possible player, growing with `max_players_per_room`), `/vote` and `/state` never do; a token's first presence row counts towards a limit per address and room (made-up tokens), beyond it the state comes as usual without a row |
| `unit/PublicPayloadTest.php` | Size caps of the public payloads (`PublicPayload`, security review M3): leaderboards are the top 10 plus `leaderboardTotal`, the viewer's own row (`leaderboardMe`) and its neighbours (`leaderboardAround`), `shared` counted on the full list; word cloud and free text keep the 100 most frequent entries with their true counts; the compass sends a deterministic sample of at most 500 points that keeps each position's share; the other types stay untouched |
| `unit/PublicRevealGateTest.php` | Reveal gates of the public views: leaderboard without the hidden running question, "quiz over" only while nothing new is running, old poll rooms (from before the start timestamp) count as "shown" through their votes, hidden quiz questions keep their real status (`revealed` alone says whether it is revealed), shuffle order independent of the solution |
| `unit/PublicSpoilerTest.php` | What the public views (projector, phone) must NOT give away in advance: the stored order of ranking/matching options (it IS the solution) comes out shuffled by a keyed hash; the summary `/s/{code}/summary` omits questions never shown |
| `unit/PublicStateCapTest.php` | Moderated rooms: `publicState` and `publicSummary` cap tallies and the quiz leaderboard, while the moderator's results, summary and CSV keep every word, answer, point and row |
| `unit/QuizCsvTest.php` | CSV of the moderated room with quiz questions: free text one row per answer group (previously 500), a number guess with its number, free text and words from the audience never become a formula, quotes per RFC 4180 |
| `unit/QuizFixWindowTest.php` | Correction window for quiz answers, enforced on the server: exactly one correction, only inside the window, with a new timestamp so the time gained is lost |
| `unit/QuizJoinNameTest.php` | Quiz join: names are unique per room, case-insensitive; your own token may keep or rewrite its name |
| `unit/QuizServiceTest.php` | Speed points, leaderboard including ties (1-2-2-4) and time tie-break |
| `unit/RoomApiErrorMapTest.php` | Moderator API error answers as they are today: a service's `InvalidArgumentException` becomes 400 in nine actions and 404 in seven, with its message; `showImage` says "Not found."; results and progress answer 204 for an unchanged `?v=` (results without building anything), progress in a moderated room 409; every action is listed as passing the message on or not, so a new one has to be classified |
| `unit/RoomApiNotFoundTest.php` | Moderator API: someone else's room answers exactly like an unknown code (404 "Room not found."), on every room-scoped route; the creating actions (room, copy, question, image, demo votes) are rate-limited per account with a message the toast shows, the reads are not |
| `unit/RoomGoneTest.php` | Room deleted while the request was in flight (`PaceService::locked`): 404 "Room not found.", not 500; publicly without a brute-force attempt (the code was right when the request came in) |
| `unit/RetentionGraceMigrationTest.php` | Migration `Version000000Date20260929120000`: where the cleanup job has never run, existing rooms without owner activity (`touched_at` = 0) count as used on the day of the update; where it already ran, no query at all |
| `unit/RoomLockTest.php` | The room lock (`RoomMapper::lockForUpdate`): `SELECT … FOR UPDATE` on MySQL/MariaDB, PostgreSQL and Oracle, without any write; on SQLite a no-op `UPDATE` of the room row first, so the transaction holds the write lock before it reads (a read-first transaction failed with "database is locked" once a parallel join committed); a deleted room is still "not found" |
| `unit/RoomPaceLifecycleTest.php` | Reset, copy, delete and demo rooms know about self-paced mode (progress, window, frozen order, join lock); a moderated room gets no additional write to the room row; `deleteRoom` removes the rows in one transaction (a failure rolls back and leaves the room complete, a deadlock is retried) and the image files only after the commit |
| `unit/RoomServiceTextTest.php` | Cleaning/shortening room titles and the copy suffix (private methods via reflection) |
| `unit/RoutesTest.php` | `routes.php`: every `{code}` carries `[A-Za-z0-9]{6}` and every `{pollId}` `\d+`, no other placeholder or requirement; the route table (name, URL, verb, and the `room` postfix that keeps the two `page#index` routes apart) is pinned as a whole |
| `unit/SelfCsvGoldenTest.php` | Golden bytes of both self-paced CSV views (`PaceStateService::exportCsv`, `players` and `answers`) over all seven quiz types: someone finished, someone halfway with an answer being checked, someone who never started, someone inside the correction window |
| `unit/SelfCsvTest.php` | Self-paced CSV views (`PaceStateService::exportCsv`): the time column stays empty without a timer; "finished" follows `PaceService::isFinished` |
| `unit/SelfGradeTest.php` | Self-paced grading of free text (`VoteService::gradeTextAnswer`): speed points use the limit that applied when answering, `pending` is dropped, moderated payloads come out byte-identical, grading runs as one transaction under the room lock |
| `unit/SelfImageVisibleTest.php` | Self-paced image visibility (`StateService::imageVisible` → `PaceStateService`): only whether THIS person has reached the question counts; moderated stays "running or revealed" |
| `unit/SensitiveParameterTest.php` | Voter tokens, nicknames, answers and token-keyed arrays stay out of exception traces (`#[\SensitiveParameter]`): a guard over every method in `lib/` with a `voterToken`, `meToken`, `nickname` or `answer` parameter, the vote values and arrays with other names, and two real traces |
| `unit/SelfJoinCapTest.php` | Self-paced caps throwaway joins cannot turn against the class: the cap of 300 counts players who have started, at most 600 are joined, and at that ceiling (open window only) players who joined 10 minutes ago and never started make room; only successful NEW players count against the address (120 per 10 minutes by default, `JoinLimitException`), taken names, retries and known players cost nothing; the ceiling and the count per address follow the app config (`max_players_per_room`, `max_new_players_per_address`) |
| `unit/SelfJoinTest.php` | Self-paced join (`VoteService::quizJoin` → `selfJoin`): under the room lock, no join after closing, "Lock joining" and the cap only affect new tokens, the name is fixed after starting, a new token starts empty; the moderated join shares only the room lock |
| `unit/SelfLeaderboardTest.php` | Self-paced leaderboard (`VoteService::selfLeaderboard`): only final votes count, no time tie-break without a timer, the whole deck in ONE query, a 2-s cache keyed by the window fields |
| `unit/SelfProgressTest.php` | Progress for the moderator (`PaceStateService::progress`): with feedback at the end, points, hits and leaderboard stay hidden until release (unless explicitly requested); `skipped` and `online` |
| `unit/SelfStateCapTest.php` | Self-paced rooms after the release: phone, projector and `/summary` get the top 10 plus the own row, the moderator's progress keeps the full leaderboard |
| `unit/SelfStateGateTest.php` | Self-paced public state (`PaceStateService::publicState`): no solution, distribution, unshuffled ranking order or verdict before it is due; the personal clock, no limit without a timer, and `progress.after` |
| `unit/SelfSummaryGateTest.php` | Self-paced phone summary (`PaceStateService::publicSummary`): `/summary` is never an answer key; before release only your own questions without solutions, after release exactly the ones you reached; no leaderboard in a practice run |
| `unit/SelfVersionTest.php` | Self-paced versions (`PaceStateService::version`): the version jumps when time alone changes something (deadline passed, vote final, time limit up) and does NOT jump while only seconds pass |
| `unit/SelfVoteTest.php` | Self-paced `/vote` (`VoteService::recordSelfVote`): the person's open progress row and personal clock, `limit` and correction window `fw` of the FIRST answer, unknown free text is `pending` with 0 points, re-checks after saving; the moderated path never touches the new dependencies |
| `unit/StateVersionTest.php` | The public version fingerprint (`StateService::stateVersion`): a keyed hash (24 hex characters) instead of guessable CRCs of the solution, and it still jumps exactly when something changes |
| `unit/TallyServiceTest.php` | Tallying of every question type: choice, words, scale (single/spectrum/compass), multi, number, text, rank, match |
| `unit/UnicodeTextTest.php` | Invisible characters in words and names (`TallyService::cleanText`): a fixed list is removed (soft hyphen, zero-width space, directional control characters, word joiner, BOM, reserved default-ignorables), while ZWNJ, ZWJ and tag characters inside a word stay |
| `unit/UserDeletedListenerTest.php` | Deleting a Nextcloud account deletes its rooms through `RoomService::deleteRoom` (questions, votes, players, presence, progress, images) and nobody else's, inside `deleteRoom`'s transaction; a room that fails is logged and skipped, and no exception reaches Nextcloud's own account clean-up; the listener is registered in `Application::register` |
| `unit/VoteStampTest.php` | The vote change stamp behind the 204 fingerprints (`VoteMapper::changeStamp`): no vote is read while nothing changes; every write path (insert, update, delete, upsert, the bulk deletes) yields a stamp never handed out before, also across workers and after an eviction; a write inside a transaction keeps the exact stamp until its marker expires; no shared cache or a broken one falls back to the exact stamp |
| `unit/VoterCookieTest.php` | The voter cookie against cookie tossing: `__Host-pulse_vt` on https with an empty webroot, `pulse_vt` otherwise; a name sent twice (also as `pulse.vt`, `pulse[vt`, `pulse_vt[x]`, `__Host-pulse.vt`) counts as no cookie, but a name that only PHP's mangling turns into `__Host-pulse_vt` (`_.Host-pulse_vt`, `_ Host-…`, `_[Host-…`) is dropped as PHP drops it and does not cost the visitor their cookie; a single legacy `pulse_vt` is adopted (moved, the old one expired) only within `LEGACY_ADOPT_PERIOD` after the first `__Host-` request, never next to a `__Host-` cookie; a broken config value means no adoption, not a 500 |
| `unit/WordCloudDedupeTest.php` | Word cloud: `Kaffee` and `KAFFEE` (test data) from one person are ONE word — vote validation and tallying use the same normal form (`TallyService::normalizeWord`) |
| `unit/WordVoteBoundsTest.php` | Anonymous input is bounded before the Unicode work: a word list longer than max(20, 2 × maxWords) is rejected at once (not after cleaning every entry), each word is cut to 200 characters before cleaning, the loop stops at maxWords distinct words with the same result as before; free text and nicknames are cut to four times their length first; long broken UTF-8 is dropped, never turned into `?` |

## What does NOT run here

Everything that really touches the database — `createRoom`, `duplicateRoom`,
`recordVote`, migrations. For that there are throwaway harnesses in the app
directory (`_lbtest.php`, `_titletest.php`, `_duptest.php`, `_reviewtest.php`,
`_ranktest.php`, `_imagetest.php`, `_matchtest.php`; `/_*.php` is gitignored,
so they are not part of the repository) that boot against the running
instance:

```sh
docker exec -u www-data nextcloud-nextcloud-1 php /var/www/html/apps/pulse/_duptest.php
```

They create their own rooms under a test UID and clean up at the end. Anyone
who wants to turn them into real integration tests needs a test database —
against the production instance they should deliberately stay throwaway
scripts.

End-to-end checks against the running instance live elsewhere:
`dev/sim/` (HTTP simulation of every flow, see `dev/sim/README.md`) and
`dev/design-shots/` (screenshots of every view, see
`dev/design-shots/README.md`). The browser-free rules of the self-paced quiz in
`src/util/pace.js` have their own test: `TZ=Europe/Berlin node dev/unit/pace.test.mjs`.
