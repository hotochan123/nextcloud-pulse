#!/usr/bin/env node
/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Takes screenshots of every Pulse view.
 *
 * Drives the Firefox installed on the server through geckodriver (WebDriver
 * over HTTP, no npm dependency). The state of the rooms is not set by the
 * browser but server-side by probe.php, so views that are otherwise only
 * reachable by clicking can be captured as well.
 *
 *   node shoot.mjs <probe.json> [output-dir]
 *
 * Section references (§…), acceptance criteria ("acceptance #7") and review
 * IDs (R3, D10, …) point to the design notes of the redesign and of the
 * self-paced quiz, which are not in the public repository (see "References in
 * code comments" in the README).
 * Only references that name a file (docs/APPSTORE.md §3) point into this
 * repository.
 */

import { execFileSync, spawn } from 'node:child_process'
import { existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
/**
 * Which instance to run against. PULSE_HOST beats everything; otherwise
 * dev/design-shots/.host may hold a name (the file is not in the repo: an
 * internal hostname does not belong in a public repository). Deliberately no
 * default: a foreign address as a fallback would send the throwaway user's
 * login to an instance where that user does not even exist.
 */
function hostFromFile() {
	const f = join(HERE, '.host')
	return existsSync(f) ? readFileSync(f, 'utf8').trim() : ''
}
const HOST = process.env.PULSE_HOST || hostFromFile()
if (!HOST) {
	console.error('PULSE_HOST setzen oder dev/design-shots/.host anlegen (eine Zeile, z. B. https://nextcloud.example.com)')
	process.exit(1)
}
const CONTAINER = process.env.CONTAINER || 'nextcloud-nextcloud-1'
const APP_IN_CONTAINER = process.env.APP_IN_CONTAINER || '/var/www/html/apps/pulse'
const UID = process.env.PULSE_SHOTS_UID || 'pulse-shots'
const LANG = process.env.PULSE_SHOTS_LANG || 'en-US, en'
const PORT = Number(process.env.PULSE_SHOTS_PORT || 4444)
const BASE = `http://127.0.0.1:${PORT}`

const DESKTOP = [1920, 1080]
const PHONE = [390, 844]
const LAPTOP = [1440, 900]
// Store page: 16:9 at the width apps.nextcloud.com displays. Captured
// at double pixel density, so the image is 2400 px wide and stays
// sharp on high-resolution screens.
const STORE = [1200, 675]
const STORE_MOD = [1280, 720]

const probeData = JSON.parse(readFileSync(process.argv[2], 'utf8'))
// Self-paced rooms (probe.php pace-create), only for the pace passes.
const PACE_FILE = join(dirname(process.argv[2]), 'pace.json')
const paceData = existsSync(PACE_FILE) ? JSON.parse(readFileSync(PACE_FILE, 'utf8')) : null
const OUT = process.argv[3] || join(HERE, 'out')
mkdirSync(OUT, { recursive: true })

const index = []
// Stages that run past their box; the kiosk clips them silently.
const overflow = []
let shotNo = 0

/** Set server-side state (probe.php in the container). */
function probe(...args) {
	return execFileSync('docker', [
		'exec', '-u', 'www-data', CONTAINER, 'php',
		`${APP_IN_CONTAINER}/dev/design-shots/probe.php`, ...args,
	], { encoding: 'utf8' }).trim()
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

/* ---------------------------------------------------------------- WebDriver */

async function call(method, path, body) {
	const res = await fetch(BASE + path, {
		method,
		headers: { 'Content-Type': 'application/json' },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	const json = await res.json()
	if (json.value && json.value.error) {
		throw new Error(`${json.value.error}: ${String(json.value.message).split('\n')[0]}`)
	}
	return json.value
}

/** One browser session; `dark` switches the system theme. */
async function newSession(dark, extraPrefs = {}) {
	const value = await call('POST', '/session', {
		capabilities: {
			alwaysMatch: {
				browserName: 'firefox',
				acceptInsecureCerts: true,
				'moz:firefoxOptions': {
					args: ['-headless'],
					prefs: {
						'intl.accept_languages': LANG,
						'ui.systemUsesDarkTheme': dark ? 1 : 0,
						'browser.startup.homepage_override.mstone': 'ignore',
						...extraPrefs,
					},
				},
			},
		},
	})
	const id = value.sessionId
	const s = {
		id,
		close: () => call('DELETE', `/session/${id}`).catch(() => {}),
		go: (url) => call('POST', `/session/${id}/url`, { url }),
		size: ([width, height]) => call('POST', `/session/${id}/window/rect`, { x: 0, y: 0, width, height }),
		script: (script, args = []) => call('POST', `/session/${id}/execute/sync`, { script, args }),
		async: (script, args = []) => call('POST', `/session/${id}/execute/async`, { script, args }),
		png: () => call('GET', `/session/${id}/screenshot`),
		find: async (sel) => {
			const el = await call('POST', `/session/${id}/element`, { using: 'css selector', value: sel })
			return Object.values(el)[0]
		},
		click: async (sel) => call('POST', `/session/${id}/element/${await s.find(sel)}/click`, {}),
		type: async (sel, text) => call('POST', `/session/${id}/element/${await s.find(sel)}/value`, { text }),
		// Fetch a cookie, remove it, put it back: a second phone in the same session.
		cookie: (name) => call('GET', `/session/${id}/cookie/${name}`),
		dropCookie: (name) => call('DELETE', `/session/${id}/cookie/${name}`),
		addCookie: (cookie) => call('POST', `/session/${id}/cookie`, { cookie }),
		// The voter cookie is __Host-pulse_vt on https with Nextcloud at the
		// domain root and pulse_vt elsewhere (PublicVoteController::voterCookie).
		voterCookie: async () => {
			try {
				return await s.cookie('__Host-pulse_vt')
			} catch (e) {
				return s.cookie('pulse_vt')
			}
		},
		// Put a voter cookie back (on the page of its host). Without `domain`:
		// Firefox would store it as a domain cookie, which a __Host- cookie must
		// not be (NS_ERROR_ILLEGAL_VALUE); without it, it is host-only again.
		addVoterCookie: (cookie) => {
			const { domain, ...hostOnly } = cookie
			return s.addCookie(hostOnly)
		},
		// Deleting a cookie that is not there is no error in WebDriver.
		dropVoterCookie: async () => {
			await s.dropCookie('__Host-pulse_vt')
			await s.dropCookie('pulse_vt')
		},
		// Pointer into the corner: otherwise the hover of the last clicked button stays in the picture.
		// Two steps: the input source remembers its position, so a second (1,1) would not be a move.
		mouseAway: () => call('POST', `/session/${id}/actions`, { actions: [{ type: 'pointer', id: 'shots-mouse', parameters: { pointerType: 'mouse' },
			actions: [{ type: 'pointerMove', duration: 0, x: 4, y: 4, origin: 'viewport' }, { type: 'pointerMove', duration: 0, x: 1, y: 1, origin: 'viewport' }] }] }),
		// Switch into an iframe (element ID) or back to the main document (null).
		frame: (el) => call('POST', `/session/${id}/frame`, { id: el === null ? null : { 'element-6066-11e4-a52e-4f735466cecf': el } }),
	}
	return s
}

/** Wait for an element; polling is more honest here than a fixed timer. */
async function waitFor(s, sel, timeout = 15000) {
	const until = Date.now() + timeout
	while (Date.now() < until) {
		if (await s.script('return !!document.querySelector(arguments[0])', [sel])) { return true }
		await sleep(250)
	}
	throw new Error(`Element blieb aus: ${sel}`)
}

/**
 * One capture: set the window size, load the page, wait for the anchor,
 * run optional clicks, let things settle briefly, take the shot.
 */
async function shot(s, { name, url, open, size, waitSel, actions = [], settle = 1200, note = '', measure = '' }) {
	await s.size(size)
	// open: a custom route to the page (e.g. through a menu); url then only appears in the index.
	if (open) { await open() } else if (url) { await s.go(HOST + url) }
	if (waitSel) { await waitFor(s, waitSel) }
	for (const act of actions) {
		if (act.click) { await s.click(act.click) }
		if (act.js) { await s.script(act.js) }
		if (act.type) { await s.type(act.type[0], act.type[1]) }
		if (act.wait) { await waitFor(s, act.wait) }
		// Wait until an expression is true (e.g. in the iframe), without aborting:
		// whatever is missing then shows up in the measurement line.
		if (act.until) {
			const end = Date.now() + (act.timeout || 10000)
			while (Date.now() < end && !(await s.script('return !!(' + act.until + ')'))) { await sleep(250) }
		}
		if (act.sleep) { await sleep(act.sleep) }
	}
	await sleep(settle)
	const file = `${String(++shotNo).padStart(2, '0')}-${name}.png`
	writeFileSync(join(OUT, file), Buffer.from(await s.png(), 'base64'))
	// The projector is a clipped kiosk: whatever sticks out past the stage is
	// cut off silently and only visible in the picture if you know what to
	// look for. That is why the test rig measures it itself
	// (acceptance criterion §5.13) and writes the result into the index.
	const fit = await s.script(`
		const el = document.querySelector('.scr-stage')
		if (!el) { return null }
		return { over: el.scrollHeight - el.clientHeight, used: Math.round(el.scrollHeight / el.clientHeight * 100),
			fs: getComputedStyle(el).fontSize }
	`)
	let flag = ''
	if (fit) {
		flag = fit.over > 1 ? ` **läuft über: ${fit.over} px**` : ` (Bühne ${fit.used} %, ${fit.fs})`
		if (fit.over > 1) { overflow.push(`${file}: ${fit.over} px`) }
	}
	// Toggles carry fixed text plus a check mark (R3), red items come last (R4),
	// and Escape returns the focus (acceptance #7/#8/#10); all three can at best
	// be guessed from the picture.
	const menu = await s.script(`
		const trig = document.querySelector('.deck-head .pulse-menu > .pulse-btn')
		if (!trig) { return null }
		const list = document.querySelector('.pulse-menu-list')
		const items = list ? Array.from(list.querySelectorAll('.pulse-menu-item')) : []
		if (!list && document.activeElement !== trig) { return null }
		return { open: !!list, focusOnTrigger: document.activeElement === trig,
			checks: items.filter((b) => b.getAttribute('role') === 'menuitemcheckbox').length,
			lastDanger: items.length ? items[items.length - 1].classList.contains('is-danger') : null }
	`)
	if (menu) {
		flag += menu.open
			? ` (Menü offen: ${menu.checks} Schalter mit Häkchen, letzter Eintrag ${menu.lastDanger ? 'rot' : 'NICHT rot'})`
			: ` (Menü zu, Fokus ${menu.focusOnTrigger ? 'zurück am Auslöser' : 'verloren'})`
		if (menu.open && !menu.lastDanger) { overflow.push(`${file}: letzter Menüeintrag ist nicht der rote`) }
		if (!menu.open && !menu.focusOnTrigger) { overflow.push(`${file}: Fokus nach Escape verloren`) }
	}
	const plain = await s.script(`
		const root = document.querySelector('.pulse-mod')
		if (!root || root.querySelector('.mod-live, .pace-run')) { return null }
		const bs = Array.from(root.querySelectorAll('.pulse-btn')).filter((b) => {
			if (b.offsetParent === null) { return false }
			const bg = getComputedStyle(b).backgroundColor
			return bg && !/rgba\\(0, 0, 0, 0\\)|transparent/.test(bg)
		})
		return { n: bs.length, labels: bs.map((b) => b.textContent.trim().replace(/\\s+/g, ' ')) }
	`)
	if (plain) {
		flag += ` (${plain.n} gefüllter Knopf${plain.n ? ': „' + plain.labels.join('“, „') + '“' : ''})`
		if (plain.n > 1) { overflow.push(`${file}: ${plain.n} gefüllte Knöpfe`) }
	}
	// Room card, deck header and deck row are button rows that wrap when space
	// gets tight. How many buttons sit there and across how many lines they run
	// could be counted in the picture; here it is given as a number.
	const cluster = await s.script(`
		const rows = (el, sel) => {
			if (!el) { return null }
			const bs = Array.from(el.querySelectorAll(sel || '.pulse-btn'))
			if (!bs.length) { return null }
			const mid = bs.map((b) => { const r = b.getBoundingClientRect(); return r.top + r.height / 2 })
				.sort((a, b) => a - b)
			let n = 1
			for (let i = 1; i < mid.length; i++) { if (mid[i] - mid[i - 1] > 10) { n++ } }
			return { n: bs.length, rows: n }
		}
		const head = rows(document.querySelector('.deck-head'), ':scope > .pulse-btn, :scope > .pulse-menu > .pulse-btn')
		const card = rows(document.querySelector('.myroom'), '.pulse-btn, .myroom-hit')
		const item = rows(document.querySelector('.deck-item'))
		if (item) { item.rows = 0 }
		if (!head && !card && !item) { return null }
		return { head, card, item }
	`)
	if (cluster) {
		const part = (l, c) => (c ? `${l} ${c.n} Knöpfe${c.rows ? ` in ${c.rows} Zeile${c.rows > 1 ? 'n' : ''}` : ''}` : null)
		flag += ' (' + [part('Deck-Kopf:', cluster.head), part('Raumkarte:', cluster.card),
			part('Deck-Zeile:', cluster.item)].filter(Boolean).join(', ') + ')'
	}
	// Phone: the fold rule from §8.1. What scrolls is stated here instead of having
	// to be spotted in the picture. Scrolling is allowed (from 7 options on), but it
	// should be visible WHEN it happens.
	const fold = await s.script(`
		const sc = document.querySelector('.h-scroll'), sub = document.querySelector('.h-submit')
		if (!sc) { return null }
		const r = sub ? sub.getBoundingClientRect() : null
		return { over: sc.scrollHeight - sc.clientHeight, cards: document.querySelectorAll('.choice').length,
			submitVisible: !r || r.bottom <= window.innerHeight + 1 }
	`)
	if (fold) {
		flag += fold.over > 1 ? ` (Antwortbereich scrollt ${fold.over} px, ${fold.cards} Karten)` : ' (nichts scrollt)'
		if (!fold.submitVisible) { overflow.push(`${file}: Absende-Leiste unter der Falz`) }
	}
	// Moderator: "exactly ONE filled button" (§9.9 #1) and "the private panel is
	// not visible in fullscreen" (#6) can only be guessed from the picture. So they
	// are counted here; filled means: its own background, not transparent.
	const mod = await s.script(`
		const live = document.querySelector('.mod-live')
		if (!live) { return null }
		const filled = Array.from(live.querySelectorAll('.pulse-btn')).filter((b) => {
			const bg = getComputedStyle(b).backgroundColor
			return bg && !/rgba\\(0, 0, 0, 0\\)|transparent/.test(bg)
		})
		const p = live.querySelector('.mod-primary')
		const bar = live.querySelector('.mod-bar')
		const br = bar ? bar.getBoundingClientRect() : null
		const fs = !!document.querySelector('.pulse-mod.is-fs')
		const cv = live.querySelector('.mod-canvas'), cin = live.querySelector('.mod-canvas-in')
		const root = document.querySelector('.pulse-mod')
		return { fs, barVisible: fs || !br || br.bottom <= window.innerHeight + 1,
			filled: filled.map((b) => b.textContent.trim().replace(/\\s+/g, ' ')),
			primary: p ? p.textContent.trim().replace(/\\s+/g, ' ') : null,
			root: root ? root.clientWidth + '×' + root.clientHeight : null,
			canvas: cv ? cv.clientWidth + '×' + cv.clientHeight : null,
			shown: cin ? cin.clientWidth + '×' + cin.clientHeight : null,
			private: !!live.querySelector('.mod-private'),
			join: !!live.querySelector('.mod-joinpanel') }
	`)
	if (mod) {
		flag += ` (Hauptaktion „${mod.primary}", ${mod.filled.length} gefüllter Knopf${mod.private ? ', privates Panel da' : ', kein privates Panel'}${mod.join ? ', Beitritt offen' : ''}${mod.fs ? ', Vollbild — Leiste auf Hover' : ''}, Vorschau ${mod.shown} in ${mod.canvas}, Rahmen ${mod.root})`
		if (mod.filled.length !== 1) { overflow.push(`${file}: ${mod.filled.length} gefüllte Knöpfe (${mod.filled.join(' · ')})`) }
		if (!mod.barVisible) { overflow.push(`${file}: Steuerleiste unter der Falz`) }
	}
	// Self-paced run view (§6.2): like the presentation, exactly ONE
	// filled button (dialogs excluded), the bar above the fold, never an
	// answer key (.tg-accepted) and never horizontal scrolling of the page.
	const run = await s.script(`
		const run = document.querySelector('.pace-run')
		if (!run) { return null }
		const txt = (el) => (el ? el.textContent.trim().replace(/\\s+/g, ' ') : '')
		const filled = Array.from(run.querySelectorAll('.pulse-btn')).filter((b) => {
			if (b.offsetParent === null || b.closest('.pod')) { return false }
			const bg = getComputedStyle(b).backgroundColor
			return bg && !/rgba\\(0, 0, 0, 0\\)|transparent/.test(bg)
		})
		const bar = run.querySelector('.pace-bar')
		const br = bar ? bar.getBoundingClientRect() : null
		const p = run.querySelector('.pace-primary')
		const wrap = run.querySelector('.pace-table-wrap')
		const side = run.querySelector('.pace-side')
		const de = document.documentElement
		return { filled: filled.map(txt), primary: p ? txt(p) + (p.disabled ? ' (aus)' : '') : null,
			barVisible: !br || br.bottom <= window.innerHeight + 1,
			accepted: run.querySelectorAll('.tg-accepted').length,
			pageX: Math.max(de.scrollWidth - de.clientWidth, run.scrollWidth - run.clientWidth),
			tableX: wrap ? wrap.scrollWidth - wrap.clientWidth : 0,
			sideX: side ? side.scrollWidth - side.clientWidth : 0 }
	`)
	if (run) {
		flag += ` (Laufansicht: Hauptaktion „${run.primary}", ${run.filled.length} gefüllter Knopf, Leiste ${run.barVisible ? 'über der Falz' : 'UNTER DER FALZ'}, ${run.accepted} .tg-accepted, waagerecht Seite ${run.pageX} px / Tabelle ${run.tableX} px / Seitenspalte ${run.sideX} px)`
		if (run.sideX > 0) { overflow.push(`${file}: Seitenspalte scrollt waagerecht (${run.sideX} px)`) }
		if (run.filled.length !== 1) { overflow.push(`${file}: ${run.filled.length} gefüllte Knöpfe in .pace-run (${run.filled.join(' · ')})`) }
		if (!run.barVisible) { overflow.push(`${file}: .pace-bar unter der Falz`) }
		if (run.accepted) { overflow.push(`${file}: Lösungsschlüssel (.tg-accepted) in der Laufansicht`) }
		if (run.pageX > 0) { overflow.push(`${file}: Seite scrollt waagerecht (${run.pageX} px)`) }
	}
	// Start screen: the first room card must sit entirely above the fold
	// (§9.9 #7), and the inactive side of the segmented switch must be readable (#8).
	const startFold = await s.script(`
		const card = document.querySelector('.myroom')
		const seg = document.querySelector('.pseg-item:not(.is-active)')
		if (!card && !seg) { return null }
		const lum = (c) => {
			const [r, g, b] = c.match(/[\\d.]+/g).slice(0, 3).map(Number).map((v) => {
				const x = v / 255
				return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4)
			})
			return 0.2126 * r + 0.7152 * g + 0.0722 * b
		}
		const solidBg = (el) => {
			for (let n = el; n; n = n.parentElement) {
				const bg = getComputedStyle(n).backgroundColor
				if (bg && !/rgba\\(0, 0, 0, 0\\)|transparent/.test(bg)) { return bg }
			}
			return 'rgb(255, 255, 255)'
		}
		let ratio = null
		if (seg) {
			const a = lum(getComputedStyle(seg).color), b = lum(solidBg(seg))
			ratio = Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 10) / 10
		}
		const cards = Array.from(document.querySelectorAll('.myroom'))
		return { cardBottom: card ? Math.round(card.getBoundingClientRect().bottom) : null,
			whole: cards.filter((c) => c.getBoundingClientRect().bottom <= window.innerHeight).length,
			total: cards.length, fold: window.innerHeight, ratio }
	`)
	if (startFold) {
		if (startFold.cardBottom !== null) {
			const ok = startFold.whole >= Math.min(2, startFold.total)
			flag += ` (${startFold.whole} von ${startFold.total} Raumkarten ganz über der Falz, erste endet bei ${startFold.cardBottom} von ${startFold.fold} px${ok ? '' : ' — ZU WENIG'})`
			if (!ok) { overflow.push(`${file}: nur ${startFold.whole} von ${startFold.total} Raumkarten über der Falz`) }
		}
		if (startFold.ratio !== null) {
			flag += ` (inaktive Umschalter-Seite ${startFold.ratio}:1)`
			if (startFold.ratio < 4.5) { overflow.push(`${file}: Umschalter-Kontrast ${startFold.ratio}:1 < 4,5:1`) }
		}
	}
	if (measure) {
		const m = await s.script(measure)
		if (m) { flag += ` (${m})` }
	}
	index.push(`| ${file} | ${size.css || size[0] + '×' + size[1]} | ${url || '(gleiche Seite)'} | ${note}${flag} |`)
	process.stdout.write(`  ${file}${fit && fit.over > 1 ? `  ← Bühne läuft ${fit.over} px über` : ''}\n`)
}

/* -------------------------------------------------------------- Capture plan */

const poll = probeData.poll
const quiz = probeData.quiz

async function publicPass(s, dark) {
	const tag = dark ? 'dark-' : ''

	await shot(s, {
		name: `${tag}join`, url: '/apps/pulse/join', size: PHONE,
		waitSel: '.pulse-part', note: 'Beitritt (Handy)',
	})
	// Lobby: no cursor -> the projector shows the code and QR.
	probe('state', poll.code, '0', 'open')
	await shot(s, {
		name: `${tag}screen-lobby`, url: `/apps/pulse/screen/${poll.code}`, size: DESKTOP,
		waitSel: '.scr-lobby', note: 'Beamer Lobby (QR + Code)',
	})

	for (const room of [poll, quiz]) {
		const mode = room === poll ? 'poll' : 'quiz'
		if (mode === 'quiz') {
			// In a quiz the phone first needs a name; the session keeps the
			// voter cookie, which is enough for all following captures.
			probe('state', room.code, String(room.polls[0].id), 'open')
			await shot(s, {
				name: `${tag}phone-quiz-nickname`, url: `/apps/pulse/s/${room.code}`, size: PHONE,
				waitSel: '.nick-input', note: 'Handy: Name eingeben (Quiz)',
			})
			await shot(s, {
				name: `${tag}phone-quiz-joined`, size: PHONE,
				actions: [{ type: ['.nick-input', 'Alex'] }, { click: '.nick .submit' }, { sleep: 1500 }],
				note: 'Handy: gerade beigetreten',
			})
		}

		for (const p of room.polls) {
			probe('state', room.code, String(p.id), 'open')
			probe('seed', room.code, '24')
			// Fake presence: otherwise the intake display shows "0 of 0".
			probe('present', room.code, '24')
			await shot(s, {
				name: `${tag}screen-${mode}-${p.label}-open`, url: `/apps/pulse/screen/${room.code}`, size: DESKTOP,
				waitSel: '.scr-stage', note: `Beamer ${mode}/${p.label}, offen mit 24 Stimmen`,
			})
			if (!dark) {
				await shot(s, {
					name: `phone-${mode}-${p.label}-vote`, url: `/apps/pulse/s/${room.code}`, size: PHONE,
					waitSel: '.pulse-part', note: `Handy ${mode}/${p.label}, Abstimm-Ansicht`,
				})
			}
			probe('state', room.code, String(p.id), 'locked')
			await shot(s, {
				name: `${tag}screen-${mode}-${p.label}-locked`, url: `/apps/pulse/screen/${room.code}`, size: DESKTOP,
				waitSel: '.scr-stage', note: `Beamer ${mode}/${p.label}, aufgelöst`,
			})
			if (!dark && p.label === 'choice') {
				await shot(s, {
					name: `phone-${mode}-${p.label}-result`, url: `/apps/pulse/s/${room.code}`, size: PHONE,
					waitSel: '.pulse-part', note: `Handy ${mode}/${p.label}, Auflösung`,
				})
			}
		}
	}

	// End the quiz -> final standings stage + phone wrap-up.
	probe('end', quiz.code)
	await shot(s, {
		name: `${tag}screen-quiz-final`, url: `/apps/pulse/screen/${quiz.code}`, size: DESKTOP,
		waitSel: '.scr-final', note: 'Beamer: Endstand am Quiz-Ende',
		settle: 5000,
	})
	if (!dark) {
		await shot(s, {
			name: 'phone-quiz-ended', url: `/apps/pulse/s/${quiz.code}`, size: PHONE,
			waitSel: '.pulse-part', note: 'Handy: Quiz beendet',
		})
	}
}

/*
 * Edge cases of the stage grammar (§2.6, test cases from §11). They do not
 * occur in the existing data but decide the acceptance: the row cap with two
 * options, the shrink loop with eight, a two-line label with descenders and
 * the two final standings (nine and two participants).
 */
async function edgePass(s) {
	const edge = probeData.edge
	// Open stage: the states the intake display depends on (§6.6).
	// Presence comes from faked heartbeats; without them it would show
	// "0", because there is no real phone in the room.
	if (edge) {
		const first = edge.polls[0]
		probe('state', edge.code, String(first.id), 'open')
		probe('present', edge.code, '24')
		await shot(s, {
			name: 'screen-edge-intake-empty', url: `/apps/pulse/screen/${edge.code}`, size: DESKTOP,
			waitSel: '.scr-stage', note: 'Beamer offen: 0 von 24 geantwortet',
		})
		probe('seed', edge.code, '24')
		probe('present', edge.code, '24')
		await shot(s, {
			name: 'screen-edge-intake-full', url: `/apps/pulse/screen/${edge.code}`, size: DESKTOP,
			waitSel: '.scr-stage', note: 'Beamer offen: alle haben geantwortet',
		})
		probe('present', edge.code, '240')
		await shot(s, {
			name: 'screen-edge-intake-240', url: `/apps/pulse/screen/${edge.code}`, size: DESKTOP,
			waitSel: '.scr-stage', note: 'Beamer offen: 240 Verbundene (Balkenstufe)',
		})
	}

	// The row model at its limits: two options, eight, a long label.
	if (edge) {
		for (const p of edge.polls) {
			probe('state', edge.code, String(p.id), 'open')
			probe('seed', edge.code, '24')
			if (p.label === 'long-label') {
				await shot(s, {
					name: `screen-edge-${p.label}-open`, url: `/apps/pulse/screen/${edge.code}`, size: DESKTOP,
					waitSel: '.scr-stage', note: `Beamer Grenzfall ${p.label}, offen`,
				})
			}
			probe('state', edge.code, String(p.id), 'locked')
			await shot(s, {
				name: `screen-edge-${p.label}-locked`, url: `/apps/pulse/screen/${edge.code}`, size: DESKTOP,
				waitSel: '.scr-stage', note: `Beamer Grenzfall ${p.label}, aufgelöst`,
			})
		}
	}
	// Countdown at the urgency level: a quiz question with 9 seconds left.
	const quizFirst = quiz.polls[0]
	probe('state', quiz.code, String(quizFirst.id), 'open')
	probe('present', quiz.code, '24')
	probe('countdown', quiz.code, String(quizFirst.id), '9')
	await shot(s, {
		name: 'screen-edge-countdown-9', url: `/apps/pulse/screen/${quiz.code}`, size: DESKTOP,
		waitSel: '.scr-stage', note: 'Beamer: Countdown bei 9 s (dringlich)',
		settle: 600,
	})

	for (const [label, room] of Object.entries(probeData.finals || {})) {
		probe('state', room.code, String(room.polls[0].id), 'open')
		probe('seed', room.code, String(room.players))
		probe('end', room.code)
		await shot(s, {
			name: `screen-edge-${label}`, url: `/apps/pulse/screen/${room.code}`, size: DESKTOP,
			waitSel: '.scr-final', note: `Beamer Endstand mit ${room.players} Teilnehmenden`,
			settle: 5000,
		})
	}
}

/*
 * A word of 40 characters: the most the server keeps (AnswerRules) and the
 * phone's word field takes. It has to fit across the projector's cloud and
 * break inside the phone's cards. ASCII on purpose: the phone pass types it
 * through WebDriver, and probe.php gets it passed in (fixture words-long), so
 * the typed answer and the seeded ones are one word.
 */
const LONG_WORD = 'Kraftfahrzeughaftpflichtversicherungsamt'

// Projector cloud: every visible word inside the cloud area (the kiosk clips
// silently), the long word's size and width, what is left over as "+N more".
const CLOUD_LONG = `
	const canvas = document.querySelector('.pl-cloud-canvas')
	if (!canvas) { return 'Wolke FEHLT' }
	const c = canvas.getBoundingClientRect()
	const words = Array.from(canvas.querySelectorAll('.pl-word')).filter((w) => w.style.opacity !== '0')
	const text = (w) => (w.firstChild ? w.firstChild.textContent : '')
	const cut = words.filter((w) => {
		const r = w.getBoundingClientRect()
		return r.left < c.left - 0.5 || r.right > c.right + 0.5 || r.top < c.top - 0.5 || r.bottom > c.bottom + 0.5
	})
	const long = words.find((w) => text(w).length >= 40)
	const more = document.querySelector('.pl-cloud-overflow')
	return words.length + ' Wörter gesetzt' + (more ? ', ' + more.textContent.trim() : '')
		+ ', langes Wort ' + (long ? Math.round(parseFloat(long.style.fontSize)) + ' px, ' + Math.round(long.getBoundingClientRect().width) + ' von ' + Math.round(c.width) + ' px breit' : 'FEHLT')
		+ (cut.length ? ', ' + cut.length + ' ABGESCHNITTEN (' + cut.map(text).join(', ') + ')' : ', alle ganz im Bild')
`

// Phone word field: how many characters it holds after typing 41, and the counter.
const WORD_FIELD = `
	const input = document.querySelector('.word-field input')
	const count = document.querySelector('.word-count')
	if (!input) { return 'Wortfeld FEHLT' }
	const n = input.value.length
	return 'Feld hält ' + n + ' Zeichen' + (n === 40 ? '' : ' — NICHT 40') + ' (maxlength ' + input.getAttribute('maxlength')
		+ '), Zähler „' + (count ? count.textContent.trim() : '—') + '"'
`

// Phone reveal: the own answer and the compact cloud break the long word inside
// their box; nothing scrolls sideways. Lines are counted from the text's boxes.
const PHONE_LONG = `
	const lines = (el) => {
		const r = document.createRange()
		r.selectNodeContents(el)
		return new Set(Array.from(r.getClientRects()).map((q) => Math.round(q.top))).size
	}
	const mine = document.querySelector('.mine-v')
	const cloud = document.querySelector('.cloud')
	const words = Array.from(document.querySelectorAll('.cloud-word'))
	const long = words.find((w) => w.textContent.trim().length >= 40)
	const cr = cloud ? cloud.getBoundingClientRect() : null
	const out = cr ? words.filter((w) => { const r = w.getBoundingClientRect(); return r.left < cr.left - 0.5 || r.right > cr.right + 0.5 }).length : 0
	const sc = document.querySelector('.h-scroll')
	const de = document.documentElement
	const pageX = de.scrollWidth - de.clientWidth, scrollX = sc ? sc.scrollWidth - sc.clientWidth : 0
	return 'eigene Antwort ' + (mine ? (mine.scrollWidth > mine.clientWidth + 1 ? 'LÄUFT ÜBER' : 'im Kasten, ' + lines(mine) + ' Zeilen') : 'FEHLT')
		+ ', langes Wort in der Wolke ' + (long ? Math.round(parseFloat(getComputedStyle(long).fontSize)) + ' px, ' + lines(long) + ' Zeilen' : 'FEHLT')
		+ (out ? ', LÄUFT ÜBER: ' + out + ' Wörter ragen aus der Wolke' : ', alle Wörter in der Wolke')
		+ ', waagerecht Seite ' + pageX + ' px / Antwortbereich ' + scrollX + ' px' + (pageX > 0 || scrollX > 0 ? ' WAAGERECHT' : '')
`

// Marks of the long-word measurements that count as an anomaly (last index line).
const LONG_WORD_MARKS = ['FEHLT', 'ABGESCHNITTEN', 'NICHT 40', 'LÄUFT ÜBER', 'WAAGERECHT']
function longWordMarks() {
	const line = index[index.length - 1]
	for (const mark of LONG_WORD_MARKS) {
		if (line.includes(mark)) { overflow.push(line.split(' | ')[0].slice(2) + ': langes Wort ' + mark) }
	}
}

/*
 * Compass corner labels of 40 characters (probe.php add-compass), the most
 * DeckService::buildCorners keeps. Each has to stay in its quadrant: not
 * across the vertical axis, not on another label or an axis pole, not out of
 * the field. Shortened ("…") is allowed and counted, not a finding.
 */
const CORNERS_STAGE = `
	const plot = document.querySelector('.splot')
	if (!plot) { return 'Kompass FEHLT' }
	const p = plot.getBoundingClientRect()
	const mid = p.left + p.width / 2
	const corners = Array.from(plot.querySelectorAll('.splot-corner'))
	if (!corners.length) { return 'Eckbeschriftungen FEHLT' }
	const box = (el) => el.getBoundingClientRect()
	const hit = (a, b) => Math.min(a.right, b.right) - Math.max(a.left, b.left) > 0.5 && Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 0.5
	const name = (el) => el.className.replace(/.*splot-corner--/, '')
	// Visible lines only: the clamped ones are still laid out below the box.
	const lines = (el) => {
		const r = document.createRange()
		r.selectNodeContents(el)
		const b = box(el)
		return new Set(Array.from(r.getClientRects()).filter((q) => q.top < b.bottom - 1).map((q) => Math.round(q.top))).size
	}
	const others = Array.from(plot.querySelectorAll('.splot-pole, .splot-centre'))
	const hits = []
	corners.forEach((a, i) => {
		corners.slice(i + 1).forEach((b) => { if (hit(box(a), box(b))) { hits.push(name(a) + '/' + name(b)) } })
		others.forEach((o) => { if (hit(box(a), box(o))) { hits.push(name(a) + '/' + o.textContent.trim()) } })
	})
	const across = corners.filter((el) => { const r = box(el); return /l$/.test(name(el)) ? r.right > mid + 0.5 : r.left < mid - 0.5 })
	const out = corners.filter((el) => { const r = box(el); return r.left < p.left - 0.5 || r.right > p.right + 0.5 || r.top < p.top - 0.5 || r.bottom > p.bottom + 0.5 })
	const cut = corners.filter((el) => el.scrollHeight > el.clientHeight + 1 || el.scrollWidth > el.clientWidth + 1)
	return corners.length + ' Ecken, ' + corners.map((el) => name(el) + ' ' + Math.round(box(el).width) + ' px/' + lines(el) + ' Z.').join(', ')
		+ ' (halbe Fläche ' + Math.round(p.width / 2) + ' px), ' + (cut.length ? cut.length + ' gekürzt' : 'nichts gekürzt')
		+ (hits.length ? ', ÜBERLAPPT: ' + hits.join(', ') : ', keine Überlappung')
		+ (across.length ? ', ÜBER DIE MITTE: ' + across.map(name).join(', ') : '')
		+ (out.length ? ', RAGT HERAUS: ' + out.map(name).join(', ') : ', alle in der Fläche')
`

// The same on the phone: SVG text, one <text> per line (ResultsView).
const CORNERS_PHONE = `
	const svg = document.querySelector('.compass-wrap svg')
	if (!svg) { return 'Kompass FEHLT' }
	const frame = svg.querySelector('rect[rx]')
	if (!frame) { return 'Rahmen FEHLT' }
	const f = frame.getBoundingClientRect()
	const mid = f.left + f.width / 2
	// Corner lines are anchored at the frame's sides (start/end); the centre of
	// gravity shares the class but sits centred on its point.
	const lines = Array.from(svg.querySelectorAll('text.fcorner:not([text-anchor="middle"])'))
	if (!lines.length) { return 'Eckbeschriftungen FEHLT' }
	const box = (el) => el.getBoundingClientRect()
	const hit = (a, b) => Math.min(a.right, b.right) - Math.max(a.left, b.left) > 0.5 && Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 0.5
	// One corner = its anchor plus the half it sits in; its own lines may touch.
	const corner = (el) => (box(el).top + box(el).height / 2 < f.top + f.height / 2 ? 't' : 'b') + (el.getAttribute('text-anchor') === 'start' ? 'l' : 'r')
	const groups = {}
	for (const el of lines) { (groups[corner(el)] = groups[corner(el)] || []).push(el) }
	const names = Object.keys(groups).sort()
	const poles = Array.from(svg.querySelectorAll('text.flabel')).filter((o) => o.textContent.trim())
	const hits = []
	names.forEach((a, i) => {
		names.slice(i + 1).forEach((b) => { if (groups[a].some((x) => groups[b].some((y) => hit(box(x), box(y))))) { hits.push(a + '/' + b) } })
		poles.forEach((o) => { if (groups[a].some((x) => hit(box(x), box(o)))) { hits.push(a + '/' + o.textContent.trim()) } })
	})
	const across = names.filter((n) => groups[n].some((el) => (/l$/.test(n) ? box(el).right > mid + 0.5 : box(el).left < mid - 0.5)))
	const out = names.filter((n) => groups[n].some((el) => { const r = box(el); return r.left < f.left - 0.5 || r.right > f.right + 0.5 || r.top < f.top - 0.5 || r.bottom > f.bottom + 0.5 }))
	const cut = names.filter((n) => groups[n].some((el) => el.textContent.trim().endsWith('…')))
	const tip = names.filter((n) => groups[n].some((el) => el.querySelector('title')))
	const sc = document.querySelector('.h-scroll')
	const de = document.documentElement
	const pageX = de.scrollWidth - de.clientWidth, scrollX = sc ? sc.scrollWidth - sc.clientWidth : 0
	return names.length + ' Ecken, ' + names.map((n) => n + ' ' + groups[n].length + ' Z.').join(', ')
		+ ', ' + (cut.length ? cut.length + ' gekürzt (' + tip.length + ' mit Tooltip)' : 'nichts gekürzt')
		+ (hits.length ? ', ÜBERLAPPT: ' + hits.join(', ') : ', keine Überlappung')
		+ (across.length ? ', ÜBER DIE MITTE: ' + across.join(', ') : '')
		+ (out.length ? ', RAGT HERAUS: ' + out.join(', ') : ', alle im Rahmen')
		+ ', waagerecht Seite ' + pageX + ' px / Antwortbereich ' + scrollX + ' px' + (pageX > 0 || scrollX > 0 ? ' WAAGERECHT' : '')
`

// Marks of the corner-label measurements that count as an anomaly (last index line).
const CORNER_MARKS = ['FEHLT', 'ÜBERLAPPT', 'ÜBER DIE MITTE', 'RAGT HERAUS', 'WAAGERECHT']
function cornerMarks() {
	const line = index[index.length - 1]
	for (const mark of CORNER_MARKS) {
		if (line.includes(mark)) { overflow.push(line.split(' | ')[0].slice(2) + ': Eckbeschriftung ' + mark) }
	}
}

/*
 * Question types revealed (§7.10). The constellations here decide the
 * acceptance of stage 3 and cannot be produced with random votes:
 * Ø ≈ median, all votes on one value, the heatmap threshold at 44 versus
 * 46 answers, a polarised item in a ranking, a portrait and a
 * panorama image.
 */
async function typePass(s) {
	const types = probeData.types
	if (!types) { return }
	const byLabel = Object.fromEntries(types.polls.map((p) => [p.label, p]))
	const stage = async (label, note, opts = {}) => {
		await shot(s, {
			name: `screen-type-${label}`, url: `/apps/pulse/screen/${types.code}`, size: DESKTOP,
			waitSel: '.scr-stage', note, ...opts,
		})
	}

	for (const [label, kind, note] of [
		['scale-same', 'scale-same', 'Beamer Skala: Ø ≈ Median (getrennte Bänder)'],
		['scale-one', 'scale-one', 'Beamer Skala: alle Stimmen auf einem Wert (Nullstummel)'],
		['rank-polar', 'rank-polar', 'Beamer Reihenfolge: gestapelte Platzverteilung, polarisiert'],
	]) {
		const p = byLabel[label]
		probe('state', types.code, String(p.id), 'open')
		probe('fixture', types.code, String(p.id), kind)
		probe('state', types.code, String(p.id), 'locked')
		await stage(label, note)
	}

	for (const label of ['spectrum-3', 'spectrum-8']) {
		const p = byLabel[label]
		probe('state', types.code, String(p.id), 'open')
		probe('seed', types.code, '24')
		probe('state', types.code, String(p.id), 'locked')
		await stage(label, `Beamer Spektrum mit ${label === 'spectrum-3' ? '3 Aspekten (kleinster Radar)' : '8 Aspekten (zweizeilige Speichennamen)'}`)
	}

	// Heatmap threshold: first 44 answers (dots), then two more (heatmap).
	const compass = byLabel['compass-threshold']
	probe('state', types.code, String(compass.id), 'open')
	probe('fixture', types.code, String(compass.id), 'compass', '44')
	probe('state', types.code, String(compass.id), 'locked')
	await stage('compass-44', 'Beamer Kompass mit 44 Antworten (Einzelpunkte)')
	probe('fixture', types.code, String(compass.id), 'compass', '2')
	await stage('compass-46', 'Beamer Kompass mit 46 Antworten (Heatmap)', { url: null })

	// Image question: open (image + narrow intake) and revealed, portrait and panorama.
	for (const label of ['image-portrait', 'image-panorama']) {
		const p = byLabel[label]
		probe('state', types.code, String(p.id), 'open')
		probe('present', types.code, '24')
		probe('seed', types.code, '24')
		await stage(`${label}-open`, `Beamer Bildfrage ${label}, offen`)
		probe('state', types.code, String(p.id), 'locked')
		await stage(`${label}-locked`, `Beamer Bildfrage ${label}, aufgelöst`)
	}

	// Revealed, but nobody answered (§7.9).
	const empty = byLabel['no-votes']
	probe('state', types.code, String(empty.id), 'open')
	probe('state', types.code, String(empty.id), 'locked')
	await stage('no-votes', 'Beamer aufgelöst ohne eine einzige Stimme')

	// A word of 40 characters: alone it has to fit across the stage, in a full
	// cloud it must not push other words off the edge, and as the most frequent
	// word it is the largest. Questions of their own, added only now: every
	// capture above keeps its data, and these come last.
	const alone = JSON.parse(probe('add-words', types.code, 'words-long-alone', '1'))
	probe('state', types.code, String(alone.id), 'open')
	probe('fixture', types.code, String(alone.id), 'words-long', '1', LONG_WORD)
	probe('state', types.code, String(alone.id), 'locked')
	await stage('words-long-alone', 'Beamer Wortwolke: ein Wort mit 40 Zeichen, allein', { settle: 2500, measure: CLOUD_LONG })
	longWordMarks()
	const full = JSON.parse(probe('add-words', types.code, 'words-long-full', '3'))
	probe('state', types.code, String(full.id), 'open')
	probe('fixture', types.code, String(full.id), 'words')
	probe('fixture', types.code, String(full.id), 'words-long', '1', LONG_WORD)
	probe('state', types.code, String(full.id), 'locked')
	await stage('words-long-in-cloud', 'Beamer Wortwolke: 16 Wörter plus eines mit 40 Zeichen (1 Nennung)', { settle: 2500, measure: CLOUD_LONG })
	longWordMarks()
	probe('fixture', types.code, String(full.id), 'words-long', '7', LONG_WORD)
	await stage('words-long-top', 'Beamer Wortwolke: das Wort mit 40 Zeichen ist das häufigste (8 Nennungen)', { settle: 2500, measure: CLOUD_LONG })
	longWordMarks()

	// Four compass corner labels of 40 characters: each stays in its quadrant
	// (two lines, then "…") instead of running across the axis into the
	// opposite one. A question of its own, added only now like the long words;
	// at full HD and at 1280 × 720, where the field is narrowest.
	const corners = JSON.parse(probe('add-compass', types.code, 'compass-corners-long'))
	probe('state', types.code, String(corners.id), 'open')
	probe('fixture', types.code, String(corners.id), 'compass', '44')
	probe('state', types.code, String(corners.id), 'locked')
	await stage('compass-corners-long', 'Beamer Kompass: vier Eckbeschriftungen mit 40 Zeichen', { settle: 2500, measure: CORNERS_STAGE })
	cornerMarks()
	await stage('compass-corners-long-1280', 'Beamer Kompass: vier Eckbeschriftungen mit 40 Zeichen bei 1280 × 720', { size: [1280, 720], measure: CORNERS_STAGE })
	cornerMarks()
}

/*
 * Phone (§8.10). The public pass photographs every question once; here are
 * the states that only exist after an action (sent, an open and an
 * expired correction window) and the device edge cases from §8.9.
 */
async function phonePass(s) {
	const edge = probeData.edge
	const NARROW = [320, 640]
	const LANDSCAPE = [844, 390]

	// Poll: select (two-step), submit, the "Answer sent" state (§8.5).
	const first = poll.polls[0]
	probe('state', poll.code, String(first.id), 'open')
	probe('seed', poll.code, '17')
	probe('present', poll.code, '24')
	await shot(s, {
		name: 'phone-poll-choice-picked', url: `/apps/pulse/s/${poll.code}`, size: PHONE,
		waitSel: '.choice', actions: [{ click: '.choice' }, { sleep: 400 }],
		note: 'Handy Umfrage: ausgewählt, noch nicht gesendet',
	})
	await shot(s, {
		name: 'phone-poll-choice-sent', size: PHONE,
		actions: [{ click: '.h-submit .submit-btn' }, { sleep: 1600 }],
		note: 'Handy Umfrage: gesendet (eigene Antwort bleibt, Eingangsstand)',
	})
	probe('state', poll.code, String(first.id), 'locked')
	await shot(s, {
		name: 'phone-poll-choice-revealed', url: `/apps/pulse/s/${poll.code}`, size: PHONE,
		waitSel: '.mine-box', note: 'Handy Umfrage aufgelöst: eigene Antwort über der Verteilung',
	})

	// Device edge cases (§8.9): on a question this device has not answered
	// yet; otherwise it would show the "Answer sent" state.
	if (edge) {
		const two = edge.polls[0]
		probe('state', edge.code, String(two.id), 'open')
		await shot(s, {
			name: 'phone-narrow-320', url: `/apps/pulse/s/${edge.code}`, size: NARROW,
			waitSel: '.choice', note: 'Handy 320 px breit: Karten ≥ 64 px, Schrift ≥ 17 px',
		})
		await shot(s, {
			name: 'phone-landscape', url: `/apps/pulse/s/${edge.code}`, size: LANDSCAPE,
			waitSel: '.choice', note: 'Handy quer 844 × 390: zwei Spalten, Absende-Leiste unten',
		})
	}

	// Eight options with images: the answer area scrolls, the bar never does (§8.9).
	if (edge) {
		const many = edge.polls.find((p) => p.label === 'opts-8')
		probe('state', edge.code, String(many.id), 'open')
		await shot(s, {
			name: 'phone-edge-opts-8', url: `/apps/pulse/s/${edge.code}`, size: PHONE,
			waitSel: '.choice', note: 'Handy: 8 Optionen mit Bild, Antwortbereich scrollt',
		})
		const long = edge.polls.find((p) => p.label === 'long-label')
		probe('state', edge.code, String(long.id), 'open')
		await shot(s, {
			name: 'phone-edge-long-label', url: `/apps/pulse/s/${edge.code}`, size: PHONE,
			waitSel: '.choice', note: 'Handy: langes Label, Karten gleich hoch, Unterlängen ganz',
		})
	}

	// Quiz: a tap sends immediately, "Change" stays for three seconds (§8.0).
	const quizFirst = quiz.polls[0]
	probe('state', quiz.code, String(quizFirst.id), 'open')
	await shot(s, {
		name: 'phone-quiz-tap', url: `/apps/pulse/s/${quiz.code}`, size: PHONE,
		waitSel: '.nick-input',
		actions: [{ type: ['.nick-input', 'Alex'] }, { click: '.nick .submit' }, { sleep: 1800 }],
		note: 'Handy Quiz: Karten, Hinweis „Tippen sendet sofort"',
	})
	await shot(s, {
		name: 'phone-quiz-fix-open', size: PHONE,
		actions: [{ click: '.choices .choice:nth-child(2)' }, { sleep: 800 }],
		note: 'Handy Quiz: Korrekturfenster offen („Ändern" plus Haarlinie)',
	})
	await shot(s, {
		name: 'phone-quiz-fix-gone', size: PHONE, actions: [{ sleep: 3200 }],
		note: 'Handy Quiz: Fenster abgelaufen — Knopf weg, Bestätigung bleibt',
	})
	probe('state', quiz.code, String(quizFirst.id), 'locked')
	await shot(s, {
		name: 'phone-quiz-revealed', url: `/apps/pulse/s/${quiz.code}`, size: PHONE,
		waitSel: '.verdict, .band', note: 'Handy Quiz aufgelöst: eigenes Ergebnis über der Verteilung',
	})
	probe('end', quiz.code)
	await shot(s, {
		name: 'phone-quiz-ended-place', url: `/apps/pulse/s/${quiz.code}`, size: PHONE,
		waitSel: '.big-place', settle: 2000, note: 'Handy Quiz-Ende: eigener Platz oben',
	})

	// A word of 40 characters: the field keeps all of them and no 41st, and
	// after the reveal the own answer and the compact cloud, where it is the
	// largest word, break it inside the card. A question of its own in the types
	// room, added only now, so the rest of the run is unchanged.
	const types = probeData.types
	if (types) {
		const long = JSON.parse(probe('add-words', types.code, 'words-long-phone', '3'))
		probe('state', types.code, String(long.id), 'open')
		await shot(s, {
			name: 'phone-words-long-typed', url: `/apps/pulse/s/${types.code}`, size: PHONE,
			waitSel: '.word-field input', actions: [{ type: ['.word-field input', LONG_WORD + 'X'] }, { sleep: 400 }],
			note: 'Handy Wortwolke: 41 Zeichen getippt, das Feld nimmt 40', measure: WORD_FIELD,
		})
		longWordMarks()
		await shot(s, {
			name: 'phone-words-long-sent', size: PHONE,
			actions: [{ click: '.h-submit .submit-btn' }, { sleep: 1600 }],
			note: 'Handy Wortwolke: das lange Wort gesendet',
		})
		probe('fixture', types.code, String(long.id), 'words')
		probe('fixture', types.code, String(long.id), 'words-long', '7', LONG_WORD)
		probe('state', types.code, String(long.id), 'locked')
		await shot(s, {
			name: 'phone-words-long-revealed', url: `/apps/pulse/s/${types.code}`, size: PHONE,
			waitSel: '.mine-box', measure: PHONE_LONG,
			note: 'Handy Wortwolke aufgelöst: eigenes Wort mit 40 Zeichen, in der Wolke das größte (8 Nennungen)',
		})
		longWordMarks()
	}

	// Ordering by keyboard: the first item goes down three times to the bottom
	// and up once. After every step the focus has to be on that item's arrow —
	// the keyed move of its <li> used to drop it to <body> on "↓" — and at the
	// bottom, where "↓" is disabled, on its "↑". focus() plus click() is what
	// Enter on a focused button does.
	const rank = poll.polls.find((p) => p.label === 'rank')
	if (rank) {
		probe('state', poll.code, String(rank.id), 'open')
		const step = { js: `
			const a = document.activeElement
			const li = a && a.closest ? a.closest('.rank-item') : null
			const mine = li && li.dataset.rankId === window.__rankFocus.id
			window.__rankFocus.log.push(mine ? (Array.from(li.querySelectorAll('.rank-arrow')).indexOf(a) === 0 ? '↑' : '↓') : 'VERLOREN')
			if (mine && window.__rankFocus.log.length < 4) { a.click() }
		` }
		await shot(s, {
			name: 'phone-rank-keyboard', url: `/apps/pulse/s/${poll.code}`, size: PHONE,
			waitSel: '.rank-item',
			actions: [
				{ js: `
					const li = document.querySelector('.rank-item')
					window.__rankFocus = { id: li.dataset.rankId, log: [] }
					const down = li.querySelectorAll('.rank-arrow')[1]
					down.focus()
					down.click()
				` },
				{ sleep: 400 }, step, { sleep: 400 }, step, { sleep: 400 }, step, { sleep: 400 }, step,
			],
			note: 'Handy Reihenfolge per Tastatur: erster Eintrag 3× ↓, dann ↑',
			measure: `return 'Fokus nach jedem Schritt: ' + window.__rankFocus.log.join(' → ') + ' (erwartet ↓ → ↓ → ↑ → ↑)'`,
		})
		if (index[index.length - 1].includes('VERLOREN')) { overflow.push(index[index.length - 1].split(' | ')[0].slice(2) + ': Fokus nach Verschieben VERLOREN') }
		// Back to the state the pass had before, so a full run hands the next
		// passes (dark photographs the poll room first) the same room as ever.
		probe('state', poll.code, String(first.id), 'locked')
	}

	// The four corner labels of 40 characters in the phone's compass field:
	// SVG text does not wrap by itself, so ResultsView breaks them into two
	// lines each, then "…". A question of its own in the types room, added
	// only now; this phone has not answered it, so the field shows without
	// "You".
	if (types) {
		const corners = JSON.parse(probe('add-compass', types.code, 'compass-corners-phone'))
		probe('state', types.code, String(corners.id), 'open')
		probe('fixture', types.code, String(corners.id), 'compass', '44')
		probe('state', types.code, String(corners.id), 'locked')
		await shot(s, {
			name: 'phone-compass-corners-long', url: `/apps/pulse/s/${types.code}`, size: PHONE,
			waitSel: '.compass-wrap svg', measure: CORNERS_PHONE,
			note: 'Handy Kompass aufgelöst: vier Eckbeschriftungen mit 40 Zeichen',
		})
		cornerMarks()
	}
}

/*
 * Moderator and embedding (§9.9). New compared with the existing captures are
 * the four flow states in a row (the main action has to move along), the
 * fullscreen mode (the private panel must not be in the document there), the
 * empty deck and the room nobody is connected to.
 */
async function login(s) {
	const pass = readFileSync(join(HERE, '.shots-pass'), 'utf8').trim()
	// Nextcloud assembles the login form in the browser; the IDs are not in
	// the delivered HTML. Going by the name attributes is stable.
	const userSel = 'input[name="user"], #user'
	const passSel = 'input[name="password"], #password'
	await s.size(LAPTOP)
	// Nextcloud 34 requires Firefox >= 145 and sends older browsers to
	// /unsupported; the server here has 128 ESR. The flag that the button
	// "Continue with this unsupported browser" sets is set right away as well;
	// otherwise the login ends up in a loop. This only affects this test rig,
	// not the app.
	await s.go(`${HOST}/login`)
	await s.script("window.localStorage.setItem('nextcloud_vol_Y29yZQ==_unsupported-browser-ignore', 'true')")
	await s.go(`${HOST}/login`)
	await waitFor(s, userSel)
	await s.type(userSel, UID)
	await s.type(passSel, pass)
	await s.click('button[type="submit"]')
	await waitFor(s, 'body#body-user, #app-navigation, .app-menu', 20000)
	await sleep(2000)
}

async function moderatorPass(s) {
	await login(s)

	// Start screen: two columns (laptop) and one column (§9.5). Both times
	// the bottom edge of the first room card is measured.
	await shot(s, {
		name: 'mod-start', url: '/apps/pulse/', size: LAPTOP,
		waitSel: '.pulse-mod', note: 'Moderator: Startbildschirm (zweispaltig ab 1200 px)',
	})
	await shot(s, {
		name: 'mod-start-narrow', url: '/apps/pulse/', size: [1024, 800],
		waitSel: '.pulse-mod', note: 'Moderator: Startbildschirm einspaltig (1024 px)',
	})

	// The four flow states (§9.1) one after another on the same quiz.
	const first = quiz.polls[0]
	const last = quiz.polls[quiz.polls.length - 1]
	probe('present', quiz.code, '24')
	probe('state', quiz.code, String(first.id), 'open')
	probe('seed', quiz.code, '17')
	probe('present', quiz.code, '24')
	await shot(s, {
		name: 'mod-flow-1-open', url: `/apps/pulse/room/${quiz.code}`, size: LAPTOP,
		waitSel: '.mod-bar', settle: 2500, note: 'Moderator 1/4: Frage offen -> „Auflösen"',
	})
	probe('state', quiz.code, String(first.id), 'locked')
	probe('present', quiz.code, '24')
	await shot(s, {
		name: 'mod-flow-2-revealed', url: `/apps/pulse/room/${quiz.code}`, size: LAPTOP,
		waitSel: '.mod-bar', settle: 2500, note: 'Moderator 2/4: aufgelöst, weitere folgen -> „Nächste Frage"',
	})
	probe('state', quiz.code, String(last.id), 'open')
	probe('seed', quiz.code, '12')
	probe('state', quiz.code, String(last.id), 'locked')
	probe('present', quiz.code, '24')
	await shot(s, {
		name: 'mod-flow-3-last', url: `/apps/pulse/room/${quiz.code}`, size: LAPTOP,
		waitSel: '.mod-bar', settle: 2500, note: 'Moderator 3/4: letzte Frage aufgelöst -> „Endstand zeigen"',
	})
	await shot(s, {
		name: 'mod-flow-4-standings', size: LAPTOP,
		// The podium comes in staggered (winner at 4.3 s); before that the area
		// is empty, as in the final standings images on the projector.
		actions: [{ click: '.mod-primary' }, { wait: '.mod-standings' }, { sleep: 5500 }],
		note: 'Moderator 4/4: Endstand sichtbar -> „Quiz beenden" (erst hier rot)',
	})

	// Fullscreen: the projected mode. The private panel must not be in the
	// document there; shot() measures that as well (`kein privates Panel`).
	probe('state', quiz.code, String(first.id), 'open')
	probe('present', quiz.code, '24')
	await shot(s, {
		name: 'mod-fullscreen', url: `/apps/pulse/room/${quiz.code}`, size: LAPTOP,
		waitSel: '.mod-bar', settle: 2000,
		actions: [{ click: '.pulse-menu > .pulse-btn' }, { sleep: 300 }, { js: 'document.querySelector(\'.pulse-menu-item[data-key="fs"]\').click()' }, { sleep: 1500 }],
		note: 'Moderator: Vollbild — nur die Leinwand, kein privates Panel',
	})
	// Leave fullscreen again, otherwise the session stays stuck in it.
	await s.script('if (document.fullscreenElement) { document.exitFullscreen() }')
	await sleep(800)

	// Joining is open because nobody is connected (§9.4). No 'present' call:
	// that is exactly the state.
	const pollFirst = poll.polls[0]
	probe('state', poll.code, String(pollFirst.id), 'open')
	await shot(s, {
		name: 'mod-join-open', url: `/apps/pulse/room/${poll.code}`, size: LAPTOP,
		waitSel: '.mod-bar', settle: 3000, note: 'Moderator: niemand verbunden -> Beitritts-Panel offen',
	})

	// Deck without questions (§9.8).
	const empty = probeData.empty
	if (empty) {
		await shot(s, {
			name: 'mod-empty-deck', url: `/apps/pulse/room/${empty.code}`, size: LAPTOP,
			waitSel: '.mod-bar', settle: 1500, note: 'Moderator: Deck ohne Fragen -> „Frage anlegen"',
		})
	}

	// Deck editor (stays unchanged, §9.6), as proof that it still works.
	await shot(s, {
		name: 'mod-deck', url: `/apps/pulse/room/${poll.code}`, size: LAPTOP,
		waitSel: '.pulse-mod', settle: 1500,
		actions: [{ click: '.pulse-menu > .pulse-btn' }, { sleep: 300 }, { js: 'document.querySelector(\'.pulse-menu-item[data-key="deck"]\').click()' }, { wait: '.deck-list' }, { sleep: 800 }],
		note: 'Moderator: Deck-Liste',
	})
}

/*
 * Embed shell (§9.7). It sits inside other people's slides: English source
 * texts via l10n, .pulse-btn instead of a custom button, the wordmark, and a
 * colour scheme that follows the embedding, not the server. The error case
 * belongs at the field.
 */
async function embedPass(s, dark) {
	const tag = dark ? 'dark-' : ''
	await shot(s, {
		name: `${tag}embed-form`, url: '/apps/pulse/embed', size: [1280, 720],
		waitSel: '.pulse-embed-cell', note: `Einbett-Shell: Code-Eingabe (${dark ? 'dunkel' : 'hell'})`,
	})
	if (dark) { return }
	// Unknown code: message at the field, no dialog. ZZZZZZ is formally valid.
	const cells = Array.from({ length: 6 }, (_, i) => ({ type: [`.pulse-embed-cell:nth-child(${i + 1})`, 'Z'] }))
	await shot(s, {
		name: 'embed-unknown-code', size: [1280, 720],
		actions: [...cells, { click: '.pulse-embed-go' }, { sleep: 1500 }],
		note: 'Einbett-Shell: unbekannter Code -> Meldung am Feld',
	})
	// Valid code: the canvas fills the frame.
	await shot(s, {
		name: 'embed-live', url: `/apps/pulse/embed?code=${poll.code}`, size: [1280, 720],
		waitSel: '.pulse-embed-frame', settle: 4000, note: 'Einbett-Shell: Leinwand im Rahmen',
	})
	// Self-paced (§5.2 step 4.5): the same shell with a running
	// race; the canvas in the frame shows the bars, not the lobby.
	if (paceData) {
		await shot(s, {
			name: 'embed-pace', url: `/apps/pulse/embed?code=${paceData.race.code}`, size: [1280, 720],
			waitSel: '.pulse-embed-frame', settle: 2500, measure: EMBED_PACE,
			actions: [{ until: '(() => { const f = document.querySelector(".pulse-embed-frame"); const d = f && f.contentDocument; return d && d.querySelector(".scr.is-race .pace-race") })()', timeout: 12000 }],
			note: 'Einbett-Shell: Rennen im eigenen Tempo im Rahmen',
		})
		const line = index[index.length - 1]
		if (/KEIN RENNEN|LÄUFT ÜBER/.test(line)) { overflow.push('embed-pace: ' + line.split(' | ').slice(-1)[0]) }
	}
}

// Embed shell with a race: is it in the frame, and does the stage fit?
const EMBED_PACE = `
	const f = document.querySelector('.pulse-embed-frame')
	const d = f && f.contentDocument
	if (!d) { return 'Rahmen nicht lesbar' }
	const st = d.querySelector('.scr-stage')
	const over = st ? st.scrollHeight - st.clientHeight : 0
	return (d.querySelector('.scr.is-race .pace-race') ? 'Rennen im Rahmen' : 'KEIN RENNEN IM RAHMEN')
		+ (d.querySelector('.pace-race.is-dense') ? ', enge Zeilen' : '')
		+ ', Rahmen ' + f.clientWidth + '×' + f.clientHeight
		+ (st ? ', Bühne ' + (over > 1 ? 'LÄUFT ÜBER ' + over + ' px' : 'passt') + ' (' + getComputedStyle(st).fontSize + ')' : '')
`

/* ----------------------------------------------------------------------- Run */

const driver = spawn(join(HERE, '..', '..', 'tools', 'geckodriver'), ['--port', String(PORT), '--log', 'error'], {
	stdio: ['ignore', 'ignore', 'inherit'],
})
process.on('exit', () => driver.kill())

/*
 * Images for the store page (docs/APPSTORE.md §3). A different job than the
 * rest of this test rig: not documenting edge cases, but showing in six
 * images what the app does. Hence its own rooms (probe.php store), the English
 * interface and the public hostname: it appears in the join link and in the
 * QR code, and the internal one does not belong on a store page.
 */
async function storePass(s) {
	const p = probeData.poll
	const q = probeData.quiz
	const byLabel = (room, label) => room.polls.find((x) => x.label === label)

	// The window is taller on the outside than its content; by how much depends on the browser.
	// Measure once and add it on; otherwise 1200×675 yields an image with a
	// 2:1 ratio instead of 16:9, and the projector looks flatter than it is.
	await s.size(STORE)
	const chrome = STORE[1] - Number(await s.script('return window.innerHeight'))
	const box = ([w, h]) => [w, h + chrome]

	// 1. What the audience sees before it starts: code and QR, large on the wall.
	probe('state', p.code, '0', 'open')
	await shot(s, {
		name: 'lobby', url: `/apps/pulse/screen/${p.code}`, size: box(STORE),
		waitSel: '.scr-lobby', note: 'Store: Lobby — Beitritt per Code und QR',
	})

	// 2. A running question. The word cloud says without words what is happening here.
	const words = byLabel(p, 'words')
	probe('state', p.code, String(words.id), 'open')
	// A fixed word distribution instead of a random one; otherwise the image looks
	// different on every run and the size grading is a matter of luck.
	probe('fixture', p.code, String(words.id), 'words')
	// More people present than answers: the intake display then shows "24 of
	// 30" instead of "complete"; that is what a running question looks like.
	probe('present', p.code, '50')
	await shot(s, {
		name: 'live-words', url: `/apps/pulse/screen/${p.code}`, size: box(STORE),
		waitSel: '.scr-stage', note: 'Store: Wortwolke, Antworten laufen ein',
	})

	// 3./4. The same question from both sides: the phone while voting, the wall
	// when revealing. The phone first; after that the question is closed.
	const choice = byLabel(p, 'choice')
	probe('state', p.code, String(choice.id), 'open')
	await shot(s, {
		name: 'phone-vote', url: `/apps/pulse/s/${p.code}`, size: box(PHONE),
		waitSel: '.choices .choice',
		// One option is tapped: only then is the submit button active. Without a
		// selection the image shows a greyed-out button and looks broken.
		actions: [{ click: '.choices .choice:nth-of-type(3)' }, { sleep: 600 }],
		note: 'Store: Abstimmen auf dem eigenen Handy',
	})
	probe('seed', p.code, '24')
	probe('present', p.code, '30')
	probe('state', p.code, String(choice.id), 'locked')
	await shot(s, {
		name: 'results', url: `/apps/pulse/screen/${p.code}`, size: box(STORE),
		waitSel: '.scr-stage', note: 'Store: aufgelöstes Ergebnis auf der Wand',
	})

	// 5. The presenter's view: canvas preview on the left, the distribution
	// only for them on the right, one main action at the bottom.
	await login(s)
	const first = q.polls[0]
	probe('state', q.code, String(first.id), 'open')
	probe('present', q.code, '15')
	await shot(s, {
		name: 'moderator', url: `/apps/pulse/room/${q.code}`, size: box(STORE_MOD),
		waitSel: '.mod-bar', settle: 2500, note: 'Store: Moderationsansicht im Quiz',
	})

	// 6. End of the quiz: podium and leaderboard.
	probe('end', q.code)
	await shot(s, {
		name: 'standings', url: `/apps/pulse/screen/${q.code}`, size: box(STORE),
		waitSel: '.scr-final', settle: 5000, note: 'Store: Endstand des Quiz',
	})
}

async function waitForDriver() {
	for (let i = 0; i < 40; i++) {
		try {
			const status = await call('GET', '/status')
			if (status.ready !== false) { return }
		} catch { /* not up yet */ }
		await sleep(250)
	}
	throw new Error('geckodriver kam nicht hoch')
}

/*
 * Overviews: start screen ("My rooms") and deck list, light and dark,
 * wide and narrow. The next design pass is about these two surfaces: the
 * button rows there have grown without ever having been put in order.
 */
async function overviewPass(s, dark) {
	const tag = dark ? '-dark' : ''
	await login(s)

	for (const [w, h] of [DESKTOP, LAPTOP, [1024, 800]]) {
		await shot(s, {
			name: `ov-start-${w}${tag}`, url: '/apps/pulse/', size: [w, h],
			waitSel: '.pulse-mod', settle: 1500,
			note: `Übersicht: Startbildschirm + Raumliste bei ${w} px${dark ? ', dunkel' : ''}`,
		})
	}

	// A room without a running question already opens in the deck (loadRoom); then
	// there is no overflow menu at all through which one could switch there.
	const openDeck = [
		{ js: 'if (!document.querySelector(".deck-list")) { document.querySelector(".pulse-menu > .pulse-btn").click() }' }, { sleep: 300 },
		{ js: 'const d = document.querySelector(\'.pulse-menu-item[data-key="deck"]\'); if (d) { d.click() }' },
		{ wait: '.deck-list' }, { sleep: 800 },
	]

	// Deck in both operating modes: the quiz has two more toggles in the menu
	// (reveal at the end, practice run).
	for (const [label, room] of [['umfrage', poll], ['quiz', quiz]]) {
		for (const [w, h] of [DESKTOP, LAPTOP, [1024, 800]]) {
			await shot(s, {
				name: `ov-deck-${label}-${w}${tag}`, url: `/apps/pulse/room/${room.code}`, size: [w, h],
				waitSel: '.pulse-mod', settle: 1500,
				actions: openDeck,
				note: `Übersicht: Deck ${label} bei ${w} px${dark ? ', dunkel' : ''}`,
			})
		}
	}

	// The open menu: fixed text plus check mark (R3), red items last (R4).
	await shot(s, {
		name: `ov-deck-menu-quiz${tag}`, url: `/apps/pulse/room/${quiz.code}`, size: LAPTOP,
		waitSel: '.pulse-mod', settle: 1200,
		actions: [...openDeck, { js: 'document.querySelector(".deck-head .pulse-menu > .pulse-btn").click()' }, { sleep: 500 }],
		note: `Übersicht: Deck-Menü offen${dark ? ', dunkel' : ''}`,
	})

	// Escape closes and returns the focus to the trigger (acceptance #10).
	await shot(s, {
		name: `ov-deck-menu-keyboard${tag}`, url: `/apps/pulse/room/${quiz.code}`, size: LAPTOP,
		waitSel: '.pulse-mod', settle: 1200,
		actions: [...openDeck,
			{ js: 'document.querySelector(".deck-head .pulse-menu > .pulse-btn").click()' }, { sleep: 400 },
			{ js: 'document.activeElement.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }))' },
			{ sleep: 400 }],
		note: `Übersicht: Menü mit Escape geschlossen, Fokus zurück am Auslöser${dark ? ', dunkel' : ''}`,
	})

	// Composer open: the header stays in place, and the second button
	// layer is added below it.
	await shot(s, {
		name: `ov-composer${tag}`, url: `/apps/pulse/room/${quiz.code}`, size: LAPTOP,
		waitSel: '.pulse-mod', settle: 1500,
		actions: [...openDeck,
			{ js: 'document.querySelector(\'.add-btn\').click()' }, { wait: '.composer' }, { sleep: 900 }],
		note: `Übersicht: Deck mit offenem Composer${dark ? ', dunkel' : ''}`,
	})
}

/*
 * Matching with eight pairs. The existing data only had four; from six rows on
 * it shows whether the projector and the moderator view can carry it. Measured
 * are the stage height (the kiosk clips silently) and the preview in the
 * moderator view.
 */
async function matchPass(s, dark) {
	const types = probeData.types
	const match = probeData.match
	const tag = dark ? 'dark-' : ''

	// Poll mode: no solution, the lead is simply the majority.
	if (types) {
		const p = types.polls.find((q) => q.label === 'match-8')
		if (p) {
			probe('state', types.code, String(p.id), 'open')
			probe('present', types.code, '24')
			await shot(s, {
				name: `${tag}screen-match8-poll-open`, url: `/apps/pulse/screen/${types.code}`, size: DESKTOP,
				waitSel: '.scr-stage', note: 'Beamer Zuordnung 8 Paare, Umfrage, offen',
			})
			probe('seed', types.code, '24')
			probe('state', types.code, String(p.id), 'locked')
			await shot(s, {
				name: `${tag}screen-match8-poll-locked`, url: `/apps/pulse/screen/${types.code}`, size: DESKTOP,
				waitSel: '.scr-stage', note: 'Beamer Zuordnung 8 Paare, Umfrage, aufgelöst',
			})
		}
	}
	if (!match) { return }

	// Quiz mode: the same eight pairs with the solution colour, plus four for comparison.
	for (const [label, note] of [['match-8', '8 Paare'], ['match-4', '4 Paare (Vergleich)']]) {
		const p = match.polls.find((q) => q.label === label)
		probe('state', match.code, String(p.id), 'open')
		probe('present', match.code, '24')
		await shot(s, {
			name: `${tag}screen-${label}-quiz-open`, url: `/apps/pulse/screen/${match.code}`, size: DESKTOP,
			waitSel: '.scr-stage', note: `Beamer Zuordnung ${note}, Quiz, offen`,
		})
		probe('seed', match.code, '24')
		probe('state', match.code, String(p.id), 'locked')
		await shot(s, {
			name: `${tag}screen-${label}-quiz-locked`, url: `/apps/pulse/screen/${match.code}`, size: DESKTOP,
			waitSel: '.scr-stage', note: `Beamer Zuordnung ${note}, Quiz, aufgelöst`,
		})
	}

	// Phone: eight rows plus eight targets at 390 px, the place where
	// matching gets tight first.
	const phone8 = match.polls.find((q) => q.label === 'match-8')
	probe('state', match.code, String(phone8.id), 'open')
	await shot(s, {
		name: `${tag}phone-match8-vote`, url: `/apps/pulse/s/${match.code}`, size: PHONE,
		waitSel: '.nick-input', settle: 1500,
		actions: [{ type: ['.nick-input', 'Alex'] }, { click: '.nick .submit' }, { sleep: 1800 }],
		note: 'Handy Zuordnung 8 Paare, Abstimm-Ansicht',
	})
	probe('seed', match.code, '24')
	probe('state', match.code, String(phone8.id), 'locked')
	await shot(s, {
		name: `${tag}phone-match8-result`, size: PHONE,
		settle: 2500, note: 'Handy Zuordnung 8 Paare, aufgelöst',
	})

	// Moderator view: canvas preview plus private panel, both with the
	// same question, at laptop and projector width.
	await login(s)
	const eight = match.polls.find((q) => q.label === 'match-8')
	for (const [size, tagSize] of [[LAPTOP, '1440'], [DESKTOP, '1920']]) {
		probe('state', match.code, String(eight.id), 'open')
		probe('present', match.code, '24')
		await shot(s, {
			name: `${tag}mod-match8-open-${tagSize}`, url: `/apps/pulse/room/${match.code}`, size,
			waitSel: '.mod-bar', settle: 3000, note: `Moderator Zuordnung 8 Paare, offen (${tagSize} px)`,
		})
		probe('state', match.code, String(eight.id), 'locked')
		probe('present', match.code, '24')
		await shot(s, {
			name: `${tag}mod-match8-locked-${tagSize}`, url: `/apps/pulse/room/${match.code}`, size,
			waitSel: '.mod-bar', settle: 3000, note: `Moderator Zuordnung 8 Paare, aufgelöst (${tagSize} px)`,
		})
	}
}

/* ---------------------------------------------------- Self-paced: moderator */

/** Wait until an element's text matches; returns the waiting time in ms. */
async function waitForText(s, sel, re, timeout = 15000) {
	const start = Date.now()
	while (Date.now() - start < timeout) {
		const t = await s.script('const el = document.querySelector(arguments[0]); return el ? el.textContent : ""', [sel])
		if (re.test(t)) { return Date.now() - start }
		await sleep(200)
	}
	throw new Error(`Text blieb aus: ${sel} ~ ${re}`)
}

// Self-paced deck: header, lock, status line, rows.
const PACE_DECK = `
	const q = (sel) => document.querySelector(sel)
	const txt = (el) => (el ? el.textContent.trim().replace(/\\s+/g, ' ') : '')
	if (!q('.deck-head')) { return null }
	const prim = Array.from(document.querySelectorAll('.deck-head > .pulse-btn:not(.deck-back)')).map(txt)
	const parts = ['Modus „' + txt(q('.deck-mode')) + '"',
		q('.deck-state') ? 'Zustand „' + txt(q('.deck-state')).replace(/until .*/, 'until …') + '"' : 'kein Zustands-Chip',
		prim.length ? 'Hauptaktion „' + prim.join('", „') + '"' : 'keine Hauptaktion']
	const note = q('.deck-lock-note')
	if (note) { parts.push('Hinweis „' + txt(note) + '"') }
	const st = Array.from(document.querySelectorAll('.pace-ds .pulse-chip')).map(txt)
	if (st.length) { parts.push('Status: ' + st.join(' · ')) }
	const rows = document.querySelectorAll('.deck-item').length
	parts.push(rows + ' Zeilen: ' + document.querySelectorAll('.deck-item .drag-handle').length + ' Griffe, '
		+ document.querySelectorAll('.deck-item .pulse-menu').length + ' Zeilenmenüs, '
		+ document.querySelectorAll('.deck-item > .pulse-btn').length + ' „Show", '
		+ document.querySelectorAll('.deck-q:disabled').length + ' gesperrte Fragen')
	parts.push(q('.add-btn') ? '„+ Add question" da' : 'kein „+ Add question"')
	parts.push('Zeile 1 „' + txt(q('.deck-item .deck-type')) + '"')
	return parts.join(', ')
`
// Open deck menu: toggles with check marks, disabled entries with a reason, red items.
const PACE_MENU = `
	const list = document.querySelector('.deck-head .pulse-menu-list')
	if (!list) { return null }
	return 'Menü: ' + Array.from(list.querySelectorAll('.pulse-menu-item')).map((b) => {
		const chk = b.getAttribute('role') === 'menuitemcheckbox' ? (b.getAttribute('aria-checked') === 'true' ? ' ✓' : ' ☐') : ''
		const hint = b.querySelector('.pulse-menu-hint')
		return b.dataset.key + chk + (b.disabled ? ' (aus' + (hint ? ': ' + hint.textContent.trim() : ' OHNE GRUND') + ')' : '')
			+ (b.classList.contains('is-danger') ? ' [rot]' : '')
	}).join(', ')
`
// Open-quiz dialog: selected segments, relative line/error, hints, focus, fit.
const PACE_DIALOG = `
	const d = document.querySelector('.pod')
	if (!d) { return null }
	const txt = (el) => (el ? el.textContent.trim().replace(/\\s+/g, ' ') : '')
	const act = Array.from(d.querySelectorAll('.pseg-item.is-active')).map(txt)
	const parts = ['Dialog „' + txt(d.querySelector('.pod-title')) + '": ' + act.join(' · ')]
	const fixed = d.querySelectorAll('.pseg.is-disabled').length
	if (fixed) { parts.push(fixed + ' Gruppe fest') }
	const rel = d.querySelector('.pod-rel')
	if (rel) { parts.push((rel.classList.contains('is-error') ? 'Fehler' : 'Zeile') + ' „' + txt(rel).replace(/— .*/, '— …') + '"') }
	if (d.querySelector('.pod-presets')) { parts.push(d.querySelectorAll('.pod-presets .pulse-btn').length + ' Vorwahl-Knöpfe') }
	if (d.querySelector('.pod-warn')) { parts.push('Warnung „each + Frist"') }
	if (d.querySelector('.pod-practice')) { parts.push('Probelauf-Hinweis mit „' + txt(d.querySelector('.pod-practice-end')) + '"') }
	if (d.querySelector('.pod-error')) { parts.push('Serverfehler „' + txt(d.querySelector('.pod-error')) + '"') }
	if (d.classList.contains('is-extend')) {
		const was = d.querySelector('.pod-was')
		parts.push('Knopf „' + txt(d.querySelector('.pod-submit')) + '"' + (was ? ', „' + txt(was).replace(/: .*/, ': …') + '"' : ', ohne „Was"'))
		const helps = Array.from(d.querySelectorAll('.pod-group > .pod-help')).map(txt)
		if (helps.length) { parts.push('Hinweise: ' + helps.map((h) => '„' + h + '"').join(' ')) }
	}
	const a = document.activeElement
	parts.push('Fokus ' + (a && d.contains(a) ? '„' + (txt(a) || a.tagName.toLowerCase()) + '"' : 'AUSSERHALB'))
	const r = d.getBoundingClientRect()
	const body = d.querySelector('.pod-body')
	if (body && body.scrollHeight > body.clientHeight + 1) { parts.push('Körper scrollt ' + (body.scrollHeight - body.clientHeight) + ' px') }
	if (r.top < 0 || r.bottom > window.innerHeight + 1) { parts.push('DIALOG RAGT ÜBER DEN RAND') }
	const input = d.querySelector('.pace-input')
	if (input) { parts.push('Feld ' + getComputedStyle(input).fontSize) }
	return parts.join(', ')
`
// Room list: self-paced state chips, counted by class and text.
const PACE_ROOMS = `
	const chips = Array.from(document.querySelectorAll('.myroom-pace'))
	if (!chips.length) { return null }
	const by = {}
	for (const c of chips) {
		const cls = (c.className.match(/is-(live|warning|neutral)/) || ['', '?'])[1]
		const k = cls + ' „' + c.textContent.trim().replace(/\\s+/g, ' ').replace(/until .*/, 'until …') + '"'
		by[k] = (by[k] || 0) + 1
	}
	return 'Raum-Chips: ' + Object.entries(by).map(([k, v]) => k + ' ×' + v).join(', ')
`

/*
 * Since step 4.3a an opened room starts in the run view; the deck is reached
 * as by hand, through its menu ("Overview").
 */
async function toDeck(s, url) {
	await s.go(HOST + url)
	await waitFor(s, '.pace-run, .deck-head')
	if (await s.script('return !!document.querySelector(".pace-run")')) {
		await s.script('document.querySelector(".pace-top .pulse-menu > .pulse-btn").click()')
		await waitFor(s, '.pace-top .pulse-menu-item[data-key="deck"]')
		await s.script('document.querySelector(\'.pace-top .pulse-menu-item[data-key="deck"]\').click()')
	}
	await waitFor(s, '.deck-head')
}

/*
 * Self-paced, moderator deck (specification §5.2 step 4.2): the toggle in the
 * deck menu, state and status chips, the open-quiz dialog, the locked deck, a
 * deadline that expires while the deck is open, and the room list. Since the
 * rollout (4.6) without ?pace=1: the toggle is in every quiz deck.
 *
 * Whatever the pass changes in the rooms (practice run back to draft,
 * deadlines, closing, release) it turns back at the end: pace-run, pace-phone
 * and pace-screen need the rooms in their initial state. Before a room is
 * reset the session is parked on about:blank (brute force, §6.4).
 */
async function paceModPass(s) {
	const P = paceData
	const deck = (code) => `/apps/pulse/room/${code}`
	const openMenu = { js: 'document.querySelector(".deck-head .pulse-menu > .pulse-btn").click()' }
	const escape = { js: 'document.activeElement.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }))' }
	// As with the keyboard: focus on the trigger, then trigger it; only this way
	// is "focus back after Escape" measurable (a script click does not focus).
	const primary = { js: 'const b = Array.from(document.querySelectorAll(".deck-head > .pulse-btn:not(.deck-back)")).pop(); b.focus(); b.click()' }
	const restore = []
	await login(s)
	try {
		// Draft: status line with the people present (presence is valid for 15 s, so set it fresh).
		probe('present', P.draft.code, '4')
		await shot(s, {
			name: 'pace-deck-draft', url: deck(P.draft.code), size: LAPTOP,
			waitSel: '.pace-ds .pulse-chip', settle: 800, measure: PACE_DECK,
			note: 'Eigenes Tempo: Deck im Entwurf, Statuszeile (4 da, 2 beigetreten)',
		})
		await shot(s, {
			name: 'pace-deck-draft-menu', size: LAPTOP, actions: [openMenu, { sleep: 400 }], measure: PACE_MENU,
			note: 'Deck-Menü im Entwurf: Häkchen „Self-paced", Rotes zuletzt',
		})
		await s.script(escape.js)
		await sleep(300)

		// Open-quiz dialog: race (default), homework with a preset, error.
		await shot(s, {
			name: 'pace-dialog-race', size: LAPTOP, actions: [primary, { wait: '.pod' }, { sleep: 500 }], measure: PACE_DIALOG,
			note: 'Öffnen-Dialog: Rennen (Vorgabe „When I close it")',
		})
		await shot(s, {
			name: 'pace-dialog-homework', size: LAPTOP,
			actions: [{ js: 'document.querySelectorAll(".pod .pseg")[0].querySelectorAll(".pseg-item")[1].click()' }, { wait: '.pod-presets' },
				{ js: 'document.querySelector(\'.pod-presets [data-preset="1w"]\').click()' }, { sleep: 500 }],
			measure: PACE_DIALOG, note: 'Öffnen-Dialog: Hausaufgabe, Vorwahl „In 1 week", Relativzeile',
		})
		await shot(s, {
			name: 'pace-dialog-homework-each', size: LAPTOP,
			actions: [{ js: 'document.querySelectorAll(".pod .pseg")[2].querySelectorAll(".pseg-item")[0].click()' }, { sleep: 400 }],
			measure: PACE_DIALOG, note: 'Öffnen-Dialog: Frist + „After each question" -> Warnung',
		})
		// Too early (30 s): inline error instead of the relative line; submitting stays local.
		const early = 'const d = new Date(Date.now() + 30000); const p = (v) => String(v).padStart(2, "0");'
			+ ' const el = document.querySelector(".pace-input");'
			+ ' el.value = d.getFullYear() + "-" + p(d.getMonth() + 1) + "-" + p(d.getDate()) + "T" + p(d.getHours()) + ":" + p(d.getMinutes());'
			+ ' el.dispatchEvent(new Event("input", { bubbles: true }))'
		await shot(s, {
			name: 'pace-dialog-error', size: LAPTOP,
			actions: [{ js: early }, { js: 'document.querySelector(".pod-submit").click()' }, { sleep: 500 }],
			measure: PACE_DIALOG, note: 'Öffnen-Dialog: Frist zu früh -> Inline-Fehler, nichts gesendet',
		})
		// Escape closes, the focus returns to "Open quiz …"; the room stays a draft.
		await s.script(escape.js)
		await sleep(400)
		const back = await s.script('const a = document.activeElement; return { dialog: !!document.querySelector(".pod"), focus: a ? (a.textContent.trim() || a.getAttribute("aria-label") || a.tagName) : "" }')
		const draftState = JSON.parse(probe('window', P.draft.code).replace(/^ok /, '')).state
		index.push(`| (Messung) | | ${deck('<CODE>')} | Dialog mit Escape zu: ${back.dialog ? 'NOCH OFFEN' : 'geschlossen'}, Fokus „${back.focus}", Raum ${draftState} |`)

		// Practice run in draft: hint + "End practice run", button "Open practice run".
		await s.go('about:blank')
		probe('reset', P.practice.code)
		restore.push(() => probe('open', P.practice.code, '0'))
		await shot(s, {
			name: 'pace-dialog-practice', url: deck(P.practice.code), size: LAPTOP,
			waitSel: '.deck-head > .pulse-btn.is-primary', actions: [primary, { wait: '.pod' }, { sleep: 500 }], measure: PACE_DIALOG,
			note: 'Öffnen-Dialog im Probelauf: Hinweis, Rückmeldung fest',
		})
		await s.script(escape.js)
		probe('open', P.practice.code, '0')
		restore.pop()

		// Locked, open (race without a deadline): hint "Close the quiz, then …".
		await shot(s, {
			name: 'pace-deck-open', url: deck(P.race.code), open: () => toDeck(s, deck(P.race.code)), size: LAPTOP,
			waitSel: '.deck-lock-note', settle: 1500, measure: PACE_DECK,
			note: 'Deck offen (Rennen): gesperrt, Zustand „Open now"',
		})
		await shot(s, {
			name: 'pace-deck-open-menu', size: LAPTOP, actions: [openMenu, { sleep: 400 }], measure: PACE_MENU,
			note: 'Deck-Menü offen: Gründe an den deaktivierten Einträgen',
		})
		await s.script(escape.js)

		// Homework (open with a deadline, no timer): chip "Open until …", no "· 30s".
		await shot(s, {
			name: 'pace-deck-homework', url: deck(P.homework.code), open: () => toDeck(s, deck(P.homework.code)), size: LAPTOP,
			waitSel: '.deck-lock-note', settle: 1500, measure: PACE_DECK,
			note: 'Deck offen (Hausaufgabe): Zustand „Open until …", Zeilen ohne Zeitlimit',
		})

		// Deadline while the deck is open (mid): set via probe -> the chip follows
		// through polling (≤ 10 s); once it expires -> chip and menu immediately.
		await toDeck(s, deck(P.mid.code))
		await waitFor(s, '.pace-ds .pulse-chip')
		await waitForText(s, '.deck-state', /Open now/)
		restore.push(() => probe('window', P.mid.code, 'closesAt=0'))
		const set1 = JSON.parse(probe('window', P.mid.code, 'closesAt=now+25').replace(/^ok /, ''))
		const tUntil = await waitForText(s, '.deck-state', /until/, 20000)
		await waitForText(s, '.deck-state', /closed/i, 40000)
		const lag = Date.now() / 1000 - set1.closesAt
		index.push(`| (Messung) | | ${deck('<CODE>')} | Frist per Probe gesetzt: Chip „Open until" nach ${(tUntil / 1000).toFixed(1)} s; abgelaufen: „Quiz closed" ${lag.toFixed(1)} s nach der Frist |`)
		await shot(s, {
			name: 'pace-deck-closed', size: LAPTOP, settle: 600, measure: PACE_DECK,
			note: 'Deck nach Fristablauf: Zustand „Quiz closed", Hinweis „…Reset the room…"',
		})
		await shot(s, {
			name: 'pace-deck-closed-menu', size: LAPTOP, actions: [openMenu, { sleep: 400 }], measure: PACE_MENU,
			note: 'Deck-Menü geschlossen: „Release or reset first."',
		})
		await s.script(escape.js)
		// Open again (deadline removed) and then expired via probe; both only
		// arrive through polling.
		probe('window', P.mid.code, 'closesAt=0')
		const tOpen = await waitForText(s, '.deck-state', /Open now/, 20000)
		probe('window', P.mid.code, 'closesAt=now-1')
		const tExpired = await waitForText(s, '.deck-state', /closed/i, 20000)
		const menuHint = await s.script('document.querySelector(".deck-head .pulse-menu > .pulse-btn").click(); return new Promise((r) => setTimeout(() => { const b = document.querySelector(\'.pulse-menu-item[data-key="pace"]\'); r(b ? (b.disabled ? "aus: " + b.textContent.trim().replace(/\\s+/g, " ") : "an") : "fehlt") }, 300))')
		await s.script(escape.js)
		index.push(`| (Messung) | | ${deck('<CODE>')} | Frist entfernt: „Open now" nach ${(tOpen / 1000).toFixed(1)} s; per Probe abgelaufen: „Quiz closed" nach ${(tExpired / 1000).toFixed(1)} s, Menü „Self-paced" ${menuHint} |`)
		probe('window', P.mid.code, 'closesAt=0')
		restore.pop()

		// Room list with all four states: mid briefly closed, wide briefly released.
		restore.push(() => probe('window', P.mid.code, 'closedAt=0'))
		probe('window', P.mid.code, 'closedAt=now')
		restore.push(() => probe('window', P.wide.code, 'closedAt=0', 'releasedAt=0'))
		probe('window', P.wide.code, 'closedAt=now', 'releasedAt=now')
		await shot(s, {
			name: 'pace-rooms', url: '/apps/pulse/', size: [1440, 2000],
			waitSel: '.myroom', settle: 1500, measure: PACE_ROOMS,
			note: 'Raumliste: Zustands-Chips Entwurf / offen / offen mit Frist / geschlossen / freigegeben',
		})
	} finally {
		for (const r of restore.reverse()) {
			try { r() } catch (e) { console.error('Zurückdrehen scheiterte:', e.message) }
		}
	}
}

/** Dark: deck in draft, homework dialog (date field!) and locked deck. */
async function paceModDark(s) {
	const P = paceData
	const deck = (code) => `/apps/pulse/room/${code}`
	await login(s)
	probe('present', P.draft.code, '4')
	await shot(s, {
		name: 'dark-pace-dialog-homework', url: deck(P.draft.code), size: LAPTOP,
		waitSel: '.deck-head > .pulse-btn.is-primary',
		actions: [{ js: 'Array.from(document.querySelectorAll(".deck-head > .pulse-btn:not(.deck-back)")).pop().click()' }, { wait: '.pod' },
			{ js: 'document.querySelectorAll(".pod .pseg")[0].querySelectorAll(".pseg-item")[1].click()' }, { wait: '.pace-input' }, { sleep: 500 }],
		measure: PACE_DIALOG, note: 'Öffnen-Dialog Hausaufgabe, dunkel',
	})
	await shot(s, {
		name: 'dark-pace-deck-open', url: deck(P.race.code), open: () => toDeck(s, deck(P.race.code)), size: LAPTOP,
		waitSel: '.deck-lock-note', settle: 1500, measure: PACE_DECK, note: 'Deck offen (gesperrt), dunkel',
	})
}

/* ------------------------------------------------ Self-paced: run view */

// Projector preview of the run view (§1.7): an iframe with the real
// projector page. It is only ready once the page is up there and, as long
// as the room is open and someone has started, shows the race rather than the
// lobby of the first render (Screen.vue raceView). Without aborting: whatever
// is missing then shows up in the measurement line.
const PREVIEW_READY = `(() => {
	const f = document.querySelector('.spv-frame')
	const d = f && f.contentDocument
	if (!d || !d.querySelector('.scr')) { return false }
	const run = document.querySelector('.pace-run')
	const vm = run && run.__vue__
	return !(vm && vm.$options.name === 'PaceRun' && vm.state === 'open' && vm.startedCount > 0) || !!d.querySelector('.scr.is-race .pace-race')
})()`
// What does the preview show? Open with at least one start, it has to be the race;
// otherwise `KEIN RENNEN`. Plus the stage INSIDE the frame: if it overflows there,
// the small box cuts it off just like the screen in the hall. This only counts
// when the box is (partly) in view: out of sight Firefox throttles
// requestAnimationFrame in the iframe (measured 1.5 instead of 60 frames/s), and
// fitting the projector page (Screen.vue fitPass) runs on rAF; down in
// the side column it is not done after 1.5 s, in view after 0.3 s.
// An expression that takes the run view (.pace-run) and returns a sentence.
const PREVIEW_SAYS = `((run) => {
	const pv = run && run.querySelector('.spv-in')
	if (!pv) { return '' }
	const f = pv.querySelector('iframe')
	let d = null
	try { d = f && f.contentDocument } catch (e) { d = null }
	const vm = run.__vue__ && run.__vue__.$options.name === 'PaceRun' ? run.__vue__ : null
	if (!vm) { return 'Vorschau: Zustand der Laufansicht UNLESBAR' }
	const want = vm.state === 'open' && vm.startedCount > 0
	const shows = !(d && d.querySelector('.scr')) ? 'Beamer-Seite NICHT geladen'
		: d.querySelector('.scr.is-race .pace-race') ? 'Rennen'
			: d.querySelector('.scr.is-race .scr-final') ? 'Endstand'
				: d.querySelector('.scr.is-race .scr-race-note') ? 'Zähler'
					: d.querySelector('.scr-lobby') ? 'Lobby' : 'unbekannt'
	const st = d && d.querySelector('.scr-stage')
	const over = st ? st.scrollHeight - st.clientHeight : 0
	const r = pv.getBoundingClientRect()
	const side = pv.closest('.pace-side')
	const sr = side ? side.getBoundingClientRect() : { top: 0, bottom: window.innerHeight }
	const seen = r.bottom > Math.max(0, sr.top) && r.top < Math.min(window.innerHeight, sr.bottom)
	const stage = !st ? '' : !seen ? ', außer Sicht (Einpassen gedrosselt' + (over > 1 ? ', noch ' + over + ' px' : '') + ')'
		: ', Bühne im Rahmen ' + (over > 1 ? 'LÄUFT ÜBER ' + over + ' px' : 'passt')
	return 'Vorschau ' + pv.clientWidth + '×' + pv.clientHeight + ': ' + shows
		+ (want && shows !== 'Rennen' ? ' — KEIN RENNEN (offen, ' + vm.startedCount + ' gestartet)' : '')
		+ (shows === 'Rennen' ? ' (' + (d.querySelector('.pace-race-join') ? 'Beitrittsblock' : d.querySelector('.pace-race-board') ? 'Spitze' : 'ohne Seitenspalte') + ')' : '')
		+ stage
})`
// Marks of the preview measurement that count as an anomaly (last index line).
const PREVIEW_MARKS = ['KEIN RENNEN', 'LÄUFT ÜBER', 'NICHT geladen', 'UNLESBAR', 'NICHT IM BILD', 'KEINE VORSCHAU', 'WAAGERECHT']
function previewMarks() {
	const line = index[index.length - 1]
	for (const mark of PREVIEW_MARKS) {
		if (line.includes(mark)) { overflow.push(line.split(' | ')[0].slice(2) + ': Beamer-Vorschau ' + mark) }
	}
}

// Run view: header chips, facts, status line, counter, table, side column
// (grading, projector preview), row menus.
const PACE_RUN = `
	const run = document.querySelector('.pace-run')
	if (!run) { return null }
	const txt = (el) => (el ? el.textContent.trim().replace(/\\s+/g, ' ') : '')
	const short = (v) => v.replace(/\\b(until|on) [^—·]*\\d[^—·]*?(?= —|\\.$|$)/g, '$1 …')
	const rows = Array.from(run.querySelectorAll('.pace-table tbody tr'))
	const dash = Array.from(run.querySelectorAll('.pace-table tbody td')).filter((td) => txt(td) === '—').length
	const parts = ['Kopf: ' + Array.from(run.querySelectorAll('.pace-top-row .pulse-chip')).map((c) => short(txt(c))).join(' · '),
		'Fakten „' + txt(run.querySelector('.pace-facts')) + '"',
		'Status „' + short(txt(run.querySelector('.pace-status'))) + '"',
		'Zähler „' + txt(run.querySelector('.pace-done')) + '"',
		rows.length + ' Zeilen (' + txt(run.querySelector('.pace-count')) + '), sortiert „' + txt(run.querySelector('.pace-sort .pseg-item.is-active'))
			+ '" (' + run.querySelectorAll('.pace-sort .pseg-item').length + ' Wahlen), oben: ' + rows.slice(0, 3).map((r) => txt(r.querySelector('.pace-nick'))).join(', '),
		dash + ' Zellen „—"',
		run.querySelectorAll('.pace-table .pace-act .pulse-menu').length + ' Zeilenmenüs',
		run.querySelector('.pace-join') ? 'Beitritt da' : 'kein Beitritt',
		run.querySelectorAll('.pace-q').length + ' Fragen']
	const g = run.querySelector('.pace-grading')
	if (g) {
		const ck = g.querySelector('.pace-checked')
		parts.push('Bewertung „' + txt(g.querySelector('.pace-side-head')) + '": ' + g.querySelectorAll(':scope > .pace-grade-q').length + ' Fragen / '
			+ g.querySelectorAll(':scope > .pace-grade-q .tg-row').length + ' Antworten'
			+ (ck ? ', „' + txt(ck.querySelector('summary')) + '" ' + (ck.open ? 'offen' : 'zu') + ' (' + ck.querySelectorAll('.tg-row.is-accepted').length + ' richtig, '
				+ ck.querySelectorAll('.tg-row.is-rejected').length + ' falsch)' : '')
			+ (g.querySelector('.pace-grade-warn') ? ', Warnung „' + txt(g.querySelector('.pace-grade-warn')) + '"' : ''))
	} else {
		parts.push('keine Bewertung')
	}
	const pvSays = ${PREVIEW_SAYS}(run)
	if (pvSays) { parts.push(pvSays) }
	if (run.querySelector('.pace-empty')) { parts.push('leer: „' + txt(run.querySelector('.pace-empty')) + '"') }
	if (run.querySelector('.pace-offline')) { parts.push('OFFLINE-Hinweis') }
	return parts.join(', ')
`
// Separate capture of the projector preview (§5.2 step 4.5): the box fully in
// view (the side column is scrolled for that), the race inside it, the stage
// fits, and the page in the frame really is 1280×720 without horizontal scrolling.
const PACE_PREVIEW = `
	const run = document.querySelector('.pace-run')
	const pv = run && run.querySelector('.spv-in')
	if (!pv) { return 'KEINE VORSCHAU' }
	const f = pv.querySelector('iframe')
	const d = f && f.contentDocument
	const r = pv.getBoundingClientRect()
	const side = pv.closest('.pace-side')
	const sr = side ? side.getBoundingClientRect() : { top: 0, bottom: window.innerHeight }
	const seen = r.top >= Math.max(0, sr.top) - 1 && r.bottom <= Math.min(window.innerHeight, sr.bottom) + 1
	const k = ((f && f.style.transform) || '').match(/scale\\(([\\d.]+)\\)/)
	const de = d && d.documentElement
	const wx = de ? de.scrollWidth - de.clientWidth : 0
	return ${PREVIEW_SAYS}(run)
		+ ', Kasten ' + (seen ? 'ganz im Bild' : 'NICHT IM BILD') + (k ? ', Maßstab ' + Number(k[1]).toFixed(3) : '')
		+ (de ? ', Beamer-Seite ' + de.clientWidth + '×' + de.clientHeight + ', ' + (wx > 0 ? 'WAAGERECHT ' + wx + ' px' : 'waagerecht 0 px') : '')
		+ (d ? ', ' + d.querySelectorAll('.pace-race-row').length + ' Balkenzeilen' + (d.querySelector('.pace-race.is-dense') ? ' (eng)' : '') : '')
`
// Open menu of the run view: toggles with check marks, red items last.
const PACE_RUN_MENU = `
	const list = document.querySelector('.pace-top .pulse-menu-list')
	if (!list) { return null }
	const items = Array.from(list.querySelectorAll('.pulse-menu-item'))
	const last = items.length ? items[items.length - 1] : null
	return 'Menü: ' + items.map((b) => b.dataset.key
		+ (b.getAttribute('role') === 'menuitemcheckbox' ? (b.getAttribute('aria-checked') === 'true' ? ' ✓' : ' ☐') : '')
		+ (b.disabled ? ' (aus)' : '') + (b.classList.contains('is-danger') ? ' [rot]' : '')).join(', ')
		+ (items.some((b) => b.classList.contains('is-danger')) && !(last && last.classList.contains('is-danger')) ? ' — ROT NICHT ZULETZT' : '')
`
// Confirmation (PulseConfirm): title, buttons, which one is filled, and whether the free-text sentence is included.
const PACE_CONFIRM = `
	const d = document.querySelector('.pconfirm')
	if (!d) { return null }
	const txt = (el) => (el ? el.textContent.trim().replace(/\\s+/g, ' ') : '')
	const text = txt(d.querySelector('.pconfirm-text'))
	const i = text.search(/\\d+ free-text answers? (is|are) not checked yet/)
	return 'Bestätigung „' + txt(d.querySelector('.pconfirm-title')) + '": ' + Array.from(d.querySelectorAll('.pulse-btn')).map((b) => txt(b)
		+ (b.classList.contains('is-danger') ? ' [rot]' : b.classList.contains('is-primary') ? ' [gefüllt]' : '')).join(' · ')
		+ (i >= 0 ? ' — Satz „' + text.slice(i) + '"' : ' — ohne Freitext-Satz')
`
// Focus after a run view dialog: back on the menu trigger (§1.5).
const FOCUS_TRIGGER = `
	const trig = document.querySelector('.pace-top .pulse-menu > .pulse-btn')
	const a = document.activeElement
	return { dialog: !!document.querySelector('.pod'), onTrigger: !!trig && a === trig,
		focus: a ? (a.getAttribute('aria-label') || a.textContent.trim().replace(/\\s+/g, ' ').slice(0, 40) || a.tagName.toLowerCase()) : '' }
`
// Main action "Check %n answers": focus on the grading heading, box visible.
const FOCUS_GRADING = `
	const h = document.getElementById('pace-grade-head')
	if (!h) { return 'keine Bewertung' }
	const r = h.getBoundingClientRect()
	return 'Fokus ' + (document.activeElement === h ? 'auf „' + h.textContent.trim() + '"' : 'NICHT auf der Bewertung')
		+ ', Überschrift ' + (r.top >= 0 && r.bottom <= window.innerHeight ? 'im Bild' : 'AUSSERHALB DES BILDS')
`
// Summary from the run view: back button, export label, hint.
const SUMMARY_VIEW = `
	const v = document.querySelector('.summary-view')
	if (!v) { return null }
	const txt = (el) => (el ? el.textContent.trim().replace(/\\s+/g, ' ') : '')
	return 'Zusammenfassung: Zurück „' + txt(v.querySelector('.summary-back')) + '", Export „' + txt(v.querySelector('.export-btn')) + '"'
		+ (v.querySelector('.summary-warn') ? ', Hinweis „' + txt(v.querySelector('.summary-warn')) + '"' : ', kein Hinweis')
`
// Record /progress requests from the moment this is installed (Resource Timing).
// The test rig's own cross-check fetches (harness=1) do not count.
const PROGRESS_WATCH = `
	window.__prog = []
	new PerformanceObserver((list) => {
		for (const e of list.getEntries()) {
			if (/\\/progress(\\?|$)/.test(e.name) && !/harness=1/.test(e.name)) { window.__prog.push([e.startTime, e.responseEnd]) }
		}
	}).observe({ type: 'resource' })
	return true
`
// Count overlapping durations: never two /progress requests in flight at once.
const PROGRESS_OVERLAP = `
	const xs = (window.__prog || []).slice().sort((a, b) => a[0] - b[0])
	let overlap = 0
	let end = -1
	for (const [s, e] of xs) {
		if (s < end - 0.5) { overlap++ }
		end = Math.max(end, e)
	}
	return { n: xs.length, overlap }
`
// Count /pace calls from the moment this is installed (Resource Timing). Installed
// several times, it still counts only once: one observer per page.
const PACE_WATCH = `
	window.__pace = 0
	if (!window.__paceObs) {
		window.__paceObs = new PerformanceObserver((list) => {
			for (const e of list.getEntries()) {
				if (e.name.split('?')[0].endsWith('/pace')) { window.__pace++ }
			}
		})
		window.__paceObs.observe({ type: 'resource' })
	}
	return true
`

/** Room row from GET /rooms (like "My rooms"); the server has the last word. */
async function roomRow(s, code) {
	return s.async(`
		const done = arguments[arguments.length - 1]
		fetch(OC.generateUrl('/apps/pulse/api/1.0/rooms'), { credentials: 'same-origin', headers: { requesttoken: document.head.dataset.requesttoken } })
			.then((r) => r.json()).then((rows) => done(rows.find((r) => r.code === arguments[0]) || null))
			.catch((e) => done({ error: String(e) }))
	`, [code])
}

/** GET on a Pulse route from the signed-in page (cross-check, harness=1). */
async function pageGet(s, path) {
	return s.async(`
		const done = arguments[arguments.length - 1]
		fetch(OC.generateUrl(arguments[0]) + '?harness=1', { credentials: 'same-origin', headers: { requesttoken: document.head.dataset.requesttoken } })
			.then((r) => r.json()).then(done).catch((e) => done({ error: String(e) }))
	`, [path])
}

/** Answer key of the free-text question (the owner's room JSON). */
async function textKey(s, code) {
	const room = await pageGet(s, `/apps/pulse/api/1.0/rooms/${code}`)
	const p = ((room && room.polls) || []).find((x) => x.type === 'text')
	const key = (p && p.answerKey) || {}
	return { accepted: key.accepted || [], rejected: key.rejected || [] }
}

/** State from /progress: names and pending free texts (sum of the groups). */
async function progressOf(s, code) {
	const p = await pageGet(s, `/apps/pulse/api/1.0/rooms/${code}/progress`)
	const players = (p && p.players) || []
	return { names: players.map((x) => x.nickname), pending: ((p && p.pendingAnswers) || []).reduce((a, x) => a + x.count, 0) }
}

/** Wait for a file in the download folder (done = without .part). */
async function waitFile(dir, re, timeout = 12000) {
	const until = Date.now() + timeout
	while (Date.now() < until) {
		const f = readdirSync(dir).find((x) => re.test(x) && !x.endsWith('.part'))
		if (f && !existsSync(join(dir, f + '.part'))) {
			await sleep(300)
			return f
		}
		await sleep(250)
	}
	return null
}

/** Downloads into a folder without asking (CSV buttons of the run view). */
function downloadPrefs(dir) {
	return {
		'browser.download.folderList': 2,
		'browser.download.dir': dir,
		'browser.download.useDownloadDir': true,
		'browser.download.always_ask_before_handling_new_types': false,
		'browser.download.manager.showWhenStarting': false,
		'browser.download.alwaysOpenPanel': false,
		'browser.helperApps.neverAsk.saveToDisk': 'text/csv',
	}
}

/*
 * Self-paced, run view (specification §5.2 steps 4.3a + 4.3b):
 * race open, homework with hidden points, "everyone through",
 * closed (pending free texts -> "Check %n answers"), released,
 * practice run closed, each at 1440×900 and 1024×768. In light mode also the
 * menus, the extend dialog, the reopen dialog after the deadline has passed
 * and the summary with "Back to progress". States the rooms do not have in
 * their initial state are set briefly by the probe (`window`) and turned back
 * afterwards: pace-phone and pace-screen need them as created.
 * Nothing here changes the room through the UI; clicks only happen on
 * `click` (paceRunClicks). No probe here deletes or resets, so
 * about:blank is not needed (§6.4); the projector preview only polls.
 */
async function paceRunPass(s, dark) {
	const P = paceData
	const tag = dark ? 'dark-' : ''
	const run = (code) => `/apps/pulse/room/${code}`
	const ready = '.pace-run .pace-table, .pace-run .pace-empty'
	// The preview is an iframe: only once the projector page is up there (open
	// with starters: the race) is the picture honest.
	const framed = { until: PREVIEW_READY, timeout: 10000 }
	const both = async (name, code, note, extra = {}) => {
		for (const [size, suffix] of [[LAPTOP, ''], [[1024, 768], '-1024']]) {
			await shot(s, {
				name: `${tag}pace-run-${name}${suffix}`, url: run(code), size, waitSel: ready, settle: 1500, actions: [framed],
				measure: PACE_RUN, note: note + (suffix ? ' (1024×768)' : '') + (dark ? ', dunkel' : ''), ...extra,
			})
			previewMarks()
		}
	}
	const menu = { js: 'document.querySelector(".pace-top .pulse-menu > .pulse-btn").click()' }
	const menuOpen = { wait: '.pace-top .pulse-menu-list' }
	const item = (key) => ({ js: `document.querySelector('.pace-top .pulse-menu-item[data-key="${key}"]').click()` })
	const escape = 'document.activeElement.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }))'
	const restore = []
	await login(s)
	try {
		await both('race', P.race.code, 'Laufansicht: Rennen offen (ohne Frist, mit Timer, Rückmeldung je Frage) — Bewertung, Beamer-Vorschau')
		if (!dark) {
			await shot(s, {
				name: 'pace-run-race-menu', size: LAPTOP, actions: [menu, menuOpen, { sleep: 300 }],
				measure: PACE_RUN_MENU, note: 'Laufansicht: Menü offen (Rennen offen, ohne Frist)',
			})
			await s.script(escape)
			// The preview itself (§5.2 step 4.5: "now shows the race"). At
			// 1440×900 it sits below the grading card; the side column is scrolled
			// down to the box, otherwise the picture only shows the heading.
			await shot(s, {
				name: 'pace-run-race-preview', url: run(P.race.code), size: LAPTOP, waitSel: ready, settle: 1500,
				actions: [framed, { js: 'document.querySelector(".spv-in").scrollIntoView({ block: "center", behavior: "instant" })' }, { sleep: 400 }],
				measure: PACE_PREVIEW, note: 'Laufansicht: Beamer-Vorschau ins Bild gescrollt (Rennen offen, mit Startern) — die Beamer-Seite 1280×720, verkleinert',
			})
			previewMarks()
		}
		await both('homework', P.homework.code, 'Laufansicht: Hausaufgabe offen (Frist, ohne Timer, Auflösung am Ende) — Punkte verborgen')
		if (!dark) {
			await shot(s, {
				name: 'pace-run-homework-menu', size: LAPTOP, actions: [menu, menuOpen, { sleep: 300 }],
				measure: PACE_RUN_MENU, note: 'Laufansicht: Menü offen (Hausaufgabe offen mit Frist: „Change the end …", „Release results", beide CSV)',
			})
			// "Release results" from the open window also closes it: its own
			// confirmation with the number of people stopped. Only look, then cancel.
			await shot(s, {
				name: 'pace-run-homework-release-confirm', size: LAPTOP, actions: [item('release'), { wait: '.pconfirm' }, { sleep: 300 }],
				measure: PACE_CONFIRM, note: 'Laufansicht: „Release results" aus dem offenen Fenster (Hausaufgabe) -> „Close and release now?" (danach abgebrochen)',
			})
			await s.script(escape)
			await sleep(400)
			const hwRow = await roomRow(s, P.homework.code)
			const hwState = hwRow && hwRow.window ? hwRow.window.state : '?'
			index.push(`| (Messung) | | ${run('<CODE>')} | Freigabe-Bestätigung abgebrochen: ${await s.script('return document.querySelector(".pconfirm") ? "NOCH OFFEN" : "zu"')}, Raum bleibt ${hwState} |`)
			if (hwState !== 'open') { overflow.push(`Abgebrochene Freigabe: Hausaufgabe steht auf ${hwState}`) }
			// "Change the end …": deadline prefilled, button "Save"; Escape -> focus on the menu trigger.
			await shot(s, {
				name: 'pace-run-extend-dialog', size: LAPTOP, actions: [menu, menuOpen, item('extend'), { wait: '.pod' }, { sleep: 500 }],
				measure: PACE_DIALOG, note: 'Laufansicht: „Change the end …" (offen, Frist vorbelegt)',
			})
			await s.script(escape)
			await sleep(400)
			const f1 = await s.script(FOCUS_TRIGGER)
			index.push(`| (Messung) | | ${run('<CODE>')} | Verlängern-Dialog mit Escape zu: ${f1.dialog ? 'NOCH OFFEN' : 'geschlossen'}, Fokus ${f1.onTrigger ? 'am Menü-Auslöser' : 'NICHT am Menü-Auslöser („' + f1.focus + '")'} |`)
			if (f1.dialog || !f1.onTrigger) { overflow.push('Verlängern-Dialog: Fokus nach Escape nicht am Menü-Auslöser') }

			// Summary from the run view (open: warning), back via "Back to progress".
			await shot(s, {
				name: 'pace-run-summary', size: LAPTOP, actions: [menu, menuOpen, item('summary'), { wait: '.summary-view .summary-back' }, { sleep: 800 }],
				measure: SUMMARY_VIEW, note: 'Zusammenfassung aus der Laufansicht (Hausaufgabe offen): Warnung, „Results per question (CSV)", „Back to progress"',
			})
			const t0 = Date.now()
			await s.script('document.querySelector(".summary-view .summary-back").click()')
			await waitFor(s, ready)
			index.push(`| (Messung) | | ${run('<CODE>')} | Zusammenfassung -> „Back to progress": Laufansicht nach ${((Date.now() - t0) / 1000).toFixed(1)} s wieder da |`)
			// "Close quiz" with a deadline: ONE /pace call (close {release:false}), no
			// room fetch before it any more; the deadline stays, nothing is released.
			const hw0 = JSON.parse(probe('window', P.homework.code).replace(/^ok /, ''))
			restore.push(() => probe('window', P.homework.code, 'closedAt=0'))
			await s.script(PACE_WATCH)
			await s.script('document.querySelector(".pace-primary").click()')
			await waitFor(s, '.pconfirm')
			await s.script('document.querySelector(".pconfirm .pulse-btn.is-primary").click()')
			await waitForText(s, '.pace-status', /^Closed/, 15000)
			const hwc = ((await roomRow(s, P.homework.code)) || {}).window || {}
			const hwCalls = await s.script('return window.__pace')
			index.push(`| (Messung) | | ${run('<CODE>')} | „Close quiz“ mit Frist: ${hwCalls} /pace-Aufruf(e); Server: state ${hwc.state}, releasedAt ${hwc.releasedAt}, Frist ${hwc.closesAt === hw0.closesAt ? 'unverändert' : 'GEÄNDERT'} |`)
			if (hwCalls !== 1 || hwc.state !== 'closed' || hwc.releasedAt !== 0 || hwc.closesAt !== hw0.closesAt) { overflow.push('„Close quiz“ mit Frist: Aufrufe oder Server-Stand passen nicht') }
			probe('window', P.homework.code, 'closedAt=0')
			restore.pop()
		}

		// "Everyone through": only those who have finished are present right now (presence is valid for 15 s, so fresh per image).
		// A race room where someone has finished and someone is still working.
		const thr = ['race', 'mid', 'wide'].find((k) => P[k] && P[k].race && P[k].race.finished > 0 && P[k].race.started > P[k].race.finished) || 'race'
		await both('through', P[thr].code, 'Laufansicht: alle Anwesenden durch -> Statuszeile „Everyone still here is through"', {
			open: async () => {
				probe('online', P[thr].code, 'finished')
				await s.go(HOST + run(P[thr].code))
				await waitForText(s, '.pace-status', /Everyone still here/, 12000)
			},
		})

		// Closed (homework, points still hidden): pending free texts come
		// before the release -> "Check %n answers".
		restore.push(() => probe('window', P.homework.code, 'closedAt=0'))
		probe('window', P.homework.code, 'closedAt=now')
		await both('closed', P.homework.code, 'Laufansicht: Hausaufgabe geschlossen -> Hauptaktion „Check %n answers" (offene Freitexte)')
		if (!dark) {
			await shot(s, {
				name: 'pace-run-closed-menu', size: LAPTOP, actions: [menu, menuOpen, { sleep: 300 }],
				measure: PACE_RUN_MENU, note: 'Laufansicht: Menü offen (geschlossen: „Reopen …", „Release results" im Menü, Rotes zuletzt)',
			})
			await s.script(escape)
			await shot(s, {
				name: 'pace-run-closed-check', size: LAPTOP,
				actions: [{ js: 'document.querySelector(".pace-primary").click()' }, { sleep: 500 }],
				measure: FOCUS_GRADING, note: 'Laufansicht: „Check %n answers" geklickt -> Bewertung, Fokus auf ihrer Überschrift',
			})
		}
		probe('window', P.homework.code, 'closedAt=0')
		restore.pop()

		// After the deadline has passed (homework): "Reopen …" -> prefilled with tomorrow + "Was: …".
		if (!dark) {
			const hw = JSON.parse(probe('window', P.homework.code).replace(/^ok /, ''))
			restore.push(() => probe('window', P.homework.code, 'closesAt=' + hw.closesAt))
			probe('window', P.homework.code, 'closesAt=now-60')
			await shot(s, {
				name: 'pace-run-reopen-dialog', url: run(P.homework.code), size: LAPTOP, waitSel: ready,
				actions: [{ wait: '.pace-state.is-warning' }, menu, menuOpen, item('reopen'), { wait: '.pod' }, { sleep: 500 }],
				measure: PACE_DIALOG, note: 'Laufansicht nach Fristablauf: „Reopen …" -> Vorbelegung morgen, „Was: …", Knopf „Reopen"',
			})
			await s.script(escape)
			await sleep(400)
			const f2 = await s.script(FOCUS_TRIGGER)
			index.push(`| (Messung) | | ${run('<CODE>')} | Wieder-öffnen-Dialog mit Escape zu: ${f2.dialog ? 'NOCH OFFEN' : 'geschlossen'}, Fokus ${f2.onTrigger ? 'am Menü-Auslöser' : 'NICHT am Menü-Auslöser („' + f2.focus + '")'} |`)
			if (f2.dialog || !f2.onTrigger) { overflow.push('Wieder-öffnen-Dialog: Fokus nach Escape nicht am Menü-Auslöser') }
			probe('window', P.homework.code, 'closesAt=' + hw.closesAt)
			restore.pop()
		}

		// Legacy: stopped as before close {release} (deadline 120 s after closing) -> "Reopen …" prefills "When I close it".
		if (!dark) {
			const rc = JSON.parse(probe('window', P.race.code).replace(/^ok /, ''))
			restore.push(() => probe('window', P.race.code, 'closedAt=0', 'closesAt=' + rc.closesAt))
			probe('window', P.race.code, 'closedAt=now', 'closesAt=now+120')
			await shot(s, {
				name: 'pace-run-reopen-legacy', url: run(P.race.code), size: LAPTOP, waitSel: ready,
				actions: [{ wait: '.pace-state.is-warning' }, menu, menuOpen, item('reopen'), { wait: '.pod' }, { sleep: 500 }],
				measure: PACE_DIALOG, note: 'Laufansicht, Altlast (gestoppt mit Frist 120 s nach dem Schluss): „Reopen …“ -> vorbelegt „When I close it“, ohne „Was: …“',
			})
			const leg = await s.script('return { end: document.querySelector(".pod .pseg-item.is-active").textContent.trim(), was: !!document.querySelector(".pod-was") }')
			index.push(`| (Messung) | | ${run('<CODE>')} | Altlast-Stopp: „Reopen …“ vorbelegt „${leg.end}“, ${leg.was ? 'MIT „Was: …“' : 'ohne „Was: …“'} |`)
			if (leg.end !== 'When I close it' || leg.was) { overflow.push('Altlast-Stopp: „Reopen …“ nicht mit „When I close it“ vorbelegt') }
			await s.script(escape)
			await sleep(400)
			probe('window', P.race.code, 'closedAt=0', 'closesAt=' + rc.closesAt)
			restore.pop()
		}

		// Released (race): pending free texts remain the main action, with the warning above.
		restore.push(() => probe('window', P.race.code, 'closedAt=0', 'releasedAt=0'))
		probe('window', P.race.code, 'closedAt=now', 'releasedAt=now')
		await both('released', P.race.code, 'Laufansicht: Rennen freigegeben — offene Freitexte: „Check %n answers" + „Standings update for everyone."')
		if (!dark) {
			await shot(s, {
				name: 'pace-run-released-menu', size: LAPTOP, actions: [menu, menuOpen, { sleep: 300 }],
				measure: PACE_RUN_MENU, note: 'Laufansicht: Menü offen (freigegeben: ohne Schalter, beide CSV, Rotes zuletzt)',
			})
			await s.script(escape)
		}
		probe('window', P.race.code, 'closedAt=0', 'releasedAt=0')
		restore.pop()

		// Practice run closed -> "End practice run".
		restore.push(() => probe('window', P.practice.code, 'closedAt=0'))
		probe('window', P.practice.code, 'closedAt=now')
		await both('practice-closed', P.practice.code, 'Laufansicht: Probelauf geschlossen -> „End practice run"')
		probe('window', P.practice.code, 'closedAt=0')
		restore.pop()
	} finally {
		for (const r of restore.reverse()) {
			try { r() } catch (e) { console.error('Zurückdrehen scheiterte:', e.message) }
		}
	}
}

/*
 * Click sequence on `click` (only there, §6.1; steps 4.3a + 4.3b): grade ->
 * regrade -> remove -> lock -> change the end (deadline, then back) ->
 * close ("Stop without releasing" = ONE /pace call) -> "Check %n answers"
 * -> "Reopen …" prefills "When I close it" (without a localStorage flag) ->
 * release (menu) -> both CSVs. Every step is cross-checked against the
 * server (GET /rooms, /rooms/{code}, /progress). Plus the in-flight
 * recording: never two /progress requests at once, even with five quick
 * actions (homework: "Show points" five times in a row). `dl` is the
 * session's download folder.
 */
async function paceRunClicks(s, dl) {
	const P = paceData
	const code = P.click.code
	const run = (c) => `/apps/pulse/room/${c}`
	const ready = '.pace-run .pace-table, .pace-run .pace-empty'
	const menu = 'document.querySelector(".pace-top .pulse-menu > .pulse-btn").click()'
	const item = (key) => `document.querySelector('.pace-top .pulse-menu-item[data-key="${key}"]').click()`
	const escape = 'document.activeElement.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }))'
	const openItem = async (key) => {
		await s.script(menu)
		await waitFor(s, `.pace-top .pulse-menu-item[data-key="${key}"]`)
		await s.script(item(key))
	}
	const note = (text) => index.push(`| (Messung) | | ${run('<CODE>')} | ${text} |`)

	// Five quick actions: each one calls refresh(), and a request is often still running.
	await s.size(LAPTOP)
	await s.go(HOST + run(P.homework.code))
	await waitFor(s, ready)
	await sleep(1500)
	await s.script(PROGRESS_WATCH)
	await s.script(menu)
	await waitFor(s, '.pace-top .pulse-menu-item[data-key="scores"]')
	await s.script(`
		let i = 0
		const go = () => {
			const el = document.querySelector('.pace-top .pulse-menu-item[data-key="scores"]')
			if (el) { el.click() }
			if (++i < 5) { setTimeout(go, 90) }
		}
		go()
	`)
	await sleep(7000)
	const burst = await s.script(PROGRESS_OVERLAP)
	const pts = await s.script('return !!Array.from(document.querySelectorAll(".pace-top-row .pulse-chip")).find((c) => /Points visible/.test(c.textContent))')
	note(`Einflug bei fünf schnellen Aktionen („Show points" 5×, Hausaufgabe): ${burst.n} /progress-Anfragen in 7 s, ${burst.overlap} überlappend; danach Punkte ${pts ? 'sichtbar' : 'verborgen'}`)
	if (burst.overlap) { overflow.push(`Einflug: ${burst.overlap} überlappende /progress-Anfragen (fünf schnelle Aktionen)`) }
	await shot(s, {
		name: 'pace-run-homework-points', size: LAPTOP, settle: 600, measure: PACE_RUN,
		note: 'Laufansicht: Hausaufgabe nach „Show points" (5× geschaltet) — Chip „Points visible", Punkte-Sortierung wählbar',
	})
	previewMarks()
	await s.script(escape)

	// The seed of `click` can end with a single pending free-text group; after
	// grading nothing would be pending any more. Additional people
	// (probe race, through the services) bring a second one, so that "Check %n
	// answers" and the free-text sentence occur along the sequence.
	await s.go(HOST + run(code))
	await waitFor(s, ready)
	const groupsNow = async () => ((await pageGet(s, `/apps/pulse/api/1.0/rooms/${code}/progress`)).pendingAnswers || []).length
	let groups = await groupsNow()
	for (let seed = 101; groups < 2 && seed < 106; seed++) {
		probe('race', code, '6', String(seed))
		groups = await groupsNow()
	}
	note(`Vorbereitung: ${groups} offene Freitext-Gruppen auf „click"`)
	if (groups < 2) { overflow.push('Klickstrecke: weniger als zwei offene Freitext-Gruppen') }
	await s.go(HOST + run(code))
	await waitFor(s, ready)
	await waitFor(s, '.pace-grading > .pace-grade-q .tg-ok')
	await sleep(1200)
	await s.script(PROGRESS_WATCH)
	await shot(s, {
		name: 'pace-run-click-grading', size: LAPTOP, settle: 300, measure: PACE_RUN,
		actions: [{ until: PREVIEW_READY, timeout: 10000 }],
		note: 'Klickstrecke: Bewertungskarte mit offenen Freitexten (ohne Lösungsschlüssel)',
	})
	previewMarks()

	// 1. Grade: the first pending answer as correct.
	const first = await s.script('const r = document.querySelector(".pace-grading > .pace-grade-q .tg-row"); return { sample: r.querySelector(".tg-text").textContent.trim(), count: parseInt(r.querySelector(".tg-count").textContent, 10) }')
	const before1 = await progressOf(s, code)
	await s.script('document.querySelector(".pace-grading > .pace-grade-q .tg-ok").click()')
	await waitForText(s, '.pace-checked > summary', /\(1\)/)
	const key1 = await textKey(s, code)
	const after1 = await progressOf(s, code)
	note(`Bewerten: „${first.sample}" (${first.count}×) als richtig -> „Checked just now (1)"; Server: akzeptiert ${key1.accepted.includes(first.sample) ? 'enthält sie' : 'OHNE sie'}, offene Freitexte ${before1.pending} -> ${after1.pending}`)
	if (!key1.accepted.includes(first.sample) || after1.pending !== before1.pending - first.count) { overflow.push('Bewerten: Server-Stand passt nicht') }
	await s.script('document.querySelector(".pace-checked").open = true')
	await shot(s, {
		name: 'pace-run-click-checked', size: LAPTOP, settle: 600, measure: PACE_RUN,
		note: 'Klickstrecke: nach dem Bewerten — „Checked just now" aufgeklappt (Umschalt-Knöpfe)',
	})
	previewMarks()

	// 2. Regrade: the same entry now wrong (same endpoint).
	await s.script('document.querySelector(".pace-checked .tg-no").click()')
	await waitFor(s, '.pace-checked .tg-row.is-rejected')
	const key2 = await textKey(s, code)
	note(`Umbewerten: „${first.sample}" jetzt falsch; Server: abgelehnt ${key2.rejected.includes(first.sample) ? 'enthält sie' : 'OHNE sie'}, akzeptiert ${key2.accepted.includes(first.sample) ? 'ENTHÄLT SIE NOCH' : 'ohne sie'}`)
	if (!key2.rejected.includes(first.sample) || key2.accepted.includes(first.sample)) { overflow.push('Umbewerten: Server-Stand passt nicht') }

	// 3. Remove: the last row of the table, through its row menu.
	const victim = await s.script('const rows = document.querySelectorAll(".pace-table tbody tr"); const r = rows[rows.length - 1]; r.querySelector(".pace-act .pulse-menu > .pulse-btn").click(); return { name: r.querySelector(".pace-nick").textContent.trim(), rows: rows.length, label: r.querySelector(".pace-act .pulse-menu > .pulse-btn").getAttribute("aria-label") }')
	await waitFor(s, '.pace-act .pulse-menu-item[data-key="remove"]')
	await s.script('document.querySelector(\'.pace-act .pulse-menu-item[data-key="remove"]\').click()')
	await waitFor(s, '.pconfirm')
	await shot(s, {
		name: 'pace-run-click-remove-confirm', size: LAPTOP, settle: 400, measure: PACE_CONFIRM,
		note: `Klickstrecke: Zeilenmenü („${victim.label}") -> „Remove from the quiz" -> Bestätigung`,
	})
	await s.script('document.querySelector(".pconfirm .pulse-btn.is-danger").click()')
	const until3 = Date.now() + 15000
	while (Date.now() < until3 && await s.script('return document.querySelectorAll(".pace-table tbody tr").length') !== victim.rows - 1) { await sleep(250) }
	const after3 = await progressOf(s, code)
	const rows3 = await s.script('return document.querySelectorAll(".pace-table tbody tr").length')
	note(`Entfernen: „${victim.name}" -> Tabelle ${victim.rows} -> ${rows3} Zeilen; Server: ${after3.names.length} Personen, ${after3.names.includes(victim.name) ? 'NAME NOCH DA' : 'Name frei'}`)
	if (rows3 !== victim.rows - 1 || after3.names.includes(victim.name)) { overflow.push('Entfernen: Server-Stand passt nicht') }

	// 4. Lock: toggle in the menu (the menu stays open), chip in the header.
	await openItem('lock')
	await waitForText(s, '.pace-top-row', /Joining locked/)
	await s.script(escape)
	const room4 = await pageGet(s, `/apps/pulse/api/1.0/rooms/${code}`)
	note(`Sperren: Chip „Joining locked"; Server: joinsLocked ${room4.joinsLocked}`)
	if (room4.joinsLocked !== true) { overflow.push('Sperren: joinsLocked nicht gesetzt') }

	// 5. Change the end: save the deadline "In 1 hour", then back to "When I close it".
	await openItem('extend')
	await waitFor(s, '.pod')
	await s.script('document.querySelectorAll(".pod .pseg")[0].querySelectorAll(".pseg-item")[1].click()')
	await waitFor(s, '.pod-presets')
	await s.script('document.querySelector(\'.pod-presets [data-preset="1h"]\').click()')
	await sleep(400)
	await shot(s, {
		name: 'pace-run-click-extend', size: LAPTOP, settle: 300, measure: PACE_DIALOG,
		note: 'Klickstrecke: „Change the end …" -> „At a set time", Vorwahl „In 1 hour"',
	})
	await s.script('document.querySelector(".pod-submit").click()')
	const until5 = Date.now() + 15000
	while (Date.now() < until5 && await s.script('return !!document.querySelector(".pod")')) { await sleep(200) }
	await sleep(300)
	const f5 = await s.script(FOCUS_TRIGGER)
	const w5 = ((await roomRow(s, code)) || {}).window || {}
	await waitForText(s, '.pace-state', /until/)
	note(`Ende ändern (Frist in 1 h, „Save"): Dialog ${f5.dialog ? 'NOCH OFFEN' : 'zu'}, Fokus ${f5.onTrigger ? 'am Menü-Auslöser' : 'NICHT am Menü-Auslöser („' + f5.focus + '")'}; Server: state ${w5.state}, Frist ${w5.closesAt > 0 ? 'gesetzt' : '0'}; Chip „Open until …"`)
	if (f5.dialog || !f5.onTrigger || w5.state !== 'open' || !(w5.closesAt > 0)) { overflow.push('Ende ändern (Frist): Fokus oder Server-Stand passt nicht') }
	await openItem('extend')
	await waitFor(s, '.pod')
	const pre6 = await s.script('return { end: document.querySelector(".pod .pseg-item.is-active").textContent.trim(), rel: (document.querySelector(".pod-rel") || { textContent: "" }).textContent.trim().replace(/— .*/, "— …") }')
	await s.script('document.querySelectorAll(".pod .pseg")[0].querySelectorAll(".pseg-item")[0].click()')
	await sleep(300)
	await s.script('document.querySelector(".pod-submit").click()')
	const until6 = Date.now() + 15000
	while (Date.now() < until6 && await s.script('return !!document.querySelector(".pod")')) { await sleep(200) }
	await sleep(300)
	const f6 = await s.script(FOCUS_TRIGGER)
	const w6 = ((await roomRow(s, code)) || {}).window || {}
	await waitForText(s, '.pace-state', /Open now/)
	note(`Ende ändern zurück (vorbelegt „${pre6.end}", „${pre6.rel}" -> „When I close it", „Save"): Fokus ${f6.onTrigger ? 'am Menü-Auslöser' : 'NICHT am Menü-Auslöser („' + f6.focus + '")'}; Server: state ${w6.state}, Frist ${w6.closesAt > 0 ? 'NOCH GESETZT' : '0'}`)
	if (f6.dialog || !f6.onTrigger || w6.state !== 'open' || w6.closesAt !== 0) { overflow.push('Ende ändern (zurück): Fokus oder Server-Stand passt nicht') }

	// 6. Close: "Close quiz" without a deadline -> confirmation (with the free-text sentence) -> "Stop without releasing".
	const before = await roomRow(s, code)
	await s.script('document.querySelector(".pace-primary").click()')
	await waitFor(s, '.pconfirm-alt')
	await shot(s, {
		name: 'pace-run-click-confirm', size: LAPTOP, settle: 400, measure: PACE_CONFIRM,
		note: 'Klickstrecke: „Close quiz" ohne Frist -> Bestätigung mit drittem Knopf „Stop without releasing" und Freitext-Satz',
	})
	await s.script(PACE_WATCH)
	await s.script('document.querySelector(".pconfirm-alt").click()')
	const tStop = await waitForText(s, '.pace-primary', /Check \d+ answers?|Release results/, 15000)
	const stopped = await roomRow(s, code)
	const w1 = (stopped && stopped.window) || {}
	const paceCalls = await s.script('return window.__pace')
	const prim7 = await s.script('return document.querySelector(".pace-primary").textContent.trim()')
	note(`Stoppen ohne Freigabe: vorher ${before && before.window ? before.window.state : '?'}, nach ${(tStop / 1000).toFixed(1)} s Hauptaktion „${prim7}"; GET /rooms: state ${w1.state}, releasedAt ${w1.releasedAt}, closedAt ${w1.closedAt > 0 ? 'gesetzt' : '0'}, Frist ${w1.closesAt > 0 ? 'GESETZT' : '0'}, ${paceCalls} /pace-Aufruf(e)`)
	if (w1.state !== 'closed' || w1.releasedAt !== 0 || w1.closesAt !== 0 || paceCalls !== 1) { overflow.push(`Stoppen ohne Freigabe: state ${w1.state}, releasedAt ${w1.releasedAt}, Frist ${w1.closesAt}, ${paceCalls} /pace-Aufrufe`) }
	if (!/^Check \d+ answers?$/.test(prim7)) { overflow.push(`Nach dem Stoppen mit offenen Freitexten: Hauptaktion „${prim7}" statt „Check %n answers"`) }
	await shot(s, {
		name: 'pace-run-click-check', size: LAPTOP, settle: 800, measure: PACE_RUN,
		note: 'Klickstrecke: gestoppt ohne Freigabe, offene Freitexte -> Hauptaktion „Check %n answers"',
	})
	previewMarks()
	await s.script('document.querySelector(".pace-primary").click()')
	await sleep(400)
	note('„Check %n answers" geklickt: ' + await s.script(FOCUS_GRADING))
	if (!/^Fokus auf/.test(await s.script(FOCUS_GRADING))) { overflow.push('„Check %n answers": Fokus nicht auf der Bewertung') }

	// 6b. "Reopen …" after stopping: deadline 0 -> "When I close it", without "Was: …", button "Reopen"; no flag. Escape.
	await openItem('reopen')
	await waitFor(s, '.pod')
	await sleep(300)
	const pre6b = await s.script('return { end: document.querySelector(".pod .pseg-item.is-active").textContent.trim(), was: !!document.querySelector(".pod-was"), submit: document.querySelector(".pod-submit").textContent.trim(), marker: window.localStorage.getItem("pulse-pace-stop:" + arguments[0]) }', [code])
	await s.script(escape)
	await sleep(300)
	note(`„Reopen …“ nach dem Stoppen: vorbelegt „${pre6b.end}“, ${pre6b.was ? 'MIT „Was: …“' : 'ohne „Was: …“'}, Knopf „${pre6b.submit}“; Merker ${pre6b.marker === null ? 'keiner' : 'GESCHRIEBEN'}`)
	if (pre6b.end !== 'When I close it' || pre6b.was || pre6b.submit !== 'Reopen' || pre6b.marker !== null) { overflow.push('„Reopen …“ nach dem Stoppen: Vorbelegung oder Merker passt nicht') }

	// 7. Release through the menu (the main action is grading).
	await openItem('release')
	await waitFor(s, '.pconfirm')
	await shot(s, {
		name: 'pace-run-click-release-confirm', size: LAPTOP, settle: 400, measure: PACE_CONFIRM,
		note: 'Klickstrecke: Menü „Release results" (geschlossen, offene Freitexte) -> Bestätigung mit Freitext-Satz',
	})
	await s.script('document.querySelector(".pconfirm .pulse-btn.is-danger").click()')
	const tRel = await waitForText(s, '.pace-status', /Results released/, 15000)
	const released = await roomRow(s, code)
	const w2 = (released && released.window) || {}
	await sleep(4000)
	const flight = await s.script(PROGRESS_OVERLAP)
	note(`Freigeben: nach ${(tRel / 1000).toFixed(1)} s Status „Results released on …"; GET /rooms: state ${w2.state}, releasedAt ${w2.releasedAt > 0 ? 'gesetzt' : '0'}. Einflug über die Klickstrecke: ${flight.n} /progress-Anfragen, ${flight.overlap} überlappend`)
	if (w2.state !== 'released') { overflow.push(`Freigeben: state ${w2.state}`) }
	if (flight.overlap) { overflow.push(`Einflug: ${flight.overlap} überlappende /progress-Anfragen (Klickstrecke)`) }
	await shot(s, {
		name: 'pace-run-click-released', size: LAPTOP, settle: 800, measure: PACE_RUN,
		note: 'Klickstrecke: freigegeben mit offenen Freitexten -> „Check %n answers", Warnung „Standings update for everyone."',
	})
	previewMarks()

	// 8. CSV in the browser (§6.2): the address as in src/util/csv.js csvUrl() (route +
	// view + requesttoken in the query) -> 200 + text/csv; without the token 412.
	const csv = await s.async(`
		const done = arguments[arguments.length - 1]
		const code = arguments[0]
		const tok = document.head.dataset.requesttoken
		const url = (view, withToken) => OC.generateUrl('/apps/pulse/api/1.0/rooms/' + code + '/export') + '?view=' + view
			+ (withToken ? '&requesttoken=' + encodeURIComponent(tok) : '')
		const get = (u) => fetch(u, { credentials: 'same-origin' }).then(async (r) => ({ status: r.status, type: r.headers.get('content-type') || '',
			disp: (r.headers.get('content-disposition') || '').replace(/[A-Z0-9]{6}/, '<CODE>'), head: (await r.text()).replace(/^\\uFEFF/, '').split('\\n')[0].slice(0, 70) }))
		Promise.all([get(url('players', true)), get(url('answers', true)), get(url('players', false)), get(url('answers', false))])
			.then(([p, a, p0, a0]) => done({ p, a, p0, a0 })).catch((e) => done({ error: String(e) }))
	`, [code])
	if (csv.error) { overflow.push('CSV per fetch: ' + csv.error) }
	for (const [label, r, r0] of csv.error ? [] : [['Teilnehmende', csv.p, csv.p0], ['Antworten', csv.a, csv.a0]]) {
		note(`CSV ${label} per fetch: ${r.status} ${r.type} (${r.disp}), Kopf „${r.head}"; ohne requesttoken: ${r0.status}`)
		if (r.status !== 200 || !/^text\/csv/.test(r.type) || r0.status !== 412) { overflow.push(`CSV ${label}: ${r.status} ${r.type}, ohne Token ${r0.status}`) }
	}
	// The real buttons (menu, downloadCsv): a file in the download folder, the page stays put.
	for (const [key, view] of [['csv-players', 'players'], ['csv-answers', 'answers']]) {
		await openItem(key)
		const file = await waitFile(dl, new RegExp(`-${view}\\.csv$`))
		const stay = await s.script('return !!document.querySelector(".pace-run") && /\\/room\\//.test(location.pathname)')
		const head = file ? readFileSync(join(dl, file), 'utf8').replace(/^﻿/, '').split('\n')[0].slice(0, 70) : ''
		note(`Knopf „${key}": ${file ? 'Datei ' + file.replace(/[A-Z0-9]{6}/, '<CODE>') + ', Kopf „' + head + '"' : 'KEINE DATEI'}, Seite ${stay ? 'bleibt stehen' : 'IST WEG'}`)
		if (!file || !stay) { overflow.push(`CSV-Knopf ${key}: ${file ? 'Datei da' : 'keine Datei'}, Seite ${stay ? 'da' : 'weg'}`) }
	}

	// Main thread: longest gap between 100 ms ticks over 8 s (one-second tick + polling).
	const lag = await s.async(`
		const done = arguments[arguments.length - 1]
		let last = performance.now()
		let worst = 0
		const iv = setInterval(() => { const t = performance.now(); worst = Math.max(worst, t - last - 100); last = t }, 100)
		setTimeout(() => { clearInterval(iv); done(Math.round(worst)) }, 8000)
	`)
	note(`Hauptfaden nach der Klickstrecke: längste Blockade ${lag} ms in 8 s`)
	if (lag > 200) { overflow.push(`Laufansicht blockiert den Hauptfaden ${lag} ms`) }
	await s.go('about:blank')
}

/* --------------------------------------------------- Self-paced: phone */

// Vue instance of the phone (Participant): for measuring (window state,
// progress, verdict) and for interventions the UI does not offer
// (the late correction, §6.2 step 1).
const PHONE_VM = `(() => {
	const r = document.querySelector('.pulse-part')
	const v = r && r.__vue__
	if (!v) { return null }
	return v.$options.name === 'Participant' ? v : (v.$children || []).find((c) => c.$options.name === 'Participant') || null
})()`

// Self-paced phone (§6.2): view, heading, hints, deadline, position,
// correction bar, verdict band, buttons (■ filled, ◦ tonal =
// is-tertiary, □ outline), submit zone and question in view, focus, announcement, toast,
// horizontal scrolling and the leak: before the release there is no solution in the DOM.
const PACE_PHONE = `
	const vm = ${PHONE_VM}
	if (!vm || !vm.isPaced) { return null }
	const root = vm.$el
	const txt = (el) => (el ? el.textContent.trim().replace(/\\s+/g, ' ') : '')
	const q = (sel) => root.querySelector(sel)
	const card = q('.pace-card')
	const view = card ? 'Karte ' + card.dataset.card : q('.review') ? 'Auswertung' : q('.nick') ? 'Namensbildschirm'
		: q('.pace-timeup') ? 'Zeitablauf' : q('.big-place, .h-app .end-title') ? 'Endstand' : q('.h-app .q') ? 'Frage' : '?'
	const p = vm.progress
	const parts = ['Viewport ' + window.innerWidth + '×' + window.innerHeight, view + ' (Fenster ' + (vm.paceState || '—') + (p ? ', Frage ' + p.k + '/' + p.n + (p.started ? '' : ', nicht gestartet') + (p.finished ? ', fertig' : '') : '') + ')']
	const h = q('.pace-card h1, .nick h1, .h-app .end-title, .review-h')
	if (h) { parts.push('„' + txt(h) + '"') }
	for (const el of root.querySelectorAll('.pace-notice')) { parts.push('Hinweis „' + txt(el) + '"') }
	const when = q('.pace-when')
	if (when) {
		const soon = when.querySelector('.pace-soon')
		parts.push('Frist „' + txt(when.querySelector('span')).replace(/until .*/, 'until …') + '"' + (soon ? ' + Chip „' + txt(soon) + '"' : ''))
	}
	const pos = q('.pace-pos')
	if (pos) {
		const soon = pos.querySelector('.pace-soon')
		parts.push('Position „' + txt(pos.querySelector('span')).replace(/until .*/, 'until …') + '"' + (soon ? ' + Chip „' + txt(soon) + '"' : ''))
	}
	const facts = q('.pace-facts')
	if (facts) { parts.push('Fakten „' + txt(facts) + '"') }
	const hints = root.querySelectorAll('.pace-hints li').length
	if (hints) { parts.push(hints + ' Hinweise') }
	const score = q('.pace-score, .big-place-of')
	if (score) { parts.push('Punkte „' + txt(score) + '"') }
	for (const el of root.querySelectorAll('.pace-line, .pace-device, .pace-momentum')) { parts.push('„' + txt(el) + '"') }
	if (q('.pace-practice')) { parts.push('Probelauf-Chip') }
	const fix = q('.fix-bar')
	if (fix) { parts.push('Korrekturleiste „' + txt(fix) + '"') }
	const band = q('.pace-verdict')
	if (band) { parts.push('Band „' + txt(band) + '" (data-verdict=' + band.dataset.verdict + ', ' + Array.from(band.classList).filter((c) => c.startsWith('is-')).join(' ') + ')') }
	// is-tertiary ist die tonale Stufe unter „primär" (grau hinterlegt) — kein zweiter Hauptknopf.
	const tonal = (b) => b.classList.contains('is-tertiary')
	const filled = (b) => { if (tonal(b)) { return false } const bg = getComputedStyle(b).backgroundColor; return bg && !/rgba\\(0, 0, 0, 0\\)|transparent/.test(bg) }
	const bs = Array.from(root.querySelectorAll('.pulse-btn, .submit')).filter((b) => b.offsetParent !== null)
	// Warum gefüllt? Ein Zeiger- oder Fokuszustand ist kein zweiter Hauptknopf — er steht dabei.
	const why = (b) => [b.matches(':hover') ? 'Hover' : '', b.matches(':focus') ? 'Fokus' : '', b.matches(':active') ? 'aktiv' : ''].filter(Boolean).join('+')
	if (bs.length) { parts.push('Knöpfe: ' + bs.map((b) => (tonal(b) ? '◦ ' : filled(b) ? '■ ' : '□ ') + txt(b) + (b.disabled ? ' (aus)' : '') + (filled(b) && why(b) ? ' (' + why(b) + ', ' + getComputedStyle(b).backgroundColor + ')' : '')).join(', ')) }
	const full = bs.filter(filled)
	if (full.length > 1) { parts.push(full.length + ' GEFÜLLTE KNÖPFE') }
	if (full.length) {
		const r = full[0].getBoundingClientRect()
		parts.push(r.bottom <= window.innerHeight + 1 ? 'Hauptknopf über der Falz' : 'HAUPTKNOPF UNTER DER FALZ (' + Math.round(r.bottom) + ' > ' + window.innerHeight + ')')
	}
	// Antwortansicht: die Absende-Zone ganz im Bild (höchstens zwei Zeilen), die Frage lesbar.
	const sub = q('.h-submit')
	if (sub && q('.h-app .q')) {
		const r = sub.getBoundingClientRect()
		const rows = Array.from(sub.children).filter((c) => c.offsetParent !== null).length
		parts.push((r.bottom <= window.innerHeight + 1 ? 'Absende-Zone über der Falz' : 'ABSENDE-ZONE UNTER DER FALZ (' + Math.round(r.bottom) + ' > ' + window.innerHeight + ')') + ', ' + rows + ' Zeile' + (rows === 1 ? '' : 'n'))
	}
	const hq = q('h1.q')
	if (hq) {
		const r = hq.getBoundingClientRect()
		parts.push(r.top >= 0 && r.bottom <= window.innerHeight + 1 ? 'Frage sichtbar' : 'FRAGE NICHT SICHTBAR')
	}
	const ae = document.activeElement
	if (ae && ae !== document.body) { parts.push('Fokus ' + ae.tagName.toLowerCase() + (typeof ae.className === 'string' && ae.className.trim() ? '.' + ae.className.trim().split(/\\s+/).join('.') : '')) }
	const sr = q('.pace-sr')
	if (sr && txt(sr)) { parts.push('Ansage „' + txt(sr) + '"') }
	for (const el of document.querySelectorAll('.pulse-toast')) { parts.push('Toast „' + txt(el) + '"') }
	const de = document.documentElement
	if (de.scrollWidth > de.clientWidth) { parts.push('SEITE SCROLLT WAAGERECHT ' + (de.scrollWidth - de.clientWidth) + ' px') }
	if (vm.paceState !== 'released' && document.querySelector('.pulse-results, .tg-accepted, .srow-mark')) { parts.push('LÖSUNG SICHTBAR') }
	return parts.join(', ')
`

// Record the history of the submit zone (correction bar → band without a verdict →
// verdict) without losing an intermediate state between two WebDriver queries.
// Read: window.__paceVerdicts.
const VERDICT_LOG = `
	const rec = () => {
		const b = document.querySelector('.pace-verdict')
		const v = b ? b.dataset.verdict : document.querySelector('.fix-bar') ? 'Korrekturleiste' : '—'
		const log = window.__paceVerdicts
		if (log[log.length - 1] !== v) { log.push(v) }
	}
	window.__paceVerdicts = []
	if (window.__paceObs) { window.__paceObs.disconnect() }
	window.__paceObs = new MutationObserver(rec)
	window.__paceObs.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['data-verdict'] })
	rec()
	return true
`

/*
 * Double tap (§2.5, §6.2 step 1): tap "Next question", immediately tap the
 * same spot again, and a third time as soon as the new question is up; each
 * time on whatever element lies there at that moment (elementFromPoint).
 * Result: what was hit and whether the new question stayed unanswered.
 */
const DOUBLE_TAP = `
	const done = arguments[arguments.length - 1]
	const vm = ${PHONE_VM}
	const btn = document.querySelector('.pace-next')
	if (!vm || !btn || btn.disabled) { done({ timeout: true, why: 'kein aktiver Weiter-Knopf' }); return }
	const r = btn.getBoundingClientRect()
	const x = r.left + r.width / 2
	const y = r.top + r.height / 2
	const hit = (el) => (el ? el.tagName.toLowerCase() + (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\\s+/).join('.') : '') + (el.disabled ? ' (aus)' : '') : 'nichts')
	const tap = () => { const el = document.elementFromPoint(x, y); const what = hit(el); if (el) { el.click() } return what }
	const k0 = vm.progress.k
	tap()
	const h2 = tap()
	const t0 = performance.now()
	const wait = () => {
		const hq = document.querySelector('h1.q')
		if (vm.progress && vm.progress.k === k0 + 1 && vm.poll && !vm.nextBusy && hq && hq.textContent.trim() === vm.poll.question.trim()) {
			const h3 = tap()
			const skip = document.querySelector('.pace-skip')
			const skipDisabled = skip ? skip.disabled : null
			const skipText = skip ? skip.textContent.trim() : null
			setTimeout(() => done({ h2, h3, ms: Math.round(performance.now() - t0), k: vm.progress.k, voted: vm.voted, busy: vm.busy, skipDisabled, skipText }), 400)
			return
		}
		if (performance.now() - t0 > 10000) { done({ timeout: true, why: 'Frage ' + (k0 + 1) + ' kam nicht' }); return }
		setTimeout(wait, 20)
	}
	wait()
`

// Time-up (§2.6, §6.2 step 2): as soon as branch 9 is in the DOM, read the
// button IMMEDIATELY: disabled with "One moment …" until the server reports timeUp.
const TIMEUP_WATCH = `
	const done = arguments[arguments.length - 1]
	const t0 = performance.now()
	const look = () => {
		const b = document.querySelector('.pace-timeup .pace-next')
		if (b) { done({ ms: Math.round(performance.now() - t0), disabled: b.disabled, text: b.textContent.trim() }); return }
		if (performance.now() - t0 > 20000) { done(null); return }
		setTimeout(look, 10)
	}
	look()
`

/*
 * Double tap at time-up (gate 4.4b): "Next question" sits centred in branch 9;
 * exactly there an answer card of the next question lies afterwards (True/False: B),
 * and choice/true-false send immediately in a quiz. Tap, tap again immediately,
 * then on the new question right after the switch and 250 ms later (the usual
 * double-tap span), each time like a finger on the button at that spot
 * (elementFromPoint → closest('button') → click(); a locked button does not
 * accept a click). Plus, once, the route without the DOM lock (vm.pickOne, like
 * the keyboard) while the lock is still in place. Then wait until the card at
 * that spot is usable again. Expected: question 2 unanswered, no /vote in flight.
 */
const TIMEUP_DOUBLE_TAP = `
	const done = arguments[arguments.length - 1]
	const vm = ${PHONE_VM}
	const btn = document.querySelector('.pace-timeup .pace-next')
	if (!vm || !btn || btn.disabled) { done({ timeout: true, why: 'kein aktiver Weiter-Knopf im Zeitablauf' }); return }
	const r = btn.getBoundingClientRect()
	const x = r.left + r.width / 2
	const y = r.top + r.height / 2
	const at = () => { const el = document.elementFromPoint(x, y); return el ? (el.closest('button') || el) : null }
	const hit = (el) => (el ? el.tagName.toLowerCase() + (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\\s+/).join('.') : '') + (el.disabled ? ' (aus)' : '') : 'nichts')
	const tap = () => { const el = at(); const what = hit(el); if (el) { el.click() } return what }
	// Die Sperre soll nicht blinken: gesperrt und scharf sieht die Karte gleich aus.
	const look = (el) => { if (!el) { return '' } const c = getComputedStyle(el); return [c.backgroundColor, c.color, c.borderColor, c.opacity, c.filter, c.boxShadow].join(' / ') }
	const k0 = vm.progress.k
	tap()
	const h2 = tap()
	const t0 = performance.now()
	const wait = () => {
		const hq = document.querySelector('.h-app h1.q')
		if (vm.progress && vm.progress.k === k0 + 1 && vm.poll && !vm.nextBusy && hq && hq.textContent.trim() === vm.poll.question.trim()) {
			const tq = performance.now()
			const lookHeld = look(at())
			const h3 = tap()
			setTimeout(() => {
				const h4 = tap()
				const held = !vm.answerArmed
				if (held && vm.optList && vm.optList.length) { vm.pickOne(vm.optList[0].id, null) }
				const armed = () => {
					const el = at()
					const card = el && el.classList && el.classList.contains('choice') ? el : null
					const late = performance.now() - tq > 5000
					if ((card && !card.disabled) || late) {
						const lookArmed = look(card)
						done({ h2, h3, h4, held, ms: Math.round(tq - t0), armedMs: late ? null : Math.round(performance.now() - tq), k: vm.progress.k, voted: vm.voted, busy: vm.busy, sameLook: lookHeld === lookArmed, lookHeld, lookArmed })
						return
					}
					setTimeout(armed, 20)
				}
				armed()
			}, 250)
			return
		}
		if (performance.now() - t0 > 10000) { done({ timeout: true, why: 'Frage ' + (k0 + 1) + ' kam nicht' }); return }
		setTimeout(wait, 10)
	}
	wait()
`

// Leak check on the wire: the phone's /state before the release has no solution fields,
// no distribution, no leaderboard (§2.5 "Never the solution").
const STATE_LEAK = `
	const done = arguments[arguments.length - 1]
	fetch(location.pathname.replace(/\\/$/, '') + '/state', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
		.then((r) => r.json())
		.then((d) => done({ keys: d.poll ? Object.keys(d.poll).filter((k) => k === 'correctOption' || k === 'answerKey') : [],
			results: d.results, leaderboard: d.leaderboard, verdict: d.myResult ? d.myResult.verdict : null }))
		.catch((e) => done({ error: String(e) }))
`

/** Intervals between the phone's /state fetches over ms milliseconds (cadence §2.10). */
async function stateCadence(s, ms) {
	return s.async(`
		const done = arguments[arguments.length - 1]
		const t0 = performance.now()
		setTimeout(() => {
			const xs = performance.getEntriesByType('resource')
				.filter((e) => /\\/s\\/[A-Z0-9]{6}\\/state(\\?|$)/.test(e.name) && e.startTime >= t0)
				.map((e) => Math.round(e.startTime))
			done({ n: xs.length, gaps: xs.slice(1).map((x, i) => x - xs[i]) })
		}, arguments[0])
	`, [ms])
}

/*
 * Phone at its real size. Headless Firefox does not make a window narrower
 * than 450 CSS px (measured: 320 or 390 requested -> innerWidth 450;
 * devPixelsPerPx does not change that), so the older phone passes actually
 * run 450 px wide. Here the page sits in an iframe of the target size: same
 * origin, the same cookie, media queries and svh at 390 or 320 px. The host
 * page is the join page without a code (it does not poll). After that,
 * scripts, clicks and wait conditions run INSIDE the iframe; a navigation
 * (s.go) leaves it.
 */
const frameWin = ([w, h]) => Object.assign([450, h + 90], { css: w + '×' + h })
async function phoneAt(s, path, [w, h]) {
	await s.size(frameWin([w, h]))
	await s.go(HOST + '/apps/pulse/join')
	await waitFor(s, '.pulse-part')
	await s.script(`
		const back = document.createElement('div')
		back.style.cssText = 'position:fixed;inset:0;z-index:2147483646;background:#8a8f98'
		const f = document.createElement('iframe')
		f.id = 'shots-phone'
		f.src = arguments[0]
		f.style.cssText = 'position:fixed;left:0;top:0;border:0;z-index:2147483647;width:' + arguments[1] + 'px;height:' + arguments[2] + 'px'
		document.body.append(back, f)
	`, [HOST + path, w, h])
	await s.frame(await s.find('#shots-phone'))
	await waitFor(s, '.pulse-part')
}

/*
 * Self-paced, phone (specification §5.2 steps 4.4a + 4.4b, §6.2):
 * name screen with deadline and "Closes in", start (also 320 × 640, real
 * size in the iframe), the full run on `e2e` (question → correction bar →
 * verdict → double tap → "Change", wait 4 s, late correction → reload →
 * continue card → "Being checked" → "I’m done" → own answers → closed
 * → final standings → all results), time-up on `timed` (disabled →
 * active, double tap on True/False question 2), "Answer saved" and
 * skipping to the end on `homework`, matching with eight pairs at
 * 320 × 640 on `match8`, waiting and a new round on `draft`, closed with
 * lost input on `late`, removed on `race`, over on `practice`,
 * plus polling cadence, focus and leak check.
 * One session = one cookie = one phone; where a second phone is needed, the
 * pass takes the cookie away briefly.
 *
 * Rooms: e2e is closed (light), raw via `window closedAt=now`, because
 * `close` without a deadline would release immediately, and then released;
 * late likewise; draft reset at the end (about:blank first, §6.4); practice
 * briefly released and reopened via `window` (pace-screen releases it
 * itself). race, homework, timed and match8 only get additional names. The
 * dark pass runs first and changes nothing except names.
 */
async function pacePhonePass(s, dark) {
	const P = paceData
	const tag = dark ? 'dark-' : ''
	const url = (code) => `/apps/pulse/s/${code}`
	const card = (name) => `.pace-card[data-card="${name}"]`
	const note = (text) => index.push(`| (Messung${dark ? ', dunkel' : ''}) | | ${url('<CODE>')} | ${text} |`)
	// A check that fails goes into the index AND into the anomalies.
	const check = (ok, text) => {
		note((ok ? 'ok: ' : '**FEHLER:** ') + text)
		if (!ok) { overflow.push(`pace-phone${dark ? ' (dunkel)' : ''}: ${text}`) }
		return ok
	}
	const vm = (expr) => s.script(`const vm = ${PHONE_VM}; return vm ? (${expr}) : null`)
	const phone = async (opts) => {
		const at = opts.size || PHONE
		const open = opts.url ? () => phoneAt(s, opts.url, at) : undefined
		await s.mouseAway()
		await shot(s, { settle: 800, measure: PACE_PHONE, ...opts, size: frameWin(at), open, name: tag + opts.name })
		const line = index[index.length - 1]
		for (const mark of ['UNTER DER FALZ', 'WAAGERECHT', 'LÖSUNG SICHTBAR', 'GEFÜLLTE KNÖPFE', 'FRAGE NICHT SICHTBAR']) {
			if (line.includes(mark)) { overflow.push(line.split(' | ')[0].slice(2) + ': ' + mark) }
		}
		return line
	}
	const join = async (code, name, size = PHONE) => {
		await phoneAt(s, url(code), size)
		await waitFor(s, '.nick-input')
		await s.type('.nick-input', name)
		await s.click('.nick .submit')
	}
	const until = async (expr, what, timeout = 15000) => {
		const t0 = Date.now()
		while (Date.now() - t0 < timeout) {
			if (await vm(expr)) { return Date.now() - t0 }
			await sleep(200)
		}
		throw new Error('Handy: ' + what + ' blieb aus')
	}
	const secs = (ms) => (ms / 1000).toFixed(1) + ' s'
	const verdictLog = async () => (await s.script('return (window.__paceVerdicts || []).join(" → ")')) || '—'
	const focusOn = (sel) => s.script('return !!document.activeElement && document.activeElement.matches(arguments[0])', [sel])
	const verdictSel = '.pace-verdict[data-verdict="correct"], .pace-verdict[data-verdict="wrong"]'
	const has = (sel) => `!!document.querySelector(${JSON.stringify(sel)})`
	// Question k is open: wait until "Skip question" is armed, then tap.
	const skipTo = async (k) => {
		await until(`vm.progress && vm.progress.k === ${k} && !!vm.poll && !vm.nextBusy`, 'Frage ' + k)
		await waitFor(s, '.pace-skip:not([disabled])', 5000)
		await s.click('.pace-skip')
	}

	// ── Name screen and start ─────────────────────────────────────────────
	// Deadline in ten minutes: name screen and start card show "Closes in".
	probe('window', P.late.code, 'closesAt=now+600')
	await phone({
		name: 'pace-phone-name-deadline', url: url(P.late.code), waitSel: '.nick-input',
		note: 'Handy Namensbildschirm, offen mit Frist in 10 min: Frist + „Closes in", Knopf „Next", Hinweis Gerätewechsel',
	})
	if (!dark) {
		await phone({
			name: 'pace-phone-name-deadline-320', url: url(P.late.code), size: [320, 640], waitSel: '.nick-input',
			note: 'dasselbe bei 320 × 640',
		})
	}
	await join(P.late.code, dark ? 'Ida' : 'Rika')
	await waitFor(s, card('start'))
	await sleep(300)
	check(await focusOn('.pace-card h1'), 'Beitritt → Fokus auf der Überschrift der Startkarte (§2.11)')
	await phone({
		name: 'pace-phone-start-deadline', waitSel: card('start'),
		note: 'Startkarte mit Frist: eine Frage, ohne Timer, Auflösung am Ende, „Closes in"',
	})

	// Race (timer, feedback per question): start card with the timer hint, narrow as well.
	const racer = dark ? 'Noa' : 'Mo'
	await join(P.race.code, racer)
	await waitFor(s, card('start'))
	await phone({
		name: 'pace-phone-start', waitSel: card('start'),
		note: 'Startkarte Rennen: Fakten, drei Hinweise (Timer läuft weiter), „Start quiz"',
	})
	await phone({
		name: 'pace-phone-start-320', url: url(P.race.code), size: [320, 640], waitSel: card('start'),
		note: 'Startkarte Rennen bei 320 × 640',
	})
	if (!dark) {
		const cad = await stateCadence(s, 11000)
		note('Takt auf der Startkarte: ' + cad.n + ' Abrufe in 11 s, Abstände ' + (cad.gaps.join(' / ') || '—') + ' ms (Soll 5000)')
		// Removed, then reloaded: the remembered name (localStorage) carries the hint.
		const stored = await s.script('return window.localStorage.getItem("pulse-pace:" + arguments[0])', [P.race.code])
		probe('remove', P.race.code, racer)
		await phone({
			name: 'pace-phone-removed', url: url(P.race.code), waitSel: '.nick .pace-notice',
			note: 'Entfernt, dann neu geladen: Namensbildschirm mit „You were removed from this quiz."',
		})
		note('Gemerkter Name vor dem Entfernen: ' + (stored ? stored.replace(/"openedAt":\d+/, '"openedAt":…') : 'FEHLT'))
	}

	// ── Full run on e2e (no timer, feedback per question) ─────────────────
	const sam = dark ? 'Lio' : 'Sam'
	await join(P.e2e.code, sam)
	await waitFor(s, card('start'))
	if (!dark) {
		await phone({ name: 'pace-phone-e2e-start', waitSel: card('start'), note: 'Startkarte e2e: drei Fragen, ohne Timer, Rückmeldung je Frage' })
	}
	await s.click(card('start') + ' .submit')
	await waitFor(s, '.h-app .choices .choice')
	await sleep(300)
	check(await focusOn('h1.q'), '„Start quiz" → Fokus auf h1.q der ersten Frage (§2.11)')
	await phone({
		name: 'pace-phone-q1', waitSel: '.h-app .choices',
		note: 'Frage 1 (Auswahl, ohne Timer): „Question 1 of 3", Tipp-Hinweis, „Skip question" unter den Antworten (1,5 s gesperrt)',
	})
	// Light: correct (443), dark: wrong (80).
	await s.script(VERDICT_LOG)
	const tapped = Date.now()
	await s.click(`.choices .choice:nth-child(${dark ? 1 : 2})`)
	await waitFor(s, '.fix-bar', 5000)
	if (!dark) {
		await phone({ name: 'pace-phone-fix', waitSel: '.fix-bar', settle: 150, note: 'Direkt nach dem Tipp: Korrekturleiste „Answer sent" + „Change" (3 s)' })
	}
	await until(has(verdictSel), 'Urteil Frage 1')
	const tV = Date.now() - tapped
	const log1 = await verdictLog()
	await phone({
		name: dark ? 'pace-phone-verdict-wrong' : 'pace-phone-verdict', waitSel: '.pace-verdict',
		note: dark ? 'Urteil falsch: Band „Not this time" (is-no), „Next question" aktiv' : 'Urteil richtig: Band „Correct!" + Punkte-Chip, „Next question" aktiv',
	})
	// "none" is only missing when the verdict arrives before the local correction bar ends.
	check(/Korrekturleiste( → none)? → (correct|wrong)$/.test(log1), 'Frage 1: Urteil ' + secs(tV) + ' nach dem Tipp, Verlauf ' + log1 + ' (vor dem Urteil neutral, kein grüner Blitz)')

	if (!dark) {
		// Double tap: "Next question" → immediately again at the same spot → question 2 stays unanswered.
		const dt = await s.async(DOUBLE_TAP)
		check(!!dt && !dt.timeout && dt.k === 2 && dt.voted === false && dt.skipDisabled === true,
			'Doppeltipp „Next question": ' + (dt && dt.timeout ? dt.why : 'Frage ' + dt.k + ' unbeantwortet=' + !dt.voted + '; 2. Tipp auf ' + dt.h2 + ', 3. Tipp nach dem Wechsel (' + dt.ms + ' ms) auf ' + dt.h3 + '; „' + dt.skipText + '" ' + (dt.skipDisabled ? 'noch gesperrt' : 'SCHON SCHARF')))
		const tArm = await until(`${has('.pace-skip')} && !document.querySelector('.pace-skip').disabled`, '„Skip question" scharf', 5000)
		note('„Skip question" scharf ' + secs(tArm) + ' nach der Doppeltipp-Prüfung (gesperrt 1,5 s ab Fragewechsel)')
		await phone({
			name: 'pace-phone-q2', waitSel: '.h-app input[type="number"]',
			note: 'Frage 2 (Zahl) unbeantwortet: „Submit" in der Absende-Zone, „Skip question" (tonal) im Scrollbereich',
		})

		// Submit a number → "Change" → wait 4 s → tap (§6.2 step 1, rules a–c from §2.1).
		await s.script(VERDICT_LOG)
		await s.type('.h-app input[type="number"]', '9')
		await s.click('.h-submit .submit-btn')
		await waitFor(s, '.fix-bar', 5000)
		await s.click('.fix-bar .fix-btn')
		const inChange = await vm(`vm.changing && ${has('.h-submit .submit-btn')} && !document.querySelector('.h-app input[type="number"]').disabled`)
		await sleep(4000)
		const after4 = await vm(`({ changing: vm.changing, locked: document.querySelector('.h-app input[type="number"]').disabled, band: ${has('.pace-verdict')}, value: vm.numInput })`)
		check(inChange && !after4.changing && after4.locked && after4.band && after4.value === '9',
			'„Change" getippt, 4 s gewartet: Korrekturmodus von selbst beendet (Regel a, lokales Fenster um), Eingabe wieder „9" und gesperrt, Band statt „Submit" — ' + JSON.stringify(after4))
		// Rule (c): a correction that only arrives after the server window (slow
		// network). The UI no longer offers "Change" after the window, so this
		// goes through the Vue instance, the same way as the button.
		await s.script(`const vm = ${PHONE_VM}; vm.startChange(); vm.numInput = '8'; vm.doSubmit(); return true`)
		await until(`${has('.pulse-toast')} && !vm.busy`, 'Toast nach der späten Korrektur', 8000)
		const late = await vm(`({ changing: vm.changing, value: vm.numInput, toast: document.querySelector('.pulse-toast').textContent.trim() })`)
		const tAlive = await until(`${has('.pace-verdict:not([data-verdict="none"])')} && ${has('.pace-next:not([disabled])')}`, 'Band mit Urteil und aktivem Weiter', 15000)
		await phone({
			name: 'pace-phone-change-late', waitSel: '.pace-verdict', settle: 200,
			note: 'Späte Korrektur abgelehnt (Regel c): Toast, Korrekturmodus aus, Band mit Urteil, „Next question" aktiv — kein toter Bildschirm',
		})
		check(!late.changing && late.value === '9' && /already answered/i.test(late.toast),
			'Späte Korrektur: Toast „' + late.toast + '", changing=' + late.changing + ', Eingabe „' + late.value + '"; Urteil + aktives Weiter ' + secs(tAlive) + ' danach; Verlauf ' + await verdictLog())

		// Reload after answering question 2: input and band are back, no "Change".
		await phoneAt(s, url(P.e2e.code), PHONE)
		await waitFor(s, '.pace-verdict')
		const re = await vm(`({ value: document.querySelector('.h-app input[type="number"]').value, verdict: document.querySelector('.pace-verdict').dataset.verdict, change: ${has('.fix-bar')}, next: !document.querySelector('.pace-next').disabled })`)
		await phone({ name: 'pace-phone-reload', waitSel: '.pace-verdict', note: 'Frage 2 nach dem Neuladen: Eingabe „9" und Band wieder da, kein „Change"' })
		check(re.value === '9' && re.verdict !== 'none' && !re.change && re.next, 'Neuladen auf Frage 2: ' + JSON.stringify(re))
		const leak = await s.async(STATE_LEAK)
		check(!!leak && !leak.error && leak.keys.length === 0 && leak.results === null && leak.leaderboard === null,
			'Leck-Prüfung /state auf Frage 2 (vor der Freigabe): ' + JSON.stringify(leak))

		// Aborted /next (open row closed, next one not started): the continue card heals it.
		probe('leave', P.e2e.code, sam)
		const tCont = await until('vm.paceCard === "continue"', 'Weiter-Karte', 12000)
		await phone({
			name: 'pace-phone-continue', waitSel: card('continue'),
			note: 'Weiter-Karte: gestartet, keine offene Frage (abgebrochenes /next) — „Your quiz continues." + „Next"',
		})
		note('Weiter-Karte ' + secs(tCont) + ' nach „probe leave"')
		await s.click(card('continue') + ' .submit')
		await waitFor(s, '.h-app .free-vote input[type="text"]')
		note('„Next" auf der Weiter-Karte heilt: Frage ' + (await vm('vm.progress.k + "/" + vm.progress.n')) + ' offen, Punkte ' + (await vm('vm.myScore')))
		const cad = await stateCadence(s, 9000)
		note('Takt auf einer unbeantworteten Frage ohne Timer: ' + cad.n + ' Abrufe in 9 s, Abstände ' + (cad.gaps.join(' / ') || '—') + ' ms (Soll 4000)')
	} else {
		// Dark: continue, skip question 2 (armed only after 1.5 s).
		await s.click('.pace-next')
		await skipTo(2)
		await waitFor(s, '.h-app .free-vote input[type="text"]')
	}

	// Question 3: free text, neither accepted nor rejected -> "Being checked".
	await s.script(VERDICT_LOG)
	await s.type('.h-app .free-vote input[type="text"]', 'X-Frame')
	await waitFor(s, '.h-submit .submit-btn:not([disabled])', 5000) // answer lock after the question switch
	await s.click('.h-submit .submit-btn')
	const tP = await until(has('.pace-verdict[data-verdict="pending"]'), '„Being checked"', 15000)
	const lastLabel = await s.script('return document.querySelector(".pace-next").textContent.trim()')
	await phone({
		name: 'pace-phone-pending', waitSel: '.pace-verdict',
		note: 'Freitext ohne Bewertung: Band „Being checked" (data-verdict=pending), letzte Frage → „I’m done"',
	})
	const solution = await s.script('return document.body.innerText.includes("X-Frame-Options")')
	check(!solution && lastLabel === 'I’m done', 'Freitext: „Being checked" ' + secs(tP) + ' nach dem Absenden, Verlauf ' + await verdictLog() + '; Knopf „' + lastLabel + '"; Lösungstext im DOM: ' + (solution ? 'JA' : 'nein'))
	await s.click('.pace-next')
	await waitFor(s, card('through'))
	await sleep(300)
	check(await focusOn('.pace-card h1'), '„I’m done" → Fokus auf „You’re through!" (§2.11)')
	await phone({
		name: 'pace-phone-through', waitSel: card('through'),
		note: 'Durch: Punkte, „The final standings follow when the quiz ends." (ohne Frist), „See your answers"',
	})

	if (!dark) {
		// Own answers before the release (§2.8).
		await s.click(card('through') + ' .review-cta')
		await waitFor(s, '.review')
		await phone({
			name: 'pace-phone-review-open', waitSel: '.review',
			note: '„See your answers" vor der Freigabe: Titel „Your answers", Punktzeile, Chips je Antwort, überall „Not revealed yet."',
		})
		const rv = await s.script(`return { title: document.querySelector('.review-h').textContent.trim(),
			chips: Array.from(document.querySelectorAll('.review-chip')).map((c) => c.textContent.trim()),
			hidden: document.querySelectorAll('.review-hidden').length, results: document.querySelectorAll('.pulse-results').length,
			score: (document.querySelector('.review-myrank') || {}).textContent }`)
		check(rv.title === 'Your answers' && rv.results === 0 && rv.chips.includes('Being checked') && !!rv.score,
			'Auswertung vor der Freigabe: ' + JSON.stringify(rv))
		await s.click('.review-head .pulse-btn')
		await waitFor(s, card('through'))

		// Closed, not released yet: raw, because `close` without a deadline would release immediately.
		probe('window', P.e2e.code, 'closedAt=now')
		const tClosed = await until('vm.paceCard === "closed"', 'Geschlossen-Karte (e2e)', 15000)
		await phone({
			name: 'pace-phone-closed', waitSel: card('closed'),
			note: 'Geschlossen (nicht freigegeben), alles beantwortet: Punkte, „Results come once …", „See your answers"',
		})
		probe('release', P.e2e.code)
		const tFinal = await until('vm.paceFinal', 'Endstand (e2e)', 15000)
		await waitFor(s, '.big-place')
		await phone({ name: 'pace-phone-final', waitSel: '.big-place', note: 'Freigegeben: Endstand mit eigenem Platz' })
		note('e2e: Geschlossen-Karte ' + secs(tClosed) + ' nach „window closedAt=now", Endstand ' + secs(tFinal) + ' nach „release"')
		await s.click('.h-app .h-submit .pulse-btn')
		await waitFor(s, '.review')
		await phone({ name: 'pace-phone-final-review', waitSel: '.review', note: '„See all results" nach der Freigabe: „All results", jetzt mit Lösungen und Verteilung' })
		const fr = await s.script('return { title: document.querySelector(".review-h").textContent.trim(), results: document.querySelectorAll(".pulse-results").length }')
		check(fr.title === 'All results' && fr.results > 0, 'Auswertung nach der Freigabe: ' + JSON.stringify(fr))
	}

	// ── Time-up on timed (two questions of 5 s each, timer) ──────────────
	await join(P.timed.code, dark ? 'Wyn' : 'Vic')
	await waitFor(s, card('start'))
	await s.click(card('start') + ' .submit')
	await waitFor(s, '.h-app .choices .choice')
	const tu = await s.async(TIMEUP_WATCH)
	if (!dark) {
		const line = await phone({ name: 'pace-phone-timeup-wait', waitSel: '.pace-timeup', settle: 0, note: 'Zeit lokal abgelaufen, Server noch nicht: „One moment …" (deaktiviert)' })
		note('Aufnahme „timeup-wait" zeigt den Knopf ' + (line.includes('One moment … (aus)') ? 'deaktiviert (gewollt)' : 'NICHT MEHR deaktiviert — Zeitfenster verpasst, maßgeblich ist die DOM-Prüfung'))
	}
	const tReady = await until(has('.pace-timeup .pace-next:not([disabled])'), '„Next question" nach Zeitablauf', 10000)
	await phone({ name: 'pace-phone-timeup-ready', waitSel: '.pace-timeup', note: 'Server meldet timeUp: „Next question" aktiv' })
	check(!!tu && tu.disabled && /One moment/.test(tu.text), 'Zweig 9 erscheint deaktiviert („' + (tu ? tu.text : '—') + '"), aktiv ' + secs(tReady) + ' danach')
	if (dark) {
		await s.click('.pace-timeup .pace-next')
	} else {
		// Double tap at time-up: the second tap lands on card B of True/False question 2;
		// the answer lock (paceHold, 0.6 s from the question switch) has to swallow it.
		const td = await s.async(TIMEUP_DOUBLE_TAP)
		const onCard = (h) => /^button\.choice\b/.test(h || '')
		check(!!td && !td.timeout && td.k === 2 && td.voted === false && !td.busy && onCard(td.h3) && onCard(td.h4) && td.held && td.armedMs !== null && td.sameLook,
			'Doppeltipp „Next question" am Zeitablauf: ' + (td && td.timeout ? td.why : 'Frage ' + td.k + ' unbeantwortet=' + !td.voted + (td.busy ? ' (/vote UNTERWEGS)' : '') + '; 2. Tipp auf ' + td.h2 + '; nach dem Wechsel (' + td.ms + ' ms) auf ' + td.h3 + ', 250 ms später auf ' + td.h4 + '; vm.pickOne ' + (td.held ? 'während der Sperre ignoriert' : 'NICHT GEPRÜFT (Sperre schon vorbei)') + '; Karte wieder bedienbar ' + (td.armedMs === null ? 'NIE (5 s)' : td.armedMs + ' ms nach dem Wechsel') + '; ' + (td.sameLook ? 'gesperrt und scharf gleich aussehend' : 'SIEHT GESPERRT ANDERS AUS (' + td.lookHeld + ' ≠ ' + td.lookArmed + ')')))
	}
	await until('vm.progress.k === 2 && !!vm.poll', 'Frage 2 (timed)')
	if (!dark) {
		await waitFor(s, '.h-app .choices .choice:not([disabled])')
		await s.click('.choices .choice:nth-child(2)')
		await until(has(verdictSel), 'Urteil (timed)')
		await phone({ name: 'pace-phone-timed-verdict', waitSel: '.pace-verdict', note: 'Frage mit Timer beantwortet: Urteil mit Punkten nach Tempo, „I’m done"' })
		await s.click('.pace-next')
		await waitFor(s, card('through'))
	}

	if (!dark) {
		// ── Homework (deadline, no timer, reveal at the end) ──────────────
		await join(P.homework.code, 'Hana')
		await waitFor(s, card('start'))
		await s.click(card('start') + ' .submit')
		await waitFor(s, '.h-app .choices .choice')
		await phone({
			name: 'pace-phone-homework-q1', waitSel: '.pace-pos',
			note: 'Hausaufgabe: Positionszeile mit Frist („Question 1 of 6 · Open until …"), ohne Timer',
		})
		await s.script(VERDICT_LOG)
		await s.click('.choices .choice:nth-child(2)')
		await until(has('.pace-verdict'), 'Band (Hausaufgabe)', 8000)
		if (await s.script('return !!document.querySelector(\'.pace-verdict[data-verdict="none"]\')')) {
			await phone({
				name: 'pace-phone-saved-none', waitSel: '.pace-verdict', settle: 100,
				note: '„Am Ende", noch nicht endgültig: grau „Answer saved" (data-verdict=none), „Next question" gesperrt',
			})
		} else {
			note('Hausaufgabe: Band ohne Urteil (none) nicht erwischt — das Urteil kam vor dem Ende der Korrekturleiste')
		}
		await until(has('.pace-verdict[data-verdict="saved"]'), '„saved" (Hausaufgabe)', 15000)
		await phone({
			name: 'pace-phone-saved', waitSel: '.pace-verdict',
			note: 'Endgültig: „Answer saved" (data-verdict=saved), „Next question" aktiv — kein Richtig/Falsch, keine Punkte',
		})
		const log = await verdictLog()
		const leak = await s.async(STATE_LEAK)
		check(/Korrekturleiste( → none)? → saved$/.test(log) && !!leak && leak.keys.length === 0 && leak.verdict === 'saved' && leak.results === null,
			'Hausaufgabe: Verlauf ' + log + '; /state ' + JSON.stringify(leak))
		await s.click('.pace-next')
		for (const k of [2, 3, 4, 5]) { await skipTo(k) }
		await until('vm.progress.k === 6 && !!vm.poll && !vm.nextBusy', 'Frage 6 (Hausaufgabe)')
		await waitFor(s, '.pace-skip:not([disabled])', 5000)
		await phone({ name: 'pace-phone-skip-last', waitSel: '.pace-skip', note: 'Letzte Frage ohne Antwort: „Skip and finish" (tonal, unter den Antworten)' })
		await s.click('.pace-skip')
		await waitFor(s, card('through'))
		await s.click(card('through') + ' .review-cta')
		await waitFor(s, '.review')
		await phone({
			name: 'pace-phone-review-saved', waitSel: '.review',
			note: 'Hausaufgabe, „See your answers": Frage 1 „Answer saved", übersprungene „Not answered", keine Punkte',
		})
		const hr = await s.script(`return { title: document.querySelector('.review-h').textContent.trim(),
			chips: Array.from(document.querySelectorAll('.review-chip')).map((c) => c.textContent.trim()),
			none: document.querySelectorAll('.review-mine.is-none').length, score: !!document.querySelector('.review-myrank') }`)
		check(hr.title === 'Your answers' && hr.chips.join() === 'Answer saved' && hr.none === 5 && !hr.score,
			'Hausaufgabe-Auswertung: ' + JSON.stringify(hr))
		await s.click('.review-head .pulse-btn')

		// ── Matching with eight pairs at 320 × 640 (match8) ──────────────
		await join(P.match8.code, 'Max', [320, 640])
		await waitFor(s, card('start'))
		await s.click(card('start') + ' .submit')
		await waitFor(s, '.mrow-list .mrow')
		await phone({
			name: 'pace-phone-match8-320', size: [320, 640], waitSel: '.mrow-list',
			note: 'Zuordnung mit acht Paaren bei 320 × 640, unbeantwortet: Absende-Zone über der Falz, Frage sichtbar',
		})
		// Assign all eight, through the same methods as the selection sheet.
		await s.script(`const vm = ${PHONE_VM}; vm.matchItems.forEach((it, i) => { vm.openSheet(it.id); vm.chooseTarget(vm.matchTargets[i].id) }); return true`)
		await phone({
			name: 'pace-phone-match8-assigned-320', size: [320, 640], waitSel: '.h-submit .submit-btn:not([disabled])',
			note: 'alle acht zugeordnet, noch nicht abgesendet: „8 of 8 assigned" + „Submit"',
		})
		await s.click('.h-submit .submit-btn')
		await until(has(verdictSel), 'Urteil (match8)')
		await phone({
			name: 'pace-phone-match8-sent-320', size: [320, 640], waitSel: '.pace-verdict',
			note: 'abgegeben: Urteil + „I’m done" in der Absende-Zone',
		})
	}

	// ── Waiting in draft with the momentum line; light: reset afterwards -> new round ──
	probe('present', P.draft.code, '4')
	await join(P.draft.code, dark ? 'Uma' : 'Tao')
	await waitFor(s, card('wait'))
	await phone({
		name: 'pace-phone-wait', waitSel: card('wait'),
		note: 'Warten im Entwurf: „Ready, …", „The quiz has not started yet.", Schwung-Zeile',
	})
	if (dark) {
		await s.go('about:blank')
		return
	}
	await s.go('about:blank') // §6.4: never reset while a page is polling
	probe('reset', P.draft.code)
	await phone({
		name: 'pace-phone-new-round', url: url(P.draft.code), waitSel: '.nick .pace-notice',
		note: 'Nach dem Zurücksetzen neu geladen: „A new round has started — choose your name."',
	})

	// ── Closed with input: start, type text, do not send, deadline passed ──
	await phoneAt(s, url(P.late.code), PHONE)
	await waitFor(s, card('start'))
	await s.click(card('start') + ' .submit')
	await waitFor(s, '.h-app .free-vote input[type="text"]')
	await s.type('.h-app .free-vote input[type="text"]', 'X-Frame')
	probe('window', P.late.code, 'closesAt=now-1')
	const tLost = await until('vm.paceCard === "closed"', 'Geschlossen-Karte', 12000)
	await phone({
		name: 'pace-phone-closed-lost', waitSel: card('closed'),
		note: 'Frist vorbei, während getippt wurde: „Your last answer was not sent in time."',
	})
	note('Geschlossen-Karte ' + secs(tLost) + ' nach „probe window closesAt=now-1" (Soll ≤ 4 s + Abruf)')

	// Second phone without a name (cookie briefly removed): closed -> no joining.
	const rika = await s.voterCookie()
	await s.dropVoterCookie()
	await phone({
		name: 'pace-phone-shut', url: url(P.late.code), waitSel: card('shut'),
		note: 'Geschlossen, ohne Namen: kein Namensbildschirm, nur „The quiz is closed."',
	})
	await s.dropVoterCookie()
	await s.addVoterCookie(rika)
	probe('release', P.late.code)
	await phone({
		name: 'pace-phone-released', url: url(P.late.code), waitSel: '.big-place',
		note: 'Freigegeben mit Rangliste (paceFinal): der bestehende Endstand mit eigenem Platz',
	})

	// ── Practice run released: no leaderboard -> card "Quiz finished" ──
	await join(P.practice.code, 'Pia')
	await waitFor(s, card('start'))
	await s.click(card('start') + ' .submit')
	await waitFor(s, '.h-app .choices .choice:not([disabled])') // answer lock after the question switch
	await s.click('.choices .choice:nth-child(2)')
	await until('vm.myResult && vm.myResult.final', 'endgültiges Urteil im Probelauf')
	probe('close', P.practice.code) // without a deadline: closes and releases
	try {
		await until('vm.paceCard === "over"', 'Karte „over"', 15000)
		await phone({
			name: 'pace-phone-over', waitSel: card('over'),
			note: 'Probelauf freigegeben (keine Rangliste): „Quiz finished", Punkte, „See all results"',
		})
		await s.click(card('over') + ' .review-cta')
		await waitFor(s, '.review')
		await phone({ name: 'pace-phone-over-review', waitSel: '.review', note: '„See all results" nach der Freigabe' })
		await s.dropVoterCookie()
		await phone({
			name: 'pace-phone-over-anon', url: url(P.practice.code), waitSel: card('over'),
			note: 'dasselbe ohne Namen: ohne Punkte, ohne Auswertung',
		})
	} finally {
		// pace-screen releases the practice run itself: reopen it.
		probe('window', P.practice.code, 'closedAt=0', 'releasedAt=0')
	}
	await s.go('about:blank')
}

/*
 * Self-paced projector (§3, §6.2): state, header, rows, grid, right
 * column, meta bar, horizontal overflow (page, stage, kiosk), leak
 * (question text or options anywhere in the text) and the contrast of the
 * race rows (text on fill / text on track, ≥ 4.5:1; bar against track only as
 * a number) and of the secondary texts (meta chips, legend, short URL,
 * deadline pill: the public page carries data-themes="", so Pulse's dark
 * values do not apply there). Capital letters = finding; the pass enters it
 * into the anomalies.
 */
const PACE_SCREEN = `
	const root = document.querySelector('.scr')
	const vm = root && root.__vue__
	if (!vm || !vm.isPaced) { return null }
	const txt = (el) => (el ? el.textContent.trim().replace(/\\s+/g, ' ') : '')
	const q = (sel) => root.querySelector(sel)
	const rgb = (c) => (c.match(/[\\d.]+/g) || []).map(Number)
	const lum = ([r, g, b]) => [r, g, b].map((v) => { const x = v / 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4) })
		.reduce((s, v, i) => s + v * [0.2126, 0.7152, 0.0722][i], 0)
	const base = rgb(getComputedStyle(root).backgroundColor)
	const solid = (c) => { const [r, g, b, a = 1] = rgb(c); return [r, g, b].map((v, i) => v * a + base[i] * (1 - a)) }
	const ratio = (fg, bg) => { const a = lum(solid(fg)), b = lum(solid(bg)); return Math.round((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05) * 10) / 10 }
	// Hintergrund eines Elements: der erste nicht durchsichtige Vorfahre.
	const bgOf = (el) => {
		for (let n = el; n; n = n.parentElement) {
			const bg = getComputedStyle(n).backgroundColor
			if (bg && !/rgba\\(0, 0, 0, 0\\)|transparent/.test(bg)) { return bg }
		}
		return getComputedStyle(root).backgroundColor
	}
	const rows = Array.from(root.querySelectorAll('.pace-race-row'))
	const contrast = {}
	const bars = {}
	const weak = []
	for (const r of rows) {
		const kind = r.classList.contains('is-done') ? 'done' : r.classList.contains('is-idle') ? 'idle' : 'q'
		if (contrast[kind]) { continue }
		const onFill = ratio(getComputedStyle(r.querySelector('.srow-text--fill')).color, getComputedStyle(r.querySelector('.srow-fill')).backgroundColor)
		const onTrack = ratio(getComputedStyle(r.querySelector('.srow-text--track')).color, getComputedStyle(r.querySelector('.srow-track')).backgroundColor)
		contrast[kind] = onFill + '/' + onTrack
		bars[kind] = ratio(getComputedStyle(r.querySelector('.srow-fill')).backgroundColor, getComputedStyle(r.querySelector('.srow-track')).backgroundColor)
		if (onFill < 4.5 || onTrack < 4.5) { weak.push(kind) }
	}
	// Nebentexte: jeder sichtbare mit eigener Farbe gegen seinen Grund.
	const side = []
	for (const el of root.querySelectorAll('.scr-meta .scr-chip, .scr-legend, .pace-race-url, .scr-lobby-when, .scr-join-url')) {
		if (!txt(el)) { continue }
		const k = ratio(getComputedStyle(el).color, bgOf(el))
		side.push(txt(el).slice(0, 18) + ' ' + k)
		if (k < 4.5) { weak.push('„' + txt(el).slice(0, 18) + '"') }
	}
	const LEAK = ['Which port', 'case sensitive', 'databases', 'question types', 'header blocks', 'Match protocol',
		'Match the service', 'not a database', 'resolves host', 'reverse proxy', 'Traefik', 'Postgres', 'MariaDB', 'X-Frame']
	const body = document.body.innerText
	const leak = LEAK.filter((w) => body.includes(w))
	// Geschlossen: der Server schickt bei Rückmeldung je Frage die Spitze mit —
	// kein Name daraus darf auf der Leinwand stehen.
	const names = (vm.paceState === 'closed' && Array.isArray(vm.leaderboard)) ? vm.leaderboard.map((r) => r.nickname).filter((nm) => body.includes(nm)) : []
	const grid = q('.pace-race-grid')
	const n = vm.race ? vm.race.n : 0
	const gridRows = grid ? getComputedStyle(grid).gridTemplateRows.split(' ').length : 0
	const board = Array.from(root.querySelectorAll('.pace-race-board .lb-row')).map((r) => txt(r.querySelector('.lb-score')))
	const stage = q('.scr-stage')
	const de = document.documentElement
	const out = []
	const bad = (cond, word) => { if (cond) { out.push(word) } }
	out.push(vm.paceState + (vm.raceView ? ' · Rennrahmen' : ' · Lobby') + (vm.raceSide ? ' · rechts ' + vm.raceSide : '')
		+ (q('.pace-race.is-dense') ? ' · enge Zeilen' + (vm.raceTight ? ' (Schrumpf-Grenze)' : '') : ''))
	if (q('.scr-lobby')) { out.push('Lobby „' + txt(q('.scr-lobby-title')) + '" / „' + txt(q('.scr-lobby-sub')) + '"' + (q('.scr-lobby-when') ? ' / „' + txt(q('.scr-lobby-when')) + '"' : '')) }
	if (q('.scr-head')) { out.push('Kopf „' + txt(q('.scr-head')) + '"') }
	if (q('.scr-race-note')) { out.push('Mitte „' + txt(q('.scr-race-note-big')) + '" + „' + txt(q('.scr-race-note-sub')) + '"') }
	if (rows.length) { out.push(rows.length + ' Zeilen (' + rows.map((r) => txt(r.querySelector('.srow-text--track .srow-label')) + ' ' + txt(r.querySelector('.srow-text--track .srow-val'))).join(', ') + ')') }
	if (grid) { out.push('Raster ' + gridRows + ' Zeilen je Spalte bei n=' + n) }
	// Offen: nicht gestartet + je Frage + fertig = beigetreten (jede Person genau einmal).
	const sum = rows.reduce((s, r) => s + (Number(txt(r.querySelector('.srow-text--track .srow-val')).replace(/[^0-9]/g, '')) || 0), 0)
	if (rows.length && vm.race) { out.push('Summe ' + sum + ' von ' + vm.race.joined) }
	bad(vm.paceState === 'open' && rows.length > 0 && !!vm.race && sum !== vm.race.joined, 'SUMME FALSCH (erwartet ' + (vm.race && vm.race.joined) + ')')
	bad(grid && gridRows !== Math.ceil(n / 2), 'RASTER FALSCH (erwartet ' + Math.ceil(n / 2) + ')')
	bad(!grid && n > 10 && n <= 20 && vm.paceState === 'open' && vm.raceView, 'RASTER FEHLT')
	if (board.length) { out.push('Spitze ' + board.length + ' (' + board.join(', ') + ')') }
	bad(board.length > 8, 'SPITZE ÜBER 8')
	bad(board.some((sc) => Number(sc) <= 0), 'NULLZEILE IN DER SPITZE')
	if (q('.scr-final')) { out.push('Endstand-Block') }
	bad(vm.paceState === 'closed' && (root.querySelectorAll('.pulse-lb').length > 0 || names.length > 0), 'RANGLISTE IM GESCHLOSSENEN' + (names.length ? ' (' + names.join(', ') + ')' : ''))
	const chips = Array.from(root.querySelectorAll('.scr-meta .scr-chip')).map(txt)
	if (chips.length) { out.push('Meta [' + chips.join(' | ') + ']' + (txt(q('.scr-legend')) ? ' „' + txt(q('.scr-legend')) + '"' : '') + (q('.scr-join-mini') ? ' + Mini-QR' : '')) }
	const lg = q('.scr-legend')
	if (lg && txt(lg) && lg.scrollWidth > lg.clientWidth + 1) { out.push('Legende abgeschnitten') }
	if (Object.keys(contrast).length) { out.push('Kontrast Füllung/Spur ' + Object.entries(contrast).map(([k, v]) => k + ' ' + v).join(', ')) }
	if (Object.keys(bars).length) { out.push('Balken gegen Spur ' + Object.entries(bars).map(([k, v]) => k + ' ' + v).join(', ')) }
	if (side.length) { out.push('Nebentexte ' + side.join(', ')) }
	bad(weak.length > 0, 'KONTRAST UNTER 4,5 (' + weak.join(', ') + ')')
	const pageX = de.scrollWidth - de.clientWidth
	const stageX = stage ? stage.scrollWidth - stage.clientWidth : 0
	const scrY = root.scrollHeight - root.clientHeight
	out.push('waagerecht Seite ' + pageX + ' px / Bühne ' + stageX + ' px, Kiosk senkrecht ' + scrY + ' px')
	bad(pageX > 0 || stageX > 0, 'WAAGERECHTER ÜBERLAUF')
	bad(scrY > 1, 'KIOSK LÄUFT ÜBER')
	bad(leak.length > 0, 'LECK: ' + leak.join(', '))
	return out.join('; ')
`

/*
 * Self-paced, projector (specification §3, §5.2 step 4.5): every state
 * at 1280×720 (PaceRun preview, PowerPoint add-in) and 1920×1080 (real
 * inner dimensions; the window chrome is added on), light and dark. The
 * geckodriver only knows one session at a time: per state first a light
 * one, then a dark one, so every room state is set only once.
 *
 * Rooms (runs last, §6.1): mid/wide/big and race are backdated via `window`
 * (joining in the first 2 min, the top list after that), homework expires
 * through its deadline, race is closed raw (`window closedAt=now`: a
 * `close` without a deadline would release immediately) and then released,
 * practice released, draft opened and reset (§6.4: every session goes to
 * about:blank before closing, so nothing is polling any more at reset
 * time), reopened without participants and released.
 */
async function paceScreenPass() {
	const P = paceData
	const url = (code) => `/apps/pulse/screen/${code}`
	// A session with real inner dimensions of 1280×720 and 1920×1080: measure
	// the window chrome once and add it on.
	const open = async (dark) => {
		const s = await newSession(dark)
		await s.size(DESKTOP)
		const chrome = DESKTOP[1] - Number(await s.script('return window.innerHeight'))
		s.kiosk = [[1280, 720], DESKTOP].map(([w, h]) => Object.assign([w, h + chrome], { css: w + '×' + h }))
		return s
	}
	// Leave the projector page before closing (§6.4).
	const close = async (s) => { await s.go('about:blank').catch(() => {}); await s.close() }
	const note = (text) => index.push(`| (Messung) | | ${url('<CODE>')} | ${text} |`)
	const check = (ok, text) => {
		note((ok ? 'ok: ' : '**FEHLER:** ') + text)
		if (!ok) { overflow.push('pace-screen: ' + text) }
		return ok
	}
	const MARKS = ['RASTER', 'SPITZE ÜBER', 'NULLZEILE', 'RANGLISTE IM', 'KONTRAST UNTER', 'WAAGERECHTER', 'KIOSK LÄUFT', 'LECK', 'SUMME']
	// One state: 1280×720 and 1920×1080, light and dark. Presence is refreshed
	// before every image; it is valid for 15 s, and four images take longer.
	const all = async (name, code, what, { waitSel = '.scr-lobby, .scr.is-race .scr-stage', present = 0, settle = 2500 } = {}) => {
		for (const dark of [false, true]) {
			const s = await open(dark)
			try {
				for (const size of s.kiosk) {
					if (present) { probe('present', code, String(present)) }
					await shot(s, {
						name: `${dark ? 'dark-' : ''}pace-screen-${name}-${size[0]}`, url: url(code), size, waitSel, settle,
						measure: PACE_SCREEN, note: `Beamer eigenes Tempo: ${what}${dark ? ', dunkel' : ''}`,
					})
					const line = index[index.length - 1]
					for (const mark of MARKS) {
						if (line.includes(mark)) { overflow.push(line.split(' | ')[0].slice(2) + ': ' + mark) }
					}
				}
			} finally { await close(s) }
		}
	}
	const race = '.scr.is-race .scr-stage'
	const lobby = '.scr-lobby'

	// ── Lobby: draft and "open, nobody started yet" ─────────────────────
	await all('draft', P.draft.code, 'Entwurf — Lobby „Not open yet" statt „Starting shortly"', { waitSel: lobby, present: 4 })
	probe('open', P.draft.code, String(2 * 86400))
	await all('open-idle', P.draft.code, 'offen mit Frist, noch niemand gestartet — Lobby „Join and start at your own pace." + „Open until …"', { waitSel: lobby, present: 3 })

	// ── Race n=6: joining for the first 2 min, then the top list (points > 0 only) ──
	probe('window', P.race.code, 'openedAt=now-30')
	await all('race-join', P.race.code, 'Rennen n=6 in den ersten 2 min — rechts der Beitrittsblock', { waitSel: race, present: 10 })
	probe('window', P.race.code, 'openedAt=now-600')
	await all('race-board', P.race.code, 'Rennen n=6 nach 2 min — rechts die Spitze (nur Punkte > 0)', { waitSel: race, present: 10 })

	// ── Homework (reveal at the end): never a leaderboard, joining stays open ──
	await all('homework', P.homework.code, 'Hausaufgabe (Frist, Auflösung am Ende) — Beitritt bleibt, Chip „Open until …"', { waitSel: race, present: 6 })

	// ── Many questions: two columns (16, 20), groups (24, 300 joined) ──
	for (const [key, what] of [['mid', 'n=16 — zwei Spalten à 8'], ['wide', 'n=20 — zwei Spalten à 10'],
		['big', 'n=24, 300 Beigetretene — Gruppen zu 3, „Not started"']]) {
		probe('window', P[key].code, 'openedAt=now-600')
		await all(key, P[key].code, what + ', rechts die Spitze', { waitSel: race, present: 12 })
	}

	// ── The deadline expires while the projector is up: closed locally, the
	// next poll confirms it, without reloading. ──
	probe('window', P.homework.code, 'closesAt=now+6')
	let waited = -1
	const sl = await open(false)
	try {
		await sl.size(sl.kiosk[0])
		await sl.go(HOST + url(P.homework.code))
		await waitFor(sl, race)
		waited = await waitForText(sl, '.scr-head', /The quiz is closed\./, 15000)
	} catch { /* shows up in the finding */ } finally { await close(sl) }
	check(waited >= 0, `Frist läuft vor offenem Beamer ab → Kopf „The quiz is closed." nach ${waited >= 0 ? (waited / 1000).toFixed(1) + ' s' : '— blieb aus'}, ohne Neuladen`)
	await all('homework-expired', P.homework.code, 'Hausaufgabe nach Fristablauf — geschlossen (Auflösung am Ende)', { waitSel: race })

	// ── Closed with feedback per question: counter, NO leaderboard ──
	probe('window', P.race.code, 'closedAt=now')
	await all('closed', P.race.code, 'geschlossen, Rückmeldung je Frage — Zähler, keine Rangliste (der Server schickt sie mit)', { waitSel: race, present: 10 })

	// ── Released: podium; practice run without a leaderboard ──
	probe('release', P.race.code)
	await all('released', P.race.code, 'freigegeben — Endstand (Podium)', { waitSel: '.scr-final', settle: 6000 })
	probe('close', P.practice.code) // without a deadline: closes and releases
	await all('practice-released', P.practice.code, 'Probelauf freigegeben — keine Rangliste: „Quiz finished"', { waitSel: race })

	// ── Released without participants: [] → "No points yet" ──
	// Resetting = brute-force trap (§6.4): no page is polling here any more;
	// every session was closed via about:blank after its state.
	probe('reset', P.draft.code)
	probe('open', P.draft.code, '0')
	await all('open-idle-race', P.draft.code, 'offen ohne Frist, niemand beigetreten — Lobby ohne Fristzeile', { waitSel: lobby })
	probe('close', P.draft.code) // without a deadline: closes and releases
	await all('released-empty', P.draft.code, 'freigegeben ohne Teilnehmende — „Final standings" + „No points yet"', { waitSel: race })
}

let failed = false
try {
	await waitForDriver()
	// When touching up a single pass: PULSE_SHOTS_ONLY=moderator
	const only = process.env.PULSE_SHOTS_ONLY || ''
	const wanted = (part) => !only || only === part || (only === 'pace' && part.startsWith('pace-'))

	let s
	// The store pass runs on its own: its own rooms (probe.php store), its own
	// image size, double pixel density. Mixed with the other passes it
	// would make no sense.
	if (only === 'store') {
		console.log('Store-Bilder:')
		s = await newSession(false, { 'layout.css.devPixelsPerPx': '2' })
		try {
			await storePass(s)
		} finally { await s.close() }
	}

	if (wanted('public')) {
		console.log('helle Oberfläche:')
		s = await newSession(false)
		try {
			await publicPass(s, false)
		} finally { await s.close() }
	}

	if (wanted('edge')) {
		console.log('Grenzfälle:')
		s = await newSession(false)
		try {
			await edgePass(s)
		} finally { await s.close() }
	}

	if (wanted('phone')) {
		console.log('Handy (§8):')
		s = await newSession(false)
		try {
			await phonePass(s)
		} finally { await s.close() }
	}

	if (wanted('types')) {
		console.log('Fragetypen (§7):')
		s = await newSession(false)
		try {
			await typePass(s)
		} finally { await s.close() }
	}

	if (wanted('dark')) {
	console.log('dunkle Oberfläche (Auswahl):')
	s = await newSession(true)
	try {
		// Only the meaningful stages; shooting everything twice gains nothing.
		await shot(s, {
			name: 'dark-screen-lobby', url: `/apps/pulse/screen/${poll.code}`, size: DESKTOP,
			waitSel: '.scr', note: 'Beamer Lobby, dunkel',
		})
		probe('state', poll.code, String(poll.polls[0].id), 'open')
		await shot(s, {
			name: 'dark-screen-poll-choice-open', url: `/apps/pulse/screen/${poll.code}`, size: DESKTOP,
			waitSel: '.scr-stage', note: 'Beamer Multiple Choice offen, dunkel',
		})
		// Revealed in the dark: this is where the percentages sit whose contrast
		// D10 is about (acceptance §5.11).
		probe('state', poll.code, String(poll.polls[0].id), 'locked')
		await shot(s, {
			name: 'dark-screen-poll-choice-locked', url: `/apps/pulse/screen/${poll.code}`, size: DESKTOP,
			waitSel: '.scr-stage', note: 'Beamer Multiple Choice aufgelöst, dunkel',
		})
		await shot(s, {
			name: 'dark-phone-poll-choice', url: `/apps/pulse/s/${poll.code}`, size: PHONE,
			waitSel: '.pulse-part', note: 'Handy Abstimmen, dunkel',
		})
		// The lightness scale mixes against the stage background (§7.0), so in the
		// dark it runs the other way and has to be checked separately.
		// The same applies to the scale's zero stubs and to the image on its
		// backing surface (§7.8).
		const types = probeData.types
		if (types) {
			const byLabel = Object.fromEntries(types.polls.map((p) => [p.label, p]))
			for (const [label, note] of [
				['rank-polar', 'Beamer Reihenfolge (Helligkeitsstaffel), dunkel'],
				['scale-same', 'Beamer Skala, dunkel'],
				['image-portrait', 'Beamer Bildfrage hochkant, dunkel (kein leuchtender Block)'],
			]) {
				probe('state', types.code, String(byLabel[label].id), 'locked')
				await shot(s, {
					name: `dark-screen-type-${label}`, url: `/apps/pulse/screen/${types.code}`, size: DESKTOP,
					waitSel: '.scr-stage', note,
				})
			}
		}
	} finally { await s.close() }
	}

	if (wanted('embed')) {
		console.log('Einbett-Shell (§9.7):')
		for (const dark of [false, true]) {
			s = await newSession(dark)
			try {
				await embedPass(s, dark)
			} finally { await s.close() }
		}
	}

	if (wanted('overview')) {
		console.log('Übersichten (Räume + Deck):')
		for (const dark of [false, true]) {
			s = await newSession(dark)
			try {
				await overviewPass(s, dark)
			} finally { await s.close() }
		}
	}

	if (wanted('match')) {
		console.log('Zuordnung mit acht Paaren:')
		for (const dark of [false, true]) {
			s = await newSession(dark)
			try {
				await matchPass(s, dark)
			} finally { await s.close() }
		}
	}

	if (wanted('moderator')) {
		console.log('Moderator (angemeldet):')
		s = await newSession(false)
		try {
			await moderatorPass(s)
		} finally { await s.close() }
	}

	if (wanted('pace-mod')) {
		if (!paceData) {
			console.log('pace-mod übersprungen: keine pace.json (run.sh legt sie nur für pace-Strecken an)')
		} else {
			console.log('Eigenes Tempo — Moderator-Deck:')
			s = await newSession(false)
			try {
				await paceModPass(s)
			} finally { await s.close() }
			s = await newSession(true)
			try {
				await paceModDark(s)
			} finally { await s.close() }
		}
	}

	if (wanted('pace-run')) {
		if (!paceData) {
			console.log('pace-run übersprungen: keine pace.json (run.sh legt sie nur für pace-Strecken an)')
		} else {
			console.log('Eigenes Tempo — Laufansicht:')
			// The CSV buttons download into a throwaway folder that is removed afterwards.
			const dl = mkdtempSync(join(tmpdir(), 'pulse-shots-dl-'))
			s = await newSession(false, downloadPrefs(dl))
			try {
				await paceRunPass(s, false)
				await paceRunClicks(s, dl)
			} finally {
				await s.close()
				rmSync(dl, { recursive: true, force: true })
			}
			s = await newSession(true)
			try {
				await paceRunPass(s, true)
			} finally { await s.close() }
		}
	}

	if (wanted('pace-phone')) {
		if (!paceData) {
			console.log('pace-phone übersprungen: keine pace.json (run.sh legt sie nur für pace-Strecken an)')
		} else {
			console.log('Eigenes Tempo — Handy:')
			// Dark first: the light pass closes `late` and resets `draft`.
			s = await newSession(true)
			try {
				await pacePhonePass(s, true)
			} finally { await s.close() }
			s = await newSession(false)
			try {
				await pacePhonePass(s, false)
			} finally { await s.close() }
		}
	}

	if (wanted('pace-screen')) {
		if (!paceData) {
			console.log('pace-screen übersprungen: keine pace.json (run.sh legt sie nur für pace-Strecken an)')
		} else {
			console.log('Eigenes Tempo — Beamer:')
			// The pass opens and closes its sessions itself (light, then
			// dark per room state).
			await paceScreenPass()
		}
	}
} catch (e) {
	failed = true
	console.error('Abbruch:', e.message)
} finally {
	writeFileSync(join(OUT, 'index.md'), [
		'# Design-Screenshots',
		'',
		`Host: ${HOST} · Räume: ${poll.code} (Umfrage), ${quiz.code} (Quiz)`,
		'',
		'| Datei | Größe | Seite | Was |',
		'|---|---|---|---|',
		...index,
		'',
		...(overflow.length
			? ['## Bühnen laufen über', '', ...overflow.map((o) => '- ' + o), '']
			: ['Keine Bühne läuft über ihren Kasten hinaus.', '']),
	].join('\n'))
	driver.kill()
	console.log(`\n${index.length} Aufnahmen in ${OUT}`)
	if (overflow.length) { console.error(`${overflow.length} Bühne(n) laufen über: ${overflow.join(', ')}`) }
	process.exit(failed ? 1 : 0)
}
