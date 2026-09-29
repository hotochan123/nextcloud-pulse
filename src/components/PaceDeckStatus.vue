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
 * Wer ist schon da? — Statuszeile unter der Beitritts-Spalte im Deck, nur im
 * eigenen Tempo (Spezifikation §1.4).
 *
 * Im Entwurf treten die Leute schon bei, bevor die Lehrkraft öffnet; ohne
 * diese Zeile sähe sie das erst in der Laufansicht. Zugleich hält die Zeile
 * das Deck aktuell: eine abgelaufene Frist oder ein zweiter Tab, der öffnet
 * oder schließt, kommt über `window` binnen ≤ 10 s im Deck an.
 *
 * Eigene /progress-Schleife (progress-poll, höchstens eine Anfrage unterwegs):
 * Entwurf 5 s, sonst 10 s; versteckter Tab = Pause, sichtbar = sofort.
 *
 * Meldet nach oben:
 *   counts   Zählstand (progressCounts) für Bestätigungstexte
 *   skew     Serverzeit − Laptopzeit in Sekunden
 *   window   das Fenster, wenn sich state oder closesAt gegenüber `win`
 *            (Prop, der Schnappschuss aus dem Raum-JSON) geändert haben
 *   gone     404/403 — Raum gelöscht oder nicht mehr meiner
 *   not-paced 409 — der Raum läuft nicht mehr im eigenen Tempo
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
		// Raum-JSON-Fenster (Schnappschuss) — Vergleichsbasis für `window`.
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
			if (document.hidden) return null // pausieren -> onPollVisibility weckt auf
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
