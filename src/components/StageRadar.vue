<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="splot-wrap">
		<div class="splot" :class="{ 'is-two-line': twoLine }">
			<svg viewBox="0 0 100 100" role="img" :aria-label="ariaLabel" preserveAspectRatio="xMidYMid meet">
				<polygon v-for="(ring, i) in rings" :key="'r' + i" :points="ring" fill="none" stroke="var(--pulse-border)" stroke-width=".25" />
				<line v-for="(sp, i) in spokes" :key="'s' + i" x1="50" y1="50" :x2="sp[0]" :y2="sp[1]" stroke="var(--pulse-border)" stroke-width=".2" />
				<polygon :points="spread" fill="var(--pulse-primary)" opacity=".16" fill-rule="evenodd" />
				<polygon :points="neutralRing" fill="none" stroke="var(--pulse-meta)" stroke-width=".3" stroke-dasharray="1.2 1" />
				<polygon :points="avgPoly" fill="var(--pulse-primary)" opacity=".3" />
				<polygon :points="avgPoly" fill="none" stroke="var(--pulse-primary)" stroke-width=".7" />
				<circle v-for="(d, i) in avgDots" :key="'d' + i" :cx="d[0]" :cy="d[1]" r=".9" fill="var(--pulse-primary)" />
			</svg>
			<!-- Name UND Wert an der Speiche: der Umweg über Ziffern-Plaketten und
			     eine Liste am anderen Bühnenrand entfällt (§7.3). -->
			<span v-for="sp in labels" :key="sp.id" class="splot-spoke" :style="sp.style">
				{{ sp.label }} <i>{{ sp.value }}</i>
			</span>
		</div>
		<div class="splot-legend">
			<span><i class="splot-sw" style="background: var(--pulse-primary)" />{{ t('pulse', 'Average') }}</span>
			<span><i class="splot-sw" style="background: var(--pulse-primary); opacity: .25" />{{ t('pulse', 'Spread (min–max)') }}</span>
			<span><i class="splot-sw splot-sw--neutral" />{{ t('pulse', 'Neutral ({value})', { value: neutralLabel }) }}</span>
		</div>
	</div>
</template>

<script>
/*
 * Spektrum am Beamer (Redesign §7.3).
 *
 * Der Radar bekommt die ganze Bühne, und die Aspektnamen stehen an ihren
 * Speichen. Vorher stand der Name bei x ≈ 660 und der zugehörige Wert bei
 * x ≈ 1860 — aus dem Saal war das ein Nachschlagen über die halbe Leinwand.
 *
 * Der Fall „zwei Aspekte" gehört nicht hierher: zwei Speichen spannen keine
 * Fläche auf. Die Elternansicht zeigt dann Zeilen (§7.3).
 */
import { fmtNum } from '../util/format.js'

// Radius der Datenfläche und der Beschriftung im 100er-viewBox. Bei sieben
// oder acht Aspekten rücken die Namen weiter nach außen: der Bogen zwischen
// zwei Speichen wächst mit dem Radius, und genau daran hängt, ob zwei
// Nachbarnamen sich überlagern.
const R = 36
const LR = 41
const LR_MANY = 46

