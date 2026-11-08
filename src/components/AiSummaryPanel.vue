<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="ais" role="region" aria-labelledby="ai-summary-title" @keydown.esc.stop.prevent="cancel">
		<h3 id="ai-summary-title" class="ais-title">
			<PulseIcon name="sparkle" size="1em" class="ais-spark" /> {{ t('pulse', 'Summary with AI') }}
		</h3>

		<p v-if="step === 'running'" class="ais-status" aria-live="polite">{{ t('pulse', 'The AI is writing the summary …') }}</p>
		<p v-if="error" class="ais-error" role="alert">{{ error }}</p>

		<template v-if="step === 'edit'">
			<p class="ais-help">{{ t('pulse', 'The numbers come from Pulse, the key points from the AI. Check and edit the text before you save it.') }}</p>
			<label class="ais-label" for="ai-summary-text">{{ t('pulse', 'Summary (Markdown)') }}</label>
			<textarea id="ai-summary-text"
				ref="text"
				v-model="text"
				class="ais-area"
				rows="14"
				:maxlength="textMax"
				:disabled="saving" />
		</template>

		<p v-if="step === 'saved'" class="ais-saved">
			{{ t('pulse', 'Saved in Files: {name}', { name: saved.path }) }}
		</p>

		<div class="ais-actions">
			<template v-if="step === 'edit'">
				<button type="button" class="pulse-btn is-primary is-sm ais-save" :disabled="saving || !text.trim()" @click="save">{{ t('pulse', 'Save') }}</button>
				<button type="button" class="pulse-btn is-secondary is-sm ais-retry" :disabled="saving" @click="start">{{ t('pulse', 'Try again') }}</button>
			</template>
			<button v-else-if="step === 'failed'" type="button" class="pulse-btn is-primary is-sm ais-retry" @click="start">{{ t('pulse', 'Try again') }}</button>
			<template v-if="step === 'saved'">
				<a class="pulse-btn is-primary is-sm ais-open" :href="saved.url" target="_blank" rel="noopener" :title="saved.name">{{ t('pulse', 'Open in Files') }}</a>
				<button type="button" class="pulse-btn is-secondary is-sm ais-close" @click="$emit('close')">{{ t('pulse', 'Close') }}</button>
			</template>
			<button v-else type="button" class="pulse-btn is-secondary is-sm ais-cancel" :disabled="saving" @click="cancel">{{ t('pulse', 'Cancel') }}</button>
		</div>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { MOD_TIMEOUT, roomApi, filesLink } from '../util/routes.js'
import { t } from '../util/l10n.js'
import { serverMessage, showSuccess } from '../toast.js'
import PulseIcon from './ui/PulseIcon.vue'

const POLL_EVERY = 2000
const POLL_LIMIT = 3 * 60 * 1000
/** Characters of the text the server takes (AiSummaryController::TEXT_MAX). */
const TEXT_MAX = 100000

/*
 * The AI summary on the results overview: starts at once, polls the task,
 * then shows the Markdown for editing. "Save" puts it into the moderator's
 * Files (folder "Pulse"). Leaving (Cancel, or the panel goes away) cancels
 * a task that is still running.
 */
