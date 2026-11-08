// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// pacedhide: hiding a free-text answer in a self-paced quiz. Two players (API phones) answer the free-text
// question of an open race differently; on the results overview of the desk (a tablet in dark mode, touch) the
// moderator taps "Hide" on one answer: the row says "Hidden" and offers "Show". After the release the other
// player's end view (/summary) has neither the answer nor its accepted chip; the author still sees their own
// answer. "Show" brings it back into the end view. The results CSV marks the hidden answer "(hidden)".

import { APP, ApiPhone, api, csvRows, downloadText, expect, expectLayout, until } from '../lib/harness.mjs'

const QS = [{ type: 'text', question: 'Largest planet?', answers: ['Jupiter'], timeLimit: 60 }]
const SECRET = 'Secretword'

/** The free-text answers another player's end view lists, and the accepted chips. */
async function endView(phone) {
	const s = await phone.req('GET', '/summary')
	const item = (s.items || [])[0] || {}
	return {
		answers: ((item.results || {}).answers || []).map((a) => a.sample),
		accepted: ((item.poll || {}).answerKey || {}).accepted || [],
		mine: (item.mine || {}).value,
		json: JSON.stringify(s),
	}
}

export async function pacedhide(world) {
	const { code, polls } = await world.room('quiz', 'E2E self-paced hide', QS)
	const pollId = polls[0].id
	await api('POST', `/${code}/pace`, { action: 'set', pace: 'self' })
	await api('POST', `/${code}/pace`, { action: 'open' })

	const ben = new ApiPhone(code)
	const ada = new ApiPhone(code)
	for (const [ph, name, value] of [[ben, 'Ben', 'Jupiter'], [ada, 'Ada', SECRET]]) {
		await ph.join(name)
		await ph.next(0)
		await ph.req('POST', '/vote', { pollId, value })
	}
	// Graded correct, the answer would also show as an accepted chip after the release.
	await api('POST', `/${code}/polls/${pollId}/grade`, { answer: SECRET, correct: true })

	const desk = await world.page('tablet', 'dark', { moderator: true, label: 'desk' })
	await desk.goto(APP + '/room/' + code)
	await until(() => desk.locator('.pace-run').isVisible(), 'desk shows the progress view')
	await desk.locator('.pace-top').getByRole('button', { name: 'More actions' }).click()
	await desk.getByRole('menu').getByRole('menuitem', { name: 'Summary' }).click()
	await desk.locator('.summary-view').waitFor()

	const row = desk.locator('.summary-view .tg-row').filter({ hasText: SECRET })
	await row.locator('.mod-ctl-btn', { hasText: 'Hide' }).click()
	await until(() => row.locator('.mod-ctl-chip', { hasText: 'Hidden' }).isVisible(), 'the row says "Hidden"')
	const show = row.locator('.mod-ctl-btn', { hasText: 'Show' })
	await show.waitFor()
	const box = await show.boundingBox()
	expect(box && box.height >= 44 && box.width >= 44, '"Show" is a 44 px target', box)
	expect(await desk.locator('.summary-view .tg-row').filter({ hasText: 'Jupiter' }).locator('.mod-ctl-btn', { hasText: 'Hide' }).isVisible(), 'the other answer can still be hidden')
	await expectLayout(desk, '.pulse-mod', 'self-paced results overview with a hidden answer on a tablet')

	await api('POST', `/${code}/pace`, { action: 'release' })
	let view = await endView(ben)
	expect(view.answers.includes('Jupiter'), 'the end view lists the visible answer', view.answers)
	expect(!/secretword/i.test(view.json), 'the hidden answer is nowhere in the other player\'s end view', view.json.slice(0, 400))
	const own = await endView(ada)
	expect(own.mine === SECRET, 'the author still sees their own answer', own.mine)

	const [download] = await Promise.all([
		desk.waitForEvent('download'),
		desk.locator('.summary-view .export-btn').click(),
	])
	const rows = csvRows(await downloadText(download))
	expect(rows.some((r) => r.includes(SECRET + ' (hidden)')), 'the results CSV marks the hidden answer', rows)

	await show.click()
	await until(() => row.locator('.mod-ctl-btn', { hasText: 'Hide' }).isVisible(), 'the row offers "Hide" again')
	view = await endView(ben)
	expect(view.answers.includes(SECRET) && view.accepted.includes(SECRET), '"Show" brings the answer back into the end view', view)
	expect(desk.e2eErrors.length === 0, 'no script errors on the desk', desk.e2eErrors)
}
