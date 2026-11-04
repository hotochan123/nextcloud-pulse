# Browser tests

Playwright (Chromium) drives the three faces of Pulse the way people use them,
on a throwaway Nextcloud:

```sh
dev/e2e/run.sh                          # ≈ 4 min: install ≈ 1–2 min, then the six cases
PULSE_E2E_ONLY=poll,quiz dev/e2e/run.sh # some cases only
PULSE_E2E_REPEAT=3 dev/e2e/run.sh       # each case three times (flake check)
PULSE_E2E_SHOTS=1 dev/e2e/run.sh        # a screenshot of every checked state
KEEP=1 dev/e2e/run.sh                   # keep the Nextcloud containers afterwards
```

| Case | What happens |
|---|---|
| `compose` | On a laptop the moderator starts a poll and a quiz and adds every question type through the composer (poll: multiple choice, word cloud, scale single/spectrum/compass, ranking, matching; quiz: multiple choice, true/false, multiple answers, number guess, free text, ranking, matching). The deck names each type and, in the quiz, its answer hint; the server stores the same. |
| `poll` | The composed poll runs live. One phone joins through the code page, one through the link (light and dark); both answer every question by tap or gesture, one changes its vote. The server's tally, the projector's answer count and each phone's own answer after the reveal carry the expected values. |
| `quiz` | The composed quiz runs live with two named phones; one answers everything right, the other everything wrong except a misspelt free text that the moderator approves on the desk. Verdicts after each reveal, final standings in order on desk, projector and phones, the CSV export with the expected counts. |
| `paced` | The moderator switches a quiz to self-paced and opens it (a race: feedback after each question). A phone plays through, right on every other question, and gets the matching verdict each time, then its points; the desk shows the progress, and the participants CSV keeps the name `=1+1` from running as a formula. |
| `layout` | Desk (room list, deck with the composer open, live view) and phone page (code entry, poll question, poll result, quiz verdict) on a laptop 1366×768, a tablet 820×1180 and a phone 390×844 (touch), light and dark: nothing scrolls sideways or runs past the edge, and the background follows the colour scheme. |
| `projector` | Four projector windows (1920×1080 and 1366×768, light and dark) follow a poll and a quiz with every question type, filled with demo votes: lobby, each question open and revealed, final standings, a self-paced race. The page does not scroll, the stage (which clips with `overflow: hidden`) holds no more than it shows, and every element on it lies inside its rectangle; only text that ends in an ellipsis or a line clamp may be cut. Each state gets up to 3 s to settle, as the projector shrinks its stage in steps. |

## How it runs

`run.sh` starts `pulse-e2e-<pid>` (Nextcloud `IMAGE`, default
`nextcloud:34-apache`, SQLite) and `pulse-e2e-<pid>-redis` on Docker's
default bridge without a published port, mounts this checkout read-only as
`custom_apps/pulse`, adds the user `alice` with a random password (never
written to a file) and switches the first-run wizard off. Without built
bundles in `js/` it runs `npm run build` first.

The browser runs in `pulse-e2e-<pid>-browser`
(`mcr.microsoft.com/playwright:v1.49.1-jammy`) inside the network namespace of
the Nextcloud container, so it opens `http://localhost`: a trusted domain and a
secure context. It sees `dev/e2e` read-only and writes only the output
directory. `playwright-core` (the version of the image, from
`package-lock.json`) lives in the Docker volume `pulse-e2e-node-1.49.1`, filled
by `npm ci` on the first run.

All containers are removed when the run ends, also when it is red or
interrupted (`KEEP=1` keeps the Nextcloud ones). Each case creates its own
rooms and deletes them.

Against a running dev stack instead (no stack of its own; the browser container
then uses the host network and opens only that address):

```sh
PULSE_E2E_URL=http://127.0.0.1:18641 PULSE_E2E_USER=alice PULSE_E2E_PASS=… dev/e2e/run.sh
```

There is no default address and no default container: the production
instance is never addressed.

## Output

One line per case, `ok <case> (<s> s)` or `FAIL <case>: <first message>`, then
`E2E <ok>/<failed>`. Exit 0 when all are green, 1 when a case failed, 2 when
the stack or the browser did not come up. A case that takes longer than
`PULSE_E2E_TIMEOUT` seconds (default 180) fails and the run goes on. The
output directory (named on stderr) holds `report.json` and a screenshot of
every page of a red case.

`PULSE_E2E_SELFTEST=fail` makes `compose` fail, `PULSE_E2E_SELFTEST=overflow`
puts an element wider than the screen into every page, and
`PULSE_E2E_SELFTEST=stage` puts one taller than the stage onto the projector's
stage, where the stage itself clips it: each must turn the run red (`stage`:
the `projector` case), which shows that the checks can fail.

## Files

```
run.sh          stack, browser container, clean-up
e2e.mjs         runs the cases, prints the result lines
lib/harness.mjs devices, login, moderator API, waiting, layout and colour-scheme checks
lib/flows.mjs   what a person does: compose, primary action, join, answer every type
cases/*.mjs     one file per case
```
