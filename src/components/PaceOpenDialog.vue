<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pod-overlay" @click.self="cancel">
		<div ref="dialog"
			class="pod"
			:class="{ 'is-extend': extend }"
			role="dialog"
			aria-modal="true"
			aria-labelledby="pace-open-title"
			tabindex="-1"
			@keydown="onKey">
			<h2 id="pace-open-title" class="pod-title">{{ title }}</h2>

			<div class="pod-body">
				<!-- Probelauf: Reset und „Reset quiz" behalten ihn (Server) — wer
				     jetzt „echt" öffnen will, muss ihn hier sehen, nicht erst am
				     Endstand ohne Rangliste. -->
				<div v-if="practice && !extend" class="pulse-notice is-warn pod-practice">
					<PulseIcon name="warning" size="1.1em" class="pulse-notice-ico" />
					<span class="pod-practice-txt">{{ t('pulse', 'This is a practice run — nothing counts.') }}</span>
					<button type="button" class="pulse-btn is-secondary is-sm pod-practice-end" :disabled="busy" @click="$emit('end-practice')">{{ t('pulse', 'End practice run') }}</button>
				</div>

				<section class="pod-group">
					<!-- Beim Verlängern stellt der Titel diese Frage schon. -->
					<h3 v-if="!extend" class="pod-label">{{ t('pulse', 'When does the quiz end?') }}</h3>
					<PulseSegmented :value="end" :options="endOptions" :label="t('pulse', 'When does the quiz end?')" @input="setEnd" />
					<div v-if="end === 'deadline'" class="pod-deadline">
						<label class="pod-sublabel" for="pace-open-deadline">{{ t('pulse', 'Deadline') }}</label>
						<!-- Vorwahl setzt nur das Feld — das Feld bleibt die Wahrheit. -->
						<div class="pod-presets">
							<button v-for="p in presets"
								:key="p.key"
								type="button"
								class="pulse-btn is-secondary is-sm"
								:data-preset="p.key"
								@click="usePreset(p)">
								{{ p.label }}
							</button>
						</div>
						<input id="pace-open-deadline"
							ref="deadline"
							v-model="deadline"
							class="pace-input"
							type="datetime-local"
							:min="minInput"
							:max="maxInput"
							:aria-invalid="deadlineError ? 'true' : 'false'"
							aria-describedby="pace-open-rel">
						<!-- Relativ + absolut: ein AM/PM- oder Datumsdreher fällt vor dem
						     Absenden auf, nicht erst am Handy. -->
						<p id="pace-open-rel" class="pod-rel" :class="{ 'is-error': !!deadlineError }" aria-live="polite">
							{{ deadlineError || relLine }}
						</p>
						<!-- Die alte Frist war vorbei oder zu nah: vorbelegt ist morgen,
						     zum Vergleich steht die alte darunter. -->
						<p v-if="wasAt" class="pod-help pod-was">{{ t('pulse', 'Was: {time}', { time: wasText }) }}</p>
						<p v-if="!extend" class="pod-help">{{ t('pulse', 'Participants must finish on the same device.') }}</p>
					</div>
					<template v-if="extend">
						<p v-if="end === 'manual'" class="pod-help">{{ t('pulse', 'Closing will then also release the results.') }}</p>
						<p v-if="reopen" class="pod-help">{{ t('pulse', 'People continue where they stopped; on timed questions their time may already be up.') }}</p>
					</template>
				</section>

				<!-- Timer und Rückmeldung stehen seit dem Öffnen fest (Server) —
				     beim Verlängern geht es nur um das Ende. -->
				<template v-if="!extend">
					<section class="pod-group">
						<h3 class="pod-label">{{ t('pulse', 'Timer and speed points') }}</h3>
						<PulseSegmented v-model="timed" :options="timerOptions" :label="t('pulse', 'Timer and speed points')" />
						<p class="pod-help">{{ timed ? t('pulse', 'Each question has its time limit; faster correct answers earn more points.') : t('pulse', 'No time pressure — every correct answer earns 1000 points.') }}</p>
					</section>

					<section class="pod-group">
						<h3 class="pod-label">{{ t('pulse', 'When do people see if they’re right?') }}</h3>
						<PulseSegmented :value="practice ? 'each' : feedback" :options="feedbackOptions" :disabled="practice" :label="t('pulse', 'When do people see if they’re right?')" @input="feedback = $event" />
						<p v-if="practice" class="pod-help">{{ t('pulse', 'Practice run: always after each question.') }}</p>
						<p v-else class="pod-help">{{ feedback === 'each' ? t('pulse', 'Participants see right or wrong after every answer.') : t('pulse', 'Participants only see that their answer was saved. Results come when you release them.') }}</p>
						<p v-if="end === 'deadline' && feedback === 'each' && !practice" class="pulse-notice is-warn pod-warn">
							<PulseIcon name="warning" size="1.1em" class="pulse-notice-ico" />
							<span>{{ t('pulse', 'Feedback after each question lets people pass on the right answers — for grades, choose “At the end”.') }}</span>
						</p>
					</section>
				</template>
			</div>

			<div class="pod-foot">
				<p v-if="!extend" class="pulse-notice is-info pod-lock">
					<PulseIcon name="lock" size="1.1em" class="pulse-notice-ico" />
					<span>{{ t('pulse', 'Everyone starts on their own phone, with their own clock. From now on the questions are locked until you reset the room.') }}</span>
				</p>
				<p v-if="serverError" class="pulse-notice is-warn pod-error" role="alert">
					<PulseIcon name="warning" size="1.1em" class="pulse-notice-ico" />
					<span>{{ serverError }}</span>
				</p>
				<div class="pod-actions">
					<span v-if="!extend" class="pod-sum">{{ n('pulse', '%n question', '%n questions', total) }}</span>
					<button ref="cancel" type="button" class="pulse-btn is-secondary" :disabled="busy" @click="cancel">{{ t('pulse', 'Cancel') }}</button>
					<button type="button" class="pulse-btn is-primary pod-submit" :disabled="busy" @click="submit">{{ busy ? '…' : submitLabel }}</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
