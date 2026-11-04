// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// projector: four projector windows (1920×1080 and 1366×768, each light and dark) follow a poll and a quiz with
// every question type, driven through the API and filled with the desk's demo votes: the lobby, each question
// open and revealed, the final standings, then a self-paced race with three players. In every state nothing
// runs past the 16:9 stage and the page does not scroll.

import { APP, ApiPhone, api, expectLayout, expectScheme, settled, until } from '../lib/harness.mjs'
import { POLL_QS, QUIZ_QS } from '../lib/flows.mjs'

const WINDOWS = [['projector', 'light'], ['projector', 'dark'], ['projectorSmall', 'light'], ['projectorSmall', 'dark']]

async function screens(world, code) {
	const pages = []
	for (const [device, scheme] of WINDOWS) {
		const p = await world.page(device, scheme, { label: `${device}-${scheme}` })
		await p.goto(APP + '/screen/' + code)
		await until(() => p.locator('.scr').isVisible(), `${p.e2eLabel} shows the projector`)
		p.e2eScheme = scheme
		pages.push(p)
	}
	return pages
}

async function all(pages, change, what) {
	await Promise.all(pages.map((p, i) => settled(p, i === 0 ? change : async () => {})))
	for (const p of pages) await expectLayout(p, '.scr', what, true)
}

export async function projector(world) {
	for (const [mode, qs] of [['poll', POLL_QS], ['quiz', QUIZ_QS]]) {
		const { code, polls } = await world.room(mode, 'E2E projector ' + mode, qs)
		const pages = await screens(world, code)
		for (const p of pages) {
			await expectLayout(p, '.scr', `${mode} lobby`, true)
			await expectScheme(p, '.scr', p.e2eScheme, `${mode} lobby`)
		}
		for (const [i, poll] of polls.entries()) {
			const tag = `${mode} ${i + 1} ${poll.type}${poll.scale ? '/' + poll.scale.mode : ''}`
			await all(pages, async () => {
				await api('POST', `/${code}/current`, { pollId: poll.id })
				await api('POST', `/${code}/demo`, { count: 40 })
			}, tag + ' open')
			await all(pages, () => api('POST', `/${code}/polls/${poll.id}/lock`), tag + ' revealed')
		}
		if (mode === 'quiz') await all(pages, () => api('POST', `/${code}/end`), 'quiz final standings')
		for (const p of pages) await p.close()
	}

	// the self-paced race: three players on their way
	const { code } = await world.room('quiz', 'E2E projector race', QUIZ_QS)
	await api('POST', `/${code}/pace`, { action: 'set', pace: 'self' })
	await api('POST', `/${code}/pace`, { action: 'open' })
	const pages = await screens(world, code)
	await all(pages, async () => {
		for (const name of ['Ada', 'Ben', 'Cleo-Marie Longname']) {
			const ph = new ApiPhone(code)
			await ph.join(name)
			await ph.next(0)
		}
	}, 'race')
	for (const p of pages) await until(() => p.locator('.scr.is-race').isVisible(), `${p.e2eLabel} shows the race`)
	for (const p of pages) await expectLayout(p, '.scr', 'race', true)
}
