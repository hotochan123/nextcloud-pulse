<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="section pulse-admin">
		<h2>{{ t('pulse', 'Pulse') }}</h2>
		<p class="pulse-admin-help">{{ t('pulse', 'AI in Pulse uses the AI provider set up in this Nextcloud (Artificial intelligence settings).') }}</p>

		<p class="pulse-admin-row">
			<input id="pulse-admin-ai"
				v-model="enabled"
				type="checkbox"
				class="checkbox"
				:disabled="busy"
				@change="saveEnabled">
			<label for="pulse-admin-ai">{{ t('pulse', 'Allow AI in Pulse') }}</label>
		</p>
		<p class="pulse-admin-help">{{ t('pulse', 'Off: no AI button and no switch “AI help” anywhere in Pulse.') }}</p>
		<p class="pulse-admin-help">{{ t('pulse', 'AI results depend on your model. Pulse only sends prompts to the AI provider set up in your Nextcloud; how good drafts, filters, themes and summaries are depends strongly on that model. Pulse is tested with Mistral Small 4 (via Infomaniak); smaller or local models may give weaker results. The prompts keep being improved.') }}</p>

		<p class="pulse-admin-row pulse-admin-limit">
			<label for="pulse-admin-limit">{{ t('pulse', 'AI requests per account and day') }}</label>
			<input id="pulse-admin-limit"
				v-model="limit"
				type="number"
				inputmode="numeric"
				min="0"
				:max="initial.dailyMax"
				step="1"
				aria-describedby="pulse-admin-limit-help"
				:disabled="busy"
				@change="saveLimit">
		</p>
		<p id="pulse-admin-limit-help" class="pulse-admin-help">{{ t('pulse', 'Counts quiz drafts, groupings, grading suggestions and summaries a moderator starts. The automatic check of answers for the projector is not counted.') }}</p>

		<p class="pulse-admin-status" :class="{ 'is-error': isError }" role="status" aria-live="polite">{{ status }}</p>
	</div>
</template>

<script>
/**
 * Admin section "Pulse": the switch "Allow AI in Pulse" and the daily limit
 * of AI requests per account. Each field saves on its change (POST /api/1.0/admin/ai);
 * a value the server refuses shows its message and the field keeps what
 * the admin typed, so it can be corrected.
 */
import axios from '@nextcloud/axios'
import { adminAiApi } from '../util/routes.js'
import { serverMessage } from '../toast.js'
import { t } from '../util/l10n.js'

export default {
	name: 'AdminSettings',
	props: {
		initial: { type: Object, required: true },
	},
	data() {
		return {
			enabled: this.initial.enabled !== false,
			limit: String(this.initial.dailyLimit),
			// what the server holds: each field saves on its own, with the other one as stored
			saved: { enabled: this.initial.enabled !== false, dailyLimit: this.initial.dailyLimit },
			busy: false,
			status: '',
			isError: false,
		}
	},
	methods: {
		saveEnabled() {
			return this.save({ enabled: this.enabled, dailyLimit: this.saved.dailyLimit }, true)
		},
		saveLimit() {
			return this.save({ enabled: this.saved.enabled, dailyLimit: String(this.limit).trim() }, false)
		},
		// The switch goes back when the server refuses; a refused limit stays in the field to be corrected.
		async save(body, isSwitch) {
			this.busy = true
			this.status = ''
			this.isError = false
			try {
				const { data } = await axios.post(adminAiApi(), body)
				this.saved = { enabled: data.enabled, dailyLimit: data.dailyLimit }
				if (isSwitch) this.enabled = data.enabled
				else this.limit = String(data.dailyLimit)
				this.status = t('pulse', 'Saved')
			} catch (e) {
				this.isError = true
				if (isSwitch) this.enabled = this.saved.enabled
				this.status = serverMessage(e, t('pulse', 'Could not save. Please try again.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.pulse-admin-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 8px 0 2px; }
.pulse-admin-limit input { width: 8em; }
.pulse-admin-help { color: var(--color-text-maxcontrast); margin: 0 0 10px; max-width: 60em; }
.pulse-admin-status { min-height: 1.5em; margin: 6px 0 0; }
.pulse-admin-status.is-error { color: var(--color-error-text, var(--color-error)); font-weight: 600; }
</style>
