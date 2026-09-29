<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="shist">
		<!-- Band 1: the median sits ABOVE the columns … -->
		<div class="shist-med">
			<span v-if="medPct !== null" class="shist-med-lbl" :data-flip="medPct > 88 ? '' : null" :style="{ left: medPct + '%' }">
				{{ t('pulse', 'median {value}', { value: num(median) }) }}
			</span>
		</div>

		<!-- Two grid rows instead of one column per value: only then does the
		     plot area have a fixed height that the column's percentage height can
		     resolve against. In a content-sized column it would stay at 0. -->
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

		<!-- … and the mean BELOW them, on the axis. Two bands means:
		     the two cannot overlap, no matter how close the values
		     are to each other (§7.2, resolves N2). -->
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
 * Single scale question on the projector (redesign notes §7.2, not in the public repository).
 *
 * The columns fill the stage instead of ending at 44 % of its height, and the
 * two statistics sit in separate bands — median above the columns, mean on
 * the axis below. Before, both sat as badges above the same column and
 * covered each other as soon as the values were close together.
 */
import { fmtNum } from '../util/format.js'

export default {
	name: 'StageHistogram',
	props: {
		// [{ value, count }] — the complete scale, including values without votes.
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
		// Value -> centre of its column as a percentage of the stage width.
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
/* Everything in em of the stage — the shrink loop takes the chart along (§2.3). */
.shist { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; gap: 0.3em; }

/* Median band: a row of its own, so the label never reaches into the columns. */
.shist-med { position: relative; height: 1.3em; flex: 0 0 auto; }
.shist-med-lbl {
	position: absolute; top: 0; transform: translateX(-50%);
	font-weight: 700; white-space: nowrap;
	background: var(--pulse-screen-bg); padding-inline: 0.25em;
	font-variant-numeric: tabular-nums;
}
/* At the right edge the label flips to the other side of the line —
   the only special case (§7.2). */
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
/* A plot area of its own: the percentage height refers to it, not to the
   column including the number — otherwise a 100 % bar would push the number out of view. */
.shist-plot { min-height: 0; display: flex; align-items: flex-end; }
.shist-bar { width: 100%; border-radius: 0.12em 0.12em 0 0; background: var(--pulse-primary); transition: height 0.7s cubic-bezier(.22, 1, .36, 1); }
/* Zero values as stubs: without one, the empty value reads as a piece of axis. */
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
