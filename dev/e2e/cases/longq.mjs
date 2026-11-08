// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// longq: a question of 1000 characters (STRESS.md S1.1) shows whole wherever a list shows it, never cut with an
// ellipsis: the desk's question list (a laptop, light), then the quiz self-paced with a free-text question after
// it: a phone (touch, dark) plays through and opens "See your answers", the desk's progress view lists both
// questions and the free text waiting to be checked, and the results overview (Summary) lists them, on a laptop
// and on a phone-sized desk.

import { APP, expect, expectLayout, expectWholeQuestion, until } from '../lib/harness.mjs'
import { answer, pickName, press } from '../lib/flows.mjs'
import { Q1000 } from '../stress/catalog.mjs'

const QS = [
	{ type: 'choice', question: Q1000, options: ['Ja', 'Nein'], correctIndex: 0, timeLimit: 60 },
	{ type: 'text', question: 'Largest planet?', answers: ['Jupiter'], timeLimit: 60 },
]

async function whole(page, root, what) {
	await expectWholeQuestion(page, root, Q1000, what)
	await expectLayout(page, root, what)
}

export async function longq(world) {
	const { code } = await world.room('quiz', 'E2E long question', QS)
	const desk = await world.page('laptop', 'light', { moderator: true, label: 'desk' })
	await desk.goto(APP + '/room/' + code)
	await desk.locator('.deck-q').first().waitFor()
	await whole(desk, '.pulse-mod', 'question list')

	await desk.locator('.deck-head').getByRole('button', { name: 'More actions' }).click()
	await desk.getByRole('menu').getByRole('menuitemcheckbox', { name: 'Self-paced' }).click()
	await desk.getByRole('button', { name: 'Open quiz …' }).click()
	await desk.locator('.pod-submit').click()
	await until(() => desk.locator('.pace-run').isVisible(), 'desk shows the progress view')

	const phone = await world.page('phone', 'dark', { label: 'phone' })
	await phone.goto(APP + '/s/' + code)
	await pickName(phone, 'Ada')
	await press(phone.getByRole('button', { name: 'Start quiz' }))
	const seen = []
	for (let k = 0; k < QS.length; k++) {
		const text = await until(async () => {
			const t = (await phone.locator('h1.q').first().innerText().catch(() => '')).replace(/\s+/g, ' ').trim()
			return t && !seen.includes(t) && t
		}, `question ${k + 1} on the phone`)
		seen.push(text)
		const q = QS.find((x) => x.question.replace(/\s+/g, ' ').trim() === text)
		expect(q, 'the phone shows a question of the deck', text.slice(0, 60))
		if (q.question === Q1000) await whole(phone, '.pulse-part', 'phone question')
		await answer(phone, q, q.type === 'text' ? 'Saturnring' : true, { quiz: true, paced: true })
		await press(phone.locator('.h-submit .pace-next'))
	}
	await until(() => phone.locator('.pace-card[data-card="through"]').isVisible(), 'the phone is through')
	await press(phone.getByRole('button', { name: 'See your answers' }))
	await phone.locator('.review-q-text').first().waitFor()
	await whole(phone, '.pulse-part', 'phone: your answers')

	await until(async () => (await desk.locator('.pace-count').innerText()).includes('1 finished'), 'desk: one player finished')
	await desk.locator('.pace-qtext').first().waitFor()
	await whole(desk, '.pulse-mod', 'progress view: questions')

	await desk.locator('.pace-top').getByRole('button', { name: 'More actions' }).click()
	await desk.getByRole('menu').getByRole('menuitem', { name: 'Summary' }).click()
	await desk.locator('.summary-qtext').first().waitFor()
	await whole(desk, '.pulse-mod', 'results overview')
	await desk.setViewportSize({ width: 390, height: 844 })
	await whole(desk, '.pulse-mod', 'results overview, phone-sized')
	expect(desk.e2eErrors.length === 0, 'no script errors on the desk', desk.e2eErrors)
	expect(phone.e2eErrors.length === 0, 'no script errors on the phone', phone.e2eErrors)
}
