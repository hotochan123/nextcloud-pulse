<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="theme-groups" :aria-label="t('pulse', 'Group with AI')">
		<div class="thg-bar">
			<h3 class="thg-head">{{ t('pulse', 'Themes') }}</h3>
			<button type="button"
				class="pulse-btn is-sm is-secondary thg-btn thg-start"
				:disabled="busy || state === 'running'"
				@click="$emit('group', 'start')">
				<PulseIcon name="sparkle" size="1em" />
				{{ state === 'ready' || state === 'failed' ? t('pulse', 'Group again') : t('pulse', 'Group with AI') }}
			</button>
		</div>
		<p v-if="state === 'running'" class="thg-status" role="status">{{ t('pulse', 'Grouping answers …') }}</p>
		<p v-else-if="state === 'failed'" class="pulse-notice is-warn thg-status" role="status">
			<PulseIcon name="warning" size="1.1em" class="pulse-notice-ico" /><span>{{ t('pulse', 'AI grouping not available right now') }}</span>
		</p>
		<template v-else-if="state === 'ready'">
			<ul v-if="themes.length" class="thg-list">
				<li v-for="(th, i) in themes" :key="i" class="thg-row">
					<span class="thg-count">{{ th.count }}×</span>
					<span class="thg-body">
						<span class="thg-title">{{ th.title }}</span>
						<span class="thg-example">“{{ th.example }}”</span>
					</span>
				</li>
			</ul>
			<p v-else class="thg-empty">{{ t('pulse', 'No answers yet.') }}</p>
			<p v-if="groups.ungrouped > 0" class="thg-ungrouped">
				{{ n('pulse', '%n answer is not in a theme', '%n answers are not in a theme', groups.ungrouped) }}
			</p>
			<button v-if="themes.length || groups.shown"
				type="button"
				class="pulse-btn is-sm thg-btn thg-show"
				:class="groups.shown ? 'is-secondary' : 'is-primary'"
				:disabled="busy"
				@click="$emit('group', groups.shown ? 'hide' : 'show')">
				{{ groups.shown ? t('pulse', 'Back to answers') : t('pulse', 'Show themes on projector') }}
			</button>
		</template>
	</section>
</template>

<script>
// "Group with AI" on the desk (never projected): start the grouping, look at
// the themes, release them to the projector or take them back.
import PulseIcon from './ui/PulseIcon.vue'

export default {
	name: 'ThemeGroups',
	components: { PulseIcon },
	props: {
		// results.moderation.groups of the moderator results
		groups: { type: Object, required: true },
		busy: { type: Boolean, default: false },
	},
	emits: ['group'],
	computed: {
		state() {
			return this.groups.state || 'idle'
		},
		themes() {
			return Array.isArray(this.groups.themes) ? this.groups.themes : []
		},
	},
}
</script>

<style scoped>
.theme-groups { margin: 0 0 1em; padding-bottom: 0.8em; border-bottom: 1px solid var(--pulse-border); }
.thg-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5em; justify-content: space-between; }
.thg-head { font-size: 0.95em; margin: 0; }
/* 44 px targets for a finger or the S Pen on the tablet desk */
.thg-btn { min-height: 44px; }
.thg-status { margin: 0.5em 0 0; font-size: 0.9em; color: var(--pulse-text-2); }
.thg-list { list-style: none; margin: 0.5em 0 0; padding: 0; }
.thg-row { display: flex; align-items: baseline; gap: 0.75em; padding: 0.4em 0.25em; border-bottom: 1px solid var(--pulse-border); }
.thg-count { flex: 0 0 auto; min-width: 3ch; text-align: right; color: var(--pulse-text-2); font-variant-numeric: tabular-nums; font-weight: 700; }
.thg-body { display: flex; flex-direction: column; min-width: 0; }
.thg-title { font-weight: 700; overflow-wrap: anywhere; }
.thg-example { font-size: 0.85em; color: var(--pulse-text-2); overflow-wrap: anywhere; }
.thg-ungrouped, .thg-empty { font-size: 0.85em; color: var(--pulse-text-2); margin: 0.5em 0 0; }
.thg-show { margin-top: 0.6em; }
</style>
