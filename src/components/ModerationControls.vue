<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span class="mod-ctl">
		<span v-if="state === 'pending'" class="pulse-chip mod-ctl-chip">{{ t('pulse', 'Checking …') }}</span>
		<span v-else-if="state === 'held'" class="pulse-chip is-warning mod-ctl-chip"><PulseIcon name="flag" size="1em" /> {{ t('pulse', 'AI flagged') }}</span>
		<span v-else-if="state === 'hidden'" class="pulse-chip mod-ctl-chip is-off"><PulseIcon name="eye" size="1em" /> {{ t('pulse', 'Hidden') }}</span>
		<button v-for="a in actions"
			:key="a"
			class="pulse-btn is-sm mod-ctl-btn"
			:class="a === 'hide' ? 'is-secondary' : 'is-primary'"
			:disabled="busy"
			:aria-label="a === 'hide' ? t('pulse', 'Hide “{answer}” from the projector', { answer: label }) : t('pulse', 'Show “{answer}” on the projector', { answer: label })"
			@click="$emit('moderate', a)">
			{{ a === 'hide' ? t('pulse', 'Hide') : t('pulse', 'Show') }}
		</button>
	</span>
</template>

<script>
// One answer's filter state and its buttons (desk only, never projected):
// free text in TextGrading, word cloud in AnswerFilter.
import PulseIcon from './ui/PulseIcon.vue'
import { moderationActions } from '../util/moderation.js'

export default {
	name: 'ModerationControls',
	components: { PulseIcon },
	props: {
		state: { type: String, default: 'visible' },
		label: { type: String, default: '' },
		busy: { type: Boolean, default: false },
	},
	emits: ['moderate'],
	computed: {
		actions() {
			const { state } = this
			return moderationActions(state)
		},
	},
}
</script>

<style scoped>
.mod-ctl { flex: 0 0 auto; margin-inline-start: auto; display: inline-flex; align-items: center; gap: 0.4em; flex-wrap: wrap; justify-content: flex-end; }
.mod-ctl-chip { font-size: 0.8em; }
.mod-ctl-chip.is-off { opacity: 0.8; }
/* 44 px targets for a finger or the S Pen on the tablet desk */
.mod-ctl-btn { min-height: 44px; min-width: 64px; }
</style>
