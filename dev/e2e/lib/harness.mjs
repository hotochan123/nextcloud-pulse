// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The plumbing of the browser tests: devices, login, the moderator API (Basic auth, as dev/sim/sim.mjs uses
// it), waiting without fixed pauses, and the two page checks (no horizontal overflow; everything inside the
// projector's stage). Runs inside the Playwright container (dev/e2e/run.sh), never on the host.

import { mkdirSync } from 'node:fs'
import { join } from 'node:path'

export const URL_BASE = (process.env.PULSE_E2E_URL || '').replace(/\/+$/, '')
export const USER = process.env.PULSE_E2E_USER || ''
export const PASS = process.env.PULSE_E2E_PASS || ''
export const OUT = process.env.PULSE_E2E_OUT || '/tmp/pulse-e2e'
export const SELFTEST = process.env.PULSE_E2E_SELFTEST || ''
export const APP = URL_BASE + '/index.php/apps/pulse'

export const DEVICES = {
	laptop: { viewport: { width: 1366, height: 768 } },
	tablet: { viewport: { width: 820, height: 1180 }, hasTouch: true },
	phone: { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, deviceScaleFactor: 3 },
	projector: { viewport: { width: 1920, height: 1080 } },
	// a 1920×1080 projector in a browser window with its tab and address bar
	projector945: { viewport: { width: 1920, height: 945 } },
	// the PowerPoint embed frame
	embed: { viewport: { width: 1264, height: 576 } },
	projectorSmall: { viewport: { width: 1366, height: 768 } },
	// browser zoom 200 % (STRESS.md S9.2): half the CSS viewport at twice the pixel density, as Chrome zooms
	zoomDesk: { viewport: { width: 683, height: 384 }, deviceScaleFactor: 2 },
	zoomPhone: { viewport: { width: 195, height: 422 }, hasTouch: true, isMobile: true, deviceScaleFactor: 6 },
}

/** A projector window of the size sweep (dev/e2e/stress/sweep.mjs) as a device. */
export const screenDevice = (size) => ({ viewport: { width: size.width, height: size.height } })

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

export class CheckError extends Error {}

export function expect(cond, label, detail) {
	if (!cond) throw new CheckError(label + (detail === undefined ? '' : ': ' + JSON.stringify(detail).slice(0, 300)))
}

/** Polls fn until it returns something truthy (or throws on time-out with the last value). */
export async function until(fn, label, ms = 20000, every = 250) {
	const end = Date.now() + ms
	let last
	for (;;) {
		try {
			last = await fn()
			if (last) return last
		} catch (e) {
			last = e.message
		}
		if (Date.now() > end) throw new CheckError('timed out: ' + label + (last === undefined ? '' : ' (last: ' + String(JSON.stringify(last)).slice(0, 200) + ')'))
		await sleep(every)
	}
}

const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

/** Moderator API, path below /api/1.0/rooms. Throws on a status of 300 and above. */
export async function api(method, path, body) {
	return appApi(method, '/api/1.0/rooms' + path, body)
}

