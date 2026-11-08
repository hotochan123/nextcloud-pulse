// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// ai: the AI quiz draft of the composer against the fake text-generation provider of dev/e2e/fakeai (run.sh
// enables it and starts the task worker). No AI button in a poll; in a quiz a topic gives a preview of six
// questions, "Try again" a new one, an unusable answer a clear message; the ticked questions land in the deck
// with their solutions and open in the composer like hand-made ones. A file of the account's Files is read
// through the same route (API).

import { api, appApi, dav, expect, expectLayout, until } from '../lib/harness.mjs'
import { confirmAiNotice, expectEqual, startRoom } from '../lib/flows.mjs'

const AI_BUTTON = { name: 'Draft questions with AI' }

// A new room opens with the composer already open; otherwise open it (empty deck or not).
async function openComposer(page) {
	const composer = page.locator('.composer')
	const opener = page.getByRole('button', { name: 'Add the first question' }).or(page.getByRole('button', { name: '+ Add question' }))
	await composer.or(opener).first().waitFor()
	if (!(await composer.isVisible())) await opener.first().click()
	await composer.waitFor()
}

export async function ai(world) {
	await confirmAiNotice()
	const avail = await appApi('GET', '/api/1.0/ai')
	expect(avail.available === true, 'a text-generation provider is set up (dev/e2e/fakeai enabled by run.sh)', avail)

	const page = await world.page('laptop', 'light', { moderator: true, label: 'desk' })

	// a poll has no AI button
	const pollCode = await startRoom(page, 'poll')
	world.track(pollCode)
	await openComposer(page)
	expect(await page.locator('.composer').getByRole('button', AI_BUTTON).count() === 0, 'no AI button in a poll')

	// a quiz has it
	const code = await startRoom(page, 'quiz')
	world.track(code)
	await openComposer(page)
	await page.locator('.composer').getByRole('button', AI_BUTTON).click()
	const dialog = page.getByRole('dialog', AI_BUTTON)
	await dialog.waitFor()
	await expectLayout(page, '.pulse-mod', 'composer with the AI button')

	// an answer without JSON: a clear message, the topic stays
	const topic = dialog.getByRole('textbox', { name: 'Topic' })
	await topic.fill('BROKEN topic')
	await dialog.getByRole('button', { name: 'Draft questions', exact: true }).click()
	await until(async () => (await dialog.getByRole('alert').textContent().catch(() => '')).includes('could not be used'), 'message for an unusable answer', 30000)
	expectEqual(await topic.inputValue(), 'BROKEN topic', 'the topic stays after the message')

	// a usable answer: six questions in the preview
	await topic.fill('Volcanoes')
	await dialog.getByRole('button', { name: 'Draft questions', exact: true }).click()
	const items = dialog.locator('.aid-q')
	await until(async () => await items.count() === 6, 'preview with six questions', 30000)
	await expectLayout(page, '.aid', 'ai preview')
	const first = await items.nth(0).locator('.aid-q-text').textContent()
	expect(first.startsWith('Volcanoes – question 1'), 'the drafted question is about the topic', first)

	// "Try again" asks anew
	await dialog.getByRole('button', { name: 'Try again' }).click()
	await until(async () => await items.count() === 6 && (await items.nth(0).locator('.aid-q-text').textContent()) !== first, 'a new draft after "Try again"', 30000)
	const texts = await items.locator('.aid-q-text').allTextContents()

	// untick the second, add the other five
	await items.nth(1).getByRole('checkbox').uncheck()
	await dialog.getByRole('button', { name: 'Add 5 questions' }).click()
	await dialog.waitFor({ state: 'detached' })
	await until(async () => await page.locator('.deck-item').count() === 5, 'five questions in the deck')
	expect(!(await page.locator('.composer').isVisible()), 'the composer closes after adding')

	const kept = [0, 2, 3, 4, 5]
	const room = await api('GET', '/' + code)
	expectEqual(room.polls.map((p) => p.question), kept.map((i) => texts[i]), 'deck holds the ticked questions in order')
	room.polls.forEach((p, k) => {
		const i = kept[k] + 1 // question number of the fake provider
		const opts = p.options.map((o) => o.label)
		expectEqual(opts, ['A', 'B', 'C', 'D'].map((l) => `Answer ${i}${l}`), `question ${i}: options`)
		if (i <= 4) {
			expectEqual(p.type, 'choice', `question ${i}: single choice`)
			expectEqual(p.correctOption, p.options[(i - 1) % 4].id, `question ${i}: correct option`)
		} else {
			expectEqual(p.type, 'multi', `question ${i}: multiple answers`)
			expectEqual([...(p.answerKey.correct || [])].sort(), [p.options[0].id, p.options[2].id].sort(), `question ${i}: correct options`)
		}
	})

	// a drafted question opens in the composer like a hand-made one, its solution marked
	await page.locator('.deck-item .deck-q').nth(4).click()
	const composer = page.locator('.composer')
	await composer.waitFor()
	expectEqual(await composer.getByPlaceholder('Your question …').inputValue(), texts[5], 'composer shows the drafted question')
	const marks = await composer.locator('.opt-correct').evaluateAll((els) => els.map((el) => el.classList.contains('is-on')))
	expectEqual(marks, [true, false, true, false], 'composer marks the drafted solution')
	expect(await composer.getByRole('button', AI_BUTTON).count() === 0, 'no AI button while editing a question')

	// the dialog on the tablet in dark mode (pen and finger): nothing runs past the screen
	const tab = await world.page('tablet', 'dark', { moderator: true, label: 'tablet-dark' })
	await tab.goto(page.url())
	await openComposer(tab)
	await tab.locator('.composer').getByRole('button', AI_BUTTON).click()
	const tabDialog = tab.getByRole('dialog', AI_BUTTON)
	await tabDialog.getByRole('textbox', { name: 'Topic' }).fill('A very long topic about the slow movement of continental plates over millions of years')
	await tabDialog.getByRole('button', { name: 'Draft questions', exact: true }).click()
	await until(async () => await tabDialog.locator('.aid-q').count() === 6, 'preview on the tablet', 30000)
	await expectLayout(tab, '.aid', 'ai preview tablet dark')
	await tabDialog.getByRole('button', { name: 'Cancel' }).click()
	await tabDialog.waitFor({ state: 'detached' })

	// a file of the account's Files: only its text goes to the AI
	await dav('PUT', '/ai-notes.txt', 'Photosynthesis\nPlants turn light, water and carbon dioxide into sugar and oxygen.')
	try {
		const { task } = await api('POST', '/' + code + '/ai/draft', { path: '/ai-notes.txt', count: 5 })
		let state
		await until(async () => {
			state = await api('GET', '/' + code + '/ai/draft/' + task)
			return state.status !== 'running'
		}, 'the draft from a file finishes', 30000)
		expectEqual(state.status, 'done', 'draft from a file')
		expect(state.questions[0].question.startsWith('Photosynthesis – question 1'), 'the file\'s text reached the AI', state.questions[0])
		let missing = ''
		await api('POST', '/' + code + '/ai/draft', { path: '/no-such-file.txt' }).catch((e) => { missing = e.message })
		expect(missing.includes('-> 404'), 'a missing file is a 404', missing)
	} finally {
		await dav('DELETE', '/ai-notes.txt').catch(() => {})
	}
}
