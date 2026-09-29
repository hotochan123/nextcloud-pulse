<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="sstack-row">
		<span v-if="rank !== ''" class="sstack-rank">{{ rank }}</span>
		<span v-if="label !== ''" class="sstack-head" :title="label">{{ label }}</span>
		<span class="sstack" role="img" :aria-label="ariaLabel">
			<span v-for="seg in shown" :key="seg.key" class="sseg" :class="seg.stateCls"
				:style="seg.style">{{ seg.text }}</span>
		</span>
		<span v-if="value !== ''" class="sstack-val">{{ value }}</span>
	</div>
</template>

<script>
/*
 * Stacked stage row (design notes §7.5/§7.6, not in the public repository).
 *
 * The row is always full and shows HOW the votes are distributed — not
 * their average. For ranking, the bar lengths last carried a difference
 * of 0.3 places through the strongest visual channel; the distribution
 * behind it (polarised or in agreement?) was invisible, even though the
 * data had it.
 *
 * Segment colours arrive ready-made from outside (`fill`/`ink`): ordered categories
 * get the lightness ramp, unordered ones the palette A–H (§7.0).
 */

// Row width in em at stage size, minus head, rank and value. A rough
// guide — it only decides whether a segment gets a label.
const STACK_EM = 22
// Space a label needs in em: average character width of the bold
// font plus a little air. A place ("3") needs a different amount of room than a
// target name ("Postgres") — a fixed minimum width would needlessly
// suppress the one and cut off the other.
const labelEm = (text) => 0.55 * String(text).length * 0.82 + 0.6

export default {
	name: 'StageStack',
	props: {
		// Place number on the left (ranking). Empty = no badge (matching).
		rank: { type: [String, Number], default: '' },
		label: { type: String, default: '' },
		// [{ key, text, pct, fill, ink, state }] — pct is the share in percent.
		segments: { type: Array, default: () => [] },
		// Pre-formatted value on the right ("Ø 2.3").
		value: { type: [String, Number], default: '' },
		// Screen-reader description of the whole row.
		ariaLabel: { type: String, default: '' },
	},
	computed: {
		shown() {
			return this.segments.filter((s) => s.pct > 0).map((s) => ({
				key: s.key,
				// Label only if the segment can carry it; otherwise it stays empty
				// and the legend explains it (§7.5).
				text: (s.pct / 100) * STACK_EM >= labelEm(s.text) ? s.text : '',
				stateCls: s.state ? 'is-' + s.state : '',
				style: {
					flex: s.pct + ' 0 0',
					'--seg-fill': s.fill,
					'--seg-ink': s.ink,
				},
			}))
		},
	},
}
</script>