/*
 * Quiz im eigenen Tempo öffnen (Spezifikation §1.5, Modus `open`) und das
 * Ende ändern bzw. wieder öffnen (Modus `extend`, aus der Laufansicht).
 *
 * Ein Formular-Dialog in der Optik von PulseConfirm — pulseConfirm selbst hat
 * keine Felder. Beim Öffnen drei Fragen, jede eine Auswahl: Wann endet es?
 * Mit Timer? Wann sehen alle, ob es stimmt? Das Ende gibt die Vorgaben der
 * beiden anderen (ohne Frist ein Rennen: Timer an, Rückmeldung je Frage; mit
 * Frist eine Hausaufgabe: ohne Timer, Auflösung am Ende). Die im Raum
 * gespeicherten timed/feedback übernimmt der Dialog bewusst nicht — er
 * schickt beide Werte ausdrücklich mit, dann steht im Formular genau das,
 * was gilt.
 *
 * Beim Verlängern bleibt nur die erste Frage: Timer und Rückmeldung stehen
 * seit dem Öffnen fest. Vorbelegt ist das jetzige Ende; eine Frist, die schon
 * vorbei (oder zu nah) ist, wird durch „morgen" ersetzt, die alte steht als
 * „Was: …" darunter. Aus einem geschlossenen Fenster heißt das Wieder öffnen.
 *
 * Der Dialog schickt den Aufruf selbst ab; Fehler bleiben im Dialog (400
 * inline), nur 409 geht nach oben (`conflict`): dann stimmt der Raum hier
 * nicht mehr, und der Moderator lädt ihn neu.
 *
 * „Jetzt" ist Serverzeit (Laptopzeit + skew), sekündlich getaktet — die
 * Fristgrenzen prüft der Server gegen seine Uhr.
 *
 * Emits: done(roomJson) · conflict(message) · end-practice · cancel
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t, n } from '../util/l10n.js'
import { fmtDeadline, fmtDuration } from '../util/format.js'
import { DEADLINE_MIN, DEADLINE_MAX, toLocalInput, fromLocalInput, defaultDeadline, deadlinePresets, stopDeadline, windowState } from '../util/pace.js'
import PulseIcon from './ui/PulseIcon.vue'
import PulseSegmented from './ui/PulseSegmented.vue'

const serverNow = (skew) => Math.floor(Date.now() / 1000) + (Number(skew) || 0)

/**
 * Vorbelegung beim Verlängern: ohne Frist „bis ich schließe"; mit Frist
 * diese — außer sie ist vorbei oder zu nah für den Server, dann morgen (volle
 * Stunde) und die alte zum Vergleich. Altlast: eine Frist, die das frühere
 * „Stoppen ohne Freigabe“ gesetzt hat (Frist in zwei Minuten, dann schließen —
 * zwei Aufrufe), gilt als keine (stopDeadline): niemand hat sie gewählt. Seit
 * close {release: false} bleibt die Frist beim Stoppen 0, und die erste Zeile
 * greift von selbst.
 * @param {object|null} win `room.window`
 * @param {number} now jetzt in Serverzeit
 * @return {{end: string, deadline: string, wasAt: number}}
 */
