// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// histtap: a scale 1–20 with 400 demo votes (STRESS.md S8.4d), revealed. On a phone zoomed to 200 % the counts
// above the columns are left out (they do not fit); tapping a column shows a label with its value and votes, a tap
// on another column moves it, a tap on the question closes it; the label stays inside the screen and nothing
// scrolls sideways; column heights and screen-reader labels do not change. On a laptop desk, where the counts fit,
// a click on a column shows nothing; on the desk zoomed to 200 % in a 320 px window a click shows the label and Escape closes it.

import { APP, api, expect, until } from '../lib/harness.mjs'
import { closeJoinPanel, press } from '../lib/flows.mjs'

const Q = { type: 'scale', question: 'Skala bis 20, viele Stimmen', scaleMode: 'single', scaleMax: 20, minLabel: 'gar nicht', maxLabel: 'sehr' }

/** Heights of the bars and the labels of the columns, to compare before and after a tap. */
const shape = (page, root) => page.locator(root + ' .hist').first().evaluate((h) => ({
	heights: [...h.querySelectorAll('.hist-bar')].map((b) => b.getBoundingClientRect().height.toFixed(1)).join(','),
	labels: [...h.querySelectorAll('.hist-col')].map((c) => c.getAttribute('aria-label')).join('|'),
}))

/** Taps (or clicks) column k and returns its screen-reader label. */
async function tapColumn(page, root, k) {
	const col = page.locator(root + ' .hist .hist-col').nth(k)
	await press(col)
	return col.getAttribute('aria-label')
}

/** The label shows the column's own words, lies inside the viewport, and the page does not scroll sideways. */
async function expectTip(page, root, want, what) {
	const tip = page.locator(root + ' .hist-tip')
	await until(() => tip.isVisible(), `${what}: the label shows`)
	await until(async () => (await tip.innerText()).trim() === want, `${what}: the label says "${want}"`, 5000)
	const box = await tip.boundingBox()
	const vw = page.viewportSize().width
	expect(box && box.x >= -0.5 && box.x + box.width <= vw + 0.5, `${what}: the label lies inside the screen`, { box, vw })
	const side = await page.evaluate(() => {
		const d = document.scrollingElement
		return { sw: d.scrollWidth, cw: d.clientWidth }
	})
	expect(side.sw <= side.cw, `${what}: the page does not scroll sideways`, side)
}

export async function histtap(world) {
	const room = await api('POST', '', { mode: 'poll', title: 'E2E histogram tap' })
	world.track(room.code)
	const poll = await api('POST', `/${room.code}/polls`, Q)
	await api('POST', `/${room.code}/current`, { pollId: poll.id })
	for (let left = 400; left > 0; left -= 200) await api('POST', `/${room.code}/demo`, { count: Math.min(200, left) })
	await api('POST', `/${room.code}/polls/${poll.id}/lock`)

	// phone zoomed to 200 %: counts left out, a tap shows them
	const phone = await world.page('zoomPhone', 'dark', { label: 'phone-zoom', locale: 'de-DE' })
	await phone.goto(APP + '/s/' + room.code)
	const P = '.pulse-part'
	await until(() => phone.locator(P + ' .hist').first().isVisible(), 'phone shows the revealed histogram')
	await until(async () => (await phone.locator(P + ' .hist--nocnt').count()) > 0, 'phone (zoom 200 %): the counts are left out')
	const cols = await phone.locator(P + ' .hist .hist-col').count()
	expect(cols === 20, 'the scale has 20 columns', cols)
	const before = await shape(phone, P)
	await expectTip(phone, P, await tapColumn(phone, P, 2), 'phone, 3rd column')
	expect(await phone.locator(P + ' .hist-col[data-tip]').count() === 1, 'phone: one column is marked')
	await expectTip(phone, P, await tapColumn(phone, P, cols - 1), 'phone, last column')
	await expectTip(phone, P, await tapColumn(phone, P, 0), 'phone, first column')
	const after = await shape(phone, P)
	expect(before.heights === after.heights, 'phone: the tap changes no column height', { before: before.heights, after: after.heights })
	expect(before.labels === after.labels, 'phone: the screen-reader labels stay', { before: before.labels, after: after.labels })
	await press(phone.locator(P + ' h1.q').first())
	await until(async () => (await phone.locator(P + ' .hist-tip').count()) === 0, 'phone: a tap on the question closes the label')
	await tapColumn(phone, P, 5)
	await until(() => phone.locator(P + ' .hist-tip').isVisible(), 'phone: label open again')
	await tapColumn(phone, P, 5)
	await until(async () => (await phone.locator(P + ' .hist-tip').count()) === 0, 'phone: a tap on the same column closes the label')

	// laptop desk: the counts fit, a click shows nothing
	const desk = await world.page('laptop', 'light', { moderator: true, label: 'desk' })
	await desk.goto(APP + '/room/' + room.code)
	await until(() => desk.locator('.mod-live').isVisible(), 'desk live view')
	await closeJoinPanel(desk)
	const D = '.pulse-mod'
	await until(() => desk.locator(D + ' .hist').first().isVisible(), 'desk shows the histogram')
	expect(await desk.locator(D + ' .hist--nocnt').count() === 0, 'desk on a laptop: the counts fit')
	await desk.locator(D + ' .hist .hist-col').nth(7).click()
	await desk.waitForTimeout(300)
	expect(await desk.locator(D + ' .hist-tip').count() === 0, 'desk on a laptop: a click on a column shows no label')

	// desk zoomed to 200 % in a narrow window: its preview leaves the counts out, a click shows the label, Escape closes it
	const zdesk = await world.page('zoomDesk', 'dark', { moderator: true, label: 'desk-zoom' })
	await zdesk.goto(APP + '/room/' + room.code)
	await until(() => zdesk.locator('.mod-live').isVisible(), 'zoomed desk live view')
	await closeJoinPanel(zdesk)
	await until(() => zdesk.locator(D + ' .hist').first().isVisible(), 'zoomed desk shows the histogram')
	// a narrow window at 200 % (a desk in a split screen): the preview's columns get too narrow for the counts
	await zdesk.setViewportSize({ width: 320, height: 384 })
	await until(async () => (await zdesk.locator(D + ' .hist--nocnt').count()) > 0, 'desk 320 px at 200 %: the counts are left out', 5000)
	await zdesk.locator(D + ' .hist .hist-col').nth(10).scrollIntoViewIfNeeded()
	const want = await zdesk.locator(D + ' .hist .hist-col').nth(10).getAttribute('aria-label')
	await zdesk.locator(D + ' .hist .hist-col').nth(10).click()
	await until(async () => (await zdesk.locator(D + ' .hist-tip').innerText().catch(() => '')).trim() === want, 'zoomed desk: the label shows')
	await zdesk.keyboard.press('Escape')
	await until(async () => (await zdesk.locator(D + ' .hist-tip').count()) === 0, 'zoomed desk: Escape closes the label')
	for (const p of [phone, desk, zdesk]) expect(p.e2eErrors.length === 0, `no script errors on ${p.e2eLabel}`, p.e2eErrors)
}
