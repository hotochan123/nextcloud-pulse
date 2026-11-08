// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// themes: "Group with AI" with the fake AI provider (dev/e2e/fakeai: one theme per first letter, "Theme <letter>";
// an answer "failgroup" makes the grouping fail). Only polls group, and a poll's free answers are word clouds (free
// text exists only in quizzes). Word cloud in a poll: the desk switches "AI help" on and the phone
// says so; "Group with AI" shows the themes with counts and an example on the desk while the projector does not show
// them; "Show themes on projector" puts them on the projector, "Back to answers" takes them off. Quiz: with "AI help"
// on and checked free-text answers the desk lists the answers but offers no "Group with AI", the route answers 400,
// and the projector shows no themes after the reveal. A failing grouping
// makes the desk say "AI grouping not available right now"; after hiding the failing word a released grouping
// replaces the cloud on the projector. Layout: six themes with titles past the 60-character cut and examples at the
// 40-character cut of a word
// stay on the projector stage (the four windows of the size sweep of the case projector, light and dark), the desk
// with its theme list fits laptop and tablet (touch), light and dark.

import { APP, ApiPhone, api, expectLayout, until } from '../lib/harness.mjs'
import { closeJoinPanel, confirmAiNotice, expectEqual, primary, waitQuestion } from '../lib/flows.mjs'
import { WINDOWS } from './projector.mjs'

const DRINK_Q = { type: 'words', question: 'What do you drink in the morning?', maxWords: 3 }
const QUIZ_Q = { type: 'text', question: 'What is the capital of Australia?', answers: ['Canberra'], timeLimit: 120 }
const WORDS_Q = { type: 'words', question: 'One word for the summer', maxWords: 3 }

// The AI check of new answers (filter before the projector) is done: only checked answers are grouped.
async function checked(code, pollId) {
	await until(async () => {
		const items = (await api('GET', '/' + code + '/polls/' + pollId + '/results')).moderation?.items || []
		return items.length > 0 && items.every((i) => i.state === 'visible')
	}, 'the AI check of the answers is done', 30000)
}

async function switchAiHelp(desk) {
	await desk.locator('.mod-top').getByRole('button', { name: 'More actions' }).click()
	await desk.getByRole('menu').getByRole('menuitemcheckbox', { name: 'AI help' }).click()
	// a checkbox item leaves the menu open; it would lie over the panel
	if (await desk.getByRole('menu').isVisible()) await desk.keyboard.press('Escape')
}