function extendStart(win, now) {
	const at = (win && Number(win.closesAt)) || 0
	if (at <= 0 || stopDeadline(win)) return { end: 'manual', deadline: '', wasAt: 0 }
	if (at < now + DEADLINE_MIN + 60) return { end: 'deadline', deadline: toLocalInput(defaultDeadline(now, 1)), wasAt: at }
	return { end: 'deadline', deadline: toLocalInput(at), wasAt: 0 }
}

export default {
	name: 'PaceOpenDialog',
	components: { PulseIcon, PulseSegmented },
	props: {
		room: { type: Object, required: true },
		// 'open' (Deck, Entwurf) | 'extend' (Laufansicht: Ende ändern / wieder öffnen)
		mode: { type: String, default: 'open', validator: (v) => v === 'open' || v === 'extend' },
		skew: { type: Number, default: 0 },
	},
	data() {
		const now = serverNow(this.skew)
		const start = this.mode === 'extend' ? extendStart(this.room.window, now) : { end: 'manual', deadline: '', wasAt: 0 }
		return {
			now,
			end: start.end,     // 'manual' (bis ich schließe) | 'deadline'
			deadline: start.deadline, // Wert des datetime-local-Felds (Ortszeit)
			wasAt: start.wasAt, // alte, abgelaufene Frist (nur Verlängern)
			timed: true,
			feedback: 'each',
			busy: false,
			serverError: '',
		}
	},
	computed: {
		extend() {
			return this.mode === 'extend'
		},
		// Verlängern eines geschlossenen Fensters = wieder öffnen.
		reopen() {
			return this.extend && windowState(this.room.window, this.now) === 'closed'
		},
		practice() {
			return !!this.room.practice
		},
		title() {
			if (this.extend) return t('pulse', 'When does the quiz end?')
			return this.practice ? t('pulse', 'Open practice run') : t('pulse', 'Open quiz')
		},
		submitLabel() {
			if (this.extend) return this.reopen ? t('pulse', 'Reopen') : t('pulse', 'Save')
			return this.practice ? t('pulse', 'Open practice run') : t('pulse', 'Open quiz')
		},
		total() {
			const w = this.room.window
			return (w && w.total) || (this.room.polls || []).length
		},
		endOptions() {
			return [
				{ value: 'manual', label: t('pulse', 'When I close it') },
				{ value: 'deadline', label: t('pulse', 'At a set time') },
			]
		},
		timerOptions() {
			return [
				{ value: true, label: t('pulse', 'Timer on') },
				{ value: false, label: t('pulse', 'Timer off') },
			]
		},
		feedbackOptions() {
			return [
				{ value: 'each', label: t('pulse', 'After each question') },
				{ value: 'end', label: t('pulse', 'At the end') },
			]
		},
		presets() {
			const label = {
				'15m': t('pulse', 'In 15 min'),
				'1h': t('pulse', 'In 1 hour'),
				tomorrow8: t('pulse', 'Tomorrow 08:00'),
				'1w': t('pulse', 'In 1 week'),
			}
			return deadlinePresets(this.now).map((p) => ({ ...p, label: label[p.key] }))
		},
		// Browsergrenzen des Felds; die eigentliche Prüfung ist deadlineError.
		minInput() {
			return toLocalInput(this.now + DEADLINE_MIN + 60)
		},
		maxInput() {
			return toLocalInput(this.now + DEADLINE_MAX - 60)
		},
		closesAt() {
			return this.end === 'manual' ? 0 : fromLocalInput(this.deadline)
		},
		// Eine Minute Luft an beiden Enden: zwischen Klick und Server vergeht Zeit.
		deadlineError() {
			if (this.end !== 'deadline') return ''
			const lead = this.closesAt - this.now
			if (!isFinite(this.closesAt) || lead < DEADLINE_MIN + 60 || lead > DEADLINE_MAX - 60) {
				return t('pulse', 'The deadline must be between one minute and 30 days from now.')
			}
			return ''
		},
		relLine() {
			if (this.end !== 'deadline' || this.deadlineError) return ''
			return t('pulse', 'Closes in {duration} — {time}', { duration: fmtDuration(this.closesAt - this.now), time: fmtDeadline(this.closesAt) })
		},
		wasText() {
			return fmtDeadline(this.wasAt)
		},
	},
	mounted() {
		// Nach dem Schließen zurück auf den Auslöser (im Deck: „Open quiz …";
		// in der Laufansicht setzt PaceRun den Fokus selbst auf ihr Menü).
		this.returnFocus = document.activeElement
		this.tick = setInterval(() => { this.now = serverNow(this.skew) }, 1000)
		document.addEventListener('keydown', this.onDocKey)
		this.$nextTick(() => {
			const first = this.$el.querySelector('.pseg-item[aria-checked="true"]')
			;(first || this.$refs.dialog).focus()
		})
	},
	beforeDestroy() {
		clearInterval(this.tick)
		document.removeEventListener('keydown', this.onDocKey)
		const el = this.returnFocus
		if (el && el.isConnected && typeof el.focus === 'function') el.focus()
	},
	methods: {
		t,
		n,
		// Das Ende bestimmt die Vorgaben der beiden anderen Fragen.
		setEnd(v) {
			this.end = v
			this.timed = v === 'manual'
			this.feedback = v === 'manual' ? 'each' : 'end'
			if (v === 'deadline' && !this.deadline) this.deadline = toLocalInput(defaultDeadline(this.now))
		},
		usePreset(p) {
			this.deadline = toLocalInput(p.ts)
		},
		cancel() {
			if (this.busy) return
			this.$emit('cancel')
		},
		async submit() {
			if (this.busy) return
			this.serverError = ''
			if (this.deadlineError) {
				if (this.$refs.deadline) this.$refs.deadline.focus()
				return
			}
			this.busy = true
			const fallback = this.extend ? t('pulse', 'Could not change the end.') : t('pulse', 'Could not open the quiz.')
			try {
				// Verlängern schickt nur das Ende — Timer und Rückmeldung bleiben (Server).
				const body = this.extend
					? { action: 'extend', closesAt: this.closesAt }
					: { action: 'open', closesAt: this.closesAt, timed: this.timed, feedback: this.practice ? 'each' : this.feedback }
				const { data } = await axios.post(generateUrl('/apps/pulse/api/1.0/rooms/' + this.room.code + '/pace'), body, { timeout: 15000 })
				this.$emit('done', data)
			} catch (e) {
				const msg = e?.response?.data?.message
				if (e?.response?.status === 409) {
					this.$emit('conflict', msg || fallback)
					return
				}
				this.serverError = msg || fallback
			} finally {
				this.busy = false
			}
		},
		onDocKey(e) {
			if (e.key === 'Escape') {
				e.preventDefault()
				this.cancel()
			}
		},
		// Fokusfang: Tab bleibt im Dialog.
		onKey(e) {
			if (e.key !== 'Tab') return
			const els = Array.from(this.$refs.dialog.querySelectorAll('button, input, [tabindex="0"]'))
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
.pod-overlay {
	position: fixed;
	inset: 0;
	z-index: 10000;
	display: grid;
	place-items: center;
	/* Unter dem Nextcloud-Kopf bleiben: dessen Suchleiste liegt sonst über
	   der Oberkante eines hohen Dialogs. */
	padding: calc(var(--header-height, 50px) + 12px) 16px 12px;
	background: rgba(0, 0, 0, .5);
}
/* Kopf und Fuß stehen, nur der Körper scrollt — die Knöpfe bleiben in
   Reichweite, auch bei 768 px Höhe mit Frist und Warnung. */
.pod {
	width: 100%;
	max-width: 560px;
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
.pod:focus { outline: none; }
.pod-title { margin: 0; padding: 18px 22px 2px; font-size: var(--t-lead); font-weight: 800; }
.pod-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; padding: 8px 22px 6px; display: flex; flex-direction: column; gap: 16px; }
.pod-group { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; }
.pod-label { margin: 0; font-size: var(--t-body); font-weight: 800; }
.pod-sublabel { font-size: var(--t-sm); font-weight: 700; color: var(--pulse-text-2); }
.pod-help { margin: 0; font-size: var(--t-sm); color: var(--pulse-text-2); line-height: 1.45; }
.pod-deadline { align-self: stretch; display: flex; flex-direction: column; align-items: flex-start; gap: 8px; margin-top: 6px; }
.pod-presets { display: flex; flex-wrap: wrap; gap: 8px; }
.pod-rel { margin: 0; font-size: var(--t-sm); color: var(--pulse-text-2); font-variant-numeric: tabular-nums; }
.pod-rel.is-error { color: var(--pulse-error); font-weight: 700; }
.pod-was { font-variant-numeric: tabular-nums; }
.pod-practice { align-items: center; flex-wrap: wrap; }
.pod-practice-txt { flex: 1 1 12em; font-weight: 600; }
.pod-warn, .pod-error { margin: 4px 0 0; align-self: stretch; }
.pod-foot { padding: 12px 22px 16px; border-block-start: 1px solid var(--pulse-border); display: flex; flex-direction: column; gap: 10px; }
.pod-lock { margin: 0; }
.pod-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
.pod-sum { flex: 1 1 auto; font-size: var(--t-sm); font-weight: 700; color: var(--pulse-text-2); font-variant-numeric: tabular-nums; }
.pod-submit { min-width: 150px; }

/* Datum + Uhrzeit: dieselben Regeln wie .pinput im Moderator (Rahmen, Fokus,
   Pulse-Tokens), Schrift 16 px — kleiner zoomt iOS beim Antippen. NC stylt
   Eingaben global über Element-Selektoren, daher die !important-Resets. */
.pace-input {
	background: var(--pulse-bg) !important;
	color: var(--pulse-text) !important;
	border: 2px solid var(--pulse-border-strong) !important;
	border-radius: var(--pulse-r-el) !important;
	padding: 9px 12px !important;
	font: inherit;
	font-size: 16px !important;
	width: 100%;
	max-width: 300px;
	height: auto !important;
	min-height: 44px;
	margin: 0 !important;
	box-sizing: border-box;
	font-variant-numeric: tabular-nums;
}
.pace-input:focus { border-color: var(--pulse-primary) !important; }
.pace-input:focus-visible { outline: 2px solid var(--pulse-primary); outline-offset: 1px; }
.pace-input[aria-invalid="true"] { border-color: var(--pulse-error) !important; }

@media (max-width: 480px) {
	.pod-title { padding-inline: 16px; }
	.pod-body { padding-inline: 16px; }
	.pod-foot { padding-inline: 16px; }
	.pod-sum { flex-basis: 100%; }
}
</style>
