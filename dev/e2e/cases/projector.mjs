// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// projector: four projector windows of the size sweep (1280×720, a 4:3, a 21:9 and a browser-window size of this
// run's sample, light and dark; dev/e2e/stress/sweep.mjs projectorWindows) follow a poll and a quiz with every
// question type, driven through the API and filled with the desk's demo votes: the lobby, each question open and
// revealed, the final standings, then a self-paced race with three players. In every state nothing
// runs past the stage and the page does not scroll. Last a long two-line question with five options and
// multiple answers at 1920×945, 1920×1080 and in the embed frame (1264×576), open, revealed without answers and
// revealed with answers, then a question four times as long, which shows whole (it shrinks itself), also after a
// resize back and forth, and a short one after it at its base size again. Last the note below the
// minimum size (STRESS.md S9.4): a band at 1024×600 that covers nothing, its ✕ (mouse and keyboard) until the next
// reload, gone from 1280×720 up, and never in the embed URL or in a frame.

import { APP, ApiPhone, api, expect, expectLayout, expectScheme, expectWholeQuestion, screenDevice, settled, until } from '../lib/harness.mjs'
import { POLL_QS, QUIZ_QS } from '../lib/flows.mjs'
import { projectorWindows } from '../stress/sweep.mjs'
import { SEED } from './stress.mjs'

/** The projector windows of this run (the seed's sample of the size sweep), as [device, scheme, label]. */
export const WINDOWS = projectorWindows(SEED).map(({ size, scheme }) => [screenDevice(size), scheme, `projector-${size.name}-${scheme}`])

async function screens(world, code, windows = WINDOWS) {
	const pages = []
	for (const [device, scheme, label] of windows) {
		const p = await world.page(device, scheme, { label: label || `${device}-${scheme}` })
		// the PowerPoint embed opens the projector page with ?embed=1 (js/pulse-embed.js)
		await p.goto(APP + '/screen/' + code + (device === 'embed' ? '?embed=1' : ''))
		await until(() => p.locator('.scr').isVisible(), `${p.e2eLabel} shows the projector`)
		await noNote(p, 'from 1280×720 up and in the embed')
		p.e2eScheme = scheme
		pages.push(p)
	}
	return pages
}

const NOTE = '.scr-small-note'
const NOTE_TEXT = { light: 'Window too small – everything shows cleanly from 1280×720', dark: 'Fenster zu klein – ab 1280×720 wird alles sauber angezeigt' }

async function noNote(p, what) {
	// the size is measured on mount and on resize: give a resize event a moment
	await new Promise((resolve) => setTimeout(resolve, 300))
	expect(await p.locator(NOTE).count() === 0, `${p.e2eLabel}: no size note ${what}`)
}

async function hasNote(p, scheme, what) {
	await until(() => p.locator(NOTE).isVisible(), `${p.e2eLabel}: size note ${what}`, 5000)
	const got = await p.evaluate((sel) => {
		const band = document.querySelector(sel)
		const b = band.getBoundingClientRect()
		const x = band.querySelector('.scr-small-close').getBoundingClientRect()
		return { text: band.querySelector('.scr-small-text').textContent.trim(), top: b.top, bottom: b.bottom, h: window.innerHeight, scr: document.querySelector('.scr').getBoundingClientRect().bottom, xw: x.width, xh: x.height }
	}, NOTE)
	expect(got.text === NOTE_TEXT[scheme], `${p.e2eLabel}: size note reads "${NOTE_TEXT[scheme]}"`, got.text)
	expect(Math.abs(got.bottom - got.h) <= 1, `${p.e2eLabel}: size note at the bottom edge`, JSON.stringify(got))
	expect(got.scr <= got.top + 1, `${p.e2eLabel}: the page ends above the size note`, JSON.stringify(got))
	expect(got.xw >= 44 && got.xh >= 44, `${p.e2eLabel}: ✕ of the size note at least 44×44`, `${got.xw}×${got.xh}`)
}

