<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="text-grading">
		<div v-if="!keyless && acceptedList.length" class="tg-accepted">
			<span class="tg-accepted-label">{{ t('pulse', 'Accepted:') }}</span>
			<span v-for="a in acceptedList" :key="a" class="pulse-chip is-success tg-chip">{{ a }}</span>
		</div>
		<p v-if="!readonly" class="tg-hint">{{ t('pulse', 'Approve (tick) or reject (cross) answers that are not obvious — identical spellings count along automatically.') }}</p>
		<ul v-if="results.answers.length" class="tg-list">
			<li v-for="a in results.answers" :key="a.norm" class="tg-row" :class="'is-' + a.status">
				<span class="tg-count">{{ a.count }}×</span>
				<span class="tg-text">{{ a.sample }}</span>

				<!-- interactive: grade (correct/wrong), 44 px, shape+colour+text -->
				<span v-if="!readonly" class="tg-actions">
					<button class="tg-btn tg-ok" :class="{ 'is-on': a.status === 'accepted' }" :disabled="busy" :title="t('pulse', 'Mark as correct')" :aria-label="t('pulse', 'Mark as correct')" @click="$emit('grade', a.sample, true)"><PulseIcon name="check" size="1.15em" /></button>
					<button class="tg-btn tg-no" :class="{ 'is-on': a.status === 'rejected' }" :disabled="busy" :title="t('pulse', 'Mark as wrong')" :aria-label="t('pulse', 'Mark as wrong')" @click="$emit('grade', a.sample, false)"><PulseIcon name="close" size="1.15em" /></button>
				</span>
				<!-- read-only: status as a badge (shape+colour+text), openly visible -->
				<span v-else class="tg-status" :class="'is-' + a.status">
					<template v-if="a.status === 'accepted'"><PulseIcon name="check" size="1em" /> {{ t('pulse', 'correct') }}</template>
					<template v-else-if="a.status === 'rejected'"><PulseIcon name="close" size="1em" /> {{ t('pulse', 'wrong') }}</template>
					<template v-else>{{ t('pulse', 'open') }}</template>
				</span>
			</li>
		</ul>
		<p v-else class="tg-empty">{{ t('pulse', 'No answers yet.') }}</p>
		<p class="tg-total">{{ n('pulse', '%n mention', '%n mentions', results.total) }}<span v-if="results.answers.length"> · {{ t('pulse', '{count} different', { count: results.answers.length }) }}</span></p>
	</div>
</template>

<script>
/*
 * Section references (§…) point to the design notes of the redesign and of the
 * self-paced quiz, which are not in the public repository (see "References in
 * code comments" in the README).
 */
import PulseIcon from './ui/PulseIcon.vue'

export default {
	name: 'TextGrading',
	components: { PulseIcon },
	props: {
		results: { type: Object, required: true },
		// read-only: same look, but without the grading buttons — this way
		// ResultsView (display) and the presentation share ONE free-text building block (§5).
		readonly: { type: Boolean, default: false },
		// Without the answer key: only hides "Accepted:". For grading
		// the self-paced quiz while it is open — the laptop is often connected to the
		// projector, and the list there would give away the solution.
		keyless: { type: Boolean, default: false },
		// A grading request is running: both buttons off, no double tap.
		busy: { type: Boolean, default: false },
	},
	computed: {
		acceptedList() {
			return (this.results && this.results.accepted) || []
		},
	},
}
</script>

<style scoped>
/* em -> scales with the container font-size (phone/moderator small, projector large). */
.text-grading { width: 100%; font-size: inherit; }
.tg-accepted { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5em; margin-bottom: 0.7em; }
.tg-accepted-label { font-size: 0.85em; color: var(--pulse-text-2); }
.tg-chip { font-size: 0.85em; }
.tg-hint { font-size: 0.85em; color: var(--pulse-text-2); margin: 0 0 0.8em; }
.tg-list { list-style: none; margin: 0; padding: 0; }
.tg-row { display: flex; align-items: center; gap: 0.75em; padding: 0.5em 0.25em; border-bottom: 1px solid var(--pulse-border); }
.tg-count { flex: 0 0 auto; min-width: 3ch; text-align: right; color: var(--pulse-text-2); font-variant-numeric: tabular-nums; font-weight: 700; }
.tg-text { flex: 1; font-weight: 600; word-break: break-word; }
.tg-row.is-accepted .tg-text { color: var(--pulse-success); }
.tg-row.is-rejected .tg-text { text-decoration: line-through; opacity: 0.6; }
.tg-actions { flex: 0 0 auto; display: flex; gap: 0.4em; }
.tg-btn {
	appearance: none; width: 2.75em; min-width: 44px; height: 2.75em; min-height: 44px; border-radius: var(--pulse-r-el);
	border: 2px solid var(--pulse-border-strong); background: var(--pulse-bg);
	color: var(--pulse-text-2); cursor: pointer;
	display: inline-flex; align-items: center; justify-content: center; padding: 0;
}
.tg-btn:focus-visible { outline: 2px solid var(--pulse-primary); outline-offset: 2px; }
/* busy: Nextcloud greys out disabled <button>s — the look stays, just dimmed. */
.tg-btn:disabled { opacity: 0.55; cursor: default; background: var(--pulse-bg); color: var(--pulse-text-2); border-color: var(--pulse-border-strong); }
.tg-ok.is-on { background: var(--pulse-success); border-color: var(--pulse-success); color: #fff; }
.tg-no.is-on { background: var(--pulse-error); border-color: var(--pulse-error); color: #fff; }
/* read-only status badge */
.tg-status { flex: 0 0 auto; display: inline-flex; align-items: center; gap: 0.25em; font-weight: 800; font-size: 0.85em; }
.tg-status.is-accepted { color: var(--pulse-success); }
.tg-status.is-rejected { color: var(--pulse-error); }
.tg-status.is-open { color: var(--pulse-text-2); }
.tg-empty, .tg-total { color: var(--pulse-text-2); font-size: 0.85em; }
.tg-total { margin-top: 0.8em; text-align: right; }
</style>
