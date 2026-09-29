#!/usr/bin/env node
/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Schießt Screenshots von allen Pulse-Ansichten.
 *
 * Steuert den auf dem Server installierten Firefox über geckodriver (WebDriver
 * über HTTP, ohne npm-Abhängigkeit). Den Zustand der Räume setzt nicht der
 * Browser, sondern probe.php serverseitig — deshalb lassen sich auch Ansichten
 * aufnehmen, die man sonst nur durch Klicken erreicht.
 *
 *   node shoot.mjs <probe.json> [ausgabeordner]
 */

import { execFileSync, spawn } from 'node:child_process'
import { existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
/**
 * Gegen welche Instanz gefahren wird. PULSE_HOST schlägt alles; sonst darf in
 * dev/design-shots/.host ein Name stehen (die Datei ist nicht im Repo — ein
 * interner Hostname gehört nicht in ein öffentliches Repository). Bewusst ohne
 * Voreinstellung: eine fremde Adresse als Rückfall schickte den Login des
 * Wegwerf-Nutzers an eine Instanz, auf der er gar nicht existiert.
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
// Store-Seite: 16:9 in der Breite, die apps.nextcloud.com anzeigt. Aufgenommen
// wird mit doppelter Pixeldichte, das Bild ist also 2400 px breit und bleibt
// auf hochauflösenden Schirmen scharf.
const STORE = [1200, 675]
const STORE_MOD = [1280, 720]

const probeData = JSON.parse(readFileSync(process.argv[2], 'utf8'))
// Räume im eigenen Tempo (probe.php pace-create) — nur für die pace-Strecken.
const PACE_FILE = join(dirname(process.argv[2]), 'pace.json')
const paceData = existsSync(PACE_FILE) ? JSON.parse(readFileSync(PACE_FILE, 'utf8')) : null
const OUT = process.argv[3] || join(HERE, 'out')
mkdirSync(OUT, { recursive: true })

const index = []
// Bühnen, die über ihren Kasten hinauslaufen — der Kiosk clippt sie stumm.
const overflow = []
let shotNo = 0

/** Serverseitigen Zustand setzen (probe.php im Container). */
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

/** Eine Browser-Sitzung; `dark` schaltet das System-Theme um. */
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
		// Ein Cookie holen, wegnehmen, zurücklegen — ein zweites Handy in derselben Sitzung.
		cookie: (name) => call('GET', `/session/${id}/cookie/${name}`),
		dropCookie: (name) => call('DELETE', `/session/${id}/cookie/${name}`),
		addCookie: (cookie) => call('POST', `/session/${id}/cookie`, { cookie }),
		// Zeiger in die Ecke: sonst bleibt der Hover des zuletzt geklickten Knopfs im Bild.
		// Zwei Schritte: die Aktionsquelle merkt sich ihre Lage, ein zweites (1,1) wäre kein Zug.
		mouseAway: () => call('POST', `/session/${id}/actions`, { actions: [{ type: 'pointer', id: 'shots-mouse', parameters: { pointerType: 'mouse' },
			actions: [{ type: 'pointerMove', duration: 0, x: 4, y: 4, origin: 'viewport' }, { type: 'pointerMove', duration: 0, x: 1, y: 1, origin: 'viewport' }] }] }),
		// In ein iframe wechseln (Element-ID) bzw. zurück ins Hauptdokument (null).
		frame: (el) => call('POST', `/session/${id}/frame`, { id: el === null ? null : { 'element-6066-11e4-a52e-4f735466cecf': el } }),
	}
	return s
}

/** Auf ein Element warten — Polling ist hier ehrlicher als ein fester Timer. */
async function waitFor(s, sel, timeout = 15000) {
	const until = Date.now() + timeout
	while (Date.now() < until) {
		if (await s.script('return !!document.querySelector(arguments[0])', [sel])) { return true }
		await sleep(250)
	}
	throw new Error(`Element blieb aus: ${sel}`)
}

/**
 * Eine Aufnahme: Fenstergröße setzen, Seite laden, auf den Anker warten,
 * optionale Klicks ausführen, kurz nachziehen lassen, auslösen.
 */
