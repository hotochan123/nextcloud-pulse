// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// stressPoll, stressQuiz, stressBoard: the stress catalog (dev/e2e/stress/catalog.mjs, STRESS.md) on the three
// faces. Each case builds its rooms from the catalog through the API, reads every text back (stored as written),
// then steps through the questions and their states (open, answered, revealed, empty; final standings at the end
// of a quiz room) and checks the views of the plan of this run (dev/e2e/stress/sweep.mjs): the projector at
// 1280×720 and one more size of the sweep, the desk and the phone on one device each, light and dark, through
// expectLayout with the text checks (no text over text, no line cut in part). The projector pages are resized
// between sizes, as a window is. A catalog case marked `open` reports its findings as "known" and stays green.

import { APP, ApiPhone, api, expect, expectLayout, expectScheme, expectSplitOrder, expectUncut, expectWholeQuestion, screenDevice, settled, until, uploadImage } from '../lib/harness.mjs'
import { answer, closeJoinPanel, pickName } from '../lib/flows.mjs'
import { BOARD_NAMES, CASES, ROOMS } from '../stress/catalog.mjs'
import { MIN, defaultSeed, plan } from '../stress/sweep.mjs'
import { stripes } from '../stress/png.mjs'

export const SEED = Number(process.env.PULSE_E2E_SEED) || defaultSeed(new Date(), process.pid)
export const PLAN = plan(SEED, CASES.map((c) => c.id), { all: process.env.PULSE_E2E_SWEEP === 'all', smallest: CASES.filter((c) => c.sizes === 'smallest').map((c) => c.id) })
/** Findings of catalog cases marked `open`: printed by e2e.mjs as "known <id>: …". */
export const KNOWN = []
/** PULSE_E2E_COLLECT=1: every finding is listed as a known one and the run goes on (for fixing many at once). */
const COLLECT = process.env.PULSE_E2E_COLLECT === '1'
/** PULSE_E2E_CATALOG=S1.3,S2.2: only these catalog cases (for fixing one at a time). */
const ONLY = (process.env.PULSE_E2E_CATALOG || '').split(',').map((s) => s.trim()).filter(Boolean)

const VIEWS = ['projector', 'desk', 'phone']
const ROOTS = { projector: '.scr', desk: '.pulse-mod', phone: '.pulse-part' }
const locale = (scheme) => (scheme === 'dark' ? 'de-DE' : 'en-US')
const labels = (list) => (list || []).map((o) => o.label)

/** What the server keeps differs from the catalog: a list of differences (empty = stored as written). */
function storedDiff(c, poll) {
	const q = c.q
	const out = []
	const same = (what, want, got) => { if (JSON.stringify(want) !== JSON.stringify(got)) out.push(`${what}: ${JSON.stringify(got).slice(0, 80)} instead of ${JSON.stringify(want).slice(0, 80)}`) }
	same('question', c.stored ?? q.question, poll.question)
	if (q.options) same('options', q.options, labels(poll.options))
	if (q.explanation) same('explanation', q.explanation, poll.explanation)
	if (q.pairs) {
		same('pairs left', q.pairs.map((p) => p.left), labels(poll.match?.items))
		same('pairs right', q.pairs.map((p) => p.right), labels(poll.match?.targets))
	}
	if (q.scaleMode === 'single') same('scale labels', [q.minLabel, q.maxLabel], [poll.scale?.minLabel, poll.scale?.maxLabel])
	if (q.aspects) same('aspects', q.aspects.map((a) => a.label), (poll.scale?.aspects || []).map((a) => a.label))
	if (q.axisX) same('axes', [q.axisX, q.axisY], [poll.scale?.axisX, poll.scale?.axisY])
	return out
}

/** The vote value of a catalog answer for the public API. */
function value(poll, a) {
	const ids = (poll.options || []).map((o) => o.id)
	switch (poll.type) {
	case 'choice': case 'truefalse': return ids[a]
	case 'multi': return a.map((i) => ids[i])
	default: return a
	}
}

