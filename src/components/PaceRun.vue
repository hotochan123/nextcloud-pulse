<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="pace-run">
		<header class="pace-top">
			<div class="pace-top-row">
				<h2 ref="title" class="pace-title" :class="{ 'is-untitled': !room.title }" tabindex="-1">{{ room.title || t('pulse', 'Your quiz') }}</h2>
				<span v-if="stateChip" class="pulse-chip pace-state" :class="stateChip.cls"><span v-if="stateChip.dot" class="pulse-chip-dot" />{{ stateChip.label }}</span>
				<span class="pulse-chip is-live pace-here" :title="t('pulse', '{count} participants active in the last few seconds', { count: here })"><span class="pulse-chip-dot" />{{ t('pulse', '{count} here', { count: here }) }}</span>
				<span v-if="room.practice" class="pulse-chip is-warning"><PulseIcon name="warning" size="1em" /> {{ t('pulse', 'Practice run') }}</span>
				<span v-if="room.joinsLocked" class="pulse-chip is-locked"><PulseIcon name="lock" size="1em" /> {{ t('pulse', 'Joining locked') }}</span>
				<span v-if="pointsShown" class="pulse-chip is-warning"><PulseIcon name="eye" size="1em" /> {{ t('pulse', 'Points visible') }}</span>
				<span class="pace-spacer" />
				<span class="pace-code" :title="t('pulse', 'Room code')">{{ spacedCode }}</span>
				<PulseMenu ref="menu" :items="menuItems" :label="t('pulse', 'More actions')" small />
			</div>
			<!-- Die beim Öffnen festgelegten Einstellungen bleiben sichtbar — wie am Handy. -->
			<p class="pace-facts">{{ facts }}</p>
			<p v-if="!online" class="pulse-notice is-warn pace-offline" role="status"><PulseIcon name="warning" size="1.1em" class="pulse-notice-ico" /><span>{{ t('pulse', 'No connection — the room is not answering. The main action stays disabled until it is back.') }}</span></p>
		</header>

		<div class="pace-body">
			<!-- Privat: Namen, Stand und Punkte je Person. Projiziert wird das
			     Beamer-Fenster, nie diese Seite. -->
			<section class="pace-people" aria-labelledby="pace-people-head">
				<h3 id="pace-people-head" class="pace-private-head"><PulseIcon name="lock" size="1.05em" /> {{ t('pulse', 'Only for you') }}</h3>
				<p class="pace-private-sub">{{ t('pulse', 'Progress per person. Mirror the projector window, not this page.') }}</p>
				<div class="pace-tools">
					<span class="pace-count">{{ t('pulse', '{joined} joined · {started} started · {finished} finished', { joined: players.length, started: startedCount, finished: finishedCount }) }}</span>
					<span class="pace-sort">
						<span class="pace-sort-label" aria-hidden="true">{{ t('pulse', 'Sort by') }}</span>
						<PulseSegmented v-model="sortKey" :options="sortOptions" :label="t('pulse', 'Sort by')" />
					</span>
				</div>
				<div v-if="players.length" class="pace-table-wrap">
					<table class="pace-table">
						<caption class="pace-sr">{{ t('pulse', 'Participants') }}</caption>
						<thead>
							<tr>
								<th scope="col">{{ t('pulse', 'Name') }}</th>
								<th scope="col">{{ t('pulse', 'Question') }}</th>
								<th scope="col" class="is-num is-long">{{ t('pulse', 'Answered') }}</th>
								<th scope="col" class="is-num is-long">{{ t('pulse', 'Skipped') }}</th>
								<th scope="col" class="is-num">{{ t('pulse', 'Correct') }}</th>
								<th scope="col" class="is-num">{{ t('pulse', 'Points') }}</th>
								<th scope="col">{{ t('pulse', 'Last active') }}</th>
								<th scope="col" class="pace-act"><span class="pace-sr">{{ t('pulse', 'Row actions') }}</span></th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="p in sortedPlayers" :key="p.id">
								<td>
									<!-- Wird es eng, rutscht der Chip unter den Namen statt die Tabelle zu verbreitern. -->
									<div class="pace-name">
										<span class="pace-nick">{{ p.nickname }}</span>
										<span v-if="p.pending > 0" class="pulse-chip is-warning pace-cell-chip"><PulseIcon name="hourglass" size="1em" /> {{ n('pulse', '%n to check', '%n to check', p.pending) }}</span>
									</div>
								</td>
								<td>
									<span v-if="p.finished" class="pulse-chip is-success pace-cell-chip"><PulseIcon name="flag" size="1em" /> {{ t('pulse', 'Finished') }}</span>
									<span v-else-if="!p.started" class="pace-muted">{{ t('pulse', 'Not started') }}</span>
									<span v-else class="pace-pos">{{ p.k }} / {{ total }}</span>
								</td>
								<td class="is-num">{{ p.answered }}</td>
								<td class="is-num" :class="{ 'pace-muted': !p.skipped }">{{ p.skipped }}</td>
								<td class="is-num">{{ p.correct === null ? '—' : p.correct }}</td>
								<td class="is-num">{{ p.score === null ? '—' : fmtNum(p.score, 0) }}</td>
								<td>
									<span v-if="p.online" class="pulse-chip is-live pace-cell-chip"><span class="pulse-chip-dot" />{{ t('pulse', 'online') }}</span>
									<span v-else class="pace-muted">{{ fmtAgo(p.lastActivity, nowServer) || '—' }}</span>
								</td>
								<!-- Genau ein Eintrag, und der ist rot: wer das Gerät verloren
								     hat oder einen Namen besetzt, wird hier entfernt. -->
								<td class="pace-act">
									<PulseMenu :items="rowMenu(p)" :label="t('pulse', 'Actions for {name}', { name: p.nickname })" small />
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<p v-else-if="progress" class="pace-empty">{{ t('pulse', 'Nobody has joined yet — share the code.') }}</p>
			</section>

			<aside class="pace-side">
				<!-- Direkt nach dem Öffnen ist das Teilen des Links die Hauptaufgabe
				     (Hausaufgabe: in den Chat der Klasse). Nur solange offen. -->
				<section v-if="state === 'open'" class="pace-card pace-join" aria-labelledby="pace-join-head">
					<h3 id="pace-join-head" class="pace-side-head">{{ t('pulse', 'Join in') }}</h3>
					<div class="pace-join-in">
						<div class="pace-qr"><QrCode :value="joinUrlFull" /></div>
						<span class="pace-join-code">{{ spacedCode }}</span>
					</div>
					<div class="pace-join-link">
						<a class="pace-join-url" :href="joinPath" target="_blank" rel="noopener">{{ joinUrlFull }}</a>
						<button class="pulse-btn is-secondary is-icon is-xs" :title="t('pulse', 'Copy join link')" :aria-label="t('pulse', 'Copy join link')" @click="$emit('copy')"><PulseIcon name="copy" size="1em" /></button>
					</div>
				</section>

				<!-- Offene Freitexte zählen 0 Punkte, bis sie bewertet sind — deshalb
				     oben und vor der Freigabe als Hauptaktion. Ohne Lösungsschlüssel
				     (keyless): der Laptop hängt oft am Beamer. -->
				<section v-if="pendingAnswers.length || checked.length" ref="grading" class="pace-card pace-grading" aria-labelledby="pace-grade-head">
					<!-- Bleibt stehen, solange „Checked just now" etwas hat — dann ohne
					     „0 … warten". Die Warnung nach der Freigabe nur, solange hier etwas
					     zu bewerten ist (offen oder die Liste zum Umbewerten aufgeklappt). -->
					<h3 id="pace-grade-head" ref="gradeHead" class="pace-side-head" tabindex="-1">{{ pendingSum ? n('pulse', '%n free text waiting', '%n free texts waiting', pendingSum) : t('pulse', 'All free texts checked') }}</h3>
					<p v-if="state === 'released' && (pendingSum > 0 || checkedOpen)" class="pulse-notice is-warn pace-grade-warn"><PulseIcon name="warning" size="1.1em" class="pulse-notice-ico" /><span>{{ t('pulse', 'Standings update for everyone.') }}</span></p>
					<div v-for="g in pendingGroups" :key="g.pollId" class="pace-grade-q">
						<p class="pace-grade-qhead"><span class="pace-grade-k">{{ t('pulse', 'Question {number}', { number: g.k }) }}</span> <span class="pace-grade-qtext" :title="g.question">{{ g.question }}</span></p>
						<TextGrading keyless :busy="gradingBusy" :results="g.results" @grade="(sample, ok) => grade(g, sample, ok)" />
					</div>
					<!-- Rückgängig ohne Umweg über die Zusammenfassung: derselbe
					     Endpunkt bewertet um. Nur diese Sitzung. -->
					<details v-if="checked.length" class="pace-checked" @toggle="checkedOpen = $event.target.open">
						<summary>{{ t('pulse', 'Checked just now ({count})', { count: checked.length }) }}</summary>
						<div v-for="g in checkedGroups" :key="g.pollId" class="pace-grade-q">
							<p class="pace-grade-qhead"><span class="pace-grade-k">{{ t('pulse', 'Question {number}', { number: g.k }) }}</span> <span class="pace-grade-qtext" :title="g.question">{{ g.question }}</span></p>
							<TextGrading keyless :busy="gradingBusy" :results="g.results" @grade="(sample, ok) => grade(g, sample, ok)" />
						</div>
					</details>
				</section>

				<!-- Was der Saal sieht — die echte Beamer-Seite, verkleinert. -->
				<section class="pace-card pace-preview" aria-labelledby="pace-preview-head">
					<h3 id="pace-preview-head" class="pace-side-head">{{ t('pulse', 'Projector view') }}</h3>
					<ScreenPreview :src="screenPath" :title="t('pulse', 'Preview of the projector view')" />
					<button class="pulse-btn is-secondary is-sm pace-preview-open" @click="openBeamer"><PulseIcon name="beamer" size="1em" /> {{ t('pulse', 'Projector') }}</button>
				</section>

				<section v-if="questions.length" class="pace-card pace-questions" aria-labelledby="pace-q-head">
					<h3 id="pace-q-head" class="pace-side-head">{{ t('pulse', 'Questions') }}</h3>
					<ol class="pace-qlist">
						<li v-for="q in questions" :key="q.pollId" class="pace-q">
							<span class="pace-qk">{{ q.k }}</span>
							<span class="pace-qbody">
								<span class="pace-qtext" :title="q.question">{{ q.question }}</span>
								<span class="pace-qstats"><span class="pace-qstat">{{ t('pulse', 'Reached') }} <b>{{ q.reached }}</b></span> · <span class="pace-qstat">{{ t('pulse', 'Answered') }} <b>{{ q.answered }}</b></span> · <span class="pace-qstat">{{ t('pulse', 'Correct') }} <b>{{ q.correct === null ? '—' : q.correct }}</b></span></span>
							</span>
							<span v-if="q.pending > 0" class="pulse-chip is-warning pace-cell-chip"><PulseIcon name="hourglass" size="1em" /> {{ n('pulse', '%n to check', '%n to check', q.pending) }}</span>
						</li>
					</ol>
				</section>
			</aside>
		</div>

		<!-- Genau EINE gefüllte Hauptaktion, und sie weiß, was gerade dran ist.
		     Nie rot: das Gewicht trägt die Bestätigung. -->
		<footer class="pace-bar">
			<button v-if="primary"
				class="pulse-btn is-primary is-lg pace-primary"
				:disabled="!online || busy"
				:title="online ? '' : t('pulse', 'No connection to the room')"
				@click="runPrimary">
				<PulseIcon :name="primary.icon" size="1.05em" /> {{ primary.label }}
			</button>
			<p class="pace-status" role="status">{{ statusLine }}</p>
			<span class="pace-spacer" />
			<span class="pulse-chip is-neutral pace-done">{{ doneText }}</span>
		</footer>

		<!-- „Change the end …" / „Reopen …": derselbe Dialog wie beim Öffnen,
		     nur mit der Frage nach dem Ende. -->
		<PaceOpenDialog v-if="extendOpen"
			:room="room"
			:skew="serverSkew"
			mode="extend"
			@done="onExtendDone"
			@conflict="onExtendConflict"
			@cancel="closeExtend" />
	</section>
