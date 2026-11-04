// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// quiz: the quiz room (composed by "compose", else built through the API) runs live with two named phones.
// Phone 1 ("=1+1", a name a spreadsheet would run as a formula) answers everything right, phone 2 everything
// wrong except the free text, which it misspells and the moderator approves on the desk. After each reveal the
// phones show their verdict; then the final standings on desk, projector and phones, and the CSV export with
// the expected counts. (The moderated export has no player names; "paced" checks the name in its players CSV.)

import { APP, csvRows, downloadText, expectNoFormula, until } from '../lib/harness.mjs'
import { QUIZ_QS, answer, closeJoinPanel, expectEqual, menu, pickName, primary, waitQuestion } from '../lib/flows.mjs'

const NAME1 = '=1+1'
const NAME2 = 'Bea'
const TEXT2 = 'Jupitr'

export async function quizRoom(world, shared) {
	// the room composed in the browser, once; a repetition builds its own
	const composed = shared.quiz
	if (composed) {
		delete shared.quiz
		world.track(composed.code)
		return composed
	}
	return world.room('quiz', 'E2E quiz', QUIZ_QS)
}

// "rank:name" of every podium place and list row under scope, by rank (the podium shows 2–1–3 by CSS order)
const standings = (page, scope) => page.locator(scope).first().evaluate((root) => {
	const rows = []
	for (const c of root.querySelectorAll('.podium-col')) rows.push([c.querySelector('.riser-rank').textContent.trim(), c.querySelector('.podium-name-txt').textContent.trim()])
	for (const r of root.querySelectorAll('.lb-row')) rows.push([r.querySelector('.lb-rank').textContent.trim(), r.querySelector('.lb-name-txt').textContent.trim()])
	return rows.sort((a, b) => Number(a[0]) - Number(b[0])).map((r) => r.join(':')).join('|')
})
const WANT = `1:${NAME1}|2:${NAME2}`

export async function quiz(world, shared) {
	const { code } = await quizRoom(world, shared)
	const desk = await world.page('laptop', 'light', { moderator: true, label: 'desk' })
	const beamer = await world.page('projector', 'light', { label: 'projector' })
	const p1 = await world.page('phone', 'light', { label: 'phone1' })
	const p2 = await world.page('phone', 'dark', { label: 'phone2' })
	await desk.goto(APP + '/room/' + code)
	await beamer.goto(APP + '/screen/' + code)
	for (const [p, name] of [[p1, NAME1], [p2, NAME2]]) {
		await p.goto(APP + '/s/' + code)
		await pickName(p, name)
		await until(() => p.locator('.you').first().isVisible(), `${p.e2eLabel} joined as ${name}`)
	}
	await desk.getByRole('button', { name: 'Start presenting' }).click()
	await closeJoinPanel(desk)

	for (let i = 0; i < QUIZ_QS.length; i++) {
		const q = QUIZ_QS[i]
		await waitQuestion(p1, q)
		await waitQuestion(p2, q)
		await answer(p1, q, true, { quiz: true })
		await answer(p2, q, q.type === 'text' ? TEXT2 : false, { quiz: true })
		await until(async () => /\b2\b/.test(await desk.locator('.mod-intake').innerText()), `desk counts 2 answers on "${q.question}"`)
		await closeJoinPanel(desk)
		if (q.type === 'text') {
			const row = desk.locator('.mod-private .tg-row').filter({ hasText: TEXT2 })
			await row.getByRole('button', { name: 'Mark as correct' }).click()
			await until(async () => (await row.getAttribute('class')).includes('is-accepted'), 'the misspelling is accepted')
		}
		await primary(desk, 'Reveal')
		const want2 = q.type === 'text' ? 'is-ok' : 'is-no'
		await until(async () => (await p1.locator('.verdict').getAttribute('class').catch(() => '') || '').includes('is-ok'), `phone 1 right on "${q.question}"`)
		await until(async () => (await p2.locator('.verdict').getAttribute('class').catch(() => '') || '').includes(want2), `phone 2 ${want2} on "${q.question}"`)
		await primary(desk, i < QUIZ_QS.length - 1 ? 'Next question' : 'Show final standings')
	}

	await until(() => desk.locator('.mod-standings-head', { hasText: 'Final standings' }).isVisible(), 'desk shows the final standings')
	await until(async () => (await standings(desk, '.mod-standings')) === WANT, 'desk: the standings in order')
	await until(async () => (await standings(beamer, '.scr')) === WANT, 'projector: the standings in order')
	await until(async () => (await p1.locator('.big-place-n').innerText().catch(() => '')).trim() === '1', 'phone 1 is on place 1')
	await until(async () => (await p2.locator('.big-place-n').innerText().catch(() => '')).trim() === '2', 'phone 2 is on place 2')

	await menu(desk, 'Summary')
	const [download] = await Promise.all([desk.waitForEvent('download'), desk.locator('.export-btn').click()])
	const rows = csvRows(await downloadText(download))
	expectEqual(rows[0], ['No.', 'Question', 'Type', 'Answer', 'Votes', 'Share'], 'CSV header')
	const line = (q, a) => (rows.find((r) => r[1] === q && r[3] === a) || []).slice(4).join(';')
	expectEqual(['Berlin', 'Paris', 'Rome'].map((a) => line('Capital of France?', a)), ['0;0%', '1;50%', '1;50%'], 'CSV: the choice question')
	expectEqual(['Jupiter', TEXT2].map((a) => line('Largest planet?', a)), ['1;50%', '1;50%'], 'CSV: the free text')
	expectEqual(new Set(rows.slice(1).filter((r) => r.length > 1).map((r) => r[1])).size, QUIZ_QS.length, 'CSV: one block per question')
	expectNoFormula(rows, 'CSV')
}