/** PULSE_E2E_TRACE=1: the time of every step and check on stderr. */
const trace = (line) => { if (process.env.PULSE_E2E_TRACE === '1') console.error('[stress] ' + line) }
const squash = (s) => s.replace(/\s+/g, ' ').trim()
/** Whether the view under `root` shows the question at all (its first 30 characters): then it must show it whole. */
async function showsAny(p, root, poll) {
	const want = squash(poll.question).slice(0, 30)
	return p.evaluate(({ root, want }) => (document.querySelector(root)?.innerText || '').replace(/\s+/g, ' ').includes(want), { root, want })
}
/** Waits until the phone page shows the question of the poll (its first 30 characters). */
async function showsQuestion(p, poll, c) {
	const want = squash(poll.question).slice(0, 30)
	await until(async () => squash(await p.locator('h1.q').first().innerText().catch(() => '')).startsWith(want), `${p.e2eLabel} shows ${c.id}`, 30000)
}

/** The pages of one room: two projector windows (light in English, dark in German), desks and phones per device. */
class Faces {
	constructor(world, room, cases) {
		this.world = world
		this.room = room
		this.cases = cases
		this.proj = {}
		this.desk = new Map()
		this.phone = new Map()
	}

	async open(firstPoll) {
		const { world, room } = this
		for (const scheme of ['light', 'dark']) {
			const p = await world.page(screenDevice(MIN), scheme, { label: `projector-${scheme}`, locale: locale(scheme), reducedMotion: 'reduce' })
			await p.goto(APP + '/screen/' + room.code)
			await until(() => p.locator('.scr').isVisible(), `${p.e2eLabel} shows the projector`)
			this.proj[scheme] = p
		}
		await api('POST', `/${room.code}/current`, { pollId: firstPoll.id })
		// the projectors leave the lobby (it polls slowly) before the first check
		for (const p of Object.values(this.proj)) await until(() => p.locator('.scr-q').count(), `${p.e2eLabel} shows the first question`, 15000)
		const need = (kind) => new Set(this.cases.flatMap((c) => (PLAN[kind].get(c.id) || []).map((a) => a.device + '|' + a.scheme)))
		for (const key of need('desk')) {
			const [device, scheme] = key.split('|')
			const p = await world.page(device, scheme, { moderator: true, label: `desk-${device}-${scheme}`, reducedMotion: 'reduce' })
			await p.goto(APP + '/room/' + room.code)
			await until(() => p.locator('.mod-live').isVisible(), `${p.e2eLabel} live view`)
			await closeJoinPanel(p)
			this.desk.set(key, p)
		}
		for (const key of need('phone')) {
			const [device, scheme] = key.split('|')
			const p = await world.page(device, scheme, { label: `phone-${device}-${scheme}`, locale: locale(scheme), reducedMotion: 'reduce' })
			await p.goto(APP + '/s/' + room.code)
			if (room.mode === 'quiz') await pickName(p, `${device} ${scheme}`)
			this.phone.set(key, p)
		}
	}

	all() {
		return [...Object.values(this.proj), ...this.desk.values(), ...this.phone.values()]
	}

	/** The pages the plan checks for catalog case `c`: both projector windows, its desk and its phone. */
	pagesOf(c, phones = true, desks = true) {
		const pick = (kind) => (PLAN[kind].get(c.id) || []).map((a) => this[kind].get(a.device + '|' + a.scheme))
		return [...Object.values(this.proj), ...(desks ? pick('desk') : []), ...(phones ? pick('phone') : [])]
	}

	/**
	 * The desk does not follow a question switched over the API (it shows the question it presents): the desks
	 * of case `c` open the room again, as a moderator does who goes to that question.
	 */
	async openDesks(c) {
		for (const { device, scheme } of PLAN.desk.get(c.id) || []) {
			const p = this.desk.get(device + '|' + scheme)
			await p.goto(APP + '/room/' + this.room.code)
			await until(() => p.locator('.mod-live').isVisible(), `${p.e2eLabel} live view of ${c.id}`)
			await closeJoinPanel(p)
		}
	}

