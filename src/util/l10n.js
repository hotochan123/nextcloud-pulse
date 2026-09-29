/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Übersetzung für Pulse — dünne Hülle um @nextcloud/l10n.
 *
 * Der einzige Unterschied: escape/sanitize sind AUS. Vue escapt selbst — sowohl
 * `{{ … }}` als auch gebundene Attribute. Mit dem Default würde ein Fragetext
 * „Tee & Kaffee" als Platzhalter zu „Tee &amp; Kaffee" und genau so auf dem
 * Beamer stehen. Ausgegeben wird ausschließlich über Vue (kein v-html), damit
 * bleibt das Escaping trotzdem an genau einer Stelle.
 */
import { translate, translatePlural } from '@nextcloud/l10n'

const OPTIONS = { escape: false, sanitize: false }

/**
 * @param {string} app App-Kürzel, für uns immer 'pulse'
 * @param {string} text englischer Quelltext
 * @param {object} [vars] Werte für {platzhalter}
 * @return {string} übersetzter Text
 */
export function t(app, text, vars) {
	return translate(app, text, vars, OPTIONS)
}

/**
 * @param {string} app App-Kürzel, für uns immer 'pulse'
 * @param {string} singular englischer Text für 1
 * @param {string} plural englischer Text für n
 * @param {number} count Anzahl (ersetzt auch %n)
 * @param {object} [vars] Werte für {platzhalter}
 * @return {string} übersetzter Text in der passenden Mehrzahlform
 */
export function n(app, singular, plural, count, vars) {
	return translatePlural(app, singular, plural, count, vars, OPTIONS)
}