/** Any API route of the app, path below /apps/pulse (e.g. '/api/1.0/ai'). Throws on a status of 300 and above. */
export async function appApi(method, path, body) {
	const r = await fetch(APP + path, {
		method,
		headers: { Authorization: AUTH, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json', Accept: 'application/json' },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	const text = await r.text()
	let data = text
	try { data = JSON.parse(text) } catch {}
	if (r.status >= 300) throw new Error(`${method} ${path} -> ${r.status} ${text.slice(0, 200)}`)
	return data
}

/**
 * The test user's own files over WebDAV, path below the user's root
 * (e.g. '/Pulse/x.csv'): GET answers the text, PUT writes body, DELETE nothing. Throws on a
 * status of 300 and above.
 */
export async function dav(method, path, body) {
	const url = URL_BASE + '/remote.php/dav/files/' + encodeURIComponent(USER) + path.split('/').map(encodeURIComponent).join('/')
	const r = await fetch(url, { method, headers: { Authorization: AUTH }, body })
	const text = await r.text()
	if (r.status >= 300) throw new Error(`DAV ${method} ${path} -> ${r.status} ${text.slice(0, 200)}`)
	return text
}

/** A case's world: its contexts, pages and rooms, closed and deleted afterwards. */
export class World {
	constructor(browser, name, auth) {
		this.browser = browser
		this.name = name
		this.auth = auth
		this.contexts = []
		this.pages = []
		this.rooms = []
	}

	/**
	 * A fresh page on a device (a name of DEVICES, or a device of its own such as screenDevice()); moderator pages
	 * get the stored login. locale: the browser's language, which the anonymous pages follow. reducedMotion:
	 * 'reduce' skips the app's animations (the stress cases check the layout, not the motion).
	 */
	async page(device, scheme = 'light', { moderator = false, label = '', locale = 'en-US', reducedMotion = 'no-preference' } = {}) {
		const dev = typeof device === 'string' ? DEVICES[device] : device
		const ctx = await this.browser.newContext({
			...dev,
			colorScheme: scheme,
			locale,
			reducedMotion,
			acceptDownloads: true,
			storageState: moderator ? this.auth : undefined,
		})
		this.contexts.push(ctx)
		const page = await ctx.newPage()
		page.setDefaultTimeout(15000)
		page.e2eLabel = label || `${typeof device === 'string' ? device : dev.viewport.width + 'x' + dev.viewport.height}-${scheme}`
		page.e2eTouch = !!dev.hasTouch
		page.e2eErrors = []
		page.on('pageerror', (e) => page.e2eErrors.push(e.message))
		this.pages.push(page)
		if (SELFTEST === 'overlap') {
			// The self-test of the overlap check: a copy of the first text on the projector's stage (or on the phone
			// page), laid exactly over it
			await page.addInitScript(() => setInterval(() => {
				const st = document.querySelector('.scr-stage') || document.querySelector('.pulse-part')
				if (!st || document.querySelector('.e2e-overlap')) return
				const walker = document.createTreeWalker(st, NodeFilter.SHOW_TEXT, { acceptNode: (n) => n.data.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP })
				const t = walker.nextNode()
				if (!t) return
				const range = document.createRange()
				range.selectNodeContents(t)
				const r = range.getBoundingClientRect()
				const d = document.createElement('div')
				d.className = 'e2e-overlap'
				d.textContent = t.data
				d.style.cssText = `position:fixed;left:${r.left}px;top:${r.top}px;color:red;white-space:nowrap`
				st.appendChild(d)
			}, 100))
		}
		if (SELFTEST === 'overflow' || SELFTEST === 'stage') {
			const wide = SELFTEST === 'overflow'
			// The self-test of the layout checks: one element wider than any screen inside each face's root
			// (added again whenever a re-render drops it)
			await page.addInitScript((wide) => setInterval(() => {
				// overflow: one wider than any screen in each face's root; stage: one taller than the projector's
				// stage, on the stage (which clips it, so only the stage check can see it)
				const add = wide ? ['.scr, .pulse-mod, .pulse-part', 'width:200vw;height:4px'] : ['.scr-stage', 'width:10px;height:200vh;flex:none']
				for (const [sel, css] of [add]) {
					const root = document.querySelector(sel)
					if (root && !root.querySelector(':scope > .e2e-wide')) {
						const d = document.createElement('div')
						d.className = 'e2e-wide'
						d.style.cssText = css + ';background:red'
						root.appendChild(d)
					}
				}
			}, 100), wide)
		}
		return page
	}

	async room(mode, title, questions) {
		const room = await api('POST', '', { mode, title })
		this.rooms.push(room.code)
		const polls = []
		for (const q of questions) polls.push(await api('POST', '/' + room.code + '/polls', q))
		return { code: room.code, polls }
	}

	track(code) {
		if (!this.rooms.includes(code)) this.rooms.push(code)
	}

	async screenshots() {
		const dir = join(OUT, this.name)
		mkdirSync(dir, { recursive: true })
		let i = 0
		for (const p of this.pages) {
			i++
			await p.screenshot({ path: join(dir, `${i}-${p.e2eLabel}.png`), fullPage: true, timeout: 5000 }).catch(() => {})
		}
	}

	async close() {
		for (const c of this.contexts) await c.close().catch(() => {})
		for (const code of this.rooms) await api('DELETE', '/' + code).catch(() => {})
	}
}

/** Logs alice in once and returns the storage state for moderator contexts. */
export async function login(browser) {
	const ctx = await browser.newContext({ ...DEVICES.laptop, locale: 'en-US' })
	const page = await ctx.newPage()
	await page.goto(URL_BASE + '/index.php/login')
	await page.locator('#user').fill(USER)
	await page.locator('#password').fill(PASS)
	await page.locator('#password').press('Enter')
	await page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 })
	const state = await ctx.storageState()
	await ctx.close()
	return state
}

/*
 * Layout check in the page: nothing scrolls sideways (neither the document nor a scroll box on the way from the
 * page to `root` or inside it) and no visible element of `root` reaches past the left or right edge of the
 * viewport. An element cut off by an ancestor inside `root` with overflow hidden/clip that is itself on screen
 * counts as contained (text cut with an ellipsis, a clipped preview); a scroll box does not.
 *
 * With stage = true (the projector) stricter: the document does not scroll at all, nothing passes any edge of the
 * viewport, the stage (.scr-stage, which clips with overflow: hidden) holds no more content than it shows, and
 * every visible element on it lies inside the stage's rectangle (±1 px). There an element cut off counts as
 * contained only inside a text box that ends in an ellipsis or a line clamp: anything else cut off by the stage
 * or a box on it is content the room does not see. Two more checks there, on the text itself: no two lines of
 * text of different elements cover each other (rows that overflow inside their own box onto a hint or a note
 * above or below), and no line of text is cut in part by a box that clips it (the descenders of a heading whose
 * box clips at its line height); a line hidden as a whole behind a line clamp is fine. With text = true the two
 * text checks run on the desk and the phone page too (`root` instead of the stage). Returns a list of
 * findings.
 */
export async function layoutFindings(page, root, stage = false, text = stage) {
	return page.evaluate(({ root, stage, text }) => {
		const vw = document.documentElement.clientWidth
		const vh = window.innerHeight
		const out = []
		const de = document.documentElement
		if (de.scrollWidth > vw + 1) out.push(`document scrolls sideways (${de.scrollWidth} > ${vw})`)
		if (stage && de.scrollHeight > vh + 1) out.push(`document scrolls down (${de.scrollHeight} > ${vh})`)
		const top = document.querySelector(root)
		if (!top) return [`no ${root}`]
		const name = (el) => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '')
		const visible = (el, r) => {
			if (r.width <= 1 || r.height <= 1) return false
			const cs = getComputedStyle(el)
			return cs.visibility !== 'hidden' && cs.display !== 'none' && Number(cs.opacity) !== 0
		}
		// The element of a scroll box that reaches furthest right: names the culprit of a sideways scroll.
		const widest = (box) => {
			const edge = box.getBoundingClientRect().left + box.clientLeft + box.clientWidth
			let best = null
			for (const d of box.querySelectorAll('*')) {
				const r = d.getBoundingClientRect()
				if (r.right > edge + 1 && (!best || r.right > best.r)) best = { d, r: r.right }
			}
			return best ? `, widest ${name(best.d)} to ${Math.round(best.r)}` : ''
		}
		const scrollsSideways = (el) => {
			const ox = getComputedStyle(el).overflowX
			return (ox === 'auto' || ox === 'scroll') && el.scrollWidth > el.clientWidth + 1
		}
		for (let a = top; a && a !== document.documentElement; a = a.parentElement) {
			if (scrollsSideways(a)) out.push(`${name(a)} scrolls sideways (${a.scrollWidth} > ${a.clientWidth}${widest(a)})`)
		}
		const VIEW = { left: 0, top: 0, right: vw, bottom: vh }
		const inside = (r, box) => r.left >= box.left - 1 && r.right <= box.right + 1 && r.top >= box.top - 1 && r.bottom <= box.bottom + 1
		const stages = stage ? [...top.querySelectorAll('.scr-stage')] : []
		for (const st of stages) {
			if (st.scrollHeight > st.clientHeight + 1 || st.scrollWidth > st.clientWidth + 1) {
				out.push(`${name(st)} holds more than it shows (${st.scrollWidth}×${st.scrollHeight} > ${st.clientWidth}×${st.clientHeight})`)
			}
		}
		const textCut = (cs) => cs.textOverflow === 'ellipsis' || (cs.webkitLineClamp && cs.webkitLineClamp !== 'none')
		const clipped = (el, onStage, box) => {
			for (let a = el.parentElement; a && a !== top; a = a.parentElement) {
				if (onStage && a === onStage) return false
				const cs = getComputedStyle(a)
				const hides = ['hidden', 'clip'].includes(cs.overflowX) || (stage && ['hidden', 'clip'].includes(cs.overflowY))
				if (hides && (!stage || textCut(cs))) {
					const ar = a.getBoundingClientRect()
					if (stage ? inside(ar, box) : ar.left >= -1 && ar.right <= vw + 1) return true
				}
				if (cs.position === 'fixed') break
			}
			return false
		}
		if (text) {
			// Lines of text: one rectangle per line of each text node, with the element that holds it and the
			// nearest box at or above it that clips vertically.
			const lines = []
			const walker = document.createTreeWalker(top, NodeFilter.SHOW_TEXT, { acceptNode: (n) => n.data.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP })
			for (let t = walker.nextNode(); t; t = walker.nextNode()) {
				const el = t.parentElement
				if (!el || !visible(el, el.getBoundingClientRect())) continue
				// The nearest box that clips vertically; a scroll box on the way ends the search: a line it shows
				// in part is scrolled, not cut, and a line outside its view is not on the screen at all (the desk's
				// side panel, the phone's answer list under its submit bar).
				let clip = null
				let scroller = null
				for (let a = el; a && a !== top.parentElement; a = a.parentElement) {
					const cs = getComputedStyle(a)
					if (!clip && ['hidden', 'clip'].includes(cs.overflowY)) clip = a
					if (['auto', 'scroll'].includes(cs.overflowY) || ['auto', 'scroll'].includes(cs.overflowX)) { scroller = a; break }
				}
				// What the boxes that clip sideways leave of a line (a name cut with an ellipsis): the glyphs
				// past them are not shown and cover nothing.
				const hclip = { left: -Infinity, right: Infinity }
				for (let a = el; a && a !== top.parentElement; a = a.parentElement) {
					if (!['hidden', 'clip'].includes(getComputedStyle(a).overflowX)) continue
					const b = a.getBoundingClientRect()
					hclip.left = Math.max(hclip.left, b.left)
					hclip.right = Math.min(hclip.right, b.right)
				}
				const view = scroller && (() => {
					const b = scroller.getBoundingClientRect()
					const left = b.left + scroller.clientLeft
					const top = b.top + scroller.clientTop
					return { left, top, right: left + scroller.clientWidth, bottom: top + scroller.clientHeight }
				})()
				// r: the glyph area of a line (ascent to descent of the font); box: its line box (line-height), which
				// is smaller when the line-height is tight. Lines stack by their line boxes; descenders reach down to
				// the glyph area's bottom. The room above the capitals (ascent) is mostly empty and not checked.
				const lh = parseFloat(getComputedStyle(el).lineHeight)
				const range = document.createRange()
				range.selectNodeContents(t)
				for (const r of range.getClientRects()) {
					if (r.width <= 1 || r.height <= 1) continue
					const half = (Number.isFinite(lh) && lh < r.height ? lh : r.height) / 2
					const mid = (r.top + r.bottom) / 2
					const box = { left: Math.max(r.left, hclip.left), right: Math.min(r.right, hclip.right), top: mid - half, bottom: mid + half }
					if (box.right - box.left <= 1) continue
					if (view) {
						const w = Math.min(box.right, view.right) - Math.max(box.left, view.left)
						const h = Math.min(box.bottom, view.bottom) - Math.max(box.top, view.top)
						if (w < (box.right - box.left) / 2 || h < (box.bottom - box.top) / 2) continue
					}
					lines.push({ el, clip, r, box })
				}
			}
			const cut = new Set()
			const clipBox = (clip) => {
				const b = clip.getBoundingClientRect()
				return { top: b.top + clip.clientTop, bottom: b.top + clip.clientTop + clip.clientHeight }
			}
			// A line counts as hidden when less than half of its line box lies inside the clipping box: the line
			// after a line clamp starts a few px early when the line before it holds a taller glyph (the ellipsis
			// from a fallback font), and only a sliver of its empty top shows.
			const hidden = (l) => {
				const b = clipBox(l.clip)
				const inside = Math.min(l.box.bottom, b.bottom) - Math.max(l.box.top, b.top)
				return inside < (l.box.bottom - l.box.top) / 2
			}
			// A box its line clamp cuts (the text goes on below) ends in an ellipsis on purpose; there the last
			// shown line has no room below it for descenders that would not also show the next line's top.
			const clamped = (box) => {
				const cs = getComputedStyle(box)
				return cs.webkitLineClamp && cs.webkitLineClamp !== 'none' && box.scrollHeight > box.clientHeight + 1
			}
			for (const l of lines) {
				// Words of the word cloud: the engine places them by its own estimate of their height (line-height 1,
				// util/wordcloud.js) with a margin to the edge; the font's descent below that is not ink.
				if (l.el.closest('.pl-cloud-canvas')) continue
				if (!l.clip || cut.has(l.el) || hidden(l) || clamped(l.clip)) continue
				const b = clipBox(l.clip)
				if (l.r.bottom > b.bottom + 1) {
					cut.add(l.el)
					out.push(`${name(l.el)} text cut by ${name(l.clip)}: descenders down to ${Math.round(l.r.bottom)}, box ends at ${Math.round(b.bottom)}`)
				}
			}
			// Text under aria-hidden is a deliberate second layer (the white copy of a row's text over its bar
			// fill, StageRow) or decoration: it lies on its twin by design and is left out of the overlap check.
			const shown = lines.filter((l) => (!l.clip || !hidden(l)) && !l.el.closest('[aria-hidden="true"]')).map((l) => ({ el: l.el, r: l.box }))
			const pairs = new Set()
			for (let i = 0; i < shown.length && out.length < 8; i++) {
				for (let j = i + 1; j < shown.length; j++) {
					const a = shown[i]
					const b = shown[j]
					if (a.el === b.el || a.el.contains(b.el) || b.el.contains(a.el)) continue
					const w = Math.min(a.r.right, b.r.right) - Math.max(a.r.left, b.r.left)
					const h = Math.min(a.r.bottom, b.r.bottom) - Math.max(a.r.top, b.r.top)
					if (w <= 2 || h <= 2) continue
					const key = name(a.el) + '|' + name(b.el)
					if (pairs.has(key)) continue
					pairs.add(key)
					out.push(`text of ${name(a.el)} and ${name(b.el)} overlap (${Math.round(w)}×${Math.round(h)} at ${Math.round(Math.max(a.r.left, b.r.left))},${Math.round(Math.max(a.r.top, b.r.top))})`)
					if (out.length >= 8) break
				}
			}
		}
		for (const el of top.querySelectorAll('*')) {
			if (out.length >= 8) break
			const r = el.getBoundingClientRect()
			if (!visible(el, r)) continue
			if (scrollsSideways(el)) out.push(`${name(el)} scrolls sideways (${el.scrollWidth} > ${el.clientWidth}${widest(el)})`)
			const onStage = stages.find((st) => st !== el && st.contains(el)) || null
			const box = onStage ? onStage.getBoundingClientRect() : VIEW
			const past = stage ? !inside(r, box) : r.right > vw + 1 || r.left < -1
			if (past && !clipped(el, onStage, box)) out.push(`${name(el)} at ${Math.round(r.left)},${Math.round(r.top)} ${Math.round(r.width)}×${Math.round(r.height)}${onStage ? ` (past the stage ${Math.round(box.left)},${Math.round(box.top)}–${Math.round(box.right)},${Math.round(box.bottom)})` : ''}`)
		}
		return out
	}, { root, stage, text })
}

