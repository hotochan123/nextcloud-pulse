/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Erzeugt aus jeder l10n/<lang>.json die passende l10n/<lang>.js.
 *
 * Nextcloud liest beide Formate: PHP nimmt die .json, der Browser bekommt die
 * .js automatisch vor das App-Bundle geladen (Util::addScript -> addTranslations).
 * Gepflegt wird nur die .json — die .js ist generiert, damit die beiden nie
 * auseinanderlaufen.
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
