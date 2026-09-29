<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="splot-wrap">
		<div class="splot">
			<svg viewBox="0 0 100 100" role="img" :aria-label="ariaLabel" preserveAspectRatio="xMidYMid meet">
				<rect width="100" height="100" fill="var(--pulse-bar-track)" opacity=".45" />
				<template v-if="heatmap">
					<rect v-for="(c, i) in cells" :key="'c' + i" :x="c.x" :y="c.y" :width="c.w" :height="c.w"
						fill="var(--pulse-primary)" :opacity="c.op" />
				</template>
				<template v-else>
					<circle v-for="(d, i) in dots" :key="'d' + i" :cx="d.x" :cy="d.y" r="1.5"
						fill="var(--pulse-primary)" opacity=".55" />
				</template>
				<line x1="50" y1="0" x2="50" y2="100" stroke="var(--pulse-text)" stroke-width=".3" opacity=".45" />
				<line x1="0" y1="50" x2="100" y2="50" stroke="var(--pulse-text)" stroke-width=".3" opacity=".45" />
				<template v-if="centre">
					<line :x1="centre.x" :y1="centre.y - 5" :x2="centre.x" :y2="centre.y + 5" stroke="var(--pulse-text)" stroke-width=".4" />
					<line :x1="centre.x - 5" :y1="centre.y" :x2="centre.x + 5" :y2="centre.y" stroke="var(--pulse-text)" stroke-width=".4" />
					<circle :cx="centre.x" :cy="centre.y" r="2.6" fill="var(--pulse-text)" />
				</template>
			</svg>
			<span v-if="axisY.poleHigh" class="splot-spoke splot-pole splot-pole--top">{{ axisY.poleHigh }}</span>
			<span v-if="axisY.poleLow" class="splot-spoke splot-pole splot-pole--bottom">{{ axisY.poleLow }}</span>
			<span v-if="axisX.poleLow" class="splot-spoke splot-pole splot-pole--left">{{ axisX.poleLow }}</span>
			<span v-if="axisX.poleHigh" class="splot-spoke splot-pole splot-pole--right">{{ axisX.poleHigh }}</span>
			<span v-if="corners[0]" class="splot-corner splot-corner--tl">{{ corners[0] }}</span>
			<span v-if="corners[1]" class="splot-corner splot-corner--tr">{{ corners[1] }}</span>
			<span v-if="corners[2]" class="splot-corner splot-corner--bl">{{ corners[2] }}</span>
			<span v-if="corners[3]" class="splot-corner splot-corner--br">{{ corners[3] }}</span>
			<span v-if="centre" class="splot-centre" :style="centre.style">{{ t('pulse', 'Centre of gravity') }}</span>
		</div>
		<div class="splot-legend">
			<span><i class="splot-sw splot-sw--dot" />{{ t('pulse', 'Centre of gravity') }}</span>
			<span>{{ heatmap
				? t('pulse', 'Heat map from {count} answers', { count: threshold })
				: t('pulse', 'Single dots below {count} answers', { count: threshold }) }}</span>
		</div>
	</div>
</template>

<script>
/*
 * Compass on the projector (redesign notes §7.4, not in the public repository).
 *
 * A square field across the full stage height. Up to the threshold every
 * answer stays a dot of its own, above it the area becomes a texture and the
 * density is the message — the threshold is a value, not a feeling.
 *
 * There is deliberately no "You" dot here: on the big screen there is no me.
 * Highlighting one's own answer is the phone's job.
 */

// Heat map grid (cells per edge).
const GRID = 12

