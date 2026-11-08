<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="splot-wrap" :class="{ 'is-list': listMode }">
		<div class="splot-main">
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
			<!-- Name AND value at the spoke: the detour via number badges and
			     a list at the other edge of the stage is gone (§7.3). -->
			<template v-if="!listMode">
				<span v-for="sp in labels" :key="sp.id" class="splot-spoke" :style="sp.style">
					{{ sp.label }} <i>{{ sp.value }}</i>
				</span>
			</template>
			<!-- Names too long for the spokes: a number at the spoke, the names in a list beside the chart. -->
			<template v-else>
				<span v-for="sp in nums" :key="sp.id" class="splot-num" :style="sp.style">{{ sp.n }}</span>
			</template>
		</div>
		<ol v-if="listMode" class="splot-list">
			<li v-for="sp in nums" :key="sp.id" class="splot-item">
				<span class="splot-item-num">{{ sp.n }}</span>
				<span class="splot-item-label">{{ sp.label }}</span>
				<i>{{ sp.value }}</i>
			</li>
		</ol>
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
 * Spectrum on the projector (redesign notes §7.3, not in the public repository).
 *
 * The radar gets the whole stage, and the aspect names sit at their
 * spokes. Before, the name stood at x ≈ 660 and its value at
 * x ≈ 1860 — from the audience that meant looking things up across half the screen.
 *
 * The "two aspects" case does not belong here: two spokes do not span
 * an area. The parent view then shows rows (§7.3).
 */
import { fmtNum } from '../util/format.js'
import { labelsCollide } from '../util/stage-labels.js'

// Radius of the data area and of the labels in the 100-unit viewBox. With seven
// or eight aspects the names move further out: the arc between
// two spokes grows with the radius, and that is exactly what decides whether two
// neighbouring names overlap.
const R = 36
const LR = 41
const LR_MANY = 46

