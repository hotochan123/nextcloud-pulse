/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Pulse — self-paced quiz: pure decisions and time arithmetic.
 *
 * Deliberately without Nextcloud imports and without translation: the file also
 * runs under plain `node` (dev/unit/pace.test.mjs, dev/sim/load.mjs). The
 * components set the labels; only keys and numbers come out of here.
 *
 * `paceCard`, `canNext` and `phoneDelay` get a flat object (not a
 * Vue instance) that the computeds put together — so the phone state machine
 * can be tested without a browser.
 *
 * Section references (§…) point to the specification of the self-paced quiz,
 * which is not in the public repository (see "References in code comments" in
 * the README).
 */

// Deadline bounds as on the server (PaceService::MIN_LEAD / MAX_LEAD): [now+60 s, now+30 days].
export const DEADLINE_MIN = 60
export const DEADLINE_MAX = 2592000

// The dialog checks a deadline DEADLINE_SLACK seconds inside those bounds. Its
// "now" estimates the server clock (laptop time + skew, both in whole seconds,
// refreshed once a second) and runs up to about 3 s behind it, plus the trips of
// the last poll's response and of this request — the server measures the lead
// only when the request arrives. Not more: the error sentence is the server's
// own ("between one minute and 30 days"), and a wider margin would refuse
// deadlines it calls fine. A request slower than that gets the server's 400
// with the same sentence, shown in the dialog.
export const DEADLINE_SLACK = 5

// From here on the phone shows "Closes in %n min" (name screen and question).
export const CLOSING_SOON = 15 * 60

const pad = (v) => String(v).padStart(2, '0')

/**
 * Does the room run self-paced? The same rule as PaceService::isSelf.
 * @param {object|null} room room JSON or `room` from /state
 * @return {boolean}
 */
export function isPacedRoom(room) {
	return !!room && room.mode === 'quiz' && room.pace === 'self'
}

/**
 * Window state for display. A deadline that has expired locally already counts as
 * "closed" — the server sees it the same way (in the second closesAt the
 * window is shut), and the next fetch confirms it.
 * @param {object|null} win `window` from room JSON, /state or /progress
 * @param {number} [nowServer] server time in seconds (0 = do not check against the deadline)
 * @return {string} '' without a window, otherwise draft|open|closed|released
 */
export function windowState(win, nowServer = 0) {
	if (!win) return ''
	if (win.state === 'open' && win.closesAt > 0 && nowServer > 0 && nowServer >= win.closesAt) return 'closed'
	return win.state || ''
}

// Legacy: before close {release}, "Stop without releasing" (progress view) set a
// deadline STOP_LEAD seconds ahead and closed immediately — two calls. Today it is
// one, and the deadline stays 0. Rooms stopped that way (including from a still open
// tab with an old bundle) are still recognised by stopDeadline from the gap.
export const STOP_LEAD = 120

/**
 * Does the deadline come from the former "Stop without releasing" (see STOP_LEAD)? Then
 * nobody chose it, and "Reopen …" pre-selects "When I close it" instead of
 * tomorrow with "Was: …" (otherwise one click would turn the race into
 * homework). Recognised by the gap: closed about STOP_LEAD seconds before the
 * deadline. A deadline that expired by itself has closedAt = closesAt and does not count.
 * @param {object|null} win `room.window`
 * @return {boolean}
 */
export function stopDeadline(win) {
	const at = (win && Number(win.closesAt)) || 0
	if (at <= 0) return false
	const closed = Number(win.closedAt) || 0
	const lead = at - closed
	return closed > 0 && lead >= STOP_LEAD - 20 && lead <= STOP_LEAD + 5
}

/**
 * Unix seconds -> value for <input type="datetime-local"> in local time.
 * @param {number} ts Unix seconds
 * @return {string} 'YYYY-MM-DDTHH:mm', '' for invalid input
 */
export function toLocalInput(ts) {
	const n = Number(ts)
	if (!isFinite(n) || n <= 0) return ''
	const d = new Date(n * 1000)
	return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
		+ 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes())
}

/**
 * Value of a datetime-local field (local time) -> Unix seconds.
 * DST change: a time in the spring gap (29.03. 02:30) moves forward one hour
 * as in the browser (03:30); a doubled autumn time (25.10.
 * 02:30) takes the first one, i.e. still summer time. A date that does not exist
 * (31.04.) is invalid instead of silently slipping into May.
 * @param {string} s 'YYYY-MM-DDTHH:mm' (seconds allowed)
 * @return {number} Unix seconds or NaN
 */