	/**
	 * Applies a change and waits until the pages of case `c` have fetched it (the desk: any room API call; a page
	 * whose view did not change fetches nothing new within 8 s, which is fine: the checks look anyway). phones =
	 * false: the change does not reach the phones (other players' answers), do not wait for them; desks = false
	 * likewise for the desks (a question switched over the API, see openDesks).
	 */
	async step(change, c, { phones = true, desks: withDesks = true } = {}) {
		const desks = new Set(this.desk.values())
		const pages = [...new Set(this.pagesOf(c, phones, withDesks))]
		const t0 = Date.now()
		await Promise.all(pages.map((p, i) => settled(p, i === 0 ? change : async () => {}, desks.has(p) ? '/results' : '/state', desks.has(p) ? 5000 : 8000).then(() => trace(`  settled ${p.e2eLabel} ${Date.now() - t0} ms (response at ${p.e2eGot - t0} ms)`)).catch((e) => {
			trace(`  no news ${p.e2eLabel} ${Date.now() - t0} ms`)
			if (!/Timeout|timed out/.test(e.message)) throw e
		})))
		trace(`step ${c.id} ${Date.now() - t0} ms`)
	}

	/** Checks the views of catalog case `c` in state `state`, as the plan of the run says. */
	async check(c, state, poll) {
		const t0 = Date.now()
		try { await this.checkViews(c, state, poll) } finally { trace(`check ${c.id} ${state} ${Date.now() - t0} ms`) }
	}

	async checkViews(c, state, poll) {
		const what = `${c.id} ${poll.type} ${state}`
		const views = c.views || VIEWS
		// the final standings show no question; every other state shows it whole wherever it shows (S1.1)
		const asked = state !== 'final standings'
		// open: a known finding of the case, for all views (a string) or per view ({ projector: '…' })
		const run = async (kind, fn) => {
			const why = typeof c.open === 'string' ? c.open : c.open?.[kind]
			if (!why && !COLLECT) return fn()
			try { await fn() } catch (e) { KNOWN.push(`${c.id}${why ? '' : ' (collected)'}: ${String(e.message).split('\n')[0]}`) }
		}
		if (views.includes('projector')) {
			for (const { size, scheme } of PLAN.projector.get(c.id)) {
				const p = this.proj[scheme]
				await p.setViewportSize({ width: size.width, height: size.height })
				await run('projector', async () => {
					await expectLayout(p, '.scr', `${what} at ${size.name}`, true)
					await expectScheme(p, '.scr', scheme, what)
					await expectSplitOrder(p, '.scr', `${what} at ${size.name}`)
					if (asked) await expectWholeQuestion(p, '.scr', poll.question, `${what} at ${size.name}`)
					if (c.uncut) {
						try {
							await expectUncut(p, '.scr', c.q.options.map((o) => o.slice(-10)), `${what} at ${size.name}`)
						} catch (e) {
							if (!c.uncutOpen) throw e
							KNOWN.push(`${c.id}: ${String(e.message).split('\n')[0].slice(0, 160)} (${c.uncutOpen})`)
						}
					}
				})
			}
		}
		for (const kind of ['desk', 'phone']) {
			if (!views.includes(kind)) continue
			for (const { device, scheme } of PLAN[kind].get(c.id)) {
				const p = this[kind].get(device + '|' + scheme)
				// a phone that polls slowly while nothing changes may still show the question before
				if (kind === 'phone' && ['open', 'answered'].includes(state)) await showsQuestion(p, poll, c)
				await run(kind, async () => {
					await expectLayout(p, ROOTS[kind], what, false, { text: true })
					await expectScheme(p, ROOTS[kind], scheme, what)
					if (asked && await showsAny(p, ROOTS[kind], poll)) await expectWholeQuestion(p, ROOTS[kind], poll.question, what)
				})
			}
		}
	}

	/** The phones the plan picks for case `c` answer on the page (quiz: right; poll: the first option). */
	async answerOnPhones(c, poll) {
		if (!(c.views || VIEWS).includes('phone')) return
		const quiz = this.room.mode === 'quiz'
		if (!quiz && poll.type !== 'choice') return
		if (quiz && !['choice', 'truefalse', 'multi', 'text', 'number'].includes(poll.type)) return
		for (const { device, scheme } of PLAN.phone.get(c.id)) {
			const p = this.phone.get(device + '|' + scheme)
			await showsQuestion(p, poll, c)
			const q = { ...c.q, question: poll.question }
			await answer(p, q, quiz ? true : c.q.options[0], { quiz })
		}
	}
}

