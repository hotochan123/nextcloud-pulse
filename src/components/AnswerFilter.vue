<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="answer-filter">
		<h3 class="af-head">{{ t('pulse', 'Words on the projector') }}</h3>
		<p class="af-hint">{{ t('pulse', 'Hide a word and it leaves the projector and the word cloud; it stays in the CSV, marked.') }}</p>
		<ul v-if="items.length" class="af-list">
			<li v-for="it in items" :key="it.key" class="af-row" :class="'is-' + it.state">
				<span class="af-count">{{ it.count }}×</span>
				<span class="af-text">{{ it.label }}</span>
				<ModerationControls :state="it.state" :label="it.label" :busy="busy" @moderate="(a) => $emit('moderate', it.label, a)" />
			</li>
		</ul>
		<p v-else class="af-empty">{{ t('pulse', 'No answers yet.') }}</p>
	</div>
</template>

<script>
// Word cloud on the desk: every word with its filter state (the cloud above
// shows only what the projector shows). Never projected.
import ModerationControls from './ModerationControls.vue'

export default {
	name: 'AnswerFilter',
	components: { ModerationControls },
	props: {
		// results.moderation.items of the moderator results
		items: { type: Array, required: true },
		busy: { type: Boolean, default: false },
	},
	emits: ['moderate'],
}
</script>

<style scoped>
.answer-filter { margin-top: 1em; }
.af-head { font-size: 0.95em; margin: 0 0 0.3em; }
.af-hint { font-size: 0.85em; color: var(--pulse-text-2); margin: 0 0 0.6em; }
.af-list { list-style: none; margin: 0; padding: 0; }
.af-row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5em 0.75em; padding: 0.4em 0.25em; border-bottom: 1px solid var(--pulse-border); }
.af-count { flex: 0 0 auto; min-width: 3ch; text-align: right; color: var(--pulse-text-2); font-variant-numeric: tabular-nums; font-weight: 700; }
/* a word keeps a readable width; the buttons move to the next line in a narrow panel */
.af-text { flex: 1 1 8em; min-width: 6em; font-weight: 600; overflow-wrap: anywhere; }
.af-row.is-hidden .af-text { text-decoration: line-through; opacity: 0.6; }
.af-row.is-held { background: var(--pulse-warning-soft); }
.af-empty { color: var(--pulse-text-2); font-size: 0.85em; }
</style>
