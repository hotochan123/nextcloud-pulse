// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// summary: the AI summary on the results overview against the fake text-generation provider of dev/e2e/fakeai
// (its key points end with "Seen: …", the answers that went out). A moderated quiz with four players (API phones),
// one free-text answer hidden on the desk. No button while "AI help" is off; switched on, the button writes a
// text with Pulse's numbers, the AI's key points (without the hidden answer) and the top 3 of the leaderboard;
// "Try again" writes a new one; the text edited by hand is what "Save" puts into the folder "Pulse", "Open in
// Files" links it, a second save gets a number. In a self-paced quiz the deck menu offers "AI help" and the
// summary its button. Runs on a tablet in dark mode (touch).

import { APP, ApiPhone, api, dav, expect, expectLayout, until } from '../lib/harness.mjs'
import { confirmAiNotice, expectEqual } from '../lib/flows.mjs'

const QS = [
	{ type: 'choice', question: 'Capital of France?', options: ['Berlin', 'Paris', 'Rome'], correctIndex: 1, timeLimit: 60 },
	{ type: 'text', question: 'What did you like most?', answers: ['pizza'], timeLimit: 60 },
]
// nickname, choice option, free text
const PLAYERS = [['Ada', 1, 'The pizza'], ['Ben', 1, 'the pizza'], ['Cleo', 0, 'Music'], ['Dan', 2, 'rudeword']]
const BUTTON = { name: 'Summary with AI' }

async function roomMenu(page, item, role = 'menuitem') {
	// the deck's header, or the live view's top bar (a room that ran opens there)
	await page.locator('.deck-head, .mod-top').first().getByRole('button', { name: 'More actions' }).click()
	await page.getByRole('menu').getByRole(role, { name: item, exact: true }).click()
	// a checkbox item leaves the menu open
	if (await page.getByRole('menu').isVisible().catch(() => false)) await page.keyboard.press('Escape')
}

async function openSummary(page) {
	await roomMenu(page, 'Summary')
	await page.locator('.summary-view').waitFor()
}

