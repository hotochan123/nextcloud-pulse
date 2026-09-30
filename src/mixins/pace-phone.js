/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * The phone in self-paced mode (stage 4 spec, §2; design notes, not in the
 * public repository): data, computeds, watchers, nextStep, verdict band, skip
 * and answer lock, focus and announcements (§2.5, §2.11). The card texts live
 * in the template of Participant.vue — the scoped classes (.center, .code-badge,
 * .you, .submit …) only apply there. The pure decisions (paceCard, canNext,
 * phoneDelay) live in util/pace.js and are tested without a browser.
 *
 * Moderated mode stays exactly as before: every branch hangs on isPaced, and
 * applyPace only runs for a state with room.pace === 'self'.
 */
import axios from '@nextcloud/axios'
import { t } from '../util/l10n.js'
import { showError, serverMessage } from '../toast.js'
import { fmtDeadline } from '../util/format.js'
import { CLOSING_SOON, canNext, paceCard, windowState } from '../util/pace.js'
import { PHONE_TIMEOUT } from '../util/routes.js'

// Remembered name per room: {nick, openedAt}. Survives a reload and a
// discarded background tab — only this way can the phone tell "removed" from
// "new round" when the server only reports `nickname: null`.
const STORE_PREFIX = 'pulse-pace:'

// Lock on answer taps after every new question (ms). Covers the second tap
// of a double tap on "Next question", even when /next is back faster
// than the finger; anyone reading the question notices nothing.
const ANSWER_HOLD_MS = 600

