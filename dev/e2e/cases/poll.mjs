// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// poll: the poll room (composed by "compose", else built through the API) runs live. One phone joins through
// the code page, one through the direct link (light and dark); both answer every question with a tap or a
// gesture; phone 1 changes its vote on the first question. The server's tally, the projector's answer count and
// each phone's own answer after the reveal carry the expected values.

import { APP, api, until } from '../lib/harness.mjs'
import { POLL_QS, answer, closeJoinPanel, expectEqual, joinByCode, primary, waitQuestion } from '../lib/flows.mjs'

// per question: [phone 1, phone 2]
const ANSWERS = [
	['Red', 'Green'],
	['coffee', 'Coffee '],
	[4, 2],
	[5, 1],
	[{ fx: 0.9, fy: 0.9 }, { fx: 0.1, fy: 0.1 }],
	[['Quality', 'Time', 'Cost'], ['Cost', 'Time', 'Quality']],
	[{ France: 'Paris', Italy: 'Rome' }, { France: 'Rome', Italy: 'Paris' }],
]

export async function pollRoom(world, shared) {
	// the room composed in the browser, once; a repetition builds its own
	const composed = shared.poll
	if (composed) {
		delete shared.poll
		world.track(composed.code)
		return composed
	}
	return world.room('poll', 'E2E poll', POLL_QS)
}

const results = (code, id) => api('GET', `/${code}/polls/${id}/results`)

// What the server must have counted from those taps and gestures (phone 1 after its change of mind)
const counted = (r) => r.results.map((x) => x.label + ':' + x.count)
const EXPECT = [
	(r) => expectEqual(counted(r), ['Red:0', 'Green:1', 'Blue:1'], 'choice tally after the changed vote'),
	(r) => expectEqual(r.results.map((x) => x.word + ':' + x.count), ['coffee:2'], 'word cloud: one word, twice'),
	(r) => expectEqual(r.results.filter((x) => x.count).map((x) => x.value), [2, 4], 'scale: the two values set by tap'),
	(r) => expectEqual([r.results[0].label, r.results[0].min, r.results[0].max], ['Pace', 1, 5], 'spectrum: the first slider moved'),
	(r) => expectEqual(r.points.map((pt) => Math.sign(pt.x) + ',' + Math.sign(pt.y)), ['1,1', '-1,-1'], 'compass: top right and bottom left'),
	(r) => expectEqual(r.results.map((x) => x.label + ':' + x.places.join('')), ['Cost:101', 'Quality:101', 'Time:020'], 'ranking places'),
	(r) => expectEqual(r.results.map((x) => x.label + ':' + x.targets.map((t) => t.count).join('')), ['France:11', 'Italy:11'], 'matching pairs'),
]

export async function poll(world, shared) {
	const { code, polls } = await pollRoom(world, shared)
	const desk = await world.page('laptop', 'light', { moderator: true, label: 'desk' })
	const beamer = await world.page('projector', 'dark', { label: 'projector' })
	const p1 = await world.page('phone', 'light', { label: 'phone1' })
	const p2 = await world.page('phone', 'dark', { label: 'phone2' })
	await desk.goto(APP + '/room/' + code)
	await beamer.goto(APP + '/screen/' + code)
	await joinByCode(p1, code)
	await p2.goto(APP + '/s/' + code)
	await desk.getByRole('button', { name: 'Start presenting' }).click()
	await closeJoinPanel(desk)

	for (let i = 0; i < POLL_QS.length; i++) {
		const q = POLL_QS[i]
		const id = polls[i].id
		await waitQuestion(p1, q)
		await waitQuestion(p2, q)
		await answer(p1, q, ANSWERS[i][0])
		await answer(p2, q, ANSWERS[i][1])
		if (i === 0) {
			// phone 1 changes its mind: Red → Blue
			await p1.locator('.h-submit').getByRole('button', { name: 'Change answer' }).click()
			await answer(p1, q, 'Blue')
		}
		const tally = await until(async () => {
			const r = await results(code, id)
			return r.total === 2 && r
		}, `"${q.question}": 2 answers on the server`)
		EXPECT[i](tally)
		await until(async () => /\b2\b/.test(await beamer.locator('.scr').innerText()), `projector counts 2 answers on "${q.question}"`)
		await closeJoinPanel(desk)
		await primary(desk, 'Reveal')
		for (const p of [p1, p2]) await until(() => p.locator('.mine-box').isVisible(), `${p.e2eLabel} sees its answer after the reveal of "${q.question}"`)
		if (i === 0) {
			expectEqual(await p1.locator('.mine-v').innerText(), 'Blue', 'phone 1 shows its changed vote')
			expectEqual(await p2.locator('.mine-v').innerText(), 'Green', 'phone 2 shows its vote')
		}
		await primary(desk, i < POLL_QS.length - 1 ? 'Next question' : 'Finish')
	}
}
