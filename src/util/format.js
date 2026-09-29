/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Pulse — kleine reine Formatier-/Rechen-Helfer, geteilt über Moderator
 * (Steuerpult), Participant (Handy) und Screen (Beamer). Bewusst ohne
 * Vue-State: so lebt jedes bisherige Duplikat (PIN-Regex 4×, Countdown-Mathe
 * inkl. Server-Skew 3×) an genau EINER Stelle und kann nicht mehr driften.
 */
import { getCanonicalLocale } from '@nextcloud/l10n'
import { t, n } from './l10n.js'
import { splitDuration, windowState } from './pace.js'

/**
 * Zahl im Format der eingestellten Sprache (Dezimaltrenner!). Vorher stand das
 * deutsche Komma fest im Code — in einer englischen Oberfläche wäre „3,5" eine
 * andere Zahl.
 * @param {number|string} value zu formatierende Zahl
 * @param {number} [max] höchstens so viele Nachkommastellen
 * @param {number} [min] mindestens so viele Nachkommastellen
 * @return {string} formatierte Zahl, '' bei ungültiger Eingabe
 */
export function fmtNum(value, max = 1, min = 0) {
	const n = Number(value)
	if (!isFinite(n)) return ''
	return n.toLocaleString(getCanonicalLocale(), { maximumFractionDigits: max, minimumFractionDigits: min })
}

/** Raum-Code als „ABC DEF" gruppieren (leerer/kurzer Code bleibt unverändert). */
export function formatCode(code) {
	return (code || '').replace(/(.{3})(.{3})/, '$1 $2')
}

/**
 * Verbleibende Sekunden eines zeitbegrenzten Polls in Server-Zeit (nowSec +
 * serverSkew), nie negativ. Poll ist das Frage-/Ergebnisobjekt mit timeLimit
 * und startedAt.
 * @return {number|null} null, wenn kein Zeitlimit gesetzt ist.
 */
export function remainingSecs(poll, nowSec, serverSkew) {
	if (!poll || !poll.timeLimit) return null
	return Math.max(0, Math.ceil(poll.timeLimit - ((nowSec + serverSkew) - poll.startedAt)))
}

/** Fortschritt 0–100 % für den Countdown-Balken (0, wenn kein Zeitlimit). */
export function remainingPct(poll, remaining) {
	return poll && poll.timeLimit ? Math.round((remaining / poll.timeLimit) * 100) : 0
}

/**
 * Frist oder Zeitpunkt absolut, in der eingestellten Sprache: „Fr., 3. Okt.,
 * 18:00". Absolut und deshalb ohne Server-Skew.
 * @param {number} ts Unix-Sekunden
 * @return {string} '' ohne Zeitpunkt
 */
export function fmtDeadline(ts) {
	const v = Number(ts)
	if (!isFinite(v) || v <= 0) return ''
	return new Intl.DateTimeFormat(getCanonicalLocale(), {
		weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
	}).format(new Date(v * 1000))
}

/**
 * „vor 5 Min." — dieselben Texte wie Moderator.ago(), aber gegen die
 * SERVERzeit gerechnet: Zeitstempel aus /progress kommen vom Server, und eine
 * schiefe Laptop-Uhr machte sonst aus „gerade eben" „vor 3 Min.".
 * @param {number} ts Unix-Sekunden (Server)
 * @param {number} nowServer jetzt in Serverzeit (Pflicht)
 * @return {string} '' ohne Zeitstempel
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
 * Zustands-Chip eines Fensters im eigenen Tempo — Deck-Kopf, Raumliste und
 * Laufansicht zeigen dieselben Texte (Spezifikation §1.6). Eine Frist, die
 * nach Serverzeit schon abgelaufen ist, gilt als geschlossen.
 * @param {object} win `window` aus Raum-JSON oder /progress
 * @param {number} nowServer jetzt in Serverzeit (Sekunden)
 * @return {{cls: string, dot: boolean, label: string}} Entwurf: „Self-paced"
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
 * Restdauer für „Closes in {duration}": unter einer Stunde Minuten, bis 48 h
 * Stunden und Minuten, darüber Tage und Stunden (Nullteile entfallen).
 * @param {number} sec Sekunden
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
