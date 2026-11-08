// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// What a person does on the three faces of Pulse: compose a question in the moderator's composer, press the
// moderator's primary action, join and answer on a phone. Selectors are roles, texts and the components' own
// classes; the app has no test-only attributes.

import { APP, CheckError, appApi, expect, until } from './harness.mjs'

// Every question type, the poll's five (scale in its three modes) and the quiz's seven. `right` and `wrong`
// say how a phone answers in the quiz; the poll answers are in POLL_ANSWERS.
export const POLL_QS = [
	{ type: 'choice', question: 'Favourite colour?', options: ['Red', 'Green', 'Blue'] },
	{ type: 'words', question: 'One word for today', maxWords: 3 },
	{ type: 'scale', question: 'How happy are you?', scaleMode: 'single', scaleMax: 5 },
	{ type: 'scale', question: 'Team profile', scaleMode: 'spectrum', scaleMax: 5, aspects: [{ label: 'Pace' }, { label: 'Quality' }, { label: 'Fun' }] },
	{ type: 'scale', question: 'Where do you stand?', scaleMode: 'compass', range: 5, axisX: { title: 'Speed', poleLow: 'slow', poleHigh: 'fast' }, axisY: { title: 'Focus', poleLow: 'broad', poleHigh: 'narrow' } },
	{ type: 'rank', question: 'Priorities', options: ['Cost', 'Time', 'Quality'] },
	{ type: 'match', question: 'Capitals', pairs: [{ left: 'France', right: 'Paris' }, { left: 'Italy', right: 'Rome' }] },
]

export const QUIZ_QS = [
	{ type: 'choice', question: 'Capital of France?', options: ['Berlin', 'Paris', 'Rome'], correctIndex: 1, timeLimit: 60 },
	{ type: 'truefalse', question: 'The earth is flat.', correctIndex: 1, timeLimit: 60 },
	{ type: 'multi', question: 'Prime numbers?', options: ['2', '4', '5'], correctIndexes: [0, 2], timeLimit: 60 },
	{ type: 'number', question: 'Moon landing (year)?', target: 1969, tolerance: 1, timeLimit: 60 },
	{ type: 'text', question: 'Largest planet?', answers: ['Jupiter'], timeLimit: 60 },
	{ type: 'rank', question: 'Sort by size, small first', options: ['Mouse', 'Cat', 'Horse'], timeLimit: 60 },
	{ type: 'match', question: 'Country to capital', pairs: [{ left: 'Germany', right: 'Berlin' }, { left: 'Austria', right: 'Vienna' }], timeLimit: 60 },
]

export const TYPE_LABEL = {
	choice: 'Multiple choice', words: 'Word cloud', scale: 'Scale', rank: 'Ranking', match: 'Matching',
	truefalse: 'True/False', multi: 'Multiple answers', number: 'Number guess', text: 'Free text',
}
const SCALE_LABEL = { single: 'Single', spectrum: 'Spectrum', compass: 'Compass' }

/** A tap on touch devices, a click elsewhere. */
export async function press(locator) {
	if (locator.page().e2eTouch) await locator.tap()
	else await locator.click()
}

// ───────────────────────── moderator ─────────────────────────

/** Start screen → a new room of the mode; returns its code (from the address the desk switches to). */
export async function startRoom(page, mode) {
	await page.goto(APP + '/')
	await page.getByRole('radiogroup', { name: 'Choose mode' }).getByRole('radio', { name: mode === 'quiz' ? 'Quiz' : 'Poll' }).click()
	await page.getByRole('button', { name: mode === 'quiz' ? 'Start quiz' : 'Start poll' }).click()
	await page.waitForURL(/\/room\/[A-Za-z0-9]{6}/)
	return page.url().match(/\/room\/([A-Za-z0-9]{6})/)[1]
}

