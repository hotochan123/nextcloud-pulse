<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="intake" :class="{ 'is-frozen': frozen, 'is-compact': compact }">
		<p class="intake-num">{{ shownAnswered }}</p>
		<p v-if="slots" class="intake-ref">{{ t('pulse', 'of {total} here', { total: slots }) }}</p>
		<p v-else class="intake-ref">{{ n('pulse', 'answer', 'answers', shownAnswered) }}</p>

		<!-- Above 200 connected people the grid turns into a texture that says
		     nothing; then a row in the stage grammar carries the same message. -->
		<div v-if="asBar" class="srows intake-bar">
			<StageRow :label="t('pulse', 'Answers')" :value="shownAnswered + ' / ' + slots" :pct="barPct" />
		</div>
		<div v-else class="intake-grid" :class="{ 'is-dense': dense }" role="img" :aria-label="ariaLabel">
			<span v-for="i in gridSlots" :key="i" class="intake-slot" :data-on="i <= shownAnswered ? '' : null" />
		</div>
	</div>
</template>

<script>
/*
 * Intake display (redesign §6.2, design notes not in the public
 * repository) — one component for all question types.
 *
 * Shows how many have answered and relates it to the number of connected
 * people. The number alone would be a claim; only the reference makes it a
 * basis for decisions — the moderator sees how many are still missing.
 *
 * Anonymity is a design principle, not an add-on: the slots fill in a fixed
 * order from the top left, cannot be told apart, carry no identifier and are
 * never re-sorted. The grid does not reveal who answered what and when.
 */
import StageRow from './StageRow.vue'

// From here on the grid gets denser, or is replaced by a row altogether.
const DENSE_FROM = 61
const BAR_FROM = 201

export default {
	name: 'IntakeBoard',
	components: { StageRow },
	props: {
		answered: { type: Number, default: 0 },
		// Connected people; 0 = unknown (then the reference is dropped).
		present: { type: Number, default: 0 },
		// Time is up: the intake freezes (§6.5).
		frozen: { type: Boolean, default: false },
		// Narrow form next to an image: number and reference on one line, grid
		// in a single row (§7.1). The intake stays visible, just flatter.
		compact: { type: Boolean, default: false },
	},
	data() {
		return {
			// The grid never shrinks while a question is running — otherwise
			// the area jumps as soon as someone closes the page (§6.6).
			maxSlots: 0,
			frozenAt: null,
		}
	},
	computed: {
		slots() {
			return Math.max(this.maxSlots, this.present, this.shownAnswered)
		},
		shownAnswered() {
			return this.frozenAt === null ? this.answered : this.frozenAt
		},
		dense() {
			return this.slots >= DENSE_FROM
		},
		asBar() {
			// Narrow next to an image there is no room for several rows once the grid
			// gets dense — the row takes over earlier there (§7.1).
			return this.slots >= (this.compact ? DENSE_FROM : BAR_FROM)
		},
		// Without a known presence (embedded view) the grid grows along without
		// empty slots.
		gridSlots() {
			return Math.max(this.slots, this.shownAnswered)
		},
		barPct() {
			return this.slots ? Math.round((this.shownAnswered / this.slots) * 100) : 0
		},
		ariaLabel() {
			return this.slots
				? this.t('pulse', '{count} of {total} have answered', { count: this.shownAnswered, total: this.slots })
				: this.shownAnswered + ' ' + this.n('pulse', 'answer', 'answers', this.shownAnswered)
		},
	},
	watch: {
		present: {
			immediate: true,
			handler(value) {
				if (value > this.maxSlots) this.maxSlots = value
			},
		},
		answered: {
			immediate: true,
			handler(value) {
				if (value > this.maxSlots) this.maxSlots = value
			},
		},
		frozen: {
			immediate: true,
			handler(value) {
				this.frozenAt = value ? this.answered : null
			},
		},
	},
}
</script>

<style scoped>
/* Everything in em of the stage (§2.3): the shrink loop takes the intake along. */
.intake { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.1em; min-height: 0; text-align: center; }
.intake-num { margin: 0; font-size: 1.6em; font-weight: 900; line-height: 1.05; font-variant-numeric: tabular-nums; }
.intake-ref { margin: 0 0 0.6em; color: var(--pulse-meta); font-weight: 600; }

.intake-grid {
	display: flex; flex-wrap: wrap; justify-content: center; align-content: center;
	gap: 0.35em; min-height: 0;
	/* Cap the width so that the slots form a grid instead of a single long
	   row — only in a grid can they be counted at a glance. */
	max-width: min(100%, 14em);
}
.intake-grid.is-dense { gap: 0.22em; }
.intake-slot {
	width: 0.9em; height: 0.9em; border-radius: 50%;
	box-shadow: inset 0 0 0 0.07em var(--pulse-border);
	transition: background-color 0.3s ease, box-shadow 0.3s ease;
}
.is-dense .intake-slot { width: 0.5em; height: 0.5em; box-shadow: inset 0 0 0 0.05em var(--pulse-border); }
.intake-slot[data-on] { background: var(--pulse-primary); box-shadow: none; }
.intake-bar { width: 100%; }
.is-frozen .intake-num { color: var(--pulse-meta); }

/* Narrow form (§7.1): one row of number, reference and grid. */
.intake.is-compact { flex-direction: row; align-items: center; justify-content: flex-start; gap: 0.5em; text-align: left; }
/* The line height must fit the font box of the digits (≈1.29 em) — 1.05
   cut it to 0.86 em, and the overhang counted as a 2 px overflow of the
   stage, although nothing was missing in the picture. */
.intake.is-compact .intake-num { flex: 0 0 auto; font-size: 1.15em; line-height: 1.35; }
.intake.is-compact .intake-ref { flex: 0 0 auto; margin: 0; white-space: nowrap; }
.intake.is-compact .intake-grid { flex: 1 1 auto; max-width: none; justify-content: flex-start; gap: 0.25em; }
.intake.is-compact .intake-slot { width: 0.55em; height: 0.55em; }
.intake.is-compact .intake-bar { flex: 1 1 auto; }

@media (prefers-reduced-motion: reduce) {
	.intake-slot { transition: none; }
}
</style>
