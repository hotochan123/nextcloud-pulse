// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// admin: the admin section "Pulse" (with its note on AI quality) and the AI notice, with the fake provider of dev/e2e/fakeai. The test account
// (an admin in run.sh's stack) switches "Allow AI in Pulse" off and sets a daily limit; after a reload both stay, a
// quiz's desk has no AI button and no "AI help", and switched on again both are back. A fresh account sees the
// notice before its first AI use: "Cancel" opens nothing, "Understood" opens the draft, and neither the next draft
// nor "AI help" asks again. With the limit at 0 the draft says the day's requests are used up. Needs the stack of
// run.sh (an admin account and the fake provider); the settings are put back at the end.

import { randomBytes } from 'node:crypto'
import { APP, URL_BASE, USER, PASS, appApi, expect, expectLayout, until } from '../lib/harness.mjs'
import { expectEqual, startRoom } from '../lib/flows.mjs'

const AI_BUTTON = { name: 'Draft questions with AI' }
const NOTICE = { name: 'Before you use AI' }
// the note on AI quality below the switch, shown whether AI is on or off
const QUALITY = /^AI results depend on your model\. .*Mistral Small 4 \(via Infomaniak\)/

async function ocs(method, path, body) {
	const r = await fetch(URL_BASE + '/ocs/v2.php' + path, {
		method,
		headers: {
			Authorization: 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64'),
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
			Accept: 'application/json',
		},
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	if (r.status >= 300) throw new Error(`OCS ${method} ${path} -> ${r.status} ${(await r.text()).slice(0, 200)}`)
}

async function loginAs(world, user, pass) {
	const page = await world.page('tablet', 'dark', { label: 'fresh-' + user })
	await page.goto(URL_BASE + '/index.php/login')
	await page.locator('#user').fill(user)
	await page.locator('#password').fill(pass)
	await page.locator('#password').press('Enter')
	await page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 })
	return page
}

async function openComposer(page) {
	const composer = page.locator('.composer')
	const opener = page.getByRole('button', { name: 'Add the first question' }).or(page.getByRole('button', { name: '+ Add question' }))
	await composer.or(opener).first().waitFor()
	if (!(await composer.isVisible())) await opener.first().click()
	await composer.waitFor()
}

// The room menu's entries (deck header, or the live view's top bar).
async function menuEntries(page) {
	await page.locator('.deck-head, .mod-top').first().getByRole('button', { name: 'More actions' }).click()
	const menu = page.getByRole('menu')
	await menu.locator('[role^="menuitem"]').first().waitFor()
	const names = (await menu.locator('[role^="menuitem"]').allInnerTexts()).map((s) => s.trim())
	await page.keyboard.press('Escape')
	return names
}

