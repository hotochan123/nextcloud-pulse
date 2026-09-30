/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Pulse: small pure formatting/calculation helpers, shared by Moderator
 * (control desk), Participant (phone) and Screen (projector). Deliberately without
 * Vue state: this way every former duplicate (PIN regex 4×, countdown maths
 * incl. server skew 3×) lives in exactly ONE place and can no longer drift.
 *
 * Section references (§…) point to the specification of the self-paced quiz,
 * which is not in the public repository (see "References in code comments" in
 * the README).
 */
import { getCanonicalLocale } from '@nextcloud/l10n'
import { t, n } from './l10n.js'
import { splitDuration, windowState } from './pace.js'

/**
 * Number in the format of the configured language (decimal separator!). Previously the
 * German decimal comma was hard-coded; in an English interface "3,5" would be a
 * different number.
 * @param {number|string} value number to format
 * @param {number} [max] at most this many decimal places
 * @param {number} [min] at least this many decimal places
 * @return {string} formatted number, '' for invalid input
 */
export function fmtNum(value, max = 1, min = 0) {
	const n = Number(value)
	if (!isFinite(n)) return ''
	return n.toLocaleString(getCanonicalLocale(), { maximumFractionDigits: max, minimumFractionDigits: min })
}

/** Group a room code as "ABC DEF" (an empty/short code stays unchanged). */
export function formatCode(code) {
	return (code || '').replace(/(.{3})(.{3})/, '$1 $2')
}

/**
 * Remaining seconds of a time-limited poll in server time (nowSec +
 * serverSkew), never negative. Poll is the question/result object with timeLimit
 * and startedAt.
 * @return {number|null} null if no time limit is set.
 */
export function remainingSecs(poll, nowSec, serverSkew) {
	if (!poll || !poll.timeLimit) return null
	return Math.max(0, Math.ceil(poll.timeLimit - ((nowSec + serverSkew) - poll.startedAt)))
}

/** Progress 0–100 % for the countdown bar (0 if there is no time limit). */
export function remainingPct(poll, remaining) {
	return poll && poll.timeLimit ? Math.round((remaining / poll.timeLimit) * 100) : 0
}

/**
 * Deadline or point in time as an absolute value, in the configured language: "Fri, 3 Oct,
 * 18:00". Absolute and therefore without server skew.
 * @param {number} ts Unix seconds
 * @return {string} '' without a point in time
 */
export function fmtDeadline(ts) {
	const v = Number(ts)
	if (!isFinite(v) || v <= 0) return ''
	return new Intl.DateTimeFormat(getCanonicalLocale(), {
		weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
	}).format(new Date(v * 1000))
}

/**
 * "5 min ago", computed against the given now. The run view passes SERVER
 * time: timestamps from /progress come from the server, and a skewed laptop
 * clock would otherwise turn "just now" into "3 min ago". Moderator's room
 * list (Moderator.ago()) passes the laptop clock.
 * @param {number} ts Unix seconds (server)
 * @param {number} nowServer now in Unix seconds, server time in the run view (required)
 * @return {string} '' without a timestamp
 */
export function fmtAgo(ts, nowServer) {
	if (!ts) return ''
	const s = Math.max(0, Math.floor(nowServer) - ts)
	if (s < 90) return t('pulse', 'just now')
	const m = Math.floor(s / 60)
	if (m < 60) return t('pulse', '{count} min ago', { count: m })
	const h = Math.floor(m / 60)
	if (h < 24) return t('pulse', '{count} h ago', { count: h })
	const d = Math.floor(h / 24)
	return n('pulse', '%n day ago', '%n days ago', d)
}

/**
 * State chip of a self-paced window: deck header, room list and
 * run view show the same texts (specification §1.6). A deadline that has
 * already passed in server time counts as closed.
 * @param {object} win `window` from the room JSON or /progress
 * @param {number} nowServer now in server time (seconds)
 * @return {{cls: string, dot: boolean, label: string}} draft: "Self-paced"
 */
export function paceStateChip(win, nowServer) {
	const st = windowState(win, nowServer)
	if (st === 'open') {
		return win.closesAt > 0
			? { cls: 'is-live', dot: true, label: t('pulse', 'Open until {time}', { time: fmtDeadline(win.closesAt) }) }
			: { cls: 'is-live', dot: true, label: t('pulse', 'Open now') }
	}
	if (st === 'closed') return { cls: 'is-warning', dot: false, label: t('pulse', 'Quiz closed') }
	if (st === 'released') return { cls: 'is-neutral', dot: false, label: t('pulse', 'Results released') }
	return { cls: 'is-neutral', dot: false, label: t('pulse', 'Self-paced') }
}

/**
 * Remaining time for "Closes in {duration}": under one hour minutes, up to 48 h
 * hours and minutes, beyond that days and hours (zero parts are dropped).
 * @param {number} sec seconds
 * @return {string}
 */
export function fmtDuration(sec) {
	const { d, h, m } = splitDuration(sec)
	const minutes = () => n('pulse', '%n minute', '%n minutes', m)
	if (d === 0 && h === 0) return minutes()
	const hours = d * 24 + h
	if (hours < 48) return n('pulse', '%n hour', '%n hours', hours) + (m ? ', ' + minutes() : '')
	return n('pulse', '%n day', '%n days', d) + (h ? ', ' + n('pulse', '%n hour', '%n hours', h) : '')
}

// From this label length on, rows are laid out on two lines — for ALL rows
// of the stage together, so the row grid does not jump (§2.6).
const TALL_LABEL_CHARS = 34

/**
 * Does one of the stage rows have a label long enough for the two-line row
 * height? Shared by the open stage (StageOpen) and the reveal (ResultsView).
 * @param {Array<{label: string}>} rows stage rows
 * @return {boolean}
 */
export function hasTallLabel(rows) {
	return rows.some((r) => (r.label || '').length > TALL_LABEL_CHARS)
}
