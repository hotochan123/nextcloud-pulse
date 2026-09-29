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
 * Countdown als Ring im Kopf (Redesign §6.5).
 *
 * Vorher war er das lauteste Element der Quiz-Bühne: ein fetter Balken über die
 * volle Breite mit der Zahl in Überschriftgröße. Er ist wichtig, aber er ist
 * nicht die Frage — also steht er auf einer Achse mit ihr und bleibt kleiner.
 *
 * Zwei Stufen, mehr nicht: neutral bis 10 Sekunden, darunter dringlich. Ein
 * weiterer Wechsel bei 5 oder 3 Sekunden wäre Lärm ohne Zusatznutzen.
 */
const R = 45

export default {
	name: 'CountdownRing',
	props: {
		remaining: { type: Number, required: true },
		// Gesamtdauer der Frage in Sekunden (für den Restlauf des Rings).
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
/* Durchmesser 1,9 em der Fragegröße — die Elternzeile setzt font-size auf
   --scr-q-fs, damit Ring und Frage gemeinsam skalieren. */
.cdr { position: relative; width: 1.9em; height: 1.9em; flex: 0 0 auto; display: grid; place-items: center; color: var(--pulse-meta); }
.cdr svg { position: absolute; inset: 0; width: 100%; height: 100%; transform: rotate(-90deg); }
.cdr circle { fill: none; stroke-width: 5.3; }
.cdr-track { stroke: var(--pulse-border); }
.cdr-run { stroke: currentColor; transition: stroke-dashoffset 1s linear; }
/* Kleiner als die Frage — zwei Stellen passen in den Ring, ohne ihn zu sprengen. */
.cdr-num { position: relative; font-size: 0.85em; font-weight: 900; line-height: 1; font-variant-numeric: tabular-nums; color: var(--pulse-text); }
.cdr.is-urgent { color: var(--pulse-countdown-urgent); }
.cdr.is-urgent .cdr-num { color: var(--pulse-countdown-urgent); }
/* Unter 10 Sekunden pulst der Ring einmal je Sekunde. */
.cdr.is-urgent svg { animation: cdr-beat 1s ease-in-out infinite; }
.cdr.is-done { color: var(--pulse-meta); }
@keyframes cdr-beat { 0%, 100% { opacity: 1; } 50% { opacity: 0.45; } }

@media (prefers-reduced-motion: reduce) {
	.cdr-run { transition: none; }
	.cdr.is-urgent svg { animation: none; }
}
</style>
