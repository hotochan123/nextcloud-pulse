/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * /progress-Schleife des Moderators im eigenen Tempo — geteilt von der
 * Statuszeile im Deck (PaceDeckStatus) und der Laufansicht (PaceRun).
 *
 * Baut auf pollingMixin auf und ersetzt dessen pollTick: höchstens EINE
 * Anfrage unterwegs. Vorher startete ein refresh() während einer laufenden
 * Anfrage eine zweite Kette, und pollClear fing nur einen der beiden Timer.
 * Jetzt merkt sich ein Tick während einer Anfrage nur den Wunsch
 * (wantRefresh); die laufende Kette holt sofort nach, sobald sie zurück ist.
 * refresh() zählt zusätzlich reqSeq hoch: die Antwort, die gerade unterwegs
 * ist, kann älter sein als die Aktion davor und wird verworfen.
 *
 * Die Komponente liefert:
 *   · progressUrl(): string
 *   · progressParams(): object   optional, z. B. { scores: 1 }
 *   · pollDelay(): number|null    wie beim pollingMixin (null = Tab versteckt)
 *   · onProgress(data)            optional, nach jeder 200er-Antwort
 *   · onGone()                    404/403: Raum weg oder nicht mehr meiner
 *   · onNotPaced()                409: der Raum läuft nicht mehr im eigenen Tempo
 * Beide Fehlerfälle beenden die Schleife dauerhaft (pollStopped).
 */
import axios from '@nextcloud/axios'
import pollingMixin from './polling.js'

/**
 * Zählstand aus einer /progress-Antwort — dieselbe Form, die
 * Moderator.fetchCounts liefert und lossText liest (Spezifikation §0.9).
 * @param {object|null} p /progress-Payload
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

export default {
	mixins: [pollingMixin],
	data() {
		return {
			progress: null,
			version: '',
			online: true,
			serverSkew: 0,
			inflight: false,  // eine Anfrage unterwegs
			reqSeq: 0,        // Antworten mit älterer Nummer sind überholt
			wantRefresh: false,
		}
	},
	mounted() {
		document.addEventListener('visibilitychange', this.onPollVisibility)
		this.pollTick()
	},
	beforeDestroy() {
		this.pollStopped = true
		this.pollClear()
		document.removeEventListener('visibilitychange', this.onPollVisibility)
	},
	methods: {
		// Vorgaben — die Komponente überschreibt, was sie braucht.
		progressParams() {
			return {}
		},
		onProgress() {},
		onGone() {},
		onNotPaced() {},

		pollTick() {
			if (this.inflight) {
				// Die laufende Kette übernimmt — nie zwei Anfragen zugleich.
				this.wantRefresh = true
				return
			}
			this.pollClear()
			this.pollOnce().finally(() => {
				if (this.pollStopped) return
				if (this.wantRefresh) {
					this.wantRefresh = false
					this.pollTick()
					return
				}
				this.pollScheduleNext()
			})
		},
		async pollOnce() {
			const seq = this.reqSeq
			this.inflight = true
			try {
				const params = { ...this.progressParams() }
				if (this.version) params.v = this.version
				const res = await axios.get(this.progressUrl(), { params, timeout: 15000 })
				if (seq !== this.reqSeq) return // überholt: refresh() kam danach
				this.online = true
				if (res.status === 204) {
					this.idleStreak++
					return
				}
				this.serverSkew = res.data.serverNow - Math.floor(Date.now() / 1000)
				this.progress = res.data
				this.version = res.data.version || ''
				this.idleStreak = 0
				this.onProgress(res.data)
			} catch (e) {
				if (seq !== this.reqSeq) return
				const st = e?.response?.status
				if (st === 404 || st === 403) {
					this.pollStopped = true
					this.onGone()
				} else if (st === 409) {
					this.pollStopped = true
					this.onNotPaced()
				} else {
					this.online = false
				}
			} finally {
				this.inflight = false
			}
		},
		// Nach jeder eigenen Aktion: ohne Version abrufen, Überholtes verwerfen.
		refresh() {
			this.version = ''
			this.reqSeq++
			this.pollTick()
		},
	},
}
