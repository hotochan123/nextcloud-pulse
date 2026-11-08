<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="grading-suggestion" :aria-label="t('pulse', 'AI grading suggestion')">
		<div class="gs-bar">
			<h3 class="gs-head">{{ t('pulse', 'AI grading suggestion') }}</h3>
			<button type="button"
				class="pulse-btn is-sm is-secondary gs-btn gs-start"
				:disabled="busy || state === 'running'"
				@click="$emit('suggest', 'start')">
				<PulseIcon name="sparkle" size="1em" />
				{{ state === 'ready' || state === 'failed' ? t('pulse', 'Suggest again') : t('pulse', 'Suggest grading with AI') }}
			</button>
		</div>
		<p v-if="state === 'running'" class="gs-status" role="status">{{ t('pulse', 'Checking answers …') }}</p>
		<p v-else-if="state === 'failed'" class="pulse-notice is-warn gs-status" role="status">
			<PulseIcon name="warning" size="1.1em" class="pulse-notice-ico" /><span>{{ t('pulse', 'AI grading suggestion not available right now') }}</span>
		</p>
		<template v-else-if="state === 'ready'">
			<p class="gs-status">{{ t('pulse', 'Nothing counts until you accept. Tap a suggestion to flip it.') }}</p>
			<button v-if="open > 0"
				type="button"
				class="pulse-btn is-sm is-primary gs-btn gs-accept"
				:disabled="busy"
				@click="$emit('suggest', 'accept')">
				{{ t('pulse', 'Accept all ({count})', { count: open }) }}
			</button>
			<p v-else class="gs-status">{{ t('pulse', 'No open answer has a suggestion.') }}</p>
		</template>
	</section>
</template>

<script>
// AI grading suggestion on the desk (never projected): ask for a proposal per
// open free-text answer and accept them all; single proposals are flipped in
// the answer list (TextGrading).
import PulseIcon from './ui/PulseIcon.vue'

export default {
	name: 'GradingSuggestion',
	components: { PulseIcon },
	props: {
		// results.moderation.grading of the moderator results
		grading: { type: Object, required: true },
		busy: { type: Boolean, default: false },
	},
	emits: ['suggest'],
	computed: {
		state() {
			return this.grading.state || 'idle'
		},
		open() {
			return this.grading.open || 0
		},
	},
}
</script>

<style scoped>
.grading-suggestion { margin: 0 0 1em; padding-bottom: 0.8em; border-bottom: 1px solid var(--pulse-border); }
.gs-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5em; justify-content: space-between; }
.gs-head { font-size: 0.95em; margin: 0; }
/* 44 px targets for a finger or the S Pen on the tablet desk */
.gs-btn { min-height: 44px; }
.gs-status { margin: 0.5em 0 0; font-size: 0.9em; color: var(--pulse-text-2); }
.gs-accept { margin-top: 0.6em; }
</style>