export default {
	name: 'StageCompass',
	props: {
		// [{ x, y }] in data coordinates (-range … +range)
		points: { type: Array, default: () => [] },
		centroid: { type: Object, default: null },
		range: { type: Number, default: 5 },
		threshold: { type: Number, default: 45 },
		axisX: { type: Object, default: () => ({}) },
		axisY: { type: Object, default: () => ({}) },
		cornerLabels: { type: Array, default: () => [] },
		ariaLabel: { type: String, default: '' },
	},
	computed: {
		heatmap() {
			return this.points.length >= this.threshold
		},
		corners() {
			const c = this.cornerLabels || []
			return [c[0] || '', c[1] || '', c[2] || '', c[3] || '']
		},
		dots() {
			return this.points.map((p) => ({ x: this.sx(p.x), y: this.sy(p.y) }))
		},
		cells() {
			const map = {}
			let peak = 0
			for (const p of this.points) {
				const i = Math.min(GRID - 1, Math.max(0, Math.floor(this.sx(p.x) / 100 * GRID)))
				const j = Math.min(GRID - 1, Math.max(0, Math.floor(this.sy(p.y) / 100 * GRID)))
				const k = i + ':' + j
				map[k] = (map[k] || 0) + 1
				if (map[k] > peak) peak = map[k]
			}
			const w = 100 / GRID
			return Object.keys(map).map((k) => {
				const parts = k.split(':')
				return {
					x: Number(parts[0]) * w,
					y: Number(parts[1]) * w,
					w,
					op: Math.round((0.12 + (map[k] / peak) * 0.68) * 100) / 100,
				}
			})
		},
		centre() {
			const c = this.centroid
			if (!c || typeof c.x !== 'number' || typeof c.y !== 'number') return null
			const x = this.sx(c.x), y = this.sy(c.y)
			// Below the dot; above it when close to the bottom edge.
			const flip = y > 80
			return {
				x,
				y,
				style: {
					left: x + '%',
					top: y + '%',
					transform: flip ? 'translate(-50%, calc(-100% - 0.6em))' : 'translate(-50%, 0.6em)',
				},
			}
		},
	},
	methods: {
		sx(x) {
			return Math.round((50 + (Number(x) / this.range) * 50) * 100) / 100
		},
		sy(y) {
			return Math.round((50 - (Number(y) / this.range) * 50) * 100) / 100
		},
	},
}
</script>

<style scoped>
.splot-wrap {
	display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0;
	box-sizing: border-box;
	/* Reserve for the labels outside the square. */
	padding: 1.4em 5em 0;
}
.splot { position: relative; flex: 1 1 auto; min-height: 0; align-self: center; aspect-ratio: 1; margin-bottom: 1.4em; }
.splot svg { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; }
.splot-spoke { position: absolute; font-weight: 700; white-space: nowrap; line-height: 1.15; padding-block: 0.14em; color: var(--pulse-text); }
.splot-pole--top { left: 50%; top: -0.2em; transform: translate(-50%, -100%); }
.splot-pole--bottom { left: 50%; bottom: -0.2em; transform: translate(-50%, 100%); }
.splot-pole--left { left: -0.4em; top: 50%; transform: translate(-100%, -50%); }
.splot-pole--right { right: -0.4em; top: 50%; transform: translate(100%, -50%); }
.splot-corner { position: absolute; font-size: 0.55em; font-weight: 700; color: var(--pulse-meta); }
.splot-corner--tl { left: 0.4em; top: 0.3em; }
.splot-corner--tr { right: 0.4em; top: 0.3em; text-align: right; }
.splot-corner--bl { left: 0.4em; bottom: 0.3em; }
.splot-corner--br { right: 0.4em; bottom: 0.3em; text-align: right; }
/* The centre of gravity carries its name — a shape alone would be a sign without
   a legend, and the legend sits at the bottom edge of the stage. */
.splot-centre { position: absolute; font-weight: 800; white-space: nowrap; color: var(--pulse-text); font-size: 0.7em; }
.splot-legend { flex: 0 0 auto; display: flex; flex-wrap: wrap; justify-content: center; gap: 1.2em; padding-top: 0.3em; font-size: 0.55em; color: var(--pulse-meta); }
.splot-legend span { display: inline-flex; align-items: center; gap: 0.4em; }
.splot-sw { width: 1.1em; height: 0.6em; border-radius: 0.12em; }
.splot-sw--dot { width: 0.8em; height: 0.8em; border-radius: 50%; background: var(--pulse-text); }
</style>
