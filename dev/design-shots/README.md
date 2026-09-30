# Design screenshots

Captures every Pulse view automatically: projector, phone, join page, embed
shell and the moderator UI — per question type, open and revealed, light and
dark. Meant for design passes: instead of clicking through by hand and taking
pictures, a run leaves ~55 PNGs side by side.

```sh
dev/design-shots/run.sh          # capture, delete the probe rooms afterwards
dev/design-shots/run.sh keep     # keep the rooms (codes in probe.json)
dev/design-shots/run.sh clean    # remove the probe user + password file
dev/design-shots/run.sh store    # the six images for the store page
```

`store` is the exception: not test cases but promotional images. Its own rooms
(`probe.php store`, tidied-up content and a leaderboard without duplicate
names), the public hostname in the join link, double pixel density, output to
`out-store/`. Why each of these points is needed is explained in
`docs/APPSTORE.md` §3.

Result: `dev/design-shots/out/NN-name.png` plus `index.md` with one line per
capture (size, page, state).

## How it works

| Part | Role |
|------|------|
| `probe.php` | runs in the Nextcloud container: creates two rooms (poll + quiz) with one question per type each, attaches an image, fills in votes through the **real** demo path and switches states (`open`/`locked`/`ended`) |
| `shoot.mjs` | drives Firefox through geckodriver (WebDriver over HTTP, no npm dependency), sets window size and theme, waits for an anchor element and takes the shot |
| `run.sh` | wraps both: throwaway user, building rooms, capturing, cleaning up |

The trick: **the server sets the state, not the browser.** So there is no need
for a click script for "question 5 revealed with 24 votes" — `probe.php` sets
it up, and the browser only loads the page.

## Requirements

- `firefox` on the host (headless) and `tools/geckodriver`:

  ```sh
  curl -sSL https://github.com/mozilla/geckodriver/releases/download/v0.35.0/geckodriver-v0.35.0-linux64.tar.gz | tar -xz -C tools
  ```
- Docker access to the Nextcloud container (`occ`, `probe.php`).
- Node ≥ 18 (uses `fetch`).

Settings via environment variables: `PULSE_HOST`, `CONTAINER`,
`APP_IN_CONTAINER`, `PULSE_SHOTS_UID`, `PULSE_SHOTS_LANG` (default `en-US, en`),
`PULSE_SHOTS_PORT`, `OUT` and `PULSE_SHOTS_ONLY=<track>` to redo a single
track: `public`, `edge`, `phone`, `types`, `dark`, `embed`, `overview`,
`match`, `moderator`, `store` as well as `pace` (all four self-paced tracks)
or individually `pace-mod`, `pace-run`, `pace-phone`, `pace-screen`.

## Self-paced quiz

`run.sh` creates the rooms for this with `probe.php pace-create` and writes
them to `pace.json` next to `probe.json` — only for the full run and for
`pace*` and `embed`, because `race 300` alone takes a while. The rooms: `draft`
(not opened, two joins), `race` (race, 14 people), `homework` (deadline in
three days, reveal at the end), `e2e`, `timed`, `match8`, `late` (phone
walkthroughs), `big` (24 questions, 300 people — nobody can join there any
more), `mid` and `wide` (16 and 20 questions), `practice` (practice run,
opened) and `click` (only for click tracks).

`PULSE_SHOTS_ONLY=pace` runs `pace-mod` → `pace-run` → `pace-phone` →
`pace-screen` in this order, because some tracks change rooms; `pace-screen`
releases `race` at the end. Among other things it measures: exactly one filled
button per view, the footer of the run view above the fold, no solution in the
phone DOM before the release (`.pulse-results`, `.tg-accepted`, `.srow-mark`),
no question text on the projector and 0 px horizontal overflow, in the open
race the rows add up to the number of people who joined (finding `SUMME`), both
CSVs in the browser 200 + `text/csv` (counter-check without request token:
412) and never two `/progress` requests at once. "Stop without releasing" and
"Close quiz" with a deadline are exactly one `/pace` call each (deadline stays
0 or unchanged, not released); "Reopen …" then preselects — also for the
legacy case, stopped with a deadline 120 s after closing — "When I close it",
without a localStorage marker. Since the go-live (step 4.6, see
`docs/pace-2026-09/UI.md`) the tracks run without `?pace=1` — the "Self-paced"
switch is in every quiz deck.

Probe commands for test cases (through the real services; `window` and `leave`
deliberately write raw):

