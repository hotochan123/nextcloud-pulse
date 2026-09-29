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
 * Gestapelte Bühnen-Zeile (Redesign §7.5/§7.6).
 *
 * Die Zeile ist immer voll und zeigt, WIE sich die Stimmen verteilen — nicht
 * ihren Durchschnitt. Beim Ranking trugen die Balkenlängen zuletzt einen
 * Unterschied von 0,3 Plätzen über den stärksten visuellen Kanal; die
 * Verteilung dahinter (polarisiert oder einig?) war unsichtbar, obwohl die
 * Daten sie hergaben.
 *
 * Segmentfarben kommen fertig von außen (`fill`/`ink`): geordnete Kategorien
 * bekommen die Helligkeitsstaffel, ungeordnete die Palette A–H (§7.0).
 */

// Zeilenbreite in em bei Bühnengröße, abzüglich Kopf, Rang und Wert. Grober
// Richtwert — er entscheidet nur, ob ein Segment beschriftet wird.
const STACK_EM = 22
// Platzbedarf einer Beschriftung in em: mittlere Zeichenbreite der fetten
// Schrift plus etwas Luft. Ein Platz („3") braucht anders viel Raum als ein
// Zielname („Postgres") — eine feste Mindestbreite würde den einen unnötig
// unterdrücken und den anderen abschneiden.
const labelEm = (text) => 0.55 * String(text).length * 0.82 + 0.6

export default {
	name: 'StageStack',
	props: {
		// Platzzahl links (Ranking). Leer = keine Plakette (Zuordnung).
		rank: { type: [String, Number], default: '' },
		label: { type: String, default: '' },
		// [{ key, text, pct, fill, ink, state }] — pct ist der Anteil in Prozent.
		segments: { type: Array, default: () => [] },
		// Fertig formatierter Wert rechts („Ø 2,3").
		value: { type: [String, Number], default: '' },
		// Vorlesbare Beschreibung der ganzen Zeile.
		ariaLabel: { type: String, default: '' },
	},
	computed: {
		shown() {
			return this.segments.filter((s) => s.pct > 0).map((s) => ({
				key: s.key,
				// Beschriftung nur, wenn das Segment sie trägt; sonst bleibt es leer
				// und die Legende erklärt es (§7.5).
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
