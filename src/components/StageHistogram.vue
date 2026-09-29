<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="shist">
		<!-- Band 1: der Median steht ÜBER den Säulen … -->
		<div class="shist-med">
			<span v-if="medPct !== null" class="shist-med-lbl" :data-flip="medPct > 88 ? '' : null" :style="{ left: medPct + '%' }">
				{{ t('pulse', 'median {value}', { value: num(median) }) }}
			</span>
		</div>

		<!-- Zwei Rasterzeilen statt einer Spalte je Wert: nur so hat der
		     Auftragsbereich eine feste Höhe, gegen die die Prozenthöhe der Säule
		     rechnen kann. In einer inhaltshohen Spalte bliebe sie bei 0. -->
		<div class="shist-cols" :style="gridStyle">
			<span v-if="medPct !== null" class="shist-med-line" :style="{ left: medPct + '%' }" aria-hidden="true" />
			<span v-for="row in rows" :key="'c' + row.value" class="shist-cnt" :data-zero="row.count ? null : ''">{{ row.count }}</span>
			<div v-for="row in rows" :key="'b' + row.value" class="shist-plot" :data-zero="row.count ? null : ''">
				<div class="shist-bar" :style="{ height: barPct(row.count) + '%' }" />
			</div>
		</div>

		<div class="shist-axis" :style="gridStyle">
			<span v-for="row in rows" :key="row.value">{{ row.value }}</span>
		</div>

		<!-- … und der Mittelwert UNTER ihnen, auf der Achse. Zwei Bänder heißt:
		     die beiden können sich nicht überlagern, egal wie nah die Werte
		     beieinanderliegen (§7.2, löst N2). -->
		<div class="shist-avg">
			<span v-if="avgPct !== null" class="shist-avg-mark" :style="{ left: avgPct + '%' }">
				<span class="shist-tri" aria-hidden="true" />
				<span class="shist-avg-lbl">Ø {{ num(average) }}</span>
			</span>
		</div>

		<div v-if="minLabel || maxLabel" class="shist-poles">
			<span>{{ minLabel }}</span>
			<span>{{ maxLabel }}</span>
		</div>
	</div>
</template>

<script>
/*
 * Skala einzel am Beamer (Redesign §7.2).
 *
 * Die Säulen füllen die Bühne statt bei 44 % ihrer Höhe zu enden, und die
 * beiden Kennwerte liegen in getrennten Bändern — Median über den Säulen,
 * Mittelwert auf der Achse darunter. Vorher lagen beide als Plaketten über
 * derselben Säule und überdeckten sich, sobald die Werte nah beieinanderlagen.
 */
import { fmtNum } from '../util/format.js'

export default {
	name: 'StageHistogram',
	props: {
		// [{ value, count }] — vollständige Skala, auch Werte ohne Stimmen.
		rows: { type: Array, default: () => [] },
		average: { type: Number, default: null },
		median: { type: Number, default: null },
		minLabel: { type: String, default: '' },
		maxLabel: { type: String, default: '' },
	},
	computed: {
		gridStyle() {
			return { gridTemplateColumns: 'repeat(' + Math.max(1, this.rows.length) + ', 1fr)' }
		},
		maxCount() {
			return this.rows.reduce((m, r) => Math.max(m, r.count || 0), 0)
		},
		avgPct() {
			return this.pos(this.average)
		},
		medPct() {
			return this.pos(this.median)
		},
	},
	methods: {
		num(v) {
			return fmtNum(v)
		},
		// Wert -> Mitte seiner Säule in Prozent der Bühnenbreite.
		pos(v) {
			if (v === null || v === undefined || !this.rows.length) return null
			const min = Number(this.rows[0].value)
			const p = ((Number(v) - min) + 0.5) / this.rows.length * 100
			return Math.round(Math.max(0, Math.min(100, p)) * 10) / 10
		},
		barPct(count) {
			return this.maxCount ? Math.round((count / this.maxCount) * 100) : 0
		},
	},
}
</script>

<style scoped>
/* Alles in em der Bühne — die Schrumpf-Schleife nimmt das Diagramm mit (§2.3). */
.shist { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; gap: 0.3em; }

/* Band des Medians: eigene Zeile, damit die Beschriftung nie in die Säulen ragt. */
.shist-med { position: relative; height: 1.3em; flex: 0 0 auto; }
.shist-med-lbl {
	position: absolute; top: 0; transform: translateX(-50%);
	font-weight: 700; white-space: nowrap;
	background: var(--pulse-screen-bg); padding-inline: 0.25em;
	font-variant-numeric: tabular-nums;
}
/* Am rechten Rand kippt die Beschriftung auf die andere Seite der Linie —
   der einzige Sonderfall (§7.2). */
.shist-med-lbl[data-flip] { transform: translateX(-100%); }

.shist-cols {
	flex: 1 1 auto; min-height: 0; position: relative;
	display: grid; grid-template-rows: auto minmax(0, 1fr); column-gap: 0.5em; row-gap: 0.18em;
}
.shist-med-line {
	position: absolute; top: -1.3em; bottom: 0; width: 0.08em;
	background: var(--pulse-text); opacity: 0.55; transform: translateX(-50%);
}
.shist-cnt { text-align: center; font-weight: 700; font-variant-numeric: tabular-nums; padding-block: 0.14em; }
.shist-cnt[data-zero] { color: var(--pulse-meta); }
/* Eigener Auftragsbereich: die Prozenthöhe bezieht sich auf ihn, nicht auf die
   Spalte samt Zahl — sonst schöbe ein 100-%-Balken die Zahl aus dem Bild. */
.shist-plot { min-height: 0; display: flex; align-items: flex-end; }
.shist-bar { width: 100%; border-radius: 0.12em 0.12em 0 0; background: var(--pulse-primary); transition: height 0.7s cubic-bezier(.22, 1, .36, 1); }
/* Nullwerte als Stummel: ohne ihn liest sich der leere Wert als Achsenstück. */
.shist-plot[data-zero] .shist-bar { height: 0.12em !important; background: var(--pulse-bar-track); }

.shist-axis { flex: 0 0 auto; display: grid; gap: 0.5em; border-top: 0.06em solid var(--pulse-border); padding-top: 0.2em; }
.shist-axis span { text-align: center; font-weight: 700; font-variant-numeric: tabular-nums; padding-block: 0.14em; }

.shist-avg { position: relative; height: 1.5em; flex: 0 0 auto; }
.shist-avg-mark { position: absolute; top: 0; transform: translateX(-50%); display: flex; flex-direction: column; align-items: center; }
.shist-tri { width: 0; height: 0; border-inline: 0.35em solid transparent; border-bottom: 0.45em solid var(--pulse-text); }
.shist-avg-lbl { font-weight: 700; white-space: nowrap; padding-block: 0.14em; font-variant-numeric: tabular-nums; }

.shist-poles { flex: 0 0 auto; display: flex; justify-content: space-between; gap: 1em; font-weight: 600; color: var(--pulse-meta); }
.shist-poles span { max-width: 45%; }
.shist-poles span:last-child { text-align: right; }

@media (prefers-reduced-motion: reduce) {
	.shist-bar { transition: none; }
}
</style>