/** Fills the composer for one question and adds it to the deck. */
export async function composeQuestion(page, q, isQuiz) {
	const composer = page.locator('.composer')
	if (!(await composer.isVisible())) {
		const first = page.getByRole('button', { name: 'Add the first question' })
		await (await first.isVisible() ? first : page.getByRole('button', { name: '+ Add question' })).click()
	}
	await composer.getByRole('radiogroup', { name: 'Question type' }).getByRole('radio', { name: TYPE_LABEL[q.type], exact: true }).click()
	await composer.getByPlaceholder('Your question …').fill(q.question)
	const addRows = async (button, want) => {
		const rows = composer.locator('.opt-row, .pair-row')
		while (await rows.count() < want) await composer.getByRole('button', { name: button, exact: true }).click()
	}
	switch (q.type) {
	case 'choice': case 'multi': case 'rank': {
		await addRows('Option', q.options.length)
		const inputs = composer.locator('.opt-row input.pinput')
		for (let i = 0; i < q.options.length; i++) await inputs.nth(i).fill(q.options[i])
		if (isQuiz && q.type === 'choice') await composer.locator('.opt-correct').nth(q.correctIndex).click()
		if (isQuiz && q.type === 'multi') for (const i of q.correctIndexes) await composer.locator('.opt-correct').nth(i).click()
		break
	}
	case 'match': {
		await addRows('Pair', q.pairs.length)
		for (let i = 0; i < q.pairs.length; i++) {
			await composer.getByPlaceholder(`Item ${i + 1}`, { exact: true }).fill(q.pairs[i].left)
			await composer.getByPlaceholder(`Belongs to ${i + 1}`, { exact: true }).fill(q.pairs[i].right)
		}
		break
	}
	case 'truefalse':
		await composer.locator('.tf-pick').getByRole('button', { name: q.correctIndex === 0 ? 'True' : 'False', exact: true }).click()
		break
	case 'number':
		await composer.getByPlaceholder('e.g. 1969').fill(String(q.target))
		await composer.locator('.num-fields input').nth(1).fill(String(q.tolerance || 0))
		break
	case 'text':
		await composer.getByPlaceholder('Correct answer', { exact: true }).fill(q.answers[0])
		break
	case 'words':
		break
	case 'scale':
		await composer.getByRole('radiogroup', { name: 'Scale mode' }).getByRole('radio', { name: SCALE_LABEL[q.scaleMode] }).click()
		if (q.scaleMode === 'spectrum') {
			const names = composer.getByPlaceholder('Aspect name (e.g. pace)')
			for (let i = 0; i < q.aspects.length; i++) await names.nth(i).fill(q.aspects[i].label)
		} else if (q.scaleMode === 'compass') {
			await composer.getByPlaceholder('Title of the X axis (e.g. practical ↔ theoretical)').fill(q.axisX.title)
			await composer.getByPlaceholder('Pole on the left (−)').fill(q.axisX.poleLow)
			await composer.getByPlaceholder('Pole on the right (+)').fill(q.axisX.poleHigh)
			await composer.getByPlaceholder('Title of the Y axis (e.g. alone ↔ together)').fill(q.axisY.title)
			await composer.getByPlaceholder('Pole at the bottom (−)').fill(q.axisY.poleLow)
			await composer.getByPlaceholder('Pole at the top (+)').fill(q.axisY.poleHigh)
		}
		break
	}
	if (isQuiz) await composer.locator('.quiz-time select').selectOption(String(q.timeLimit))
	const before = await page.locator('.deck-item').count()
	await composer.getByRole('button', { name: 'Add to the deck' }).click()
	await until(async () => (await page.locator('.deck-item').count()) === before + 1, `"${q.question}" in the deck`)
}

/** The moderator's one filled button: waits until it carries `label`, then presses it. */
export async function primary(page, label) {
	const btn = page.locator('.mod-primary')
	await until(async () => (await btn.innerText()).trim() === label && await btn.isEnabled(), `primary action "${label}"`, 20000)
	await btn.click()
}

/** Closes the join panel of the live view, which opens by itself while nobody is connected. */
export async function closeJoinPanel(page) {
	const panel = page.getByRole('dialog', { name: 'Join in' })
	if (await panel.isVisible()) await panel.getByRole('button', { name: 'Close' }).click()
}

export async function menu(page, item) {
	await page.locator('.mod-top').getByRole('button', { name: 'More actions' }).click()
	await page.getByRole('menu').getByRole('menuitem', { name: item, exact: true }).click()
}

// ───────────────────────── phone ─────────────────────────

/** The join page: types the code into the six fields and joins. */
export async function joinByCode(page, code) {
	await page.goto(APP + '/join')
	await page.locator('.digits input').first().click()
	await page.keyboard.type(code)
	await press(page.locator('button.submit', { hasText: 'Join' }))
	await page.waitForURL(new RegExp('/s/' + code))
}

/** Quiz: the name step. */
export async function pickName(page, name) {
	await page.locator('.nick-input').fill(name)
	await press(page.locator('.nick button.submit'))
}

export async function waitQuestion(page, q) {
	await until(async () => (await page.locator('h1.q').first().innerText().catch(() => '')).trim() === q.question, `${page.e2eLabel} shows "${q.question}"`, 25000)
}

const submit = (page) => press(page.locator('.h-submit .submit-btn'))

async function choose(page, label) {
	const btn = page.locator('.choices .choice').filter({ has: page.locator('.choice-label', { hasText: exactly(label) }) })
	// the self-paced phone keeps the answers disabled for a moment after a new question (double-tap guard)
	await until(() => btn.isEnabled(), `"${label}" can be tapped`, 5000, 100)
	await press(btn)
}

