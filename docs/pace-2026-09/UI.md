# Self-paced quiz — UI (stage 4)

> Like the rest of Pulse, this handover was written by AI coding agents — the
> app is vibe-coded (see
> [How this app was built](../../README.md#how-this-app-was-built-ai-disclosure)
> in the README). It is the working record of how stage 4 was built and
> verified, kept for the next agent session and for any human reviewer.

Handover for the UI of the self-paced quiz: what has been built, where it
lives, how to check it and what remains open. The engine behind it (stages
2+3: window, `/pace`, `/progress`, `/next`, finality, leaks) is described in the
commit `feat(quiz): self-paced quiz engine (API only)` (commit in the private
history) and in the CHANGELOG.

**Status:** 2026-09-28 · steps 4.0–4.6 done, **live**
(`PACE_UI = true`, protocol 3). The product questions F1–F5 are built as
described below under "Decisions".

**Engine follow-up round (2026-09-28):** three items after the go-live — see
the section "Engine follow-up round" before "Known and open".

| Step | Content | Status |
|---|---|---|
| 4.0 | Starting point: baseline `out-pre-pace-ui`, sim, brute-force counter | done |
| 4.1 | Foundations: `util/pace.js`, `util/csv.js`, icons, tokens, additive props on the building blocks, two German translation fixes, probe commands | done |
| 4.2 | Self-paced deck, switch, open dialog, status in the deck, room list | done |
| 4.3a | Run view: header, table, joining, main action, stop without releasing | done |
| 4.3b | Run view: free-text grading, removing, menu, projector preview, summary | done |
| 4.4a | Phone: cards, joining, start, next, end cards | done |
| 4.4b | Phone: answer zone, verdict, time running out, results, focus; load run | done |
| 4.5 | Projector: lobby, race, closed, final standings | done |
| Wrap-up | eleven review findings fixed and verified in the browser | done |
| 4.6 | Go-live, protocol 2 → 3, docs | done |

## What it is

A quiz room runs either **moderated** (one question for everyone, the host
moves on) or **self-paced**: each person works through the deck on their own
phone, and their clock for each question only starts when they fetch the
question. Instead of a cursor, the host controls a **window**:
draft → open → closed → released.

- **Race** (no deadline): defaults to a timer and a verdict after each
  question; closing releases immediately.
- **Homework** (deadline 1 minute to 30 days): defaults to no timer, results
  only with the release; the deadline closes the quiz by itself.
- **Practice run**: always an immediate verdict, never a leaderboard.

## Where it lives

New logic lives in new files; `Moderator.vue`, `Participant.vue` and
`Screen.vue` only carry branch points, data fields and small template blocks.
Every new branch hangs off `isPaced` (moderator: `room.mode === 'quiz' &&
room.pace === 'self'`; phone/projector: `data.room.pace === 'self'`).

| Area | File |
|---|---|
| Pure rules without a browser: window state, deadlines, presets, phone cards, `canNext`, polling intervals, race rows, feature flag `PACE_UI` | `src/util/pace.js` (test: `dev/unit/pace.test.mjs`) |
| CSV with the request token in the query (otherwise 412) | `src/util/csv.js` |
| Deadlines, durations, "x min ago", state chip | `src/util/format.js` (`fmtDeadline`, `fmtDuration`, `fmtAgo`, `paceStateChip`) |
| Switch, locks, deck header, counts for confirmations, errors/409 | `src/Moderator.vue` (`deckMenu`, `paceLock`, `deckPrimary`, `fetchCounts`, `lossText`, `failWrite`, `refreshRoom`) |
| Open dialog and "Change the end … / Reopen …" | `src/components/PaceOpenDialog.vue` (`mode="open"` / `"extend"`) |
| "N here · N joined" in the deck, follows the deadline and a second tab | `src/components/PaceDeckStatus.vue` |
| Run view (phase `pace`): table, grading, menu, main action, status line | `src/components/PaceRun.vue` |
| `/progress` loop, single-flight, with sequence number and timeout | `src/mixins/progress-poll.js` |
| Projector preview in the run view | `src/components/ScreenPreview.vue` |
| Phone: state, cards, `nextStep`, correction mode, focus, announcements | `src/mixins/pace-phone.js` + branches in `src/Participant.vue` |
| Single-flight polling for phone and projector in self-paced mode | `src/mixins/polling.js` (`pollSingleFlight`, off when moderated) |
| Projector: race, join block, top of the leaderboard, counters, final standings | `src/components/StageRace.vue` + branches in `src/Screen.vue`, colours `.pace-race-row` in `src/styles/pulse-ds.css` |
| Additive props (byte-identical without them) | `PulseSegmented` `disabled`, `PulseMenu` `hint`, `PulseConfirm`/`pulseConfirm` `altLabel`, `TextGrading` `keyless`/`busy` |
| Icons `hourglass`, `calendar`, `flag`, `users`, `download` | `src/components/ui/PulseIcon.vue` |
| Contrast tokens `--pulse-viz-ink`, `--pulse-state-done-ink` | `src/styles/pulse-tokens.css` (all three theme blocks) |
| Strings (source language English) | `l10n/de.json` |
| Protocol number (one-time reload of old tabs) | `src/util/protocol.js` + `Application::PROTOCOL` |
| Screenshot harness, load run | `dev/design-shots/{probe.php,shoot.mjs,run.sh}`, `dev/sim/load.mjs` |

## Guardrails that still apply

- **Moderated mode behaves exactly as before.** Deliberate exceptions, all of
  them bug fixes (in the CHANGELOG under "Fixed"): "Export CSV" in the summary
  (previously 412), the German translation of "Change" is now "Ändern"
  instead of "Wandel" and "Practice run" is "Probelauf" instead of
  "Übungslauf", five error toasts show the server message, `join()` discards
  outdated `/state` responses, a one-time reload caused by the protocol bump.
- **No solution before the release.** The phone gets only `verdict`, never
  `correctOption`/`answerKey`; the projector never gets question text, options
  or distributions, and when closed no leaderboard either; the run view has no
  "Accepted:" block, and points are hidden with feedback at the end; the
  summary warns as long as the quiz is open.
- **Errors:** branching only on the HTTP status; what is shown is
  `e.response.data.message` with a generic text as fallback; message texts are
  never compared. Every 409 on a self-paced write call reloads the room.
- **Polling:** every new loop is single-flight, discards outdated responses by
  sequence number and has an axios `timeout`.
- **Buttons:** exactly one filled button per view; `PulseMenu` for overflow,
  switches with a checkmark, destructive (red) items last.
- **Vue 2:** new reactive fields go in `data()` (also in the mixin), maps only
  via `$set`. `.vue`/`.js` are indented with tabs — structural changes via a
  Node script, not with an editor that loses leading tabs.
- CSS classes are called `pace-*`, never "self": `--pulse-self*` is the magenta
  "you" colour.

## Decisions (F1–F5, as built)

| # | Question | Built |
|---|---|---|
| F1 | Name of the setting | "Self-paced" (German: "Eigenes Tempo") |
| F2 | Where to switch | Checkmark item in every quiz's deck menu + header chip "Quiz · self-paced" |
| F3 | When "Next question" appears | only with the verdict (a few seconds, costs no points) |
| F4 | Top of the leaderboard on the projector during an open race | yes, after the first 2 minutes and only with points > 0; never with feedback at the end |
| F5 | "Du" (informal "you") in new German strings | capitalised |

## Checking

Standard sequence after every change (from the app directory; `npx`/`npm run`
do not work under this path, hence directly via `node`):

```sh
node build/l10n-build.js && node build/l10n-check.js
TZ=Europe/Berlin node dev/unit/pace.test.mjs
npm_package_name=pulse npm_package_version=0.18.0 node node_modules/webpack/bin/webpack.js --node-env production --config webpack.config.js
occ config:app:set theming cachebuster --value=<n+1>   # otherwise browsers see the old bundle
tests/run.sh
dev/sim/run.sh
dev/design-shots/run.sh           # or PULSE_SHOTS_ONLY=<track>
occ security:bruteforce:attempts <server IP>
```

- **Sim:** 1443 ok / 0 (log scan empty). **PHPUnit:** 804 tests. **Unit:** 31 cases.
  (As of 2026-09-28; after the re-check of 2026-09-29 below: sim 1458 ok / 0,
  PHPUnit 827 tests.)
- **Screenshot harness:** tracks `moderator`, `phone`, `public`, `types`,
  `match`, `embed`, `overview` and `pace` (= `pace-mod`, `pace-run`,
  `pace-phone`, `pace-screen`). What the pace tracks measure, the probe
  commands and the brute-force trap are described in
  `dev/design-shots/README.md`.
- **Baseline:** `dev/design-shots/out-post-pace-ui/{moderator,phone,public}`
  (not in the repository). Compared with the baseline from before stage 4
  (`out-pre-pace-ui`), the measurement lines of these three tracks are
  identical; the only intended difference — the "Self-paced" item in a quiz's
  deck menu — shows up in the `overview` track (menu open: three switches
  instead of two).
- **Brute-force counter:** unchanged after every run, except for `embed` (+1 on
  purpose, unknown code `ZZZZZZ`; reset it afterwards).

By hand, in three windows (moderator, phone, projector at
`/apps/pulse/screen/<code>`):

1. Create a quiz, deck menu → "Self-paced". With nobody joined it switches
   without asking; otherwise it asks, showing the number.
2. "Open quiz …" → "Open quiz". Projector: "Join and start at your own pace."
3. Phone: name → "Start quiz" → answer → verdict → "Next question" → … →
   "I’m done" → "You’re through!". Projector: race "1 of 1 finished", never a
   question text.
4. Run view: "Close quiz" → "Close and release". Projector: podium; phone:
   "Quiz finished" with the place; the main action is now "Participants as CSV".
5. Moderated counter-check: another quiz, "Start presenting", reveal, "Show
   final standings" — phone and projector follow as before.

## Go-live (4.6)

- `PACE_UI = true` in `src/util/pace.js`: the switch is in every quiz deck,
  `?pace=1` has no meaning any more (the screenshot harness no longer needs it
  either).
- `Application::PROTOCOL` and `src/util/protocol.js` from 2 to 3. A phone or
  projector tab with a bundle from before would have shown a self-paced room
  forever as "Waiting for the next question …" or "Starting shortly"; with the
  bump every open tab reloads once. A marker in `sessionStorage`
  (`pulse-protocol-reload` = `3`) prevents a loop.
- **Order for every protocol bump:** first build the bundle and raise the
  cachebuster, **then** the PHP constant. The other way round, a tab that sees
  the new server would reload the *old* bundle, set the marker — and then stay
  on the old bundle, because the marker forbids any further reload. In the
  meantime (new bundle, old server) a freshly opened tab reloads at most once
  with marker `2` and is fine afterwards.

**Measured at go-live (2026-09-28):**

- Full standard sequence: translations 749 source strings, 0 missing; unit
  30 / 0; build; cachebuster 170 → 171; PHPUnit 597 tests
  (ProtocolConstantTest 3 = 3); sim 1384 / 0, before and after the screenshot
  harness.
- Protocol bump: one phone tab (in the middle of question 1) and one projector
  tab (race), both opened before the step with the bundle `…-170`. After build
  and cachebuster (server still at 2): no reload. After the PHP constant,
  `/state` reported protocol 3 after 12 s; both tabs reloaded **exactly once**
  13–14 s after the change, now with `…-171`, marker `3`, the phone back on its
  question. After that, 2.5 minutes without any further reload.
- Screenshot harness: `moderator`, `phone`, `public`, `types`, `match`,
  `overview`, `embed`, `pace` — all without findings. Measurement lines of
  `moderator`/`phone`/`public` identical to `out-pre-pace-ui`; `overview` shows
  three switches instead of two in the quiz deck menu (the new item), otherwise
  identical; `pace` compared with the run before the go-live differs only in
  clock times and run times, and now runs without `?pace=1`. New baseline
  `out-post-pace-ui`.
- Brute-force counter 0 before and after every run (`embed` +1 as intended,
  reset).
- Joint walkthrough in three sessions, 20 / 0: moderated quiz (start, reveal,
  final standings on phone and projector); "Self-paced" switch without
  `?pace=1` in the quiz deck, not in the poll deck; switching without asking
  (nobody joined), opening with the defaults, phone through two questions,
  projector race without question text, "Close and release", podium and
  place 1.

## Engine follow-up round (2026-09-28)

After the go-live, without a protocol bump and without a version bump
(0.18.0), in this order:

- **Input hardened.** Every request parameter goes through
  `lib/Service/Input.php` (`str`/`number`/`int`/`flag`), the voter cookie
  through `PublicVoteController::voterToken()`: lists, objects, 1e999/INF,
  numbers beyond ±1e18 and broken UTF-8 count as not sent — the usual 400 or
  the default, never a PHP warning, never a 500. NUL bytes are dropped; only
  the free-text answer used for grading keeps them (`rawStr`), because the
  stored vote keeps them and the normal form would otherwise never match its
  group. Where "missing" has a meaning, unusable input is a 400: deadline
  (`closesAt`), cursor (`pollId`), optional switches in `/pace` (`timed`).
  Only the form the server hands out counts as a voter cookie (32 characters
  A–Z, a–z, 0–9); anything else counts as no cookie (`/state`, `/join`,
  `/vote` hand out a new one). Checked by: `InputTest`, `ControllerInputTest`
  (every action with lists in every parameter, source-code guard),
  `HostileInputTest`; in the sim the "hostile input" pass, followed by the log
  scan (user agent `pulse-sim`, no entry at level 2 or above). Measured:
  PHPUnit 597 → 773 tests (1748 → 3837 assertions), sim 1384 → 1417 ok / 0
  with an empty log scan, unit 30 / 0, translations 749 / 0 missing, screenshot
  harness `moderator`/`phone`/`public` identical to `out-post-pace-ui` except
  for the font size of the revealed number guess on the projector (42 instead
  of 46 px — depends on the random demo votes and already varied between both
  values before), brute-force counter 0 before and after every run.
- **Stopping without releasing is one call:** `POST /pace {action: close,
  release: false}` — closed, not released, the deadline stays 0, "Reopen …"
  preselects "When I close it". `release` only has an effect when closing an
  open window (closed = no-op, even with `true`); if it is missing, the old
  rule applies (no deadline = release) — old tabs and API clients keep working
  unchanged, without a protocol bump. Unusable values (a list, "maybe", 2) =
  400 "Invalid request.", `''` = false. The run view now always sends `release`
  explicitly: "Close and release" `true`, "Close quiz" with a deadline and
  "Stop without releasing" `false`; the fresh room fetch before "Close quiz"
  and the localStorage marker are gone. Rollout order: PHP first (≥ 65 s for
  opcache, sim pass "stop without releasing" green), then the bundle — a new
  bundle against the old server would release when stopping. Measured (PHP
  before the bundle; the bundle follows below under "Bundle"): PHPUnit 773 →
  801 tests (3837 → 3942 assertions), sim 1417 → 1440 ok / 0 with an empty log
  scan (23 checks for "stop without releasing"), unit 30 / 0, translations
  749 / 0 missing, brute-force counter 0 before and after the run.
- **Race bars without overlap.** `beamerState` counts every person who started
  exactly once: finished (`isFinished`) or on question k (the open row,
  otherwise the last row reached — the same number as on the phone and in the
  run view); not started + per question + finished = joined. The legend now
  reads "100% = everyone who joined" (German: "100 % = alle Beigetretenen").
  The sim checks projector = `/progress` (race open and released, homework
  after the deadline, practice run), the screenshot harness track
  `pace-screen` checks the sum (finding `SUMME`).

Measured (2026-09-28, PHP for all three items): PHPUnit 804 tests / 3952
assertions, unit 31 / 0, sim 1443 ok / 0 (log scan without entries),
translations 749 / 0 missing / 0 orphaned, brute-force counter 0 before and
after the run.

**Bundle (2026-09-28, one step for all three items, after the PHP):** build,
cachebuster 171 → 172. Screenshot harness `pace`: 222 captures without
findings — "Stop without releasing" exactly one `/pace` call, deadline 0;
"Close quiz" with a deadline one call, deadline unchanged; no marker; `SUMME`
consistent in all 24 open race rows; legend not cut off. `moderator` identical
to `out-post-pace-ui`. Sim 1443 / 0 with an empty log scan; fuzzing across
every route and every parameter (lists/objects in JSON, form, query and
cookie; throwaway script, not in the repository) 14,271 requests, 0× 5xx.

**Re-check (2026-09-29), only PHP, sim and docs — no new bundle:**

- The free-text answer used for grading was read via `Input::str` and so lost
  its NUL; the vote keeps it (`normalizeValue` only checks `is_string`). An
  answer "Pa\0ris" then stayed "Being checked" forever, and marking it "wrong"
  rejected every genuine "Paris" instead. Now `Input::rawStr` (like `str`, just
  with NUL; the answer only ever goes through `json_encode`). Checked by:
  `ControllerInputTest`, `InputTest`, sim check "free text with NUL stays
  gradable".
- CSV of the moderated room: every quiz with a free-text question returned 500
  (since 0.17.0, when free text arrived) — the tally is called `answers` there,
  not `results`. Now one row per answer group; the number guess states its
  number (the column was empty); free text and words from the audience go
  through `CsvFormat::cell` as in self-paced mode (previously
  `PaceStateService::cell`); quotes per RFC 4180 (`fputcsv` with an empty
  escape, which PHP 8.4 explicitly requires anyway). Checked by: `QuizCsvTest`,
  the sim's CSV checks in every moderated quiz.
- Sim: projector = `/progress` now also after the deadline (homework) and in
  the practice run — before, only the race (open and released) was checked
  against `/progress`.
- Poll vote: two simultaneous first votes from the same token (double tap,
  retry after a network error) both found no vote, and the second insert
  failed on the UNIQUE index — 500. An old bug (already in 0.18.0), found by
  the fuzzer (`/vote` with four simultaneous requests and one cookie). Now
  caught as in the quiz and in self-paced mode: the second one becomes an
  update. Checked by: `PollVoteRaceTest`; race with 60 × 8 simultaneous first
  votes (throwaway script): 480× 200, 224 caught violations in the PostgreSQL
  log, 60 votes for 60 tokens.
- Sim, fuzzer and screenshot harness share `pulse-shots`; `probe destroy`
  deletes every room of that user. Never in parallel (see the READMEs).

Measured: PHPUnit 804 → 827 tests (3952 → 3989 assertions), unit 31 / 0,
translations 749 / 0 missing / 0 orphaned, sim 1443 → 1458 ok / 0 with an empty
log scan (before and after the vote fix). Fuzzing before the vote fix 14,271
requests, 2× 500 (the race, otherwise without a log entry); afterwards 14,271,
0× 5xx, log scan empty.
Screenshot harness `phone` and `public` against the bundle of 172 identical to
`out-post-pace-ui` (only different room codes; `public` again 42 instead of
46 px for the revealed number guess, see above). Brute-force counter 0 before
and after every run, afterwards no room of `pulse-shots` left.

## Known and open

**Limits you need to know**

- **Switching devices** means a new identity: whoever continues on another
  device starts with a new name at question 1, and the old name counts as
  taken. The host can remove the old person, then the name is free. A re-entry
  code is stage 6.
- **"Finished" before the last points:** a person is finished once they have
  answered the last question — even while that answer is still in its
  correction window. The run view then shows "Finished" and the status line
  "Everyone still here is through …", and the points for the last answer only
  arrive with its verdict (measured: finished after 0.3 s, points after 4.2 s
  — at the same moment as the verdict on the phone). Nothing is lost: closing
  makes the answer final immediately. The run view lags behind the phone by at
  most one poll (2–3 s).
- **Open free-text answers** count 0 points until they are graded; grading
  after the release changes final standings that people have already seen
  (the run view warns about this there).
- **Load:** load run with 100 phones, 90 s (2026-09-27): `/state` p95 40 ms,
  `/next` and `/vote` p95 ≈ 42 ms, `/progress` p95 304 ms (rebuilds everything
  on every poll; one moderator every 2–3 s). Within bounds; an aggregate cache
  for `/progress` only once measurements show it is getting tight.
- At most 300 people per room who have started and 600 who have joined, 120
  new names per address and room in ten minutes (since the security review of
  September 2026; before, 300 joined and 120 join requests) — a school class
  behind a NAT stays below that, a lecture hall behind one address not
  necessarily; for that, the instance raises `max_players_per_room` and
  `max_new_players_per_address` in the app config.

**Next stages**

- Stage 5: start from the waiting state without a tap, demo race (`?demo=1`),
  store images with a race.
- Stage 6: re-entry code (switching devices), shuffling per person, joint
  walkthrough after closing.

**Engine follow-ups** (PHP + test, a small round of their own)

- In a closed, not released window with "feedback after each question",
  `beamerState` still sends the top of the leaderboard (top 8). The projector
  does not show it (Screen.vue), but it can be fetched with the room code;
  since `close {release: false}` this is the normal case of stopping. Possibly
  send it only while the window is open (PHP, SelfStateGateTest, screenshot
  harness finding `RANGLISTE IM GESCHLOSSENEN`).
- A cheap fingerprint for self-paced `/state`, and not writing presence on
  every poll — only if load calls for it.
- `/progress` with an aggregate cache per version — only under measurable load.
- Without an API, so not planned anywhere: "Not you?" on shared tablets, the
  join lock in the public state, the length of the correction window in the
  payload.

**Clean-up**

- Switch the moderator's presentation preview to `ScreenPreview`;
  `Moderator.ago()` to `fmtAgo`.
- `prefers-reduced-motion` globally for `.srow-fill` (today only the race
  rows).
- The moderated correction mode stays open after a rejected correction (400).
  In self-paced mode this is fixed; in moderated mode the host's next question
  resolves it — a round of its own.
- The German wording of "This question is closed." (currently "Diese
  Abstimmung ist beendet.", a shared server key) and the lowercase "du/dich" in
  existing German strings (e.g. "Nur für dich").
- `PACE_UI` is now a constant `true`. It can be removed together with its check
  in `deckMenu` once it is certain that it will not be turned back.
- The `moderator` track does not open a quiz deck menu; only `overview` (and
  `pace-mod`) cover the new item. Anyone who wants a sharper baseline adds a
  capture of the open quiz menu to `moderator`.
- `stopDeadline`/`STOP_LEAD` in `src/util/pace.js` now only recognise legacy
  stops: rooms stopped by the earlier two-step path (deadline in 120 s, then
  `close`) sit in the database with `closesAt = closedAt + 120` and without a
  release. A protocol bump only reloads tabs, these rooms stay — without the
  helpers, "Reopen …" would preselect "At a set time" with tomorrow there, and
  one click would turn the race into homework. They may only be removed once
  none of these rooms exists any more: `SELECT code FROM oc_pulse_rooms WHERE
  pace = 'self' AND released_at = 0 AND closed_at > 0 AND closes_at -
  closed_at BETWEEN 100 AND 125` empty — otherwise first set `closes_at = 0`
  for these rooms or keep the helpers. The two-step path only existed on
  `main` between `7d5f030` and `ef29c10` (commits in the private history), in
  no release; on the live instance there was no self-paced room on 2026-09-29.
- Deliberately accepted: a legacy stop whose second call failed is no longer
  recognised by anything. The 120-s deadline then closed the room by itself
  (`closedAt = closesAt`, gap 0); the only giveaway was the localStorage marker
  `pulse-pace-stop:<code>` of the old tab, and the new bundle does not read it.
  "Reopen …" (in the two minutes before that also "Change the end …")
  preselects "At a set time" with tomorrow and "Was: …" there — visible in the
  dialog before anyone clicks. That requires a bundle from before
  `close {release}` (only on `main`, never released) and a failed second call;
  reading the marker again for this is not worth it.