let shot = 0
export async function expectLayout(page, root, what, stage = false, { text = stage } = {}) {
	if (process.env.PULSE_E2E_SHOTS === '1') {
		await sleep(300)
		mkdirSync(join(OUT, 'shots'), { recursive: true })
		await page.screenshot({ path: join(OUT, 'shots', `${String(++shot).padStart(3, '0')}-${page.e2eLabel}-${what.replace(/[^\w-]+/g, '_')}.png`) })
	}
	// The projector fits its stage in steps over several frames (Screen.vue fitStage/fitPass): give a layout up
	// to 3 s to settle. Content that really runs past stays past and is reported with its last findings.
	let f = []
	const end = Date.now() + 3000
	for (;;) {
		f = await layoutFindings(page, root, stage, text)
		if (f.length === 0 || Date.now() > end) break
		await sleep(200)
	}
	expect(f.length === 0, `${page.e2eLabel} ${what}: runs past the ${stage ? 'stage' : 'screen'}`, f)
}

/** Relative luminance of the first opaque background behind `selector` (body as the last resort). */
export async function backgroundLuminance(page, selector) {
	return page.evaluate((selector) => {
		let el = document.querySelector(selector) || document.body
		for (; el; el = el.parentElement) {
			const m = getComputedStyle(el).backgroundColor.match(/rgba?\(([^)]+)\)/)
			if (!m) continue
			const [r, g, b, a = 1] = m[1].split(',').map((x) => Number(x.trim()))
			if (a < 0.5) continue
			const lin = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4 }
			return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b)
		}
		return 1
	}, selector)
}