async function shot(s, { name, url, open, size, waitSel, actions = [], settle = 1200, note = '', measure = '' }) {
	await s.size(size)
	// open: eigener Weg zur Seite (z. B. über ein Menü); url steht dann nur im Index.
	if (open) { await open() } else if (url) { await s.go(HOST + url) }
	if (waitSel) { await waitFor(s, waitSel) }
	for (const act of actions) {
		if (act.click) { await s.click(act.click) }
		if (act.js) { await s.script(act.js) }
		if (act.type) { await s.type(act.type[0], act.type[1]) }
		if (act.wait) { await waitFor(s, act.wait) }
		// Warten, bis ein Ausdruck wahr ist (z. B. im iframe) — ohne Abbruch:
		// was dann fehlt, steht in der Messzeile.
		if (act.until) {
			const end = Date.now() + (act.timeout || 10000)
			while (Date.now() < end && !(await s.script('return !!(' + act.until + ')'))) { await sleep(250) }
		}
		if (act.sleep) { await sleep(act.sleep) }
	}
	await sleep(settle)
	const file = `${String(++shotNo).padStart(2, '0')}-${name}.png`
	writeFileSync(join(OUT, file), Buffer.from(await s.png(), 'base64'))
	// Der Beamer ist ein geclippter Kiosk: was über die Bühne hinausragt, wird
	// stillschweigend abgeschnitten und ist auf dem Bild nur zu erkennen, wenn
	// man weiß, wonach man sucht. Deshalb misst der Prüfstand es selbst
	// (Abnahmekriterium §5.13) und schreibt das Ergebnis in den Index.
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
	// Schalter tragen festen Text plus Häkchen (R3), Rotes steht zuletzt (R4),
	// und Escape gibt den Fokus zurück (Abnahme #7/#8/#10) — alles drei ist auf
	// dem Bild bestenfalls zu ahnen.
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
	// Raumkarte, Deck-Kopf und Deck-Zeile sind Knopf-Reihen, die umbrechen, wenn
	// es eng wird. Wie viele Knöpfe dort stehen und über wie viele Zeilen sie
	// laufen, ist auf dem Bild zu zählen — hier steht es als Zahl.
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
	// Handy: die Falz-Regel aus §8.1 — was scrollt, steht hier, statt auf dem
	// Bild gesucht zu werden. Scrollen ist erlaubt (ab 7 Optionen), aber es
	// soll sichtbar sein, WANN es passiert.
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
	// Moderator: „genau EIN gefüllter Knopf" (§9.9 #1) und „das private Panel ist
	// im Vollbild nicht sichtbar" (#6) sind auf dem Bild nur zu ahnen. Gezählt
	// wird deshalb hier — gefüllt heißt: eigener Hintergrund, nicht durchsichtig.
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
	// Laufansicht im eigenen Tempo (§6.2): wie die Präsentation genau EIN
	// gefüllter Knopf (Dialoge ausgenommen), die Leiste über der Falz, nie ein
	// Lösungsschlüssel (.tg-accepted) und nie waagerechtes Scrollen der Seite.
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
	// Startbildschirm: die erste Raumkarte muss vollständig über der Falz stehen
	// (§9.9 #7), und die inaktive Seite des Umschalters muss lesbar sein (#8).
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

/* -------------------------------------------------------------- Aufnahmeplan */

const poll = probeData.poll
const quiz = probeData.quiz

async function publicPass(s, dark) {
	const tag = dark ? 'dark-' : ''

	await shot(s, {
		name: `${tag}join`, url: '/apps/pulse/join', size: PHONE,
		waitSel: '.pulse-part', note: 'Beitritt (Handy)',
	})
	// Lobby: kein Cursor -> Beamer zeigt Code und QR.
	probe('state', poll.code, '0', 'open')
	await shot(s, {
		name: `${tag}screen-lobby`, url: `/apps/pulse/screen/${poll.code}`, size: DESKTOP,
		waitSel: '.scr-lobby', note: 'Beamer Lobby (QR + Code)',
	})

	for (const room of [poll, quiz]) {
		const mode = room === poll ? 'poll' : 'quiz'
		if (mode === 'quiz') {
			// Handy braucht im Quiz erst einen Namen; die Sitzung behält den
			// Voter-Cookie, das reicht für alle folgenden Aufnahmen.
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
			// Präsenz vortäuschen: sonst zeigt die Eingangs-Anzeige „0 von 0".
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

	// Quiz beenden -> Endstand-Bühne + Handy-Abschluss.
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
 * Grenzfälle der Bühnen-Grammatik (§2.6, Prüffälle aus §11). Sie kommen im
 * Bestand nicht vor, entscheiden aber über die Abnahme: Zeilendeckel bei zwei
 * Optionen, Schrumpf-Schleife bei acht, zweizeiliges Label mit Unterlängen und
 * die beiden Endstände (neun bzw. zwei Teilnehmende).
 */
async function edgePass(s) {
	const edge = probeData.edge
	// Offene Bühne: die Zustände, an denen die Eingangs-Anzeige hängt (§6.6).
	// Die Präsenz kommt aus vorgetäuschten Heartbeats — ohne sie stünde dort
	// „0", weil kein echtes Handy im Raum ist.
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

	// Zeilenmodell an seinen Rändern: zwei Optionen, acht, langes Label.
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
	// Countdown in der Dringlichkeitsstufe: eine Quizfrage mit 9 Sekunden Rest.
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
 * Fragetypen aufgelöst (§7.10). Die Konstellationen hier entscheiden die
 * Abnahme von Etappe 3 und lassen sich mit Zufallsstimmen nicht herstellen:
 * Ø ≈ Median, alle Stimmen auf einem Wert, die Heatmap-Schwelle bei 44 gegen
 * 46 Antworten, ein polarisiertes Element in der Reihenfolge, hochkantes und
 * Panorama-Bild.
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

	// Heatmap-Schwelle: erst 44 Antworten (Punkte), dann zwei dazu (Heatmap).
	const compass = byLabel['compass-threshold']
	probe('state', types.code, String(compass.id), 'open')
	probe('fixture', types.code, String(compass.id), 'compass', '44')
	probe('state', types.code, String(compass.id), 'locked')
	await stage('compass-44', 'Beamer Kompass mit 44 Antworten (Einzelpunkte)')
	probe('fixture', types.code, String(compass.id), 'compass', '2')
	await stage('compass-46', 'Beamer Kompass mit 46 Antworten (Heatmap)', { url: null })

	// Bildfrage: offen (Bild + schmaler Eingang) und aufgelöst, hochkant und Panorama.
	for (const label of ['image-portrait', 'image-panorama']) {
		const p = byLabel[label]
		probe('state', types.code, String(p.id), 'open')
		probe('present', types.code, '24')
		probe('seed', types.code, '24')
		await stage(`${label}-open`, `Beamer Bildfrage ${label}, offen`)
		probe('state', types.code, String(p.id), 'locked')
		await stage(`${label}-locked`, `Beamer Bildfrage ${label}, aufgelöst`)
	}

	// Aufgelöst, aber niemand hat geantwortet (§7.9).
	const empty = byLabel['no-votes']
	probe('state', types.code, String(empty.id), 'open')
	probe('state', types.code, String(empty.id), 'locked')
	await stage('no-votes', 'Beamer aufgelöst ohne eine einzige Stimme')
}

/*
 * Handy (§8.10). Der öffentliche Durchgang fotografiert jede Frage einmal; hier
 * stehen die Zustände, die es nur nach einer Handlung gibt — gesendet, offenes
 * und abgelaufenes Korrekturfenster — und die Geräte-Grenzfälle aus §8.9.
 */
async function phonePass(s) {
	const edge = probeData.edge
	const NARROW = [320, 640]
	const LANDSCAPE = [844, 390]

	// Umfrage: auswählen (zweistufig), absenden, Zustand „gesendet" (§8.5).
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

	// Geräte-Grenzfälle (§8.9): an einer Frage, die dieses Gerät noch nicht
	// beantwortet hat — sonst stünde dort der Zustand „gesendet".
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

	// Acht Optionen mit Bild: der Antwortbereich scrollt, die Leiste nie (§8.9).
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

	// Quiz: Tipp sendet sofort, „Ändern" steht drei Sekunden (§8.0).
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
}

/*
 * Moderator und Einbettung (§9.9). Neu gegenüber dem Bestand sind die vier
 * Ablaufzustände hintereinander (die Hauptaktion muss mitwandern), der
 * Vollbildmodus (das private Panel darf dort nicht im Dokument stehen), das
 * leere Deck und der Raum, in dem niemand verbunden ist.
 */
async function login(s) {
	const pass = readFileSync(join(HERE, '.shots-pass'), 'utf8').trim()
	// Das Anmeldeformular baut Nextcloud im Browser zusammen; die IDs stehen
	// nicht im ausgelieferten HTML. Über die name-Attribute ist es stabil.
	const userSel = 'input[name="user"], #user'
	const passSel = 'input[name="password"], #password'
	await s.size(LAPTOP)
	// Nextcloud 34 verlangt Firefox >= 145 und schickt ältere Browser nach
	// /unsupported — der Server hier hat 128 ESR. Der Merker, den der Knopf
	// „Continue with this unsupported browser" setzt, wird gleich mitgesetzt;
	// sonst landet der Login in einer Schleife. Betrifft nur diesen Prüfstand,
	// nicht die App.
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

	// Startbildschirm: zweispaltig (Laptop) und einspaltig (§9.5). Beide Male
	// wird gemessen, wo die erste Raumkarte endet.
	await shot(s, {
		name: 'mod-start', url: '/apps/pulse/', size: LAPTOP,
		waitSel: '.pulse-mod', note: 'Moderator: Startbildschirm (zweispaltig ab 1200 px)',
	})
	await shot(s, {
		name: 'mod-start-narrow', url: '/apps/pulse/', size: [1024, 800],
		waitSel: '.pulse-mod', note: 'Moderator: Startbildschirm einspaltig (1024 px)',
	})

	// Die vier Ablaufzustände (§9.1) nacheinander am selben Quiz.
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
		// Das Podest läuft gestaffelt ein (Sieger bei 4,3 s) — vorher ist die
		// Fläche leer, wie bei den Endstand-Bildern am Beamer.
		actions: [{ click: '.mod-primary' }, { wait: '.mod-standings' }, { sleep: 5500 }],
		note: 'Moderator 4/4: Endstand sichtbar -> „Quiz beenden" (erst hier rot)',
	})

	// Vollbild: der projizierte Modus. Das private Panel darf dort nicht im
	// Dokument stehen — das misst shot() mit („kein privates Panel").
	probe('state', quiz.code, String(first.id), 'open')
	probe('present', quiz.code, '24')
	await shot(s, {
		name: 'mod-fullscreen', url: `/apps/pulse/room/${quiz.code}`, size: LAPTOP,
		waitSel: '.mod-bar', settle: 2000,
		actions: [{ click: '.pulse-menu > .pulse-btn' }, { sleep: 300 }, { js: 'document.querySelector(\'.pulse-menu-item[data-key="fs"]\').click()' }, { sleep: 1500 }],
		note: 'Moderator: Vollbild — nur die Leinwand, kein privates Panel',
	})
	// Vollbild wieder verlassen, sonst hängt die Sitzung darin fest.
	await s.script('if (document.fullscreenElement) { document.exitFullscreen() }')
	await sleep(800)

	// Beitritt offen, weil niemand verbunden ist (§9.4). Kein 'present'-Aufruf:
	// genau das ist der Zustand.
	const pollFirst = poll.polls[0]
	probe('state', poll.code, String(pollFirst.id), 'open')
	await shot(s, {
		name: 'mod-join-open', url: `/apps/pulse/room/${poll.code}`, size: LAPTOP,
		waitSel: '.mod-bar', settle: 3000, note: 'Moderator: niemand verbunden -> Beitritts-Panel offen',
	})

	// Deck ohne Fragen (§9.8).
	const empty = probeData.empty
	if (empty) {
		await shot(s, {
			name: 'mod-empty-deck', url: `/apps/pulse/room/${empty.code}`, size: LAPTOP,
			waitSel: '.mod-bar', settle: 1500, note: 'Moderator: Deck ohne Fragen -> „Frage anlegen"',
		})
	}

	// Deck-Editor (bleibt unverändert, §9.6) — als Beleg, dass er es tut.
	await shot(s, {
		name: 'mod-deck', url: `/apps/pulse/room/${poll.code}`, size: LAPTOP,
		waitSel: '.pulse-mod', settle: 1500,
		actions: [{ click: '.pulse-menu > .pulse-btn' }, { sleep: 300 }, { js: 'document.querySelector(\'.pulse-menu-item[data-key="deck"]\').click()' }, { wait: '.deck-list' }, { sleep: 800 }],
		note: 'Moderator: Deck-Liste',
	})
}

/*
 * Einbett-Shell (§9.7). Sie steckt in fremden Folien: englische Quelltexte über
 * l10n, .pulse-btn statt eines eigenen Knopfes, Wortmarke — und ein Farbschema,
 * das der Einbettung folgt, nicht dem Server. Der Fehlerfall gehört ans Feld.
 */
async function embedPass(s, dark) {
	const tag = dark ? 'dark-' : ''
	await shot(s, {
		name: `${tag}embed-form`, url: '/apps/pulse/embed', size: [1280, 720],
		waitSel: '.pulse-embed-cell', note: `Einbett-Shell: Code-Eingabe (${dark ? 'dunkel' : 'hell'})`,
	})
	if (dark) { return }
	// Unbekannter Code: Meldung am Feld, kein Dialog. ZZZZZZ ist formal gültig.
	const cells = Array.from({ length: 6 }, (_, i) => ({ type: [`.pulse-embed-cell:nth-child(${i + 1})`, 'Z'] }))
	await shot(s, {
		name: 'embed-unknown-code', size: [1280, 720],
		actions: [...cells, { click: '.pulse-embed-go' }, { sleep: 1500 }],
		note: 'Einbett-Shell: unbekannter Code -> Meldung am Feld',
	})
	// Gültiger Code: die Leinwand füllt den Rahmen.
	await shot(s, {
		name: 'embed-live', url: `/apps/pulse/embed?code=${poll.code}`, size: [1280, 720],
		waitSel: '.pulse-embed-frame', settle: 4000, note: 'Einbett-Shell: Leinwand im Rahmen',
	})
	// Eigenes Tempo (§5.2 Schritt 4.5): dieselbe Shell mit einem laufenden
	// Rennen — die Leinwand im Rahmen zeigt die Balken, nicht die Lobby.
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

// Einbett-Shell mit einem Rennen: steht es im Rahmen, und passt die Bühne?
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

/* -------------------------------------------------------------------- Ablauf */

const driver = spawn(join(HERE, '..', '..', 'tools', 'geckodriver'), ['--port', String(PORT), '--log', 'error'], {
	stdio: ['ignore', 'ignore', 'inherit'],
})
process.on('exit', () => driver.kill())

/*
 * Bilder für die Store-Seite (docs/APPSTORE.md §3). Andere Aufgabe als der
 * Rest dieses Prüfstands: nicht Grenzfälle belegen, sondern in fünf Bildern
 * zeigen, was die App tut. Deshalb eigene Räume (probe.php store), englische
 * Oberfläche und der öffentliche Hostname — der steht im Beitritts-Link und
 * im QR-Code, und der interne gehört nicht auf eine Store-Seite.
 */
async function storePass(s) {
	const p = probeData.poll
	const q = probeData.quiz
	const byLabel = (room, label) => room.polls.find((x) => x.label === label)

	// Das Fenster ist außen höher als der Inhalt; wie viel, hängt vom Browser ab.
	// Einmal messen und aufschlagen — sonst kommt aus 1200×675 ein Bild im
	// Verhältnis 2:1 statt 16:9, und der Beamer sieht flacher aus als er ist.
	await s.size(STORE)
	const chrome = STORE[1] - Number(await s.script('return window.innerHeight'))
	const box = ([w, h]) => [w, h + chrome]

	// 1. Was das Publikum sieht, bevor es losgeht: Code und QR groß auf der Wand.
	probe('state', p.code, '0', 'open')
	await shot(s, {
		name: 'lobby', url: `/apps/pulse/screen/${p.code}`, size: box(STORE),
		waitSel: '.scr-lobby', note: 'Store: Lobby — Beitritt per Code und QR',
	})

	// 2. Laufende Frage. Die Wortwolke sagt ohne Worte, was hier passiert.
	const words = byLabel(p, 'words')
	probe('state', p.code, String(words.id), 'open')
	// Feste Wortverteilung statt Zufall — sonst sieht das Bild bei jedem Lauf
	// anders aus und die Größenstaffelung ist Glückssache.
	probe('fixture', p.code, String(words.id), 'words')
	// Mehr Anwesende als Antworten: die Eingangs-Anzeige steht dann auf „24 von
	// 30" statt auf „vollständig" — eine laufende Frage sieht so aus.
	probe('present', p.code, '50')
	await shot(s, {
		name: 'live-words', url: `/apps/pulse/screen/${p.code}`, size: box(STORE),
		waitSel: '.scr-stage', note: 'Store: Wortwolke, Antworten laufen ein',
	})

	// 3./4. Dieselbe Frage von beiden Seiten: Handy beim Abstimmen, Wand beim
	// Auflösen. Erst das Handy — danach ist die Frage zu.
	const choice = byLabel(p, 'choice')
	probe('state', p.code, String(choice.id), 'open')
	await shot(s, {
		name: 'phone-vote', url: `/apps/pulse/s/${p.code}`, size: box(PHONE),
		waitSel: '.choices .choice',
		// Eine Option ist angetippt: erst dann ist der Absende-Knopf aktiv. Ohne
		// Auswahl zeigt das Bild einen ausgegrauten Knopf und wirkt kaputt.
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

	// 5. Der Blick der vortragenden Person: Leinwand-Vorschau links, die
	// Verteilung nur für sie rechts, eine Hauptaktion unten.
	await login(s)
	const first = q.polls[0]
	probe('state', q.code, String(first.id), 'open')
	probe('present', q.code, '15')
	await shot(s, {
		name: 'moderator', url: `/apps/pulse/room/${q.code}`, size: box(STORE_MOD),
		waitSel: '.mod-bar', settle: 2500, note: 'Store: Moderationsansicht im Quiz',
	})

	// 6. Quiz-Ende: Podest und Rangliste.
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
		} catch { /* noch nicht da */ }
		await sleep(250)
	}
	throw new Error('geckodriver kam nicht hoch')
}

/*
 * Übersichten: Startbildschirm („Meine Räume") und Deck-Liste, hell und dunkel,
 * breit und schmal. Um diese beiden Flächen geht der nächste Design-Durchgang —
 * die Knopf-Reihen sind dort gewachsen, ohne je geordnet worden zu sein.
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

	// Ein Raum ohne laufende Frage öffnet schon im Deck (loadRoom) — dann gibt
	// es gar kein Überlaufmenü, über das man dorthin wechseln könnte.
	const openDeck = [
		{ js: 'if (!document.querySelector(".deck-list")) { document.querySelector(".pulse-menu > .pulse-btn").click() }' }, { sleep: 300 },
		{ js: 'const d = document.querySelector(\'.pulse-menu-item[data-key="deck"]\'); if (d) { d.click() }' },
		{ wait: '.deck-list' }, { sleep: 800 },
	]

	// Deck beider Betriebsarten: das Quiz trägt zwei Schalter mehr im Menü
	// (Auflösen am Ende, Übungslauf).
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

	// Das offene Menü: fester Text plus Häkchen (R3), Rotes zuletzt (R4).
	await shot(s, {
		name: `ov-deck-menu-quiz${tag}`, url: `/apps/pulse/room/${quiz.code}`, size: LAPTOP,
		waitSel: '.pulse-mod', settle: 1200,
		actions: [...openDeck, { js: 'document.querySelector(".deck-head .pulse-menu > .pulse-btn").click()' }, { sleep: 500 }],
		note: `Übersicht: Deck-Menü offen${dark ? ', dunkel' : ''}`,
	})

	// Escape schließt und gibt den Fokus an den Auslöser zurück (Abnahme #10).
	await shot(s, {
		name: `ov-deck-menu-keyboard${tag}`, url: `/apps/pulse/room/${quiz.code}`, size: LAPTOP,
		waitSel: '.pulse-mod', settle: 1200,
		actions: [...openDeck,
			{ js: 'document.querySelector(".deck-head .pulse-menu > .pulse-btn").click()' }, { sleep: 400 },
			{ js: 'document.activeElement.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }))' },
			{ sleep: 400 }],
		note: `Übersicht: Menü mit Escape geschlossen, Fokus zurück am Auslöser${dark ? ', dunkel' : ''}`,
	})

	// Composer offen: der Kopf bleibt stehen, darunter kommt die zweite
	// Knopfschicht dazu.
	await shot(s, {
		name: `ov-composer${tag}`, url: `/apps/pulse/room/${quiz.code}`, size: LAPTOP,
		waitSel: '.pulse-mod', settle: 1500,
		actions: [...openDeck,
			{ js: 'document.querySelector(\'.add-btn\').click()' }, { wait: '.composer' }, { sleep: 900 }],
		note: `Übersicht: Deck mit offenem Composer${dark ? ', dunkel' : ''}`,
	})
}

/*
 * Zuordnung mit acht Paaren. Der Bestand hatte nur vier; ab sechs Zeilen
 * entscheidet sich, ob Beamer und Moderationsansicht das tragen. Gemessen wird
 * die Bühnenhöhe (der Kiosk clippt stumm) und die Vorschau im Moderator.
 */
async function matchPass(s, dark) {
	const types = probeData.types
	const match = probeData.match
	const tag = dark ? 'dark-' : ''

	// Umfrage-Modus: keine Lösung, Führung ist nur die Mehrheit.
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

	// Quiz-Modus: dieselben acht Paare mit Lösungsfarbe, dazu vier zum Vergleich.
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

	// Handy: acht Zeilen plus acht Ziele auf 390 px — der Ort, an dem die
	// Zuordnung als Erstes eng wird.
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

	// Moderationsansicht: Leinwand-Vorschau plus privates Panel, beide mit
	// derselben Frage — Laptop und Beamer-Breite.
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

/* ------------------------------------------------- Eigenes Tempo: Moderator */

/** Warten, bis der Text eines Elements passt; gibt die Wartezeit in ms zurück. */
async function waitForText(s, sel, re, timeout = 15000) {
	const start = Date.now()
	while (Date.now() - start < timeout) {
		const t = await s.script('const el = document.querySelector(arguments[0]); return el ? el.textContent : ""', [sel])
		if (re.test(t)) { return Date.now() - start }
		await sleep(200)
	}
	throw new Error(`Text blieb aus: ${sel} ~ ${re}`)
}

// Deck im eigenen Tempo: Kopf, Sperre, Statuszeile, Zeilen.
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
// Offenes Deck-Menü: Schalter mit Häkchen, deaktivierte Einträge mit Grund, Rotes.
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
// Öffnen-Dialog: gewählte Segmente, Relativzeile/Fehler, Hinweise, Fokus, Passform.
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
// Raumliste: Zustands-Chips im eigenen Tempo, nach Klasse und Text gezählt.
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
 * Ein geöffneter Raum startet seit Schritt 4.3a in der Laufansicht; ins Deck
 * geht es wie von Hand über deren Menü („Overview").
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
 * Eigenes Tempo, Moderator-Deck (Spezifikation §5.2 Schritt 4.2): Schalter im
 * Deck-Menü, Zustands- und Status-Chips, Öffnen-Dialog, gesperrtes Deck, eine
 * Frist, die abläuft, während das Deck offen ist, und die Raumliste. Seit der
 * Freischaltung (4.6) ohne ?pace=1 — der Schalter steht in jedem Quiz-Deck.
 *
 * Was der Pass an Räumen verstellt (Probelauf zurück in den Entwurf, Fristen,
 * Schluss, Freigabe), dreht er am Ende zurück: pace-run, pace-phone und
 * pace-screen brauchen die Räume im Ausgangszustand. Vor dem Zurücksetzen
 * eines Raums steht die Sitzung auf about:blank (Brute-Force, §6.4).
 */
async function paceModPass(s) {
	const P = paceData
	const deck = (code) => `/apps/pulse/room/${code}`
	const openMenu = { js: 'document.querySelector(".deck-head .pulse-menu > .pulse-btn").click()' }
	const escape = { js: 'document.activeElement.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }))' }
	// Wie per Tastatur: Fokus auf den Auslöser, dann auslösen — nur so ist
	// „Fokus zurück nach Escape" messbar (ein Skript-Klick fokussiert nicht).
	const primary = { js: 'const b = Array.from(document.querySelectorAll(".deck-head > .pulse-btn:not(.deck-back)")).pop(); b.focus(); b.click()' }
	const restore = []
	await login(s)
	try {
		// Entwurf: Statuszeile mit Anwesenden (Präsenz gilt 15 s — frisch setzen).
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

		// Öffnen-Dialog: Rennen (Vorgabe), Hausaufgabe mit Vorwahl, Fehler.
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
		// Zu früh (30 s): Inline-Fehler statt Relativzeile; Absenden bleibt lokal.
		const early = 'const d = new Date(Date.now() + 30000); const p = (v) => String(v).padStart(2, "0");'
			+ ' const el = document.querySelector(".pace-input");'
			+ ' el.value = d.getFullYear() + "-" + p(d.getMonth() + 1) + "-" + p(d.getDate()) + "T" + p(d.getHours()) + ":" + p(d.getMinutes());'
			+ ' el.dispatchEvent(new Event("input", { bubbles: true }))'
		await shot(s, {
			name: 'pace-dialog-error', size: LAPTOP,
			actions: [{ js: early }, { js: 'document.querySelector(".pod-submit").click()' }, { sleep: 500 }],
			measure: PACE_DIALOG, note: 'Öffnen-Dialog: Frist zu früh -> Inline-Fehler, nichts gesendet',
		})
		// Escape schließt, der Fokus geht zurück an „Open quiz …"; der Raum bleibt Entwurf.
		await s.script(escape.js)
		await sleep(400)
		const back = await s.script('const a = document.activeElement; return { dialog: !!document.querySelector(".pod"), focus: a ? (a.textContent.trim() || a.getAttribute("aria-label") || a.tagName) : "" }')
		const draftState = JSON.parse(probe('window', P.draft.code).replace(/^ok /, '')).state
		index.push(`| (Messung) | | ${deck('<CODE>')} | Dialog mit Escape zu: ${back.dialog ? 'NOCH OFFEN' : 'geschlossen'}, Fokus „${back.focus}", Raum ${draftState} |`)

		// Probelauf im Entwurf: Hinweis + „End practice run", Knopf „Open practice run".
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

		// Gesperrt, offen (Rennen ohne Frist): Hinweis „erst schließen".
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

		// Hausaufgabe (offen mit Frist, ohne Timer): Chip „Open until …", keine „· 30s".
		await shot(s, {
			name: 'pace-deck-homework', url: deck(P.homework.code), open: () => toDeck(s, deck(P.homework.code)), size: LAPTOP,
			waitSel: '.deck-lock-note', settle: 1500, measure: PACE_DECK,
			note: 'Deck offen (Hausaufgabe): Zustand „Open until …", Zeilen ohne Zeitlimit',
		})

		// Frist, während das Deck offen ist (mid): per Probe gesetzt -> Chip folgt
		// über den Abruf (≤ 10 s); läuft sie ab -> Chip und Menü sofort.
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
		// Wieder offen (Frist entfernt) und dann per Probe abgelaufen — beides
		// kommt nur über den Abruf an.
		probe('window', P.mid.code, 'closesAt=0')
		const tOpen = await waitForText(s, '.deck-state', /Open now/, 20000)
		probe('window', P.mid.code, 'closesAt=now-1')
		const tExpired = await waitForText(s, '.deck-state', /closed/i, 20000)
		const menuHint = await s.script('document.querySelector(".deck-head .pulse-menu > .pulse-btn").click(); return new Promise((r) => setTimeout(() => { const b = document.querySelector(\'.pulse-menu-item[data-key="pace"]\'); r(b ? (b.disabled ? "aus: " + b.textContent.trim().replace(/\\s+/g, " ") : "an") : "fehlt") }, 300))')
		await s.script(escape.js)
		index.push(`| (Messung) | | ${deck('<CODE>')} | Frist entfernt: „Open now" nach ${(tOpen / 1000).toFixed(1)} s; per Probe abgelaufen: „Quiz closed" nach ${(tExpired / 1000).toFixed(1)} s, Menü „Self-paced" ${menuHint} |`)
		probe('window', P.mid.code, 'closesAt=0')
		restore.pop()

		// Raumliste mit allen vier Zuständen: mid kurz geschlossen, wide kurz freigegeben.
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

/** Dunkel: Deck im Entwurf, Hausaufgaben-Dialog (Datumsfeld!) und gesperrtes Deck. */
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

/* ------------------------------------------ Eigenes Tempo: Laufansicht */

// Beamer-Vorschau der Laufansicht (§1.7): ein iframe mit der echten
// Beamer-Seite. Bereit ist sie erst, wenn dort die Seite steht und — solange
// der Raum offen ist und jemand gestartet hat — das Rennen, nicht die Lobby
// des ersten Renderns (Screen.vue raceView). Ohne Abbruch: was dann fehlt,
// steht in der Messzeile.
const PREVIEW_READY = `(() => {
	const f = document.querySelector('.spv-frame')
	const d = f && f.contentDocument
	if (!d || !d.querySelector('.scr')) { return false }
	const run = document.querySelector('.pace-run')
	const vm = run && run.__vue__
	return !(vm && vm.$options.name === 'PaceRun' && vm.state === 'open' && vm.startedCount > 0) || !!d.querySelector('.scr.is-race .pace-race')
})()`
// Was zeigt die Vorschau? Offen mit mindestens einem Start muss es das Rennen
// sein; sonst „KEIN RENNEN". Dazu die Bühne IM Rahmen: läuft sie dort über,
// schneidet der kleine Kasten genauso ab wie der Saal. Gewertet wird das nur,
// wenn der Kasten (teilweise) im Bild ist: außer Sicht drosselt Firefox
// requestAnimationFrame im iframe (gemessen 1,5 statt 60 Bilder/s), und das
// Einpassen der Beamer-Seite (Screen.vue fitPass) läuft über rAF — unten in
// der Seitenspalte ist es nach 1,5 s noch nicht fertig, im Bild nach 0,3 s.
// Ein Ausdruck, der die Laufansicht (.pace-run) nimmt und einen Satz liefert.
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
// Marken der Vorschau-Messung, die als Auffälligkeit zählen (letzte Indexzeile).
const PREVIEW_MARKS = ['KEIN RENNEN', 'LÄUFT ÜBER', 'NICHT geladen', 'UNLESBAR', 'NICHT IM BILD', 'KEINE VORSCHAU', 'WAAGERECHT']
function previewMarks() {
	const line = index[index.length - 1]
	for (const mark of PREVIEW_MARKS) {
		if (line.includes(mark)) { overflow.push(line.split(' | ')[0].slice(2) + ': Beamer-Vorschau ' + mark) }
	}
}

// Laufansicht: Kopf-Chips, Fakten, Statuszeile, Zähler, Tabelle, Seitenspalte
// (Bewertung, Beamer-Vorschau), Zeilenmenüs.
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
// Eigene Aufnahme der Beamer-Vorschau (§5.2 Schritt 4.5): der Kasten ganz im
// Bild (die Seitenspalte ist dafür gescrollt), darin das Rennen, die Bühne
// passt, und die Seite im Rahmen ist wirklich 1280×720 ohne waagerechtes Scrollen.
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
// Offenes Menü der Laufansicht: Schalter mit Häkchen, Rotes zuletzt.
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
// Bestätigung (PulseConfirm): Titel, Knöpfe, welcher gefüllt ist, und ob der Freitext-Satz dransteht.
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
// Fokus nach einem Dialog der Laufansicht: zurück am Menü-Auslöser (§1.5).
const FOCUS_TRIGGER = `
	const trig = document.querySelector('.pace-top .pulse-menu > .pulse-btn')
	const a = document.activeElement
	return { dialog: !!document.querySelector('.pod'), onTrigger: !!trig && a === trig,
		focus: a ? (a.getAttribute('aria-label') || a.textContent.trim().replace(/\\s+/g, ' ').slice(0, 40) || a.tagName.toLowerCase()) : '' }
`
// Hauptaktion „Check %n answers": Fokus auf der Überschrift der Bewertung, Kasten sichtbar.
const FOCUS_GRADING = `
	const h = document.getElementById('pace-grade-head')
	if (!h) { return 'keine Bewertung' }
	const r = h.getBoundingClientRect()
	return 'Fokus ' + (document.activeElement === h ? 'auf „' + h.textContent.trim() + '"' : 'NICHT auf der Bewertung')
		+ ', Überschrift ' + (r.top >= 0 && r.bottom <= window.innerHeight ? 'im Bild' : 'AUSSERHALB DES BILDS')
`
// Zusammenfassung aus der Laufansicht: Zurück-Knopf, Export-Beschriftung, Hinweis.
const SUMMARY_VIEW = `
	const v = document.querySelector('.summary-view')
	if (!v) { return null }
	const txt = (el) => (el ? el.textContent.trim().replace(/\\s+/g, ' ') : '')
	return 'Zusammenfassung: Zurück „' + txt(v.querySelector('.summary-back')) + '", Export „' + txt(v.querySelector('.export-btn')) + '"'
		+ (v.querySelector('.summary-warn') ? ', Hinweis „' + txt(v.querySelector('.summary-warn')) + '"' : ', kein Hinweis')
`
// /progress-Anfragen seit dem Einhängen mitschneiden (Resource Timing). Die
// Gegenlese-Abrufe des Prüfstands (harness=1) zählen nicht mit.
const PROGRESS_WATCH = `
	window.__prog = []
	new PerformanceObserver((list) => {
		for (const e of list.getEntries()) {
			if (/\\/progress(\\?|$)/.test(e.name) && !/harness=1/.test(e.name)) { window.__prog.push([e.startTime, e.responseEnd]) }
		}
	}).observe({ type: 'resource' })
	return true
`
// Überlappende Laufzeiten zählen: nie zwei /progress zugleich unterwegs.
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
// /pace-Aufrufe seit dem Einhängen zählen (Resource Timing). Mehrfach
// eingehängt zählt es trotzdem nur einmal: ein Beobachter je Seite.
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

/** Raumzeile aus GET /rooms (wie „Meine Räume") — der Server hat das letzte Wort. */
async function roomRow(s, code) {
	return s.async(`
		const done = arguments[arguments.length - 1]
		fetch(OC.generateUrl('/apps/pulse/api/1.0/rooms'), { credentials: 'same-origin', headers: { requesttoken: document.head.dataset.requesttoken } })
			.then((r) => r.json()).then((rows) => done(rows.find((r) => r.code === arguments[0]) || null))
			.catch((e) => done({ error: String(e) }))
	`, [code])
}

/** GET auf eine Pulse-Route aus der angemeldeten Seite (Gegenlesen, harness=1). */
async function pageGet(s, path) {
	return s.async(`
		const done = arguments[arguments.length - 1]
		fetch(OC.generateUrl(arguments[0]) + '?harness=1', { credentials: 'same-origin', headers: { requesttoken: document.head.dataset.requesttoken } })
			.then((r) => r.json()).then(done).catch((e) => done({ error: String(e) }))
	`, [path])
}

/** Lösungsschlüssel der Freitextfrage (Raum-JSON des Besitzers). */
async function textKey(s, code) {
	const room = await pageGet(s, `/apps/pulse/api/1.0/rooms/${code}`)
	const p = ((room && room.polls) || []).find((x) => x.type === 'text')
	const key = (p && p.answerKey) || {}
	return { accepted: key.accepted || [], rejected: key.rejected || [] }
}

/** Stand aus /progress: Namen und offene Freitexte (Summe der Gruppen). */
async function progressOf(s, code) {
	const p = await pageGet(s, `/apps/pulse/api/1.0/rooms/${code}/progress`)
	const players = (p && p.players) || []
	return { names: players.map((x) => x.nickname), pending: ((p && p.pendingAnswers) || []).reduce((a, x) => a + x.count, 0) }
}

/** Auf eine Datei im Download-Ordner warten (fertig = ohne .part). */
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

/** Downloads ohne Rückfrage in einen Ordner (CSV-Knöpfe der Laufansicht). */
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
 * Eigenes Tempo, Laufansicht (Spezifikation §5.2 Schritte 4.3a + 4.3b):
 * Rennen offen, Hausaufgabe mit verborgenen Punkten, „alle durch",
 * geschlossen (offene Freitexte -> „Check %n answers"), freigegeben,
 * Probelauf geschlossen — je 1440×900 und 1024×768. Hell dazu die Menüs, der
 * Verlängern-Dialog, der Wieder-öffnen-Dialog nach Fristablauf und die
 * Zusammenfassung mit „Back to progress". Zustände, die die Räume im
 * Ausgangszustand nicht haben, setzt die Probe kurz (`window`) und dreht sie
 * danach zurück: pace-phone und pace-screen brauchen sie wie angelegt.
 * Nichts hier ändert den Raum über die Oberfläche — geklickt wird nur auf
 * `click` (paceRunClicks). Keine Probe hier löscht oder setzt zurück,
 * about:blank ist deshalb nicht nötig (§6.4); die Beamer-Vorschau pollt nur.
 */
async function paceRunPass(s, dark) {
	const P = paceData
	const tag = dark ? 'dark-' : ''
	const run = (code) => `/apps/pulse/room/${code}`
	const ready = '.pace-run .pace-table, .pace-run .pace-empty'
	// Die Vorschau ist ein iframe: erst wenn dort die Beamer-Seite steht (offen mit
	// Startern: das Rennen), ist das Bild ehrlich.
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
			// Die Vorschau selbst (§5.2 Schritt 4.5: „zeigt jetzt das Rennen"). Bei
			// 1440×900 steht sie unter der Bewertungskarte — die Seitenspalte wird
			// bis zum Kasten gescrollt, sonst zeigt das Bild nur die Überschrift.
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
			// „Release results" aus dem offenen Fenster schließt zugleich: eigene
			// Bestätigung mit der Zahl der Gestoppten. Nur ansehen, dann abbrechen.
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
			// „Change the end …": Frist vorbelegt, Knopf „Save"; Escape -> Fokus am Menü-Auslöser.
			await shot(s, {
				name: 'pace-run-extend-dialog', size: LAPTOP, actions: [menu, menuOpen, item('extend'), { wait: '.pod' }, { sleep: 500 }],
				measure: PACE_DIALOG, note: 'Laufansicht: „Change the end …" (offen, Frist vorbelegt)',
			})
			await s.script(escape)
			await sleep(400)
			const f1 = await s.script(FOCUS_TRIGGER)
			index.push(`| (Messung) | | ${run('<CODE>')} | Verlängern-Dialog mit Escape zu: ${f1.dialog ? 'NOCH OFFEN' : 'geschlossen'}, Fokus ${f1.onTrigger ? 'am Menü-Auslöser' : 'NICHT am Menü-Auslöser („' + f1.focus + '")'} |`)
			if (f1.dialog || !f1.onTrigger) { overflow.push('Verlängern-Dialog: Fokus nach Escape nicht am Menü-Auslöser') }

			// Zusammenfassung aus der Laufansicht (offen: Warnung), zurück mit „Back to progress".
			await shot(s, {
				name: 'pace-run-summary', size: LAPTOP, actions: [menu, menuOpen, item('summary'), { wait: '.summary-view .summary-back' }, { sleep: 800 }],
				measure: SUMMARY_VIEW, note: 'Zusammenfassung aus der Laufansicht (Hausaufgabe offen): Warnung, „Results per question (CSV)", „Back to progress"',
			})
			const t0 = Date.now()
			await s.script('document.querySelector(".summary-view .summary-back").click()')
			await waitFor(s, ready)
			index.push(`| (Messung) | | ${run('<CODE>')} | Zusammenfassung -> „Back to progress": Laufansicht nach ${((Date.now() - t0) / 1000).toFixed(1)} s wieder da |`)
			// „Close quiz“ mit Frist: EIN /pace-Aufruf (close {release:false}), kein
			// Raum-Abruf mehr davor; die Frist bleibt, nichts freigegeben.
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

		// „Alle durch": nur die Fertigen sind gerade da (Präsenz gilt 15 s — je Bild frisch).
		// Ein Rennraum, in dem jemand fertig ist und jemand noch arbeitet.
		const thr = ['race', 'mid', 'wide'].find((k) => P[k] && P[k].race && P[k].race.finished > 0 && P[k].race.started > P[k].race.finished) || 'race'
		await both('through', P[thr].code, 'Laufansicht: alle Anwesenden durch -> Statuszeile „Everyone still here is through"', {
			open: async () => {
				probe('online', P[thr].code, 'finished')
				await s.go(HOST + run(P[thr].code))
				await waitForText(s, '.pace-status', /Everyone still here/, 12000)
			},
		})

		// Geschlossen (Hausaufgabe, Punkte weiter verborgen): offene Freitexte
		// gehen vor der Freigabe -> „Check %n answers".
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

		// Nach Fristablauf (Hausaufgabe): „Reopen …" -> vorbelegt morgen + „Was: …".
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

		// Altlast: gestoppt wie vor close {release} (Frist 120 s nach dem Schluss) -> „Reopen …“ belegt „When I close it“ vor.
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

		// Freigegeben (Rennen): offene Freitexte bleiben Hauptaktion, darüber die Warnung.
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

		// Probelauf geschlossen -> „End practice run".
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
 * Klickstrecke auf `click` (nur dort, §6.1; Schritte 4.3a + 4.3b): bewerten ->
 * umbewerten -> entfernen -> sperren -> Ende ändern (Frist, dann zurück) ->
 * schließen („Stop without releasing“ = EIN /pace-Aufruf) -> „Check %n answers“
 * -> „Reopen …“ vorbelegt „When I close it“ (ohne localStorage-Merker) ->
 * freigeben (Menü) -> beide CSV. Jeder Schritt wird gegen den Server
 * gegengelesen (GET /rooms, /rooms/{code}, /progress). Dazu der
 * Einflug-Mitschnitt: nie zwei /progress zugleich — auch bei fünf schnellen
 * Aktionen (Hausaufgabe: „Show points" fünfmal hintereinander). `dl` ist der
 * Download-Ordner der Sitzung.
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

	// Fünf schnelle Aktionen: jede ruft refresh() — eine Anfrage läuft oft noch.
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

	// Der Seed von `click` kann mit einer einzigen offenen Freitext-Gruppe
	// enden — nach dem Bewerten wäre nichts mehr offen. Weitere Personen
	// (probe race, über die Services) bringen eine zweite, damit „Check %n
	// answers" und der Freitext-Satz auf der Strecke vorkommen.
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

	// 1. Bewerten: die erste offene Antwort als richtig.
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

	// 2. Umbewerten: derselbe Eintrag jetzt falsch (gleicher Endpunkt).
	await s.script('document.querySelector(".pace-checked .tg-no").click()')
	await waitFor(s, '.pace-checked .tg-row.is-rejected')
	const key2 = await textKey(s, code)
	note(`Umbewerten: „${first.sample}" jetzt falsch; Server: abgelehnt ${key2.rejected.includes(first.sample) ? 'enthält sie' : 'OHNE sie'}, akzeptiert ${key2.accepted.includes(first.sample) ? 'ENTHÄLT SIE NOCH' : 'ohne sie'}`)
	if (!key2.rejected.includes(first.sample) || key2.accepted.includes(first.sample)) { overflow.push('Umbewerten: Server-Stand passt nicht') }

	// 3. Entfernen: die letzte Zeile der Tabelle, über ihr Zeilenmenü.
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

	// 4. Sperren: Schalter im Menü (bleibt offen), Chip im Kopf.
	await openItem('lock')
	await waitForText(s, '.pace-top-row', /Joining locked/)
	await s.script(escape)
	const room4 = await pageGet(s, `/apps/pulse/api/1.0/rooms/${code}`)
	note(`Sperren: Chip „Joining locked"; Server: joinsLocked ${room4.joinsLocked}`)
	if (room4.joinsLocked !== true) { overflow.push('Sperren: joinsLocked nicht gesetzt') }

	// 5. Ende ändern: Frist „In 1 hour" speichern, dann zurück auf „When I close it".
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

	// 6. Schließen: „Close quiz" ohne Frist -> Bestätigung (mit Freitext-Satz) -> „Stop without releasing".
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

	// 6b. „Reopen …“ nach dem Stoppen: Frist 0 -> „When I close it“, ohne „Was: …“, Knopf „Reopen“; kein Merker. Escape.
	await openItem('reopen')
	await waitFor(s, '.pod')
	await sleep(300)
	const pre6b = await s.script('return { end: document.querySelector(".pod .pseg-item.is-active").textContent.trim(), was: !!document.querySelector(".pod-was"), submit: document.querySelector(".pod-submit").textContent.trim(), marker: window.localStorage.getItem("pulse-pace-stop:" + arguments[0]) }', [code])
	await s.script(escape)
	await sleep(300)
	note(`„Reopen …“ nach dem Stoppen: vorbelegt „${pre6b.end}“, ${pre6b.was ? 'MIT „Was: …“' : 'ohne „Was: …“'}, Knopf „${pre6b.submit}“; Merker ${pre6b.marker === null ? 'keiner' : 'GESCHRIEBEN'}`)
	if (pre6b.end !== 'When I close it' || pre6b.was || pre6b.submit !== 'Reopen' || pre6b.marker !== null) { overflow.push('„Reopen …“ nach dem Stoppen: Vorbelegung oder Merker passt nicht') }

	// 7. Freigeben über das Menü (die Hauptaktion ist die Bewertung).
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

	// 8. CSV im Browser (§6.2): Adresse wie src/util/csv.js csvUrl() (Route +
	// view + requesttoken in der Query) -> 200 + text/csv; ohne Token 412.
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
	// Die echten Knöpfe (Menü, downloadCsv): Datei im Download-Ordner, die Seite bleibt stehen.
	for (const [key, view] of [['csv-players', 'players'], ['csv-answers', 'answers']]) {
		await openItem(key)
		const file = await waitFile(dl, new RegExp(`-${view}\\.csv$`))
		const stay = await s.script('return !!document.querySelector(".pace-run") && /\\/room\\//.test(location.pathname)')
		const head = file ? readFileSync(join(dl, file), 'utf8').replace(/^﻿/, '').split('\n')[0].slice(0, 70) : ''
		note(`Knopf „${key}": ${file ? 'Datei ' + file.replace(/[A-Z0-9]{6}/, '<CODE>') + ', Kopf „' + head + '"' : 'KEINE DATEI'}, Seite ${stay ? 'bleibt stehen' : 'IST WEG'}`)
		if (!file || !stay) { overflow.push(`CSV-Knopf ${key}: ${file ? 'Datei da' : 'keine Datei'}, Seite ${stay ? 'da' : 'weg'}`) }
	}

	// Hauptfaden: längste Lücke zwischen 100-ms-Takten über 8 s (Sekundentakt + Abruf).
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

/* ------------------------------------------------ Eigenes Tempo: Handy */

// Vue-Instanz des Handys (Participant): zum Messen (Fensterzustand,
// Fortschritt, Urteil) und für Eingriffe, die die Oberfläche nicht anbietet
// (die späte Korrektur, §6.2 Schritt 1).
const PHONE_VM = `(() => {
	const r = document.querySelector('.pulse-part')
	const v = r && r.__vue__
	if (!v) { return null }
	return v.$options.name === 'Participant' ? v : (v.$children || []).find((c) => c.$options.name === 'Participant') || null
})()`

// Handy im eigenen Tempo (§6.2): Ansicht, Überschrift, Hinweise, Frist,
// Position, Korrekturleiste, Urteilsband, Knöpfe (■ gefüllt, ◦ tonal =
// is-tertiary, □ Umriss), Absende-Zone und Frage im Bild, Fokus, Ansage, Toast,
// waagerechtes Scrollen und das Leck — vor der Freigabe steht keine Lösung im DOM.
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

// Verlauf der Absende-Zone mitschreiben (Korrekturleiste → Band ohne Urteil →
// Urteil), ohne dass ein Zwischenstand zwischen zwei WebDriver-Abfragen verloren
// geht. Lesen: window.__paceVerdicts.
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
 * Doppeltipp (§2.5, §6.2 Schritt 1): „Next question" tippen, sofort an
 * derselben Stelle noch einmal, und ein drittes Mal, sobald die neue Frage
 * steht — jeweils auf das Element, das dort gerade liegt (elementFromPoint).
 * Ergebnis: was getroffen wurde und ob die neue Frage unbeantwortet blieb.
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

// Zeitablauf (§2.6, §6.2 Schritt 2): sobald Zweig 9 im DOM steht, SOFORT den
// Knopf lesen — deaktiviert mit „One moment …", bis der Server timeUp meldet.
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
 * Doppeltipp am Zeitablauf (Gate 4.4b): „Next question" steht in Zweig 9 mittig —
 * genau dort liegt danach eine Antwortkarte der nächsten Frage (Wahr/Falsch: B),
 * und Auswahl/Wahr-Falsch senden im Quiz sofort. Tippen, sofort noch einmal,
 * dann auf die neue Frage direkt nach dem Wechsel und 250 ms später (übliche
 * Doppeltipp-Spanne) — jeweils wie ein Finger auf den Knopf an dieser Stelle
 * (elementFromPoint → closest('button') → click(); ein gesperrter Knopf nimmt
 * keinen Klick an). Dazu einmal der Weg ohne DOM-Sperre (vm.pickOne, wie Tastatur),
 * solange die Sperre noch steht. Danach warten, bis die Karte an der Stelle wieder
 * bedienbar ist. Soll: Frage 2 unbeantwortet, kein /vote unterwegs.
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

// Leck-Prüfung am Draht: /state des Handys vor der Freigabe — keine Lösungsfelder,
// keine Verteilung, keine Rangliste (§2.5 „Nie die Lösung").
const STATE_LEAK = `
	const done = arguments[arguments.length - 1]
	fetch(location.pathname.replace(/\\/$/, '') + '/state', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
		.then((r) => r.json())
		.then((d) => done({ keys: d.poll ? Object.keys(d.poll).filter((k) => k === 'correctOption' || k === 'answerKey') : [],
			results: d.results, leaderboard: d.leaderboard, verdict: d.myResult ? d.myResult.verdict : null }))
		.catch((e) => done({ error: String(e) }))
`

/** Abstände der /state-Abrufe des Handys über ms Millisekunden (Takt §2.10). */
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
 * Handy in echter Größe. Firefox headless macht kein Fenster schmaler als 450
 * CSS-px (gemessen: 320 oder 390 angefordert -> innerWidth 450; devPixelsPerPx
 * ändert daran nichts) — die älteren Handy-Strecken laufen deshalb in Wahrheit
 * 450 px breit. Hier steht die Seite in einem iframe der Zielgröße: gleicher
 * Ursprung, dasselbe Cookie, Medienabfragen und svh nach 390 bzw. 320 px. Wirt
 * ist die Beitrittsseite ohne Code (sie pollt nicht). Danach laufen Skripte,
 * Klicks und Wartebedingungen IM iframe; eine Navigation (s.go) verlässt es.
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
 * Eigenes Tempo, Handy (Spezifikation §5.2 Schritte 4.4a + 4.4b, §6.2):
 * Namensbildschirm mit Frist und „Closes in", Start (auch 320 × 640, echte
 * Größe im iframe), der volle Durchlauf auf `e2e` (Frage → Korrekturleiste →
 * Urteil → Doppeltipp → „Change", 4 s warten, späte Korrektur → Neuladen →
 * Weiter-Karte → „Being checked" → „I’m done" → eigene Antworten → geschlossen
 * → Endstand → alle Ergebnisse), der Zeitablauf auf `timed` (deaktiviert →
 * aktiv, Doppeltipp auf die Wahr/Falsch-Frage 2), „Answer saved" und
 * Überspringen bis zum Ende auf `homework`, die Zuordnung mit acht Paaren bei
 * 320 × 640 auf `match8`, Warten und neue Runde auf `draft`, geschlossen mit
 * verlorener Eingabe auf `late`, entfernt auf `race`, vorbei auf `practice` —
 * dazu Abruftakt, Fokus und Leck-Prüfung.
 * Eine Sitzung = ein Cookie = ein Handy; wo ein zweites Handy nötig ist, nimmt
 * der Pass das Cookie kurz weg.
 *
 * Räume: e2e wird (hell) geschlossen — roh per `window closedAt=now`, denn
 * `close` gäbe ohne Frist sofort frei — und freigegeben; late ebenso; draft am
 * Ende zurückgesetzt (vorher about:blank, §6.4); practice kurz freigegeben und
 * per `window` wieder geöffnet (pace-screen gibt ihn selbst frei). race,
 * homework, timed und match8 bekommen nur zusätzliche Namen. Der dunkle
 * Durchgang läuft zuerst und ändert nichts außer Namen.
 */
async function pacePhonePass(s, dark) {
	const P = paceData
	const tag = dark ? 'dark-' : ''
	const url = (code) => `/apps/pulse/s/${code}`
	const card = (name) => `.pace-card[data-card="${name}"]`
	const note = (text) => index.push(`| (Messung${dark ? ', dunkel' : ''}) | | ${url('<CODE>')} | ${text} |`)
	// Eine Prüfung, die nicht stimmt, steht im Index UND bei den Auffälligkeiten.
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
	// Frage k ist offen: warten, bis „Skip question" scharf ist, dann tippen.
	const skipTo = async (k) => {
		await until(`vm.progress && vm.progress.k === ${k} && !!vm.poll && !vm.nextBusy`, 'Frage ' + k)
		await waitFor(s, '.pace-skip:not([disabled])', 5000)
		await s.click('.pace-skip')
	}

	// ── Namensbildschirm und Start ────────────────────────────────────────
	// Frist in zehn Minuten: Namensbildschirm und Startkarte zeigen „Closes in".
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

	// Rennen (Timer, Rückmeldung je Frage): Startkarte mit Timer-Hinweis, auch schmal.
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
		// Entfernt, dann neu geladen: der gemerkte Name (localStorage) trägt den Hinweis.
		const stored = await s.script('return window.localStorage.getItem("pulse-pace:" + arguments[0])', [P.race.code])
		probe('remove', P.race.code, racer)
		await phone({
			name: 'pace-phone-removed', url: url(P.race.code), waitSel: '.nick .pace-notice',
			note: 'Entfernt, dann neu geladen: Namensbildschirm mit „You were removed from this quiz."',
		})
		note('Gemerkter Name vor dem Entfernen: ' + (stored ? stored.replace(/"openedAt":\d+/, '"openedAt":…') : 'FEHLT'))
	}

	// ── Voller Durchlauf auf e2e (ohne Timer, Rückmeldung je Frage) ───────
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
	// Hell richtig (443), dunkel falsch (80).
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
	// „none" fehlt nur, wenn das Urteil ankommt, bevor die lokale Korrekturleiste endet.
	check(/Korrekturleiste( → none)? → (correct|wrong)$/.test(log1), 'Frage 1: Urteil ' + secs(tV) + ' nach dem Tipp, Verlauf ' + log1 + ' (vor dem Urteil neutral, kein grüner Blitz)')

	if (!dark) {
		// Doppeltipp: „Next question" → sofort an derselben Stelle erneut → Frage 2 bleibt unbeantwortet.
		const dt = await s.async(DOUBLE_TAP)
		check(!!dt && !dt.timeout && dt.k === 2 && dt.voted === false && dt.skipDisabled === true,
			'Doppeltipp „Next question": ' + (dt && dt.timeout ? dt.why : 'Frage ' + dt.k + ' unbeantwortet=' + !dt.voted + '; 2. Tipp auf ' + dt.h2 + ', 3. Tipp nach dem Wechsel (' + dt.ms + ' ms) auf ' + dt.h3 + '; „' + dt.skipText + '" ' + (dt.skipDisabled ? 'noch gesperrt' : 'SCHON SCHARF')))
		const tArm = await until(`${has('.pace-skip')} && !document.querySelector('.pace-skip').disabled`, '„Skip question" scharf', 5000)
		note('„Skip question" scharf ' + secs(tArm) + ' nach der Doppeltipp-Prüfung (gesperrt 1,5 s ab Fragewechsel)')
		await phone({
			name: 'pace-phone-q2', waitSel: '.h-app input[type="number"]',
			note: 'Frage 2 (Zahl) unbeantwortet: „Submit" in der Absende-Zone, „Skip question" (tonal) im Scrollbereich',
		})

		// Zahl absenden → „Change" → 4 s warten → tippen (§6.2 Schritt 1, Regeln a–c aus §2.1).
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
		// Regel (c): eine Korrektur, die erst nach dem Server-Fenster ankommt (langsames
		// Netz). Die Oberfläche bietet „Change" nach dem Fenster nicht mehr an —
		// deshalb über die Vue-Instanz, auf demselben Weg wie der Knopf.
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

		// Neuladen nach dem Antworten auf Frage 2: Eingabe und Band wieder da, kein „Change".
		await phoneAt(s, url(P.e2e.code), PHONE)
		await waitFor(s, '.pace-verdict')
		const re = await vm(`({ value: document.querySelector('.h-app input[type="number"]').value, verdict: document.querySelector('.pace-verdict').dataset.verdict, change: ${has('.fix-bar')}, next: !document.querySelector('.pace-next').disabled })`)
		await phone({ name: 'pace-phone-reload', waitSel: '.pace-verdict', note: 'Frage 2 nach dem Neuladen: Eingabe „9" und Band wieder da, kein „Change"' })
		check(re.value === '9' && re.verdict !== 'none' && !re.change && re.next, 'Neuladen auf Frage 2: ' + JSON.stringify(re))
		const leak = await s.async(STATE_LEAK)
		check(!!leak && !leak.error && leak.keys.length === 0 && leak.results === null && leak.leaderboard === null,
			'Leck-Prüfung /state auf Frage 2 (vor der Freigabe): ' + JSON.stringify(leak))

		// Abgebrochenes /next (offene Zeile geschlossen, nächste nicht gestartet): Weiter-Karte heilt.
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
		// Dunkel: weiter, Frage 2 überspringen (erst nach 1,5 s scharf).
		await s.click('.pace-next')
		await skipTo(2)
		await waitFor(s, '.h-app .free-vote input[type="text"]')
	}

	// Frage 3: Freitext, weder angenommen noch abgelehnt -> „Being checked".
	await s.script(VERDICT_LOG)
	await s.type('.h-app .free-vote input[type="text"]', 'X-Frame')
	await waitFor(s, '.h-submit .submit-btn:not([disabled])', 5000) // Antwortsperre nach dem Fragewechsel
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
		// Eigene Antworten vor der Freigabe (§2.8).
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

		// Geschlossen, noch nicht freigegeben: roh, denn `close` gäbe ohne Frist sofort frei.
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

	// ── Zeitablauf auf timed (zwei Fragen à 5 s, Timer) ──────────────────
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
		// Doppeltipp am Zeitablauf: der zweite Tipp landet auf Karte B der Wahr/Falsch-Frage 2 —
		// die Antwortsperre (paceHold, 0,6 s ab Fragewechsel) muss ihn schlucken.
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
		// ── Hausaufgabe (Frist, ohne Timer, Auflösung am Ende) ────────────
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

		// ── Zuordnung mit acht Paaren bei 320 × 640 (match8) ─────────────
		await join(P.match8.code, 'Max', [320, 640])
		await waitFor(s, card('start'))
		await s.click(card('start') + ' .submit')
		await waitFor(s, '.mrow-list .mrow')
		await phone({
			name: 'pace-phone-match8-320', size: [320, 640], waitSel: '.mrow-list',
			note: 'Zuordnung mit acht Paaren bei 320 × 640, unbeantwortet: Absende-Zone über der Falz, Frage sichtbar',
		})
		// Alle acht zuordnen — über dieselben Methoden wie das Auswahlblatt.
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

	// ── Warten im Entwurf mit Schwung-Zeile; hell danach zurückgesetzt -> neue Runde ──
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
	await s.go('about:blank') // §6.4: nie zurücksetzen, während eine Seite pollt
	probe('reset', P.draft.code)
	await phone({
		name: 'pace-phone-new-round', url: url(P.draft.code), waitSel: '.nick .pace-notice',
		note: 'Nach dem Zurücksetzen neu geladen: „A new round has started — choose your name."',
	})

	// ── Geschlossen mit Eingabe: Start, Text tippen, nicht senden, Frist vorbei ──
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

	// Zweites Handy ohne Namen (Cookie kurz weg): geschlossen -> kein Beitritt.
	const rika = await s.cookie('pulse_vt')
	await s.dropCookie('pulse_vt')
	await phone({
		name: 'pace-phone-shut', url: url(P.late.code), waitSel: card('shut'),
		note: 'Geschlossen, ohne Namen: kein Namensbildschirm, nur „The quiz is closed."',
	})
	await s.dropCookie('pulse_vt')
	await s.addCookie(rika)
	probe('release', P.late.code)
	await phone({
		name: 'pace-phone-released', url: url(P.late.code), waitSel: '.big-place',
		note: 'Freigegeben mit Rangliste (paceFinal): der bestehende Endstand mit eigenem Platz',
	})

	// ── Probelauf freigegeben: keine Rangliste -> Karte „Quiz finished" ──
	await join(P.practice.code, 'Pia')
	await waitFor(s, card('start'))
	await s.click(card('start') + ' .submit')
	await waitFor(s, '.h-app .choices .choice:not([disabled])') // Antwortsperre nach dem Fragewechsel
	await s.click('.choices .choice:nth-child(2)')
	await until('vm.myResult && vm.myResult.final', 'endgültiges Urteil im Probelauf')
	probe('close', P.practice.code) // ohne Frist: schließt und gibt frei
	try {
		await until('vm.paceCard === "over"', 'Karte „over"', 15000)
		await phone({
			name: 'pace-phone-over', waitSel: card('over'),
			note: 'Probelauf freigegeben (keine Rangliste): „Quiz finished", Punkte, „See all results"',
		})
		await s.click(card('over') + ' .review-cta')
		await waitFor(s, '.review')
		await phone({ name: 'pace-phone-over-review', waitSel: '.review', note: '„See all results" nach der Freigabe' })
		await s.dropCookie('pulse_vt')
		await phone({
			name: 'pace-phone-over-anon', url: url(P.practice.code), waitSel: card('over'),
			note: 'dasselbe ohne Namen: ohne Punkte, ohne Auswertung',
		})
	} finally {
		// pace-screen gibt den Probelauf selbst frei: wieder öffnen.
		probe('window', P.practice.code, 'closedAt=0', 'releasedAt=0')
	}
	await s.go('about:blank')
}

/*
 * Beamer im eigenen Tempo (§3, §6.2): Zustand, Kopf, Zeilen, Raster, rechte
 * Spalte, Meta-Leiste, waagerechter Überlauf (Seite, Bühne, Kiosk), Leck
 * (Fragetext oder Optionen irgendwo im Text) und der Kontrast der Rennzeilen
 * (Schrift auf Füllung / Schrift auf Spur, ≥ 4,5:1; Balken gegen Spur nur als
 * Zahl) und der Nebentexte (Meta-Chips, Legende, Kurz-URL, Fristpille — die
 * öffentliche Seite trägt data-themes="", Pulse-Dunkelwerte greifen dort
 * nicht). Großbuchstaben = Befund, die Strecke trägt ihn in die
 * Auffälligkeiten ein.
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
 * Eigenes Tempo, Beamer (Spezifikation §3, §5.2 Schritt 4.5): jeder Zustand
 * bei 1280×720 (PaceRun-Vorschau, PowerPoint-Add-in) und 1920×1080 — echte
 * Innenmaße, die Fensterleiste wird aufgeschlagen —, hell und dunkel. Der
 * geckodriver kennt nur eine Sitzung zugleich: je Zustand erst eine helle,
 * dann eine dunkle — so wird jeder Raumzustand nur einmal gesetzt.
 *
 * Räume (läuft zuletzt, §6.1): mid/wide/big und race werden per `window`
 * zurückdatiert (Beitritt in den ersten 2 min, danach die Spitze), homework
 * läuft per Frist ab, race wird roh geschlossen (`window closedAt=now` — ein
 * `close` ohne Frist gäbe sofort frei) und dann freigegeben, practice
 * freigegeben, draft geöffnet und zurückgesetzt (§6.4: jede Sitzung geht vor
 * dem Schließen auf about:blank, beim Zurücksetzen pollt also nichts mehr),
 * ohne Teilnehmende wieder geöffnet und freigegeben.
 */
async function paceScreenPass() {
	const P = paceData
	const url = (code) => `/apps/pulse/screen/${code}`
	// Eine Sitzung mit echten Innenmaßen 1280×720 und 1920×1080: die
	// Fensterleiste einmal messen und aufschlagen.
	const open = async (dark) => {
		const s = await newSession(dark)
		await s.size(DESKTOP)
		const chrome = DESKTOP[1] - Number(await s.script('return window.innerHeight'))
		s.kiosk = [[1280, 720], DESKTOP].map(([w, h]) => Object.assign([w, h + chrome], { css: w + '×' + h }))
		return s
	}
	// Vor dem Schließen weg von der Beamer-Seite (§6.4).
	const close = async (s) => { await s.go('about:blank').catch(() => {}); await s.close() }
	const note = (text) => index.push(`| (Messung) | | ${url('<CODE>')} | ${text} |`)
	const check = (ok, text) => {
		note((ok ? 'ok: ' : '**FEHLER:** ') + text)
		if (!ok) { overflow.push('pace-screen: ' + text) }
		return ok
	}
	const MARKS = ['RASTER', 'SPITZE ÜBER', 'NULLZEILE', 'RANGLISTE IM', 'KONTRAST UNTER', 'WAAGERECHTER', 'KIOSK LÄUFT', 'LECK', 'SUMME']
	// Ein Zustand: 1280×720 und 1920×1080, hell und dunkel. Präsenz vor jedem
	// Bild neu — sie gilt 15 s, vier Bilder dauern länger.
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

	// ── Lobby: Entwurf und „offen, noch niemand gestartet" ──────────────
	await all('draft', P.draft.code, 'Entwurf — Lobby „Not open yet" statt „Starting shortly"', { waitSel: lobby, present: 4 })
	probe('open', P.draft.code, String(2 * 86400))
	await all('open-idle', P.draft.code, 'offen mit Frist, noch niemand gestartet — Lobby „Join and start at your own pace." + „Open until …"', { waitSel: lobby, present: 3 })

	// ── Rennen n=6: erste 2 min Beitritt, danach die Spitze (nur Punkte > 0) ──
	probe('window', P.race.code, 'openedAt=now-30')
	await all('race-join', P.race.code, 'Rennen n=6 in den ersten 2 min — rechts der Beitrittsblock', { waitSel: race, present: 10 })
	probe('window', P.race.code, 'openedAt=now-600')
	await all('race-board', P.race.code, 'Rennen n=6 nach 2 min — rechts die Spitze (nur Punkte > 0)', { waitSel: race, present: 10 })

	// ── Hausaufgabe (Auflösung am Ende): nie eine Rangliste, Beitritt bleibt ──
	await all('homework', P.homework.code, 'Hausaufgabe (Frist, Auflösung am Ende) — Beitritt bleibt, Chip „Open until …"', { waitSel: race, present: 6 })

	// ── Viele Fragen: zwei Spalten (16, 20), Gruppen (24, 300 Beigetretene) ──
	for (const [key, what] of [['mid', 'n=16 — zwei Spalten à 8'], ['wide', 'n=20 — zwei Spalten à 10'],
		['big', 'n=24, 300 Beigetretene — Gruppen zu 3, „Not started"']]) {
		probe('window', P[key].code, 'openedAt=now-600')
		await all(key, P[key].code, what + ', rechts die Spitze', { waitSel: race, present: 12 })
	}

	// ── Frist läuft ab, während der Beamer steht: lokal geschlossen, der
	// nächste Abruf bestätigt — ohne Neuladen. ──
	probe('window', P.homework.code, 'closesAt=now+6')
	let waited = -1
	const sl = await open(false)
	try {
		await sl.size(sl.kiosk[0])
		await sl.go(HOST + url(P.homework.code))
		await waitFor(sl, race)
		waited = await waitForText(sl, '.scr-head', /The quiz is closed\./, 15000)
	} catch { /* steht im Befund */ } finally { await close(sl) }
	check(waited >= 0, `Frist läuft vor offenem Beamer ab → Kopf „The quiz is closed." nach ${waited >= 0 ? (waited / 1000).toFixed(1) + ' s' : '— blieb aus'}, ohne Neuladen`)
	await all('homework-expired', P.homework.code, 'Hausaufgabe nach Fristablauf — geschlossen (Auflösung am Ende)', { waitSel: race })

	// ── Geschlossen mit Rückmeldung je Frage: Zähler, KEINE Rangliste ──
	probe('window', P.race.code, 'closedAt=now')
	await all('closed', P.race.code, 'geschlossen, Rückmeldung je Frage — Zähler, keine Rangliste (der Server schickt sie mit)', { waitSel: race, present: 10 })

	// ── Freigegeben: Podium; Probelauf ohne Rangliste ──
	probe('release', P.race.code)
	await all('released', P.race.code, 'freigegeben — Endstand (Podium)', { waitSel: '.scr-final', settle: 6000 })
	probe('close', P.practice.code) // ohne Frist: schließt und gibt frei
	await all('practice-released', P.practice.code, 'Probelauf freigegeben — keine Rangliste: „Quiz finished"', { waitSel: race })

	// ── Freigegeben ohne Teilnehmende: [] → „No points yet" ──
	// Zurücksetzen = Brute-Force-Falle (§6.4): hier pollt keine Seite mehr —
	// jede Sitzung ist nach ihrem Zustand über about:blank geschlossen.
	probe('reset', P.draft.code)
	probe('open', P.draft.code, '0')
	await all('open-idle-race', P.draft.code, 'offen ohne Frist, niemand beigetreten — Lobby ohne Fristzeile', { waitSel: lobby })
	probe('close', P.draft.code) // ohne Frist: schließt und gibt frei
	await all('released-empty', P.draft.code, 'freigegeben ohne Teilnehmende — „Final standings" + „No points yet"', { waitSel: race })
}

let failed = false
try {
	await waitForDriver()
	// Beim Nachbessern einer einzelnen Strecke: PULSE_SHOTS_ONLY=moderator
	const only = process.env.PULSE_SHOTS_ONLY || ''
	const wanted = (part) => !only || only === part || (only === 'pace' && part.startsWith('pace-'))

	let s
	// Die Store-Strecke läuft allein: eigene Räume (probe.php store), eigene
	// Bildgröße, doppelte Pixeldichte. Mit den anderen Strecken gemischt
	// ergäbe sie keinen Sinn.
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
		// Nur die aussagekräftigen Bühnen — alles doppelt zu schießen bringt nichts.
		await shot(s, {
			name: 'dark-screen-lobby', url: `/apps/pulse/screen/${poll.code}`, size: DESKTOP,
			waitSel: '.scr', note: 'Beamer Lobby, dunkel',
		})
		probe('state', poll.code, String(poll.polls[0].id), 'open')
		await shot(s, {
			name: 'dark-screen-poll-choice-open', url: `/apps/pulse/screen/${poll.code}`, size: DESKTOP,
			waitSel: '.scr-stage', note: 'Beamer Multiple Choice offen, dunkel',
		})
		// Aufgelöst im Dunkeln: hier stehen die Prozentwerte, um deren Kontrast
		// es in D10 geht (Abnahme §5.11).
		probe('state', poll.code, String(poll.polls[0].id), 'locked')
		await shot(s, {
			name: 'dark-screen-poll-choice-locked', url: `/apps/pulse/screen/${poll.code}`, size: DESKTOP,
			waitSel: '.scr-stage', note: 'Beamer Multiple Choice aufgelöst, dunkel',
		})
		await shot(s, {
			name: 'dark-phone-poll-choice', url: `/apps/pulse/s/${poll.code}`, size: PHONE,
			waitSel: '.pulse-part', note: 'Handy Abstimmen, dunkel',
		})
		// Die Helligkeitsstaffel mischt gegen den Bühnengrund (§7.0) — im Dunkeln
		// läuft sie also in die andere Richtung und muss eigens geprüft werden.
		// Dasselbe gilt für die Nullstummel der Skala und das Bild auf seiner
		// Trägerfläche (§7.8).
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
			// Die CSV-Knöpfe laden herunter: in einen Wegwerf-Ordner, danach weg.
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
			// Dunkel zuerst: der helle Durchgang schließt `late` und setzt `draft` zurück.
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
			// Die Strecke öffnet und schließt ihre Sitzungen selbst (hell, dann
			// dunkel je Raumzustand).
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