export default {
	data() {
		return {
			pace: '',             // room.pace from /state ('' = moderated)
			paceWindow: null,     // window from /state: state, deadline, settings
			progress: null,       // {k, n, started, finished, timeUp, currentPollId, after}
			myScore: null,        // own points (null = hidden, "At the end")
			nextBusy: false,      // /next in flight
			paceNotice: '',       // ''|'removed'|'newRound' — notice on the name screen
			paceStored: null,     // {nick, openedAt} — remembered name for this room
			paceLostDraft: false, // input present, but the window closed before sending
			reviewState: '',      // window state when the review was opened
			skipArmed: false,     // "Skip question" only armed 1.5 s after every new question
			skipTimer: null,      // its timer (beforeDestroy clears it)
			answerArmed: true,    // answer taps: after every new question only armed after ANSWER_HOLD_MS
			answerTimer: null,    // its timer (beforeDestroy clears it)
			paceAnnounce: '',     // announcement for screen readers (aria-live, §2.11)
			paceFocusNext: false, // after an own transition: focus on the new heading
		}
	},
	computed: {
		isPaced() {
			return this.isQuiz && this.pace === 'self'
		},
		// A deadline that has passed locally already counts as closed; the next
		// fetch confirms it. Server time via serverSkew, never the bare clock.
		paceState() {
			return windowState(this.paceWindow, this.nowSec + this.serverSkew)
		},
		// Closed or released: joining is no longer possible, so no
		// name screen (the server would reject it with 400 anyway).
		paceNoJoin() {
			return this.isPaced && (this.paceState === 'closed' || this.paceState === 'released')
		},
		// Released with a leaderboard: then the existing final standings (branch 7).
		paceFinal() {
			return this.isPaced && this.paceState === 'released'
				&& Array.isArray(this.leaderboard) && this.leaderboard.length > 0
		},
		// A verdict only once the answer is final — none before that (no green flash).
		paceVerdict() {
			return this.myResult && this.myResult.final ? this.myResult.verdict : null
		},
		paceAnswerFinal() {
			return !!(this.myResult && this.myResult.final)
		},
		/*
		 * Answer taps briefly locked (§2.5, gate 4.4b): "Next question" sits in the
		 * middle when time is up and at the bottom otherwise — exactly where the cards
		 * of the next question land afterwards (True/False: B) or its "Submit" (ordering
		 * is ready to submit at once). Choice and True/False submit immediately in the
		 * quiz; the second tap of a double tap would thus submit an answer nobody
		 * chose. Always false when moderated.
		 */
		paceHold() {
			return this.isPaced && !this.answerArmed
		},
		paceLast() {
			return !!this.progress && this.progress.k >= this.progress.n
		},
		// Card without a question (§2.2): '' = none, then the other branches apply.
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
		// Verdict band of the submit zone (§2.5): class, icon, word — never colour alone.
		// Neutral before the final verdict (no green flash); "At the end" already
		// says the same now as afterwards. Never the solution, never a distribution.
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
		 * What the announcement region says (§2.11): the heading of the new card or
		 * "Question {number} of {total}" — also on changes made by the server
		 * (deadline, removed, released). The wait and continue cards carry the same
		 * heading as the start ("Ready, …"); there the status line says what
		 * has changed.
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
		// Deadline as an absolute time ("Fri, Oct 3, 18:00"), only while open.
		paceUntil() {
			return this.paceClosesAt > 0 && this.paceState === 'open' ? fmtDeadline(this.paceClosesAt) : ''
		},
		// "Closes in %n min" only in the last quarter of an hour, minutes rounded up.
		closesInMin() {
			if (!this.paceUntil) return 0
			const rest = this.paceClosesAt - (this.nowSec + this.serverSkew)
			return rest > 0 && rest <= CLOSING_SOON ? Math.ceil(rest / 60) : 0
		},
	},
	watch: {
		// Never leave the correction mode hanging in self-paced mode (§2.1): anyone who
		// taps "Change" and then waits would only get 400 from /vote — a dead
		// screen until a reload. (a) The local window is over …
		nowSec(now) {
			if (this.isPaced && this.changing && this.fixUntil > 0 && now >= this.fixUntil) this.paceEndChange()
		},
		// … (b) or the server reports the answer as final.
		paceAnswerFinal(final) {
			if (final && this.isPaced && this.changing) this.paceEndChange()
		},
		// New question (pollKey change): "Skip question" only armed after 1.5 s, the
		// answers after 0.6 s — so a double tap on "Next question" never skips or
		// answers the next question. The watcher runs before rendering:
		// the new question already appears locked.
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
			// No storage (private window, blocked) or a broken entry:
			// same as on the first visit.
		}
	},
	// Focus after an own transition (join, start, next, skip,
	// §2.11): otherwise it would fall to <body> with the tapped button. Once per flag.
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
		 * Take over the self-paced state (§2.1). Runs in applyState BEFORE the
		 * existing lines — it still needs the old question and `voted` — and
		 * only with responses that passed the submitSeq guard.
		 */
		applyPace(data) {
			const win = data.window || null
			const st = win ? win.state : ''
			// Input lost? An open, unanswered question with input, and the
			// window is shut (deadline or closed while typing).
			if (this.poll && !this.voted && this.needsSubmit && this.submitReady && st && st !== 'open') this.paceLostDraft = true
			if (st === 'open' && data.poll) this.paceLostDraft = false
			this.paceWindow = win
			this.progress = data.progress || null
			this.myScore = ('myScore' in data) ? data.myScore : null
			if (data.nickname) {
				this.paceNotice = ''
				this.rememberPace({ nick: data.nickname, openedAt: win ? win.openedAt : 0 })
			} else if (this.paceStored && this.paceStored.nick && (st === 'draft' || st === 'open')) {
				// Same round (openedAt unchanged) and open: removed. Otherwise a new
				// round (reset, practice run/pace switched, reopened).
				this.paceNotice = (st === 'open' && win.openedAt > 0 && win.openedAt === this.paceStored.openedAt) ? 'removed' : 'newRound'
			}
			// The review belongs to the window state in which it was loaded.
			if (this.review && st !== this.reviewState) this.review = null
		},
		rememberPace(entry) {
			const had = this.paceStored
			if (had && had.nick === entry.nick && had.openedAt === entry.openedAt) return
			this.paceStored = entry
			try {
				window.localStorage.setItem(STORE_PREFIX + this.code, JSON.stringify(entry))
			} catch (e) {
				// Without storage only the state in this tab counts.
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
		// Polling loop (mixins/polling.js) single-flight in self-paced mode: every
		// fetch has a timeout here, and a phone that is unlocked with a hanging
		// request would otherwise start a second chain.
		pollSingleFlight() {
			return this.isPaced
		},
		// The heading of the new view (card or question) carries tabindex="-1".
		paceFocusHeading() {
			const root = this.$el
			const h = root && root.querySelector ? root.querySelector('.pace-card h1, .pace-timeup h1.q, .h-app h1.q') : null
			if (h && typeof h.focus === 'function') h.focus()
		},
		// Cancel the correction and show the sent answer again — otherwise
		// an unsent change would sit next to "sent".
		paceEndChange() {
			this.changing = false
			this.restoreMine()
		},
		// Reset the inputs from the sent answer (myValue) — only the
		// quiz types; choice and True/False are marked by isPicked via myValue.
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
		 * Fetch the next question (§2.7) — also "Start quiz" and "Next" on the
		 * continue card. `after` is always progress.after, never poll.id: that way
		 * /next also heals a request that broke off between closing and starting.
		 * The response IS the new state; a no-op (double tap, stale
		 * `after`) is a 200 with an unchanged state. The timeout prevents
		 * a permanently dead button; on errors the loop fetches the state.
		 */
		async nextStep() {
			if (this.nextBusy || this.busy || !this.progress) return // never while /vote is running
			this.nextBusy = true
			this.submitSeq++ // discard /state responses still in flight
			try {
				const { data } = await axios.post(this.base('/next'), { after: this.progress.after }, { timeout: PHONE_TIMEOUT })
				this.online = true
				this.applyState(data)
				this.paceFocusNext = true
			} catch (e) {
				if (e?.response?.status === 404) {
					// Room gone: permanently off (brute-force throttle, like pollOnce).
					this.roomExists = false
					this.pollStopped = true
					this.pollClear()
					return
				}
				if (!e?.response) this.online = false // timeout/network: the button becomes free again
				else showError(serverMessage(e, t('pulse', 'Could not load the next question.')))
			} finally {
				this.submitSeq++
				this.nextBusy = false
			}
		},
	},
}
