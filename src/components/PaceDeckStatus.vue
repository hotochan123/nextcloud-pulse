<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pace-ds">
		<template v-if="progress">
			<span class="pulse-chip is-live pace-ds-chip" :title="t('pulse', '{count} participants active in the last few seconds', { count: here })"><span class="pulse-chip-dot" />{{ t('pulse', '{count} here', { count: here }) }}</span>
			<span class="pulse-chip is-neutral pace-ds-chip"><PulseIcon name="users" size="1em" /> {{ t('pulse', '{count} joined', { count: joined }) }}</span>
		</template>
		<span v-if="!online" class="pace-ds-off" role="status">{{ t('pulse', '● offline — connecting …') }}</span>
	</div>
</template>

<script>
/*
 * Who is here already? — Status line under the join column in the deck, only
 * in self-paced mode (self-paced spec §1.4, not in the public repository).
 *
 * In draft, people already join before the teacher opens; without
 * this line she would only see that in the run view. At the same time the line
 * keeps the deck current: an expired deadline or a second tab that opens
 * or closes arrives in the deck via `window` within ≤ 10 s.
 *
 * Its own /progress loop (progress-poll, at most one request in flight):
 * draft 5 s, otherwise 10 s; hidden tab = pause, visible = immediately.
 *
 * Emits upwards:
 *   counts   tally (progressCounts) for confirmation texts
 *   skew     server time − laptop time in seconds
 *   window   the window, when state or closesAt changed compared with `win`
 *            (prop, the snapshot from the room JSON)
 *   gone     404/403 — room deleted or no longer mine
 *   not-paced 409 — the room no longer runs self-paced
 */
import { generateUrl } from '@nextcloud/router'
import { t } from '../util/l10n.js'
import { windowState } from '../util/pace.js'
import progressPoll, { progressCounts } from '../mixins/progress-poll.js'
import PulseIcon from './ui/PulseIcon.vue'

export default {
	name: 'PaceDeckStatus',
	components: { PulseIcon },
	mixins: [progressPoll],
	props: {
		code: { type: String, required: true },
		// Room JSON window (snapshot) — comparison basis for `window`.
		win: { type: Object, default: null },
	},
	computed: {
		here() {
			return (this.progress && Number(this.progress.present)) || 0
		},
		joined() {
			return (this.progress && Array.isArray(this.progress.players)) ? this.progress.players.length : 0
		},
	},
	methods: {
		t,
		progressUrl() {
			return generateUrl('/apps/pulse/api/1.0/rooms/' + this.code + '/progress')
		},
		pollDelay() {
			if (document.hidden) return null // pause -> onPollVisibility wakes it up
			const win = (this.progress && this.progress.window) || this.win
			return windowState(win) === 'draft' ? 5000 : 10000
		},
		onProgress(data) {
			this.$emit('counts', progressCounts(data))
			this.$emit('skew', this.serverSkew)
			const w = data.window
			const cur = this.win
			if (w && (!cur || w.state !== cur.state || w.closesAt !== cur.closesAt)) {
				this.$emit('window', w)
			}
		},
		onGone() {
			this.$emit('gone')
		},
		onNotPaced() {
			this.$emit('not-paced')
		},
	},
}
</script>

<style scoped>
.pace-ds { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 12px; }
.pace-ds-chip { font-variant-numeric: tabular-nums; }
.pace-ds-off { flex: 1 0 100%; font-size: 12px; color: var(--pulse-error); }
</style>