const exactly = (text) => new RegExp('^' + text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$')

/** Brings the ranking list into `order` (labels, top first) with the ↑ buttons. */
async function rankInto(page, order) {
	for (let target = 0; target < order.length; target++) {
		for (let guard = 0; guard < 10; guard++) {
			const labels = await page.locator('.rank-label').allInnerTexts()
			const at = labels.map((s) => s.trim()).indexOf(order[target])
			if (at < 0) throw new CheckError(`ranking has no "${order[target]}"`)
			if (at === target) break
			await press(page.getByRole('button', { name: `${order[target]} up` }))
		}
	}
}

async function matchInto(page, map) {
	for (const [left, right] of Object.entries(map)) {
		const row = page.locator('.mrow').filter({ has: page.locator('.mrow-cat', { hasText: exactly(left) }) })
		// a tap right after a new self-paced question can go unheard: tap again until the sheet is open
		await until(async () => {
			if (await page.locator('.msheet').isVisible()) return true
			await press(row)
			return page.locator('.msheet').isVisible()
		}, `picker sheet for "${left}"`, 8000, 400)
		await press(page.locator('.msheet .msheet-opt').filter({ hasText: right }))
		await until(async () => !(await page.locator('.msheet').isVisible()), `picker sheet for "${left}" closes`, 5000, 100)
	}
}

/** Where a pointer gesture on a range input lands for `value` of min..max. */
function rangePoint(box, min, max, value) {
	const thumb = 22
	return { x: thumb / 2 + (box.width - thumb) * (value - min) / (max - min), y: box.height / 2 }
}

/** Sets a range input by pointer, the way a finger does (tap where the value sits). */
async function slide(page, locator, value) {
	const [min, max] = await locator.evaluate((el) => [Number(el.min), Number(el.max)])
	const box = await locator.boundingBox()
	const p = rangePoint(box, min, max, value)
	if (page.e2eTouch) await locator.tap({ position: p })
	else await locator.click({ position: p })
	// no fallback: a finger that does not reach the value is exactly what this has to show
	await until(async () => Number(await locator.inputValue()) === value, `${page.e2eLabel}: a ${page.e2eTouch ? 'tap' : 'click'} puts the slider at ${value}`, 3000)
}

/*
 * Answers the question on the phone. Poll answers come from `answer` (see POLL_ANSWERS); quiz answers are
 * right or wrong. Ends when the phone shows "Answer sent" (or, in the self-paced quiz, its verdict).
 */
export async function answer(page, q, answerSpec, { quiz = false, paced = false } = {}) {
	const right = answerSpec === true
	switch (q.type) {
	case 'choice': case 'truefalse': {
		const opts = q.type === 'truefalse' ? ['True', 'False'] : q.options
		const label = quiz ? opts[right ? q.correctIndex : (q.correctIndex + 1) % opts.length] : answerSpec
		await choose(page, label)
		if (!quiz) await submit(page)
		break
	}
	case 'multi': {
		const picks = right ? q.correctIndexes.map((i) => q.options[i]) : [q.options[1]]
		for (const l of picks) await choose(page, l)
		await submit(page)
		break
	}
	case 'number':
		await page.locator('.free-vote input').fill(String(right ? q.target : q.target - 30))
		await submit(page)
		break
	case 'text':
		await page.locator('.free-vote input').fill(typeof answerSpec === 'string' ? answerSpec : right ? q.answers[0] : 'Saturn')
		await submit(page)
		break
	case 'words':
		await page.locator('.words input').first().fill(answerSpec)
		await submit(page)
		break
	case 'rank':
		await rankInto(page, quiz ? (right ? q.options : q.options.slice().reverse()) : answerSpec)
		await submit(page)
		break
	case 'match': {
		let map = answerSpec
		if (quiz) {
			const rights = q.pairs.map((p) => p.right)
			map = Object.fromEntries(q.pairs.map((p, i) => [p.left, right ? p.right : rights[(i + 1) % rights.length]]))
		}
		await matchInto(page, map)
		await submit(page)
		break
	}
	case 'scale':
		if (q.scaleMode === 'single') {
			await slide(page, page.locator('.scale-range.is-uni'), answerSpec)
		} else if (q.scaleMode === 'spectrum') {
			await slide(page, page.locator('.scale-range.is-bip').first(), answerSpec)
		} else {
			// the 2-D pad: a pointer gesture at a fraction of the field (x right, y up)
			const pad = page.locator('.pad')
			const box = await pad.boundingBox()
			const pos = { x: box.width * answerSpec.fx, y: box.height * (1 - answerSpec.fy) }
			if (page.e2eTouch) await pad.tap({ position: pos })
			else await pad.click({ position: pos })
		}
		await submit(page)
		break
	}
	if (paced) {
		await until(async () => {
			const v = await page.locator('.pace-verdict').getAttribute('data-verdict').catch(() => null)
			return v && v !== 'none' && v
		}, `${page.e2eLabel} verdict on "${q.question}"`)
		return
	}
	await until(async () => (await page.locator('.h-submit .band.is-ok, .h-submit .fix-bar').count()) > 0, `${page.e2eLabel} sent "${q.question}"`)
}

export function expectEqual(actual, wanted, label) {
	expect(JSON.stringify(actual) === JSON.stringify(wanted), label, { actual, wanted })
}

/**
 * The test account confirms the AI notice over the API, so a case about an AI feature is not stopped by it
 * (the case admin shows the notice itself, with a fresh account).
 */
export async function confirmAiNotice() {
	await appApi('POST', '/api/1.0/ai/notice')
}