export async function themes(world) {
	await confirmAiNotice()
	const { code, polls } = await world.room('poll', 'E2E themes', [DRINK_Q])
	const desk = await world.page('laptop', 'light', { moderator: true, label: 'desk' })
	const beamer = await world.page('projector', 'dark', { label: 'projector' })
	const phone = await world.page('phone', 'light', { label: 'phone' })
	await desk.goto(APP + '/room/' + code)
	await beamer.goto(APP + '/screen/' + code)
	await phone.goto(APP + '/s/' + code)
	await desk.getByRole('button', { name: 'Start presenting' }).click()
	await closeJoinPanel(desk)
	await waitQuestion(phone, DRINK_Q)

	await switchAiHelp(desk)
	await until(async () => (await api('GET', '/' + code)).aiHelp === true, 'the room has AI help on')
	await until(() => phone.getByText('Free-text answers are evaluated with AI').isVisible(), 'the phone says that answers are evaluated with AI')

	const answers = ['Tea', 'Tea', 'Coffee', 'Cocoa', 'Water']
	for (let i = 0; i < answers.length; i++) {
		const ph = new ApiPhone(code)
		await ph.req('GET', '/state')
		const st = await ph.req('GET', '/state')
		await ph.req('POST', '/vote', { pollId: st.poll.id, value: [answers[i]] })
	}

	await checked(code, polls[0].id)
	const panel = desk.locator('.theme-groups')
	await until(() => panel.isVisible(), 'the desk offers "Group with AI"')
	await panel.getByRole('button', { name: 'Group with AI' }).click()
	const row = (title) => panel.locator('.thg-row').filter({ hasText: title })
	await until(() => row('Theme C').isVisible(), 'the desk shows the themes', 30000)
	expectEqual((await row('Theme C').innerText()).replace(/\s+/g, ' ').includes('2×'), true, 'two answers in "Theme C"')
	expectEqual(/“tea”/i.test(await row('Theme T').innerText()), true, 'the example is a real answer')
	expectEqual(await beamer.locator('.stage-themes').count(), 0, 'the projector does not show the themes yet')

	await panel.getByRole('button', { name: 'Show themes on projector' }).click()
	await until(async () => /Theme T/.test(await beamer.locator('.scr').innerText()), 'the projector shows the themes')
	expectEqual(await beamer.locator('.stage-themes .st-card').count(), 3, 'three theme cards on the projector')
	await panel.getByRole('button', { name: 'Back to answers' }).click()
	await until(async () => await beamer.locator('.stage-themes').count() === 0, '"Back to answers" takes the themes off the projector')

	// Quiz: no "Group with AI" on the desk, the route refuses, the projector shows no themes after the reveal.
	const q = await world.room('quiz', 'E2E themes quiz', [QUIZ_Q])
	const qid = q.polls[0].id
	await api('POST', '/' + q.code + '/ai-help', { on: true })
	await desk.goto(APP + '/room/' + q.code)
	await desk.getByRole('button', { name: 'Start presenting' }).click()
	await closeJoinPanel(desk)
	for (const [i, a] of ['Canberra', 'Sydney', 'Vienna'].entries()) {
		const ph = new ApiPhone(q.code)
		await ph.req('GET', '/state')
		await ph.join('Q' + i)
		await ph.req('POST', '/vote', { pollId: qid, value: a })
	}
	await checked(q.code, qid)
	await until(async () => /Sydney/.test(await desk.locator('.mod-private').innerText()), 'the quiz desk lists the answers')
	expectEqual(await desk.locator('.theme-groups').count(), 0, 'the quiz desk has no "Group with AI" block')
	expectEqual(await desk.getByRole('button', { name: 'Group with AI' }).count(), 0, 'the quiz desk has no "Group with AI" button')
	const refused = await api('POST', '/' + q.code + '/polls/' + qid + '/group', { action: 'start' }).then(() => '', (e) => e.message)
	expectEqual(/-> 400 .*only available in polls/.test(refused), true, 'the grouping route refuses a quiz room (400)')
	await primary(desk, 'Reveal')
	await beamer.goto(APP + '/screen/' + q.code)
	await until(async () => /Canberra/.test(await beamer.locator('.scr').innerText()), 'the quiz projector shows the reveal')
	expectEqual(await beamer.locator('.stage-themes').count(), 0, 'no themes on the quiz projector after the reveal')

	// Word cloud: a released grouping replaces the cloud on the projector.
	const w = await world.room('poll', 'E2E themes cloud', [WORDS_Q])
	await api('POST', '/' + w.code + '/ai-help', { on: true })
	await desk.goto(APP + '/room/' + w.code)
	await desk.getByRole('button', { name: 'Start presenting' }).click()
	await closeJoinPanel(desk)
	for (const words of [['Sun', 'Sea'], ['sun'], ['Ice', 'failgroup']]) {
		const ph = new ApiPhone(w.code)
		await ph.req('GET', '/state')
		await ph.req('POST', '/vote', { pollId: w.polls[0].id, value: words })
	}
	await checked(w.code, w.polls[0].id)
	const wpanel = desk.locator('.theme-groups')
	await until(() => wpanel.isVisible(), 'the desk of the word cloud offers "Group with AI"')
	await wpanel.getByRole('button', { name: 'Group with AI' }).click()
	await until(() => wpanel.getByText('AI grouping not available right now').isVisible(), 'a failing grouping: the desk says so', 30000)
	await api('POST', '/' + w.code + '/polls/' + w.polls[0].id + '/moderate', { answer: 'failgroup', action: 'hide' })
	await wpanel.getByRole('button', { name: 'Group again' }).click()
	await until(() => wpanel.locator('.thg-row').filter({ hasText: 'Theme S' }).isVisible(), 'the hidden word is left out and the grouping works', 30000)
	await wpanel.getByRole('button', { name: 'Show themes on projector' }).click()
	await beamer.goto(APP + '/screen/' + w.code)
	await until(async () => /Theme S/.test(await beamer.locator('.scr').innerText()), 'the projector shows the themes of the word cloud')
	expectEqual(/failgroup/i.test(await beamer.locator('.scr').innerText()), false, 'the hidden word is not on the projector')

	// Layout: six themes, long titles and long examples.
	const long = await world.room('poll', 'E2E themes layout', [WORDS_Q])
	const lid = long.polls[0].id
	await api('POST', '/' + long.code + '/ai-help', { on: true })
	await api('POST', '/' + long.code + '/current', { pollId: lid })
	// a word is cut to 40 characters: the longest example a theme can have
	const filler = ' with a rather long word that goes on and on'
	for (const [i, first] of ['Apple', 'Banana', 'Cherry', 'Date', 'Elder', 'Fig longtitle'].entries()) {
		const ph = new ApiPhone(long.code)
		await ph.req('GET', '/state')
		await ph.req('POST', '/vote', { pollId: lid, value: [(first + filler).slice(0, 40)] })
	}
	await checked(long.code, lid)
	await api('POST', '/' + long.code + '/polls/' + lid + '/group', { action: 'start' })
	await until(async () => (await api('GET', '/' + long.code + '/polls/' + lid + '/results')).moderation?.groups?.state === 'ready', 'the six themes are ready', 30000)
	await api('POST', '/' + long.code + '/polls/' + lid + '/group', { action: 'show' })
	for (const [device, scheme, label] of WINDOWS) {
		const p = await world.page(device, scheme, { label })
		await p.goto(APP + '/screen/' + long.code)
		await until(async () => await p.locator('.stage-themes .st-card').count() === 6, p.e2eLabel + ' shows six themes')
		await expectLayout(p, '.scr', 'six themes', true)
	}
	for (const [device, scheme] of [['laptop', 'light'], ['laptop', 'dark'], ['tablet', 'light'], ['tablet', 'dark']]) {
		const p = await world.page(device, scheme, { moderator: true, label: 'desk-' + device + '-' + scheme })
		await p.goto(APP + '/room/' + long.code)
		await closeJoinPanel(p)
		await until(async () => await p.locator('.theme-groups .thg-row').count() === 6, p.e2eLabel + ' lists six themes')
		await expectLayout(p, '.pulse-mod', 'theme list')
		const box = await p.locator('.theme-groups').getByRole('button', { name: 'Back to answers' }).boundingBox()
		expectEqual(!!box && box.height >= 44, true, p.e2eLabel + ': "Back to answers" is at least 44 px high')
	}
}
