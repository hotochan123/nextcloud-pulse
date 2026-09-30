# Pulse — live polls for Nextcloud

> **Vibe-coded:** Pulse was written by AI coding agents (Anthropic's Claude Code),
> not by hand. The maintainer set the goals, made the product decisions and tried
> the app out in the browser; there has been no independent human code review and
> no security audit. Details: [How this app was built](#how-this-app-was-built-ai-disclosure).

Mentimeter-style live polls as a native Nextcloud app. The presenter opens a room
and projects a six-character code; the audience joins through a public link
without a Nextcloud account and votes from their phones. Results update live
through polling.

## How this app was built (AI disclosure)

Pulse is vibe-coded. The maintainer ([hotochan123](https://github.com/hotochan123))
did not write the code by hand. They set the goals, made the product decisions
and tried the app out in the browser. Everything else was written by AI coding
agents:

- all code — PHP, JavaScript/Vue, CSS and the database migrations;
- the tests, the development tools (HTTP simulation, screenshot harness) and the
  throwaway scripts used for request fuzzing;
- the documentation, including this README, and the German translation.

**Tool and models.** The agents ran in Anthropic's Claude Code. The private
development history has 87 commits, from 16 July to 29 September 2026. Every one
of them carries a `Co-Authored-By` trailer naming the model: Claude Opus 4.8
(37 commits), Claude Opus 5 (44) and Claude Opus 5.5 (6). Commits in this public
repository carry the same trailers.

**Design.** The UI design explorations (mockups, design briefs) were also
produced with AI tools. They are not part of this repository.

**Verification.** The app is checked by a PHPUnit suite (over 1,300 tests),
unit tests for the self-paced rules, an HTTP simulation of complete poll and
quiz runs and a browser screenshot harness; during development it was also
fuzzed with throwaway request-fuzzing scripts. All of them were written by the
same AI agents, so they check the code against the agents' own reading of the
requirements. Not all of them run automatically: CI
(`.github/workflows/ci.yml`) only builds the bundle, checks that the committed
bundles in `js/` are exactly what the sources build to, checks the
translations and `info.xml` against the App Store schema, packs a test release
archive, runs the unit tests (`dev/unit`), `php -l` and the PHPUnit suite, and
checks that the dev scripts parse. The simulation (`dev/sim`) and the
screenshot harness (`dev/design-shots`) are run by hand — they need a running
Nextcloud instance — and the fuzzing scripts were one-offs that are not in this
repository. There has been no independent human code review and no security
audit. In September 2026 AI agents of the same kind reviewed the code for
security problems and fixed what they found (listed under "Security" in
[`CHANGELOG.md`](CHANGELOG.md)); what remains is described under
[Security notes](#security-notes). That review is no substitute for an
independent audit. How to report a problem: [`SECURITY.md`](SECURITY.md).

**History.** This public repository starts with one squashed commit. The earlier
history is kept private because early screenshots in it showed an internal host
name; the code is the same.

**What this means for you.** Evaluate Pulse yourself before you rely on it,
especially for anything sensitive. Issues and reviews are welcome
([issue tracker](https://github.com/hotochan123/hotochan123-nextcloud-pulse/issues)).
Pulse is licensed under AGPL-3.0-or-later and comes without any warranty.

## Features

- Two roles: **moderator** (signed in to Nextcloud) and **participant** (public,
  no account).
- Two room modes: **poll** (anonymous) and **quiz** (nickname, countdown,
  leaderboard).
- Question types — poll: multiple choice · word cloud · scale (single/spectrum/compass) ·
  ranking · **matching**. Quiz: multiple choice · true/false · multiple answers ·
  number guess · free text (with grading) · ranking · **matching**.
- A deck of several questions, an image per question, a projector view, QR code,
  practice run, and a summary with CSV export.
- **Self-paced** quizzes (the "Self-paced" toggle in a quiz deck's menu): instead
  of one question for everybody, each person works through the deck on their own
  phone — as a race in the room or as homework with a deadline. The moderator
  follows each person's progress, grades free-text answers and releases the
  results; the projector shows the race, never the questions. See below.
- An anonymous voter cookie (no link to a person) discourages double voting;
  whoever clears their cookies can vote again (see
  [Security notes](#security-notes)).
- A daily background job deletes rooms after 30 days without any activity:
  no participant was there, and the owner did nothing with the room — did not
  open it, edit the deck, present or look at the results. A deck prepared
  weeks ahead therefore stays. Participants alone keep a room alive only while
  its owner has used it within the last 180 days, so a room whose code keeps
  being polled still goes about 180 days after its owner last used it.
  Deleting a Nextcloud account deletes that account's rooms, with their
  questions, votes, players and images. The same daily job also deletes the
  rooms of accounts that no longer exist — for example ones deleted while
  Pulse was disabled, which would otherwise pass to a new account with the same
  user ID — and question images that no question refers to any more.

## Self-paced quiz

1. **Switch:** in the quiz room, deck menu → tick "Self-paced". This clears
   participants, answers and leaderboard (after asking, once somebody has
   joined); the questions stay. The header shows "Quiz · self-paced".
2. **Open:** "Open quiz …" instead of "Start presenting". In the dialog, choose
   how it ends:
   - "When I close it" = **race**: by default with a timer and feedback after
     every question; closing releases the results at once (or use "Stop without
     releasing" to check free-text answers first).
   - "At a set time" = **homework**, deadline 1 minute to 30 days: by default
     without a timer, results only with the release; the deadline closes the
     quiz by itself, even with no tab open.

   Timer and feedback can be changed in the dialog; a practice run always gives
   feedback at once and never shows a leaderboard. Once the quiz is open, the
   questions are locked until the room is reset.
3. **Progress view** ("Show progress"): for each person the question, answers,
   points and last activity; next to it the join code with QR, a projector
   preview and free-text grading (without the list of accepted answers; slips
   can be undone under "Checked just now"). At the bottom exactly one main
   action: "Close quiz" → check open free-text answers → "Release results". In
   the menu: lock joining, change the end or reopen, show points (with feedback
   at the end), participants and answers as CSV, reset the quiz; people can be
   removed one by one. The page is private — what you project is the projector
   window.
4. **Phone:** name → start card → questions. "Next question" appears once the
   answer is final (a few seconds, costs no points); questions without a timer
   can be skipped. Reloading continues at the same place — but only in the same
   browser: switching devices starts over with a new name at question 1.
5. **Projector:** while open, the race (how many people are on which question,
   how many have finished), with a large join block for the first two minutes,
   then — with feedback after every question — the top eight; once closed, only
   the count; once released, the final standings.

Limits: at most 300 people per room who have started and 600 who have joined,
and 120 new names per address and room within ten minutes; an instance can
change the last two (all limits: [Limits](#limits)). Lock joining once the
class is in. Implementation notes with
test paths and open points:
[`docs/pace-2026-09/UI.md`](docs/pace-2026-09/UI.md).

## Architecture

- **Public access** without login through `#[PublicPage]` + `#[NoCSRFRequired]` —
  the same mechanism as share links. The room code is the share token:
  `/apps/pulse/s/{code}`. Because that switches Nextcloud's CSRF check off, the
  participant API checks for itself that a vote comes from its own page (see
  [Security notes](#security-notes)).
- **Core decision on vote counting:** one database row per `(poll_id, voter_token)`
  (`UNIQUE` index), counted with `COUNT`/aggregates — no shared counter. A
  re-vote is an upsert (last write wins per person). Carried over from the
  original prototype so that simultaneous votes are not lost.
- **Realtime:** adaptive JSON polling. Moderated rooms poll every 1.2 to 2.5 s
  depending on view and phase, backing off to 4–5 s while nothing changes; in a
  self-paced quiz, phones poll every 0.7–10 s, the progress view every 2–10 s
  (`phoneDelay`/`progressDelay` in `src/util/pace.js`) and the deck status
  every 5–10 s. Polling pauses in hidden tabs, and an unchanged state is
  answered with an empty 204. In moderated rooms that check reads no votes: it
  compares a per-question change marker kept in Nextcloud's memory cache (the
  distributed one, such as Redis, where configured). Without any memory cache
  it falls back to reading the votes of the question. No High Performance
  Back-end needed.
- **Public views are capped:** phones and the projector get the top 100 words
  or free-text groups, at most 500 compass points and the first ten leaderboard
  rows plus the viewer's own row and neighbours, each with the full count. The
  moderator's results, the summary and the CSV export stay complete.

## Structure

```
lib/Controller/   PageController (moderator SPA), RoomApiController (moderator API),
                  PublicController (participant pages), PublicVoteController (public API),
                  AddinController (PowerPoint add-in manifest)
lib/Db/           Room, Poll, Vote, Player, Presence, Progress + QBMapper
lib/Service/      RoomService (rooms), DeckService (questions), VoteService (votes),
                  StateService (read views + CSV), TallyService (tallying),
                  AnswerRules (per-type answer checks + grading), QuizService (points),
                  PollImageService (images), DemoService, CodeGenerator,
                  PaceService + PaceStateService (self-paced quiz: window, read views),
                  PublicPayload (capped public views), Limits (caps per account)
lib/SetupCheck/   EmbedFraming (setup check for the PowerPoint embed)
lib/BackgroundJob/ CleanupStaleRoomsJob (deletes rooms unused for 30 days, rooms of deleted
                  accounts and question images nothing refers to)
lib/Listener/     UserDeletedListener (a deleted account takes its rooms with it)
lib/Migration/    Schema (pulse_rooms, pulse_polls, pulse_votes, … pulse_progress)
src/              Moderator.vue (control desk), Participant.vue (phone), Screen.vue (projector),
                  components/StageRow.vue (stage row model), ResultsView.vue,
                  self-paced: components/Pace*.vue (moderator), StageRace.vue (projector),
                  mixins/pace-phone.js (phone), util/pace.js (pure rules, no browser),
                  util/question-types.js (composer contract per question type, no browser),
                  styles.js (token layer without Vue — for the embed shell)
l10n/             Translations (de.json is maintained, de.js is generated from it)
build/            l10n-build.js (json -> js), l10n-check.js (coverage),
                  package.sh (release archive), certificate.sh (signing key)
tests/            PHPUnit (database-free) + run.sh
office-addin/     PowerPoint add-in (guide, Traefik example; the instance generates
                  the manifest itself)
dev/design-shots/ Screenshot harness for design reviews and store images (headless Firefox)
dev/sim/          HTTP simulation of complete runs against a running instance
.github/          CI: bundle (and that js/ matches it), PHPUnit, JS unit tests (dev/unit),
                  script syntax, translations, info.xml schema, test release archive
screenshots/      Images for the App Store page
.htaccess         Hides the development folders when a git checkout sits in the web root (Apache)
```

## References in code comments

Many code comments cite section numbers ("§2.4", "specification §1.6"),
acceptance criteria ("acceptance #7"), steps and gates of the build plan
("step 4.6", "gate 4.4b") or review IDs ("B1", "R2", "E1", "D6"). They point to
the working documents the AI agents worked from: the design briefs, handoffs,
review notes and screenshots of the July 2026 design rounds, and the
specification of the self-paced quiz from September 2026. None of these
documents is part of the public repository, so such a reference cannot be
looked up; read it as "this rule comes from the design notes". Files that cite
them say so in a note. The one exception is
[`docs/pace-2026-09/UI.md`](docs/pace-2026-09/UI.md), the implementation
record of the self-paced UI, which is included.

## Installation

Pulse is not in the Nextcloud App Store yet: the signing certificate it needs
has not been requested so far (as of 29 September 2026; steps and status in
[`docs/APPSTORE.md`](docs/APPSTORE.md)). Until then it is installed from this
repository. It has only been tested on Nextcloud 34 (34.0.1), with fresh
installations on SQLite, MariaDB 11.8, PostgreSQL 17 and MySQL 8.4.

Do not clone the repository into the web root. A checkout carries the
development tree — tests, the simulation and screenshot harness, sources and,
after a build, `node_modules/` with demo pages of its packages — and the web
server hands out static files below `apps/` as they are, so all of it would be
public on your Nextcloud's own origin. Clone it somewhere else and copy only the
runtime files into Nextcloud. The built bundles in `js/` are part of the
repository, so this needs no build step, only Node.js for the translation
check:

```
git clone https://github.com/hotochan123/hotochan123-nextcloud-pulse.git ~/pulse-src
cd ~/pulse-src
SKIP_BUILD=1 ALLOW_UNRELEASED=1 sh build/package.sh   # -> build/pulse-<version>.tar.gz
tar -xzf build/pulse-<version>.tar.gz -C <nextcloud>/apps/
occ app:enable pulse
```

The archive holds a single folder `pulse/` (the directory has to be named after
the app ID) with only the runtime folders — `appinfo`, `css`, `img`, `js`,
`l10n`, `lib`, `templates`, `office-addin` — plus README, licence and changelog.
Without a key it is not signed, which Nextcloud accepts for an app installed by
hand. `ALLOW_UNRELEASED=1` is only needed between releases (a checkout of a
release tag packs without it). Copying those folders yourself, for example with
`rsync`, works just as well. To update, replace `<nextcloud>/apps/pulse` with
the new folder; when the version in `appinfo/info.xml` went up, run
`occ upgrade` afterwards (until then Nextcloud does not serve the app).

Running Pulse straight from a git checkout inside `apps/` still works, and the
repository ships an `.htaccess` as a safety net for that case: on Apache (with
`mod_alias` and `AllowOverride` for the app folders, as in Nextcloud's own
setup) it answers 404 for `node_modules/`, `dev/`, `build/`, `tests/`, `src/`,
`tools/`, `docs/`, `.git/`, local `_*.php` scripts and the package files. nginx
ignores `.htaccess`; there, deploy the runtime files as above.

## Building

Build outside the web root as well, then deploy as above:

```
npm ci             # exactly the dependency versions of package-lock.json
npm run build      # produces js/pulse-main.js, js/pulse-public.js and js/pulse-styles.js
```

Use Node.js 22.18.0, the version the committed bundles are built with: with it,
`npm ci` plus `npm run build` reproduces `js/` byte for byte. CI builds the same
way and fails when the committed `js/` differs from a fresh build, so after a
change to `src/` or to the dependencies, commit the rebuilt `js/` along with
it. `build/package.sh` without `SKIP_BUILD=1` runs the build itself.

## Languages

The source language is **English** — the code contains the English text, and
translations come from `l10n/`. That way the app does not suddenly show German
buttons outside German-speaking countries, and further languages need no code
change.

```
PHP:  $this->l10n->t('Room not found.')          // IL10N in the constructor
      $this->l10n->n('%n vote', '%n votes', $x)
Vue:  {{ t('pulse', 'Save') }}                    // Vue.prototype.t/n from main.js
      {{ n('pulse', 'answer', 'answers', total) }}
      t('pulse', 'Hello {name}', { name })        // placeholders in curly braces
```

Only `l10n/de.json` is maintained by hand; `l10n/de.js` (the file Nextcloud
loads in front of the bundle) is generated from it:

```
npm run l10n         # de.json -> de.js
npm run l10n:check   # reports missing and orphaned translations, exit 1 on gaps
```

Plurals live under the combined key `_singular_::_plural_` with an array as the
value — the same rule in PHP and JS. Numbers are formatted in the language of
the interface (`fmtNum()` in the frontend, `NumberFormatter` in the CSV export);
a hard-wired decimal comma would be a different number in an English interface.

## Data model

```
Poll  = { id, type, question, options, answerKey, maxWords, timeLimit, image, status }

# options holds something different per type (JSON in one column):
choice/multi/rank/truefalse -> [{id,label}, …]
scale                       -> {mode, min, max, aspects|axisX/axisY, …}   -> getScaleConfig()
match                       -> {items:[{id,label}], targets:[{id,label}]} -> getMatchConfig()

# answerKey is the quiz solution (never sent to participants before the reveal):
multi {correct:[id]} · rank {order:[id]} · match {map:{itemId:targetId}}
number {target,tolerance} · text {accepted:[],rejected:[]}

Vote  = { value: "<optionId>" }                  # choice/truefalse
Vote  = { value: ["word", "word"] }              # words (lower-cased when tallying)
Vote  = { value: ["id","id"] }                   # rank (complete permutation)
Vote  = { value: {"<itemId>": "<targetId>"} }    # match (every row assigned)
```

## Tests

```
tests/run.sh          # PHPUnit inside the Nextcloud container (see tests/README.md)
npm run l10n:check    # translation coverage, exit 1 on gaps
TZ=Europe/Berlin node dev/unit/pace.test.mjs   # self-paced rules (deadlines, phone cards, polling rate)
node dev/unit/format.test.mjs                  # shared formatting helpers (tall labels, "x min ago")
node dev/unit/question-types.test.mjs          # composer contract per question type, deck labels, loss texts
dev/sim/run.sh        # HTTP simulation of complete runs against the running instance (dev/sim/README.md)
```

Screenshots of every view (projector, phone, moderator — per question type, open
and revealed, light and dark) are taken automatically by `dev/design-shots/run.sh`;
see `dev/design-shots/README.md`. The run also measures every projector stage and
reports when content runs past the clipped kiosk — on a still image you only
notice that if you look for it.

Handoffs, implementation status and images of the July 2026 design rounds are
not part of the public repository; see
[References in code comments](#references-in-code-comments).

The unit suite stays database-free (grading, tallying, text normalisation,
parameter pass-through). Paths that really touch the database are exercised by
the HTTP simulation and by throwaway scripts against a running instance, which
are not part of the repository — see `tests/README.md`.

## Embedding in PowerPoint

A custom content add-in (sideloaded, not from the Office Store). Each instance
serves the Office manifest itself — `/apps/pulse/addin/manifest.xml`, and
"PowerPoint add-in" in the deck menu. It carries the address it was downloaded
from; an Office manifest has no variables, so a shipped file would need a hand
edit on every installation. Guide: `office-addin/README.md`.

**Note:** Nextcloud sends `X-Frame-Options: SAMEORIGIN` from `.htaccess` and
`lib/base.php` — an app cannot override that. For `/apps/pulse/screen/` and
`/apps/pulse/embed` the header has to be removed in the web server or reverse
proxy; snippets for Apache, nginx and Traefik are in `office-addin/README.md`.
Whether it is still there is reported by the setup check "Pulse: embedding in
PowerPoint" under Administration → Overview, separately for each address.

## Limits

**Per account and quiz room.** No user quota counts what Pulse stores — question
images live in Nextcloud's app data, rooms and questions in the database — and
anonymous participants can add players to a quiz, so Pulse keeps its own caps.
Each can be changed per instance, for example
`occ config:app:set pulse max_rooms_per_owner --value 500`. Only a positive
whole number overrides the default (anything else keeps it, so a typo never
means "unlimited"), and a change takes effect within a few seconds.

| App config key | Default | Limits |
|---|---|---|
| `max_rooms_per_owner` | 200 | rooms of one account |
| `max_polls_per_room` | 100 | questions in one room |
| `max_question_length` | 1000 | characters of a question text |
| `max_image_bytes_per_owner` | 209715200 (200 MB) | question images of all rooms of one account, counted as stored — Pulse re-encodes every upload as PNG or JPEG, which can be several times the size of the upload |
| `max_players_per_room` | 600 | players who have joined one quiz: every player of a moderated quiz, started or not in a self-paced one (the self-paced cap of 300 who have started is fixed); the flood guard on joining grows with it |
| `max_new_players_per_address` | 120 | new names per address and self-paced quiz within ten minutes — raise it when a larger class or a lecture hall joins from behind one address |
| `rate_limit_create` | 30 | new rooms per account and minute |
| `rate_limit_duplicate` | 10 | room copies per account and minute |
| `rate_limit_add_poll` | 120 | new questions per account and minute |
| `rate_limit_upload_image` | 30 | image uploads per account and minute |
| `rate_limit_demo` | 30 | batches of demo votes per account and minute |

A cap answers with a message (400), a rate limit with "Too many attempts. Please
wait a moment." (429). Copying a room checks its questions and images against
the caps before it writes anything. Requests that arrive at the same moment
all check against what was stored before any of them wrote, so the image
budget and the caps on rooms and questions count once more after writing, and
a request that finds the account over takes its own room, question, copy or
image back — parallel requests may then all be refused, but they no longer get
past a cap together. The per-minute rate limits come from Nextcloud's rate
limiter, which is approximate under parallel requests: a burst can get a few
more through. Answer options are cut to 200 characters,
and a free-text question keeps at most 50 accepted answers.

**Per room and address.** Fixed in the code where no app config key is named;
"an address" is what Nextcloud counts as one — an IPv4 address or an IPv6 /56
network.

- Wrong room codes: after ten from one address within 30 minutes, further
  unknown codes get 429. While an address is blocked its misses are not
  counted, so the block ends 30 minutes after the last miss that counted, even
  if a phone keeps polling a deleted room. Existing rooms keep working.
- New anonymous identities (a vote or a join without the voter cookie): 300 per
  address and room within ten minutes.
- Presence ("N here", a player's last activity): 600 new entries per address
  and room within ten minutes. Beyond that a phone works as usual; it is
  counted as present once the window has moved on.
- Joining a quiz: 2,400 requests per address and room within ten minutes (four
  per possible player, more with a higher `max_players_per_room`), in both
  modes — only a guard against floods.
- Moderated quiz: at most 600 players (`max_players_per_room`).
- Self-paced quiz: at most 300 players who have started and 600 who have
  joined (`max_players_per_room`); at that ceiling, while the quiz is open,
  players who joined more than ten minutes ago and never started make room. At
  most 120 new names per address and room within ten minutes
  (`max_new_players_per_address`).
- Word cloud: a vote with more than twice the allowed number of words (at least
  20) is refused; long words and texts are cut before they are processed.

## Security notes

- **Anonymous participants.** A random voter token in a cookie discourages
  double voting without identifying anyone; whoever clears their cookies can
  vote again. In a quiz, participants only give a nickname.
- **Voter cookie.** On https with Nextcloud at the root of its domain the cookie
  is called `__Host-pulse_vt` (Secure, `Path=/`, no `Domain`, `HttpOnly`,
  `SameSite=Lax`). Browsers accept such a cookie only from this very origin, so
  another service on a sibling subdomain can neither plant a token it knows —
  and then read a participant's answers with it — nor overwrite it. Under http
  or a sub-path it stays `pulse_vt` without that protection. A `pulse_vt` from
  before the update is taken over for 60 days after the first request under the
  new name and ignored after that; the start is kept in the app config key
  `voter_cookie_host_since`, and setting it to 1
  (`occ config:app:set pulse voter_cookie_host_since --value=1 --type=integer`)
  ends the takeover at once. A request that sends a cookie name twice counts as
  having no cookie. A name that PHP only turns into `__Host-pulse_vt` —
  `_.Host-pulse_vt`, which any sibling subdomain may set — is ignored, as PHP
  itself ignores it.
- **Votes only from the page itself.** Joining, voting and moving on in a
  self-paced quiz (`POST /s/{code}/join`, `/vote`, `/next`) need
  `X-Requested-With: XMLHttpRequest`, which the page's own script sends, or
  `Sec-Fetch-Site: same-origin`; anything else gets 403 and no cookie. A form
  on another site, a sibling subdomain included, can therefore neither vote or
  rename in a participant's name nor replace their cookie. Reading (`/state`,
  `/summary`, images) needs only the code.
- **Room codes** have six characters out of 32, about 10^9 codes. A wrong code
  counts towards Nextcloud's brute-force protection (action `pulseRoomCode`):
  after ten misses from one address within 30 minutes, further unknown codes
  get 429. Only misses ever count, and an existing room always opens — a typo
  in the join form, a web page that embeds made-up codes or a room deleted
  under 30 polling phones cannot lock a school or a venue out of its own room.
  Requests from a blocked address are not counted, so phones still polling a
  deleted room neither prolong the block nor grow the list of attempts that
  Nextcloud reads for that address on every check, its login included.
  The price: an address can still tell existing from unknown codes, even while
  it is blocked; only a rate limit in front of Nextcloud (in the reverse
  proxy, for `/apps/pulse/s/` and `/apps/pulse/screen/`) bounds how fast. An
  admin lifts a block with `occ security:bruteforce:reset <address>`. The
  moderator API answers someone else's room exactly like an unknown code (404
  "Room not found."), so a logged-in account learns no more there.
- **Ballot stuffing.** Without an account, anyone with the code can vote again
  and again under new identities — another browser, a private window, or a
  script. Voter tokens are random values the server does not sign, so a script
  can make up its own; the limit on new identities (see [Limits](#limits))
  only slows down scripts that send no cookie at all. Pulse cannot prevent
  this: the result of an anonymous poll is an impression, not a head count.
  What is bounded is the cost for everybody else: public views are capped (see
  [Architecture](#architecture)), an unchanged state costs no vote reads, a
  request without the cookie writes no presence row, and made-up cookies add
  at most 600 presence rows per address and room in ten minutes. Every
  accepted vote still makes each open phone and the projector fetch the state once more, and the
  server builds it from all votes of the question — a few hundred milliseconds
  per viewer at tens of thousands of votes. The owner's only remedy is
  resetting the room, which deletes the real results as well.
- **Names on the podium.** Anyone with the code can join a quiz under any free
  name and reach the projector's podium. The host can lock joining ("Lock
  joining" in the menus) and remove players — in a moderated quiz from the
  "Participants" list in the "Only for you" panel and under the standings, in
  a self-paced one from the progress view. A removed player's answers are
  deleted; they can join again from zero points unless joining is locked. In a
  moderated quiz a name is frozen once its player has answered or a question
  has ended. Names are compared without case, invisible characters (every
  character Unicode reserves as "default ignorable", unassigned ones included)
  and lookalike letters (fullwidth forms, Greek or Cyrillic letters that look
  Latin; with PHP's `intl` extension also pairs such as "PauI" and "Paul"), so
  nobody can pass as a player already in the room — not even by re-spelling
  their own name: "paui" may become "PAUI", but not "PauI" next to a "Paul". That occasionally refuses a
  genuine name close to a taken one ("Ian" next to "lan"); adding a letter
  helps.
- **Quiz solutions** (`answerKey`) are not sent to phones or the projector
  before the reveal. A self-paced quiz can still be scouted: a second identity
  that answers anything moves on at once (only an unanswered timed question
  holds it until the time is up), so it sees the whole timed deck before the
  main identity's clock starts, and the main identity still earns full speed
  points. With feedback "After each question" — the race default — leaving a
  question makes its answer final at once and shows whether it was right, so a
  few throwaway identities find the right option of every choice question. For
  anything graded, open the quiz with feedback "At the end", lock joining once
  the class is in and check the names against your class list. Pulse cannot
  tie an identity to a person; where that matters, use a tool with a sign-in
  for every participant.
- **Framing.** `/apps/pulse/screen/{code}` (the projector view) and
  `/apps/pulse/embed` (the add-in shell) may be framed by any site —
  `frame-ancestors *`, and the web server drops `X-Frame-Options` for exactly
  these paths (see [Embedding in PowerPoint](#embedding-in-powerpoint)). That
  is what the PowerPoint add-in needs. Both are read-only; the projector view
  shows what anyone with the code sees anyway. The voting page `/s/{code}` and
  the rest of Nextcloud keep `SAMEORIGIN`.
- **office.js in your origin.** The add-in shell loads Microsoft's `office.js`
  from Microsoft's CDN into your Nextcloud's origin. Inside PowerPoint there
  is no Nextcloud session, but opened in a browser where you are logged in to
  Nextcloud, that third-party script has the reach of your session. Test the
  add-in address in a private window (see `office-addin/README.md`). The add-in
  manifest asks PowerPoint only for the `Restricted` permission — the document
  settings in which the room code travels with the `.pptx` — so script inside
  the add-in cannot read or change the presentation. A manifest downloaded
  before that change asked for more; download it again.
- **Development files** do not belong in the web root; see
  [Installation](#installation).
- **Logs.** Voter tokens, nicknames and answers are marked as sensitive
  parameters, so they do not end up in exception traces in `nextcloud.log`.
- **Retention.** Rooms, with their answers and nicknames, are deleted by the
  daily job as described under [Features](#features).
- As described [above](#how-this-app-was-built-ai-disclosure), the code has not
  had an independent human review or a security audit; the AI agents' own
  security review is summarised in the changelog. Reporting a problem:
  [`SECURITY.md`](SECURITY.md).

## Release

```
build/certificate.sh    # once: key + certificate request for the signature
build/package.sh        # builds, checks, signs and packs build/pulse-<version>.tar.gz
```

The whole way into the Nextcloud App Store — public repository, certificate,
app ID registration, version, signature, upload — is described in
[`docs/APPSTORE.md`](docs/APPSTORE.md).

## Licence

AGPL-3.0-or-later, see [`LICENSE`](LICENSE). Every source file carries the
matching SPDX header.

## Known limits and possible extensions

- App Store: not published yet. The screenshots are in `screenshots/`; still
  to do, in this order, are making this repository public, the pull request
  for the signing certificate (not opened yet), registering the app ID and the
  first signed release (as of 29 September 2026). Checklist in
  `docs/APPSTORE.md`. Further languages would go through Transifex and need
  Nextcloud's involvement.
- Tested on Nextcloud 34 only; `info.xml` declares 34 only. Nextcloud 35
  (September 2026) is not covered yet: updating an instance to 35 disables
  Pulse, and it stays off until a release declares 35.
- Question type image hotspot (tap on an image instead of choosing an answer).
- Self-paced quiz: a rejoin code for switching devices, starting from the
  waiting state without a tap, a demo race and store images; plus small engine
  follow-ups (list in `docs/pace-2026-09/UI.md`).
- Real push instead of polling — would need a WebSocket service of its own;
  `notify_push` is not an option because participants are anonymous and not
  signed in.
- Ballot stuffing: without an account, the voter cookie only discourages voting
  twice — a documented limit, not fixable without a login. Its cost for other
  viewers is bounded, but every accepted vote still has each open page rebuild
  the state from all votes of the question; a shared cache of the capped public
  results per change would make that one computation for all viewers. Number
  guesses still send one row per distinct value to phones and the projector.
- Self-paced quizzes are open to scouting with a second identity, and with
  feedback after each question to finding the right answers that way (see
  [Security notes](#security-notes)). Holding answered timed questions until
  their time is up, withholding verdicts until the end, or a question order per
  person would each narrow it.
- More than 60 people behind one address (a lecture hall on one NAT) who join
  the same self-paced quiz within ten minutes: the rest get "Too many attempts.
  Please wait a moment." until the window slides on.
- The add-in shell loads `office.js` even when it is opened outside Office;
  loading it only inside an Office host is still to do.
