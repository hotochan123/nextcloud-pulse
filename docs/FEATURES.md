<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Pulse features in detail

What Pulse does, for presenters. The short overview is in the
[README](../README.md#features); settings, limits and security notes for
administrators are in [`ADMIN.md`](ADMIN.md).

## Roles and room modes

- Two roles: **moderator** (signed in to Nextcloud) and **participant**
  (public, no account). Participants join through `/apps/pulse/s/{code}`, the
  QR code or the join form.
- Two room modes: **poll** (anonymous) and **quiz** (nickname, countdown,
  leaderboard).

## Question types

- **Poll:** multiple choice · word cloud · scale (single/spectrum/compass) ·
  ranking · matching.
- **Quiz:** multiple choice · true/false · multiple answers · number guess ·
  free text (with grading) · ranking · matching.
- A quiz question can carry an optional **explanation** (plain text, up to 500
  characters) that the reveal shows together with the correct answer.

## Presenting and results

- A deck of several questions, an image per question, a projector view, QR
  code, practice run, and a summary with CSV export – downloaded, or saved
  straight into your Nextcloud files (folder "Pulse", one new file per save,
  never over an existing file).
- Below 1280×720 the projector view shows a slim band at the bottom edge,
  "Window too small – everything shows cleanly from 1280×720"; the page ends
  above it, so it covers nothing. It goes away by itself once the window is
  large enough, and its ✕ hides it until the page is reloaded. The PowerPoint
  embed (which opens the page with `?embed=1`) and the desk's previews (the
  page in a frame) never show it.
- The projector view fits every question onto its stage in any window from
  1280×720 up (16:9, 16:10, 4:3, 21:9, a browser window): the text shrinks
  step by step; the question always shows whole (a long one gets smaller
  first, and smaller again before anything would be cut, never with "…"); at the smallest size rows move closer, long options keep one
  line ending in "…" (options that share a long start keep their different
  end and cut the start instead), and free-text answers that do not fit end in
  a tile "+N more". A spectrum radar shows the aspect names at its spokes;
  names too long for that become numbers at the spokes and a list beside the
  chart. Nothing is cut in the middle of a line or covers other text.
- On phones and in the desk's preview a scale result keeps its histogram
  inside the width, also with 20 values and browser zoom: when the numbers
  under the columns do not fit, the axis shows every 2nd, 5th or 10th value
  (always the first and the last), and vote counts too wide for their column
  are left out above the columns (the column heights and the total remain;
  screen readers read value and votes per column). While the counts are left
  out, tapping or clicking a column shows a small label above it with its
  value and votes ("7: 12 votes"); tapping another column moves it, tapping
  the same column, anywhere else or pressing Escape closes it.
