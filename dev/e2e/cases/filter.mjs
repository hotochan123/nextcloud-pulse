// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// filter: the filter before the projector, with the fake AI provider (dev/e2e/fakeai: flags "badword", answers
// "slowpoke" only after 15 s). The desk switches "AI help" on and the phone says so; a fine word reaches the
// projector after the check, a flagged one stays off it until the desk taps "Show"; "Hide" on the desk takes a
// word out of the projector's cloud; a check that takes too long lets its word through after about 10 s and the
// desk says "AI check not available right now".

import { APP, ApiPhone, api, until } from '../lib/harness.mjs'
import { answer, closeJoinPanel, confirmAiNotice, expectEqual, waitQuestion } from '../lib/flows.mjs'

const Q = { type: 'words', question: 'One word for the team', maxWords: 3 }

async function vote(code, pollId, words) {
	const ph = new ApiPhone(code)
	await ph.req('GET', '/state')
	await ph.req('POST', '/vote', { pollId, value: words })
}

export async function filter(world) {
	await confirmAiNotice()
	const { code, polls } = await world.room('poll', 'E2E filter', [Q])
	const id = polls[0].id
	const desk = await world.page('laptop', 'light', { moderator: true, label: 'desk' })
	const beamer = await world.page('projector', 'dark', { label: 'projector' })
	const phone = await world.page('phone', 'light', { label: 'phone' })
	await desk.goto(APP + '/room/' + code)
	await beamer.goto(APP + '/screen/' + code)
	await phone.goto(APP + '/s/' + code)
	await desk.getByRole('button', { name: 'Start presenting' }).click()
	await closeJoinPanel(desk)
	await waitQuestion(phone, Q)

	// The switch, from the desk's menu; the phone shows the notice before anyone answers.
	await desk.locator('.mod-top').getByRole('button', { name: 'More actions' }).click()
	await desk.getByRole('menu').getByRole('menuitemcheckbox', { name: 'AI help' }).click()
	// a checkbox item leaves the menu open; it would lie over the panel
	if (await desk.getByRole('menu').isVisible()) await desk.keyboard.press('Escape')
	await until(async () => (await api('GET', '/' + code)).aiHelp === true, 'the room has AI help on')
	await until(() => phone.getByText('Free-text answers are evaluated with AI').isVisible(), 'the phone says that answers are evaluated with AI')

	const cloud = () => beamer.locator('.scr').innerText()
	await answer(phone, Q, 'sunshine')
	await vote(code, id, ['badword', 'teamwork'])
	await until(async () => /sunshine/i.test(await cloud()) && /teamwork/i.test(await cloud()), 'checked words reach the projector', 30000)
	const shown = await cloud()
	expectEqual(/badword/i.test(shown), false, 'the flagged word is not on the projector')

	// The desk sees the flagged word and lets it through.
	const row = (word) => desk.locator('.af-row').filter({ hasText: word })
	await until(() => row('badword').getByText('AI flagged').isVisible(), 'the desk marks the flagged word')
	await row('badword').getByRole('button', { name: 'Show “badword” on the projector' }).click()
	await until(async () => /badword/i.test(await cloud()), '"Show" brings the word to the projector')

	// Hide takes a word out of the projector's cloud; the CSV keeps it, marked.
	await row('teamwork').getByRole('button', { name: 'Hide “teamwork” from the projector' }).click()
	await until(async () => !/teamwork/i.test(await cloud()), '"Hide" takes the word off the projector')
	await until(() => row('teamwork').getByText('Hidden').isVisible(), 'the desk shows the word as hidden')

	// A check that takes too long: the word goes through after the 10 seconds, the desk says so.
	await vote(code, id, ['slowpoke'])
	await until(() => row('slowpoke').isVisible(), 'the desk lists the new word')
	expectEqual(/slowpoke/i.test(await cloud()), false, 'the word waits for the check')
	await until(async () => /slowpoke/i.test(await cloud()), 'the word goes through unchecked after 10 s', 25000)
	await until(() => desk.getByText('AI check not available right now').isVisible(), 'the desk says the AI check is not available')
}
