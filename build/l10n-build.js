/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Generates the matching l10n/<lang>.js from every l10n/<lang>.json.
 *
 * Nextcloud reads both formats: PHP takes the .json, the browser gets the
 * .js loaded automatically before the app bundle (Util::addScript -> addTranslations).
 * Only the .json is maintained — the .js is generated so that the two never
 * drift apart.
 *
 *   npm run l10n
 */
const fs = require('fs')
const path = require('path')

const dir = path.join(__dirname, '..', 'l10n')
const files = fs.readdirSync(dir).filter((f) => f.endsWith('.json'))

for (const file of files) {
	const lang = file.replace(/\.json$/, '')
	const data = JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8'))
	const body = JSON.stringify(data.translations, null, 4).replace(/\n/g, '\n    ')
	const js = 'OC.L10N.register(\n    "pulse",\n    ' + body
		+ ',\n"' + data.pluralForm + '");\n'
	fs.writeFileSync(path.join(dir, lang + '.js'), js)
	console.log(lang + '.js  ' + Object.keys(data.translations).length + ' Strings')
}