export async function expectScheme(page, selector, scheme, what) {
	const l = await backgroundLuminance(page, selector)
	if (scheme === 'dark') expect(l < 0.2, `${page.e2eLabel} ${what}: background not dark`, l.toFixed(3))
	else expect(l > 0.8, `${page.e2eLabel} ${what}: background not light`, l.toFixed(3))
}

/** Rows of a CSV with ";" as the separator and "…" quoting (the moderator's export); a BOM is dropped. */
export function csvRows(text) {
	const rows = []
	let row = []
	let cell = ''
	let quoted = false
	const s = text.replace(/^﻿/, '')
	for (let i = 0; i < s.length; i++) {
		const c = s[i]
		if (quoted) {
			if (c === '"' && s[i + 1] === '"') { cell += '"'; i++ } else if (c === '"') quoted = false
			else cell += c
		} else if (c === '"') quoted = true
		else if (c === ';') { row.push(cell); cell = '' } else if (c === '\n' || c === '\r') {
			if (c === '\r' && s[i + 1] === '\n') i++
			row.push(cell); rows.push(row); row = []; cell = ''
		} else cell += c
	}
	if (cell !== '' || row.length) { row.push(cell); rows.push(row) }
	return rows
}

/** No cell a spreadsheet would run as a formula (the export prefixes those with an apostrophe). */
export function expectNoFormula(rows, label) {
	const bad = rows.flat().filter((c) => /^[=+\-@\t\r]/.test(c) && !/^-?\d[\d.,]*%?$/.test(c))
	expect(bad.length === 0, `${label}: cells that start a formula`, bad.slice(0, 5))
}

