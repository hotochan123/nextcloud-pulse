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
	projectorSmall: { viewport: { width: 1366, height: 768 } },
}

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
	const r = await fetch(APP + '/api/1.0/rooms' + path, {
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

	/** A fresh page on a device; moderator pages get the stored login. */
	async page(device, scheme = 'light', { moderator = false, label = '' } = {}) {
		const ctx = await this.browser.newContext({
			...DEVICES[device],
			colorScheme: scheme,
			locale: 'en-US',
			acceptDownloads: true,
			storageState: moderator ? this.auth : undefined,
		})
		this.contexts.push(ctx)
		const page = await ctx.newPage()
		page.setDefaultTimeout(15000)
		page.e2eLabel = label || `${device}-${scheme}`
		page.e2eTouch = !!DEVICES[device].hasTouch
		page.e2eErrors = []
		page.on('pageerror', (e) => page.e2eErrors.push(e.message))
		this.pages.push(page)
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
 * or a box on it is content the room does not see. Returns a list of findings.
 */
export async function layoutFindings(page, root, stage = false) {
	return page.evaluate(({ root, stage }) => {
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
		const scrollsSideways = (el) => {
			const ox = getComputedStyle(el).overflowX
			return (ox === 'auto' || ox === 'scroll') && el.scrollWidth > el.clientWidth + 1
		}
		for (let a = top; a && a !== document.documentElement; a = a.parentElement) {
			if (scrollsSideways(a)) out.push(`${name(a)} scrolls sideways (${a.scrollWidth} > ${a.clientWidth})`)
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
		for (const el of top.querySelectorAll('*')) {
			if (out.length >= 8) break
			const r = el.getBoundingClientRect()
			if (!visible(el, r)) continue
			if (scrollsSideways(el)) out.push(`${name(el)} scrolls sideways (${el.scrollWidth} > ${el.clientWidth})`)
			const onStage = stages.find((st) => st !== el && st.contains(el)) || null
			const box = onStage ? onStage.getBoundingClientRect() : VIEW
			const past = stage ? !inside(r, box) : r.right > vw + 1 || r.left < -1
			if (past && !clipped(el, onStage, box)) out.push(`${name(el)} at ${Math.round(r.left)},${Math.round(r.top)} ${Math.round(r.width)}×${Math.round(r.height)}${onStage ? ` (past the stage ${Math.round(box.left)},${Math.round(box.top)}–${Math.round(box.right)},${Math.round(box.bottom)})` : ''}`)
		}
		return out
	}, { root, stage })
}

let shot = 0
export async function expectLayout(page, root, what, stage = false) {
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
		f = await layoutFindings(page, root, stage)
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

/** Waits until the page has fetched a changed state (HTTP 200 on /state) and its animations have settled. */
export async function settled(page, change) {
	const got = page.waitForResponse((r) => r.url().includes('/state') && r.status() === 200, { timeout: 15000 })
	await change()
	await got
	await until(() => page.evaluate(() => document.getAnimations().every((a) => a.playState !== 'running' || a.effect?.getTiming().iterations === Infinity)), `${page.e2eLabel} animations settle`, 8000, 150)
	await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))))
}