async function smallWindow(world) {
	const { code, polls } = await world.room('poll', 'E2E projector small window', POLL_QS.slice(0, 1))
	const SMALL = { width: 1024, height: 600 }
	for (const [scheme, locale] of [['light', 'en-US'], ['dark', 'de-DE']]) {
		const p = await world.page(screenDevice(SMALL), scheme, { label: `projector-small-${scheme}`, locale })
		await p.goto(APP + '/screen/' + code)
		await until(() => p.locator('.scr').isVisible(), `${p.e2eLabel} shows the projector`)
		await hasNote(p, scheme, 'in the lobby')
		await expectLayout(p, '.scr', 'lobby with size note', true)
		await settled(p, async () => {
			await api('POST', `/${code}/current`, { pollId: polls[0].id })
			await api('POST', `/${code}/demo`, { count: 40 })
		})
		await until(() => p.locator('.scr-q').count(), `${p.e2eLabel} shows the question`, 15000)
		await hasNote(p, scheme, 'on a question')
		await expectLayout(p, '.scr', 'question with size note', true)
		// ✕ with the mouse (light) or the keyboard (dark): gone, and the stage fits the larger page
		if (scheme === 'light') await p.click('.scr-small-close')
		else {
			await p.focus('.scr-small-close')
			await p.keyboard.press('Enter')
		}
		await noNote(p, 'after its ✕')
		await expectLayout(p, '.scr', 'question after the size note', true)
		expect(await p.evaluate(() => Math.abs(document.querySelector('.scr').getBoundingClientRect().bottom - window.innerHeight) <= 1), `${p.e2eLabel}: the page takes the whole window again`)
		await p.setViewportSize({ width: 1100, height: 600 })
		await noNote(p, 'after its ✕ and a resize')
		await p.reload()
		await hasNote(p, scheme, 'after a reload')
		// by itself: away from 1280×720 up, back below it
		await p.setViewportSize({ width: 1280, height: 720 })
		await until(async () => await p.locator(NOTE).count() === 0, `${p.e2eLabel}: size note gone at 1280×720`, 5000)
		await expectLayout(p, '.scr', 'question at 1280×720 after the size note', true)
		await p.setViewportSize(SMALL)
		await hasNote(p, scheme, 'back below 1280×720')
		await p.close()
	}
	// the embed URL and a frame (the desk's previews, the PowerPoint add-in) never show it
	const e = await world.page(screenDevice(SMALL), 'light', { label: 'projector-small-embed' })
	await e.goto(APP + '/screen/' + code + '?embed=1')
	await until(() => e.locator('.scr').isVisible(), `${e.e2eLabel} shows the projector`)
	await noNote(e, 'with ?embed=1')
	await e.close()
	const f = await world.page(screenDevice({ width: 1100, height: 700 }), 'light', { label: 'projector-small-frame' })
	// a same-origin host page (the embed URL, so it shows no note itself) that holds only the frame
	await f.goto(APP + '/screen/' + code + '?embed=1')
	await f.evaluate((src) => {
		document.body.innerHTML = ''
		const fr = document.createElement('iframe')
		fr.src = src
		fr.style.cssText = 'position:fixed;left:0;top:0;width:1024px;height:600px;border:0'
		document.body.appendChild(fr)
	}, APP + '/screen/' + code)
	const frame = f.frameLocator('iframe')
	await until(() => frame.locator('.scr').isVisible(), `${f.e2eLabel} shows the projector in a frame`)
	await new Promise((resolve) => setTimeout(resolve, 500))
	expect(await frame.locator(NOTE).count() === 0, `${f.e2eLabel}: no size note in a frame`)
	await f.close()
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

	// a long two-line question with five options and multiple answers, on a 1080p projector in a browser window
	// (1920×945), full screen and in the PowerPoint embed frame: open, revealed without a single answer, and
	// revealed with answers
	const wide = [['projector945', 'light'], ['projector945', 'dark'], ['projector', 'light'], ['projector', 'dark'], ['embed', 'light']]
	const long = {
		type: 'multi',
		question: 'Welche der folgenden Aussagen über Ludwig van Beethoven sind korrekt?',
		options: ['Beethoven wurde in Bonn, Deutschland, geboren.', 'Sein Geburtsjahr ist 1770.', 'Er verlor im Alter sein Gehör.', 'Beethoven komponierte hauptsächlich Opern.', 'Seine bekannteste Symphonie ist die "Pastorale".'],
		correctIndexes: [0, 1, 2],
		timeLimit: 60,
	}
	// a question four times as long: it shows whole (it shrinks itself instead of being cut)
	const longer = { ...long, question: (long.question + ' ').repeat(4).trim() }
	// …and a short one after it, whose descenders must show again
	const short = { ...long, question: 'Mag Ludwig gerne Gugelhupf?' }
	const room = await world.room('quiz', 'E2E projector long multi', [long, long, longer, short])
	const wpages = await screens(world, room.code, wide)
	const [empty, filled, four, after] = room.polls
	const factor = (p) => p.evaluate(() => document.querySelector('.scr').style.getPropertyValue('--scr-q-k') || '1')
	// the fit steps down in frames: the factor once it has not changed for 600 ms
	const settledFactor = async (p) => {
		let last = await factor(p)
		await until(async () => {
			await new Promise((r) => setTimeout(r, 600))
			const now = await factor(p)
			const same = now === last
			last = now
			return same
		}, `${p.e2eLabel}: the question size settles`, 10000)
		return last
	}
	await all(wpages, () => api('POST', `/${room.code}/current`, { pollId: empty.id }), 'long multi open')
	await all(wpages, () => api('POST', `/${room.code}/polls/${empty.id}/lock`), 'long multi revealed without answers')
	for (const p of wpages) await until(() => p.locator('.scr-empty').isVisible(), `${p.e2eLabel} shows "No answer received"`)
	await all(wpages, async () => {
		await api('POST', `/${room.code}/current`, { pollId: filled.id })
		await api('POST', `/${room.code}/demo`, { count: 40 })
	}, 'long multi open with answers')
	await all(wpages, () => api('POST', `/${room.code}/polls/${filled.id}/lock`), 'long multi revealed with answers')
	await all(wpages, () => api('POST', `/${room.code}/current`, { pollId: four.id }), 'question four times as long')
	for (const p of wpages) await expectWholeQuestion(p, '.scr', longer.question, 'question four times as long')
	// a resize back and forth (a window dragged): the fit ends in the same place and stays there
	const first = wpages[0]
	const vp = first.viewportSize()
	const k0 = await settledFactor(first)
	for (const w of [1280, 1500, vp.width]) await first.setViewportSize({ width: w, height: vp.height })
	await expectWholeQuestion(first, '.scr', longer.question, 'question four times as long after a resize')
	await expectLayout(first, '.scr', 'question four times as long after a resize', true)
	const k1 = await settledFactor(first)
	await new Promise((r) => setTimeout(r, 1500))
	expect(k1 === await factor(first), `${first.e2eLabel}: the question size stays after the fit`, { k1 })
	expect(k1 === k0, `${first.e2eLabel}: the same window, the same question size`, { k0, k1 })
	await all(wpages, () => api('POST', `/${room.code}/current`, { pollId: after.id }), 'short question after the long one')
	for (const p of wpages) {
		await expectWholeQuestion(p, '.scr', short.question, 'short question after the long one')
		expect(await factor(p) === '1', `${p.e2eLabel}: the short question after the long one has its base size`, await factor(p))
	}
	for (const p of wpages) await p.close()

	await smallWindow(world)
}
