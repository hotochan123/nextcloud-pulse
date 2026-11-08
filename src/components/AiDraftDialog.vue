<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="aid-overlay" @click.self="cancel">
		<div ref="dialog"
			class="aid"
			role="dialog"
			aria-modal="true"
			aria-labelledby="ai-draft-title"
			tabindex="-1"
			@keydown="onKey">
			<h2 id="ai-draft-title" class="aid-title">
				<PulseIcon name="sparkle" size="1em" class="aid-spark" /> {{ t('pulse', 'Draft questions with AI') }}
			</h2>

			<div class="aid-body">
				<!-- Form: topic or file, how many -->
				<template v-if="step !== 'preview'">
					<PulseSegmented v-model="source" :options="sourceOptions" :disabled="running" :label="t('pulse', 'Source')" />
					<template v-if="source === 'topic'">
						<label class="aid-label" for="ai-draft-topic">{{ t('pulse', 'Topic') }}</label>
						<textarea id="ai-draft-topic"
							ref="topic"
							v-model="topic"
							class="aid-input aid-area"
							rows="3"
							:maxlength="topicMax"
							:disabled="running"
							:placeholder="t('pulse', 'e.g. The water cycle, for class 7')" />
					</template>
					<div v-else class="aid-file">
						<button type="button" class="pulse-btn is-secondary" :disabled="running" @click="pickFile">{{ path ? t('pulse', 'Pick another file') : t('pulse', 'Pick a file') }}</button>
						<span class="aid-file-name" :class="{ 'is-empty': !path }">{{ path ? fileName : t('pulse', 'Text, Markdown, PDF, Word, PowerPoint or OpenDocument') }}</span>
					</div>
					<label class="aid-label" for="ai-draft-count">{{ t('pulse', 'Questions') }}</label>
					<select id="ai-draft-count" v-model.number="count" class="aid-input aid-select" :disabled="running">
						<option v-for="c in counts" :key="c" :value="c">{{ c }}</option>
					</select>
					<p class="aid-help">{{ t('pulse', 'Only this topic or the file’s text is sent to the AI service set up by your admin.') }}</p>
					<p v-if="running" class="aid-status" aria-live="polite">{{ t('pulse', 'The AI is writing …') }}</p>
				</template>

				<!-- Preview: tick what goes into the deck -->
				<template v-else>
					<p class="aid-help">{{ t('pulse', 'Untick what you do not want. You can edit every question afterwards.') }}</p>
					<ol class="aid-list">
						<li v-for="(q, i) in questions" :key="i" class="aid-q" :class="{ 'is-off': !picked[i] }">
							<label class="aid-q-head">
								<input v-model="picked[i]" type="checkbox" class="aid-check">
								<span class="aid-q-text">{{ q.question }}</span>
							</label>
							<span class="aid-q-type">{{ q.type === 'multi' ? t('pulse', 'Multiple answers') : t('pulse', 'Multiple choice') }}</span>
							<ul class="aid-opts">
								<li v-for="(o, k) in q.options" :key="k" class="aid-opt" :class="{ 'is-correct': q.correct.includes(k) }">
									<PulseIcon v-if="q.correct.includes(k)" name="check" size="1em" :label="t('pulse', 'Correct')" />
									<span v-else class="aid-opt-dot" aria-hidden="true" />
									<span>{{ o }}</span>
								</li>
							</ul>
						</li>
					</ol>
				</template>
			</div>

			<div class="aid-foot">
				<p v-if="error" class="pulse-notice is-warn aid-error" role="alert">
					<PulseIcon name="warning" size="1.1em" class="pulse-notice-ico" />
					<span>{{ error }}</span>
				</p>
				<div class="aid-actions">
					<button ref="cancel" type="button" class="pulse-btn is-secondary" :disabled="adding" @click="cancel">{{ t('pulse', 'Cancel') }}</button>
					<template v-if="step === 'preview'">
						<button type="button" class="pulse-btn is-secondary aid-again" :disabled="adding" @click="draft">{{ t('pulse', 'Try again') }}</button>
						<button type="button" class="pulse-btn is-primary aid-add" :disabled="adding || !pickedCount" @click="add">{{ adding ? '…' : n('pulse', 'Add %n question', 'Add %n questions', pickedCount) }}</button>
					</template>
					<button v-else type="button" class="pulse-btn aid-go" :disabled="running" @click="draft">
						<PulseIcon name="sparkle" size="1em" /> {{ running ? '…' : t('pulse', 'Draft questions') }}
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
/*
 * AI quiz draft of the composer (server: AiDraftService). The moderator gives
 * a topic or picks a file in their Files (Nextcloud's file picker), the server
 * schedules a text-generation task, the dialog asks for its state every two
 * seconds (at most POLL_LIMIT) and shows the drafted questions. The ticked ones
 * go into the deck one by one through the normal "add question" route, so
 * every check of a hand-made question applies.
 *
 * Emits: added(poll) per question that got in · done(count) · conflict(message) · cancel
 */
