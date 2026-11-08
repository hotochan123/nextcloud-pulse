<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Developing Pulse

Architecture, structure, data model, building, tests and releases. Like the
rest of Pulse, this was written by AI coding agents (see
[How this app is built](../README.md#how-this-app-is-built-ai-disclosure)).

- [Architecture](#architecture)
- [Structure](#structure)
- [References in code comments](#references-in-code-comments)
- [Building](#building)
- [Languages](#languages)
- [Data model](#data-model)
- [Tests](#tests)
- [Release](#release)

## Architecture

- **Public access** without login through `#[PublicPage]` + `#[NoCSRFRequired]` –
  the same mechanism as share links. The room code is the share token:
  `/apps/pulse/s/{code}`. Because that switches Nextcloud's CSRF check off, the
  participant API checks for itself that a vote comes from its own page (see
  [Security notes](ADMIN.md#security-notes)).
- **Core decision on vote counting:** one database row per `(poll_id, voter_token)`
  (`UNIQUE` index), counted with `COUNT`/aggregates – no shared counter. A
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
                  util/routes.js (every URL and request time limit of the Vue frontend;
                  js/pulse-embed.js builds its own),
                  styles.js (token layer without Vue – for the embed shell)
l10n/             Translations (de.json is maintained, de.js is generated from it)
build/            l10n-build.js (json -> js), l10n-check.js (coverage)
tests/            PHPUnit (database-free) + run.sh
office-addin/     PowerPoint add-in (guide, Traefik example; the instance generates
                  the manifest itself)
dev/design-shots/ Screenshot harness for design reviews and store images (headless Firefox)
dev/sim/          HTTP simulation of complete runs against a running instance
dev/it/           The simulation on throwaway instances with all four databases
dev/e2e/          Browser tests (Playwright, Chromium) of desk, phone and projector on a throwaway instance
dev/unit/         Browser-free JavaScript unit tests (plain Node)
docs/             This documentation
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
[`pace-2026-09/UI.md`](pace-2026-09/UI.md), the implementation record of the
self-paced UI, which is included.

The UI design explorations (mockups, design briefs) were also produced with AI
tools. They are not part of this repository.

## Building

Build outside the web root, then deploy as described in
[`ADMIN.md`](ADMIN.md#installing-from-the-repository):

```
npm ci             # exactly the dependency versions of package-lock.json
npm run build      # produces js/pulse-main.js, js/pulse-public.js and js/pulse-styles.js
```

Use Node.js 22.18.0, the version the release bundles are built with: with it,
`npm ci` plus `npm run build` reproduces the `js/` of a release archive byte for
byte. The bundles are not committed (only the hand-written `js/pulse-embed.js`
is).

## Languages

The source language is **English** – the code contains the English text, and
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
value – the same rule in PHP and JS. Numbers are formatted in the language of
the interface (`fmtNum()` in the frontend, `NumberFormatter` in the CSV export);
a hard-wired decimal comma would be a different number in an English interface.

## Data model

```
Poll  = { id, type, question, options, answerKey, explanation, maxWords, timeLimit, image, status }

# options holds something different per type (JSON in one column):
choice/multi/rank/truefalse -> [{id,label}, …]
scale                       -> {mode, min, max, aspects|axisX/axisY, …}   -> getScaleConfig()
match                       -> {items:[{id,label}], targets:[{id,label}]} -> getMatchConfig()

# answerKey is the quiz solution (never sent to participants before the reveal):
multi {correct:[id]} · rank {order:[id]} · match {map:{itemId:targetId}}
number {target,tolerance} · text {accepted:[],rejected:[]}
# plus, any type, "mod": the filter before the projector (hidden, held back,
# AI check); Poll::getAnswerKeyArray() never returns it

# explanation (quiz only, plain text, NULL = none) follows the same gate as
# answerKey: owner views always, participants and projector only once revealed.

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
node dev/unit/routes.test.mjs                  # Vue frontend URLs and their call sites, checked against appinfo/routes.php
node dev/unit/composer-limits.test.mjs         # composer maxlength/min/max, checked against what DeckService keeps
node dev/unit/wordcloud.test.mjs               # projector word-cloud layout (long words fit), phone word length vs. server
node dev/unit/moderation.test.mjs              # filter before the projector: buttons per answer state on the desk
node dev/unit/ai-draft.test.mjs                # AI quiz draft: drafted questions become the same request a hand-made one sends
node dev/unit/stress-sweep.test.mjs            # stress catalog of the browser tests (every case of STRESS.md) and the projector size sweep
node dev/unit/question-fit.test.mjs            # projector: a long question shrinks in 8 % steps to 14 px, takes at most 40 % of its frame
node dev/unit/stage-labels.test.mjs            # projector labels: distinguishing ends of one-line options, radar names list when they collide
node dev/unit/hist-axis.test.mjs              # narrow scale histogram: thinned axis numbers, counts that fit their columns, tapped-column label
dev/sim/run.sh        # HTTP simulation of complete runs against the running instance (dev/sim/README.md)
DB=all dev/it/run.sh  # the same simulation on throwaway instances with all four databases (dev/it/README.md)
dev/sim/load-check.sh # load run (60 phones, self-paced race) on a throwaway Nextcloud; exit 1 on 5xx or p95 /state >= 300 ms
dev/e2e/run.sh        # browser tests of desk, phone and projector on laptop, tablet and phone, light and dark, the stress
                      # catalog in the projector size sweep from 1280×720 up (dev/e2e/README.md, dev/e2e/STRESS.md)
```

Before every merge the private development repository runs: the build, the
translation check, `info.xml` against the App Store schema, a test release
archive, every `dev/unit` test, a syntax check of every PHP file and
development script, PHPUnit on the PHP of the Nextcloud 34 and 35 images and
on PHP 8.2 and 8.3 (see `tests/README.md`), `DB=all dev/it/run.sh`,
`dev/sim/load-check.sh` and `dev/e2e/run.sh`. There is no GitHub Actions
workflow.

Screenshots of every view (projector, phone, moderator – per question type, open
and revealed, light and dark) are taken by `dev/design-shots/run.sh`, by hand
against a running Nextcloud; see `dev/design-shots/README.md`. The run also
measures every projector stage and reports when content runs past the clipped
kiosk – on a still image you only notice that if you look for it.

Handoffs, implementation status and images of the July 2026 design rounds are
not part of the public repository; see
[References in code comments](#references-in-code-comments).

The unit suite stays database-free (grading, tallying, text normalisation,
parameter pass-through). Paths that really touch the database are exercised by
the HTTP simulation and by throwaway scripts against a running instance, which
are not part of the repository – see `tests/README.md`.

## Release

Releases are built, signed and uploaded to the Nextcloud App Store with the
developer's release tooling, outside this repository: it builds the bundles
from the release tag, packs the runtime folders listed under
[Installing from the repository](ADMIN.md#installing-from-the-repository) into
`pulse-<version>.tar.gz` with a single folder `pulse/`, signs it with the
app's certificate and publishes the same archive as a GitHub release. The
release notes are the version's section in [`CHANGELOG.md`](../CHANGELOG.md).
