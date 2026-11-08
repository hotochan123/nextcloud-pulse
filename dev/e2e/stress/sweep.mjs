// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The size sweep of the browser tests and the sample of it one run takes (pure logic, no browser; tested by
// dev/unit/stress-sweep.test.mjs).
//
// Projector: every width of WIDTHS in every aspect ratio of RATIOS, plus a browser window with tab and address bar
// (a 16:9 screen less CHROME px: 1920 → 1920×945). Only sizes of at least MIN count: the projector page supports
// 1280×720 and up. A size class is the kind (ratio or "window") and the width band (≤ 1440, ≤ 2200, larger).
// Desk and phone: the devices of DESK and PHONE (dev/e2e/lib/harness.mjs DEVICES), zoom 200 % included.
//
// plan(seed, ids) picks for each catalog case: on the projector MIN and one more size (opposite schemes), on the
// desk and on the phone one device each; over the run every size class and every device comes up at least once,
// and each face sees light and dark. The same seed gives the same plan. all = true: every size for every case,
// light and dark, and every device. smallest = ids of cases that also get the smallest size of every kind of the
// sweep on the projector (a long question where the height is shortest), schemes alternating.

export const MIN = { width: 1280, height: 720 }
export const WIDTHS = [1280, 1366, 1600, 1920, 2560, 3200, 3840]
export const RATIOS = { '16:9': 16 / 9, '16:10': 16 / 10, '4:3': 4 / 3, '21:9': 21 / 9 }
export const CHROME = 135
export const DESK = ['laptop', 'tablet', 'phone', 'zoomDesk']
export const PHONE = ['phone', 'tablet', 'zoomPhone']

export function band(width) {
	return width <= 1440 ? 'small' : width <= 2200 ? 'mid' : 'large'
}

/** Every projector size of the sweep, MIN first. */
export function sweep() {
	const out = []
	for (const width of WIDTHS) {
		for (const [kind, ratio] of Object.entries(RATIOS)) out.push({ kind, width, height: Math.round(width / ratio) })
		out.push({ kind: 'window', width, height: Math.round(width * 9 / 16) - CHROME })
	}
	return out
		.filter((s) => s.width >= MIN.width && s.height >= MIN.height)
		.map((s) => ({ ...s, band: band(s.width), name: `${s.width}x${s.height}` }))
}

export const sizeClass = (s) => s.kind + '/' + s.band

/** The size classes of the sweep, in sweep order. */
export function classes(sizes = sweep()) {
	return [...new Set(sizes.map(sizeClass))]
}

/** A small seeded random generator (mulberry32): the same seed, the same numbers. */
export function random(seed) {
	let a = seed >>> 0
	return () => {
		a = (a + 0x6D2B79F5) >>> 0
		let t = a
		t = Math.imul(t ^ (t >>> 15), t | 1)
		t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
		return ((t ^ (t >>> 14)) >>> 0) / 4294967296
	}
}

function shuffle(list, rnd) {
	const a = list.slice()
	for (let i = a.length - 1; i > 0; i--) {
		const j = Math.floor(rnd() * (i + 1))
		;[a[i], a[j]] = [a[j], a[i]]
	}
	return a
}

/** Default seed of a run: the day and the process, printed by the run so that a red run can be replayed. */
export function defaultSeed(now = new Date(), pid = 0) {
	return (Number(now.toISOString().slice(0, 10).replace(/-/g, '')) * 1000 + (pid % 1000)) >>> 0
}

/*
 * Spreads `items` over the cases: case i gets items[order[i % n]], and when there are more items than cases, the
 * items left over go to the cases from the start again. Every item is used at least once.
 */
function spread(ids, items, rnd) {
	const pool = shuffle(items, rnd)
	const out = new Map(ids.map((id) => [id, []]))
	const order = shuffle(ids, rnd)
	const n = Math.max(order.length, pool.length)
	for (let i = 0; i < n && order.length; i++) out.get(order[i % order.length]).push(pool[i % pool.length])
	return out
}

/**
 * The plan of one run for the catalog cases `ids`: { seed, projector, desk, phone }, each a Map id → list of
 * { size | device, scheme }.
 */
export function plan(seed, ids, { all = false, smallest = [] } = {}) {
	const rnd = random(seed)
	const sizes = sweep()
	const min = sizes.find((s) => s.width === MIN.width && s.height === MIN.height)
	const projector = new Map()
	const desk = new Map()
	const phone = new Map()
	if (all) {
		for (const id of ids) {
			projector.set(id, sizes.flatMap((size) => ['light', 'dark'].map((scheme) => ({ size, scheme }))))
			desk.set(id, DESK.flatMap((device) => ['light', 'dark'].map((scheme) => ({ device, scheme }))))
			phone.set(id, PHONE.flatMap((device) => ['light', 'dark'].map((scheme) => ({ device, scheme }))))
		}
		return { seed, projector, desk, phone }
	}
	// One size of each class, drawn within the class, then spread over the cases
	const byClass = classes(sizes).map((c) => sizes.filter((s) => sizeClass(s) === c))
	const picks = byClass.map((list) => list[Math.floor(rnd() * list.length)])
	const extra = spread(ids, picks, rnd)
	const flip = rnd() < 0.5
	ids.forEach((id, i) => {
		const first = (i % 2 === 0) !== flip ? 'light' : 'dark'
		const other = first === 'light' ? 'dark' : 'light'
		const more = extra.get(id).map((size, k) => ({ size, scheme: k % 2 === 0 ? other : first }))
		projector.set(id, [{ size: min, scheme: first }, ...more])
	})
	for (const id of smallest) {
		const list = projector.get(id)
		if (!list) continue
		const kinds = [...new Set(sizes.map((s) => s.kind))]
		kinds.forEach((kind, k) => {
			const size = sizes.find((s) => s.kind === kind)
			if (!list.some((a) => a.size.name === size.name)) list.push({ size, scheme: k % 2 === 0 ? 'dark' : 'light' })
		})
	}
	for (const [map, devices] of [[desk, DESK], [phone, PHONE]]) {
		const pairs = devices.flatMap((device) => ['light', 'dark'].map((scheme) => ({ device, scheme })))
		const got = spread(ids, pairs, rnd)
		for (const id of ids) map.set(id, got.get(id))
	}
	return { seed, projector, desk, phone }
}

/**
 * The projector windows of the cases projector and themes for one run: 1280×720 light, then a 4:3, a 21:9 and a
 * browser-window size of the sweep drawn by the seed, dark, light and dark.
 */
export function projectorWindows(seed) {
	const rnd = random(seed ^ 0x5bd1e995)
	const sizes = sweep()
	const pick = (kind) => {
		const list = sizes.filter((s) => s.kind === kind)
		return list[Math.floor(rnd() * list.length)]
	}
	const min = sizes.find((s) => s.width === MIN.width && s.height === MIN.height)
	return [{ size: min, scheme: 'light' }, { size: pick('4:3'), scheme: 'dark' }, { size: pick('21:9'), scheme: 'light' }, { size: pick('window'), scheme: 'dark' }]
}