export function fromLocalInput(s) {
	const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(String(s || '').trim())
	if (!m) return NaN
	const [y, mo, d, h, mi, sec] = [m[1], m[2], m[3], m[4], m[5], m[6] || '0'].map(Number)
	if (mo < 1 || mo > 12 || d < 1 || h > 23 || mi > 59 || sec > 59) return NaN
	const date = new Date(y, mo - 1, d, h, mi, sec, 0)
	if (date.getFullYear() !== y || date.getMonth() !== mo - 1 || date.getDate() !== d) return NaN
	return Math.floor(date.getTime() / 1000)
}

/**
 * Will the server take this deadline (DEADLINE_MIN … DEADLINE_MAX ahead),
 * with DEADLINE_SLACK to spare at both ends?
 * @param {number} closesAt Unix seconds (NaN = empty or invalid field)
 * @param {number} nowSec now in server time (seconds)
 * @return {boolean}
 */
export function deadlineInRange(closesAt, nowSec) {
	const lead = closesAt - nowSec
	return isFinite(lead) && lead >= DEADLINE_MIN + DEADLINE_SLACK && lead <= DEADLINE_MAX - DEADLINE_SLACK
}

/**
 * min/max of the datetime-local field. The field holds whole minutes, so the
 * earliest one is rounded up and the latest one down: every minute the
 * picker offers passes deadlineInRange at this second.
 * @param {number} nowSec now in server time (seconds)
 * @return {{min: number, max: number}} Unix seconds, both on a full minute
 */
export function deadlineInputRange(nowSec) {
	return {
		min: Math.ceil((nowSec + DEADLINE_MIN + DEADLINE_SLACK) / 60) * 60,
		max: Math.floor((nowSec + DEADLINE_MAX - DEADLINE_SLACK) / 60) * 60,
	}
}

/**
 * Default for a deadline: in `days` calendar days at the same time, rounded up
 * to the next full hour (local time) — "Fri, 3 Oct, 18:00" instead of
 * "17:43".
 * @param {number} nowSec now in server time (seconds)
 * @param {number} [days] days from now
 * @return {number} Unix seconds
 */
export function defaultDeadline(nowSec, days = 7) {
	const d = new Date(nowSec * 1000)
	d.setDate(d.getDate() + days)
	if (d.getMinutes() || d.getSeconds() || d.getMilliseconds()) d.setMinutes(60, 0, 0)
	return Math.floor(d.getTime() / 1000)
}

/**
 * The four preset buttons above the deadline field. All rounded up to the
 * full minute: the field shows minutes, so the field gives back exactly the
 * value the button set.
 * @param {number} nowSec now in server time (seconds)
 * @return {Array<{key: string, ts: number}>} 15m, 1h, tomorrow8 (tomorrow 08:00 local time), 1w
 */
export function deadlinePresets(nowSec) {
	const up = (ts) => Math.ceil(ts / 60) * 60
	const tomorrow = new Date(nowSec * 1000)
	tomorrow.setDate(tomorrow.getDate() + 1)
	tomorrow.setHours(8, 0, 0, 0)
	const week = new Date(nowSec * 1000)
	week.setDate(week.getDate() + 7)
	return [
		{ key: '15m', ts: up(nowSec + 15 * 60) },
		{ key: '1h', ts: up(nowSec + 3600) },
		{ key: 'tomorrow8', ts: Math.floor(tomorrow.getTime() / 1000) },
		{ key: '1w', ts: up(Math.floor(week.getTime() / 1000)) },
	]
}

/**
 * Duration in days/hours/minutes. Minutes rounded up: "Closes in 15 minutes"
 * stays until really less than 14 full minutes are left — the same
 * rounding as "Closes in %n min" on the phone.
 * @param {number} sec seconds (negative = 0)
 * @return {{d: number, h: number, m: number}}
 */
export function splitDuration(sec) {
	const s = Math.max(0, Math.floor(Number(sec) || 0))
	const total = Math.ceil(s / 60)
	return { d: Math.floor(total / 1440), h: Math.floor((total % 1440) / 60), m: total % 60 }
}

