# Browser tests

Playwright (Chromium) drives the three faces of Pulse the way people use them,
on a throwaway Nextcloud:

```sh
dev/e2e/run.sh                          # ≈ 12 min: install ≈ 1–2 min, then the cases
PULSE_E2E_ONLY=poll,quiz dev/e2e/run.sh # some cases only
PULSE_E2E_REPEAT=3 dev/e2e/run.sh       # each case three times (flake check)
PULSE_E2E_SHOTS=1 dev/e2e/run.sh        # a screenshot of every checked state
KEEP=1 dev/e2e/run.sh                   # keep the Nextcloud containers afterwards
PULSE_E2E_SEED=42 dev/e2e/run.sh        # replay the size sample of a run (its first line says "seed <n>")
PULSE_E2E_SWEEP=all dev/e2e/run.sh      # every projector size for every catalog case (long, not in the check)
PULSE_E2E_ONLY=stressPoll PULSE_E2E_CATALOG=S1.3,S2.2 PULSE_E2E_COLLECT=1 dev/e2e/run.sh
                                        # some catalog cases, every finding listed as "known" instead of failing
PULSE_E2E_TRACE=1 dev/e2e/run.sh        # the time of every stress step and check on stderr
```

| Case | What happens |
|---|---|
| `compose` | On a laptop the moderator starts a poll and a quiz and adds every question type through the composer (poll: multiple choice, word cloud, scale single/spectrum/compass, ranking, matching; quiz: multiple choice, true/false, multiple answers, number guess, free text, ranking, matching). The deck names each type and, in the quiz, its answer hint; the server stores the same. |
| `poll` | The composed poll runs live. One phone joins through the code page, one through the link (light and dark); both answer every question by tap or gesture, one changes its vote. The server's tally, the projector's answer count and each phone's own answer after the reveal carry the expected values. |
| `quiz` | The composed quiz runs live with two named phones; one answers everything right, the other everything wrong except a misspelt free text that the moderator approves on the desk. Verdicts after each reveal, final standings in order on desk, projector and phones, the CSV export with the expected counts. "Save in Files" twice: each time "Open in Files" links the new file, which holds the same CSV as the download (read back over WebDAV), and the second one gets a number in its name. |
| `paced` | The moderator switches a quiz to self-paced and opens it (a race: feedback after each question). A phone plays through, right on every other question, and gets the matching verdict each time, then its points; the desk shows the progress, and the participants CSV keeps the name `=1+1` from running as a formula. Its summary offers "Save in Files" too. |
| `longq` | A question of 1000 characters (STRESS.md S1.1) shows whole wherever a list shows it: the desk's question list (laptop, light), then the quiz self-paced: a phone (dark, touch) shows it whole while answering and in "See your answers", the progress view and the results overview list it whole, on a laptop and phone-sized. "Whole" = an element holds its whole text and nothing cuts it (no line clamp, no ellipsis, no overflow, inside every box that clips). |
| `histtap` | A scale 1–20 with 400 demo votes (STRESS.md S8.4d), revealed. On a phone at zoom 200 % (dark, German, touch) the counts above the columns are left out; a tap on a column shows a label with its value and votes (the column's screen-reader label), a tap on another column moves it, a tap on the question or on the same column closes it; the label lies inside the screen, the page does not scroll sideways, column heights and screen-reader labels do not change. On a laptop desk the counts fit and a click shows nothing; on the desk at zoom 200 % in a 320 px window a click shows the label and Escape closes it. |
| `pacedhide` | Hiding in a self-paced quiz: two API players answer a free-text question of an open race differently; on the results overview (tablet, dark) the desk taps "Hide" on one answer, the row says "Hidden" with a 44 px "Show"; after the release the other player's end view has neither the answer nor its accepted chip, the author still sees their own answer, the results CSV marks it "(hidden)"; "Show" brings it back. |
| `layout` | Desk (room list, deck with the composer open, live view) and phone page (code entry, poll question, poll result, quiz verdict) on a laptop 1366×768, a tablet 820×1180 and a phone 390×844 (touch), light and dark: nothing scrolls sideways or runs past the edge, and the background follows the colour scheme. |
| `filter` | The filter before the projector with the fake AI provider (`fakeai/`, flags "badword", answers "slowpoke" only after 15 s): the desk switches "AI help" on in its menu and the phone shows the AI notice; a checked word reaches the projector's cloud, a flagged one stays off it until the desk taps "Show"; "Hide" takes a word off the projector; a slow check lets its word through after about 10 s and the desk says "AI check not available right now". Needs the stack of `run.sh` (against `PULSE_E2E_URL` the fake provider is missing). |
| `themes` | "Group with AI" with the fake AI provider (`fakeai/`, one theme per first letter, an answer "failgroup" fails): in a poll the desk switches "AI help" on and the phone shows the AI notice; "Group with AI" shows the themes with counts and an example on the desk while the projector does not; "Show themes on projector" puts three theme cards on the projector and "Back to answers" takes them off; in a quiz with "AI help" on the desk lists the checked answers but has no "Group with AI", the route answers 400 and the projector shows no themes after the reveal; in a word cloud a failing grouping makes the desk say "AI grouping not available right now", and after hiding the failing word a grouping replaces the cloud on the projector without the hidden word; six themes with titles past the 60-character cut and long examples stay on the projector stage (the four windows of the case `projector`), and the desk with its theme list fits laptop and tablet, light and dark, with a 44 px "Back to answers". Needs the stack of `run.sh`. |
| `grading` | The AI grading suggestion with the fake AI provider (`fakeai/`, correct = starts with the first four letters of an expected answer, an answer "failgrade" fails): in a live quiz expecting "Berlin" the desk switches "AI help" on, phones answer "Berlln" and "Paris"; "Suggest grading with AI" shows "AI: correct" and "AI: wrong" while both stay open; one tap flips Paris, "Accept all" grades both, the cross afterwards makes Paris wrong; a failing task makes the desk say "AI grading suggestion not available right now"; four proposals with long answers fit the desk on laptop and tablet, light and dark, with 44 px targets. Needs the stack of `run.sh`. |
| `ai` | The AI quiz draft in the composer against a fake text-generation provider (`fakeai/`, see below): a poll has no AI button, a quiz has it; an answer without JSON gives a clear message and keeps the topic; a topic gives a preview of six questions, "Try again" a new draft; one unticked, "Add 5 questions" puts five into the deck with their solutions (checked on the server), and one opens in the composer with its solution marked; the preview on a tablet in dark mode stays on the screen; a text file uploaded over WebDAV gives a draft through the same route, a missing one a 404. |
| `summary` | The AI summary on the results overview against the fake provider (its key points end with "Seen: …", the answers it got), on a tablet in dark mode: a moderated quiz with four API players and one free-text answer hidden; no button while "AI help" is off; switched on, the text holds Pulse's numbers, the key points without the hidden answer and the top 3 of the leaderboard; "Try again" writes a new one; the hand-edited text is what "Save" puts into the folder "Pulse" (read back over WebDAV), a second save gets a number; in a self-paced quiz "AI help" sits in the deck menu and the button appears. |
| `admin` | The admin section "Pulse" with the fake provider: the test account (an admin in `run.sh`'s stack) switches "Allow AI in Pulse" off and sets the limit to 5, both stay after a reload, 5000 gets a message; a quiz's composer has no AI button and the live view no "AI help", switched on again both are back. A fresh account (added and removed over the provisioning API) sees the AI notice on a tablet in dark mode at its first draft, naming quiz draft, filter, summary, grouping and grading suggestion and that question and answer texts are sent: "Cancel" opens nothing, "Understood" opens the draft, the next draft and "AI help" do not ask again; with the limit at 0 the draft says the day's requests are used up. The settings are put back at the end. Needs the stack of `run.sh`. |
| `projector` | Four projector windows of the size sweep (1280×720, a 4:3, a 21:9 and a browser-window size drawn by the run's seed, light and dark) follow a poll and a quiz with every question type, filled with demo votes: lobby, each question open and revealed, final standings, a self-paced race. The page does not scroll, the stage (which clips with `overflow: hidden`) holds no more than it shows, and every element on it lies inside its rectangle; only text that ends in an ellipsis or a line clamp may be cut. No two texts on the projector cover each other, and no box cuts a line of text in part (the descenders of a question; a line hidden as a whole by a line clamp is fine). Then a long two-line question with five options and multiple answers on 1920×945 (a 1080p projector in a browser window) and 1920×1080, light and dark, and in the PowerPoint embed frame 1264×576: open, revealed without a single answer, open and revealed with demo votes, a question four times as long, which shows whole (it shrinks itself; also after a resize back and forth, to the same size), and a short one after it at its base size again. Last the note below the minimum size (STRESS.md S9.4) at 1024×600, light and dark (German): the band at the bottom edge with its text, the page ending above it with nothing covered in the lobby and on a question, its ✕ by mouse and by keyboard (44×44) hiding it until a reload, gone at 1280×720 and back below; no band with `?embed=1` nor in a frame. Each state gets up to 3 s to settle, as the projector shrinks its stage in steps. |
| `stressPoll`, `stressQuiz`, `stressBoard` | The stress catalog (`STRESS.md`, as data in `stress/catalog.mjs`) on all three faces: four rooms (a poll with the 80-character title, a quiz, a leaderboard of 60 players with the widest and non-Latin names, 90 free-text answers) built through the API, every text read back as written. Each catalog case goes through its states (open, answered by API players, demo votes and a phone of the plan, revealed or revealed without an answer, final standings) and is checked on the projector at 1280×720 and one more size of the sweep, on the desk and on the phone on one device each (laptop, tablet, phone, zoom 200 %), light and dark; the dark projector and phone pages run in German. The checks are those of `projector` plus, on desk and phone, the two text checks (no text over text, no line cut in part; a line scrolled out of its scroll box does not count), and on every face that shows the question, the question whole (as in `longq`). S1.1 and S1.5 also run on the projector in the smallest size of every kind of the sweep (1280×720, 1280×800, 1280×960, 1920×823, 1600×765). A case marked `open` or `uncutOpen` in the catalog prints its findings as `known <id>: …` and stays green. |

## How it runs

`run.sh` starts `pulse-e2e-<pid>` (Nextcloud `IMAGE`, default
`nextcloud:34-apache`, SQLite) and `pulse-e2e-<pid>-redis` on Docker's
default bridge without a published port, mounts this checkout read-only as
`custom_apps/pulse`, adds the user `alice` with a random password (never
written to a file, an admin for the case `admin`) and switches the first-run wizard off. For the cases `filter`,
`ai`, `summary`, `themes`, `admin` and `grading` it also mounts `dev/e2e/fakeai` as `custom_apps/pulse_fakeai`, enables
it (a test-only app whose provider answers every `core:text2text` task at once:
the AI check flags "badword" and answers "slowpoke" only after 15 s; a grouping gets one theme per first letter, "failgroup" fails, "longtitle" makes long titles; a grading suggestion calls an answer correct when it starts like an expected answer, "failgrade" fails; a quiz
draft gets fixed questions, material with `BROKEN` gets prose, `FAILME`
fails, a summary gets three key points per question) and starts `occ taskprocessing:worker` in the container. Without built
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

There the cases `ai` and `summary` need a text-generation provider; without one `ai` fails
with "a text-generation provider is set up" (leave it out with `PULSE_E2E_ONLY`).

There is no default address and no default container: the production
instance is never addressed.

## Output

First `seed <n>`, the seed of the run's sample of the size sweep
(`stress/sweep.mjs`: widths 1280 to 3840 in 16:9, 16:10, 4:3, 21:9 and a
browser window with tab and address bar, only sizes from 1280×720 up; each
catalog case at 1280×720 and one more size, every size class and every device
at least once per run). Then one line per case, `ok <case> (<s> s)` or
`FAIL <case>: <first message>`, after a case its `known <id>: …` lines, then
`E2E <ok>/<failed>`. Exit 0 when all are green, 1 when a case failed, 2 when
the stack or the browser did not come up. A case that takes longer than
`PULSE_E2E_TIMEOUT` seconds (default 180; the stress cases 420) fails and the run goes on. The
output directory (named on stderr) holds `report.json` and a screenshot of
every page of a red case.

`PULSE_E2E_SELFTEST=fail` makes `compose` fail, `PULSE_E2E_SELFTEST=overflow`
puts an element wider than the screen into every page, and
`PULSE_E2E_SELFTEST=stage` puts one taller than the stage onto the projector's
stage, where the stage itself clips it, and `PULSE_E2E_SELFTEST=overlap` lays a
copy of the first text on the projector's stage (or on the phone page) over it:
each must turn the run red (`stage` and `overlap`: the `projector` case;
`overlap` also the stress cases on the phone), which shows that the checks
can fail.

## Files

```
run.sh          stack, browser container, clean-up
e2e.mjs         runs the cases, prints the result lines
lib/harness.mjs devices, login, moderator API, waiting, layout and colour-scheme checks
lib/flows.mjs   what a person does: compose, primary action, join, answer every type
cases/*.mjs     one file per case (cases/stress.mjs: the three stress cases)
stress/         the stress catalog as data (catalog.mjs), the size sweep and its sample per seed (sweep.mjs), a PNG of any size (png.mjs)
STRESS.md       the stress catalog: what each case is and why
fakeai/         test-only app pulse_fakeai: a canned core:text2text provider for the cases filter, ai, summary, themes and grading (never install it elsewhere)
```