</template>

<script>
/*
 * Laufansicht im eigenen Tempo (Spezifikation §1.6) — Phase 'pace' des
 * Moderators, sobald das Quiz geöffnet ist.
 *
 * Nichts hängt an diesem Tab: Fristablauf, Urteile und der Fortschritt der
 * Handys laufen auf dem Server. Die Ansicht ist Anzeige plus Knöpfe — eine
 * Tabelle je Person (privat, mit „entfernen"), die offenen Freitexte zum
 * Bewerten, die Beamer-Vorschau, die Fragen mit ihren Zahlen, der Beitritt
 * zum Teilen, und unten genau eine Hauptaktion (schließen, bewerten,
 * freigeben, CSV).
 *
 * Eigene /progress-Schleife (progress-poll: höchstens eine Anfrage unterwegs,
 * Überholtes wird verworfen). Nach jeder eigenen Aktion refresh().
 *
 * Das Fenster (`room.window`) ist die Wahrheit für Zustand und Knöpfe: der
 * Moderator hält es über `window` (aus /progress) und `room` (Antwort jedes
 * /pace-Aufrufs) aktuell.
 *
 * Emits: room(json) · window(win) · skew(s) · go('deck'|'summary'|'home') ·
 *        reset(counts) · end-practice · copy · reload
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t, n } from '../util/l10n.js'
import { showError } from '../toast.js'
import { pulseConfirm } from '../util/confirm.js'
import { fmtAgo, fmtDeadline, fmtNum, formatCode, paceStateChip } from '../util/format.js'
import { downloadCsv } from '../util/csv.js'
import { windowState, progressDelay } from '../util/pace.js'
import progressPoll, { progressCounts } from '../mixins/progress-poll.js'
import PaceOpenDialog from './PaceOpenDialog.vue'
import QrCode from './QrCode.vue'
import ScreenPreview from './ScreenPreview.vue'
import TextGrading from './TextGrading.vue'
import PulseIcon from './ui/PulseIcon.vue'
import PulseMenu from './ui/PulseMenu.vue'
import PulseSegmented from './ui/PulseSegmented.vue'

// Jede Anfrage im eigenen Tempo hat ein Zeitlimit — ein hängender Aufruf
// hielte sonst `busy` und damit die Hauptaktion fest.
const TIMEOUT = 15000

const sumCount = (rows) => rows.reduce((acc, a) => acc + (Number(a.count) || 0), 0)

export default {
	name: 'PaceRun',
	components: { PaceOpenDialog, QrCode, ScreenPreview, TextGrading, PulseIcon, PulseMenu, PulseSegmented },
	mixins: [progressPoll],
	props: {
		room: { type: Object, required: true },
		joinUrl: { type: String, default: '' },
		joinUrlFull: { type: String, default: '' },
		joinPath: { type: String, default: '' },
		// Übergang per Klick hierher (Moderator.enterPace): Fokus auf den Titel.
		focusTitle: { type: Boolean, default: false },
	},
	data() {
		return {
			nowSec: Math.floor(Date.now() / 1000), // eigener Sekundentakt (Frist, „vor 3 Min.")
			busy: false,       // ein /pace-Aufruf läuft
			showScores: false, // „Show points": Punkte trotz „Auflösung am Ende" (?scores=1)
			sortPick: '',      // eigene Sortierwahl bis zum Verlassen; leer = Vorgabe
			extendOpen: false, // Dialog „Change the end …" / „Reopen …"
			gradingBusy: false, // eine Bewertung läuft
			// „Checked just now" — nur diese Sitzung: {pollId, k, question, answer, count, ok}
			checked: [],
			checkedOpen: false, // „Checked just now" aufgeklappt
		}
	},
	computed: {
		// Fenster aus dem Raum-JSON (frisch gehalten), zur Not aus /progress.
		win() {
			return this.room.window || (this.progress && this.progress.window) || null
		},
		// „Jetzt" in Serverzeit — nie die nackte Laptop-Uhr gegen Server-Zeitstempel.
		nowServer() {
			return this.nowSec + this.serverSkew
		},
		// Eine lokal abgelaufene Frist gilt schon als geschlossen; der nächste Abruf bestätigt es.
		state() {
			return windowState(this.win, this.nowServer)
		},
		stateChip() {
			return this.win ? paceStateChip(this.win, this.nowServer) : null
		},
		spacedCode() {
			return formatCode(this.room.code)
		},
		screenPath() {
			return generateUrl('/apps/pulse/screen/' + this.room.code)
		},
		here() {
			return (this.progress && Number(this.progress.present)) || 0
		},
		players() {
			return (this.progress && Array.isArray(this.progress.players)) ? this.progress.players : []
		},
		questions() {
			return (this.progress && Array.isArray(this.progress.questions)) ? this.progress.questions : []
		},
		total() {
			return (this.progress && this.progress.n) || (this.win && this.win.total) || 0
		},
		startedCount() {
			return this.players.filter((p) => p.started).length
		},
		finishedCount() {
			return this.players.filter((p) => p.finished).length
		},
		// Gestartet und noch nicht fertig.
		workingCount() {
			return this.players.filter((p) => p.started && !p.finished).length
		},
		scoresHidden() {
			return !!(this.progress && this.progress.scoresHidden)
		},
		// Der Schalter hängt an der Einstellung, nicht an `scoresHidden` — sonst
		// verschwände er in dem Moment, in dem man ihn einschaltet.
		scoresToggle() {
			return !!this.win && this.win.feedback === 'end' && this.state !== 'released'
		},
		pointsShown() {
			return this.showScores && this.scoresToggle
		},
		facts() {
			const w = this.win || {}
			return [
				n('pulse', '%n question', '%n questions', this.total),
				w.timed ? t('pulse', 'with time limit') : t('pulse', 'no time limit'),
				w.feedback === 'end' ? t('pulse', 'results at the end') : t('pulse', 'feedback after each question'),
			].join(' · ')
		},
		sortOptions() {
			const opts = [
				{ value: 'furthest', label: t('pulse', 'Furthest first') },
				{ value: 'name', label: t('pulse', 'Name') },
			]
			if (!this.scoresHidden) opts.push({ value: 'points', label: t('pulse', 'Points') })
			return opts
		},
		// Vorgabe: freigegeben mit sichtbaren Punkten nach Punkten, sonst wer am
		// weitesten ist. Eine eigene Wahl gilt, solange es sie noch gibt.
		sortKey: {
			get() {
				if (this.sortPick && this.sortOptions.some((o) => o.value === this.sortPick)) return this.sortPick
				return this.state === 'released' && !this.scoresHidden ? 'points' : 'furthest'
			},
			set(v) {
				this.sortPick = v
			},
		},
		sortedPlayers() {
			const byName = (a, b) => String(a.nickname).localeCompare(String(b.nickname))
			const list = this.players.slice()
			if (this.sortKey === 'name') return list.sort(byName)
			if (this.sortKey === 'points') {
				return list.sort((a, b) => ((b.score || 0) - (a.score || 0)) || ((b.correct || 0) - (a.correct || 0)) || byName(a, b))
			}
			// Am weitesten zuerst: fertig, dann nach Frage absteigend, dann Name.
			return list.sort((a, b) => (Number(!!b.finished) - Number(!!a.finished)) || ((b.k || 0) - (a.k || 0)) || byName(a, b))
		},
		/*
		 * Offene Freitexte aus /progress (roh, nie mit Lösung). Was hier gerade
		 * bewertet wurde, fällt sofort heraus, nicht erst mit dem nächsten
		 * Abruf: der Server wertet jede spätere Antwort mit derselben
		 * Normalform selbst — ein solcher Eintrag ist also immer veraltet, und
		 * so tippt niemand dieselbe Antwort zweimal.
		 */
		pendingAnswers() {
			const list = (this.progress && Array.isArray(this.progress.pendingAnswers)) ? this.progress.pendingAnswers : []
			return list.filter((a) => !this.checked.some((c) => c.pollId === a.pollId && c.answer === a.answer))
		},
		// Offene Freitext-Antworten insgesamt (Summe der Gruppen).
		pendingSum() {
			return sumCount(this.pendingAnswers)
		},
		pendingGroups() {
			return this.groupAnswers(this.pendingAnswers.map((a) => ({ ...a, status: 'open' })))
		},
		checkedGroups() {
			return this.groupAnswers(this.checked.map((c) => ({ ...c, status: c.ok ? 'accepted' : 'rejected' })))
		},
		// Der Satz, der an jede Schluss-/Freigabe-Bestätigung gehängt wird.
		pendingNote() {
			return this.pendingSum > 0
				? ' ' + n('pulse', '%n free-text answer is not checked yet — it counts as 0 points.', '%n free-text answers are not checked yet — they count as 0 points.', this.pendingSum)
				: ''
		},
		/*
		 * DIE Hauptaktion — erste zutreffende Zeile gewinnt (§1.6). Offene
		 * Freitexte nach dem Schluss gehen vor der Freigabe: sie zählen 0
		 * Punkte, bis sie bewertet sind, und eine spätere Bewertung änderte
		 * einen schon gesehenen Endstand.
		 */
		primary() {
			const st = this.state
			if (st === 'open') {
				return { key: 'close', label: t('pulse', 'Close quiz'), icon: 'check', act: this.win.closesAt > 0 ? this.confirmCloseTimed : this.confirmCloseRace }
			}
			if ((st === 'closed' || st === 'released') && this.room.practice) {
				return { key: 'practice', label: t('pulse', 'End practice run'), icon: 'reset', act: () => this.$emit('end-practice') }
			}
			if ((st === 'closed' || st === 'released') && this.pendingSum > 0) {
				return { key: 'check', label: n('pulse', 'Check %n answer', 'Check %n answers', this.pendingSum), icon: 'hourglass', act: this.focusGrading }
			}
			if (st === 'closed') {
				return { key: 'release', label: t('pulse', 'Release results'), icon: 'eye', act: this.confirmRelease }
			}
			if (st === 'released') {
				return { key: 'csv', label: t('pulse', 'Participants as CSV'), icon: 'download', act: () => downloadCsv(this.room.code, 'players') }
			}
			return null
		},
		/*
		 * „Alle durch" zählt nur, wer gerade da ist: wer nicht mehr pollt, hält
		 * das Rennen nicht auf. Wer da ist und noch nicht gestartet hat, ist
		 * aber auch nicht durch — und ohne eine anwesende fertige Person stünde
		 * der Satz sonst in einem leeren Raum.
		 */
		allThrough() {
			const here = this.players.filter((p) => p.online)
			return this.startedCount > 0 && here.length > 0 && here.every((p) => p.finished)
		},
		statusLine() {
			const st = this.state
			const w = this.win || {}
			if (st === 'open') {
				if (!this.startedCount) return t('pulse', 'Open — everyone now taps “Start quiz” on their phone.')
				if (this.allThrough) {
					return w.closesAt > 0
						? t('pulse', 'Everyone still here is through — you can close the quiz now.')
						: t('pulse', 'Everyone still here is through — close the quiz to release the results.')
				}
				return w.closesAt > 0
					? t('pulse', 'Open until {time} — then it closes by itself.', { time: fmtDeadline(w.closesAt) })
					: t('pulse', 'Open until you close it — closing also releases the results.')
			}
			if (st === 'closed') return t('pulse', 'Closed — results stay hidden until you release them.')
			if (st === 'released') return t('pulse', 'Results released on {time}.', { time: fmtDeadline(w.releasedAt) })
			return ''
		},
		/*
		 * „still working" nur, solange offen: `finished` heißt „letzte Frage
		 * erreicht" (PaceService::isFinished) — wer vorher aufgehört hat, bleibt
		 * nach dem Schließen gestartet und nicht fertig, kann aber nichts mehr tun.
		 */
		doneText() {
			const base = t('pulse', '{done} of {total} finished', { done: this.finishedCount, total: this.players.length })
			return this.state === 'open' && this.workingCount > 0
				? base + ' · ' + n('pulse', '%n still working', '%n still working', this.workingCount)
				: base
		},
		// Zählstand für zerstörende Bestätigungen (Zurücksetzen) — aus dem eigenen Abruf.
		counts() {
			return progressCounts(this.progress)
		},
		/*
		 * Überlaufmenü (Tabelle §1.6): Schalter mit Häkchen, dann das Ende,
		 * die Freigabe, wo sie nicht schon Hauptaktion ist, beide CSV, die
		 * Wege hinaus, Rotes zuletzt.
		 */
		menuItems() {
			const st = this.state
			const items = []
			if (st === 'open' || st === 'closed') {
				items.push({ key: 'lock', label: t('pulse', 'Lock joining'), checked: !!this.room.joinsLocked, act: this.toggleJoinsLocked })
			}
			if (this.scoresToggle) {
				items.push({ key: 'scores', label: t('pulse', 'Show points'), checked: this.showScores, act: this.toggleScores })
			}
			if (st === 'open') {
				items.push({ key: 'extend', label: t('pulse', 'Change the end …'), icon: 'calendar', act: this.openExtend })
			}
			if (st === 'closed') {
				items.push({ key: 'reopen', label: t('pulse', 'Reopen …'), icon: 'reset', act: this.openExtend })
			}
			// Offen nur mit Frist (ohne Frist gibt „Close quiz" schon frei);
			// geschlossen nur, wenn die Hauptaktion etwas anderes ist.
			if (st === 'open' && this.win.closesAt > 0) {
				items.push({ key: 'release', label: t('pulse', 'Release results'), icon: 'eye', act: this.confirmReleaseOpen })
			}
			if (st === 'closed' && (!this.primary || this.primary.key !== 'release')) {
				items.push({ key: 'release', label: t('pulse', 'Release results'), icon: 'eye', act: this.confirmRelease })
			}
			if (!this.primary || this.primary.key !== 'csv') {
				items.push({ key: 'csv-players', label: t('pulse', 'Participants as CSV'), icon: 'download', act: () => downloadCsv(this.room.code, 'players') })
			}
			items.push({ key: 'csv-answers', label: t('pulse', 'Answers as CSV'), icon: 'download', act: () => downloadCsv(this.room.code, 'answers') })
			items.push({ key: 'beamer', label: t('pulse', 'Projector'), icon: 'beamer', act: this.openBeamer })
			items.push({ key: 'summary', label: t('pulse', 'Summary'), icon: 'poll', act: () => this.$emit('go', 'summary') })
			items.push({ key: 'deck', label: t('pulse', 'Overview'), icon: 'edit', act: () => this.$emit('go', 'deck') })
			items.push({ key: 'home', label: t('pulse', 'My rooms'), icon: 'chevron', act: () => this.$emit('go', 'home') })
			if (st !== 'open') {
				items.push({ key: 'reset', label: t('pulse', 'Reset quiz'), icon: 'reset', danger: true, act: () => this.$emit('reset', this.counts) })
			}
			return items
		},
	},
	created() {
		// Außerhalb von data(): der Handle muss nicht reaktiv sein.
		this.tick = null
	},
	mounted() {
		this.tick = setInterval(() => { this.nowSec = Math.floor(Date.now() / 1000) }, 1000)
		// Der Auslöser (Öffnen-Dialog, „Show progress", „Back to progress") ist mit
		// der alten Ansicht verschwunden — ohne das fiele der Fokus auf <body>.
		if (this.focusTitle) {
			this.$nextTick(() => {
				if (this.$refs.title) this.$refs.title.focus({ preventScroll: true })
			})
		}
	},
	beforeDestroy() {
		if (this.tick) clearInterval(this.tick)
	},
	methods: {
		t,
		n,
		fmtNum,
		fmtAgo,

		// ── /progress (progress-poll) ───────────────────────────────────────
		progressUrl() {
			return generateUrl('/apps/pulse/api/1.0/rooms/' + this.room.code + '/progress')
		},
		progressParams() {
			return this.showScores ? { scores: 1 } : {}
		},
		pollDelay() {
			if (document.hidden) return null // pausieren -> onPollVisibility weckt auf
			return progressDelay(this.state, this.players.length, this.idleStreak)
		},
		onProgress(data) {
			this.$emit('skew', this.serverSkew)
			const w = data.window
			const cur = this.room.window
			if (w && (!cur || w.state !== cur.state || w.closesAt !== cur.closesAt)) this.$emit('window', w)
		},
		onGone() {
			showError(t('pulse', 'Room not found — it may have been deleted.'))
			this.$emit('go', 'home')
		},
		// 409: der Raum läuft nicht mehr im eigenen Tempo -> neu laden.
		onNotPaced() {
			this.$emit('reload')
		},

		// ── Aktionen (/pace) ────────────────────────────────────────────────
		async post(body) {
			const { data } = await axios.post(generateUrl('/apps/pulse/api/1.0/rooms/' + this.room.code + '/pace'), body, { timeout: TIMEOUT })
			this.$emit('room', data)
			return data
		},
		/*
		 * Rahmen jedes /pace-Aufrufs: busy an; geschafft -> sofort neu abrufen;
		 * 400 -> Servermeldung, neu abrufen; 409 -> Servermeldung, Raum neu laden
		 * (er steht auf dem Server anders als hier); 404/403 -> wie der Abruf.
		 */
		async act(run, fallback) {
			if (this.busy) return
			this.busy = true
			try {
				await run()
				this.refresh()
			} catch (e) {
				this.fail(e, fallback)
			} finally {
				this.busy = false
			}
		},
		fail(e, fallback) {
			const st = e?.response?.status
			if (st === 404 || st === 403) {
				this.pollStopped = true
				this.pollClear()
				this.onGone()
				return
			}
			showError(e?.response?.data?.message || fallback)
			if (st === 409) {
				this.$emit('reload')
				return
			}
			this.refresh()
		},
		runPrimary() {
			if (this.primary && !this.busy) this.primary.act()
		},
		// Ohne Frist gibt Schließen sofort frei — deshalb der dritte Ausgang. `release: true`
		// hält das Versprechen auch, wenn ein zweiter Tab inzwischen eine Frist gesetzt hat.
		async confirmCloseRace() {
			const choice = await pulseConfirm({
				title: t('pulse', 'Close the quiz now?'),
				text: t('pulse', 'Nobody can answer any more, and the results are released right away: everyone sees the final standings and the solutions. This cannot be undone. To check answers first, stop without releasing.') + this.pendingNote,
				confirmLabel: t('pulse', 'Close and release'),
				cancelLabel: t('pulse', 'Cancel'),
				altLabel: t('pulse', 'Stop without releasing'),
				danger: true,
			})
			if (choice === 'alt') return this.stopWithoutRelease()
			if (choice === true) return this.closeQuiz(true)
		},
		// Mit Frist bleibt nach dem Schließen alles verborgen — umkehrbar. Das
		// verspricht die Bestätigung, und `release: false` hält es auch dann, wenn
		// ein zweiter Tab das Ende inzwischen auf „When I close it“ gestellt hat
		// (ohne den Parameter gäbe „close“ dann sofort frei).
		async confirmCloseTimed() {
			if (!await pulseConfirm({
				title: t('pulse', 'Close the quiz now?'),
				text: t('pulse', 'Nobody can answer any more. The results stay hidden until you release them — until then you can reopen the quiz.') + this.pendingNote,
				confirmLabel: t('pulse', 'Close quiz'),
				cancelLabel: t('pulse', 'Cancel'),
			})) return
			return this.closeQuiz(false)
		},
		// `release` sagt dem Server ausdrücklich, was die Bestätigung versprochen
		// hat — nie „je nach Frist“ (PaceService::closeWindow).
		closeQuiz(release) {
			return this.act(() => this.post({ action: 'close', release }), t('pulse', 'Could not close the quiz.'))
		},
		/*
		 * Stoppen ohne Freigabe — EIN Aufruf: close {release: false}. Der Raum
		 * steht dann auf „closed“ ohne releasedAt, die Frist bleibt 0: bewerten,
		 * freigeben, wieder öffnen („Reopen …“ belegt „When I close it“ vor) oder
		 * zurücksetzen.
		 */
		stopWithoutRelease() {
			return this.closeQuiz(false)
		},
		async confirmRelease() {
			if (!await pulseConfirm({
				title: t('pulse', 'Release the results?'),
				text: t('pulse', 'Everyone who took part sees their place, the final standings and the solutions. The quiz cannot be reopened after that.') + this.pendingNote,
				confirmLabel: t('pulse', 'Release results'),
				cancelLabel: t('pulse', 'Cancel'),
				danger: true,
			})) return
			return this.act(() => this.post({ action: 'release' }), t('pulse', 'Could not release the results.'))
		},
		// Freigeben aus dem offenen Fenster (Hausaufgabe) schließt zugleich —
		// wer noch nicht fertig ist, wird dabei gestoppt.
		async confirmReleaseOpen() {
			const nf = this.players.filter((p) => !p.finished).length
			const text = nf === 0
				? t('pulse', 'The quiz closes now. Everyone sees their place, the final standings and the solutions. This cannot be undone.')
				: n('pulse', 'The quiz closes now and %n person who has not finished is stopped. Everyone sees their place, the final standings and the solutions. This cannot be undone.', 'The quiz closes now and %n people who have not finished are stopped. Everyone sees their place, the final standings and the solutions. This cannot be undone.', nf)
			if (!await pulseConfirm({
				title: t('pulse', 'Close and release now?'),
				text: text + this.pendingNote,
				confirmLabel: t('pulse', 'Close and release'),
				cancelLabel: t('pulse', 'Cancel'),
				danger: true,
			})) return
			return this.act(() => this.post({ action: 'release' }), t('pulse', 'Could not release the results.'))
		},
		// Beitritt sperren: wer schon drin ist, kommt weiter herein.
		toggleJoinsLocked() {
			const on = !this.room.joinsLocked
			return this.act(() => this.post({ action: on ? 'lockJoins' : 'unlockJoins' }), t('pulse', 'Could not change joining.'))
		},
		toggleScores() {
			this.showScores = !this.showScores
			this.refresh()
		},
		openBeamer() {
			window.open(window.location.origin + generateUrl('/apps/pulse/screen/' + this.room.code), 'pulse-beamer-' + this.room.code)
		},

		// ── Person entfernen ────────────────────────────────────────────────
		rowMenu(p) {
			return [{ key: 'remove', label: t('pulse', 'Remove from the quiz'), icon: 'trash', danger: true, act: () => this.confirmRemove(p) }]
		},
		// Der Name wird frei; wer das Gerät verloren hat, fängt neu an.
		async confirmRemove(p) {
			if (!await pulseConfirm({
				title: t('pulse', 'Remove {name}?', { name: p.nickname }),
				text: t('pulse', 'The answers and progress of {name} are deleted and the name becomes free. Use this if someone lost their device: they can join again with the same name, but start from question 1 — unless joining is locked.', { name: p.nickname }),
				confirmLabel: t('pulse', 'Remove person'),
				cancelLabel: t('pulse', 'Cancel'),
				danger: true,
			})) return
			return this.act(() => this.post({ action: 'removePlayer', playerId: p.id }), t('pulse', 'Could not remove the person.'))
		},

		// ── Ende ändern / wieder öffnen ─────────────────────────────────────
		openExtend() {
			this.extendOpen = true
		},
		// Der Menüeintrag, der den Dialog geöffnet hat, gibt es danach nicht
		// mehr — der Fokus geht an den Menü-Auslöser.
		closeExtend() {
			this.extendOpen = false
			this.$nextTick(() => {
				if (this.$refs.menu) this.$refs.menu.focusTrigger()
			})
		},
		onExtendDone(data) {
			this.$emit('room', data)
			this.closeExtend()
			this.refresh()
		},
		onExtendConflict(message) {
			showError(message)
			this.closeExtend()
			this.$emit('reload')
		},

		// ── Freitexte bewerten ──────────────────────────────────────────────
		questionOf(pollId) {
			const q = this.questions.find((x) => x.pollId === pollId)
			return q ? q.question : ''
		},
		// Je Frage (Deck-Reihenfolge) die Form, die TextGrading kennt.
		groupAnswers(rows) {
			const byPoll = new Map()
			for (const a of rows) {
				if (!byPoll.has(a.pollId)) byPoll.set(a.pollId, { pollId: a.pollId, k: a.k, question: this.questionOf(a.pollId), rows: [] })
				byPoll.get(a.pollId).rows.push(a)
			}
			return Array.from(byPoll.values()).sort((x, y) => x.k - y.k).map((g) => ({
				...g,
				results: {
					answers: g.rows.map((a) => ({ norm: a.pollId + ':' + a.answer, sample: a.answer, count: a.count, status: a.status })),
					total: sumCount(g.rows),
					accepted: [],
				},
			}))
		},
		/*
		 * Bewerten und Umbewerten — derselbe Endpunkt wie in der
		 * Zusammenfassung; er wertet alle gleichen Antworten neu. Die Antwort
		 * enthält die akzeptierten Antworten, also die Lösung, und wird bewusst
		 * verworfen.
		 */
		async grade(group, sample, ok) {
			if (this.gradingBusy) return
			this.gradingBusy = true
			try {
				await axios.post(generateUrl('/apps/pulse/api/1.0/rooms/' + this.room.code + '/polls/' + group.pollId + '/grade'), { answer: sample, correct: ok }, { timeout: TIMEOUT })
				const i = this.checked.findIndex((c) => c.pollId === group.pollId && c.answer === sample)
				if (i >= 0) {
					this.$set(this.checked, i, { ...this.checked[i], ok })
				} else {
					const row = group.rows.find((a) => a.answer === sample)
					this.checked.unshift({ pollId: group.pollId, k: group.k, question: group.question, answer: sample, count: row ? row.count : 1, ok })
				}
				this.refresh()
			} catch (e) {
				this.fail(e, t('pulse', 'Could not grade.'))
			} finally {
				this.gradingBusy = false
			}
			// Der bewertete Eintrag ist weg und mit ihm der Fokus: weiter zum
			// nächsten offenen, sonst an die Überschrift der Bewertung.
			this.$nextTick(() => {
				if (document.activeElement && document.activeElement !== document.body) return
				const next = this.$refs.grading && this.$refs.grading.querySelector(':scope > .pace-grade-q .tg-ok:not(:disabled)')
				if (next) next.focus()
				else if (this.$refs.gradeHead) this.$refs.gradeHead.focus()
			})
		},
		// Hauptaktion „Check %n answers": zur Bewertung, Fokus auf ihre Überschrift.
		focusGrading() {
			const card = this.$refs.grading
			if (!card) return
			card.scrollIntoView({ block: 'nearest' })
			if (this.$refs.gradeHead) this.$refs.gradeHead.focus({ preventScroll: true })
		},
	},
}
</script>

