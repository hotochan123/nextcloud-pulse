<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="viz-empty" role="status">
		<b class="viz-empty-line">{{ title }}</b>
		<span v-if="hint" class="viz-empty-hint">{{ hint }}</span>
	</div>
</template>

<script>
/*
 * VizEmpty — EIN Leerzustand für alle Ergebnis-Grafiken (Histogramm, Radar,
 * Kompass). Vorher kollabierte das Diagramm bei 0 Stimmen zu einem leeren
 * Kasten und sah kaputt aus; der ruhige gestrichelte Rahmen mit einer Zeile
 * hält die Bühne, bis die erste Antwort kommt (Verfeinerung §1).
 *
 * Alle Maße in em -> skaliert mit der Container-font-size vom Handy bis zum
 * Beamer, genau wie der Rest von ResultsView.
 */
import { t } from '../../util/l10n.js'

export default {
	name: 'VizEmpty',
	props: {
		title: { type: String, default: t('pulse', 'No answers yet') },
		hint: { type: String, default: t('pulse', 'As soon as people vote, the stage fills up.') },
	},
}
</script>

<style scoped>
.viz-empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 0.35em;
	min-height: 7em;
	padding: 1.5em 1em;
	border: 1.5px dashed var(--pulse-border-strong);
	border-radius: var(--pulse-r-card);
	text-align: center;
}
.viz-empty-line { font-size: 1em; font-weight: 700; color: var(--pulse-text-2); }
.viz-empty-hint { font-size: 0.8em; color: var(--pulse-text-2); }
</style>
