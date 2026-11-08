# Stress content for display checks

Questions, options, answers and names that are known to break layouts, or are
likely to: cut-off lines, text over text, text past its box or the stage,
lost differences, wrong direction. Every feature that shows content (projector,
moderator desk, phone, summary, export preview) is checked against this
catalog in the browser E2E, in the sizes of the projector sweep (minimum
1280×720, user decision 2026-11-06) and the phone and tablet sizes, light and
dark, through the overlap and clip check of `dev/e2e/lib/harness.mjs`.

The catalog is data, not a test list: the E2E cases `stressPoll`, `stressQuiz`
and `stressBoard` build rooms from it (`dev/e2e/stress/catalog.mjs`, which also
says how each id below is covered) and run every view through the states that
apply (open, answered, revealed, empty, leaderboard, final standings; the
themes of "Group with AI" in the case `themes`).
A new question type, field or view adds its own cases here in the same job.
Strings below are exact; `×n` means the text repeated n times with a space.

Limits of the server (`lib/Service/DeckService.php`, `Limits.php`): question
1000 characters, option 200, free-text answer 100, explanation 500, 2–8
options or pairs, nickname 24, scale range up to 20.

## S1 Length at the limit

| id | case | why |
|----|------|-----|
| S1.1 | Question of exactly 1000 characters: `Welche der folgenden Aussagen über Ludwig van Beethoven sind korrekt?` ×14, cut to 1000 | whole question visible (no line clamp, no "…"), in every state and in the smallest window of every kind |
| S1.2 | Question two lines long at 1920 wide, with descenders on the last line: `Welche der folgenden Aussagen über Ludwig van Beethoven sind korrekt?` | the reported bug: descenders of the last line cut |
| S1.3 | Eight options of exactly 200 characters each, `Option n: ` + `Lorem ipsum dolor sit amet, gypsy jugglers quietly yapping` ×n, cut to 200 | tallest possible option list |
| S1.4 | Eight options: `A`, `Ja`, then one of 200 characters, then five of 3 characters | one long row among short ones, uneven heights |
| S1.5 | Explanation of 500 characters on S1.1 with eight 200-character options, revealed | everything at its limit at the same time |
| S1.6 | Room title of the longest the composer allows, words of 30 letters | header, tabs, "My rooms" |
| S1.7 | Question of one character: `?`; options `1` and `2` | the opposite: tiny content must not stretch or centre oddly |

## S2 Words that do not break

| id | case | why |
|----|------|-----|
| S2.1 | Question `Wer trägt die Donaudampfschifffahrtsgesellschaftskapitänsmützenabzeichenherstellerinnung?` | one word wider than a phone |
| S2.2 | Option `https://example.com/pulse/a/very/long/path/without/any/break/points/at/all/index.html?query=1234567890` | URL, no spaces |
| S2.3 | Option `100 000 000 000 €` with no-break spaces (U+00A0) between the groups | no-break spaces stop wrapping |
| S2.4 | Option `WWWWWWWWWWWWWWWWWWWWWWWW` and one `MMMMMMMMMMMMMMMMMMMMMMMM` (24 wide capitals) | widest glyphs |
| S2.5 | Free-text answer of 100 characters without a space: `a` ×100 joined | answer tiles and the desk list |
| S2.6 | Word-cloud entry of 100 characters without a space | the cloud's biggest word wider than the stage |
| S2.7 | Nickname `WWWWWWWWWWWWWWWWWWWWWWWW` (24) | leaderboard, podium, live players |

## S3 Tall and unusual glyphs

| id | case | why |
|----|------|-----|
| S3.1 | Question `Gypsy jugglers quietly yapping: g j p q y – ÄÖÜ Ångström Ÿ` | ascenders, descenders, accents above capitals, against tight line heights |
| S3.2 | Option `Z̴̢͎a̷̛͓l̶̨̛g̸̡̛o̵̢̧ text` (combining marks above and below) | glyphs far outside the line box |
| S3.3 | Options `👩‍👩‍👧‍👦 Familie`, `🇩🇪🇫🇷🇮🇹`, `👍🏽👍🏿`, and an option that is only `🎉` | emoji sequences, flags, skin tones, emoji alone |
| S3.4 | Question in Vietnamese `Người ta nói tiếng Việt ở những quốc gia nào?` | stacked diacritics |
| S3.5 | Option `x² + y² = z²  ∑∫√∞ ≠ ≤ ≥` | maths symbols from fallback fonts |

## S4 Scripts and direction

