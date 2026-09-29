/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Pulse — Quiz im eigenen Tempo: reine Entscheidungen und Zeitrechnung.
 *
 * Bewusst ohne Nextcloud-Imports und ohne Übersetzung: die Datei läuft auch
 * unter nacktem `node` (dev/unit/pace.test.mjs, dev/sim/load.mjs). Die
 * Beschriftungen setzen die Komponenten; hier fallen nur Schlüssel und Zahlen.
 *
 * `paceCard`, `canNext` und `phoneDelay` bekommen ein flaches Objekt (keine
 * Vue-Instanz), das die Computeds zusammenstellen — so ist der Handy-Automat
 * ohne Browser prüfbar.
 */

// Feature-Schalter: der Umschalter „Eigenes Tempo" im Deck-Menü jedes Quiz.
// Bis Schritt 4.6 erschien er nur mit ?pace=1 in der Moderator-URL, solange
// Handy und Beamer den Modus noch nicht konnten; seit der Freischaltung immer.
// Räume, die schon im eigenen Tempo laufen, zeigt die Oberfläche ohnehin.
export const PACE_UI = true

// Fristgrenzen wie der Server (PaceService::MIN_LEAD / MAX_LEAD): [jetzt+60 s, jetzt+30 Tage].
export const DEADLINE_MIN = 60
export const DEADLINE_MAX = 2592000

// Ab hier zeigt das Handy „Closes in %n min" (Namensbildschirm und Frage).
export const CLOSING_SOON = 15 * 60

const pad = (v) => String(v).padStart(2, '0')

/**
 * Läuft der Raum im eigenen Tempo? Dieselbe Regel wie PaceService::isSelf.
 * @param {object|null} room Raum-JSON oder `room` aus /state
 * @return {boolean}
 */
export function isPacedRoom(room) {
	return !!room && room.mode === 'quiz' && room.pace === 'self'
}

/**
 * Fensterzustand für die Anzeige. Eine lokal abgelaufene Frist gilt schon als
 * „closed" — der Server sieht es genauso (in der Sekunde closesAt ist das
 * Fenster zu), und der nächste Abruf bestätigt es.
 * @param {object|null} win `window` aus Raum-JSON, /state oder /progress
 * @param {number} [nowServer] Serverzeit in Sekunden (0 = nicht gegen die Frist prüfen)
 * @return {string} '' ohne Fenster, sonst draft|open|closed|released
 */
export function windowState(win, nowServer = 0) {
	if (!win) return ''
	if (win.state === 'open' && win.closesAt > 0 && nowServer > 0 && nowServer >= win.closesAt) return 'closed'
	return win.state || ''
}

// Altlast: vor close {release} setzte „Stoppen ohne Freigabe“ (Laufansicht) eine
// Frist in STOP_LEAD Sekunden und schloss sofort — zwei Aufrufe. Heute ist es
// einer, und die Frist bleibt 0. So gestoppte Räume (auch aus einem noch offenen
// Tab mit altem Bundle) erkennt stopDeadline weiter am Abstand.
export const STOP_LEAD = 120

/**
 * Stammt die Frist vom früheren „Stoppen ohne Freigabe“ (s. STOP_LEAD)? Dann
 * hat sie niemand gewählt, und „Reopen …“ belegt „When I close it“ vor statt
 * „morgen“ mit „Was: …“ (sonst würde ein Klick aus dem Rennen eine
 * Hausaufgabe). Erkannt am Abstand: geschlossen rund STOP_LEAD Sekunden vor der
 * Frist. Eine von selbst abgelaufene Frist hat closedAt = closesAt und zählt nicht.
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
 * Unix-Sekunden -> Wert für <input type="datetime-local"> in Ortszeit.
 * @param {number} ts Unix-Sekunden
 * @return {string} 'YYYY-MM-DDTHH:mm', '' bei ungültiger Eingabe
 */
export function toLocalInput(ts) {
	const n = Number(ts)
	if (!isFinite(n) || n <= 0) return ''
	const d = new Date(n * 1000)
	return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
		+ 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes())
}

/**
 * Wert eines datetime-local-Felds (Ortszeit) -> Unix-Sekunden.
 * Zeitumstellung: eine Uhrzeit in der Frühjahrslücke (29.03. 02:30) rückt wie
 * im Browser eine Stunde vor (03:30); eine doppelte Herbst-Uhrzeit (25.10.
 * 02:30) nimmt die erste, also noch Sommerzeit. Ein Datum, das es nicht gibt
 * (31.04.), ist ungültig statt still in den Mai zu rutschen.
 * @param {string} s 'YYYY-MM-DDTHH:mm' (Sekunden erlaubt)
 * @return {number} Unix-Sekunden oder NaN
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
 * Vorbelegung einer Frist: in `days` Kalendertagen zur selben Uhrzeit, auf die
 * nächste volle Stunde (Ortszeit) aufgerundet — „Fr., 3. Okt., 18:00" statt
 * „17:43".
 * @param {number} nowSec jetzt in Serverzeit (Sekunden)
 * @param {number} [days] Tage ab jetzt
 * @return {number} Unix-Sekunden
 */
export function defaultDeadline(nowSec, days = 7) {
	const d = new Date(nowSec * 1000)
	d.setDate(d.getDate() + days)
	if (d.getMinutes() || d.getSeconds() || d.getMilliseconds()) d.setMinutes(60, 0, 0)
	return Math.floor(d.getTime() / 1000)
}

