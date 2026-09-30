# Flow simulation

Plays through a poll, four quiz variants and the self-paced quiz against the
running instance — without a browser, directly through the HTTP endpoints, the
same way the moderator UI, phones and projector use them:

```sh
dev/sim/run.sh          # play through, delete the rooms afterwards
KEEP=1 dev/sim/run.sh   # keep the rooms (to inspect them in the browser)
# Ctrl-C also removes the rooms created up to that point.
```

Ends with `N ok / M fehlgeschlagen` ("failed") and exit code 1 as soon as
anything is red. A run takes just under five minutes (answer delays, correction
windows, expired 5-s timers; the self-paced homework waits out its 90-s
deadline, which runs in parallel with the race; plus just under 30 s of race
conditions).

The console output of `sim.mjs` (pass headings, check labels, the summary
line) is still in German; the pass names below are English descriptions of
those passes.

## What is played through

| Pass | Content |
|------|---------|
| Poll | all five types (multiple choice, word cloud, scale single/spectrum/compass, ranking, matching), six phones, changing a vote, revealing, reopening, paging back, summary, CSV |
| Quiz "per question" | all seven types, four players with a fixed answer plan, correction window, free-text grading, reveal per question, final standings, CSV |
| Quiz "at the end" | as above, but hidden until `/end` |
| Practice run | revealed per question, no leaderboard, end = lobby |
| Practice run + at the end | `/end` reveals once (without a leaderboard), then lobby |
| (every quiz) | afterwards a second run without resetting: the old end no longer applies |
| Timer & deck | time running out, reopening, editing clears votes, duplicating, resetting |
| "At the end" switched on afterwards | switched on after revealing: the question is hidden again, no new answers; open phones/projectors learn about it (the version changes), switched back it is revealed again |
| Question skipped | moved on without revealing: its points are missing from every public leaderboard until it is revealed; at the end everything counts, alike on phone, projector, `/summary` and for the moderator |
| Word keys | ❤️/❤ (variation selector) and `guter  Kaffee` (double whitespace) are one word each, displayed in the first spelling |
| Running question edited | poll without votes: open phones fetch the new version, a vote with the new IDs goes through |
| Deleted and foreign question | a question deleted in the meantime and one from another room: editing, uploading an image and reordering answer 404 with the message (unlike the bare 404 of a stale route cache) and write nothing — the deck keeps its order, the other room its question; grading answers 400 (the run view reads a 404 on `/grade` as a deleted room) |
| Practice run switch | switching clears test participants and test votes; phone and projector in the lobby learn about it |
| Live quiz: players | a new name in the middle of a question changes no version (projector 204); renaming after the first answer is refused; "Lock joining" turns new names away, known players get back in; the moderator leaderboard carries `playerId`, the public one never; removing a player takes them and their answer off the leaderboard, the projector learns about it at once (new version), the phone has no name, a locked room keeps the cookie out, unlocked it joins again with the same name at zero points; a poll room answers 409 |
| Live quiz: joining at once | 30 phones join at the same moment, all 200 and each name once on the leaderboard (on SQLite the room lock used to fail with "database is locked"); four phones joining as the same name at the same moment: exactly one gets it |
| Self-paced: race | no deadline, with timer, verdict per question; cursor controls and (from opening on) the deck are locked (409), personal clock from `/next`, preview lock, correction only within the window of the first answer, time running out, free text "Being checked" and grading, name fixed after starting, "Lock joining", removing a person, closing = release, both CSV views, resetting and switching back to moderated |
| Self-paced: homework | deadline (1 min–30 days, from the server clock), no timer, verdict only with the release; the deadline passes without a moderator, extending, closing without release, releasing; a flat 1000 points, CSV without times |
| Self-paced: practice run | "verdict at the end" becomes "per question", never a leaderboard (phone, projector, `/summary`; moderator `[]`), 130 refused joins of the same person are never 429 (failed and repeated joins cost nothing, only a far-off flood guard counts requests) |
| Self-paced: new names per address | 120 new names from one address per room (the default of `max_new_players_per_address`), the 121st is 429 without a cookie; the spelling of your own name, a taken name and a known player do not count |
| Self-paced: stop without releasing | `close {release:false}` in one call: closed, deadline 0, not released, vote immediately final, phone/projector without final standings; invalid `release` is 400 without effect; `close` on a closed window is a no-op (also with `release`); the old two-step path (deadline in 120 s, then `close`) works as before; `release: "false"` as a string; `release: true` releases with a deadline |
| Self-paced: race conditions | truly parallel, with the phone's delay swept across the measured runtime difference moderator − phone: removing against `/vote` and `/next`, grading against an answer and against its correction |
| Hostile input | lists/objects instead of text or a number in moderator and phone parameters (JSON and form), 1e100/1e999, NUL, broken UTF-8, foreign voter cookies (list, overlong, broken): never 500, but 400 with the usual message, the default or a new cookie; a free-text answer with NUL stays gradable |
| Browser checks | a participant POST without `X-Requested-With` or with `Sec-Fetch-Site: cross-site`/`same-site` is 403 without a cookie, `same-origin` goes through; a cookie name sent twice counts as none (new cookie); on https with an empty webroot a legacy `pulse_vt` moves to `__Host-pulse_vt` (the old one expires) and a planted `pulse_vt` next to `__Host-pulse_vt` is ignored; a phone's first `/state` issues the cookie but only its second one counts it as present |

