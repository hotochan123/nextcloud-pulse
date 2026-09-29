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

**Verification.** The app is checked by a PHPUnit suite (827 tests), unit tests
for the self-paced rules, an HTTP simulation of complete poll and quiz runs and a
browser screenshot harness; during development it was also fuzzed with throwaway
request-fuzzing scripts. All of them were written by the same AI agents, so they
check the code against the agents' own reading of the requirements. Not all of
them run automatically: CI (`.github/workflows/ci.yml`) only builds the bundle,
checks the translations and `info.xml` against the App Store schema, packs a
test release archive and runs `php -l` and the PHPUnit suite. The unit tests
(`dev/unit`), the simulation (`dev/sim`) and the screenshot harness
(`dev/design-shots`) are run by hand — the last two need a running Nextcloud
instance — and the fuzzing scripts were one-offs that are not in this
repository. There has been no independent human code review and no security
audit.

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
- Protection against double voting through an anonymous voter cookie (no link to
  a person).
- Rooms nobody has used for 30 days are deleted by a daily background job.

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

Limits: at most 300 people per room, 120 joins per address and room within ten
minutes. Implementation notes with test paths and open points:
[`docs/pace-2026-09/UI.md`](docs/pace-2026-09/UI.md).

## Architecture

- **Public access** without login through `#[PublicPage]` + `#[NoCSRFRequired]` —
  the same mechanism as share links. The room code is the share token:
  `/apps/pulse/s/{code}`.
- **Core decision on vote counting:** one database row per `(poll_id, voter_token)`
  (`UNIQUE` index), counted with `COUNT`/aggregates — no shared counter. A
  re-vote is an upsert (last write wins per person). Carried over from the
  original prototype so that simultaneous votes are not lost.
- **Realtime:** adaptive JSON polling. Moderated rooms poll every 1.2 to 2.5 s
  depending on view and phase, backing off to 4–5 s while nothing changes; in a
  self-paced quiz, phones poll every 0.7–10 s, the progress view every 2–10 s
  (`phoneDelay`/`progressDelay` in `src/util/pace.js`) and the deck status
  every 5–10 s. Polling pauses in hidden tabs, and an unchanged state is
  answered with an empty 204. No High Performance Back-end needed.

## Structure

```
lib/Controller/   PageController (moderator SPA), RoomApiController (moderator API),
                  PublicController (participant pages), PublicVoteController (public API),
                  AddinController (PowerPoint add-in manifest)
lib/Db/           Room, Poll, Vote, Player, Presence, Progress + QBMapper
lib/Service/      RoomService (rooms), DeckService (questions), VoteService (votes),
                  StateService (read views + CSV), TallyService (tallying),
                  QuizService (points), PollImageService (images), DemoService, CodeGenerator,
                  PaceService + PaceStateService (self-paced quiz: window, read views)
lib/SetupCheck/   EmbedFraming (setup check for the PowerPoint embed)
lib/BackgroundJob/ CleanupStaleRoomsJob (deletes rooms unused for 30 days)
lib/Migration/    Schema (pulse_rooms, pulse_polls, pulse_votes, … pulse_progress)
src/              Moderator.vue (control desk), Participant.vue (phone), Screen.vue (projector),
                  components/StageRow.vue (stage row model), ResultsView.vue,
                  self-paced: components/Pace*.vue (moderator), StageRace.vue (projector),
                  mixins/pace-phone.js (phone), util/pace.js (pure rules, no browser),
                  styles.js (token layer without Vue — for the embed shell)
l10n/             Translations (de.json is maintained, de.js is generated from it)
build/            l10n-build.js (json -> js), l10n-check.js (coverage),
                  package.sh (release archive), certificate.sh (signing key)
tests/            PHPUnit (database-free) + run.sh
office-addin/     PowerPoint add-in (guide, Traefik example; the instance generates
                  the manifest itself)
dev/design-shots/ Screenshot harness for design reviews and store images (headless Firefox)
dev/sim/          HTTP simulation of complete runs against a running instance
.github/          CI: bundle, PHPUnit, translations, info.xml schema
screenshots/      Images for the App Store page
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

Pulse is not in the Nextcloud App Store yet (see [Release](#release)) and has
only been tested on Nextcloud 34. The built bundles in `js/` are part of the
repository, so a checkout runs without a build step. The directory has to be
named after the app ID, `pulse`:

```
git clone https://github.com/hotochan123/hotochan123-nextcloud-pulse.git <nextcloud>/apps/pulse
occ app:enable pulse
```

## Building

```
npm install
npm run build      # produces js/pulse-main.js, js/pulse-public.js and js/pulse-styles.js
```

Then enable it in Nextcloud:

```
occ app:enable pulse
```

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

## Security notes

- Participants are anonymous. A random voter token in a cookie prevents double
  voting without identifying anyone; in a quiz, participants only give a
  nickname.
- Guessing room codes is slowed down by Nextcloud's brute-force protection
  (action `pulseRoomCode`); joining a self-paced quiz is limited to 120 requests
  per address and room within ten minutes.
- Quiz solutions (`answerKey`) are not sent to phones or the projector before the
  reveal.
- Without an account, the voter cookie is the only barrier against ballot
  stuffing: whoever clears their cookies can vote again. This is a documented
  limit, not fixable without a login.
- As described [above](#how-this-app-was-built-ai-disclosure), the code has not
  had an independent human review or a security audit.

## Release

```
build/certificate.sh    # once: key + certificate request for the signature
build/package.sh        # builds, checks, signs and packs build/pulse-<version>.tar.gz
```

The whole way into the Nextcloud App Store — certificate, version, signature,
upload — is described in [`docs/APPSTORE.md`](docs/APPSTORE.md).

## Licence

AGPL-3.0-or-later, see [`LICENSE`](LICENSE). Every source file carries the
matching SPDX header.

## Known limits and possible extensions

- App Store: the screenshots are in `screenshots/`; what is missing is the
  signing certificate — the pull request that requests it has not been opened
  yet (as of 29 September 2026). Checklist in `docs/APPSTORE.md`. Further
  languages would go through Transifex and need Nextcloud's involvement.
- Tested on Nextcloud 34 only; `info.xml` declares 34 only.
- Question type image hotspot (tap on an image instead of choosing an answer).
- Self-paced quiz: a rejoin code for switching devices, starting from the
  waiting state without a tap, a demo race and store images; plus small engine
  follow-ups (list in `docs/pace-2026-09/UI.md`).
- Real push instead of polling — would need a WebSocket service of its own;
  `notify_push` is not an option because participants are anonymous and not
  signed in.
- Ballot stuffing: without an account, the voter cookie remains the only
  barrier — a documented limit, not fixable without a login.
