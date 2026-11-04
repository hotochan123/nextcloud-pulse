// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// compose: on the laptop the moderator starts a poll and a quiz and adds every question type through the
// composer. The deck lists each with its type (and, in the quiz, the answer hint); the API holds the same.

import { api, expect } from '../lib/harness.mjs'
import { POLL_QS, QUIZ_QS, TYPE_LABEL, composeQuestion, expectEqual, startRoom } from '../lib/flows.mjs'

const HINTS = ['Answer B', 'False', '2 correct', '= 1969 ±1', 'Free text', '3 in order', '2 pairs']

export async function compose(world, shared) {
	const page = await world.page('laptop', 'light', { moderator: true, label: 'desk' })
	for (const [mode, qs] of [['poll', POLL_QS], ['quiz', QUIZ_QS]]) {
		const code = await startRoom(page, mode)
		world.track(code)
		for (const q of qs) await composeQuestion(page, q, mode === 'quiz')
		const rows = await page.locator('.deck-item').evaluateAll((els) => els.map((el) => ({
			q: el.querySelector('.deck-q').textContent.trim(),
			type: el.querySelector('.deck-type').textContent.replace(/\s+/g, ' ').trim(),
		})))
		expectEqual(rows.map((r) => r.q), qs.map((q) => q.question), `${mode}: deck order`)
		rows.forEach((r, i) => {
			expect(r.type.includes(TYPE_LABEL[qs[i].type]), `${mode}: row ${i + 1} names its type`, r.type)
			if (mode === 'quiz') expect(r.type.includes(HINTS[i]), `quiz: row ${i + 1} shows the answer hint`, r.type)
		})
		const room = await api('GET', '/' + code)
		const polls = room.polls || room.deck || []
		expectEqual(polls.map((p) => p.type + (p.scale ? '/' + p.scale.mode : '')),
			qs.map((q) => q.type + (q.scaleMode ? '/' + q.scaleMode : '')), `${mode}: types stored by the server`)
		shared[mode] = { code, polls }
	}
	// poll and quiz run these rooms (each takes its room once); e2e.mjs deletes them at the end
	world.rooms = world.rooms.filter((c) => c !== shared.poll.code && c !== shared.quiz.code)
	shared.leftover = [...(shared.leftover || []), shared.poll.code, shared.quiz.code]
}