export async function admin(world) {
	const avail = await appApi('GET', '/api/1.0/ai')
	expect(avail.available === true, 'a text-generation provider is set up (dev/e2e/fakeai enabled by run.sh)', avail)
	const fresh = 'e2e' + randomBytes(4).toString('hex')
	const freshPass = 'Pw-' + randomBytes(12).toString('hex')
	let created = false
	try {
		// ── the admin section ──
		const settings = await world.page('laptop', 'dark', { moderator: true, label: 'admin' })
		await settings.goto(URL_BASE + '/index.php/settings/admin/pulse')
		const allow = settings.getByRole('checkbox', { name: 'Allow AI in Pulse' })
		const limit = settings.getByRole('spinbutton', { name: 'AI requests per account and day' })
		await allow.waitFor()
		await expectLayout(settings, '.pulse-admin', 'admin section')
		expect(await allow.isChecked(), 'AI is allowed by default')
		expect(await settings.locator('.pulse-admin-help').filter({ hasText: QUALITY }).isVisible(), 'the note on AI quality is shown while AI is on')
		expectEqual(await limit.inputValue(), '30', 'the daily limit is 30 by default')

		// Nextcloud draws the checkbox on its label (the input itself is hidden)
		await settings.getByText('Allow AI in Pulse', { exact: true }).click()
		await until(async () => (await settings.getByRole('status').filter({ hasText: 'Saved' }).count()) > 0, '"Saved" after the switch')
		await limit.fill('5')
		await limit.press('Tab')
		// the change handler empties the status line at once; "Saved" again = the limit is stored
		await until(async () => /Saved/.test(await settings.locator('.pulse-admin-status').innerText()) && !(await limit.isDisabled()), '"Saved" after the limit')
		await until(async () => (await appApi('GET', '/api/1.0/ai')).available === false, 'the server says AI is off')
		await settings.reload()
		await allow.waitFor()
		expect(!(await allow.isChecked()), 'the switch stays off after a reload')
		expect(await settings.locator('.pulse-admin-help').filter({ hasText: QUALITY }).isVisible(), 'the note on AI quality is shown while AI is off')
		await until(async () => (await limit.inputValue()) === '5', 'the limit stays 5 after a reload')

		// a value out of range: a message, nothing stored
		await limit.fill('5000')
		await limit.press('Tab')
		await until(async () => /0 to 1000/.test(await settings.locator('.pulse-admin-status').innerText()), 'a message for 5000')

		// AI off: no AI button, no "AI help"
		const desk = await world.page('laptop', 'light', { moderator: true, label: 'desk' })
		const code = await startRoom(desk, 'quiz')
		world.track(code)
		await openComposer(desk)
		expect(await desk.locator('.composer').getByRole('button', AI_BUTTON).count() === 0, 'no AI button while AI is off')
		// "AI help" sits in the live view's menu (a reopened room opens there)
		await desk.goto(APP + '/room/' + code)
		const off = await menuEntries(desk)
		expect(!off.includes('AI help'), 'no "AI help" while AI is off', off)

		// on again: both are back
		await settings.getByText('Allow AI in Pulse', { exact: true }).click()
		await until(async () => (await appApi('GET', '/api/1.0/ai')).available === true, 'the server says AI is on')
		const code2 = await startRoom(desk, 'quiz')
		world.track(code2)
		await openComposer(desk)
		await desk.locator('.composer').getByRole('button', AI_BUTTON).waitFor()
		await desk.goto(APP + '/room/' + code2)
		const on = await menuEntries(desk)
		expect(on.includes('AI help'), '"AI help" is back', on)

		// ── the notice, for a fresh account ──
		await ocs('POST', '/cloud/users', { userid: fresh, password: freshPass })
		created = true
		const page = await loginAs(world, fresh, freshPass)
		const freshCode = await startRoom(page, 'quiz')
		await openComposer(page)
		await page.locator('.composer').getByRole('button', AI_BUTTON).click()
		const notice = page.getByRole('dialog', NOTICE)
		await notice.waitFor()
		await expectLayout(page, '.pulse-mod', 'AI notice on a tablet')
		expect(/Never sent: names/.test(await notice.innerText()), 'the notice says what is never sent')
		const noticeText = await notice.innerText()
		for (const part of ['quiz draft', 'filter', 'summary', 'grouping', 'grading suggestion', 'question text', 'answer texts']) {
			expect(noticeText.includes(part), `the notice names "${part}"`)
		}
		await notice.getByRole('button', { name: 'Cancel' }).click()
		await notice.waitFor({ state: 'detached' })
		expect(await page.getByRole('dialog', AI_BUTTON).count() === 0, '"Cancel" opens no draft')

		await page.locator('.composer').getByRole('button', AI_BUTTON).click()
		await notice.waitFor()
		await notice.getByRole('button', { name: 'Understood' }).click()
		const draft = page.getByRole('dialog', AI_BUTTON)
		await draft.waitFor()
		await page.keyboard.press('Escape')
		await draft.getByRole('button', { name: 'Cancel' }).click().catch(() => {})
		await draft.waitFor({ state: 'detached' })

		await page.locator('.composer').getByRole('button', AI_BUTTON).click()
		await draft.waitFor()
		expect(await notice.count() === 0, 'the second draft does not ask again')

		// limit 0: the draft says the day's requests are used up
		await limit.fill('0')
		await limit.press('Tab')
		await until(async () => /Saved/.test(await settings.locator('.pulse-admin-status').innerText()), '"Saved" for limit 0')
		await draft.getByRole('textbox', { name: 'Topic' }).fill('Volcanoes')
		await draft.getByRole('button', { name: 'Draft questions', exact: true }).click()
		await until(async () => (await draft.getByRole('alert').textContent().catch(() => '')).includes('AI requests for today'), 'the limit message', 15000)
		await draft.getByRole('button', { name: 'Cancel' }).click().catch(() => {})

		// "AI help" asks no more (confirmed above)
		await page.goto(APP + '/room/' + freshCode)
		await page.locator('.deck-head, .mod-top').first().getByRole('button', { name: 'More actions' }).click()
		await page.getByRole('menu').getByRole('menuitemcheckbox', { name: 'AI help' }).click()
		if (await page.getByRole('menu').isVisible()) await page.keyboard.press('Escape')
		expect(await notice.count() === 0, '"AI help" does not ask again')
		await page.locator('.deck-head, .mod-top').first().getByRole('button', { name: 'More actions' }).click()
		await until(async () => (await page.getByRole('menu').getByRole('menuitemcheckbox', { name: 'AI help' }).getAttribute('aria-checked')) === 'true', '"AI help" is on without a second notice')
		await page.keyboard.press('Escape')
		for (const p of [settings, desk, page]) expect(p.e2eErrors.length === 0, 'no script errors on ' + p.e2eLabel, p.e2eErrors)
	} finally {
		await appApi('POST', '/api/1.0/admin/ai', { enabled: true, dailyLimit: 30 }).catch(() => {})
		if (created) await ocs('DELETE', '/cloud/users/' + fresh).catch(() => {})
	}
}