<style scoped>
/* Rahmen wie .mod-live: Kopf, Körper (einzige schrumpfende Zeile), Leiste. */
.pace-run { position: relative; height: 100%; display: grid; grid-template-rows: auto minmax(0, 1fr) auto; background: var(--pulse-bg); color: var(--pulse-text); }

/* Kopf */
.pace-top { min-height: 64px; box-sizing: border-box; padding: 10px 20px 8px; display: flex; flex-direction: column; justify-content: center; gap: 4px; border-block-end: 1px solid var(--pulse-border); }
.pace-top-row { display: flex; align-items: center; gap: 8px 12px; flex-wrap: wrap; }
.pace-title { margin: 0; min-width: 0; font-size: 18px; font-weight: 800; line-height: 1.25; overflow-wrap: anywhere; }
.pace-title.is-untitled { color: var(--pulse-text-2); }
.pace-title:focus-visible { outline: 2px solid var(--pulse-primary); outline-offset: 2px; }
.pace-top-row .pulse-chip { flex: 0 0 auto; font-variant-numeric: tabular-nums; }
.pace-spacer { flex: 1 1 auto; }
.pace-code { font-size: 18px; font-weight: 800; letter-spacing: 0.08em; font-variant-numeric: tabular-nums; white-space: nowrap; }
.pace-facts { margin: 0; font-size: var(--t-sm); color: var(--pulse-text-2); }
.pace-offline { margin: 4px 0 0; }

