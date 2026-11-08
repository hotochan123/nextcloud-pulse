<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Pulse for administrators

Installation, settings, limits, security notes and known limits. What the app
does for presenters is in [`FEATURES.md`](FEATURES.md), how it is built in
[`DEVELOPMENT.md`](DEVELOPMENT.md).

- [Installation from the App Store](#installation-from-the-app-store)
- [Installing from the repository](#installing-from-the-repository)
- [Embedding in PowerPoint](#embedding-in-powerpoint)
- [Limits](#limits)
- [Security notes](#security-notes)
- [Known limits and possible extensions](#known-limits-and-possible-extensions)

## Installation from the App Store

Pulse has been in the [Nextcloud App Store](https://apps.nextcloud.com/apps/pulse)
since 3 October 2026. As an admin, open Apps in Nextcloud, search for "Pulse"
and install it, or run `occ app:install pulse`. A new release can take some
hours to show up there: Nextcloud reads a copy of the store's app list that
the store renews only from time to time. Every release is signed with the
app's certificate from Nextcloud and attached to the
[GitHub releases](https://github.com/hotochan123/nextcloud-pulse/releases),
which is where the store sends every instance to download it.

Pulse runs on Nextcloud 34 and 35 and has been tested on 34.0.1 and 35.0.1
with fresh installations on SQLite, MariaDB 11.8, PostgreSQL 17 and MySQL 8.4,
and on 34.0.4 with PostgreSQL, including the server update from 34.0.4 to
35.0.1. The signed archives of 0.19.0 and 0.19.1 were installed on 34.0.4 and
35.0.1 through the same download and signature checks an installation from the
store runs, and both have been installed from the store itself (0.19.0 on
34.0.4, 0.19.1 on 35.0.1).

## Installing from the repository

To install from the repository instead (for example a state that has not
been released yet), do not clone it into the web root. A checkout carries the
development tree – tests, the simulation and screenshot harness, sources and,
after a build, `node_modules/` with demo pages of its packages – and the web
server hands out static files below `apps/` as they are, so all of it would be
public on your Nextcloud's own origin. Clone it somewhere else and copy only the
runtime files into Nextcloud. The bundles in `js/` are not part of the
repository, so this needs Node.js for the build (see
[Building](DEVELOPMENT.md#building)):

```
git clone https://github.com/hotochan123/nextcloud-pulse.git ~/pulse-src
cd ~/pulse-src
npm ci && npm run build                  # builds js/
mkdir <nextcloud>/apps/pulse
cp -a appinfo css img js l10n lib templates office-addin \
      README.md LICENSE CHANGELOG.md <nextcloud>/apps/pulse/
rm -f <nextcloud>/apps/pulse/js/*.map
occ app:enable pulse
```

The folder has to be named after the app ID (`pulse`) and needs only the
runtime folders – `appinfo`, `css`, `img`, `js`, `l10n`, `lib`,
`templates`, `office-addin` – plus README, licence and changelog; the same
set a release archive ships. It is not signed, which Nextcloud accepts for an
app installed by hand. `rsync` with the same list works just as well, and the
web server user needs read access to the copy. To update, replace
`<nextcloud>/apps/pulse` with the new folder; when the version in
`appinfo/info.xml` went up, run `occ upgrade` afterwards (until then Nextcloud
does not serve the app).

Running Pulse straight from a git checkout inside `apps/` still works (after
`npm ci` and `npm run build`), and the repository ships an `.htaccess` as a
safety net for that case: on Apache (with `mod_alias` and `AllowOverride` for
the app folders, as in Nextcloud's own setup) it answers 404 for
`node_modules/`, `dev/`, `build/`, `tests/`, `src/`, `tools/`, `docs/`,
`.git/`, local `_*.php` scripts and the package files. nginx ignores
`.htaccess`; there, deploy the runtime files as above.

## Embedding in PowerPoint

A custom content add-in (sideloaded, not from the Office Store). Each instance
serves the Office manifest itself – `/apps/pulse/addin/manifest.xml`, and
"PowerPoint add-in" in the deck menu. It carries the address it was downloaded
from; an Office manifest has no variables, so a shipped file would need a hand
edit on every installation. Guide:
[`office-addin/README.md`](../office-addin/README.md).

**Note:** Nextcloud sends `X-Frame-Options: SAMEORIGIN` from `.htaccess` and
`lib/base.php` – an app cannot override that. For `/apps/pulse/screen/` and
`/apps/pulse/embed` the header has to be removed in the web server or reverse
proxy; snippets for Apache, nginx and Traefik are in `office-addin/README.md`.
Whether it is still there is reported by the setup check "Pulse: embedding in
PowerPoint" under Administration → Overview, separately for each address.

## Limits

**Per account and quiz room.** No user quota counts what Pulse stores – question
images live in Nextcloud's app data, rooms and questions in the database – and
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
| `max_image_bytes_per_owner` | 209715200 (200 MB) | question images of all rooms of one account, counted as stored – Pulse re-encodes every upload as PNG or JPEG, which can be several times the size of the upload |
| `max_players_per_room` | 600 | players who have joined one quiz: every player of a moderated quiz, started or not in a self-paced one (the self-paced cap of 300 who have started is fixed); the flood guard on joining grows with it |
| `max_new_players_per_address` | 120 | new names per address and self-paced quiz within ten minutes – raise it when a larger class or a lecture hall joins from behind one address |
| `rate_limit_create` | 30 | new rooms per account and minute |
| `rate_limit_duplicate` | 10 | room copies per account and minute |
| `rate_limit_add_poll` | 120 | new questions per account and minute |
| `rate_limit_upload_image` | 30 | image uploads per account and minute |
| `rate_limit_demo` | 30 | batches of demo votes per account and minute |
| `rate_limit_save_file` | 30 | results CSVs and summaries saved into Files per account and minute |
| `rate_limit_ai_draft` | 10 | AI quiz drafts started per account and minute |
| `rate_limit_ai_summary` | 10 | AI summaries started per account and minute |
| `rate_limit_ai_grade` | 10 | AI grading suggestions started per account and minute |

How good the AI results are depends on the text-generation model set up in
Nextcloud: Pulse is tested with Mistral Small 4 (via Infomaniak); smaller or
local models may give weaker drafts, filters, themes and summaries.

The AI settings of the admin section "Pulse" are app config values too:
`ai_enabled` (`1`, `0` = no AI in Pulse) and `ai_daily_limit` (default 30, 0–1000:
AI tasks a moderator starts per account and calendar day in the server's time
zone, counted approximately like the caps above; the automatic check of answers
for the projector is not counted). A reached daily limit answers 429 with its own
message.

A cap answers with a message (400), a rate limit with "Too many attempts. Please
wait a moment." (429). Copying a room checks its questions and images against
the caps before it writes anything. Requests that arrive at the same moment
all check against what was stored before any of them wrote, so the image
budget and the caps on rooms and questions count once more after writing, and
a request that finds the account over takes its own room, question, copy or
image back – parallel requests may then all be refused, but they no longer get
past a cap together. The per-minute rate limits come from Nextcloud's rate
limiter, which is approximate under parallel requests: a burst can get a few
more through. Answer options are cut to 200 characters, and a free-text
question keeps at most 50 accepted answers.

**Per room and address.** Fixed in the code where no app config key is named;
"an address" is what Nextcloud counts as one – an IPv4 address or an IPv6 /56
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
  modes – only a guard against floods.
- Moderated quiz: at most 600 players (`max_players_per_room`).
- Self-paced quiz: at most 300 players who have started and 600 who have
  joined (`max_players_per_room`); at that ceiling, while the quiz is open,
  players who joined more than ten minutes ago and never started make room. At
  most 120 new names per address and room within ten minutes
  (`max_new_players_per_address`).
- Word cloud: a vote with more than twice the allowed number of words (at least
  20) is refused; long words and texts are cut before they are processed.

## Security notes

- **AI in general.** An admin can switch all AI of Pulse off (admin section
  "Pulse"; the route is admin-only, CSRF-protected) and cap the AI tasks per
  account and day. Before an account's first AI use the server refuses to start
  a draft, a grouping, a summary or the switch "AI help" (409) until the account
  confirmed the fixed notice of what goes to the provider.
- **AI quiz draft.** Only what the moderator gives goes to the AI provider the
  admin set up in Nextcloud: the topic, or the plain text of a file they picked
  from their own Files (at most 20 MB, cut to 15,000 characters); never the file
  itself, never anything of the participants. The text is taken from Office and
  OpenDocument files without an XML parser, so no entity is resolved; each
  unpacked part is capped at 10 MB, and reading a file stops after 64 KB of text
  or 50 MB unpacked in all. Pulse stores no draft: a moderator can only
  read their own task of that room, and the drafted questions reach the deck
  only through the normal "add question" route with all of its checks, after
  they clicked "Add".
- **AI summary.** Only question texts and the answers the audience may see
  (free-text and word-cloud answers with their count; at most 100 per question,
  300 characters each, 20,000 characters in all) go to the provider, as the
  account of the moderator who starts it — no nicknames, voter tokens, room
  name or scores; hidden and held back answers stay out. Every number in the
  text is counted by Pulse. Answers, nicknames and the AI's points are escaped
  in the Markdown, so the saved file shows them as text, never as a link,
  image or HTML. Pulse stores nothing until the moderator saves the text they
  have read and edited; a moderator can only read their own task of that room.
- **Anonymous participants.** A random voter token in a cookie discourages
  double voting without identifying anyone; whoever clears their cookies can
  vote again. In a quiz, participants only give a nickname.
- **Voter cookie.** On https with Nextcloud at the root of its domain the cookie
  is called `__Host-pulse_vt` (Secure, `Path=/`, no `Domain`, `HttpOnly`,
  `SameSite=Lax`). Browsers accept such a cookie only from this very origin, so
  another service on a sibling subdomain can neither plant a token it knows –
  and then read a participant's answers with it – nor overwrite it. Under http
  or a sub-path it stays `pulse_vt` without that protection. A `pulse_vt` from
  before the update is taken over for 60 days after the first request under the
  new name and ignored after that; the start is kept in the app config key
  `voter_cookie_host_since`, and setting it to 1
  (`occ config:app:set pulse voter_cookie_host_since --value=1 --type=integer`)
  ends the takeover at once. A request that sends a cookie name twice counts as
  having no cookie. A name that PHP only turns into `__Host-pulse_vt` –
  `_.Host-pulse_vt`, which any sibling subdomain may set – is ignored, as PHP
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
  get 429. Only misses ever count, and an existing room always opens – a typo
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
  and again under new identities – another browser, a private window, or a
  script. Voter tokens are random values the server does not sign, so a script
  can make up its own; the limit on new identities (see [Limits](#limits))
  only slows down scripts that send no cookie at all. Pulse cannot prevent
  this: the result of an anonymous poll is an impression, not a head count.
  What is bounded is the cost for everybody else: public views are capped (see
  [Architecture](DEVELOPMENT.md#architecture)), an unchanged state costs no
  vote reads, a request without the cookie writes no presence row, and made-up
  cookies add at most 600 presence rows per address and room in ten minutes.
  Every accepted vote still makes each open phone and the projector fetch the
  state once more, and the server builds it from all votes of the question – a
  few hundred milliseconds per viewer at tens of thousands of votes. The
  owner's only remedy is resetting the room, which deletes the real results as
  well.
- **Names on the podium.** Anyone with the code can join a quiz under any free
  name and reach the projector's podium. The host can lock joining ("Lock
  joining" in the menus) and remove players – in a moderated quiz from the
  "Participants" list in the "Only for you" panel and under the standings, in
  a self-paced one from the progress view. A removed player's answers are
  deleted; they can join again from zero points unless joining is locked. In a
  moderated quiz a name is frozen once its player has answered or a question
  has ended. Names are compared without case, invisible characters (every
  character Unicode reserves as "default ignorable", unassigned ones included)
  and lookalike letters (fullwidth forms, Greek or Cyrillic letters that look
  Latin; with PHP's `intl` extension also pairs such as "PauI" and "Paul"), so
  nobody can pass as a player already in the room – not even by re-spelling
  their own name: "paui" may become "PAUI", but not "PauI" next to a "Paul".
  That occasionally refuses a genuine name close to a taken one ("Ian" next to
  "lan"); adding a letter helps.
- **AI check of answers.** With "AI help" on, the text of each new free-text
  or word-cloud answer goes to the text-generation provider your admin set up
  (Nextcloud's TaskProcessing, as the account of the room's owner) – numbered,
  without the question, nicknames, voter tokens or anything else about the
  participant; phones say so while the switch is on. A hidden or held back
  answer is in no public view (projector, phones, the summary, the solution
  after the reveal); the participant's own phone keeps showing their own
  answer and is not told. The AI is a help, not a guarantee: it can miss
  something or flag something harmless, and the 10-second limit lets answers
  through unchecked when it is slow.
- **Group with AI.** Only in polls, started only by the room owner on the desk with "AI help"
  on (a quiz room refuses it, and themes stored there earlier reach neither the desk
  nor the audience): the most frequent 200 visible answer texts go to the same provider,
  with the question text, numbered, without nicknames or tokens; hidden, held back and
  still unchecked answers are left out. The projector and phones get the
  themes only after the owner released them; the example of a theme is always an answer someone typed and that
  is visible, never text written by the AI, and the AI's titles are plain
  text cut to 60 characters.
- **AI grading suggestion.** Started only by the room owner on the desk with
  "AI help" on, in a live quiz: the question, up to 20 expected answers and the
  200 most frequent open answer texts go to the same provider, numbered,
  without nicknames or tokens; hidden, held back and still unchecked answers
  and answers already graded are left out. The proposals stay on the desk
  (never in the public state, so they cannot give the solution away) and
  change no points until the owner taps "Accept all".
- **Quiz solutions** (`answerKey`) are not sent to phones or the projector
  before the reveal. A self-paced quiz can still be scouted: a second identity
  that answers anything moves on at once (only an unanswered timed question
  holds it until the time is up), so it sees the whole timed deck before the
  main identity's clock starts, and the main identity still earns full speed
  points. With feedback "After each question" – the race default – leaving a
  question makes its answer final at once and shows whether it was right, so a
  few throwaway identities find the right option of every choice question. For
  anything graded, open the quiz with feedback "At the end", lock joining once
  the class is in and check the names against your class list. Pulse cannot
  tie an identity to a person; where that matters, use a tool with a sign-in
  for every participant.
- **Framing.** `/apps/pulse/screen/{code}` (the projector view) and
  `/apps/pulse/embed` (the add-in shell) may be framed by any site –
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
  manifest asks PowerPoint only for the `Restricted` permission – the document
  settings in which the room code travels with the `.pptx` – so script inside
  the add-in cannot read or change the presentation. A manifest downloaded
  before that change asked for more; download it again.
- **Development files** do not belong in the web root; see
  [Installing from the repository](#installing-from-the-repository).
- **Logs.** Voter tokens, nicknames and answers are marked as sensitive
  parameters, so they do not end up in exception traces in `nextcloud.log`.
- **Retention.** Rooms, with their answers and nicknames, are deleted by the
  daily job as described under
  [Automatic clean-up](FEATURES.md#automatic-clean-up).
- As described in the
  [README](../README.md#how-this-app-is-built-ai-disclosure), the code has not
  had an independent human review or a security audit; the AI agents' own
  security review is summarised in the [changelog](../CHANGELOG.md). Reporting
  a problem: [`SECURITY.md`](../SECURITY.md).

## Known limits and possible extensions

- Projector size: the projector page is made for a window of 1280×720 and
  larger, in any aspect ratio (16:9, 16:10, 4:3, 21:9, a browser window with
  tab and address bar), up to 3840 wide; there every question fits its stage
  without text that is cut or covers other text. Below 1280×720 it still works
  as well as the space allows and shows a slim note at the bottom edge (with a
  ✕); the PowerPoint embed frame is such a case, but shows no note.
- Languages: English and German. Further languages would go through
  Transifex and need Nextcloud's involvement.
- Nextcloud 34 and 35 only (`min-version="34" max-version="35"`, 35 since
  2 October 2026, tested including the server update from 34 to 35). Copies from
  before that date carry `max-version="34"`, so Nextcloud 35's updater turns
  them off.
- Question type image hotspot (tap on an image instead of choosing an answer).
- Self-paced quiz: a rejoin code for switching devices, starting from the
  waiting state without a tap, a demo race and store images; plus small engine
  follow-ups (list in [`pace-2026-09/UI.md`](pace-2026-09/UI.md)).
- Real push instead of polling – would need a WebSocket service of its own;
  `notify_push` is not an option because participants are anonymous and not
  signed in.
- Ballot stuffing: without an account, the voter cookie only discourages voting
  twice – a documented limit, not fixable without a login. Its cost for other
  viewers is bounded, but every accepted vote still has each open page rebuild
  the state from all votes of the question; a shared cache of the capped public
  results per change would make that one computation for all viewers. Number
  guesses still send one row per distinct value to phones and the projector.
- Self-paced quizzes are open to scouting with a second identity, and with
  feedback after each question to finding the right answers that way (see
  [Security notes](#security-notes)). Holding answered timed questions until
  their time is up, withholding verdicts until the end, or a question order per
  person would each narrow it.
- More than 120 people behind one address (a lecture hall on one NAT) who join
  the same self-paced quiz within ten minutes: the rest get "Too many attempts.
  Please wait a moment." until the window slides on. Raise
  `max_new_players_per_address` for such rooms (see [Limits](#limits)).
- The add-in shell loads `office.js` even when it is opened outside Office;
  loading it only inside an Office host is still to do.