| id | case | why |
|----|------|-----|
| S4.1 | Question `ما هي عاصمة ألمانيا؟` with options `برلين`, `Berlin (DE)`, `בֶּרְלִין` | right-to-left text and mixed direction in one list |
| S4.2 | Question `日本の首都はどこですか？東京、大阪、京都の中から一つ選んでください。` ×3 | CJK without spaces: line breaking anywhere |
| S4.3 | Nicknames `محمد`, `山田太郎`, `Zoë`, `ʕ•ᴥ•ʔ` | names in the leaderboard and on the phone |
| S4.5 | Three right-to-left options with the same start (`برلين هي عاصمة ألمانيا الاتحادية الكبيرة الخيار …`), differing in their last word | the kept end must stand after the start in reading order, on the left |
| S4.4 | Free-text answers mixing scripts: `Berlin`, `برلين`, `ベルリン`, `БЕРЛИН` | answer tiles, grouping, CSV |

## S5 Text that looks like markup

| id | case | why |
|----|------|-----|
| S5.1 | Question `<b>Fett?</b> **Stern** \`Code\` &amp; &lt;script&gt;alert(1)&lt;/script&gt;` | must show literally, never as markup |
| S5.2 | Option `{{ room.code }}` and option `${code}` | template syntax must stay text |
| S5.3 | Question with a line break and leading, trailing and doubled spaces: `  Zeile eins\nZeile  zwei  ` | white space in the question |
| S5.4 | Option with soft hyphens (U+00AD) and zero-width spaces (U+200B) inside a long word | hidden break points |

## S6 Nearly the same

| id | case | why |
|----|------|-----|
| S6.1 | Eight options with the same first 120 characters, differing only at the end (`… Variante 1` … `… Variante 8`) | a cut label hides the only difference |
| S6.2 | Options `0`, `O`, `o`, `Ο` (Greek), `О` (Cyrillic) | look-alikes stay apart in results and CSV |
| S6.4 | Three options with the same start of 30 characters (`Ich bin der Meinung, dass wir …`) and long different ends of 57–70 characters | a long distinguishing end must not push the shared start out of the row |
| S6.3 | Free-text answers `Berlin`, `berlin`, ` Berlin `, `Berlín` | grouping and tiles |

## S7 Numbers and results at the extremes

| id | case | why |
|----|------|-----|
| S7.1 | Number question: target `123456789.123`, tolerance `0.001`; answers `-0.0001`, `999999999999`, `1e6`, `1,5` | number width and format in results |
| S7.2 | Results: 0 votes; 1 vote; all votes on one option (100 % next to 0 %); all options equal; 1000 votes (four-digit counters) | bars, percentages, counters |
| S7.3 | Leaderboard: 60 players, ties on every place, one player with 0 points, one with the highest possible score | rows, ranks, podium |
| S7.4 | Free text: one answer given 50 times next to 40 single answers | 12× tile next to many 1× tiles |
| S7.5 | Word cloud: 300 distinct words; one word with all mentions; all words with the same count | cloud layout at both ends |

## S8 Per question type

| id | case | why |
|----|------|-----|
| S8.1 | True/false with S1.1 as the question | short options under a maximal question |
| S8.2 | Ordering (rank) with eight 200-character options | drag rows on the phone, result order |
| S8.3 | Matching with eight pairs, each side 100 characters, one side S2.1 | two columns on a phone |
| S8.4 | Scale with range 20, and aspects and axis or corner labels of the longest allowed length with words of 30 letters | ticks and labels crowd |
| S8.4d | Scale 1–20 with 400 votes, so the counts above the 20 columns have two digits, on the phone and zoomed | counts and axis numbers wider than a column; the label of a tapped column inside the screen (case `histtap`) |
| S8.5 | Image question with S1.1, once with a tall portrait image (600×2000) and once with a panorama (3000×400) | image and text share the stage |
| S8.6 | Word cloud with up to 5 words per answer, each word 30 letters | |

## S9 Viewing conditions

| id | case | why |
|----|------|-----|
| S9.1 | UI in German and in English (German labels are longer) | buttons and chips |
| S9.2 | Browser zoom 200 % on the desk and the phone; the system's large text setting on the phone | text grows, boxes must follow |
| S9.3 | Projector in a browser window with tab and address bar, and in full screen | 945 vs 1080 high at 1920 wide |
| S9.4 | Projector below the minimum size (1024×600), light and dark (German), lobby and a question with votes | the size note at the bottom edge must cover nothing, the stage fits above it |

## Design rounds

A design round (OpenDesign or any mock-up) shows its variants with the cases of
this catalog that apply, not only with tidy sample text, from 1280×720 up and on
phone and tablet (user decision 2026-11-06). A variant that only works with
short text is not taken.

## Adding a case

A display bug found by hand becomes a case here first (smallest content that
shows it), then the fix. The E2E runs the whole catalog; a case may set the
views and states it applies to.
