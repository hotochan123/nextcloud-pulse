// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The stress catalog of dev/e2e/STRESS.md as data: rooms and questions the server accepts, with the answers,
// nicknames and images that go with them. The E2E cases of cases/stress.mjs build the rooms from it and run each
// case through its views and states (see STRESS.md and dev/e2e/README.md).
//
// A case:
//   id        the id of STRESS.md (S1.3), with a letter for a part of one (S7.2b)
//   room      the room it runs in: cases of one room share it, in catalog order (ROOMS below)
//   q         the question, the body of POST /rooms/{code}/polls, with the exact characters of STRESS.md
//   stored    only where the server changes the text on purpose: the question as it is kept (why: in a comment)
//   answers   answers of players over the public API: an option index (choice, truefalse), a list of indexes
//             (multi), a string (text), a number or string (number), a list of words (words)
//   demo      the desk's demo votes after the answers (several calls when more than 200)
//   samples   free text: the answers the results must show exactly as written
//   image     a generated PNG uploaded to the question: [width, height]
//   views     projector, desk, phone (default: all three)
//   states    open, answered, revealed, empty (default: open, answered, revealed); empty = revealed with no answer
//   uncut     the ends of the option labels, where they differ, must show whole, never cut with an ellipsis (a cut
//             end hides the difference; the projector's one-line rows cut the shared start instead)
//   uncutOpen a known limit of uncut: its findings are reported as "known <id>: …" (the layout checks stay hard)
//   sizes     'smallest': on the projector also the smallest size of every kind of the sweep (a question that must
//             show whole where the height is shortest)
//   open      a known finding this job could not fix, for all views (a string) or per view ({ projector: … }):
//             reported as "known <id>: …", the run stays green
//
// Not as a case of its own: S1.6 is the title of the room "poll" (all three faces show it), S9.1 the language
// (the projector and phone pages of the dark scheme run in German, de-DE), S9.2 the zoom devices of the sweep
// (zoomDesk, zoomPhone), S9.3 the sizes of the sweep (window = browser window with tab and address bar), S9.4
// the size note of the case projector (below 1280×720). The state "themes" runs in the case themes (fake AI
// provider); S7.2a (no vote) is the state "empty" of S7.2.

const rep = (s, n) => Array(n).fill(s).join(' ')
/** First n characters (code points, as the server counts), never ending in a space (the server trims). */
const cut = (s, n) => {
	const a = Array.from(s).slice(0, n)
	if (a[a.length - 1] === ' ') a[a.length - 1] = '.'
	return a.join('')
}

const BEET = 'Welche der folgenden Aussagen über Ludwig van Beethoven sind korrekt?'
const LOREM = 'Lorem ipsum dolor sit amet, gypsy jugglers quietly yapping'
export const Q1000 = cut(rep(BEET, 15), 1000)
const O200 = (n) => cut(`Option ${n}: ` + rep(LOREM, 4), 200)
const EIGHT200 = [1, 2, 3, 4, 5, 6, 7, 8].map(O200)
const DONAU = 'Wer trägt die Donaudampfschifffahrtsgesellschaftskapitänsmützenabzeichenherstellerinnung?'
const W30 = 'Donaudampfschifffahrtsgesellsc'
const W24 = 'W'.repeat(24)
const BEET5 = ['Beethoven wurde in Bonn, Deutschland, geboren.', 'Sein Geburtsjahr ist 1770.', 'Er verlor im Alter sein Gehör.', 'Beethoven komponierte hauptsächlich Opern.', 'Seine bekannteste Symphonie ist die "Pastorale".']
const SAME120 = cut('Die Antwort lautet, nach reiflicher Überlegung aller Umstände und unter Berücksichtigung sämtlicher Fußnoten, ganz eindeutig', 120)
const quiz = (q) => ({ timeLimit: 300, ...q })

/** The rooms: mode and title (S1.6: the longest title the server keeps, 80 characters, words of 30 letters). */
export const ROOMS = {
	poll: { mode: 'poll', title: cut(`${W30} ${W30} ${W30}`, 80) },
	quiz: { mode: 'quiz', title: 'Stress quiz' },
	board: { mode: 'quiz', title: 'Stress leaderboard' },
	tiles: { mode: 'quiz', title: 'Stress free-text tiles' },
}

// S7.3 (with S2.7 and S4.3): 60 players, the widest and the non-Latin names among them; ties on every place come
// from pairs answering in the same second, one player answers wrong (0 points)
export const BOARD_NAMES = [W24, 'محمد', '山田太郎', 'Zoë', 'ʕ•ᴥ•ʔ', ...Array.from({ length: 55 }, (_, i) => `Spieler ${String(i + 6).padStart(2, '0')}`)]

const WORDS300 = Array.from({ length: 60 }, (_, v) => Array.from({ length: 5 }, (_, k) => `Wort${String(v * 5 + k + 1).padStart(3, '0')}`))