/** Reads a Playwright download into a string. */
export async function downloadText(download) {
	const stream = await download.createReadStream()
	const chunks = []
	for await (const c of stream) chunks.push(c)
	return Buffer.concat(chunks).toString('utf8')
}

/** A participant without a browser (the public API, as dev/sim/sim.mjs plays it): for filling a race. */
export class ApiPhone {
	constructor(code) { this.code = code; this.cookie = '' }
	async req(method, path, body) {
		const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
		if (this.cookie) headers.Cookie = this.cookie
		if (body !== undefined) headers['Content-Type'] = 'application/json'
		const r = await fetch(APP + '/s/' + this.code + path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) })
		for (const c of r.headers.getSetCookie()) {
			const m = /^((?:__Host-)?pulse_vt)=([^;]*)/.exec(c)
			if (m && !/;\s*max-age=0/i.test(c)) this.cookie = m[1] + '=' + m[2]
		}
		const text = await r.text()
		let data = text
		try { data = JSON.parse(text) } catch {}
		if (r.status >= 300) throw new Error(`${method} /s/${this.code}${path} -> ${r.status} ${text.slice(0, 200)}`)
		return data
	}
	join(nickname) { return this.req('POST', '/join', { nickname }) }
	next(after) { return this.req('POST', '/next', { after }) }
}