export async function summary(world) {
	await confirmAiNotice()
	const { code, polls } = await world.room('quiz', 'E2E summary', QS)
	const phones = []
	for (const [name] of PLAYERS) {
		const ph = new ApiPhone(code)
		await ph.join(name)
		phones.push(ph)
	}
	for (const [i, poll] of polls.entries()) {
		await api('POST', `/${code}/current`, { pollId: poll.id })
		for (const [k, ph] of phones.entries()) {
			await ph.req('GET', '/state')
			const value = i === 0 ? poll.options[PLAYERS[k][1]].id : PLAYERS[k][2]
			await ph.req('POST', '/vote', { pollId: poll.id, value })
		}
		await api('POST', `/${code}/polls/${poll.id}/lock`)
	}
	await api('POST', `/${code}/end`)
	await api('POST', `/${code}/polls/${polls[1].id}/moderate`, { answer: 'rudeword', action: 'hide' })

	const desk = await world.page('tablet', 'dark', { moderator: true, label: 'desk' })
	await desk.goto(APP + '/room/' + code)
	await openSummary(desk)
	expect(await desk.getByRole('button', BUTTON).count() === 0, 'no AI summary button while "AI help" is off')

	await desk.getByRole('button', { name: 'Back to the deck' }).click()
	await roomMenu(desk, 'AI help', 'menuitemcheckbox')
	await until(async () => (await api('GET', '/' + code)).aiHelp === true, 'the room has AI help on')
	await openSummary(desk)
	await desk.getByRole('button', BUTTON).click()

	const area = desk.getByRole('textbox', { name: 'Summary (Markdown)' })
	await until(async () => (await area.inputValue().catch(() => '')).includes('Point A of question 2'), 'the summary text with key points', 30000)
	await expectLayout(desk, '.pulse-mod', 'summary panel on a tablet')
	const text = await area.inputValue()
	expect(/^# E2E summary – Summary \d{4}-\d{2}-\d{2}\n/.test(text), 'the heading names the room and the date', text.slice(0, 80))
	expect(text.includes('- Berlin: 1 (25%)\n- Paris: 2 (50%)\n- Rome: 1 (25%)'), 'the numbers of the choice question come from Pulse', text)
	expect(text.includes('- 4 answers\n- The pizza: 2 (50%)\n- Music: 1 (25%)'), 'the free-text numbers without the hidden answer', text)
	expect(text.includes('- Seen: The pizza, Music\n'), 'only the visible answers went to the AI', text)
	expect(!/rudeword/i.test(text), 'the hidden answer is nowhere in the summary', text)
	const board = text.slice(text.indexOf('## Leaderboard'))
	expect(text.includes('## Leaderboard'), 'a quiz has its leaderboard', text)
	expectEqual(board.split('\n').filter((l) => /^\d+\. /.test(l)).length, 3, 'top 3 of the leaderboard')
	expect(/^1\. (Ada|Ben) – \d+ points$/m.test(board), 'the winner with nickname and points', board)
	expect(!board.includes('Dan'), 'fourth place is not in the summary', board)

	// "Try again": a new text
	const run = /run (\d+)/.exec(text)[1]
	await desk.locator('.ais').getByRole('button', { name: 'Try again' }).click()
	await until(async () => {
		const v = await area.inputValue().catch(() => '')
		return v.includes('Point A of question 2') && !v.includes(`run ${run})`)
	}, 'a new text after "Try again"', 30000)

	// edited by hand, then saved twice
	const saved = []
	try {
		for (const round of [1, 2]) {
			if (round === 2) {
				await desk.locator('.ais').getByRole('button', { name: 'Close' }).click()
				await desk.getByRole('button', BUTTON).click()
				await until(async () => (await area.inputValue().catch(() => '')).includes('Point A'), 'the summary again', 30000)
			}
			const edited = (await area.inputValue()) + `\nEdited by hand ${round}\n`
			await area.fill(edited)
			await desk.locator('.ais').getByRole('button', { name: 'Save' }).click()
			const link = desk.locator('.ais').getByRole('link', { name: 'Open in Files' })
			await link.waitFor()
			const name = await link.getAttribute('title')
			saved.push(name)
			expect(/\/f\/\d+$/.test(await link.getAttribute('href')), `save ${round}: the link opens the file in Files`, await link.getAttribute('href'))
			expect(/^E2E summary – Summary \d{4}-\d{2}-\d{2}( \(\d+\))?\.md$/.test(name), `save ${round}: the file name`, name)
			expectEqual(await dav('GET', '/Pulse/' + name), edited, `save ${round}: the file holds the edited text`)
		}
		expect(saved[0] !== saved[1] && / \(\d+\)\.md$/.test(saved[1]), 'the second save gets a new name with a number', saved)
	} finally {
		for (const name of saved) await dav('DELETE', '/Pulse/' + name).catch(() => {})
	}
	expect(desk.e2eErrors.length === 0, 'no script errors on the desk', desk.e2eErrors)

	// self-paced: the switch sits in the deck menu, the summary gets its button
	const paced = await world.room('quiz', 'E2E summary paced', [QS[0]])
	await api('POST', `/${paced.code}/pace`, { action: 'set', pace: 'self' })
	await desk.goto(APP + '/room/' + paced.code)
	await roomMenu(desk, 'AI help', 'menuitemcheckbox')
	await until(async () => (await api('GET', '/' + paced.code)).aiHelp === true, 'the self-paced room has AI help on')
	await openSummary(desk)
	await desk.getByRole('button', BUTTON).waitFor()
	await desk.getByRole('button', BUTTON).click()
	await until(async () => (await area.inputValue().catch(() => '')).startsWith('# E2E summary paced'), 'a self-paced summary without free text comes at once', 30000)
	await desk.locator('.ais').getByRole('button', { name: 'Cancel' }).click()
	await desk.locator('.ais').waitFor({ state: 'detached' })
}