/**
 * Die vier Vorwahl-Knöpfe über dem Fristfeld. Alle auf die volle Minute
 * aufgerundet: das Feld zeigt Minuten, so kommt aus dem Feld genau der Wert
 * zurück, den der Knopf gesetzt hat.
 * @param {number} nowSec jetzt in Serverzeit (Sekunden)
 * @return {Array<{key: string, ts: number}>} 15m, 1h, tomorrow8 (morgen 08:00 Ortszeit), 1w
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
 * Dauer in Tage/Stunden/Minuten. Minuten aufgerundet: „Closes in 15 minutes"
 * steht, bis wirklich weniger als 14 volle Minuten übrig sind — dieselbe
 * Rundung wie „Closes in %n min" am Handy.
 * @param {number} sec Sekunden (negativ = 0)
 * @return {{d: number, h: number, m: number}}
 */
export function splitDuration(sec) {
	const s = Math.max(0, Math.floor(Number(sec) || 0))
	const total = Math.ceil(s / 60)
	return { d: Math.floor(total / 1440), h: Math.floor((total % 1440) / 60), m: total % 60 }
}

/**
 * Zeilen des Beamer-Rennens (Spezifikation §3.3). Reihenfolge: „Not started"
 * (nur offen und nur, wenn jemand fehlt) · Fragen · „Finished" (außerhalb des
 * Rasters). Bis 10 Fragen eine Spalte, 11–20 zwei Spalten (`perCol` Zeilen
 * je Spalte), darüber Gruppen zu ceil(n/10) Fragen in einer Spalte.
 * Der Server zählt jede Person genau einmal (nicht gestartet, auf Frage k
 * oder fertig): offen ergeben die Werte zusammen `joined`, ohne „Not
 * started“ die Gestarteten.
 * @param {object|null} race `race` aus /state?spectate=1 ({n, joined, started, finished, onQuestion})
 * @param {boolean} [open] Fenster offen (nur dann steht „Not started" da)
 * @return {{rows: Array<object>, cols: number, perCol: number}}
 *   rows: {key, kind: 'idle'|'q'|'group'|'done', value, k?, from?, to?}
 *   perCol: Fragezeilen je Spalte (ohne „Not started"/„Finished")
 */
export function raceRows(race, open = true) {
	if (!race) return { rows: [], cols: 1, perCol: 0 }
	const n = Math.max(0, Number(race.n) || 0)
	const on = Array.isArray(race.onQuestion) ? race.onQuestion : []
	const at = (i) => Number(on[i]) || 0
	const rows = []
	const idle = Math.max(0, (Number(race.joined) || 0) - (Number(race.started) || 0))
	if (open && idle > 0) rows.push({ key: 'idle', kind: 'idle', value: idle })
	let cols = 1
	let perCol = n
	if (n > 20) {
		const b = Math.ceil(n / 10)
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
		if (n > 10) {
			cols = 2
			perCol = Math.ceil(n / 2)
		}
	}
	rows.push({ key: 'done', kind: 'done', value: Number(race.finished) || 0 })
	return { rows, cols, perCol }
}

/**
 * Handy: welche Karte ohne Frage (Spezifikation §2.2). Nur im eigenen Tempo
 * und nur ohne offene Frage; '' = kein Treffer, dann greifen die übrigen
 * Zweige (Name wählen, Endstand, Frage).
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
 * Darf „Next question"/„I’m done" bzw. „Skip question" getippt werden
 * (Spezifikation §2.5)? Beantwortet: erst mit dem endgültigen Urteil. Offen
 * ohne Timer: sofort (überspringen). Offen mit Timer: erst, wenn der SERVER
 * den Zeitablauf meldet — der lokale Countdown steht bis zu einer Sekunde
 * früher auf 0, und davor wäre /next ein stilles No-op.
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
 * Abruftakt des Handys im eigenen Tempo (Spezifikation §2.10, erste Zeile
 * gewinnt). Alle Werte unter dem Präsenzfenster von 15 s. Ein verstecktes
 * Tab entscheidet der Aufrufer (null vor diesem Aufruf).
 * @param {object} s {isPaced, state, poll, voted, myResult, progress, remaining}
 *   state: Fensterzustand; remaining: lokale Restsekunden der Frage (null ohne Timer)
 * @return {number|null} Millisekunden; null außerhalb des eigenen Tempos
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
	// Karten start/continue/through/wait — und der Namensbildschirm, den die
	// Tabelle nicht nennt: dort muss das Öffnen ebenso bald ankommen.
	return 5000
}

/**
 * Abruftakt von /progress in der Laufansicht (Spezifikation §1.6): offen bis
 * 50 Personen 2 s, darüber 3 s, geschlossen/freigegeben 5 s; nach drei 204 in
 * Folge 5 s bzw. 10 s.
 * @param {string} state Fensterzustand
 * @param {number} players Anzahl Personen
 * @param {number} idleStreak 204 in Folge
 * @return {number} Millisekunden
 */
export function progressDelay(state, players, idleStreak) {
	const idle = (Number(idleStreak) || 0) >= 3
	if (state === 'open') {
		if (idle) return 5000
		return (Number(players) || 0) > 50 ? 3000 : 2000
	}
	return idle ? 10000 : 5000
}