/**
 * Waits until the page has fetched a changed state (HTTP 200 on /state, or on a URL with `part`, within `ms`) and
 * its animations have settled. The countdown ring (.cdr) restarts its one-second transition every second while a
 * question runs: it never settles and is left out (it waited out the 8 s now and then and failed the case).
 */
export async function settled(page, change, part = '/state', ms = 15000) {
	const got = page.waitForResponse((r) => r.url().includes(part) && r.status() === 200, { timeout: ms })
	// a change that throws leaves the wait behind: never an unhandled rejection
	got.catch(() => {})
	await change()
	await got
	page.e2eGot = Date.now()
	await until(() => page.evaluate(() => document.getAnimations().every((a) => a.playState !== 'running' || a.effect?.getTiming().iterations === Infinity || a.effect?.target?.closest?.('.cdr'))), `${page.e2eLabel} animations settle`, 8000, 150)
	await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))))
}

/**
 * Texts that must show whole (STRESS.md S6.1: a cut label hides the only difference): every visible text node of
 * `root` that contains one of `needles` sits in no box that cuts it with an ellipsis or a line clamp.
 */
export async function expectUncut(page, root, needles, what) {
	// like expectLayout: the projector may still be fitting its stage (up to 3 s)
	let f = []
	const end = Date.now() + 3000
	for (;;) {
		f = await uncutFindings(page, root, needles)
		if (f.length === 0 || Date.now() > end) break
		await sleep(200)
	}
	expect(f.length === 0, `${page.e2eLabel} ${what}: text cut that must show whole`, f)
}

