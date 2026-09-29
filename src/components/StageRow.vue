<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="srow" :class="[{ 'srow--outline': outline, 'srow--tall': tall }, stateCls]" :style="style">
		<template v-if="!outline">
			<span class="srow-track" aria-hidden="true" />
			<span class="srow-fill" aria-hidden="true" />
			<!-- Ebene über der Füllung: identischer Inhalt, komplementär beschnitten. -->
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
 * Bühnen-Zeile (Redesign §2.4) — der Balken IST die Zeile.
 *
 * Die Palettenfarbe kommt von außen: die Klasse `pulse-opt-a` … `-h` auf dem
 * Element setzt --opt-fill/--opt-ink (util/palette.js liefert sie). Vue reicht
 * Klassen am Komponenten-Tag an das Wurzelelement durch, deshalb genügt
 * `<StageRow :class="row.pal.cls" …>`.
 *
 * Styling liegt bewusst in pulse-ds.css (global), nicht scoped: Bühne,
 * Endstand und offene Optionsliste teilen sich dasselbe Zeilenmodell, und die
 * jeweiligen Eltern bestimmen die Zeilenhöhe (.srows--stretch/--fixed).
 */
import PulseIcon from './ui/PulseIcon.vue'

export default {
	name: 'StageRow',
	components: { PulseIcon },
	props: {
		// Buchstabe (A–H) oder Rangzahl. Leer = keine Plakette.
		badge: { type: [String, Number], default: '' },
		label: { type: String, default: '' },
		// Fertig formatierter Wert („9 · 38 %"), rechts im Balken.
		value: { type: [String, Number], default: '' },
		// Füllanteil in Prozent.
		pct: { type: Number, default: 0 },
		// Offene Bühne: Umriss statt Fläche, kein Wert (§6.3).
		outline: { type: Boolean, default: false },
		// '' | 'correct' | 'wrong' — Lösungs-Kodierung (§4.2).
		state: { type: String, default: '' },
		// Wort hinter dem Label („correct", „leading") — Farbe ist nie der
		// einzige Träger.
		flag: { type: String, default: '' },
		// Zweizeiliges Label: alle Zeilen der Bühne wachsen gemeinsam (§2.6).
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