import axios from '@nextcloud/axios'
import { MOD_TIMEOUT, roomApi } from '../util/routes.js'
import { t, n } from '../util/l10n.js'
import { serverMessage } from '../toast.js'
import { COUNT_DEFAULT, COUNT_MAX, COUNT_MIN, PICK_MIMES, TOPIC_MAX, baseName, pickedQuestions, toPollBody } from '../util/ai-draft.js'
import PulseIcon from './ui/PulseIcon.vue'
import PulseSegmented from './ui/PulseSegmented.vue'

const POLL_EVERY = 2000
const POLL_LIMIT = 5 * 60 * 1000

export default {
	name: 'AiDraftDialog',
	components: { PulseIcon, PulseSegmented },
	props: {
		code: { type: String, required: true },
	},
	data() {
		return {
			source: 'topic',
			topic: '',
			path: '',
			count: COUNT_DEFAULT,
			step: 'form', // 'form' | 'running' | 'preview'
			task: 0,
			questions: [],
			picked: [],
			error: '',
			adding: false,
		}
	},
	computed: {
		running() {
			return this.step === 'running'
		},
		topicMax() {
			return TOPIC_MAX
		},
		counts() {
			const out = []
			for (let c = COUNT_MIN; c <= COUNT_MAX; c++) out.push(c)
			return out
		},
		sourceOptions() {
			return [
				{ value: 'topic', label: t('pulse', 'Topic') },
				{ value: 'file', label: t('pulse', 'File') },
			]
		},
		fileName() {
			return baseName(this.path)
		},
		pickedCount() {
			return this.picked.filter(Boolean).length
		},
	},
	mounted() {
		this.returnFocus = document.activeElement
		document.addEventListener('keydown', this.onDocKey)
		this.$nextTick(() => (this.$refs.topic || this.$refs.dialog).focus())
	},
	beforeDestroy() {
		this.stopPolling()
		document.removeEventListener('keydown', this.onDocKey)
		const el = this.returnFocus
		if (el && el.isConnected && typeof el.focus === 'function') el.focus()
	},
	methods: {
		t,
		n,
		pickFile() {
			const dialogs = window.OC && window.OC.dialogs
			if (!dialogs || typeof dialogs.filepicker !== 'function') {
				this.error = t('pulse', 'The file picker is not available here.')
				return
			}
			dialogs.filepicker(t('pulse', 'Pick a file for the quiz'), (picked) => {
				const p = Array.isArray(picked) ? picked[0] : picked
				if (typeof p === 'string' && p) {
					this.path = p
					this.error = ''
				}
			}, false, PICK_MIMES, true, dialogs.FILEPICKER_TYPE_CHOOSE)
		},
		async draft() {
			this.error = ''
			const body = { count: this.count }
			if (this.source === 'topic') {
				const topic = this.topic.trim()
				if (!topic) {
					this.error = t('pulse', 'Enter a topic or pick a file.')
					return
				}
				body.topic = topic
			} else {
				if (!this.path) {
					this.error = t('pulse', 'Enter a topic or pick a file.')
					return
				}
				body.path = this.path
			}
			this.step = 'running'
			try {
				const { data } = await axios.post(roomApi(this.code, '/ai/draft'), body, { timeout: MOD_TIMEOUT })
				this.task = data.task
				this.startedAt = Date.now()
				this.schedulePoll()
			} catch (e) {
				// the AI notice is not confirmed (e.g. reset in another tab): the desk asks for it
				if (e?.response?.data?.notice === true) {
					this.$emit('notice')
					return
				}
				this.fail(e, t('pulse', 'The AI could not answer. Try again.'))
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
				this.cancelTask(task)
				this.step = 'form'
				this.error = t('pulse', 'The AI took too long. Try again.')
				return
			}
			try {
				const { data } = await axios.get(roomApi(this.code, '/ai/draft/' + task), { timeout: MOD_TIMEOUT })
				if (task !== this.task || this.step !== 'running') return
				if (data.status === 'done' && Array.isArray(data.questions) && data.questions.length) {
					this.questions = data.questions
					this.picked = data.questions.map(() => true)
					this.step = 'preview'
					this.task = 0
					return
				}
				if (data.status === 'failed') {
					this.step = 'form'
					this.task = 0
					this.error = data.message || t('pulse', 'The AI could not answer. Try again.')
					return
				}
			} catch (e) {
				const status = e?.response?.status
				// a 404 (room or draft gone) ends it; a hiccup of the network is asked again
				if (status && status < 500) {
					this.fail(e, t('pulse', 'The AI could not answer. Try again.'))
					return
				}
			}
			this.schedulePoll()
		},
		fail(e, fallback) {
			this.stopPolling()
			this.task = 0
			if (this.step !== 'preview') this.step = 'form'
			const msg = serverMessage(e, fallback)
			if (e?.response?.status === 409) {
				this.$emit('conflict', msg)
				return
			}
			this.error = msg
		},
		cancelTask(task) {
			// fire and forget: a finished task has nothing to cancel
			axios.delete(roomApi(this.code, '/ai/draft/' + task), { timeout: MOD_TIMEOUT }).catch(() => {})
		},
		async add() {
			if (this.adding) return
			this.error = ''
			this.adding = true
			const todo = pickedQuestions(this.questions, this.picked)
			let done = 0
			try {
				for (const q of todo) {
					const { data } = await axios.post(roomApi(this.code, '/polls'), toPollBody(q), { timeout: MOD_TIMEOUT })
					this.$emit('added', data)
					done++
					// what got in leaves the preview, so a second click does not add it twice
					const i = this.questions.indexOf(q)
					this.questions.splice(i, 1)
					this.picked.splice(i, 1)
				}
				this.$emit('done', done)
			} catch (e) {
				const msg = serverMessage(e, t('pulse', 'Could not save.'))
				if (e?.response?.status === 409) {
					this.$emit('conflict', msg)
					return
				}
				this.error = t('pulse', 'Added {done} of {total} questions.', { done, total: todo.length }) + ' ' + msg
			} finally {
				this.adding = false
			}
		},
		cancel() {
			if (this.adding) return
			if (this.task) this.cancelTask(this.task)
			this.stopPolling()
			this.$emit('cancel')
		},
		onDocKey(e) {
			if (e.key === 'Escape') {
				e.preventDefault()
				this.cancel()
			}
		},
		// Focus trap: Tab stays inside the dialog.
		onKey(e) {
			if (e.key !== 'Tab') return
			const els = Array.from(this.$refs.dialog.querySelectorAll('button, input, textarea, select, [tabindex="0"]'))
				.filter((el) => !el.disabled && el.getAttribute('tabindex') !== '-1' && el.offsetParent !== null)
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
.aid-overlay {
	position: fixed;
	inset: 0;
	z-index: 10000;
	display: grid;
	place-items: center;
	padding: calc(var(--header-height, 50px) + 12px) 16px 12px;
	background: rgba(0, 0, 0, .5);
}
.aid {
	width: 100%;
	max-width: 620px;
	max-height: calc(100vh - var(--header-height, 50px) - 24px);
	display: flex;
	flex-direction: column;
	background: var(--pulse-bg);
	color: var(--pulse-text);
	border: 1px solid var(--pulse-border);
	border-radius: var(--pulse-r-card);
	box-shadow: var(--pulse-shadow-pop);
	box-sizing: border-box;
}
.aid:focus { outline: none; }
.aid-title { margin: 0; padding: 18px 22px 2px; font-size: var(--t-lead); font-weight: 800; display: flex; align-items: center; gap: 8px; }
.aid-spark { color: var(--pulse-ai); }
.aid-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; padding: 10px 22px 8px; display: flex; flex-direction: column; align-items: flex-start; gap: 10px; }
.aid-label { font-size: var(--t-sm); font-weight: 700; color: var(--pulse-text-2); margin-top: 4px; }
.aid-help { margin: 0; font-size: var(--t-sm); color: var(--pulse-text-2); line-height: 1.45; }
.aid-status { margin: 0; font-weight: 700; color: var(--pulse-ai); }
/* The same rules as .pinput in the moderator; 16 px so iOS does not zoom. */
.aid-input {
	background: var(--pulse-bg) !important;
	color: var(--pulse-text) !important;
	border: 2px solid var(--pulse-border-strong) !important;
	border-radius: var(--pulse-r-el) !important;
	padding: 9px 12px !important;
	font: inherit;
	font-size: 16px !important;
	min-height: 44px;
	margin: 0 !important;
	box-sizing: border-box;
}
.aid-input:focus { border-color: var(--pulse-primary) !important; }
.aid-area { width: 100%; height: auto !important; resize: vertical; }
.aid-select { width: auto; min-width: 90px; }
.aid-file { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; align-self: stretch; }
.aid-file-name { font-weight: 700; overflow-wrap: anywhere; min-width: 0; }
.aid-file-name.is-empty { font-weight: 400; font-size: var(--t-sm); color: var(--pulse-text-2); }

.aid-list { list-style: none; margin: 0; padding: 0; align-self: stretch; display: flex; flex-direction: column; gap: 10px; }
.aid-q { border: 1px solid var(--pulse-border); border-radius: var(--pulse-r-el); padding: 10px 12px; }
.aid-q.is-off { opacity: .55; }
.aid-q-head { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; min-height: 44px; }
.aid-check { flex: 0 0 auto; width: 22px; height: 22px; margin: 2px 0 0 !important; accent-color: var(--pulse-ai); cursor: pointer; }
.aid-q-text { font-weight: 700; overflow-wrap: anywhere; min-width: 0; }
.aid-q-type { display: block; margin: 2px 0 6px 32px; font-size: var(--t-sm); color: var(--pulse-text-2); }
.aid-opts { list-style: none; margin: 0 0 0 32px; padding: 0; display: flex; flex-direction: column; gap: 3px; }
.aid-opt { display: flex; align-items: baseline; gap: 8px; overflow-wrap: anywhere; min-width: 0; }
.aid-opt.is-correct { font-weight: 700; color: var(--pulse-success-text, var(--color-success-text, #005416)); }
.aid-opt-dot { flex: 0 0 auto; width: 1em; text-align: center; }
.aid-opt-dot::before { content: '·'; }

.aid-foot { padding: 12px 22px 16px; border-block-start: 1px solid var(--pulse-border); display: flex; flex-direction: column; gap: 10px; }
.aid-error { margin: 0; }
.aid-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
.aid-go { background: var(--pulse-ai) !important; color: var(--pulse-ai-on) !important; border-color: var(--pulse-ai) !important; }
.aid-go:hover:not(:disabled) { filter: brightness(1.08); }

@media (max-width: 480px) {
	.aid-title, .aid-body, .aid-foot { padding-inline: 16px; }
	.aid-q-type, .aid-opts { margin-left: 0; }
}
</style>
