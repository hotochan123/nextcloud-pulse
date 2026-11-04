// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// layout: the desk and the phone page on a laptop (1366×768), a tablet (820×1180, touch) and a phone (390×844,
// touch), light and dark. Desk: the room list, the deck with the composer open on its biggest form (compass),
// the live view. Phone page: the code entry, a poll question, the poll result after the reveal, the quiz
// verdict. In every state nothing runs past the right edge and the background follows the colour scheme.

import { APP, api, expectLayout, expectScheme, until } from '../lib/harness.mjs'
import { answer, closeJoinPanel, pickName, waitQuestion } from '../lib/flows.mjs'

const COMBOS = ['laptop', 'tablet', 'phone'].flatMap((d) => ['light', 'dark'].map((s) => [d, s]))
const CHOICE = { type: 'choice', question: 'Which option fits a long question that wraps onto a second line on a phone?', options: ['A rather long first option that needs room', 'Short', 'Third option'] }
const QUIZ = { type: 'choice', question: 'Capital of France?', options: ['Berlin', 'Paris', 'Rome'], correctIndex: 1, timeLimit: 90 }

async function check(page, root, what, scheme) {
	await expectLayout(page, root, what)
	await expectScheme(page, root, scheme, what)
}

export async function layout(world) {
	const deck = await world.room('poll', 'E2E layout deck', [CHOICE])
	const live = await world.room('poll', 'E2E layout live', [CHOICE])
	const quiz = await world.room('quiz', 'E2E layout quiz', [QUIZ])
	await api('POST', `/${live.code}/current`, { pollId: live.polls[0].id })
	await api('POST', `/${quiz.code}/current`, { pollId: quiz.polls[0].id })

	const phones = []
	for (const [device, scheme] of COMBOS) {
		const desk = await world.page(device, scheme, { moderator: true, label: `${device}-${scheme}-desk` })
		await desk.goto(APP + '/')
		await until(() => desk.locator('.myrooms').isVisible(), 'room list')
		await check(desk, '.pulse-mod', 'room list', scheme)
		await desk.goto(APP + '/room/' + deck.code)
		await desk.getByRole('button', { name: '+ Add question' }).click()
		await desk.locator('.composer').getByRole('radio', { name: 'Scale', exact: true }).click()
		await desk.locator('.composer').getByRole('radio', { name: 'Compass' }).click()
		await check(desk, '.pulse-mod', 'deck with the composer', scheme)
		await desk.goto(APP + '/room/' + live.code)
		await until(() => desk.locator('.mod-live').isVisible(), 'live view')
		await closeJoinPanel(desk)
		await check(desk, '.pulse-mod', 'live view', scheme)
		await desk.close()

		const phone = await world.page(device, scheme, { label: `${device}-${scheme}-phone` })
		await phone.goto(APP + '/join')
		await check(phone, '.pulse-part', 'code entry', scheme)
		await phone.goto(APP + '/s/' + live.code)
		await waitQuestion(phone, CHOICE)
		await check(phone, '.pulse-part', 'poll question', scheme)
		await answer(phone, CHOICE, CHOICE.options[0])
		const player = await world.page(device, scheme, { label: `${device}-${scheme}-player` })
		await player.goto(APP + '/s/' + quiz.code)
		await pickName(player, `Player ${phones.length + 1} with a long name`)
		await waitQuestion(player, QUIZ)
		await answer(player, QUIZ, phones.length % 2 === 0, { quiz: true })
		phones.push([phone, player, scheme])
	}

	await api('POST', `/${live.code}/polls/${live.polls[0].id}/lock`)
	await api('POST', `/${quiz.code}/polls/${quiz.polls[0].id}/lock`)
	for (const [phone, player, scheme] of phones) {
		await until(() => phone.locator('.mine-box').isVisible(), `${phone.e2eLabel} poll result`)
		await check(phone, '.pulse-part', 'poll result', scheme)
		await until(() => player.locator('.verdict').isVisible(), `${player.e2eLabel} verdict`)
		await check(player, '.pulse-part', 'quiz verdict', scheme)
	}
}