/* Körper: Personen | Seitenspalte; jede Spalte scrollt in sich. */
.pace-body { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 24px; min-height: 0; padding: 16px 20px; }
.pace-people, .pace-side { min-width: 0; min-height: 0; }

.pace-people { display: flex; flex-direction: column; gap: 6px; padding: 14px; border: 1px solid var(--pulse-border-strong); border-radius: var(--pulse-r-card); background: var(--pulse-hover); }
.pace-private-head { display: flex; align-items: center; gap: 8px; margin: 0; font-size: 15px; font-weight: 800; }
.pace-private-sub { margin: 0; font-size: 12px; color: var(--pulse-text-2); }
.pace-tools { display: flex; align-items: center; justify-content: space-between; gap: 8px 16px; flex-wrap: wrap; margin: 6px 0 2px; }
.pace-count { font-size: var(--t-sm); font-weight: 700; font-variant-numeric: tabular-nums; }
.pace-sort { display: inline-flex; align-items: center; gap: 8px; }
.pace-sort-label { font-size: var(--t-cap); font-weight: 700; color: var(--pulse-text-2); }
.pace-sort :deep(.pseg) { padding: 3px; }
.pace-sort :deep(.pseg-item) { min-height: 32px; padding: 0 10px; font-size: var(--t-cap); }
.pace-table-wrap { flex: 1 1 auto; min-height: 0; overflow: auto; }
.pace-empty { margin: 8px 0 0; color: var(--pulse-text-2); }