| Command | Effect |
|---|---|
| `pace <code> live\|self` | switch the flow |
| `open <code> [closesIn] [timed 0\|1] [feedback each\|end]` | open; `closesIn` in seconds from now, 0 = no deadline |
| `close <code>` / `release <code>` | close / release |
| `lock <code> 0\|1` | lock joining |
| `window <code> closesAt=… closedAt=… releasedAt=… openedAt=…` | set timestamps raw, `now±s` allowed (`closesAt=now-1` = deadline passed immediately) |
| `race <code> <N> [seed]` | N people join and play deterministically to different points, with real scores |
| `remove <code> <name>` | remove one person |
| `leave <code> <name>` | close a person's open question without starting the next one |
| `online <code> finished\|started\|all` | set the people's presence (15 s): they show up in `/progress` as "online" |
| `present <code> <N>` | N people present without names (15 s) — "{count} here" before opening |
| `reset <code>` | like "Reset quiz", also in the open state |
| `pace-create <uid>` | create the rooms above, JSON on stdout |

By hand, e.g.:

```sh
docker exec -u www-data nextcloud-nextcloud-1 php /var/www/html/apps/pulse/dev/design-shots/probe.php open ABC123 259200 0 end
```

## Long words

The `types` and `phone` passes end with a word of 40 characters, the most
the server keeps and the phone's word field takes. `probe.php add-words`
appends its questions to the types room only then, so every earlier capture
and every other pass keeps its rooms and decks. `types` photographs the
projector cloud with the word alone, inside the 16-word fixture cloud (one
mention) and as its most frequent word (eight mentions), and measures that
every word stays inside the cloud area. `phone` types 41 characters (the
field keeps 40), sends, and after the reveal measures the own answer and the
compact cloud: the word breaks inside its box, nothing scrolls sideways. A
finding (`FEHLT`, `ABGESCHNITTEN`, `NICHT 40`, `LÄUFT ÜBER`,
`WAAGERECHT`) lands in the list at the end of `index.md`.

After them the `phone` pass moves the first item of the poll's ordering
question by keyboard (focus plus click, as Enter does): three times down to the
bottom, once up. After every step the focus must be on that item's arrow — at
the bottom on its "↑", since "↓" is disabled there. The line reads
`↓ → ↓ → ↑ → ↑`; a lost focus shows as `VERLOREN` and lands in the list too.

## Brute-force trap

If a room is deleted or reset while a `/s/…` or `/screen/…` page — or the
projector preview in the run view — is still polling, 404s on `pulseRoomCode`
pile up, and at some point Nextcloud answers the server address with 429 on
all Pulse routes. Therefore:

- In every track, load `about:blank` first before `reset` or `destroy`;
  `close`, `release`, `window` and `remove` do not need this. `run.sh` only
  cleans up once `node` has exited.
- After every run, look at `occ security:bruteforce:attempts <server IP>`; if
  the counter rises, find the cause and then run `occ security:bruteforce:reset
  <server IP>`.
- **Deliberate exception:** the `embed` track raises the counter by exactly 1 —
  the "unknown code" capture types `ZZZZZZ`, and exactly that request counts.
  So after `embed`, check for +1 and reset; any further increase is a real
  finding.
- The projector keeps its state for 2 s: after a probe command at least
  `settle: 2500`.
- Never in parallel with `dev/sim/run.sh` (or a second harness run): both use
  `pulse-shots`, and `run.sh` removes **every** room of that user before and
  after with `probe destroy pulse-shots` — including the sim's, whose next
  phone request then goes nowhere and counts.

## Baselines

`out-pre-pace-ui/` (before stage 4) and `out-post-pace-ui/` (after the go-live)
keep the `moderator`, `phone` and `public` tracks for comparison; like all
`out-*` folders they are not in the repository. What gets compared are the
measurement lines of `index.md` with neutralised room codes — pixel
comparisons show differences on every run, because code, QR code and seeded
votes change.

**Which instance?** `PULSE_HOST` beats everything. Otherwise `.host` in this
folder is read — one line, e.g. `https://nextcloud.intern.example`; the store
track reads `.store-host` with the public name instead. Neither file is in the
repository: an internal hostname has no business in a public repository. If
both are missing, `run.sh` (and `shoot.mjs`) abort before the first step — a
hard-coded fallback address would have sent the throwaway user's login to
someone else's instance.

## Two quirks of the environment

- **Nextcloud 34 requires Firefox ≥ 145**, the server runs 128 ESR. Without a
  countermeasure the login ends up on `/unsupported`. `shoot.mjs` therefore
  sets the same flag that the "Continue with this unsupported browser" button
  sets. This only affects the harness.
- **Welcome dialog:** otherwise `firstrunwizard` covers the first moderator
  screenshot. `run.sh` sets the user setting `show` to a high version number —
  the value has recently become a version, not a switch.

## Limits

Still images without hover, focus and animation states, no feel for operating
it on a real device. What stands out (line breaks, contrast, use of space, cut
off content) stands out here; whether something *feels good* is still decided
by a human with a real projector.
