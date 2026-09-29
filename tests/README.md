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
| `unit/CleanupRetentionTest.php` | Retention (`RoomService::cleanupStaleRooms`): a room stays as long as its latest sign of life is within the retention period — participant heartbeat, owner activity (`touched_at`, every room mode) and, for self-paced rooms, also opening, deadline, closing and release; a homework with a deadline that is evaluated weeks later must not vanish with its results, nor a moderated deck its owner edited yesterday; without owner activity moderated rooms keep exactly the old rule |
| `unit/CodeGeneratorTest.php` | Room code alphabet without I/O/0/1, collisions are re-rolled, token lengths |
| `unit/ControllerInputTest.php` | Every parameter of every controller action sent as a list, broken `pulse_vt` cookies: no 500, no warning; a source-code guard against raw casts on request values; `close {release}`: missing = old rule, booleans pass, anything else is 400 without closing; the free-text answer used for grading keeps NUL like the stored vote |
| `unit/DeckSetCurrentTest.php` | Setting the cursor (`DeckService::setCurrent`): in a quiz every jump restarts the timer and opens the question; in a poll the FIRST jump records "was shown" so the public summary can leave out questions never shown |
| `unit/DeckUpdatePollTest.php` | Editing a question (`DeckService::updatePoll`): new content clears the votes and a question that is not running counts as "never shown" again; `ended` stays, the running question stays (only an open quiz timer restarts), locked stays locked |
| `unit/HostileInputTest.php` | `DeckService`, `VoteService::normalizeValue` and image upload with nested lists and objects, `true`, 1e300, INF, "1e999", twenty digits, broken UTF-8 and NUL: a storable result or `InvalidArgumentException` (400), never a warning, `TypeError` or a value `json_encode` chokes on |
| `unit/ImageRouteTest.php` | Image routes carry `#[NoCSRFRequired]` — without it an `<img src>` gets a 412 |
| `unit/InputTest.php` | Raw client values: lists, 1e999, NAN, broken UTF-8 and NUL become "not sent" instead of a PHP warning; `rawStr` keeps NUL (grading free text) |
| `unit/InsertColumnsTest.php` | MySQL has no default for some NOT NULL columns (Doctrine drops the declared default of TEXT columns there), so every INSERT must carry them: the migrations are replayed offline and MySQL 8.4's DBAL platform decides; a new entity marks exactly the columns whose declared default MySQL drops (`pulse_polls.options`); `addPoll` for every question type and `duplicateRoom` carry every column without a MySQL default; a loaded poll still updates only what changed |
| `unit/LeaderboardSkipTest.php` | Leaderboard without one question (`VoteService::leaderboardFor`, `$skipPollIds`): the public summary leaves the running, still hidden question out of the scoring |
| `unit/MatchGradingTest.php` | Matching in a quiz: all-or-nothing, row order does not matter, speed points |
| `unit/OwnerActivityTest.php` | Retention, controller side: every moderator request that names a room records owner activity exactly once, moderated rooms included; the room list and creating a room record nothing; at most one write per hour; a source-code guard that rooms are only looked up through `ownedRoom()` |
| `unit/PaceFinalRuleTest.php` | Self-paced: ONE rule for "final", "correctable", "finished" and the `/next` value, used by phone, projector, progress, leaderboard and CSV alike |
| `unit/PaceGuardTest.php` | Self-paced guards on the existing moderator routes and switching the pace; with `pace='live'` every guard is a no-op |
| `unit/PaceNextTest.php` | Self-paced `/next`, the only place a clock starts: double taps, parallel tabs and aborts (stale `after`, compare-and-set, healing), the preview lock on timed questions, removal while `/next` is in flight |
| `unit/PaceOrderTest.php` | Self-paced frozen order: the single source for "question k of n" and the next question; `seq` is always the index in the FULL order |
| `unit/PacePlayerAdminTest.php` | Self-paced "Lock joining" and removing a person: removal clears everything tied to their token (player, votes in the frozen deck, progress, presence), also after release |
| `unit/PaceStateTestCase.php` | Not a test: shared base class for the self-paced read-view tests (a room with three questions in memory, mapper doubles, an adjustable clock; `PaceService`, `VoteService`, `TallyService` and `DeckService` are real) |
| `unit/PaceStillJoinedTest.php` | Self-paced races with removing and deleting: `assertStillJoined` leaves no vote or row without a player; `locked()` turns a deleted room into `RoomGoneException` (404) |
| `unit/PaceWindowStateTest.php` | Self-paced window state derived from the timestamps; at the second `closes_at` the window is CLOSED, everywhere alike (state, votes, versions) |
| `unit/PaceWindowTest.php` | Self-paced window actions (open, close, extend, release): each runs under the room lock, checks the freshly locked row, and rolls back on every exception |
| `unit/PollParamsTest.php` | The controller passes on every question field the `DeckService` reads (a forgotten field = "missing" although filled in) |
| `unit/PollVoteRaceTest.php` | Two simultaneous first poll votes from the same token: the second insert fails on the UNIQUE index and becomes an update (previously 500); other database errors stay errors |
| `unit/ProtocolConstantTest.php` | Server (`Application::PROTOCOL`) and bundle (`src/util/protocol.js`) state the same protocol number |
| `unit/PublicRevealGateTest.php` | Reveal gates of the public views: leaderboard without the hidden running question, "quiz over" only while nothing new is running, old poll rooms (from before the start timestamp) count as "shown" through their votes, hidden quiz questions keep their real status (`revealed` alone says whether it is revealed), shuffle order independent of the solution |
| `unit/PublicSpoilerTest.php` | What the public views (projector, phone) must NOT give away in advance: the stored order of ranking/matching options (it IS the solution) comes out shuffled by a keyed hash; the summary `/s/{code}/summary` omits questions never shown |
| `unit/QuizCsvTest.php` | CSV of the moderated room with quiz questions: free text one row per answer group (previously 500), a number guess with its number, free text and words from the audience never become a formula, quotes per RFC 4180 |
| `unit/QuizFixWindowTest.php` | Correction window for quiz answers, enforced on the server: exactly one correction, only inside the window, with a new timestamp so the time gained is lost |
| `unit/QuizJoinNameTest.php` | Quiz join: names are unique per room, case-insensitive; your own token may keep or rewrite its name |
| `unit/QuizServiceTest.php` | Speed points, leaderboard including ties (1-2-2-4) and time tie-break |
| `unit/RoomGoneTest.php` | Room deleted while the request was in flight (`PaceService::locked`): 404 "Room not found.", publicly like an unknown code (throttled), not 500 |
| `unit/RetentionGraceMigrationTest.php` | Migration `Version000000Date20260929120000`: where the cleanup job has never run, existing rooms without owner activity (`touched_at` = 0) count as used on the day of the update; where it already ran, no query at all |
| `unit/RoomPaceLifecycleTest.php` | Reset, copy, delete and demo rooms know about self-paced mode (progress, window, frozen order, join lock); a moderated room gets no additional write to the room row; `deleteRoom` removes the rows in one transaction (a failure rolls back and leaves the room complete, a deadlock is retried) and the image files only after the commit |
| `unit/RoomServiceTextTest.php` | Cleaning/shortening room titles and the copy suffix (private methods via reflection) |
| `unit/SelfCsvTest.php` | Self-paced CSV views (`PaceStateService::exportCsv`): the time column stays empty without a timer; "finished" follows `PaceService::isFinished` |
| `unit/SelfGradeTest.php` | Self-paced grading of free text (`VoteService::gradeTextAnswer`): speed points use the limit that applied when answering, `pending` is dropped, moderated payloads come out byte-identical, grading runs as one transaction under the room lock |
| `unit/SelfImageVisibleTest.php` | Self-paced image visibility (`StateService::imageVisible` → `PaceStateService`): only whether THIS person has reached the question counts; moderated stays "running or revealed" |
| `unit/SelfJoinTest.php` | Self-paced join (`VoteService::quizJoin` → `selfJoin`): under the room lock, no join after closing, "Lock joining" and the cap of 300 only affect new tokens, the name is fixed after starting, a new token starts empty |
| `unit/SelfLeaderboardTest.php` | Self-paced leaderboard (`VoteService::selfLeaderboard`): only final votes count, no time tie-break without a timer, the whole deck in ONE query, a 2-s cache keyed by the window fields |
| `unit/SelfProgressTest.php` | Progress for the moderator (`PaceStateService::progress`): with feedback at the end, points, hits and leaderboard stay hidden until release (unless explicitly requested); `skipped` and `online` |
| `unit/SelfStateGateTest.php` | Self-paced public state (`PaceStateService::publicState`): no solution, distribution, unshuffled ranking order or verdict before it is due; the personal clock, no limit without a timer, and `progress.after` |
| `unit/SelfSummaryGateTest.php` | Self-paced phone summary (`PaceStateService::publicSummary`): `/summary` is never an answer key; before release only your own questions without solutions, after release exactly the ones you reached; no leaderboard in a practice run |
| `unit/SelfVersionTest.php` | Self-paced versions (`PaceStateService::version`): the version jumps when time alone changes something (deadline passed, vote final, time limit up) and does NOT jump while only seconds pass |
| `unit/SelfVoteTest.php` | Self-paced `/vote` (`VoteService::recordSelfVote`): the person's open progress row and personal clock, `limit` and correction window `fw` of the FIRST answer, unknown free text is `pending` with 0 points, re-checks after saving; the moderated path never touches the new dependencies |
| `unit/StateVersionTest.php` | The public version fingerprint (`StateService::stateVersion`): a keyed hash (24 hex characters) instead of guessable CRCs of the solution, and it still jumps exactly when something changes |
| `unit/TallyServiceTest.php` | Tallying of every question type: choice, words, scale (single/spectrum/compass), multi, number, text, rank, match |
| `unit/UnicodeTextTest.php` | Invisible characters in words and names (`TallyService::cleanText`): a fixed list is removed (soft hyphen, zero-width space, directional control characters, word joiner, BOM), while ZWNJ, ZWJ and tag characters inside a word stay |
| `unit/UserDeletedListenerTest.php` | Deleting a Nextcloud account deletes its rooms through `RoomService::deleteRoom` (questions, votes, players, presence, progress, images) and nobody else's, inside `deleteRoom`'s transaction; a room that fails is logged and skipped, and no exception reaches Nextcloud's own account clean-up; the listener is registered in `Application::register` |
| `unit/WordCloudDedupeTest.php` | Word cloud: `Kaffee` and `KAFFEE` (test data) from one person are ONE word — vote validation and tallying use the same normal form (`TallyService::normalizeWord`) |

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
