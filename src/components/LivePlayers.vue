<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<details class="live-players" :open="open" @toggle="onToggle">
		<summary class="live-players-sum">
			<PulseIcon name="users" size="1em" />
			<span>{{ t('pulse', 'Participants') }}</span>
			<span v-if="loaded" class="pulse-chip is-neutral live-players-chip">{{ t('pulse', '{count} joined', { count: players.length }) }}</span>
			<span v-if="joinsLocked" class="pulse-chip is-locked live-players-chip"><PulseIcon name="lock" size="1em" /> {{ t('pulse', 'Joining locked') }}</span>
		</summary>
		<p class="live-players-hint">{{ t('pulse', 'Remove names that do not belong in the quiz. Once everyone is in, lock joining in the menu.') }}</p>
		<ul v-if="players.length" class="live-players-list">
			<li v-for="p in players" :key="p.playerId" class="live-players-row">
				<span class="live-players-name">{{ p.nickname }}</span>
				<!-- Exactly one entry, and it is red — the same as in the self-paced run view. -->
				<PulseMenu :items="rowMenu(p)" :label="t('pulse', 'Actions for {name}', { name: p.nickname })" small />
			</li>
		</ul>
		<p v-else-if="loaded" class="live-players-empty">{{ t('pulse', 'Nobody has joined yet — share the code.') }}</p>
	</details>
</template>

<script>
/*
 * Player administration of a moderated (live) quiz, in the moderator's
 * private panel and under the final standings (security review L1). Anyone
 * with the code can put a name on the podium; here the host removes it
 * again — with the same /pace action and the same confirmation as the
 * self-paced run view (PaceRun). "Lock joining" is the checkbox in the menu
 * of the presentation and of the deck; this list only shows the state.
 *
 * The names come from the moderator's /leaderboard, which carries the
 * `playerId` that removePlayer takes. Sorted by name, without points: with
 * "Reveal at the end" the panel shows no standings, and a list for removing
 * names should not become one. Fetched only while the list is open — on
 * opening, every 5 s (not while the tab is hidden) and after each removal.
 *
 * Emits: room(json) — the room after /pace (joinsLocked) · removed — a
 * player is gone, the parent refreshes its leaderboard · conflict — 409, the
 * room is no longer a quiz room.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '../util/l10n.js'
import { showError } from '../toast.js'
import { pulseConfirm } from '../util/confirm.js'
import PulseIcon from './ui/PulseIcon.vue'
import PulseMenu from './ui/PulseMenu.vue'

const TIMEOUT = 15000
const REFRESH = 5000

export default {
	name: 'LivePlayers',
	components: { PulseIcon, PulseMenu },
	props: {
		code: { type: String, required: true },
		joinsLocked: { type: Boolean, default: false },
	},
	data() {
		return {
			open: false,
			loaded: false,
			rows: [],
			busy: false,
		}
	},
	computed: {
		players() {
			return this.rows
				.filter((r) => r && Number.isInteger(r.playerId) && r.playerId > 0)
				.slice()
				.sort((a, b) => String(a.nickname).localeCompare(String(b.nickname)))
		},
	},
	watch: {
		// Another room in the same view: nothing of the old list may stay.
		code() {
			this.rows = []
			this.loaded = false
			if (this.open) this.load()
		},
	},
	created() {
		// Outside of data(): neither needs to be reactive.
		this.timer = null
		this.seq = 0
	},
	beforeDestroy() {
		this.stop()
	},
	methods: {
		t,
		onToggle(e) {
			this.open = e.target.open
			if (this.open) {
				this.load()
			} else {
				this.stop()
			}
		},
		stop() {
			if (this.timer) clearTimeout(this.timer)
			this.timer = null
			this.seq++ // a response still in flight is no longer wanted
		},
		schedule() {
			if (this.timer) clearTimeout(this.timer)
			this.timer = this.open ? setTimeout(() => this.load(), REFRESH) : null
		},
		async load() {
			if (!this.open) return
			if (document.hidden) {
				this.schedule()
				return
			}
			const seq = ++this.seq
			try {
				const { data } = await axios.get(generateUrl('/apps/pulse/api/1.0/rooms/' + this.code + '/leaderboard'), { timeout: TIMEOUT })
				if (seq !== this.seq) return
				this.rows = Array.isArray(data) ? data : []
				this.loaded = true
			} catch (e) {
				if (seq !== this.seq) return
				// Room gone: the presentation notices that itself; stop asking.
				if (e?.response?.status === 404) {
					this.open = false
					return
				}
			}
			this.schedule()
		},
		rowMenu(p) {
			return [{ key: 'remove', label: t('pulse', 'Remove from the quiz'), icon: 'trash', danger: true, act: () => this.confirmRemove(p) }]
		},
		// The name becomes free; the answers and points go with it.
		async confirmRemove(p) {
			if (this.busy) return
			if (!await pulseConfirm({
				title: t('pulse', 'Remove {name}?', { name: p.nickname }),
				text: t('pulse', 'The answers of {name} are deleted and the name becomes free. They can join again, but start from zero points — unless joining is locked.', { name: p.nickname }),
				confirmLabel: t('pulse', 'Remove person'),
				cancelLabel: t('pulse', 'Cancel'),
				danger: true,
			})) return
			this.busy = true
			try {
				const { data } = await axios.post(generateUrl('/apps/pulse/api/1.0/rooms/' + this.code + '/pace'), { action: 'removePlayer', playerId: p.playerId }, { timeout: TIMEOUT })
				this.rows = this.rows.filter((r) => r.playerId !== p.playerId)
				this.$emit('room', data)
				this.$emit('removed')
			} catch (e) {
				showError(e?.response?.data?.message || t('pulse', 'Could not remove the person.'))
				if (e?.response?.status === 409) this.$emit('conflict')
			} finally {
				this.busy = false
			}
			this.load()
		},
	},
}
</script>

<style scoped>
.live-players { margin-block-start: 8px; padding-block-start: 8px; border-block-start: 1px solid var(--pulse-border); font-size: 14px; }
.live-players-sum { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 8px; padding: 6px 0; font-weight: 700; color: var(--pulse-text-2); cursor: pointer; }
.live-players-sum:focus-visible { outline: 2px solid var(--pulse-primary); outline-offset: 2px; }
.live-players-chip { font-weight: 600; }
.live-players-hint { margin: 2px 0 8px; font-size: 12px; color: var(--pulse-text-2); }
.live-players-list { list-style: none; margin: 0; padding: 0; max-height: 240px; overflow-y: auto; }
.live-players-row { display: flex; align-items: center; gap: 8px; min-height: 36px; padding-inline-start: 4px; border-block-end: 1px solid var(--pulse-border); }
.live-players-row:last-child { border-block-end: 0; }
.live-players-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }
.live-players-empty { margin: 0; color: var(--pulse-text-2); }
</style>
