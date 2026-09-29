/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Handy im eigenen Tempo (Spezifikation Stufe 4, §2): Daten, Computeds,
 * Watcher, nextStep, Urteilsband, Überspringen- und Antwortsperre, Fokus und
 * Ansagen (§2.5, §2.11). Die Kartentexte stehen im Template von
 * Participant.vue — die scoped Klassen (.center, .code-badge, .you, .submit …)
 * wirken nur dort. Die reinen Entscheidungen (paceCard, canNext, phoneDelay)
 * liegen in util/pace.js und sind ohne Browser geprüft.
 *
 * Moderiert bleibt alles beim Alten: jeder Zweig hängt an isPaced, und
 * applyPace läuft nur für einen Zustand mit room.pace === 'self'.
 */
import axios from '@nextcloud/axios'
import { t } from '../util/l10n.js'
import { showError } from '../toast.js'
import { fmtDeadline } from '../util/format.js'
import { CLOSING_SOON, canNext, paceCard, windowState } from '../util/pace.js'

// Gemerkter Name je Raum: {nick, openedAt}. Übersteht Neuladen und einen
// verworfenen Hintergrund-Tab — nur so kann das Handy „entfernt" von „neue
// Runde" unterscheiden, wenn der Server nur noch `nickname: null` meldet.
const STORE_PREFIX = 'pulse-pace:'

// Sperre der Antworttipps nach jeder neuen Frage (ms). Deckt den zweiten Tipp
// eines Doppeltipps auf „Next question" ab, auch wenn /next schneller zurück
// ist als der Finger; wer die Frage liest, merkt davon nichts.
const ANSWER_HOLD_MS = 600