- **PowerPoint add-in:** the deck menu entry "PowerPoint add-in" downloads an
  Office manifest that puts the projector view on a slide. The guide is in
  [`office-addin/README.md`](../office-addin/README.md); what the web server
  needs is in [`ADMIN.md`](ADMIN.md#embedding-in-powerpoint).

## Filter before the projector

In moderated rounds the desk hides any free-text or word-cloud answer with one
tap – it leaves the projector, the word cloud and the phones, stays in the CSV
marked "(hidden)", and "Show" undoes it. In self-paced quizzes the results
overview does the same for free-text answers: a hidden answer leaves the end
view of the phones after the release and the AI summary, stays in both CSV
exports marked "(hidden)", and the score does not change (the AI check below
runs only in moderated rounds).

With the menu switch **"AI help"** (offered only when an admin has set up a
text-generation provider in Nextcloud's AI settings; off by default, per room)
new answers are first checked by the AI for offensive or unfitting content:
flagged ones wait on the desk ("AI flagged") for "Show" or "Hide"; without an
answer from the AI within 10 seconds they go through unchecked and the desk
says "AI check not available right now". While the switch is on, the phones
say "Free-text answers are evaluated with AI". Nicknames are not checked. What
goes to the AI: see [`ADMIN.md`](ADMIN.md#security-notes).

### Group with AI

Only in polls. While "AI help" is on, a free-text or word-cloud question gets the desk button
"Group with AI": the AI gets the question and sorts the visible answers into 2–6 themes, each with a
short title in the moderator's Nextcloud language that names what its answers
have in common (fewer themes when the answers hardly group). The desk sees
them first – each theme with its number of answers and the most frequent answer
in it as example; answers that came in later are counted as "not in a theme"
until "Group again". "Show themes on projector" puts the theme view on the projector in place of the cloud or the answer
tiles, "Back to answers" takes it off. Hiding an answer later takes it out of
its theme at once. Moderated rounds only. A quiz has no grouping: its questions
have a correct answer, and the AI grading suggestion sorts the answers into right
and wrong instead; themes grouped in a quiz room before are no longer shown.

### AI grading suggestion

While "AI help" is on, a free-text question of a live quiz gets the desk block
"AI grading suggestion" above the answer list. "Suggest grading with AI" sends
the open answers (not yet graded, not hidden) to the AI, which proposes right
or wrong for each: an answer that means the same as an expected answer – a
typo, a synonym, another spelling – counts as right. Each proposal shows as a
dashed "AI: correct" or "AI: wrong" in its row; one tap flips it. Nothing
counts until "Accept all": it grades every answer that is still open with its
proposal, exactly like a tick or cross, and leaves answers you graded by hand
meanwhile alone. Afterwards tick and cross work as always. Answers that came
in later get no proposal until "Suggest again". Not in self-paced quizzes.

## AI quiz draft

The violet "AI" button in the quiz composer drafts 5–10 single- and
multiple-choice questions with their correct answers from a topic or a file of
your Nextcloud (text, Markdown, PDF, Word, PowerPoint, OpenDocument; scanned
PDFs have no text to read), in the language of the source. Untick what you do
not want, try again, or add the rest as normal questions. It uses Nextcloud's
own AI (task processing, text generation) and only appears when the admin has
set up a provider for it; Pulse holds no keys.

### Summary with AI

On the results overview (moderated rounds and self-paced quizzes, while the
room's switch "AI help" is on — in a self-paced quiz the switch sits in the deck
menu and only enables this): a Markdown text with every question's result in
numbers (counted by Pulse), 3–5 key points by the AI per free-text and
word-cloud question (hidden answers left out) and, in a quiz, the top 3 of the
leaderboard. You edit it on the desk, try again or cancel; "Save" puts it into
your files (folder "Pulse", "<room name> – Summary <date>.md", one new file per
save).

### AI settings for admins

Administration settings → "Pulse": "Allow AI in Pulse" (on by default) switches
every AI feature off at once – no AI button and no switch "AI help" anywhere; a
room's switch is kept for when AI comes back. "AI requests per account and day"
(default 30, 0–1000) caps the quiz drafts, groupings, grading suggestions and summaries a moderator
starts; the automatic check of answers for the projector is not counted. Once
the limit is reached, a message says so until the next day. Before an
account's first AI use a fixed notice names every AI feature (quiz draft,
filter, summary, grouping, grading suggestion), says what each sends to the
provider and what never goes; it is confirmed once with "Understood". Below the
switch a note says that the results depend on the model set up in Nextcloud
(Pulse is tested with Mistral Small 4 via Infomaniak; smaller or local models
may give weaker results).

## Self-paced quiz

The "Self-paced" toggle in a quiz deck's menu: instead of one question for
everybody, each person works through the deck on their own phone – as a race
in the room or as homework with a deadline. The moderator follows each
person's progress, grades free-text answers and releases the results; the
projector shows the race, never the questions.

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
   removed one by one. The page is private – what you project is the projector
   window.
4. **Phone:** name → start card → questions. "Next question" appears once the
   answer is final (a few seconds, costs no points); questions without a timer
   can be skipped. Reloading continues at the same place – but only in the same
   browser: switching devices starts over with a new name at question 1.
5. **Projector:** while open, the race (how many people are on which question,
   how many have finished), with a large join block for the first two minutes,
   then – with feedback after every question – the top eight; once closed, only
   the count; once released, the final standings.
6. **Results overview** ("Summary" in the menu): every question with its
   results; free-text answers can be graded there and hidden with one tap
   (see [Filter before the projector](#filter-before-the-projector)).

Limits: at most 300 people per room who have started and 600 who have joined,
and 120 new names per address and room within ten minutes; an instance can
change the last two (all limits: [`ADMIN.md`](ADMIN.md#limits)). Lock joining
once the class is in. Implementation notes with test paths and open points:
[`pace-2026-09/UI.md`](pace-2026-09/UI.md).

## Voting twice

An anonymous voter cookie (no link to a person) discourages double voting;
whoever clears their cookies can vote again (see
[`ADMIN.md`](ADMIN.md#security-notes)).

## Automatic clean-up

A daily background job deletes rooms after 30 days without any activity: no
participant was there, and the owner did nothing with the room – did not open
it, edit the deck, present or look at the results. A deck prepared weeks ahead
therefore stays. Participants alone keep a room alive only while its owner has
used it within the last 180 days, so a room whose code keeps being polled
still goes about 180 days after its owner last used it.

Deleting a Nextcloud account deletes that account's rooms, with their
questions, votes, players and images. The same daily job also deletes the
rooms of accounts that no longer exist – for example ones deleted while Pulse
was disabled, which would otherwise pass to a new account with the same user
ID – and question images that no question refers to any more.

## Languages

English and German. The interface follows the language set in Nextcloud;
numbers are formatted in that language too, also in the CSV export.