/* Tabelle: Zahlen rechtsbündig in Tabellenziffern, Kopf bleibt beim Scrollen stehen. */
.pace-table { width: 100%; border-collapse: collapse; font-size: var(--t-sm); font-variant-numeric: tabular-nums; }
.pace-table th {
	position: sticky;
	top: 0;
	z-index: 1;
	padding: 8px;
	background: var(--pulse-hover);
	border-block-end: 1px solid var(--pulse-border-strong);
	color: var(--pulse-text-2);
	font-size: var(--t-cap);
	font-weight: 700;
	text-align: start;
	vertical-align: bottom;
	line-height: 1.25;
	/* Nextcloud setzt Tabellenzellen global auf nowrap — Köpfe dürfen umbrechen. */
	white-space: normal;
}
/* Lange deutsche Einzelwörter („Beantwortet", „Übersprungen") dürfen trennen,
   sonst verbreitern sie die Tabelle bei 1024 px über die Spalte hinaus. Nur
   dort: überall sonst trennte der Browser auch „Rich-tig", obwohl Platz wäre. */
.pace-table th.is-long { -webkit-hyphens: auto; hyphens: auto; }
.pace-table td { padding: 8px; border-block-end: 1px solid var(--pulse-border); vertical-align: middle; white-space: normal; }
.pace-table tbody tr:last-child td { border-block-end: 0; }
.pace-table .is-num { text-align: end; }
/* Zeilenmenü: so schmal wie sein Knopf, am rechten Rand. */
.pace-table .pace-act { width: 1%; padding-block: 4px; padding-inline: 4px 0; text-align: end; }
.pace-name { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 6px; min-width: 5em; }
.pace-nick { font-weight: 700; overflow-wrap: anywhere; }
.pace-pos { font-weight: 700; white-space: nowrap; }
.pace-cell-chip { padding: 4px 8px; font-size: 12px; white-space: nowrap; vertical-align: middle; }
.pace-muted { color: var(--pulse-text-2); }
/* Nur für Screenreader (Tabellen-Beschriftung, Kopf der Zeilenmenüs). */
.pace-sr { position: absolute !important; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }

