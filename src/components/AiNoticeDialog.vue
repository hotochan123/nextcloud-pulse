<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ain-overlay" @click.self="$emit('cancel')">
		<div ref="dialog"
			class="ain"
			role="dialog"
			aria-modal="true"
			aria-labelledby="ai-notice-title"
			aria-describedby="ai-notice-body"
			tabindex="-1"
			@keydown="onKey">
			<h2 id="ai-notice-title" class="ain-title">
				<PulseIcon name="sparkle" size="1em" /> {{ t('pulse', 'Before you use AI') }}
			</h2>
			<ul id="ai-notice-body" class="ain-body">
				<li>{{ t('pulse', 'Pulse sends the text the AI needs to the AI provider set up by your administrator: your topic or file text for a quiz draft; the answer texts of your participants for the filter; the question text and the answer texts for the summary, the grouping and the grading suggestion, for the grading suggestion also your expected answers.') }}</li>
				<li>{{ t('pulse', 'Never sent: names, nicknames, ids or other data of your participants, nor your account.') }}</li>
				<li>{{ t('pulse', 'AI can be wrong. Check every result before you use it.') }}</li>
			</ul>
			<p v-if="error" class="pulse-notice is-warn ain-error" role="alert">{{ error }}</p>
			<div class="ain-actions">
				<button type="button" class="pulse-btn is-secondary" :disabled="busy" @click="$emit('cancel')">{{ t('pulse', 'Cancel') }}</button>
				<button ref="ok"
					type="button"
					class="pulse-btn is-primary"
					:disabled="busy"
					@click="confirm">
					{{ t('pulse', 'Understood') }}
				</button>
			</div>
		</div>
	</div>
</template>

<script>
/**
 * The AI notice: a fixed text (what goes to the provider, what does not)
 * that an account confirms once before its first AI use. "Understood"
 * stores that on the server (POST /api/1.0/ai/notice), then emits
 * `confirmed`; Cancel, Esc or a click beside it emits `cancel`, and the AI
 * action that asked for it does not run.
 */
import axios from '@nextcloud/axios'
import PulseIcon from './ui/PulseIcon.vue'
import { aiNoticeApi } from '../util/routes.js'
import { serverMessage } from '../toast.js'
import { t } from '../util/l10n.js'

export default {
	name: 'AiNoticeDialog',
	components: { PulseIcon },
	data() {
		return { busy: false, error: '' }
	},
	mounted() {
		this.opener = document.activeElement
		this.$nextTick(() => (this.$refs.ok || this.$refs.dialog).focus())
	},
	beforeDestroy() {
		const el = this.opener
		if (el && el.isConnected && typeof el.focus === 'function') el.focus()
	},
	methods: {
		async confirm() {
			this.busy = true
			this.error = ''
			try {
				await axios.post(aiNoticeApi())
				this.$emit('confirmed')
			} catch (e) {
				this.error = serverMessage(e, t('pulse', 'Could not save. Please try again.'))
			} finally {
				this.busy = false
			}
		},
		onKey(e) {
			if (e.key === 'Escape') {
				e.preventDefault()
				this.$emit('cancel')
				return
			}
			if (e.key !== 'Tab') return
			const els = Array.from(this.$refs.dialog.querySelectorAll('button')).filter((el) => !el.disabled)
			if (!els.length) return
			const first = els[0]
			const last = els[els.length - 1]
			if (e.shiftKey && (document.activeElement === first || document.activeElement === this.$refs.dialog)) {
				e.preventDefault()
				last.focus()
			} else if (!e.shiftKey && document.activeElement === last) {
				e.preventDefault()
				first.focus()
			}
		},
	},
}
</script>

<style scoped>
.ain-overlay {
	position: fixed;
	inset: 0;
	z-index: 10001;
	display: grid;
	place-items: center;
	padding: calc(var(--header-height, 50px) + 12px) 16px 12px;
	background: rgba(0, 0, 0, .5);
}
.ain {
	width: 100%;
	max-width: 520px;
	max-height: calc(100vh - var(--header-height, 50px) - 24px);
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 18px 22px 16px;
	background: var(--pulse-bg);
	color: var(--pulse-text);
	border: 1px solid var(--pulse-border);
	border-radius: var(--pulse-r-card);
	box-shadow: var(--pulse-shadow-pop);
	box-sizing: border-box;
}
.ain:focus { outline: none; }
.ain-title { margin: 0; font-size: var(--t-lead); font-weight: 800; display: flex; align-items: center; gap: 8px; }
.ain-body { margin: 0; padding-inline-start: 1.2em; display: flex; flex-direction: column; gap: 8px; line-height: 1.45; overflow-wrap: anywhere; }
.ain-error { margin: 0; }
.ain-actions { display: flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
.ain-actions .pulse-btn { min-height: 44px; }
</style>
