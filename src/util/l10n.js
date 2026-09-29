/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Translation for Pulse — a thin wrapper around @nextcloud/l10n.
 *
 * The only difference: escape/sanitize are OFF. Vue escapes by itself — both
 * `{{ … }}` and bound attributes. With the default, a question text
 * "Tea & Coffee" used as a placeholder value would turn into "Tea &amp; Coffee"
 * and show up exactly like that on the projector. Output goes exclusively
 * through Vue (no v-html), so escaping still happens in exactly one place.
 */
import { translate, translatePlural } from '@nextcloud/l10n'

const OPTIONS = { escape: false, sanitize: false }

/**
 * @param {string} app app id, always 'pulse' for us
 * @param {string} text English source text
 * @param {object} [vars] values for {placeholders}
 * @return {string} translated text
 */
export function t(app, text, vars) {
	return translate(app, text, vars, OPTIONS)
}

/**
 * @param {string} app app id, always 'pulse' for us
 * @param {string} singular English text for 1
 * @param {string} plural English text for n
 * @param {number} count count (also replaces %n)
 * @param {object} [vars] values for {placeholders}
 * @return {string} translated text in the matching plural form
 */
export function n(app, singular, plural, count, vars) {
	return translatePlural(app, singular, plural, count, vars, OPTIONS)
}
