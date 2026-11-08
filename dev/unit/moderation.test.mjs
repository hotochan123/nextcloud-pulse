#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Unit test for src/util/moderation.js (filter before the projector): the
// buttons per answer state, and that the desk components use them.
//
//   node dev/unit/moderation.test.mjs      # exit 0 = all green
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { MOD_STATES, moderationActions, onProjector } from '../../src/util/moderation.js'

const APP = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

let passed = 0
const failures = []
function test(name, fn) {
	try {
		fn()
		passed++
	} catch (e) {
		failures.push(name)
		console.log('FAIL ' + name + '\n     ' + String(e.message).split('\n').join('\n     '))
	}
}

test('every state has a way back: hidden can be shown, everything else hidden', () => {
	assert.deepEqual(moderationActions('visible'), ['hide'])
	assert.deepEqual(moderationActions('pending'), ['hide'])
	assert.deepEqual(moderationActions('held'), ['show', 'hide'])
	assert.deepEqual(moderationActions('hidden'), ['show'])
	assert.deepEqual(moderationActions(undefined), ['hide'], 'an older server without states')
})

test('only visible answers are on the projector', () => {
	assert.deepEqual(MOD_STATES.filter(onProjector), ['visible'])
	assert.equal(onProjector(undefined), true)
})

test('the server knows the same four states', () => {
	const php = readFileSync(join(APP, 'lib/Service/ModerationService.php'), 'utf8')
	for (const s of MOD_STATES) assert.ok(php.includes(`'${s}'`), s)
})

test('free text and word cloud on the desk both use the controls', () => {
	for (const f of ['src/components/TextGrading.vue', 'src/components/AnswerFilter.vue']) {
		const src = readFileSync(join(APP, f), 'utf8')
		assert.match(src, /<ModerationControls/, f)
	}
	const ctl = readFileSync(join(APP, 'src/components/ModerationControls.vue'), 'utf8')
	assert.match(ctl, /moderationActions\(state\)/)
})

console.log(`\n==== moderation.js: ${passed} ok / ${failures.length} failed ====`)
process.exit(failures.length ? 1 : 0)
