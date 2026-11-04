// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// paced: the moderator switches a quiz to self-paced and opens it with the dialog's defaults (a race:
// feedback after each question). A phone (touch) plays through in its own order, right on every other
// question, and gets the matching verdict after each answer, then its points. The desk's progress shows the
// player finished with the right count, and its participants CSV keeps the name "=1+1" from running as a formula.

import { APP, csvRows, downloadText, expect, expectNoFormula, until } from '../lib/harness.mjs'
import { QUIZ_QS, answer, expectEqual, pickName, press } from '../lib/flows.mjs'

const NAME = '=1+1'

export async function paced(world) {
	const { code } = await world.room('quiz', 'E2E self-paced', QUIZ_QS)
	const desk = await world.page('laptop', 'light', { moderator: true, label: 'desk' })
	const phone = await world.page('phone', 'dark', { label: 'phone' })
	await desk.goto(APP + '/room/' + code)
	await desk.locator('.deck-head').getByRole('button', { name: 'More actions' }).click()
	await desk.getByRole('menu').getByRole('menuitemcheckbox', { name: 'Self-paced' }).click()
	await desk.getByRole('button', { name: 'Open quiz …' }).click()
	await desk.locator('.pod-submit').click()
	await until(() => desk.locator('.pace-run').isVisible(), 'desk shows the progress view')

	await phone.goto(APP + '/s/' + code)
	await pickName(phone, NAME)
	await press(phone.getByRole('button', { name: 'Start quiz' }))
	const seen = []
	let wantCorrect = 0
	for (let k = 0; k < QUIZ_QS.length; k++) {
		const text = await until(async () => {
			const t = (await phone.locator('h1.q').first().innerText().catch(() => '')).trim()
			return t && !seen.includes(t) && t
		}, `question ${k + 1} on the phone`)
		seen.push(text)
		const q = QUIZ_QS.find((x) => x.question === text)
		expect(q, 'the phone shows a question of the deck', text)
		const right = k % 2 === 0
		if (right) wantCorrect++
		await answer(phone, q, right, { quiz: true, paced: true })
		expectEqual(await phone.locator('.pace-verdict').getAttribute('data-verdict'), right ? 'correct' : 'wrong', `verdict on "${q.question}"`)
		await press(phone.locator('.h-submit .pace-next'))
	}
	await until(() => phone.locator('.pace-card[data-card="through"]').isVisible(), 'the phone is through')
	const score = (await phone.locator('.pace-score').innerText()).trim()
	expect(/^[1-9]\d* points?$/.test(score), 'the phone shows its points', score)

	await until(async () => (await desk.locator('.pace-count').innerText()).includes('1 finished'), 'desk: one player finished')
	const row = desk.locator('.pace-table tbody tr').filter({ hasText: NAME })
	await until(async () => (await row.innerText()).split(/\s+/).includes(String(wantCorrect)), `desk: ${wantCorrect} correct for ${NAME}`)

	await desk.locator('.pace-top').getByRole('button', { name: 'More actions' }).click()
	const [download] = await Promise.all([
		desk.waitForEvent('download'),
		desk.getByRole('menu').getByRole('menuitem', { name: 'Participants as CSV' }).click(),
	])
	const rows = csvRows(await downloadText(download))
	expectNoFormula(rows, 'participants CSV')
	expect(rows.some((r) => r.some((c) => c.replace(/^'/, '') === NAME)), 'participants CSV lists the player', rows.slice(0, 3))
}
