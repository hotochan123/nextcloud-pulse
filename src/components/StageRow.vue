<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="srow" :class="[{ 'srow--outline': outline, 'srow--tall': tall }, stateCls]" :style="style">
		<template v-if="!outline">
			<span class="srow-track" aria-hidden="true" />
			<span class="srow-fill" aria-hidden="true" />
			<!-- Layer above the fill: identical content, clipped complementarily. -->
			<span class="srow-text srow-text--fill" aria-hidden="true">
				<span v-if="badge !== ''" class="srow-badge">{{ badge }}</span>
				<PulseIcon v-if="state === 'correct'" name="check" size="1.2em" class="srow-mark" />
				<span class="srow-label">{{ label }}</span>
				<span v-if="flag" class="srow-flag">{{ flag }}</span>
				<span v-if="value !== ''" class="srow-val">{{ value }}</span>
			</span>
		</template>
		<span class="srow-text srow-text--track">
			<span v-if="badge !== ''" class="srow-badge">{{ badge }}</span>
			<PulseIcon v-if="state === 'correct'" name="check" size="1.2em" class="srow-mark" />
			<span class="srow-label">{{ label }}</span>
			<span v-if="flag" class="srow-flag">{{ flag }}</span>
			<span v-if="value !== ''" class="srow-val">{{ value }}</span>
		</span>
	</div>
</template>

<script>
/*
 * Stage row (design notes §2.4, not in the public repository) — the bar IS
 * the row.
 *
 * The palette colour comes from outside: the class `pulse-opt-a` … `-h` on the
 * element sets --opt-fill/--opt-ink (util/palette.js supplies them). Vue passes
 * classes on the component tag through to the root element, so
 * `<StageRow :class="row.pal.cls" …>` is enough.
 *
 * Styling lives in pulse-ds.css (global) on purpose, not scoped: the stage,
 * final standings and the open option list share the same row model, and the
 * respective parents decide the row height (.srows--stretch/--fixed).
 */
import PulseIcon from './ui/PulseIcon.vue'

export default {
	name: 'StageRow',
	components: { PulseIcon },
	props: {
		// Letter (A–H) or rank number. Empty = no badge.
		badge: { type: [String, Number], default: '' },
		label: { type: String, default: '' },
		// Pre-formatted value ("9 · 38 %"), on the right inside the bar.
		value: { type: [String, Number], default: '' },
		// Fill share in percent.
		pct: { type: Number, default: 0 },
		// Open stage: outline instead of a filled area, no value (§6.3).
		outline: { type: Boolean, default: false },
		// '' | 'correct' | 'wrong' — solution encoding (§4.2).
		state: { type: String, default: '' },
		// Word after the label ("correct", "leading") — colour is never the
		// only carrier.
		flag: { type: String, default: '' },
		// Two-line label: all rows of the stage grow together (§2.6).
		tall: { type: Boolean, default: false },
	},
	computed: {
		style() {
			return this.outline ? {} : { '--p': Math.max(0, Math.min(100, this.pct)) + '%' }
		},
		stateCls() {
			return this.state ? 'is-' + this.state : ''
		},
	},
}
</script>