## What is checked

- The phone summary and the moderator count the same (per question); the
  projector counts the same answers. The poll CSV is only generated (200,
  BOM); the moderated quiz CSV names every question, for free text one row per
  answer group as in the moderator, for a number guess the number.
- Before revealing, neither the solution (`correctOption`, `answerKey`) nor the
  tally nor right/wrong appears in the public state; ranking and matching come
  shuffled (keyed hash, the same for everyone, independent of the solution);
  whether a question is revealed is stated by `poll.revealed`. The public
  `version` is an opaque hash, not a CRC of the solution.
- The phone summary contains only questions that have already been shown; its
  leaderboard does not count a hidden question (running or skipped), only the
  final standings count everything. An edited question counts as never shown
  again.
- Points: the sum of the per-question feedback per person = leaderboard points;
  with the same number of correct answers the faster person ranks first.
- An answer with a stale `pollId` (the moderator has moved on) is rejected,
  not credited to the new question.
- Nicknames are unique within a room (ignoring case, invisible characters,
  variation selectors and double whitespace); your own may be rewritten. Word
  cloud: one word per person, even with zero-width spaces or NBSP.

Additionally for self-paced:

- Before the release the phone shows neither solution nor distribution nor
  leaderboard; `/summary` shows only questions already left while the window
  is open, all questions reached after closing, both without solutions, and
  never a question to a foreign cookie. The projector never shows question
  texts or options, only the race in numbers (`race`) and, with "verdict per
  question", the top of the leaderboard. The race counts every person who
  started exactly once (on their question or finished) and matches
  `/progress` — open, released and after the deadline has passed.
- Only final votes count (correction window over, corrected or no longer
  correctable): phone (`myScore`), projector, `/progress`, final standings and
  CSV report the same points. A vote right before closing counts immediately.
- The version jumps without a job as soon as something time-driven flips (vote
  final, time up, deadline), and after grading and release; the heartbeat of
  polling phones leaves the `/progress` version unchanged (204).
- "Verdict at the end": until the release only "saved", `/progress` hides the
  points (except with `?scores=1`) — even after the deadline has passed.
- `close` without `release` keeps the old rule (no deadline = release) — for
  old tabs and API clients.
- Joining: when locked only known phones get in, after starting there is no
  other name, a removed person loses name and progress and their name becomes
  free; the 121st join attempt per IP and room gets 429. One IP never reaches
  the cap of 300 players (limit 120) — that cap is only covered by the unit
  test.
- Race conditions (whether a run hits the millisecond window is chance; what is
  checked is what a hit would violate): whoever is removed in the middle of
  `/vote` or `/next` leaves neither a vote nor a row behind; an answer that
  arrives in the middle of grading its normal form does not stay on "Being
  checked"; a correction is not lost against a simultaneous grading; no 500.
  The sim does not check deleting a room in the middle of `/join`/`/next`:
  every 404 on it would count as a brute-force attempt by the sim IP (unit
  tests `RoomGoneTest`, `RoomPaceLifecycleTest`).

## How it works

- `run.sh` creates the throwaway user `pulse-shots` if needed (the same one as
  in `dev/design-shots`, password in `dev/design-shots/.shots-pass`),
  determines the container IP and starts `sim.mjs`.
