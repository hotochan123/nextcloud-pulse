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
//   pacedhide  self-paced quiz: the desk hides a free-text answer on the results overview, the end view after
//              the release and the results CSV follow, "Show" brings it back
//   longq      a question of 1000 characters shows whole in the desk's lists, on the phone and in its answers after
//              a self-paced quiz, and on the results overview (laptop and phone-sized)
//   histtap    scale 1–20 with 400 votes: on a zoomed phone (and desk) a tap on a column shows its value and votes
//              while the counts are left out; a laptop desk where they fit shows nothing
//   layout     desk and phone page on laptop, tablet and phone, light and dark: nothing runs past the screen
//   filter     filter before the projector with a fake AI provider: AI help switch and its phone notice, a
//              flagged word held back until "Show", "Hide" on the desk, a slow check going through after 10 s
//   ai         the AI quiz draft in the composer against a fake text-generation provider (dev/e2e/fakeai)
//   summary    the AI summary on the results overview (fake provider): numbers, key points, top 3, Save in Files
//   themes     "Group with AI" with the fake provider: themes on the desk first, on the projector after "Show
//              themes on projector" (in a quiz after the reveal), "Back to answers", a failing grouping
//   admin      the admin section "Pulse" (allow AI, daily limit) and the AI notice of a fresh account (fake provider)
//   grading    the AI grading suggestion with the fake provider: proposals per open free-text answer, nothing counts
//              before "Accept all", flipping one, the cross afterwards, a failing task, 44 px targets
//   projector  the projector page in windows of the size sweep (stress/sweep.mjs: 1280×720, a 4:3, a 21:9 and a
//              browser window size of this run's sample), light and dark, every question open and revealed,
//              leaderboard and race: nothing runs past the stage
//   stressPoll, stressQuiz, stressBoard
//              the stress catalog (stress/catalog.mjs, STRESS.md) on projector, desk and phone in the sizes of this
//              run's sample of the sweep (stress/sweep.mjs), light and dark: stored as written, nothing past the
//              screen or stage, no text over text, no line cut in part
//
// Output: a line "seed <n>" (the sample of the size sweep), then "ok <case>" or "FAIL <case>: <message>" per case,
// "known <id>: <finding>" for catalog cases marked open, then "E2E <ok>/<failed>". Exit 0 / 1.
// Knobs: PULSE_E2E_ONLY=a,b (cases), PULSE_E2E_REPEAT=n (each case n times), PULSE_E2E_TIMEOUT (s per case,
// default 180; the stress cases 420), PULSE_E2E_SEED=n (replays a sample), PULSE_E2E_SWEEP=all (every size for every
// catalog case), PULSE_E2E_CATALOG=S1.3,S2.2 (some catalog cases), PULSE_E2E_COLLECT=1 (catalog findings as "known",
// for fixing many at once), PULSE_E2E_TRACE=1 (time of each stress step on stderr),
// PULSE_E2E_SELFTEST=fail|overflow|stage|overlap (proves that a red case turns the run red).

import { writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { OUT, SELFTEST, URL_BASE, USER, World, api, expect, login } from './lib/harness.mjs'
import { compose } from './cases/compose.mjs'
import { poll } from './cases/poll.mjs'
import { quiz } from './cases/quiz.mjs'
import { paced } from './cases/paced.mjs'
import { pacedhide } from './cases/pacedhide.mjs'
import { longq } from './cases/longq.mjs'
import { histtap } from './cases/histtap.mjs'
import { layout } from './cases/layout.mjs'
import { projector } from './cases/projector.mjs'
import { filter } from './cases/filter.mjs'
import { ai } from './cases/ai.mjs'
import { summary } from './cases/summary.mjs'
import { themes } from './cases/themes.mjs'
import { admin } from './cases/admin.mjs'
import { grading } from './cases/grading.mjs'
import { KNOWN, PLAN, stressBoard, stressPoll, stressQuiz } from './cases/stress.mjs'

const CASES = { compose, poll, quiz, paced, pacedhide, longq, histtap, layout, projector, filter, ai, summary, themes, admin, grading, stressPoll, stressQuiz, stressBoard }
const only = (process.env.PULSE_E2E_ONLY || '').split(',').map((s) => s.trim()).filter(Boolean)
const repeat = Math.max(1, Number(process.env.PULSE_E2E_REPEAT) || 1)
const limit = Math.max(10, Number(process.env.PULSE_E2E_TIMEOUT) || 180) * 1000
console.log(`seed ${PLAN.seed}`)

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
		// a case may need more time than the default (fn.limit, in s), never less than PULSE_E2E_TIMEOUT
		const caseLimit = Math.max(limit, (fn.limit || 0) * 1000)
		const started = Date.now()
		let error = null
		let timer
		try {
			await Promise.race([
				(async () => {
					await fn(world, shared)
					if (SELFTEST === 'fail' && name === 'compose') expect(false, 'self-test: this case is meant to fail')
				})(),
				new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(`case took longer than ${caseLimit / 1000} s`)), caseLimit) }),
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
		for (const k of KNOWN.splice(0)) console.log(`known ${k}`)
		await world.close()
	}
}
for (const code of shared.leftover || []) await api('DELETE', '/' + code).catch(() => {})
await browser.close()
writeFileSync(join(OUT, 'report.json'), JSON.stringify(report, null, '\t') + '\n')
console.log(`E2E ${ok}/${failed}`)
process.exit(failed ? 1 : 0)