/* Seitenspalte */
.pace-side { display: flex; flex-direction: column; gap: 16px; overflow-y: auto; }
.pace-card { padding: 14px; border: 1px solid var(--pulse-border); border-radius: var(--pulse-r-card); background: var(--pulse-bg); }
.pace-side-head { margin: 0 0 10px; font-size: 15px; font-weight: 800; }
.pace-join-in { display: flex; align-items: center; gap: 16px; margin-block-end: 10px; }
/* QR bewusst fix auf Weiß (scanbar auch im dunklen Thema) — wie .qr-card im Deck. */
.pace-qr { flex: 0 0 104px; width: 104px; box-sizing: border-box; padding: 6px; background: #fff; border: 1px solid var(--pulse-border); border-radius: var(--pulse-r-el); }
.pace-join-code { min-width: 0; font-size: 30px; font-weight: 800; letter-spacing: 0.08em; line-height: 1.05; font-variant-numeric: tabular-nums; }
.pace-join-link { display: flex; align-items: flex-start; gap: 8px; }
.pace-join-url { flex: 1; min-width: 0; font-size: var(--t-sm); font-weight: 600; color: var(--pulse-primary); word-break: break-all; text-decoration: none; }
.pace-join-url:hover { text-decoration: underline; }

/* Bewertung: je Frage eine Zwischenzeile, darunter TextGrading (ohne Schlüssel). */
.pace-grading { font-size: var(--t-sm); }
.pace-grading .pace-side-head:focus-visible { outline: 2px solid var(--pulse-primary); outline-offset: 2px; }
.pace-grade-warn { margin: 0 0 10px; }
.pace-grade-q + .pace-grade-q { margin-block-start: 14px; }
.pace-grade-qhead { display: flex; gap: 6px; min-width: 0; margin: 0 0 6px; font-size: var(--t-sm); }
.pace-grade-k { flex: 0 0 auto; font-weight: 800; }
.pace-grade-qtext { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--pulse-text-2); }
/* Im schmalen Kasten: der Bedienhinweis nur einmal, die Summenzeile gar nicht
   (die Überschrift zählt schon). */
