/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Adaptive polling loop — recursive setTimeout instead of a fixed interval:
 * fast while something is running, throttled when idle, paused while the
 * tab is hidden (onPollVisibility wakes it up). Shared by Participant (phone), Screen
 * (projector) and Moderator (control desk); previously copied three times almost verbatim.
 *
 * The view supplies the two variable parts as methods:
 *   · pollDelay(): number|null — wait time until the next fetch in ms
 *                                (null = tab hidden, loop rests)
 *   · pollOnce(): Promise      — exactly ONE fetch (sets data, never throws itself)
 * Optional pollActive(): boolean (default true) — additional gate; the
 * moderator only polls in certain phases (this.polling).
 * Optional pollSingleFlight(): boolean (default false) — at most ONE
 * request in flight. Without it, a tab that comes back while a fetch
 * is still hanging starts a second chain next to the first (both keep scheduling
 * afterwards). Moderated rooms leave it off: there the fetch has no timeout, and
 * exactly this restart brings a hanging loop back.
 *
 * Permanently off (room gone -> 404, or view destroyed): pollStopped = true.
 * Important, because a tab that keeps hammering would otherwise trigger the
 * pulseRoomCode brute-force throttle (429 "Too Many Requests").
 */
export default {
	data() {
		return {
			pollTimer: null,    // current setTimeout handle
			pollStopped: false, // permanently off (room gone / destroyed)
			idleStreak: 0,      // "unchanged" (204) in a row -> throttling
			pollBusy: false,    // a fetch in flight (single-flight only)
			pollAgain: false,   // immediate fetch requested in the meantime
		}
	},
	methods: {
		// Default gate; views with phases (moderator) override it.
		pollActive() {
			return true
		},
		pollScheduleNext() {
			if (this.pollStopped || !this.pollActive()) return
			const d = this.pollDelay()
			if (d === null) return // tab hidden: onPollVisibility wakes it up
			this.pollTimer = setTimeout(this.pollTick, d)
		},
		// Off by default; phone and projector switch it on in self-paced mode.
		pollSingleFlight() {
			return false
		},
		pollTick() {
			if (!this.pollSingleFlight()) {
				this.pollOnce().finally(this.pollScheduleNext)
				return
			}
			// Single-flight: if a fetch is already running, just note the request — the
			// running chain catches up immediately once it is back.
			if (this.pollBusy) {
				this.pollAgain = true
				return
			}
			this.pollClear()
			this.pollBusy = true
			this.pollOnce().finally(() => {
				this.pollBusy = false
				if (this.pollAgain) {
					this.pollAgain = false
					if (!this.pollStopped && this.pollActive() && this.pollDelay() !== null) {
						this.pollTick()
						return
					}
				}
				this.pollScheduleNext()
			})
		},
		onPollVisibility() {
			if (this.pollStopped || !this.pollActive()) return
			if (!document.hidden) {
				// Back in the foreground -> catch up immediately.
				if (this.pollTimer) clearTimeout(this.pollTimer)
				this.pollTick()
			}
		},
		pollClear() {
			if (this.pollTimer) {
				clearTimeout(this.pollTimer)
				this.pollTimer = null
			}
		},
	},
}
