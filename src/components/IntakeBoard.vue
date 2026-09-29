<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="intake" :class="{ 'is-frozen': frozen, 'is-compact': compact }">
		<p class="intake-num">{{ shownAnswered }}</p>
		<p v-if="slots" class="intake-ref">{{ t('pulse', 'of {total} here', { total: slots }) }}</p>
		<p v-else class="intake-ref">{{ n('pulse', 'answer', 'answers', shownAnswered) }}</p>

		<!-- Über 200 Verbundenen wird das Raster zur Textur ohne Aussage; dann
		     trägt eine Zeile in der Bühnen-Grammatik dieselbe Aussage. -->
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
 * Eingangs-Anzeige (Redesign §6.2) — ein Bauteil für alle Fragetypen.
 *
 * Zeigt, wie viele geantwortet haben, und setzt es ins Verhältnis zu den
 * Verbundenen. Die Zahl allein wäre eine Behauptung; erst der Bezug macht sie
 * zur Entscheidungsgrundlage — der Moderator sieht, wie viele noch fehlen.
 *
 * Anonymität ist Bauprinzip, nicht Beiwerk: die Plätze füllen sich in fester
 * Reihenfolge von oben links, sind untereinander nicht unterscheidbar, tragen
 * keine Kennung und werden nie neu sortiert. Aus dem Raster ist nicht
 * ableitbar, wer wann was geantwortet hat.
 */
import StageRow from './StageRow.vue'

// Ab hier wird das Raster verdichtet bzw. ganz durch eine Zeile ersetzt.
const DENSE_FROM = 61
const BAR_FROM = 201

export default {
	name: 'IntakeBoard',
	components: { StageRow },
	props: {
		answered: { type: Number, default: 0 },
		// Verbundene Personen; 0 = unbekannt (dann entfällt der Bezug).
		present: { type: Number, default: 0 },
		// Zeit abgelaufen: der Eingang friert ein (§6.5).
		frozen: { type: Boolean, default: false },
		// Schmale Form neben einem Bild: Zahl und Bezug einzeilig, Raster
		// einreihig (§7.1). Der Eingang bleibt sichtbar, nur flacher.
		compact: { type: Boolean, default: false },
	},
	data() {
		return {
			// Das Raster schrumpft während einer laufenden Frage nie — sonst
			// springt die Fläche, sobald jemand die Seite schließt (§6.6).
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
			// Schmal neben einem Bild ist schon ab dem verdichteten Raster kein
			// Platz mehr für mehrere Reihen — dort trägt die Zeile früher (§7.1).
			return this.slots >= (this.compact ? DENSE_FROM : BAR_FROM)
		},
		// Ohne bekannte Präsenz (eingebettete Ansicht) wächst das Raster ohne
		// leere Plätze mit.
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
/* Alles in em der Bühne (§2.3): die Schrumpf-Schleife nimmt den Eingang mit. */
.intake { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.1em; min-height: 0; text-align: center; }
.intake-num { margin: 0; font-size: 1.6em; font-weight: 900; line-height: 1.05; font-variant-numeric: tabular-nums; }
.intake-ref { margin: 0 0 0.6em; color: var(--pulse-meta); font-weight: 600; }

.intake-grid {
	display: flex; flex-wrap: wrap; justify-content: center; align-content: center;
	gap: 0.35em; min-height: 0;
	/* Deckel auf die Breite, damit die Plätze ein Raster bilden statt einer
	   einzelnen langen Reihe — nur im Raster sind sie auf einen Blick zählbar. */
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

/* Schmale Form (§7.1): eine Zeile aus Zahl, Bezug und Raster. */
.intake.is-compact { flex-direction: row; align-items: center; justify-content: flex-start; gap: 0.5em; text-align: left; }
/* Die Zeilenhöhe muss die Schriftbox der Ziffern (≈1,29 em) fassen — 1,05
   schnitt sie auf 0,86 em zu, und die Überlänge zählte als 2 px Überlauf der
   Bühne, obwohl auf dem Bild nichts fehlte. */
.intake.is-compact .intake-num { flex: 0 0 auto; font-size: 1.15em; line-height: 1.35; }
.intake.is-compact .intake-ref { flex: 0 0 auto; margin: 0; white-space: nowrap; }
.intake.is-compact .intake-grid { flex: 1 1 auto; max-width: none; justify-content: flex-start; gap: 0.25em; }
.intake.is-compact .intake-slot { width: 0.55em; height: 0.55em; }
.intake.is-compact .intake-bar { flex: 1 1 auto; }

@media (prefers-reduced-motion: reduce) {
	.intake-slot { transition: none; }
}
</style>