.pace-grading :deep(.tg-total) { display: none; }
.pace-grade-q + .pace-grade-q :deep(.tg-hint), .pace-checked :deep(.tg-hint) { display: none; }
.pace-checked { margin-block-start: 14px; padding-block-start: 8px; border-block-start: 1px solid var(--pulse-border); }
.pace-checked > summary { padding: 6px 0; font-weight: 700; color: var(--pulse-text-2); cursor: pointer; }
.pace-checked > summary:focus-visible { outline: 2px solid var(--pulse-primary); outline-offset: 2px; }
.pace-checked[open] > summary { margin-block-end: 8px; }

/* Beamer-Vorschau: volle Spaltenbreite, 16:9. */
.pace-preview-open { margin-block-start: 10px; }

.pace-qlist { list-style: none; margin: 0; padding: 0; }
.pace-q { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-block-end: 1px solid var(--pulse-border); }
.pace-q:last-child { border-block-end: 0; padding-block-end: 0; }
.pace-q:first-child { padding-block-start: 0; }
.pace-qk { flex: 0 0 auto; min-width: 1.6em; font-weight: 800; color: var(--pulse-text-2); font-variant-numeric: tabular-nums; }
.pace-qbody { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.pace-qtext { font-size: var(--t-sm); font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pace-qstats { font-size: var(--t-cap); color: var(--pulse-text-2); font-variant-numeric: tabular-nums; }
.pace-qstats b { color: var(--pulse-text); }
.pace-qstat { white-space: nowrap; }

/* Leiste: eine Hauptaktion, daneben was gerade gilt, rechts der Zähler. */
.pace-bar { display: flex; align-items: center; gap: 12px 16px; flex-wrap: wrap; min-height: 96px; box-sizing: border-box; padding: 12px 20px; border-block-start: 1px solid var(--pulse-border); }
.pace-primary { min-width: 220px; }
.pace-status { flex: 0 1 auto; min-width: 0; max-width: 62ch; margin: 0; font-size: var(--t-sm); color: var(--pulse-text-2); }
.pace-done { flex: 0 0 auto; font-size: var(--t-sm); font-variant-numeric: tabular-nums; }

/* Bis 1200 px: Tabelle neben der 360-px-Spalte enger setzen, damit sie ohne
   waagerechtes Scrollen passt (1024 × 768) — auch mit Zeilenmenü und drei
   Chip-Spalten („to check", „Finished", „online"). */
@media (max-width: 1199px) {
	.pace-table th, .pace-table td { padding: 7px 4px; }
	.pace-table .pace-act { padding-block: 4px; padding-inline: 0; }
	.pace-cell-chip { padding-inline: 6px; }
}

/* Unter 1000 px untereinander: der Körper scrollt als Ganzes, die Leiste bleibt unten. */
@media (max-width: 999px) {
	.pace-body { grid-template-columns: minmax(0, 1fr); align-content: start; overflow-y: auto; }
	.pace-side { overflow: visible; }
	.pace-table-wrap { overflow-y: visible; overflow-x: auto; }
}
</style>