export default {
	name: 'AiSummaryPanel',
	components: { PulseIcon },
	props: {
		code: { type: String, required: true },
	},
	data() {
		return {
			step: 'running', // 'running' | 'edit' | 'failed' | 'saved'
			task: 0,
			text: '',
			error: '',
			saving: false,
			saved: null,
		}
	},
	computed: {
		textMax() {
			return TEXT_MAX
		},
	},
	mounted() {
		this.start()
	},
	beforeDestroy() {
		this.dropTask()
	},
	methods: {
		t,
		async start() {
			this.dropTask()
			this.error = ''
			this.text = ''
			this.step = 'running'
			const run = (this.run || 0) + 1
			this.run = run
			try {
				const { data } = await axios.post(roomApi(this.code, '/ai/summary'), null, { timeout: MOD_TIMEOUT })
				if (run !== this.run || this.step !== 'running') {
					if (data && data.task) this.cancelTask(data.task)
					return
				}
				if (data && typeof data.text === 'string') {
					this.done(data.text)
					return
				}
				this.task = data.task
				this.startedAt = Date.now()
				this.schedulePoll()
			} catch (e) {
				if (run === this.run && e?.response?.data?.notice === true) {
					this.$emit('notice')
					return
				}
				if (run === this.run) this.fail(serverMessage(e, t('pulse', 'The AI could not answer. Try again.')))
			}
		},
		schedulePoll() {
			this.stopPolling()
			this.pollTimer = setTimeout(this.poll, POLL_EVERY)
		},
		stopPolling() {
			if (this.pollTimer) clearTimeout(this.pollTimer)
			this.pollTimer = null
		},
		async poll() {
			const task = this.task
			if (!task || this.step !== 'running') return
			if (Date.now() - this.startedAt > POLL_LIMIT) {
				this.dropTask()
				this.fail(t('pulse', 'The AI took too long. Try again.'))
				return
			}
			try {
				const { data } = await axios.get(roomApi(this.code, '/ai/summary/' + task), { timeout: MOD_TIMEOUT })
				if (task !== this.task || this.step !== 'running') return
				if (data.status === 'done' && typeof data.text === 'string') {
					this.task = 0
					this.done(data.text)
					return
				}
				if (data.status === 'failed') {
					this.task = 0
					this.fail(data.message || t('pulse', 'The AI could not answer. Try again.'))
					return
				}
			} catch (e) {
				if (task !== this.task) return
				const status = e?.response?.status
				// a 404 (room or task gone) ends it; a hiccup of the network is asked again
				if (status && status < 500) {
					this.task = 0
					this.fail(serverMessage(e, t('pulse', 'The AI could not answer. Try again.')))
					return
				}
			}
			this.schedulePoll()
		},
		done(text) {
			this.stopPolling()
			this.text = text
			this.step = 'edit'
			this.$nextTick(() => this.$refs.text && this.$refs.text.focus())
		},
		fail(message) {
			this.stopPolling()
			this.step = 'failed'
			this.error = message
		},
		cancelTask(task) {
			// fire and forget: a finished task has nothing to cancel
			axios.delete(roomApi(this.code, '/ai/summary/' + task), { timeout: MOD_TIMEOUT }).catch(() => {})
		},
		dropTask() {
			this.stopPolling()
			if (this.task) this.cancelTask(this.task)
			this.task = 0
		},
		async save() {
			if (this.saving || !this.text.trim()) return
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.post(roomApi(this.code, '/summary/files'), { text: this.text }, { timeout: MOD_TIMEOUT })
				this.saved = { name: data.name, path: data.path, url: filesLink(data.fileId) }
				this.step = 'saved'
				showSuccess(t('pulse', 'Saved in Files: {name}', { name: data.path }))
			} catch (e) {
				this.error = serverMessage(e, t('pulse', 'Could not save the file in your Files.'))
			} finally {
				this.saving = false
			}
		},
		cancel() {
			if (this.saving) return
			this.run = (this.run || 0) + 1
			this.dropTask()
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.ais {
	margin: 0 0 18px;
	padding: 14px 16px;
	border: 1px solid var(--pulse-border);
	border-inline-start: 4px solid var(--pulse-ai);
	border-radius: var(--pulse-r-card);
	background: var(--pulse-bg);
	color: var(--pulse-text);
	display: flex;
	flex-direction: column;
	gap: 10px;
	min-width: 0;
}
.ais-title { margin: 0; font-size: var(--t-lead); font-weight: 800; display: flex; align-items: center; gap: 8px; }
.ais-spark { color: var(--pulse-ai); }
.ais-status { margin: 0; font-weight: 700; color: var(--pulse-ai); }
.ais-error { margin: 0; color: var(--pulse-error); font-weight: 600; }
.ais-help { margin: 0; font-size: var(--t-sm); color: var(--pulse-text-2); line-height: 1.45; }
.ais-label { font-size: var(--t-sm); font-weight: 700; color: var(--pulse-text-2); }
.ais-saved { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
/* The same rules as .pinput in the moderator; 16 px so iOS does not zoom. */
.ais-area {
	width: 100%;
	min-height: 260px;
	height: auto !important;
	resize: vertical;
	background: var(--pulse-bg) !important;
	color: var(--pulse-text) !important;
	border: 2px solid var(--pulse-border-strong) !important;
	border-radius: var(--pulse-r-el) !important;
	padding: 9px 12px !important;
	font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
	font-size: 16px !important;
	line-height: 1.45;
	margin: 0 !important;
	box-sizing: border-box;
}
.ais-area:focus { border-color: var(--pulse-primary) !important; }
.ais-actions { display: flex; flex-wrap: wrap; gap: 10px; }
.ais-actions .pulse-btn { min-height: 44px; }
</style>