/**
 * Rows of the projector race (specification §3.3). Order: "Not started"
 * (only while open and only if someone is missing) · questions · "Finished" (outside the
 * grid). Up to 10 questions one column, 11–20 two columns (`perCol` rows
 * per column), above that groups of ceil(n/10) questions in one column.
 * `maxRows` caps the question rows per column (fitRaceRows, embed frame):
 * one column only up to that many, two columns only up to twice that many,
 * otherwise groups large enough to stay within it.
 * The server counts every person exactly once (not started, on question k
 * or finished): while open the values add up to `joined`, without "Not
 * started" to those who started.
 * @param {object|null} race `race` from /state?spectate=1 ({n, joined, started, finished, onQuestion})
 * @param {boolean} [open] window open (only then is "Not started" shown)
 * @param {number} [maxRows] question rows per column at most (0 = no limit)
 * @return {{rows: Array<object>, cols: number, perCol: number}}
 *   rows: {key, kind: 'idle'|'q'|'group'|'done', value, k?, from?, to?}
 *   perCol: question rows per column (without "Not started"/"Finished")
 */
export function raceRows(race, open = true, maxRows = 0) {
	if (!race) return { rows: [], cols: 1, perCol: 0 }
	const n = Math.max(0, Number(race.n) || 0)
	const fit = maxRows > 0 ? Math.max(1, Math.floor(maxRows)) : Infinity
	const oneCol = n <= 10 && n <= fit
	const twoCols = !oneCol && n <= 20 && Math.ceil(n / 2) <= fit
	const on = Array.isArray(race.onQuestion) ? race.onQuestion : []
	const at = (i) => Number(on[i]) || 0
	const rows = []
	const idle = Math.max(0, (Number(race.joined) || 0) - (Number(race.started) || 0))
	if (open && idle > 0) rows.push({ key: 'idle', kind: 'idle', value: idle })
	let cols = 1
	let perCol = n
	if (!oneCol && !twoCols) {
		const b = Math.max(Math.ceil(n / 10), Math.ceil(n / fit))
		perCol = 0
		for (let from = 1; from <= n; from += b) {
			const to = Math.min(n, from + b - 1)
			let value = 0
			for (let k = from; k <= to; k++) value += at(k - 1)
			rows.push({ key: 'g' + from, kind: 'group', from, to, value })
			perCol++
		}
	} else {
		for (let k = 1; k <= n; k++) rows.push({ key: 'q' + k, kind: 'q', k, value: at(k - 1) })
		if (twoCols) {
			cols = 2
			perCol = Math.ceil(n / 2)
		}
	}
	rows.push({ key: 'done', kind: 'done', value: Number(race.finished) || 0 })
	return { rows, cols, perCol }
}

// Leaderboard rows beside the open race, and the fewest it gives up to.
export const RACE_BOARD_ROWS = 8
export const RACE_BOARD_MIN = 3

/**
 * Leaderboard rows beside the open race when even tight rows overflow at the
 * shrink limit — the embed frame (1264×576) is lower than the 1280×720
 * kiosk. The leaderboard gives up as many rows at the bottom as the overflow
 * needs, instead of being cut off mid-row. Only the part by which it is
 * taller than the bars next to it counts (beyond that the bars overflow
 * themselves, fewer leaders would not help), and never below RACE_BOARD_MIN.
 * @param {object} m {shown, overflow, excess, pitch}: rows shown now, stage
 *   overflow (px), how much taller the leaderboard is than the bars (px),
 *   distance from one leaderboard row to the next (px)
 * @return {number} rows to show
 */
export function trimRaceBoard({ shown, overflow, excess, pitch }) {
	const n = Math.max(0, Math.floor(Number(shown) || 0))
	if (!(overflow > 0) || !(excess > 0) || !(pitch > 0) || n <= RACE_BOARD_MIN) return n
	return Math.max(RACE_BOARD_MIN, n - Math.ceil(Math.min(overflow, excess) / pitch))
}

// Question rows per column the race keeps at the least.
export const RACE_ROWS_MIN = 3

/**
 * Question rows per column for the open race when even tight rows overflow at
 * the shrink limit and fewer leaders would not help (trimRaceBoard): the
 * embed frame (1264×576) holds eight tight rows, a quiz with ten questions
 * and people who have not started needs twelve. As many rows fewer as the
 * bars' own overflow needs; raceRows then moves on to two columns or larger
 * groups. Never below RACE_ROWS_MIN; without anything to gain the rows stay.
 * @param {object} m {perCol, overflow, excess, pitch}: question rows in the
 *   tallest column now, stage overflow (px), how much taller the leaderboard
 *   is than the bars (px; 0 or less when the bars are the tallest), distance
 *   from one bar to the next (px)
 * @return {number} question rows per column at most
 */
