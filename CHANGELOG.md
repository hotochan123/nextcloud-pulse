# Changelog

All notable changes to Pulse are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

Release notes on the Nextcloud App Store are taken from the topmost sections of
this file, so every published version needs an entry here.

## [Unreleased]

### Added
- Every instance hands out its own PowerPoint add-in manifest, at
  `/apps/pulse/addin/manifest.xml` and from the deck's overflow menu. An Office
  manifest cannot hold variables — `<AppDomain>` and `<SourceLocation>` have to
  be absolute URLs — so a shipped file needed a hand edit in two places on every
  installation, and forgetting it pointed the add-in at somebody else's
  Nextcloud with no error message anywhere.
- Setup check "Pulse: embedding in PowerPoint" under Administration → Overview.
  It fetches the embed page under every address the server can reach itself on
  and reports whether `X-Frame-Options` still blocks framing, listing blocked
  and working addresses separately — a proxy rule that covers the internal host
  but not the public one now shows up instead of quietly breaking the add-in on
  the road. The header cannot be dropped from inside the app on Apache, so the
  check points at the web server rather than pretending to fix it.
- Self-paced quiz. A quiz room can now run at its own pace instead of
  following the presenter: tick "Self-paced" in the deck's menu, and everybody
  works through the questions alone on their own phone, with a clock for each
  question that starts only when they open it. Switching clears the players
  and answers collected so far and asks first if there are any; the questions
  stay. Instead of presenting, the presenter opens the quiz with
  "Open quiz …" and closes it later. Without a deadline it is a race: by
  default with a timer and a verdict after every question, and closing it
  releases the results. With a deadline one minute to 30 days ahead it is
  homework: by default without a timer and with the verdict only after the
  release, and the deadline closes the quiz with nobody around. Both defaults
  can be changed when opening; a practice run always gives the verdict at once
  and never shows a leaderboard. Moderated quizzes work as before, apart from
  the fixes listed below.
  - While the quiz is open, the presenter follows everyone on a progress page:
    how far each person got, their answers, points and last activity, the
    join code with its QR code, a preview of the projector, and one main
    button that names the next step — close the quiz, check free-text answers,
    release the results. Free-text answers are marked right or wrong there
    without the list of accepted answers on screen, and a slip can be undone
    under "Checked just now". A race can also be stopped without releasing the
    results, to check answers first. The end can be moved, a closed quiz
    reopened, joining locked and single players removed; participants and
    answers download as CSV. With the verdict at the end, points stay hidden on
    this page until the presenter shows them.
  - Phones go from name to start card to question to verdict. "Next question"
    appears once the answer is final — a few seconds, which cost no points —
    so the verdict is never skipped by accident. Questions without a timer can
    be skipped, the position and the deadline stay in view, and a phone that
    is reloaded carries on where it was.
  - The projector never shows a question. While the quiz is open it shows the
    race — how many people are on each question and how many have finished —
    with a large join code for the first two minutes and afterwards, when
    verdicts come after every question, the top eight of the leaderboard. A
    closed quiz shows only the count, a released one the final standings.
  - The question order is frozen when the quiz opens, and the deck stays
    locked until the room is reset. An answer counts once its correction
    window is over or it can no longer be changed, and phone, projector,
    progress page, leaderboard and CSV all count the same answers. Before the
    release, phones show no solution, distribution or leaderboard. Joining
    ends when the quiz closes; join requests are limited to 120 per address
    and room in ten minutes, and a room takes at most 300 players.
  - All of it works through the API as well:
    `POST /api/1.0/rooms/{code}/pace` switches the pace and opens, closes
    (with `release: false` without releasing the results, whatever the
    deadline), extends or releases the quiz,
    `GET /api/1.0/rooms/{code}/progress` follows everyone,
    `POST /s/{code}/next` moves a phone on, and the CSV export takes
    `?view=players` and `?view=answers`.