export default {
	data() {
		return {
			pace: '',             // room.pace aus /state ('' = moderiert)
			paceWindow: null,     // window aus /state: Zustand, Frist, Einstellungen
			progress: null,       // {k, n, started, finished, timeUp, currentPollId, after}
			myScore: null,        // eigene Punkte (null = verborgen, „am Ende")
			nextBusy: false,      // /next unterwegs
			paceNotice: '',       // ''|'removed'|'newRound' — Hinweis am Namensbildschirm
			paceStored: null,     // {nick, openedAt} — gemerkter Name dieses Raums
			paceLostDraft: false, // Eingabe da, aber das Fenster schloss vor dem Senden
			reviewState: '',      // Fensterzustand beim Öffnen der Auswertung
			skipArmed: false,     // „Skip question" erst 1,5 s nach jeder neuen Frage scharf
			skipTimer: null,      // Timer dazu (beforeDestroy räumt ihn ab)
			answerArmed: true,    // Antworttipps: nach jeder neuen Frage erst nach ANSWER_HOLD_MS scharf
			answerTimer: null,    // Timer dazu (beforeDestroy räumt ihn ab)
			paceAnnounce: '',     // Ansage für Screenreader (aria-live, §2.11)
			paceFocusNext: false, // nach eigenem Übergang: Fokus auf die neue Überschrift
		}
	},
	computed: {
		isPaced() {
			return this.isQuiz && this.pace === 'self'
		},
		// Eine lokal abgelaufene Frist gilt schon als geschlossen; der nächste
		// Abruf bestätigt es. Serverzeit über serverSkew, nie die nackte Uhr.
		paceState() {
			return windowState(this.paceWindow, this.nowSec + this.serverSkew)
		},
		// Geschlossen oder freigegeben: beitreten geht nicht mehr, also kein
		// Namensbildschirm (der Server lehnte ohnehin mit 400 ab).
		paceNoJoin() {
			return this.isPaced && (this.paceState === 'closed' || this.paceState === 'released')
		},
		// Freigegeben mit Rangliste: dann der bestehende Endstand (Zweig 7).
		paceFinal() {
			return this.isPaced && this.paceState === 'released'
				&& Array.isArray(this.leaderboard) && this.leaderboard.length > 0
		},
		// Urteil erst, wenn die Antwort endgültig ist — davor keines (kein grüner Blitz).
		paceVerdict() {
			return this.myResult && this.myResult.final ? this.myResult.verdict : null
		},
		paceAnswerFinal() {
			return !!(this.myResult && this.myResult.final)
		},
		/*
		 * Antworttipps kurz gesperrt (§2.5, Gate 4.4b): „Next question" steht beim
		 * Zeitablauf mittig und sonst unten — genau dort liegen danach die Karten
		 * der nächsten Frage (Wahr/Falsch: B) bzw. ihr „Submit" (Reihenfolge ist
		 * sofort absendebereit). Auswahl und Wahr/Falsch senden im Quiz sofort; der
		 * zweite Tipp eines Doppeltipps gäbe so eine Antwort ab, die niemand
		 * gewählt hat. Moderiert immer false.
		 */
		paceHold() {
			return this.isPaced && !this.answerArmed
		},
		paceLast() {
			return !!this.progress && this.progress.k >= this.progress.n
		},
		// Karte ohne Frage (§2.2): '' = keine, dann greifen die übrigen Zweige.
		paceCard() {
			return paceCard({
				isPaced: this.isPaced,
				poll: this.poll,
				nickname: this.nickname,
				state: this.paceState,
				progress: this.progress,
				paceFinal: this.paceFinal,
			})
		},
		canNext() {
			return canNext({
				isPaced: this.isPaced,
				progress: this.progress,
				poll: this.poll,
				voted: this.voted,
				myResult: this.myResult,
				busy: this.busy,
				nextBusy: this.nextBusy,
				online: this.online,
			})
		},
		nextLabel() {
			return this.paceLast ? t('pulse', 'I’m done') : t('pulse', 'Next question')
		},
		// Urteilsband der Absende-Zone (§2.5): Klasse, Icon, Wort — Farbe nie allein.
		// Vor dem endgültigen Urteil neutral (kein grüner Blitz); „am Ende" sagt
		// schon jetzt dasselbe wie danach. Nie die Lösung, nie eine Verteilung.
		paceBand() {
			switch (this.paceVerdict) {
			case 'correct':
				return { cls: 'is-ok', icon: 'check', text: t('pulse', 'Correct!') }
			case 'wrong':
				return { cls: 'is-no', icon: 'close', text: t('pulse', 'Not this time') }
			case 'pending':
				return { cls: 'is-mut', icon: 'hourglass', text: t('pulse', 'Being checked') }
			case 'saved':
				return { cls: 'is-mut', icon: 'check', text: t('pulse', 'Answer saved') }
			}
			const end = !!this.paceWindow && this.paceWindow.feedback === 'end'
			return { cls: 'is-mut', icon: 'check', text: end ? t('pulse', 'Answer saved') : t('pulse', 'Answer sent') }
		},
		/*
		 * Was die Ansage-Region sagt (§2.11): die Überschrift der neuen Karte bzw.
		 * „Question {number} of {total}" — auch bei Wechseln durch den Server
		 * (Frist, entfernt, freigegeben). Warten und Weiter tragen dieselbe
		 * Überschrift wie der Start („Ready, …"); dort sagt die Statuszeile, was
		 * sich geändert hat.
		 */
		paceHeadline() {
			if (!this.isPaced) return ''
			switch (this.paceCard) {
			case 'shut':
			case 'closed':
				return t('pulse', 'The quiz is closed.')
			case 'over':
				return t('pulse', 'Quiz finished')
			case 'wait':
				return t('pulse', 'The quiz has not started yet.')
			case 'start':
				return t('pulse', 'Ready, {name}', { name: this.nickname })
			case 'continue':
				return t('pulse', 'Your quiz continues.')
			case 'through':
				return t('pulse', 'You’re through!')
			}
			if (this.paceFinal) return t('pulse', 'Quiz finished')
			if (this.poll && this.progress) return t('pulse', 'Question {number} of {total}', { number: this.progress.k, total: this.progress.n })
			if (!this.nickname && this.paceNotice === 'removed') return t('pulse', 'You were removed from this quiz.')
			if (!this.nickname && this.paceNotice === 'newRound') return t('pulse', 'A new round has started — choose your name.')
			return ''
		},
		paceClosesAt() {
			return (this.paceWindow && this.paceWindow.closesAt) || 0
		},
		// Frist absolut („Fr., 3. Okt., 18:00"), nur solange offen.
		paceUntil() {
			return this.paceClosesAt > 0 && this.paceState === 'open' ? fmtDeadline(this.paceClosesAt) : ''
		},
		// „Closes in %n min" erst in der letzten Viertelstunde, Minuten aufgerundet.
		closesInMin() {
			if (!this.paceUntil) return 0
			const rest = this.paceClosesAt - (this.nowSec + this.serverSkew)
			return rest > 0 && rest <= CLOSING_SOON ? Math.ceil(rest / 60) : 0
		},
	},
	watch: {
		// Korrekturmodus im eigenen Tempo nie hängen lassen (§2.1): wer „Change"
		// tippt und dann wartet, bekäme von /vote nur noch 400 — ein toter
		// Bildschirm bis zum Neuladen. (a) Das lokale Fenster ist um …
		nowSec(now) {
			if (this.isPaced && this.changing && this.fixUntil > 0 && now >= this.fixUntil) this.paceEndChange()
		},
		// … (b) oder der Server meldet die Antwort als endgültig.
		paceAnswerFinal(final) {
			if (final && this.isPaced && this.changing) this.paceEndChange()
		},
		// Neue Frage (pollKey-Wechsel): „Skip question" erst nach 1,5 s scharf, die
		// Antworten nach 0,6 s — ein Doppeltipp auf „Next question" überspringt oder
		// beantwortet so nie die nächste Frage. Der Watcher läuft vor dem Rendern:
		// die neue Frage erscheint schon gesperrt.
		lastPollKey() {
			if (!this.isPaced) return
			this.armSkip()
			this.holdAnswers()
		},
		paceHeadline(text) {
			if (text) this.paceAnnounce = text
		},
	},
	created() {
		if (!this.code) return
		try {
			const raw = window.localStorage.getItem(STORE_PREFIX + this.code)
			const v = raw ? JSON.parse(raw) : null
			if (v && typeof v.nick === 'string' && v.nick) {
				this.paceStored = { nick: v.nick, openedAt: Number(v.openedAt) || 0 }
			}
		} catch (e) {
			// Kein Speicher (privates Fenster, gesperrt) oder kaputter Eintrag:
			// wie beim ersten Besuch.
		}
	},
	// Fokus nach einem eigenen Übergang (Beitritt, Start, Weiter, Überspringen,
	// §2.11): sonst fiele er mit dem getippten Knopf auf <body>. Einmal je Flag.
	updated() {
		if (!this.paceFocusNext) return
		this.paceFocusNext = false
		this.$nextTick(this.paceFocusHeading)
	},
	beforeDestroy() {
		if (this.skipTimer) clearTimeout(this.skipTimer)
		if (this.answerTimer) clearTimeout(this.answerTimer)
	},
	methods: {
		/*
		 * Zustand im eigenen Tempo übernehmen (§2.1). Läuft in applyState VOR den
		 * bestehenden Zeilen — es braucht noch die alte Frage und `voted` — und
		 * nur mit Antworten, die die submitSeq-Wache passiert haben.
		 */
		applyPace(data) {
			const win = data.window || null
			const st = win ? win.state : ''
			// Eingabe verloren? Offene, unbeantwortete Frage mit Eingabe, und das
			// Fenster ist zu (Frist oder geschlossen, während getippt wurde).
			if (this.poll && !this.voted && this.needsSubmit && this.submitReady && st && st !== 'open') this.paceLostDraft = true
			if (st === 'open' && data.poll) this.paceLostDraft = false
			this.paceWindow = win
			this.progress = data.progress || null
			this.myScore = ('myScore' in data) ? data.myScore : null
			if (data.nickname) {
				this.paceNotice = ''
				this.rememberPace({ nick: data.nickname, openedAt: win ? win.openedAt : 0 })
			} else if (this.paceStored && this.paceStored.nick && (st === 'draft' || st === 'open')) {
				// Gleiche Runde (openedAt unverändert) und offen: entfernt. Sonst neue
				// Runde (zurückgesetzt, Probelauf/Tempo umgeschaltet, neu geöffnet).
				this.paceNotice = (st === 'open' && win.openedAt > 0 && win.openedAt === this.paceStored.openedAt) ? 'removed' : 'newRound'
			}
			// Die Auswertung gehört zum Fensterzustand, in dem sie geladen wurde.
			if (this.review && st !== this.reviewState) this.review = null
		},
		rememberPace(entry) {
			const had = this.paceStored
			if (had && had.nick === entry.nick && had.openedAt === entry.openedAt) return
			this.paceStored = entry
			try {
				window.localStorage.setItem(STORE_PREFIX + this.code, JSON.stringify(entry))
			} catch (e) {
				// Ohne Speicher gilt nur der Stand in diesem Tab.
			}
		},
		armSkip() {
			if (this.skipTimer) clearTimeout(this.skipTimer)
			this.skipArmed = false
			this.skipTimer = setTimeout(() => {
				this.skipTimer = null
				this.skipArmed = true
			}, 1500)
		},
		holdAnswers() {
			if (this.answerTimer) clearTimeout(this.answerTimer)
			this.answerArmed = false
			this.answerTimer = setTimeout(() => {
				this.answerTimer = null
				this.answerArmed = true
			}, ANSWER_HOLD_MS)
		},
		// Abfrage-Schleife (mixins/polling.js) im eigenen Tempo einflugig: jeder
		// Abruf hat hier ein Timeout, und ein Handy, das mit einer hängenden
		// Anfrage entsperrt wird, startete sonst eine zweite Kette.
		pollSingleFlight() {
			return this.isPaced
		},
		// Die Überschrift der neuen Ansicht (Karte bzw. Frage) trägt tabindex="-1".
		paceFocusHeading() {
			const root = this.$el
			const h = root && root.querySelector ? root.querySelector('.pace-card h1, .pace-timeup h1.q, .h-app h1.q') : null
			if (h && typeof h.focus === 'function') h.focus()
		},
		// Korrektur abbrechen und die gesendete Antwort wieder zeigen — sonst
		// stünde eine ungesendete Änderung neben „gesendet".
		paceEndChange() {
			this.changing = false
			this.restoreMine()
		},
		// Eingaben aus der gesendeten Antwort (myValue) neu setzen — nur die
		// Quiz-Typen; Auswahl und Wahr/Falsch markiert isPicked über myValue.
		restoreMine() {
			const p = this.poll
			const v = this.myValue
			this.singlePick = null
			this.sheetFor = null
			if (!p || v === null || v === undefined) return
			if (p.type === 'multi' && Array.isArray(v)) this.multiPick = v.slice()
			else if (p.type === 'number') this.numInput = String(v)
			else if (p.type === 'text' && typeof v === 'string') this.textInput = v
			else if (p.type === 'rank' && Array.isArray(v)) this.rankOrder = this.initRank(p, v)
			else if (p.type === 'match' && typeof v === 'object') this.matchPick = this.initMatch(p, v).pick
		},
		/*
		 * Nächste Frage holen (§2.7) — auch „Start quiz" und „Next" auf der
		 * Weiter-Karte. `after` ist immer progress.after, nie poll.id: so heilt
		 * /next auch eine Anfrage, die zwischen Schließen und Starten abbrach.
		 * Die Antwort IST der neue Zustand, ein No-op (Doppeltipp, veraltetes
		 * `after`) ist 200 mit unverändertem Zustand. Das Timeout verhindert
		 * einen dauerhaft toten Knopf; bei Fehlern holt die Schleife den Stand.
		 */
		async nextStep() {
			if (this.nextBusy || this.busy || !this.progress) return // nie, während /vote läuft
			this.nextBusy = true
			this.submitSeq++ // laufende /state-Antworten verwerfen
			try {
				const { data } = await axios.post(this.base('/next'), { after: this.progress.after }, { timeout: 10000 })
				this.online = true
				this.applyState(data)
				this.paceFocusNext = true
			} catch (e) {
				if (e?.response?.status === 404) {
					// Raum weg: dauerhaft aus (Brute-Force-Drossel, wie pollOnce).
					this.roomExists = false
					this.pollStopped = true
					this.pollClear()
					return
				}
				if (!e?.response) this.online = false // Zeitüberschreitung/Netz: der Knopf wird wieder frei
				else showError(e.response.data?.message || t('pulse', 'Could not load the next question.'))
			} finally {
				this.submitSeq++
				this.nextBusy = false
			}
		},
	},
}