export const CASES = [
	// ── S1 length at the limit
	{ id: 'S1.1', room: 'quiz', q: quiz({ type: 'choice', question: Q1000, options: ['Ja', 'Nein', 'Vielleicht'], correctIndex: 0 }), answers: [0, 1], demo: 30, sizes: 'smallest' },
	{ id: 'S1.2', room: 'quiz', q: quiz({ type: 'multi', question: BEET, options: BEET5, correctIndexes: [0, 1, 2] }), states: ['open', 'empty'] },
	{ id: 'S1.2b', room: 'quiz', q: quiz({ type: 'multi', question: BEET, options: BEET5, correctIndexes: [0, 1, 2] }), answers: [[0, 1, 2], [3]], demo: 40 },
	{ id: 'S1.3', room: 'poll', q: { type: 'choice', question: 'Welche Option?', options: EIGHT200 }, answers: [0, 7, 3], demo: 40 },
	{ id: 'S1.4', room: 'poll', q: { type: 'choice', question: 'Ungleich hoch', options: ['A', 'Ja', O200(3), 'abc', 'def', 'ghi', 'jkl', 'mno'] }, answers: [2, 0], demo: 20 },
	{ id: 'S1.5', room: 'quiz', q: quiz({ type: 'choice', question: Q1000, options: EIGHT200, correctIndex: 0, explanation: cut(rep('Erklärung mit Unterlängen: gypsy jugglers quietly yapping.', 10), 500) }), answers: [0, 5], demo: 40, sizes: 'smallest' },
	{ id: 'S1.7', room: 'poll', q: { type: 'choice', question: '?', options: ['1', '2'] }, answers: [0] },
	// ── S2 words that do not break
	{ id: 'S2.1', room: 'quiz', q: quiz({ type: 'choice', question: DONAU, options: ['Ja', 'Nein'], correctIndex: 0 }), answers: [0] },
	{ id: 'S2.2', room: 'poll', q: { type: 'choice', question: 'Welche Adresse?', options: ['https://example.com/pulse/a/very/long/path/without/any/break/points/at/all/index.html?query=1234567890', 'Kurz'] }, answers: [0] },
	{ id: 'S2.3', room: 'poll', q: { type: 'choice', question: 'Wie viel?', options: ['100 000 000 000 €', '1 €'] }, answers: [0] },
	{ id: 'S2.4', room: 'poll', q: { type: 'choice', question: 'Breite Buchstaben', options: ['W'.repeat(24), 'M'.repeat(24)] }, answers: [1] },
	{ id: 'S2.5', room: 'quiz', q: quiz({ type: 'text', question: 'Ein langes Wort?', answers: ['Berlin'] }), answers: ['a'.repeat(100), 'Berlin'], samples: ['a'.repeat(100), 'Berlin'] },
	// S2.6: the server keeps a word of at most 40 characters (AnswerRules), the rest of the 100 is cut
	{ id: 'S2.6', room: 'poll', q: { type: 'words', question: 'Ein Wort ohne Leerzeichen', maxWords: 1 }, answers: [['b'.repeat(100)], ['kurz']] },
	// ── S3 tall and unusual glyphs
	{ id: 'S3.1', room: 'quiz', q: quiz({ type: 'choice', question: 'Gypsy jugglers quietly yapping: g j p q y – ÄÖÜ Ångström Ÿ', options: ['g j p q y', 'ÄÖÜ Ÿ'], correctIndex: 0 }), answers: [0] },
	{ id: 'S3.2', room: 'poll', q: { type: 'choice', question: 'Zalgo', options: ['Z̴̢͎a̷̛͓l̶̨̛g̸̡̛o̵̢̧ text', 'normal'] }, answers: [0] },
	{ id: 'S3.3', room: 'poll', q: { type: 'choice', question: 'Emoji', options: ['👩‍👩‍👧‍👦 Familie', '🇩🇪🇫🇷🇮🇹', '👍🏽👍🏿', '🎉'] }, answers: [3, 0] },
	{ id: 'S3.4', room: 'quiz', q: quiz({ type: 'choice', question: 'Người ta nói tiếng Việt ở những quốc gia nào?', options: ['Việt Nam', 'Lào', 'Campuchia'], correctIndex: 0 }), answers: [0] },
	{ id: 'S3.5', room: 'poll', q: { type: 'choice', question: 'Formel', options: ['x² + y² = z²  ∑∫√∞ ≠ ≤ ≥', 'keine'] }, answers: [0] },
	// ── S4 scripts and direction
	{ id: 'S4.1', room: 'quiz', q: quiz({ type: 'choice', question: 'ما هي عاصمة ألمانيا؟', options: ['برلين', 'Berlin (DE)', 'בֶּרְלִין'], correctIndex: 0 }), answers: [0, 2] },
	{ id: 'S4.2', room: 'poll', q: { type: 'choice', question: rep('日本の首都はどこですか？東京、大阪、京都の中から一つ選んでください。', 3), options: ['東京', '大阪', '京都'] }, answers: [0] },
	{ id: 'S4.4', room: 'quiz', q: quiz({ type: 'text', question: 'Hauptstadt?', answers: ['Berlin'] }), answers: ['Berlin', 'برلين', 'ベルリン', 'БЕРЛИН'], samples: ['Berlin', 'برلين', 'ベルリン', 'БЕРЛИН'] },
	{ id: 'S4.5', room: 'poll', q: { type: 'choice', question: 'أي خيار؟', options: ['الأول', 'الثاني', 'الثالث'].map((w) => 'برلين هي عاصمة ألمانيا الاتحادية الكبيرة الخيار ' + w) }, answers: [0, 2], demo: 20, uncut: true },
	// ── S5 text that looks like markup
	{ id: 'S5.1', room: 'poll', q: { type: 'choice', question: '<b>Fett?</b> **Stern** `Code` &amp; &lt;script&gt;alert(1)&lt;/script&gt;', options: ['<i>kursiv</i>', '&amp;'] }, answers: [0] },
	{ id: 'S5.2', room: 'poll', q: { type: 'choice', question: 'Vorlagen', options: ['{{ room.code }}', '${code}'] }, answers: [1] },
	// S5.3: the server trims the white space around a question (all fields), inner spaces and the line break stay
	{ id: 'S5.3', room: 'poll', q: { type: 'choice', question: '  Zeile eins\nZeile  zwei  ', options: ['a', 'b'] }, stored: 'Zeile eins\nZeile  zwei', answers: [0] },
	{ id: 'S5.4', room: 'poll', q: { type: 'choice', question: 'Versteckte Trennstellen', options: ['Donau­dampf­schiff­fahrts​gesellschafts​kapitäns­mützen­abzeichen', 'kurz'] }, answers: [0] },
	// ── S6 nearly the same
	{ id: 'S6.1', room: 'poll', q: { type: 'choice', question: 'Welche Variante?', options: [1, 2, 3, 4, 5, 6, 7, 8].map((n) => `${SAME120} Variante ${n}`) }, answers: [0, 7], demo: 20, uncut: true },
	{ id: 'S6.4', room: 'poll', q: { type: 'choice', question: 'Wie weiter?', options: ['das Projekt sofort starten und die Planung unterwegs anpassen sollten', 'abwarten sollten, bis alle Beteiligten ihre Zustimmung gegeben haben', 'das Budget zuerst noch einmal gründlich überprüfen müssen'].map((e) => 'Ich bin der Meinung, dass wir ' + e) }, answers: [0, 2], demo: 20 },
	{ id: 'S6.2', room: 'poll', q: { type: 'choice', question: 'Doppelgänger', options: ['0', 'O', 'o', 'Ο', 'О'] }, answers: [0, 3, 4] },
	{ id: 'S6.3', room: 'quiz', q: quiz({ type: 'text', question: 'Hauptstadt Deutschlands?', answers: ['Berlin'] }), answers: ['Berlin', 'berlin', ' Berlin ', 'Berlín'] },
	// ── S7 numbers and results at the extremes
	// S7.1: the server takes a number with a decimal point only; "1,5" (a comma) gets "Please enter a number." on
	// the phone, so the catalog sends 1.5
	{ id: 'S7.1', room: 'quiz', q: quiz({ type: 'number', question: 'Wie groß?', target: 123456789.123, tolerance: 0.001 }), answers: [-0.0001, 999999999999, '1e6', '1.5'] },
	{ id: 'S7.2', room: 'poll', q: { type: 'choice', question: 'Kein Ergebnis', options: ['Ja', 'Nein', 'Vielleicht', 'Weiß nicht'] }, states: ['open', 'empty'] },
	{ id: 'S7.2b', room: 'poll', q: { type: 'choice', question: 'Eine Stimme', options: ['Ja', 'Nein', 'Vielleicht', 'Weiß nicht'] }, answers: [2] },
	{ id: 'S7.2c', room: 'poll', q: { type: 'choice', question: 'Alle auf einer', options: ['Ja', 'Nein', 'Vielleicht', 'Weiß nicht'] }, answers: [0, 0, 0, 0, 0, 0] },
	{ id: 'S7.2d', room: 'poll', q: { type: 'choice', question: 'Alle gleich', options: ['Ja', 'Nein', 'Vielleicht', 'Weiß nicht'] }, answers: [0, 1, 2, 3, 0, 1, 2, 3] },
	{ id: 'S7.2e', room: 'poll', q: { type: 'choice', question: 'Tausend Stimmen', options: ['Ja', 'Nein', 'Vielleicht', 'Weiß nicht'] }, demo: 1000, states: ['answered', 'revealed'] },
	{ id: 'S7.3', room: 'board', q: quiz({ type: 'choice', question: 'Wer gewinnt?', options: ['Richtig', 'Falsch'], correctIndex: 0 }), answers: BOARD_NAMES.map((_, i) => (i === BOARD_NAMES.length - 1 ? 1 : 0)), final: true },
	{ id: 'S7.4', room: 'tiles', q: quiz({ type: 'text', question: 'Ein Wort?', answers: ['Hauptstadt'] }), answers: [...Array(50).fill('Hauptstadt'), ...Array.from({ length: 40 }, (_, i) => `Einzeln ${i + 1}`)], samples: ['Hauptstadt', 'Einzeln 40'] },
	{ id: 'S7.5', room: 'poll', q: { type: 'words', question: '300 Wörter', maxWords: 5 }, answers: WORDS300 },
	{ id: 'S7.5b', room: 'poll', q: { type: 'words', question: 'Ein Wort für alle', maxWords: 1 }, answers: Array(20).fill(['Alles']) },
	{ id: 'S7.5c', room: 'poll', q: { type: 'words', question: 'Alle gleich oft', maxWords: 5 }, answers: Array.from({ length: 6 }, (_, v) => Array.from({ length: 5 }, (_, k) => `Gleich${v * 5 + k + 1}`)) },
	// ── S8 per question type
	{ id: 'S8.1', room: 'quiz', q: quiz({ type: 'truefalse', question: Q1000, correctIndex: 0 }), answers: [0, 1] },
	{ id: 'S8.2', room: 'poll', q: { type: 'rank', question: 'Ordne', options: EIGHT200 }, demo: 20 },
	{ id: 'S8.3', room: 'quiz', q: quiz({ type: 'match', question: 'Ordne zu', pairs: [1, 2, 3, 4, 5, 6, 7, 8].map((n) => ({ left: n === 1 ? DONAU : cut(`Links ${n}: ` + rep(LOREM, 2), 100), right: cut(`Rechts ${n}: ` + rep(LOREM, 2), 100) })) }), demo: 20 },
	{ id: 'S8.4', room: 'poll', q: { type: 'scale', question: 'Skala bis 20', scaleMode: 'single', scaleMax: 20, minLabel: cut(`${W30} ${W30}`, 40), maxLabel: cut(`${W30} ${W30}`, 40) }, demo: 40 },
	{ id: 'S8.4b', room: 'poll', q: { type: 'scale', question: 'Spektrum', scaleMode: 'spectrum', scaleMax: 5, aspects: [1, 2, 3, 4, 5, 6].map((n) => ({ label: cut(`${W30}${n} ${W30}`, 40) })) }, demo: 40 },
	{ id: 'S8.4c', room: 'poll', q: { type: 'scale', question: 'Kompass', scaleMode: 'compass', range: 20, axisX: { title: cut(`${W30} ${W30}`, 40), poleLow: cut(`${W30}links`, 40), poleHigh: cut(`${W30}rechts`, 40) }, axisY: { title: cut(`${W30} ${W30}`, 40), poleLow: cut(`${W30}unten`, 40), poleHigh: cut(`${W30}oben`, 40) } }, demo: 40 },
	{ id: 'S8.4d', room: 'poll', q: { type: 'scale', question: 'Skala bis 20, viele Stimmen', scaleMode: 'single', scaleMax: 20, minLabel: 'gar nicht', maxLabel: 'sehr' }, demo: 400 },
	{ id: 'S8.5', room: 'quiz', q: quiz({ type: 'choice', question: Q1000, options: ['Hoch', 'Breit'], correctIndex: 0 }), image: [600, 2000], answers: [0] },
	{ id: 'S8.5b', room: 'quiz', q: quiz({ type: 'choice', question: Q1000, options: ['Hoch', 'Breit'], correctIndex: 1 }), image: [3000, 400], answers: [1] },
	{ id: 'S8.6', room: 'poll', q: { type: 'words', question: 'Fünf lange Wörter', maxWords: 5 }, answers: Array.from({ length: 6 }, (_, v) => Array.from({ length: 5 }, (_, k) => cut(`${W30}`, 28) + String(v * 5 + k).padStart(2, '0'))) },
]

/** ids of STRESS.md covered without a case of their own, with the reason (see the head of this file). */
export const COVERED = {
	'S1.6': 'title of the room "poll"',
	'S2.7': 'a nickname of S7.3',
	'S4.3': 'nicknames of S7.3',
	'S9.1': 'the dark projector and phone pages run in German',
	'S9.2': 'zoom devices of the sweep (zoomDesk, zoomPhone)',
	'S9.3': 'sizes of the sweep (window)',
	'S9.4': 'the case projector (size note at 1024×600)',
}