- The moderator talks to the API with Basic auth + `OCS-APIRequest: true` (no
  CSRF token needed); every phone keeps its own voter cookie — `__Host-pulse_vt`
  when the instance counts as https with an empty webroot (`overwriteprotocol`
  applies to the direct container requests too), otherwise `pulse_vt` — and,
  like the public bundle (`@nextcloud/axios`), sends
  `X-Requested-With: XMLHttpRequest`: participant POSTs without it are 403.
- The requests go directly to the container, not through the proxy. The IP is
  not a `trusted_domain`, so `sim.mjs` sends `Host: localhost` — and uses
  `node:http` for that, because `fetch` overwrites the Host header.
- Self-paced with real waiting times: a vote is only final once
  `(now − created) > fw` holds in whole seconds, so in practice up to fw+1 s
  after the answer — `FINAL(fw)` waits fw + 1.5 s. The projector state and the
  public leaderboard sit in the server cache for 2 s, so projector checks after
  a change first wait 2.1 s. Deadlines come from `serverNow` of the last
  response, never from this machine's clock.
- Messages and CSV cells ("yes"/"no") are compared in English: `pulse-shots`
  has the language `en`, and the phones send no `Accept-Language`.
- Every request carries the user agent `pulse-sim`. After the run, a log scan
  shows whether any of them wrote a warning or an error to the Nextcloud log —
  expected: no line:

  ```sh
  LOG=/var/log/nextcloud/nextcloud.log
  OFF=$(docker exec nextcloud-nextcloud-1 stat -c %s $LOG)
  dev/sim/run.sh
  docker exec nextcloud-nextcloud-1 tail -c +$((OFF + 1)) $LOG | grep -a '"userAgent":"pulse-sim"' | grep -a -E '"level":[234]'
  ```

**Never in parallel** with `dev/design-shots/run.sh` or a second sim run:
they all use `pulse-shots`, and the screenshot harness removes every room of
that user with `probe destroy pulse-shots` — including the ones the sim is
using right now. The next phone request to a deleted code counts as a
brute-force attempt by the sim IP.

Settings: `CONTAINER`, `PULSE_SIM_URL`, `PULSE_SIM_HOST`, `PULSE_SIM_USER`
(then also `PULSE_SIM_PASS` — `.shots-pass` belongs to `pulse-shots`). If
`occ` does not answer, `run.sh` aborts instead of overwriting the shared
password.

`PULSE_SIM_HOST` (default `localhost`) is also the way out of the route cache:
Nextcloud stores the routes per Host header in APCu for one hour
(`lib/private/Route/CachingRouter.php`, key Host + base URL). Until then, a
newly added route answers with 404 for a host that is already cached — the
HTML error page, not the controller's JSON (401/403/400/405 means: route
loaded). A port gives a fresh key, and the `trusted_domains` check ignores it:

```sh
PULSE_SIM_HOST=localhost:34071 dev/sim/run.sh
```

The self-paced passes check this up front: if `/pace` answers with 404, the
sim reports exactly one failure with this hint and skips only those passes.

Do this only after changing `appinfo/routes.php` and waiting a minute (opcache
checks files only every `opcache.revalidate_freq` seconds, 60 here), otherwise
the fresh key stores the old routes for an hour. No container restart needed.

## Load run

`dev/sim/load.mjs` is a separate load run for the self-paced quiz: 60 phones
join a freshly opened race room, answer and tap "Next question" at the pace the
phone UI uses, while a moderator polls `/progress` and a projector polls
`/state?spectate=1`. It prints p50/p95 per endpoint, status code shares and
requests per second as Markdown. Like the sim it talks directly to the
container and uses the same throwaway user:

```sh
PULSE_SIM_URL=http://<container-ip> PULSE_SIM_PASS="$(cat dev/design-shots/.shots-pass)" \
  node dev/sim/load.mjs
```

Settings: `LOAD_PHONES` (default and at most 60 — one address may add 60 new
players per room in 10 min, more would only measure the 429), `LOAD_SECS`
(load phase, default 90), `LOAD_TAIL_SECS` (final standings polling, default
30). On a live instance run it only at a quiet time, and only once.

## Limit

The simulation reproduces the moderator buttons (`moderatorFinish` mirrors the
main button on the last question from `Moderator.vue`); it does not click them.
If the flow changes there, `sim.mjs` has to follow — and whether the UI offers
the right button, only the browser shows.
