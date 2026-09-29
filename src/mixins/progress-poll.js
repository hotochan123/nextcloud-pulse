/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * The moderator's self-paced /progress loop — shared by the status line in
 * the deck (PaceDeckStatus) and the run view (PaceRun).
 *
 * Builds on pollingMixin and replaces its pollTick: at most ONE request in
 * flight. Before, a refresh() during a running request started a second
 * chain, and pollClear only caught one of the two timers.
 * Now a tick during a request only records the wish (wantRefresh); the
 * running chain catches up immediately as soon as it is back.
 * refresh() also bumps reqSeq: the response that is currently in flight
 * may be older than the action before it and is discarded.
 *
 * The component provides:
 *   · progressUrl(): string
 *   · progressParams(): object   optional, e.g. { scores: 1 }
 *   · pollDelay(): number|null    as with pollingMixin (null = tab hidden)
 *   · onProgress(data)            optional, after every 200 response
 *   · onGone()                    404/403: room gone or no longer mine
 *   · onNotPaced()                409: the room is no longer running self-paced
 * Both error cases end the loop for good (pollStopped).
 */
import axios from '@nextcloud/axios'
import pollingMixin from './polling.js'

/**
 * Counts from a /progress response — the same shape that
 * Moderator.fetchCounts delivers and lossText reads (spec §0.9, not in the
 * public repository).
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

export default {
	mixins: [pollingMixin],
	data() {
		return {
			progress: null,
			version: '',
			online: true,
			serverSkew: 0,
			inflight: false,  // one request in flight
			reqSeq: 0,        // responses with an older number are stale
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
		// Defaults — the component overrides what it needs.
		progressParams() {
			return {}
		},
		onProgress() {},
		onGone() {},
		onNotPaced() {},

		pollTick() {
			if (this.inflight) {
				// The running chain takes over — never two requests at once.
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
				if (seq !== this.reqSeq) return // stale: refresh() came afterwards
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
		// After each of our own actions: fetch without a version, discard stale responses.
		refresh() {
			this.version = ''
			this.reqSeq++
			this.pollTick()
		},
	},
}
