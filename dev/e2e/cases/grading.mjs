// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// grading: the AI grading suggestion with the fake AI provider (dev/e2e/fakeai: an answer is correct when it starts
// with the first four letters of an expected answer; an answer "failgrade" makes the task fail). A free-text quiz
// question expecting "Berlin": the desk switches "AI help" on, phones answer "Berlln" and "Paris"; "Suggest grading
// with AI" marks Berlln "AI: correct" and Paris "AI: wrong" while both stay open; one tap flips Paris; "Accept all"
// grades both as correct; the moderator's cross afterwards makes Paris wrong. A failing task makes the desk say
// "AI grading suggestion not available right now". The suggestion button and the proposal chips are at least 44 px
// high on laptop and tablet, light and dark.

import { APP, ApiPhone, api, expectLayout, until } from '../lib/harness.mjs'
import { closeJoinPanel, expectEqual } from '../lib/flows.mjs'

const TEXT_Q = { type: 'text', question: 'Capital of Germany?', answers: ['Berlin'], timeLimit: 120 }

// The AI check of new answers (filter before the projector) is done.
async function checked(code, pollId, n) {
	await until(async () => {
		const items = (await api('GET', '/' + code + '/polls/' + pollId + '/results')).moderation?.items || []
		return items.length >= n && items.every((i) => i.state === 'visible')
	}, 'the AI check of the answers is done', 30000)
}

async function answer(code, pollId, name, value) {
	const ph = new ApiPhone(code)
	await ph.req('GET', '/state')
	await ph.join(name)
	await ph.req('POST', '/vote', { pollId, value })
}

export async function grading(world) {
	const { code, polls } = await world.room('quiz', 'E2E grading', [TEXT_Q])
	const pid = polls[0].id
	const desk = await world.page('laptop', 'light', { moderator: true, label: 'desk' })
	await desk.goto(APP + '/room/' + code)
	await desk.getByRole('button', { name: 'Start presenting' }).click()
	await closeJoinPanel(desk)
	await desk.locator('.mod-top').getByRole('button', { name: 'More actions' }).click()
	await desk.getByRole('menu').getByRole('menuitemcheckbox', { name: 'AI help' }).click()
	if (await desk.getByRole('menu').isVisible()) await desk.keyboard.press('Escape')
	await until(async () => (await api('GET', '/' + code)).aiHelp === true, 'the room has AI help on')

	await answer(code, pid, 'Ann', 'Berlln')
	await answer(code, pid, 'Ben', 'Paris')
	await checked(code, pid, 2)

	const panel = desk.locator('.grading-suggestion')
	const row = (text) => desk.locator('.text-grading .tg-row').filter({ hasText: text })
	await until(() => panel.isVisible(), 'the desk offers the AI grading suggestion')
	await panel.getByRole('button', { name: 'Suggest grading with AI' }).click()
	await until(() => row('Berlln').locator('.tg-ai').isVisible(), 'the desk shows the proposals', 30000)
	expectEqual((await row('Berlln').locator('.tg-ai').innerText()).trim(), 'AI: correct', 'Berlln is proposed as correct')
	expectEqual((await row('Paris').locator('.tg-ai').innerText()).trim(), 'AI: wrong', 'Paris is proposed as wrong')
	const statusOf = async () => Object.fromEntries(((await api('GET', '/' + code + '/polls/' + pid + '/results')).answers || []).map((a) => [a.sample, a.status]))
	expectEqual(JSON.stringify(await statusOf()), JSON.stringify({ Berlln: 'pending', Paris: 'pending' }), 'nothing counts before the moderator accepts')

	await row('Paris').locator('.tg-ai').click()
	await until(async () => (await row('Paris').locator('.tg-ai').innerText()).trim() === 'AI: correct', 'one tap flips the proposal')
	await panel.getByRole('button', { name: 'Accept all (2)' }).click()
	await until(async () => (await row('Paris').getAttribute('class')).includes('is-accepted'), '"Accept all" grades Paris')
	expectEqual((await row('Berlln').getAttribute('class')).includes('is-accepted'), true, '"Accept all" grades Berlln')
	expectEqual(await desk.locator('.text-grading .tg-ai').count(), 0, 'no proposal left after accepting')
	await row('Paris').getByRole('button', { name: 'Mark as wrong' }).click()
	await until(async () => (await row('Paris').getAttribute('class')).includes('is-rejected'), 'the cross after accepting makes Paris wrong')

	// a failing task: the desk says so
	await answer(code, pid, 'Cat', 'failgrade')
	await checked(code, pid, 3)
	await until(() => panel.getByRole('button', { name: 'Suggest grading with AI' }).isEnabled(), 'after accepting the desk offers a new suggestion')
	await panel.getByRole('button', { name: 'Suggest grading with AI' }).click()
	await until(() => panel.getByText('AI grading suggestion not available right now').isVisible(), 'a failing suggestion: the desk says so', 30000)
	expectEqual(await panel.getByRole('button', { name: 'Suggest again' }).isVisible(), true, 'after a failure the button says "Suggest again"')

	// Layout: proposals on laptop and tablet, light and dark.
	const lay = await world.room('quiz', 'E2E grading layout', [TEXT_Q])
	const lid = lay.polls[0].id
	await api('POST', '/' + lay.code + '/ai-help', { on: true })
	await api('POST', '/' + lay.code + '/current', { pollId: lid })
	const long = ' which is a rather long answer that goes on and on about the city'.repeat(2)
	for (const [i, v] of ['Berlinn' + long, 'Bonn', 'Hamburg' + long, 'Munich'].entries()) await answer(lay.code, lid, 'L' + i, v)
	await checked(lay.code, lid, 4)
	await api('POST', '/' + lay.code + '/polls/' + lid + '/grade-ai', { action: 'start' })
	await until(async () => (await api('GET', '/' + lay.code + '/polls/' + lid + '/results')).moderation?.grading?.state === 'ready', 'the proposals are ready', 30000)
	for (const [device, scheme] of [['laptop', 'light'], ['laptop', 'dark'], ['tablet', 'light'], ['tablet', 'dark']]) {
		const p = await world.page(device, scheme, { moderator: true, label: 'desk-' + device + '-' + scheme })
		await p.goto(APP + '/room/' + lay.code)
		await closeJoinPanel(p)
		await until(async () => await p.locator('.text-grading .tg-ai').count() === 4, p.e2eLabel + ' shows four proposals')
		await expectLayout(p, '.pulse-mod', 'grading proposals')
		for (const loc of [p.locator('.text-grading .tg-ai').first(), p.locator('.grading-suggestion').getByRole('button', { name: 'Accept all (4)' })]) {
			const box = await loc.boundingBox()
			expectEqual(!!box && box.height >= 44, true, p.e2eLabel + ': target at least 44 px high')
		}
	}
}
