<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="cdr" :class="{ 'is-urgent': urgent, 'is-done': remaining <= 0 }"
		role="status" :aria-label="n('pulse', '%n second left', '%n seconds left', Math.max(0, remaining))">
		<svg viewBox="0 0 100 100" aria-hidden="true">
			<circle class="cdr-track" cx="50" cy="50" :r="R" />
			<circle class="cdr-run" cx="50" cy="50" :r="R"
				:stroke-dasharray="circumference" :stroke-dashoffset="offset" />
		</svg>
		<span class="cdr-num">{{ Math.max(0, remaining) }}</span>
	</div>
</template>

<script>
/*
 * Countdown as a ring in the header (design notes §6.5, not in the public repository).
 *
 * Before, it was the loudest element on the quiz stage: a heavy bar across the
 * full width with the number at heading size. It matters, but it is
 * not the question — so it sits on one axis with it and stays smaller.
 *
 * Two levels, no more: neutral down to 10 seconds, urgent below that. Another
 * change at 5 or 3 seconds would be noise with no added value.
 */
const R = 45

export default {
	name: 'CountdownRing',
	props: {
		remaining: { type: Number, required: true },
		// Total duration of the question in seconds (for the ring's remaining arc).
		total: { type: Number, default: 0 },
	},
	data() {
		return { R }
	},
	computed: {
		circumference() {
			return Math.round(2 * Math.PI * R * 10) / 10
		},
		offset() {
			if (!this.total) return 0
			const left = Math.max(0, Math.min(1, this.remaining / this.total))
			return Math.round(this.circumference * (1 - left) * 10) / 10
		},
		urgent() {
			return this.remaining > 0 && this.remaining <= 10
		},
	},
}
</script>

<style scoped>
/* Diameter 1.9 em of the question size — the parent row sets font-size to
   --scr-q-fs so that ring and question scale together. */
.cdr { position: relative; width: 1.9em; height: 1.9em; flex: 0 0 auto; display: grid; place-items: center; color: var(--pulse-meta); }
.cdr svg { position: absolute; inset: 0; width: 100%; height: 100%; transform: rotate(-90deg); }
.cdr circle { fill: none; stroke-width: 5.3; }
.cdr-track { stroke: var(--pulse-border); }
.cdr-run { stroke: currentColor; transition: stroke-dashoffset 1s linear; }
/* Smaller than the question — two digits fit into the ring without bursting it. */
.cdr-num { position: relative; font-size: 0.85em; font-weight: 900; line-height: 1; font-variant-numeric: tabular-nums; color: var(--pulse-text); }
.cdr.is-urgent { color: var(--pulse-countdown-urgent); }
.cdr.is-urgent .cdr-num { color: var(--pulse-countdown-urgent); }
/* Below 10 seconds the ring pulses once per second. */
.cdr.is-urgent svg { animation: cdr-beat 1s ease-in-out infinite; }
.cdr.is-done { color: var(--pulse-meta); }
@keyframes cdr-beat { 0%, 100% { opacity: 1; } 50% { opacity: 0.45; } }

@media (prefers-reduced-motion: reduce) {
	.cdr-run { transition: none; }
	.cdr.is-urgent svg { animation: none; }
}
</style>