export default {
	name: 'StageRadar',
	props: {
		// [{ id, label, average, min, max, n }]
		rows: { type: Array, default: () => [] },
		// Oberes Ende der Skala.
		max: { type: Number, default: 5 },
		ariaLabel: { type: String, default: '' },
	},
	computed: {
		// Ab sieben Aspekten dürfen die Namen zweizeilig werden (§7.3).
		twoLine() {
			return this.rows.length >= 7
		},
		neutralLabel() {
			return fmtNum(this.max / 2)
		},
		avgPoly() {
			return this.poly(this.rows.map((r) => r.average), R)
		},
		avgDots() {
			return this.rows.map((r, i) => this.pt(i, (r.average / this.scale) * R))
		},
		neutralRing() {
			return this.poly(this.rows.map(() => this.scale / 2), R)
		},
		// Streuung als Ring zwischen min und max (Fläche mit Loch, evenodd).
		spread() {
			const hi = this.poly(this.rows.map((r) => r.max), R)
			const lo = this.poly(this.rows.map((r) => r.min), R).split(' ').reverse().join(' ')
			return hi + ' ' + lo
		},
		spokes() {
			return this.rows.map((r, i) => this.pt(i, R))
		},
		rings() {
			return [0.25, 0.5, 0.75, 1].map((f) => this.poly(this.rows.map(() => this.scale), R * f))
		},
		labels() {
			return this.rows.map((row, i) => {
				const [x, y] = this.pt(i, this.twoLine ? LR_MANY : LR)
				const dx = x - 50, dy = y - 50
				// Ausrichtung nach Winkel: rechte Hälfte linksbündig, linke Hälfte
				// rechtsbündig. Senkrecht wird nach INNEN gesetzt — außen hinge der
				// Name über dem Bühnenrand, und die Reserve dafür ginge vom
				// Quadrat ab (statt 656 px nur noch 528).
				const tx = Math.abs(dx) < 3 ? '-50%' : (dx > 0 ? '0' : '-100%')
				const ty = Math.abs(dy) < 3 ? '-50%' : (dy > 0 ? '-100%' : '0')
				return {
					id: row.id,
					label: row.label,
					value: fmtNum(row.average),
					style: { left: x + '%', top: y + '%', transform: `translate(${tx}, ${ty})` },
				}
			})
		},
		scale() {
			return this.max || 1
		},
	},
	methods: {
		pt(i, r) {
			const a = (-90 + i * 360 / this.rows.length) * Math.PI / 180
			const r1 = (v) => Math.round(v * 100) / 100
			return [r1(50 + Math.cos(a) * r), r1(50 + Math.sin(a) * r)]
		},
		poly(values, r) {
			return values.map((v, i) => this.pt(i, (v / this.scale) * r).join(',')).join(' ')
		},
	},
}
</script>

<style scoped>
.splot-wrap {
	display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0;
	box-sizing: border-box;
	/* Reserve für die Beschriftungen außerhalb des Quadrats. */
	/* Nur seitlich Reserve: die Namen stehen links und rechts außerhalb des
	   Quadrats, senkrecht dagegen innen (siehe labels()). So bleibt die volle
	   Höhe für den Radar — §7.10 verlangt mindestens 600 px. */
	padding: 0 5em;
}
/* Quadratisch, Höhe aus dem Restplatz, Breite über aspect-ratio. align-self
   verhindert das Strecken in der Querachse — sonst käme die Breite aus der
   Bühne und aspect-ratio sprengte die Höhe. */
.splot { position: relative; flex: 1 1 auto; min-height: 0; align-self: center; aspect-ratio: 1; }
.splot svg { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; }
.splot-spoke {
	position: absolute; font-weight: 700; white-space: nowrap; line-height: 1.15;
	padding-block: 0.14em; color: var(--pulse-text);
}
.splot.is-two-line .splot-spoke { white-space: normal; max-width: 4.6em; }
.splot-spoke i { font-style: normal; color: var(--pulse-primary); font-variant-numeric: tabular-nums; }
/* Legende direkt unter dem Radar, Klasse C — nicht am Bühnenrand. */
.splot-legend { flex: 0 0 auto; display: flex; flex-wrap: wrap; justify-content: center; gap: 1.2em; padding-top: 0.3em; font-size: 0.55em; color: var(--pulse-meta); }
.splot-legend span { display: inline-flex; align-items: center; gap: 0.4em; }
.splot-sw { width: 1.1em; height: 0.6em; border-radius: 0.12em; }
.splot-sw--neutral { border: 0.12em dashed var(--pulse-meta); height: 0; }
</style>