async function uncutFindings(page, root, needles) {
	return page.evaluate(({ root, needles }) => {
		const out = []
		const top = document.querySelector(root)
		if (!top) return [`no ${root}`]
		const walker = document.createTreeWalker(top, NodeFilter.SHOW_TEXT)
		for (let t = walker.nextNode(); t; t = walker.nextNode()) {
			if (!needles.some((n) => t.data.includes(n))) continue
			for (let a = t.parentElement; a && a !== top.parentElement; a = a.parentElement) {
				const cs = getComputedStyle(a)
				const clamps = cs.textOverflow === 'ellipsis' || (cs.webkitLineClamp && cs.webkitLineClamp !== 'none')
				if (clamps && (a.scrollHeight > a.clientHeight + 1 || a.scrollWidth > a.clientWidth + 1)) {
					out.push(`"${t.data.slice(-20)}" cut by ${a.tagName.toLowerCase()}.${String(a.className).split(/\s+/)[0]}`)
					break
				}
			}
		}
		return out
	}, { root, needles })
}

/**
 * The question shows whole (STRESS.md S1.1): under `root` an element holds exactly its text (white space squashed),
 * and neither that element nor a box above it up to `root` cuts it: no line clamp, no ellipsis, nothing hidden by
 * overflow (scrollHeight, scrollWidth), and it lies inside every box that clips (±1 px). The projector may still be
 * fitting (up to 3 s). Returns the font size of the question in px (for a comparison of sizes).
 */
export async function expectWholeQuestion(page, root, question, what) {
	let r = { out: [] }
	const end = Date.now() + 3000
	for (;;) {
		r = await wholeQuestionFindings(page, root, question)
		if (r.out.length === 0 || Date.now() > end) break
		await sleep(200)
	}
	expect(r.out.length === 0, `${page.e2eLabel} ${what}: question not whole`, r.out)
	return r.fontSize
}