export function fitRaceRows({ perCol, overflow, excess, pitch }) {
	const n = Math.max(0, Math.floor(Number(perCol) || 0))
	const over = (Number(overflow) || 0) - Math.max(0, Number(excess) || 0)
	if (!(over > 0) || !(pitch > 0) || n <= RACE_ROWS_MIN) return n
	return Math.max(RACE_ROWS_MIN, n - Math.ceil(over / pitch))
}

/**
 * Phone: which card without a question (specification §2.2). Only self-paced
 * and only without an open question; '' = no match, then the remaining
 * branches apply (choose a name, final standings, question).
 * @param {object} s {isPaced, poll, nickname, state, progress, paceFinal}
 * @return {string} ''|'shut'|'over'|'wait'|'start'|'through'|'continue'|'closed'
 */
export function paceCard(s) {
	if (!s || !s.isPaced || s.poll) return ''
	const st = s.state
	if (!s.nickname) {
		if (st === 'closed') return 'shut'
		if (st === 'released' && !s.paceFinal) return 'over'
		return ''
	}
	if (st === 'draft') return 'wait'
	if (st === 'open') {
		const p = s.progress
		if (!p) return 'wait'
		if (!p.started) return 'start'
		if (p.finished) return 'through'
		return 'continue'
	}
	if (st === 'closed') return 'closed'
	if (st === 'released') return s.paceFinal ? '' : 'over'
	return ''
}

/**
 * May "Next question"/"I’m done" or "Skip question" be tapped
 * (specification §2.5)? Answered: only with the final verdict. Open
 * without a timer: immediately (skip). Open with a timer: only once the SERVER
 * reports the time is up — the local countdown reaches 0 up to a second
 * earlier, and before that /next would be a silent no-op.
 * @param {object} s {isPaced, progress, poll, voted, myResult, busy, nextBusy, online}
 * @return {boolean}
 */
export function canNext(s) {
	if (!s || !s.isPaced || !s.progress || s.busy || s.nextBusy || !s.online) return false
	if (!s.poll) return false
	if (s.voted) return !!(s.myResult && s.myResult.final)
	if (s.poll.timeLimit === 0) return true
	return !!s.progress.timeUp
}

/**
 * Polling interval of the phone when self-paced (specification §2.10, the first line
 * wins). All values below the presence window of 15 s. A hidden
 * tab is decided by the caller (null before this call).
 * @param {object} s {isPaced, state, poll, voted, myResult, progress, remaining}
 *   state: window state; remaining: local remaining seconds of the question (null without a timer)
 * @return {number|null} milliseconds; null outside self-paced mode
 */
export function phoneDelay(s) {
	if (!s || !s.isPaced) return null
	if (s.poll) {
		if (!s.voted) {
			const timed = s.poll.timeLimit > 0
			const ending = s.remaining !== null && s.remaining !== undefined && s.remaining <= 1
			if (timed && ending && !(s.progress && s.progress.timeUp)) return 700
			return 4000
		}
		return (s.myResult && s.myResult.final) ? 4000 : 1000
	}
	if (s.state === 'closed' || s.state === 'released') return 10000
	// Cards start/continue/through/wait — and the name screen, which the
	// table does not mention: there the opening has to arrive just as soon.
	return 5000
}

/**
 * Polling interval of /progress in the progress view (specification §1.6): open up to
 * 50 people 2 s, above that 3 s, closed/released 5 s; after three 204s in
 * a row 5 s or 10 s respectively.
 * @param {string} state window state
 * @param {number} players number of people
 * @param {number} idleStreak 204s in a row
 * @return {number} milliseconds
 */
export function progressDelay(state, players, idleStreak) {
	const idle = (Number(idleStreak) || 0) >= 3
	if (state === 'open') {
		if (idle) return 5000
		return (Number(players) || 0) > 50 ? 3000 : 2000
	}
	return idle ? 10000 : 5000
}

/**
 * Counts from a /progress response — the same shape that
 * Moderator.fetchCounts delivers and lossText (util/question-types.js) reads
 * (specification §0.9).
 * @param {object|null} p /progress payload
 * @return {object|null} {joined, present, started, finished, answers, pending, state}
 */
export function progressCounts(p) {
	if (!p) return null
	const players = Array.isArray(p.players) ? p.players : []
	const sum = (key) => players.reduce((acc, x) => acc + (Number(x[key]) || 0), 0)
	return {
		joined: players.length,
		present: Number(p.present) || 0,
		started: players.filter((x) => x.started).length,
		finished: players.filter((x) => x.finished).length,
		answers: sum('answered'),
		pending: sum('pending'),
		state: (p.window && p.window.state) || '',
	}
}
