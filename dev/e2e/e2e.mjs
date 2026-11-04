#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Browser tests of Pulse. Started by dev/e2e/run.sh inside the Playwright container; see dev/e2e/README.md.
//
//   compose    the moderator composes a poll and a quiz with every question type in the composer
//   poll       the poll runs live: two phones join and answer every type, one changes its vote, reveal
//   quiz       the quiz runs live: names, right and wrong answers, free text graded on the desk, reveal,
//              leaderboard, final standings, CSV export
//   paced      the quiz self-paced (race): a phone plays through and gets its verdict after each question
//   layout     desk and phone page on laptop, tablet and phone, light and dark: nothing runs past the screen
//   projector  the projector page at 1920×1080 and 1366×768, light and dark, every question open and
//              revealed, leaderboard and race: nothing runs past the 16:9 stage
//
// Output: "ok <case>" or "FAIL <case>: <message>" per case, then "E2E <ok>/<failed>". Exit 0 / 1.
// Knobs: PULSE_E2E_ONLY=a,b (cases), PULSE_E2E_REPEAT=n (each case n times), PULSE_E2E_TIMEOUT (s per case,
// default 180), PULSE_E2E_SELFTEST=fail|overflow|stage (proves that a red case turns the run red).

import { writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { OUT, SELFTEST, URL_BASE, USER, World, api, expect, login } from './lib/harness.mjs'
import { compose } from './cases/compose.mjs'
import { poll } from './cases/poll.mjs'
import { quiz } from './cases/quiz.mjs'
import { paced } from './cases/paced.mjs'
import { layout } from './cases/layout.mjs'
import { projector } from './cases/projector.mjs'

const CASES = { compose, poll, quiz, paced, layout, projector }
const only = (process.env.PULSE_E2E_ONLY || '').split(',').map((s) => s.trim()).filter(Boolean)
const repeat = Math.max(1, Number(process.env.PULSE_E2E_REPEAT) || 1)
const limit = Math.max(10, Number(process.env.PULSE_E2E_TIMEOUT) || 180) * 1000

if (!URL_BASE || !USER) {
	console.error('PULSE_E2E_URL and PULSE_E2E_USER are not set: start dev/e2e/run.sh, not this file')
	process.exit(2)
}
for (const name of only) {
	if (!CASES[name]) {
		console.error(`unknown case "${name}" (cases: ${Object.keys(CASES).join(', ')})`)
		process.exit(2)
	}
}

const { chromium } = await import(process.env.PULSE_E2E_PW || 'playwright-core')
let browser
try {
	browser = await chromium.launch()
} catch (e) {
	console.error('chromium did not start: ' + e.message.split('\n')[0])
	process.exit(2)
}
let auth
try {
	auth = await login(browser)
} catch (e) {
	console.error('login failed: ' + e.message.split('\n')[0])
	await browser.close()
	process.exit(2)
}

// What one case leaves for a later one (compose → poll/quiz: the rooms composed in the browser)
const shared = {}
const report = []
let ok = 0
let failed = 0
for (const [name, fn] of Object.entries(CASES)) {
	if (only.length && !only.includes(name)) continue
	for (let run = 1; run <= repeat; run++) {
		const label = repeat > 1 ? `${name}#${run}` : name
		const world = new World(browser, label, auth)
		const started = Date.now()
		let error = null
		let timer
		try {
			await Promise.race([
				(async () => {
					await fn(world, shared)
					if (SELFTEST === 'fail' && name === 'compose') expect(false, 'self-test: this case is meant to fail')
				})(),
				new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(`case took longer than ${limit / 1000} s`)), limit) }),
			])
		} catch (e) {
			error = e
		} finally {
			clearTimeout(timer)
		}
		const secs = Math.round((Date.now() - started) / 1000)
		if (error) {
			failed++
			const msg = String(error.message || error).split('\n')[0]
			console.log(`FAIL ${label}: ${msg}`)
			await world.screenshots()
			const errs = world.pages.flatMap((p) => p.e2eErrors.map((m) => `${p.e2eLabel}: ${m}`))
			report.push({ case: label, ok: false, secs, message: String(error.stack || error), pageErrors: errs })
		} else {
			ok++
			console.log(`ok ${label} (${secs} s)`)
			report.push({ case: label, ok: true, secs })
		}
		await world.close()
	}
}
for (const code of shared.leftover || []) await api('DELETE', '/' + code).catch(() => {})
await browser.close()
writeFileSync(join(OUT, 'report.json'), JSON.stringify(report, null, '\t') + '\n')
console.log(`E2E ${ok}/${failed}`)
process.exit(failed ? 1 : 0)