/** Runs the catalog cases of one room of ROOMS. */
async function runRoom(world, key) {
	const def = ROOMS[key]
	const cases = CASES.filter((c) => c.room === key && (!ONLY.length || ONLY.includes(c.id)))
	if (!cases.length) return
	const room = await api('POST', '', { mode: def.mode, title: def.title })
	world.track(room.code)
	room.mode = def.mode
	const shown = await api('GET', '/' + room.code)
	expect(shown.title === def.title, `room "${key}": title stored as written`, shown.title)
	const polls = []
	for (const c of cases) {
		let poll
		try {
			poll = await api('POST', `/${room.code}/polls`, c.q)
		} catch (e) {
			throw new Error(`${c.id}: the server refuses the question: ${e.message}`)
		}
		if (c.image) await uploadImage(room.code, poll.id, stripes(...c.image))
		polls.push(poll)
	}
	const kept = (await api('GET', '/' + room.code)).polls
	for (const [i, c] of cases.entries()) {
		const d = storedDiff(c, kept.find((p) => p.id === polls[i].id))
		expect(d.length === 0, `${c.id}: not stored as written`, d)
	}

	const faces = new Faces(world, room, cases)
	await faces.open(polls[0])
	const players = new Map()
	for (const [i, c] of cases.entries()) {
		const poll = kept.find((p) => p.id === polls[i].id)
		const states = c.states || ['open', 'answered', 'revealed']
		if (i > 0) {
			await faces.step(() => api('POST', `/${room.code}/current`, { pollId: poll.id }), c, { desks: false })
			await faces.openDesks(c)
		}
		if (states.includes('open')) await faces.check(c, 'open', poll)
		if (states.includes('answered')) {
			await faces.step(async () => {
				for (const [k, a] of (c.answers || []).entries()) {
					let ph = players.get(k)
					if (!ph) {
						ph = new ApiPhone(room.code)
						await ph.req('GET', '/state')
						if (def.mode === 'quiz') await ph.join(key === 'board' ? BOARD_NAMES[k] : `Player ${k + 1}`)
						players.set(k, ph)
					}
					try {
						await ph.req('POST', '/vote', { pollId: poll.id, value: value(poll, a) })
					} catch (e) {
						throw new Error(`${c.id}: the server refuses answer ${JSON.stringify(a).slice(0, 40)}: ${e.message}`)
					}
				}
				for (let left = c.demo || 0; left > 0; left -= 200) await api('POST', `/${room.code}/demo`, { count: Math.min(200, left) })
			}, c, { phones: false })
			await faces.answerOnPhones(c, poll)
			if (c.samples) {
				const r = await api('GET', `/${room.code}/polls/${poll.id}/results`)
				const got = (r.answers || []).map((x) => x.sample)
				for (const s of c.samples) expect(got.includes(s), `${c.id}: answer stored as written`, { want: s, got: got.slice(0, 8) })
			}
			await faces.check(c, 'answered', poll)
		}
		if (states.includes('revealed') || states.includes('empty')) {
			await faces.step(() => api('POST', `/${room.code}/polls/${poll.id}/lock`), c)
			await faces.check(c, states.includes('empty') ? 'empty' : 'revealed', poll)
		}
		if (key === 'board' && i === cases.length - 1) {
			const lb = await api('GET', `/${room.code}/leaderboard`)
			const names = lb.map((r) => r.nickname)
			for (const n of BOARD_NAMES.slice(0, 5)) expect(names.includes(n), `${c.id}: nickname stored as written`, { want: n, got: names.slice(0, 8) })
		}
	}
	if (def.mode === 'quiz') {
		await faces.step(() => api('POST', `/${room.code}/end`), cases[cases.length - 1])
		for (const c of cases.filter((x) => x.final || x === cases[cases.length - 1])) await faces.check(c, 'final standings', kept.find((p) => p.id === polls[cases.indexOf(c)].id))
	}
	for (const p of faces.all()) await p.close()
}

export async function stressPoll(world) { await runRoom(world, 'poll') }
export async function stressQuiz(world) { await runRoom(world, 'quiz') }
export async function stressBoard(world) {
	await runRoom(world, 'board')
	await runRoom(world, 'tiles')
}
for (const fn of [stressPoll, stressQuiz, stressBoard]) fn.limit = 420