async function wholeQuestionFindings(page, root, question) {
	return page.evaluate(({ root, question }) => {
		const squash = (s) => s.replace(/\s+/g, ' ').trim()
		const want = squash(question)
		const top = document.querySelector(root)
		if (!top) return { out: [`no ${root}`] }
		const visible = (el) => { const b = el.getBoundingClientRect(); return b.width > 0 && b.height > 0 }
		const els = [...top.querySelectorAll('*')].filter((el) => !el.children.length && squash(el.textContent) === want && visible(el))
		if (!els.length) {
			const cut = [...top.querySelectorAll('*')].find((el) => !el.children.length && el.textContent.length > 8 && want.startsWith(squash(el.textContent).replace(/[…]$/, '').slice(0, 30)) && visible(el))
			return { out: [cut ? `only "${squash(cut.textContent).slice(-30)}" shows` : `no element shows the question "${want.slice(0, 40)}"`] }
		}
		const out = []
		for (const el of els) {
			const name = (a) => `${a.tagName.toLowerCase()}.${String(a.className).split(/\s+/)[0]}`
			// the part of the question a box above has to show: past a scroll box only what that box shows
			const r = el.getBoundingClientRect()
			const b = { top: r.top, bottom: r.bottom, left: r.left, right: r.right }
			for (let a = el; a && a !== top.parentElement; a = a.parentElement) {
				const cs = getComputedStyle(a)
				if (cs.webkitLineClamp && cs.webkitLineClamp !== 'none') out.push(`line clamp ${cs.webkitLineClamp} on ${name(a)}`)
				if (cs.textOverflow === 'ellipsis' && a.scrollWidth > a.clientWidth + 1) out.push(`ellipsis on ${name(a)}`)
				if (a === el && (a.scrollHeight > a.clientHeight + 1 || a.scrollWidth > a.clientWidth + 1)) out.push(`${name(a)} overflows (${a.scrollWidth}×${a.scrollHeight} in ${a.clientWidth}×${a.clientHeight})`)
				if (a !== el && (cs.overflowX !== 'visible' || cs.overflowY !== 'visible')) {
					const c = a.getBoundingClientRect()
					// a scrolling axis (desk, phone) shows the rest when scrolled: only an axis that hides counts
					const hides = (v) => v === 'hidden' || v === 'clip'
					const outY = hides(cs.overflowY) && (b.top < c.top - 1 || b.bottom > c.bottom + 1)
					const outX = hides(cs.overflowX) && (b.left < c.left - 1 || b.right > c.right + 1)
					if (outY || outX) out.push(`cut by ${name(a)} (${Math.round(b.top)}–${Math.round(b.bottom)} in ${Math.round(c.top)}–${Math.round(c.bottom)})`)
					const scrolls = (v) => v === 'auto' || v === 'scroll'
					if (scrolls(cs.overflowY)) { b.top = Math.max(b.top, c.top); b.bottom = Math.min(b.bottom, c.bottom) }
					if (scrolls(cs.overflowX)) { b.left = Math.max(b.left, c.left); b.right = Math.min(b.right, c.right) }
				}
			}
		}
		return { out: [...new Set(out)], fontSize: parseFloat(getComputedStyle(els[0]).fontSize) }
	}, { root, question })
}

/**
 * Labels split into a start and a distinguishing end (StageRow `tail`, STRESS.md S6.1, S4.5) read in their own
 * direction: a label whose first strong letter is right-to-left runs right-to-left, and its end stands after the start
 * in reading order (left of it there, right of it otherwise).
 */
export async function expectSplitOrder(page, root, what) {
	const f = await page.evaluate((root) => {
		const out = []
		for (const lab of document.querySelectorAll(root + ' .srow-text--track .srow-label--split')) {
			const head = lab.querySelector('.srow-head')
			const tail = lab.querySelector('.srow-tail')
			if (!head || !tail) continue
			const strong = (lab.textContent.match(/[A-Za-z\u00C0-\u024F\u0370-\u04FF\u0590-\u08FF]/) || [''])[0]
			const rtl = /[\u0590-\u08FF]/.test(strong)
			const dir = getComputedStyle(lab).direction
			const h = head.getBoundingClientRect()
			const t = tail.getBoundingClientRect()
			const name = '"' + tail.textContent.slice(-14) + '"'
			if ((dir === 'rtl') !== rtl) out.push(`${name} runs ${dir}, its text is ${rtl ? 'rtl' : 'ltr'}`)
			else if (rtl ? t.right > h.left + 1 : t.left < h.right - 1) out.push(`${name} end stands before its start (${dir})`)
		}
		return out
	}, root)
	expect(f.length === 0, `${page.e2eLabel} ${what}: split label out of reading order`, f)
}

/** Uploads `bytes` as the image of a question (moderator API, multipart field "image"). */
export async function uploadImage(code, pollId, bytes, type = 'image/png') {
	const form = new FormData()
	form.append('image', new Blob([bytes], { type }), 'stress.png')
	const r = await fetch(APP + '/api/1.0/rooms/' + code + '/polls/' + pollId + '/image', { method: 'POST', headers: { Authorization: AUTH, 'OCS-APIRequest': 'true', Accept: 'application/json' }, body: form })
	const text = await r.text()
	if (r.status >= 300) throw new Error(`image upload -> ${r.status} ${text.slice(0, 200)}`)
	return text
}