export default {
	name: 'StageRadar',
	props: {
		// [{ id, label, average, min, max, n }]
		rows: { type: Array, default: () => [] },
		// Upper end of the scale.
		max: { type: Number, default: 5 },
		ariaLabel: { type: String, default: '' },
	},
	data() {
		return {
			// Names at the spokes cover each other or leave the chart's area (measured, see checkLabels): list instead.
			listMode: false,
		}
	},
	computed: {
		labelKey() {
			return this.rows.map((r) => r.label || '').join('\u0001')
		},
		// List mode: number discs at the outer end of the spokes, centred there.
		nums() {
			return this.rows.map((row, i) => {
				const [x, y] = this.pt(i, LR)
				return {
					id: row.id,
					n: i + 1,
					label: row.label,
					value: fmtNum(row.average),
					style: { left: x + '%', top: y + '%' },
				}
			})
		},
		// From seven aspects on, the names may wrap onto two lines (§7.3), and
		// so may a name too long for one line beside the radar.
		twoLine() {
			return this.rows.length >= 7 || this.rows.some((r) => (r.label || '').length > 12)
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
		// Spread as a ring between min and max (an area with a hole, evenodd).
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
				// Alignment by angle: right half left-aligned, left half
				// right-aligned. Vertically the label is placed INWARDS — outside, the
				// name would hang over the edge of the stage, and the room for it would come off the
				// square (only 528 px instead of 656).
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
	watch: {
		labelKey() {
			this.relayout()
		},
	},
	mounted() {
		// A window of another size starts again with the names at the spokes. Only the window: the list mode itself and
		// the projector's font fit change the chart's size, and reacting to those would flip between the two modes.
		this._onResize = () => this.relayout()
		window.addEventListener('resize', this._onResize)
		this.relayout()
	},
	beforeDestroy() {
		window.removeEventListener('resize', this._onResize)
		this._gen = (this._gen || 0) + 1
	},
	methods: {
		relayout() {
			this.listMode = false
			const gen = this._gen = (this._gen || 0) + 1
			this.$nextTick(() => requestAnimationFrame(() => {
				if (gen === this._gen) this.checkLabels()
			}))
		},
		// Measured at the size the stage starts with, before the projector shrinks its font: the names only get
		// smaller against the chart from there, so names that fit here keep fitting.
		checkLabels() {
			if (this.listMode || !this.$el || !this.$el.querySelectorAll) return
			const spokes = [...this.$el.querySelectorAll('.splot-spoke')].map((e) => e.getBoundingClientRect())
			if (spokes.length && labelsCollide(spokes, this.$el.getBoundingClientRect())) {
				this.listMode = true
				// The stage refits around the new layout (Screen.vue listens).
				this.$nextTick(() => this.$el.dispatchEvent(new CustomEvent('pulse-relayout', { bubbles: true })))
			}
		},
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
	/* Room for the labels outside the square. */
	/* Room at the sides only: the names stand left and right outside the
	   square, vertically however inside (see labels()). That keeps the full
	   height for the radar — §7.10 requires at least 600 px. */
	padding: 0 5em;
}
/* Square, height from the remaining space, width via aspect-ratio. align-self
   prevents stretching in the cross axis — otherwise the width would come from the
   stage and aspect-ratio would blow up the height. */
/* The chart is the largest square the space holds (container units): with a list beside it the width is shared. */
.splot-main { flex: 1 1 auto; min-height: 0; container-type: size; display: flex; align-items: center; justify-content: center; gap: 1.2em; }
.splot { position: relative; flex: 0 0 auto; width: min(100cqh, 100cqw); height: min(100cqh, 100cqw); }
.splot-wrap.is-list { padding: 0; }
.is-list .splot { width: min(100cqh, 50cqw); height: min(100cqh, 50cqw); }
.splot-num {
	position: absolute; transform: translate(-50%, -50%);
	width: 1.5em; height: 1.5em; border-radius: 50%; display: grid; place-items: center;
	background: var(--pulse-primary); color: var(--pulse-on-primary); font-weight: 800; font-size: 0.7em; line-height: 1;
}
.splot-list {
	flex: 1 1 0; min-width: 0; max-height: 100%; list-style: none; margin: 0; padding: 0;
	display: flex; flex-direction: column; justify-content: center; gap: 0.35em; font-size: 0.7em;
}
.splot-item { display: flex; align-items: baseline; gap: 0.5em; min-width: 0; }
.splot-item-num {
	flex: 0 0 auto; align-self: center; width: 1.5em; height: 1.5em; border-radius: 50%; display: grid; place-items: center;
	background: var(--pulse-primary); color: var(--pulse-on-primary); font-weight: 800; font-size: 0.85em; line-height: 1;
}
.splot-item-label { flex: 1 1 auto; min-width: 0; font-weight: 700; line-height: 1.15; padding-block: 0.14em; overflow-wrap: anywhere; color: var(--pulse-text); }
.splot-item i { flex: 0 0 auto; font-style: normal; font-weight: 800; color: var(--pulse-primary); font-variant-numeric: tabular-nums; }
.splot svg { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; }
.splot-spoke {
	position: absolute; font-weight: 700; white-space: nowrap; line-height: 1.15;
	padding-block: 0.14em; color: var(--pulse-text);
}
.splot.is-two-line .splot-spoke { white-space: normal; max-width: 4.6em; overflow-wrap: anywhere; }
.splot-spoke i { font-style: normal; color: var(--pulse-primary); font-variant-numeric: tabular-nums; }
/* Legend directly below the radar, class C — not at the edge of the stage. */
.splot-legend { flex: 0 0 auto; display: flex; flex-wrap: wrap; justify-content: center; gap: 1.2em; padding-top: 0.3em; font-size: 0.55em; color: var(--pulse-meta); }
.splot-legend span { display: inline-flex; align-items: center; gap: 0.4em; }
.splot-sw { width: 1.1em; height: 0.6em; border-radius: 0.12em; }
.splot-sw--neutral { border: 0.12em dashed var(--pulse-meta); height: 0; }
</style>
