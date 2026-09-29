/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Vergleicht die übersetzbaren Strings im Quelltext mit l10n/<lang>.json.
 *
 * Die Quellsprache der App ist Englisch; Deutsch kommt aus l10n/de.json. Fehlt
 * dort ein String, sieht die deutsche Oberfläche plötzlich englisch aus — das
 * fällt beim Durchklicken kaum auf, hier aber sofort.
 *
 *   npm run l10n:check          # Exit 1, wenn etwas fehlt
 */
const fs = require('fs')
const path = require('path')

const root = path.join(__dirname, '..')

/** Alle Dateien unterhalb von dir mit passender Endung. */
function walk(dir, exts, out = []) {
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			walk(full, exts, out)
		} else if (exts.some((e) => entry.name.endsWith(e))) {
			out.push(full)
		}
	}
	return out
}

// t(…, 'Text') liefert den Text selbst als Schlüssel; n(…, 'Singular', 'Plural')
// den zusammengesetzten Schlüssel `_Singular_::_Plural_` — so legt Nextcloud die
// Mehrzahlformen ab (dieselbe Regel in PHP und JS). Die beiden Hälften sind
// für sich kein Schlüssel und werden nicht vermerkt. Steht dasselbe Wort auch
// in einem t(), braucht es einen eigenen Eintrag: t() sucht nie in einer
// Mehrzahl (so fehlte „answers" im CSV-Dateinamen, und die Prüfung schwieg).
const SINGULAR = [
	/\bt\(\s*'pulse'\s*,\s*'((?:[^'\\]|\\.)*)'/g,
	// Einbett-Shell: eigener Helfer tr('Text') statt t('pulse', 'Text').
	/\btr\(\s*'((?:[^'\\]|\\.)*)'/g,
	/->t\(\s*'((?:[^'\\]|\\.)*)'/g,
]
const PLURAL = [
	/\bn\(\s*'pulse'\s*,\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'/g,
	/->n\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'/g,
]

const found = new Map() // Schlüssel -> Set der Fundstellen
const files = [
	...walk(path.join(root, 'src'), ['.vue', '.js']),
	...walk(path.join(root, 'lib'), ['.php']),
	// Handgeschriebenes Asset ohne Bundle — seit B3 übersetzbar, also mitprüfen.
	path.join(root, 'js', 'pulse-embed.js'),
]
const unescape = (s) => s.replace(/\\'/g, '\'').replace(/\\\\/g, '\\')
const note = (key, rel) => {
	if (!found.has(key)) found.set(key, new Set())
	found.get(key).add(rel)
}
for (const file of files) {
	const src = fs.readFileSync(file, 'utf8')
	const rel = path.relative(root, file)
	for (const re of PLURAL) {
		re.lastIndex = 0
		let m
		while ((m = re.exec(src)) !== null) {
			note('_' + unescape(m[1]) + '_::_' + unescape(m[2]) + '_', rel)
		}
	}
	for (const re of SINGULAR) {
		re.lastIndex = 0
		let m
		while ((m = re.exec(src)) !== null) {
			note(unescape(m[1]), rel)
		}
	}
}
let bad = 0
const langs = fs.readdirSync(path.join(root, 'l10n')).filter((f) => f.endsWith('.json'))
for (const file of langs) {
	const lang = file.replace(/\.json$/, '')
	const translations = JSON.parse(fs.readFileSync(path.join(root, 'l10n', file), 'utf8')).translations
	const missing = [...found.keys()].filter((s) => !(s in translations))
	const orphan = Object.keys(translations).filter((s) => !found.has(s))
	console.log(`${lang}: ${found.size} Quell-Strings, ${missing.length} fehlen, ${orphan.length} verwaist`)
	for (const s of missing) {
		console.log(`  FEHLT     ${JSON.stringify(s)}  (${[...found.get(s)].join(', ')})`)
	}
	for (const s of orphan) {
		console.log(`  VERWAIST  ${JSON.stringify(s)}`)
	}
	bad += missing.length + orphan.length
}

if (found.size === 0) {
	console.log('Keine übersetzbaren Strings gefunden — stimmt das Muster noch?')
	process.exit(1)
}
process.exit(bad === 0 ? 0 : 1)
