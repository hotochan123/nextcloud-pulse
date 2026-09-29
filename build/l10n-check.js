/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Compares the translatable strings in the source code with l10n/<lang>.json.
 *
 * The app's source language is English; German comes from l10n/de.json. If a
 * string is missing there, the German UI suddenly shows English — that is
 * hard to notice while clicking through, but shows up here at once.
 *
 *   npm run l10n:check          # exit 1 if anything is missing
 */
const fs = require('fs')
const path = require('path')

const root = path.join(__dirname, '..')

/** All files below dir with a matching extension. */
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

// t(…, 'Text') yields the text itself as the key; n(…, 'Singular', 'Plural')
// yields the combined key `_Singular_::_Plural_` — that is how Nextcloud stores
// plural forms (the same rule in PHP and JS). The two halves are not
// keys on their own and are not recorded. If the same word also appears
// in a t(), it needs its own entry: t() never looks inside a plural (that is
// how "answers" went missing in the CSV file name while the check stayed silent).
const SINGULAR = [
	/\bt\(\s*'pulse'\s*,\s*'((?:[^'\\]|\\.)*)'/g,
	// Embed shell: its own helper tr('Text') instead of t('pulse', 'Text').
	/\btr\(\s*'((?:[^'\\]|\\.)*)'/g,
	/->t\(\s*'((?:[^'\\]|\\.)*)'/g,
]
const PLURAL = [
	/\bn\(\s*'pulse'\s*,\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'/g,
	/->n\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'/g,
]

const found = new Map() // key -> set of locations
const files = [
	...walk(path.join(root, 'src'), ['.vue', '.js']),
	...walk(path.join(root, 'lib'), ['.php']),
	// Hand-written asset without a bundle — translatable since B3, so check it too.
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