- An AI disclosure. Pulse is vibe-coded: its code, tests, development tools,
  documentation and German translation were written by AI coding agents
  (Anthropic's Claude Code), and there has been no independent human code
  review and no security audit. The README now says so in a notice right under
  the title and in a section "How this app was built (AI disclosure)" with the
  models, the commit counts and what the tests can and cannot vouch for; the
  App Store description carries a short version in English and German.

### Changed
- Documentation and code comments are in English now. The READMEs, the App
  Store guide and the comments in PHP, JavaScript, Vue, CSS, shell and
  configuration files used to be mostly German, and so were the messages of
  the release scripts, the CI step names and the `package.json` description.
  The German translation of the interface (`l10n/`) and the German store
  description stay German on purpose. Still German, for a later round: German
  inside the code itself — test data and test method names, messages of the
  test, translation and development scripts, the seed words of the demo mode,
  the comments inside the add-in manifest template and inside the browser
  scripts of the screenshot harness.
- The README was brought up to date for a public audience: installation from
  the repository, a section on security notes, the adaptive polling intervals
  instead of a fixed 2.5 s, the databases Pulse was tested on, and the App
  Store status (the screenshots exist; the pull request for the signing
  certificate has not been opened yet). It no longer says that `info.xml`
  advertises Nextcloud 29 and later — it has declared 34 only since 0.18.0.
- The add-in manifest is no longer German-only: source locale `en-US` with a
  German override, like the rest of the app. Its `<Version>` now comes from
  `info.xml`, so PowerPoint recognises an updated add-in.
- The Traefik snippet next to it carries `nextcloud.example.com` instead of one
  specific server.
- "Show final standings" at the end of a quiz, and the automatic advance after
  the last question, now end the quiz for the whole room: projector and phones
  switch to the final standings together with the moderator. With reveal per
  question they used to appear on the moderator's screen only. If ending the
  quiz fails, the moderator stays on the question and sees an error instead of
  standings nobody else has.
- Standings opened from the menu in the middle of a quiz are called
  "Leaderboard"; "Final standings" is reserved for the end. From there, Next
  and Back take the moderator back to presenting along with the room, and on
  the final standings Back is disabled. Opened with no question running, the
  standings offer "Back to the deck" instead of "Finish quiz".
- A practice run with "Reveal at the end" can now reveal its last question: the
  main button reads "Reveal" (still without a leaderboard, as always in
  practice), then "Finish" sends the room back to the lobby. It used to go
  straight to the lobby, so nothing was ever revealed. A practice run revealed
  per question no longer drops the moderator back to the deck on its own after
  the last question.
- Nicknames are unique within a room. A name already taken — also in different
  case, or with invisible characters or extra spaces — is refused with "This
  name is already taken. Please choose another one.", so the leaderboard no
  longer shows two identical rows. Players can still keep or change their own
  name. Invisible characters are stripped from names, and a name made only of
  them is refused.
- `info.xml` gives the licence as `AGPL-3.0-or-later` instead of `agpl`. The
  store schema still accepts the short form but lists it as deprecated, and
  for apps targeting Nextcloud 31 and later the store's guide asks for an SPDX
  identifier. `package.json` names the licence now as well.

### Fixed
- The join panel no longer reopens by itself while presenting. It used to open
  whenever nobody was connected yet, and that decision was taken again on every
  results poll — every 1.2 s in a quiz — so closing it with ×, a click beside it
  or Escape had no effect for longer than one tick. In a room nobody had joined
  yet it could not be closed at all. The panel is now only ever opened by the
  "Join" button; the room code stays visible in the header and on the projector
  either way.
- The framing rules now also cover `/index.php/apps/pulse/embed` and
  `/index.php/apps/pulse/screen/…`. Without `htaccess.IgnoreFrontController`
  Nextcloud builds its URLs with `/index.php` in front, so a rule that only knew
  the short form never applied — the same page came back without the header
  under one address and with it under the other. Affects the shipped Traefik
  file and the Apache/nginx snippets in the add-in README.
- The setup check asked the router for the embed path and got an empty string
  when the app routes were not loaded, then dutifully measured `/` instead —
  which carries `X-Frame-Options` quite rightly, so the check reported "blocked"
  for every instance. It now uses the fixed path, which also keeps the result
  identical between `occ setupchecks` and the admin overview.
- Quiz answers no longer leak before the reveal:
  - The change marker phones and the projector poll with contained checksums of
    the answer key and of all votes, which could be tested against candidate
    answers. It is now a keyed hash that says nothing about the content.
  - Ranking and matching questions went out in their stored order, which is
    the solution — on the projector and through the API. Until the reveal they
    are now shuffled, the same way for everyone and independent of the
    solution.
  - "See all results" on the phone listed the whole deck, including questions
    not shown yet — in a quiz with their answer options. It now lists only
    questions that have been shown.
  - Public leaderboards counted questions that were not revealed yet, such as
    one the moderator skipped without revealing, so a jump in points told a
    player whether the answer was right. Hidden questions now stay out until
    the quiz is finished; the final standings count everything and match on
    phone, projector and moderator screen.
  - A "Reveal at the end" quiz run a second time without a reset still carried
    the first run's end, and "See all results" gave away every solution from
    the first question on. Showing a question now clears the old run's end.
  - An edited question that had already been revealed stayed revealed, so its
    new solution showed up in "See all results" at once. A question edited
    while it is not on screen now counts as not shown yet, unless a finished
    quiz ended on it.
- An answer sent just as the moderator moved on is refused with "This question
  is closed." instead of being counted for the next question, where a number, a
  word or a scale value often fits just as well.
- Open phones and the projector now pick up changes they used to miss: switching
  "Reveal at the end" after a reveal, editing a running poll question nobody had
  answered yet (every vote on it failed afterwards), renaming the room, and
  resetting it or toggling the practice run while everyone waits in the lobby
  (phones kept greeting players whose names had just been deleted). A question
  that is closed but not revealed yet shows as "Answers closed" on the projector
  instead of a result view without results.
- The projector now fits the first quiz question to the screen. Its size was
  only recalculated when results arrived, and a quiz sends none before the
  reveal. An edited running question with a longer text is refitted as well.
- Word clouds count each word once per person. "Coffee" and "COFFEE" from one
  person counted twice and used up two of the allowed words; invisible
  characters, non-breaking spaces, double spaces and emoji variants no longer
  make a second word either. The cloud shows the first spelling, in lower case
  as before, and votes given before this change are counted the same way.
- Phones: in a poll, "Your answer" now appears for every question type once
  results are up — word clouds, rankings, matchings and spectrum or compass
  scales used to read "Did not answer this round." After a reload,
  multiple-choice, number and free-text questions show the answer given instead
  of an empty, locked field. A status update that overtook a vote no longer
  resets the selection and the correction window. When the running question is
  edited, phones drop the old selection, and once their answer has been
  cleared they get a fresh correction window.
- Open phone and projector pages now reload themselves after an app update
  that changes how page and server talk to each other. A tab kept running the
  script it was opened with, even after the server behind it had moved on,
  until somebody reloaded it by hand. `/state` now carries a protocol number
  that goes up whenever server and page no longer fit together; a page that
  sees a different number than the one it was built with reloads once — at
  most once per tab and protocol, so a stubborn cache cannot turn it into a
  reload loop. Where the browser refuses session storage, as some do inside
  the PowerPoint add-in's frame, the page carries on without reloading rather
  than risk that loop. The number is also part of every state version, so an
  outdated page gets one full state instead of an endless run of "unchanged"
  replies. Pages opened before this version do not know the number yet and
  pick up the new scripts on their next reload, as before.
- Word clouds and grouped free-text answers show the first spelling that came
  in. Votes were read without a defined order, and PostgreSQL returns rows in
  storage order, which differs from the order of arrival once new votes fill
  the space of deleted ones.
- "Export CSV" on the summary page delivers the file again. The link carried
  no request token, so Nextcloud answered with "412 Precondition Failed"
  instead of the CSV.
- German phones labelled the button that changes an answer "Wandel" instead
  of "Ändern": the English "Change" was also a word in the word-cloud demo,
  and that translation won. The demo word is now "Transformation" in English
  and still "Wandel" in German. The practice-run banner in the deck said
  "Übungslauf", while everything else in German says "Probelauf"; now it
  says "Probelauf" as well.
- When resetting the room, switching "Reveal at the end" or the practice run,
  saving the question order or deleting a question fails, the error message
  now gives the server's reason instead of a general "Could not …".
- Joining a quiz no longer throws the phone back to the name screen for a
  moment. A status update that had set off before the name was sent could
  arrive after the join and still report no name; it is now discarded.
- Requests that send a value as a list or an object where text or a number is
  expected (for example `action[]=close`), a number the server cannot hold
  (`1e999`), text that is not valid UTF-8, or a participant cookie the server
  never issued no longer write "Array to string conversion" or similar
  warnings to the Nextcloud log, and no longer fail with an internal server
  error. Such values count as missing: the request gets the usual message with
  status 400, or the default applies. A deadline, a question pointer or a
  switch of the self-paced quiz that cannot be read is refused instead of
  being taken as "none".
- The results export (CSV) of a quiz with a free-text question no longer fails
  with an internal server error. Free-text answers get one row per group of
  equal answers, as on the grading screen, and number guesses show the number
  instead of an empty answer column. Free-text answers and words from the
  audience that start like a spreadsheet formula get an apostrophe in front, as
  in the self-paced export, and quotation marks inside a cell are doubled the
  way spreadsheets expect.
- Two votes sent at the same moment from the same phone in a poll — a double
  tap, or a retry after a network hiccup — no longer end in an internal server
  error. The later one counts, as when a vote is changed.
- The daily clean-up no longer deletes decks their owner is still working on.
  A moderated room created more than 30 days ago that no audience had joined
  in those 30 days was deleted, even if the owner had edited it the day
  before: only participants kept such a room alive. Now whatever the owner
  does with a room counts as well, in every room mode — opening it, editing
  the deck or its settings, presenting, looking at the results or the
  progress — so a room goes only after 30 days in which neither the owner nor
  a participant used it. The owner's activity is recorded at most once an hour
  and changes nothing anyone sees. It is recorded from this version on: on an
  installation where the clean-up already ran, a room its owner has not
  opened since the update is still judged by its participants alone, as
  before.
- Rooms of a deleted Nextcloud account no longer stay behind. They were left
  with nobody to manage them, still reachable under their public codes, and a
  room the audience kept using was never cleaned up at all. Deleting an
  account now deletes its rooms together with their questions, votes,
  players, progress and images, and their codes stop working.
- New installations now register the clean-up job. It was only added by a
  migration step, and Nextcloud skips those steps when it installs an app for
  the first time, so rooms there were never deleted. The job is now declared
  in `info.xml`, which Nextcloud reads on every install and update. An
  installation that started out on an earlier version without the job gets
  it with this update. Its existing rooms count as used on the day of the
  update, so the job deletes none of them in the first 30 days — not even a
  deck its owner edited just before the update, which the job has no record
  of.
- Deleting a room — with the delete button, by the daily clean-up or with its
  owner's account — no longer leaves its questions, votes, players or
  progress behind when the database fails halfway, for example on a deadlock
  with a vote arriving at the same moment. The rows now go in one
  transaction, which is retried after a deadlock; a deletion that still fails
  leaves the room complete instead of half deleted.
- On MySQL, adding a word cloud, a number question or a free-text question no
  longer fails with an internal server error, and neither does duplicating a
  room that contains one. MySQL cannot keep the default value of a text
  column, so a new question has to write its empty option list itself, and
  these three types left it out. A duplicate that failed this way left an
  incomplete "(copy)" room behind, which can simply be deleted. MariaDB,
  PostgreSQL and SQLite were not affected. No migration is needed: existing
  MySQL installations work as soon as they run this version.
- Release packaging (`build/package.sh`, for developers): signing works. It
  failed every time and then left the private key in the Nextcloud
  container's `/tmp`; it now signs a copy in a private temporary folder that
  is removed on every exit. Archive entries are 755/644 and owned by 0:0
  instead of world-writable, `info.xml` is checked against the store's
  current schema, and packing stops when `info.xml`, `package.json` and
  `CHANGELOG.md` disagree about the version (`ALLOW_UNRELEASED=1` packs a
  test archive, as CI does). A pre-release such as `0.19.0-beta.1` needs its
  notes under `[Unreleased]`, where the store looks for them.

### Removed
- `office-addin/manifest.xml`. It is generated now, and a second editable copy
  next to the generated one is exactly the trap this change removes.
- Internal design notes — design briefs, handoffs and HTML mockups from the
  design rounds under `docs/` — are no longer part of the public repository.
  They were working material of those rounds, not documentation of the app.
  Code comments still cite their section numbers, and those of the self-paced
  quiz specification, which was never in the repository; every file that does
  so says in a note that these documents are not public, and the README
  explains such references under "References in code comments".

## [0.18.0] - 2026-08-14

### Changed
- The demo mode fills word clouds with impressions of something new — "faster",
  "cluttered", "familiar", "confusing" — instead of the values vocabulary it
  used before ("trust", "future", "solidarity"). On the projector that old set
  read like a mission statement rather than an answer, which is the opposite of
  what a word cloud is for.
- Pulse now declares support for Nextcloud 34 only, instead of 29 to 34. The
  wider range was never tested: the app has only ever run on 34, and claiming
  five server versions means fielding bug reports from four of them. The range
  will be widened again per version that is actually verified.
- The preparation screens were tidied up the way the presentation view already
  was (specification: design notes, not in the public repository):
  - The deck header is one row with three controls — back, an overflow menu and
    the one action that matters. It used to carry nine buttons in three fixed
    rows at every width, mixing navigation, two state toggles and a destructive
    reset with the only thing you actually press during a talk.
  - "Reveal at the end" and "Practice run" are checkable menu entries now. As
    buttons their label moved with the state, so "Practice run off" read just as
    well as the current state as it did as the effect of the next click — and
    the wrong reading costs the real run, because switching empties the room.
  - A deck row has two controls instead of five: the question itself opens the
    editor, and moving and deleting live in the row's menu.
  - A room card is the button that opens it, with the rest behind its menu. Seven
    rooms used to mean seven filled buttons, each as loud as "Start poll".
  - Below 1200 px the marketing copy steps aside once there are rooms: two whole
    room cards fit above the fold instead of one cut-off one.
  - The composer scrolls into view when it opens and focuses the question field.
    With seven questions it used to open below the fold, and nothing seemed to
    happen.

### Added
- `PulseMenu`: one overflow menu for all four places, with arrow keys,
  Home/End, click-outside, and Escape that closes and returns focus to the
  trigger.

### Fixed
- A matching question with more than five pairs fell apart on the projector
  (design notes, not in the public repository). Only four pairs had
  ever been on screen — in the fixtures, in the demo, in every review:
  - While the question was open, the pool of targets was drawn **on top of** the
    rows and the first and last row were cut off. The row list claimed the full
    height of its parent although the hint above and the pool below share it.
  - The stage overflowed its frame by 66 px, and the projector clips silently.
  - The shrink loop counted `options`, which a matching question does not have,
    so four pairs and eight pairs shared one key: moving from one to the other
    left the stage at the old type size.
  - Eight rows now stand in two columns across the full width, with the intake
    display as a flat strip below instead of a column taking 40% of the screen.
  - Row labels no longer end in an ellipsis while there is room next to them:
    from 14 characters the head column grows and wraps to two lines.
  - The moderator's private panel showed every target per row — up to 64 bars in
    a 340 px column, four of eight pairs above the fold. It now carries the same
    stacked row per pair as the projector.

## [0.17.0] - 2026-07-26

### Changed
- Redesign of the projector view, stage one of five (specification: design
  notes, not in the public repository):
  - The projector is a kiosk: no Nextcloud header, no page frame, no card —
    header, stage and a single meta bar at the bottom.
  - One row per option instead of two: the bar **is** the row, and the letter,
    the label and the value all sit in it. Two identical text layers with
    complementary clipping keep the label readable on the fill and on the track.
  - Type sizes are calculated for a 3 m projection: question 68 px, labels and
    values 46 px, meta 23 px. The stage computes in `em`; one value shrinks it
    when a question brings too many rows.
  - While a question is open the projector no longer shows the result. It shows
    the answer options as outlines — the word cloud stays the exception and
    keeps growing.
  - The solution carries four signals at once: full saturation, ring, tick and
    the word "correct". Wrong answers are desaturated but keep their contrast.
  - QR code and room code moved from the top right corner into the meta bar and
    disappear once a question is revealed.
- Redesign of the projector view, stage two of five:
  - While a question is open the stage now shows how many answers are in and
    how many people are connected — a filling grid of anonymous slots, one per
    person, a bar above 200. Number guess and free text used to show nothing
    but a pencil icon; they now carry that display alone.
  - Every question type shows what to do on the phone without giving anything
    away: options as outlines, the scale as its axis with both poles, the
    compass as an empty cross, matching as rows plus a pool of targets,
    ranking without numbers.
  - The countdown became a ring in the header, on one axis with the question,
    instead of a full-width bar above the stage. Two steps: neutral, and urgent
    below ten seconds.
- Redesign of the projector view, stage three of five:
  - A question with an image now splits the stage in two: the image gets a
    column of its own and the question moves next to the result. It used to
    hang in the header at 3% of the screen area and pushed the question out of
    the middle — it is now a third of the stage.
  - Ranking rows carry the **distribution** instead of the average: every row is
    full and divided into place segments. A row with many first *and* many last
    places now reads as what it is — a topic that divides the room. The bar
    length used to carry a difference of 0.3 places.
  - Matching rows show every target, not only the leading one; in a quiz the
    correct segment carries the solution, the others are desaturated.
  - The scale fills the stage, and median and mean sit in separate bands — above
    and below the columns — so their labels can no longer collide. Values
    without votes keep a visible stub instead of looking like the axis.
  - The spectrum puts the aspect names on their spokes; the numbered badges and
    the lookup list on the far side of the screen are gone, and the radar grew
    from 450 to 656 px.
  - The compass fills the stage as a square, switches from dots to a heat map at
    45 answers, and no longer marks "you" — on a projector there is no me.
  - No word in the word cloud is rotated any more: half of them used to stand
    vertically, which looks good and reads badly from the back row.
  - Dark mode: images sit on a mat and are dimmed slightly, so a white
    background no longer glows on the screen.
  - Revealing a question nobody answered shows the answer options and says so,
    instead of an empty chart.
- Redesign of the phone, stage four of five:
  - In a quiz, tapping an answer sends it right away, and for three seconds
    afterwards a bar offers to change it (six seconds when the choice came from
    the keyboard). Exactly one correction, and it resets the timestamp — so
    tapping something quickly to buy time gains nothing. Polls stay two-step.
  - The submit bar is its own row at the bottom of the screen and never scrolls
    away. With an image and four options, every card and the button fit on a
    390 × 844 screen; the answer area only scrolls from seven options on.
  - Answer cards are 64 px tall and carry the answer colour: as a tint in
    polls, as the full surface in a quiz — the same palette and letters as the
    projector.
  - After sending, the phone keeps showing your own answer, dims the others and
    says how many have answered so far.
  - After the reveal, your own result stands above the distribution — in a quiz
    with points and place plus the leaderboard around you. The dead end
    "Short break — back in a moment" is gone.
  - At the end of a quiz the phone shows your own place first, then the top
    three, not the last question.
  - Matching replaces four native dropdowns with rows and a bottom sheet; the
    compass says "Pace: rather fast (+2)" instead of "PACE 0 · SCOPE 0" and
    keeps all four pole labels horizontal.
  - Works down to 320 px and in landscape; the reorder arrows are 44 px.
- Redesign of the moderator view and the embed shell, stage five of five:
  - The presentation view has **one** main action, and it knows what is due:
    Reveal → Next question → Show final standings → Finish quiz. Before that,
    six equal buttons stood side by side, the loudest of them the destructive
    one, while the most frequent action — moving on — had no button at all.
    Everything that is needed once or twice per session moved into an overflow
    menu; "Finish" only turns red once the standings are up.
  - The view shows a live **preview of the projector** — the same page as
    `/screen/{code}`, scaled down — instead of a second rendering of the same
    data that can drift away from it.
  - The live distribution the room does not see is a panel of its own, marked
    private with a lock **and** the words "Only for you". In full screen — the
    mode that gets projected — it is not in the document at all.
  - Joining is a state, not a column: the QR used to take a third of the width
    permanently and showed the code three times. It now stands open while
    nobody is connected, folds away once the first person joins, and is a
    button in the header after that.
  - The vote count sits next to what it refers to ("17 of 24 here"), the same
    building block as on the projector.
  - Start screen: two columns from 1200 px. The header block used to start
    150 px down the page and the first room card was cut off by the fold; the
    inactive side of the poll/quiz switch was too pale to read as a choice. It
    is now a radio group with full text contrast and arrow-key support.
  - A room without questions opens in the presentation view and offers "Add a
    question" instead of an empty deck editor.
  - The embed shell for PowerPoint got the Pulse wordmark, six code boxes that
    take a pasted code apart, an error message at the field for an unknown code
    (no dialog), and a colour scheme that follows the embedding context rather
    than the server.

### Added
- `LICENSE` (AGPL-3.0-or-later) and SPDX headers on all source files.
- Screenshot harness for design reviews (`dev/design-shots/`): drives headless
  Firefox over WebDriver and captures every view, per question type, open and
  revealed, light and dark.
- Reproducible release archive (`build/package.sh`) plus certificate helper
  (`build/certificate.sh`) for App Store uploads.
- Continuous integration on GitHub Actions: bundle build, PHPUnit, translation
  coverage and `info.xml` schema validation.
- `info.xml` now carries website, repository and documentation links.

### Fixed
- The projector cut off eight options on a question with an image: the shrink
  loop measured the stage before the image had loaded.
- A filled danger button turned *lighter* on hover and read as disabled: the
  outline variant's hover rule carried one class more and won.
- The "Projector" button in the deck header looked disabled — it was the only
  button of its group without a border colour.
- The question image stayed blank in the moderator view: the image route
  rejected the plain `<img src>` request with 412 because it required a CSRF
  token.
- Final standings cut off places 4 to 8: the podium counted in pixels and
  ignored the shrink factor. Two columns now, everything in `em`.
- The final stage still carried the headline of the last question above
  "Final standings".
- The embed shell for PowerPoint was hard-coded German while the app is
  English with translations.

## [0.16.0] - 2026-07-26

### Added
- Question type **matching**: form pairs by dragging on the phone. Available in
  poll mode (consensus view) and quiz mode (all or nothing, like ranking and
  multiple answers).
- Final standings are their own stage on the projector at the end of a quiz.

### Fixed
- Matching pairs never reached the server — the API controller dropped the field.
  A unit test now guards every question field against that class of mistake.
- Projector showed nothing during a matching question; the reveal was cut off
  after four of eight pairs.
- Projector no longer gives the solution away: unassigned targets are shown as a
  loose chip pool instead of a second aligned column.
- "End quiz" sent the projector back to the lobby instead of the standings.
- Keyboard users keep their place when reordering questions with the arrow
  buttons: focus follows the moved question.

## [0.15.0] - 2026-07-26

### Added
- Multilingual support. English is the source language, German ships in `l10n/`.
  `npm run l10n:check` reports missing and orphaned strings.
- Numbers are formatted in the language of the interface, in the frontend and in
  the CSV export.

## [0.14.0] - 2026-07-26

### Added
- An image per question: upload, delivery and display on phone and projector.

## [0.13.0] - 2026-07-26

### Added
- Full summary on the phone — all questions instead of only the last one.

### Changed
- The monolithic service was split into `RoomService`, `DeckService`,
  `VoteService`, `StateService` and `TallyService`.

## [0.12.0] - 2026-07-25

### Added
- Duplicate a room to reuse a deck as a template.
- PHPUnit suite for the pure computation paths (grading, tallying, text
  normalisation, code generation) — no database required.

## [0.11.0] - 2026-07-25

### Added
- Question type **scale** with three modes: single slider, spectrum radar and a
  two-dimensional compass field.
- Rooms can be named instead of being identified by code only.
- Demo mode (`?demo=1`) fills the active question with synthetic votes.

### Changed
- Design system: shared tokens and components across moderator, phone and
  projector; one pill button style, framework-free word cloud engine, radar and
  compass fill the projector stage, podium handles ties.

### Fixed
- Green/red contrast now meets WCAG AA.

## [0.10.0] - 2026-07-17

### Added
- PowerPoint content add-in: the projector view runs live inside a slide.

## [0.9.0] - 2026-07-17

### Added
- Projector/audience view at `/screen/{code}` with a spoiler-free presentation
  mode.

## [0.8.0] - 2026-07-17

### Added
- Four more quiz question types (true/false, multiple answers, number guess,
  free text with grading after the fact) and a "My rooms" navigation.

## [0.7.0] - 2026-07-17

### Added
- Automatic reveal, smooth countdown, practice mode and reveal-at-the-end mode.
- Accessibility: names for icon buttons, status regions, keyboard operation.

### Changed
- Own toast implementation instead of `@nextcloud/dialogs` (smaller bundle).

## [0.5.0] - 2026-07-17

### Changed
- Near-realtime updates: adaptive polling driven by a change version instead of
  a fixed 2.5 second tick.

## [0.4.1] - 2026-07-17

### Added
- Cleanup job: rooms unused for 30 days are removed.

## [0.4.0] - 2026-07-17

### Added
- Quiz mode with nicknames, countdown, speed points and a leaderboard.

## [0.3.0] - 2026-07-16

### Added
- Alphanumeric room codes, brute-force protection, "My rooms", summary with CSV
  export, participant presence.

## [0.2.0] - 2026-07-16

### Added
- First working version: rooms, public voting page without an account, multiple
  choice and word cloud, live results.
