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
 * VizEmpty — ONE empty state for all result charts (histogram, radar,
 * compass). Before, the chart collapsed into an empty box at 0 votes and
 * looked broken; the calm dashed frame with a single line holds the stage
 * until the first answer arrives (design notes §1, not in the public repository).
 *
 * All sizes in em -> scales with the container font-size from phone to
 * projector, just like the rest of ResultsView.
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
