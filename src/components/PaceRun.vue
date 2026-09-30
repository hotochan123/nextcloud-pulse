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
			<!-- The settings fixed on opening stay visible — as on the phone. -->
			<p class="pace-facts">{{ facts }}</p>
			<p v-if="!online" class="pulse-notice is-warn pace-offline" role="status"><PulseIcon name="warning" size="1.1em" class="pulse-notice-ico" /><span>{{ t('pulse', 'No connection — the room is not answering. The main action stays disabled until it is back.') }}</span></p>
		</header>

		<div class="pace-body">
			<!-- Private: names, progress and points per person. What gets projected
			     is the projector window, never this page. -->
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
									<!-- When space gets tight, the chip slides below the name instead of widening the table. -->
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
								<!-- Exactly one entry, and it is red: whoever lost their device
								     or is squatting a name gets removed here. -->
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
				<!-- Right after opening, sharing the link is the main task
				     (homework: into the class chat). Only while open. -->
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

				<!-- Open free texts count 0 points until they are graded — hence
				     at the top and ahead of the release as the main action. Without the answer key
				     (keyless): the laptop is often connected to the projector. -->
				<section v-if="pendingAnswers.length || checked.length" ref="grading" class="pace-card pace-grading" aria-labelledby="pace-grade-head">
					<!-- Stays in place as long as "Checked just now" has something — then without
					     "0 … waiting". The warning after the release only as long as there is something
					     to grade here (open, or the list for re-grading expanded). -->
					<h3 id="pace-grade-head" ref="gradeHead" class="pace-side-head" tabindex="-1">{{ pendingSum ? n('pulse', '%n free text waiting', '%n free texts waiting', pendingSum) : t('pulse', 'All free texts checked') }}</h3>
					<p v-if="state === 'released' && (pendingSum > 0 || checkedOpen)" class="pulse-notice is-warn pace-grade-warn"><PulseIcon name="warning" size="1.1em" class="pulse-notice-ico" /><span>{{ t('pulse', 'Standings update for everyone.') }}</span></p>
					<div v-for="g in pendingGroups" :key="g.pollId" class="pace-grade-q">
						<p class="pace-grade-qhead"><span class="pace-grade-k">{{ t('pulse', 'Question {number}', { number: g.k }) }}</span> <span class="pace-grade-qtext" :title="g.question">{{ g.question }}</span></p>
						<TextGrading keyless :busy="gradingBusy" :results="g.results" @grade="(sample, ok) => grade(g, sample, ok)" />
					</div>
					<!-- Undo without a detour through the summary: the same
					     endpoint re-grades. This session only. -->
					<details v-if="checked.length" class="pace-checked" @toggle="checkedOpen = $event.target.open">
						<summary>{{ t('pulse', 'Checked just now ({count})', { count: checked.length }) }}</summary>
						<div v-for="g in checkedGroups" :key="g.pollId" class="pace-grade-q">
							<p class="pace-grade-qhead"><span class="pace-grade-k">{{ t('pulse', 'Question {number}', { number: g.k }) }}</span> <span class="pace-grade-qtext" :title="g.question">{{ g.question }}</span></p>
							<TextGrading keyless :busy="gradingBusy" :results="g.results" @grade="(sample, ok) => grade(g, sample, ok)" />
						</div>
					</details>
				</section>

				<!-- What the room sees — the real projector page, scaled down. -->
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

		<!-- Exactly ONE filled main action, and it knows what is due right now.
		     Never red: the confirmation carries the weight. -->
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

		<!-- "Change the end …" / "Reopen …": the same dialog as when opening,
		     only with the question about the end. -->
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
 * Self-paced run view (spec §1.6, not in the public repository) — the
 * moderator's 'pace' phase once the quiz is open.
 *
 * Nothing depends on this tab: deadline expiry, verdicts and the phones'
 * progress run on the server. The view is display plus buttons — a
 * table per person (private, with "Remove from the quiz"), the open free
 * texts for grading, the projector preview, the questions with their
 * numbers, joining for sharing, and at the bottom exactly one main action
 * (close, grade, release, CSV).
 *
 * Its own /progress loop (progress-poll: at most one request in flight,
 * stale responses are discarded). After each of our own actions refresh().
 *
 * The window (`room.window`) is the truth for state and buttons: the
 * moderator keeps it current via `window` (from /progress) and `room`
 * (the response of every /pace call).
 *
 * Emits: room(json) · window(win) · skew(s) · go('deck'|'summary'|'home') ·
 * reset(counts) · end-practice · copy · reload
 */
import axios from '@nextcloud/axios'
import { MOD_TIMEOUT, roomApi, screenPage, openProjector } from '../util/routes.js'
import { t, n } from '../util/l10n.js'
import { showError, serverMessage } from '../toast.js'
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

const sumCount = (rows) => rows.reduce((acc, a) => acc + (Number(a.count) || 0), 0)

export default {
	name: 'PaceRun',
	components: { PaceOpenDialog, QrCode, ScreenPreview, TextGrading, PulseIcon, PulseMenu, PulseSegmented },
	mixins: [progressPoll],
	props: {
		room: { type: Object, required: true },
		joinUrlFull: { type: String, default: '' },
		joinPath: { type: String, default: '' },
		// Arrived here by a click (Moderator.enterPace): focus on the title.
		focusTitle: { type: Boolean, default: false },
	},
	data() {
		return {
			nowSec: Math.floor(Date.now() / 1000), // own one-second tick (deadline, "3 min ago")
			busy: false,       // a /pace call is running
			showScores: false, // "Show points": points despite "results at the end" (?scores=1)
			sortPick: '',      // own sort choice until leaving; empty = default
			extendOpen: false, // dialog "Change the end …" / "Reopen …"
			gradingBusy: false, // a grading request is running
			// "Checked just now" — this session only: {pollId, k, question, answer, count, ok}
			checked: [],
			checkedOpen: false, // "Checked just now" expanded
		}
	},
	computed: {
		// Window from the room JSON (kept fresh), failing that from /progress.
		win() {
			return this.room.window || (this.progress && this.progress.window) || null
		},
		// "Now" in server time — never the bare laptop clock against server timestamps.
		nowServer() {
			return this.nowSec + this.serverSkew
		},
		// A deadline that expired locally already counts as closed; the next fetch confirms it.
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
			return screenPage(this.room.code)
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
		// Started and not finished yet.
		workingCount() {
			return this.players.filter((p) => p.started && !p.finished).length
		},
		scoresHidden() {
			return !!(this.progress && this.progress.scoresHidden)
		},
		// The toggle hangs on the setting, not on `scoresHidden` — otherwise
		// it would vanish the moment you switch it on.
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
		// Default: released with visible points by points, otherwise whoever is
		// furthest along. A choice of one's own applies as long as it still exists.
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
			// Furthest first: finished, then by question descending, then name.
			return list.sort((a, b) => (Number(!!b.finished) - Number(!!a.finished)) || ((b.k || 0) - (a.k || 0)) || byName(a, b))
		},
		/*
		 * Open free texts from /progress (raw, never with the solution). Whatever was
		 * just graded here drops out immediately, not only with the next
		 * fetch: the server grades every later answer with the same
		 * normal form itself — such an entry is therefore always stale, and
		 * this way nobody taps the same answer twice.
		 */
		pendingAnswers() {
			const list = (this.progress && Array.isArray(this.progress.pendingAnswers)) ? this.progress.pendingAnswers : []
			return list.filter((a) => !this.checked.some((c) => c.pollId === a.pollId && c.answer === a.answer))
		},
		// Open free-text answers in total (sum of the groups).
		pendingSum() {
			return sumCount(this.pendingAnswers)
		},
		pendingGroups() {
			return this.groupAnswers(this.pendingAnswers.map((a) => ({ ...a, status: 'open' })))
		},
		checkedGroups() {
			return this.groupAnswers(this.checked.map((c) => ({ ...c, status: c.ok ? 'accepted' : 'rejected' })))
		},
		// The sentence appended to every close/release confirmation.
		pendingNote() {
			return this.pendingSum > 0
				? ' ' + n('pulse', '%n free-text answer is not checked yet — it counts as 0 points.', '%n free-text answers are not checked yet — they count as 0 points.', this.pendingSum)
				: ''
		},
		/*
		 * THE main action — the first matching row wins (§1.6). Open
		 * free texts after the close go before the release: they count 0
		 * points until they are graded, and a later grading would change
		 * final standings that have already been seen.
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
		 * "Everyone through" only counts whoever is here right now: whoever no longer
		 * polls does not hold up the race. But whoever is here and has not started yet
		 * is not through either — and without a present, finished person
		 * the sentence would otherwise show up in an empty room.
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
		 * "still working" only while open: `finished` means "reached the last
		 * question" (PaceService::isFinished) — whoever stopped earlier stays
		 * started and unfinished after the close, but can no longer do anything.
		 */
		doneText() {
			const base = t('pulse', '{done} of {total} finished', { done: this.finishedCount, total: this.players.length })
			return this.state === 'open' && this.workingCount > 0
				? base + ' · ' + n('pulse', '%n still working', '%n still working', this.workingCount)
				: base
		},
		// Counts for destructive confirmations (reset) — from our own fetch.
		counts() {
			return progressCounts(this.progress)
		},
		/*
		 * Overflow menu (table §1.6): toggles with a tick, then the end,
		 * the release where it is not already the main action, both CSVs, the
		 * ways out, red items last.
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
			// Open only with a deadline (without a deadline "Close quiz" already releases);
			// closed only if the main action is something else.
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
		// Outside of data(): the handle does not need to be reactive.
		this.tick = null
	},
	mounted() {
		this.tick = setInterval(() => { this.nowSec = Math.floor(Date.now() / 1000) }, 1000)
		// The trigger (open dialog, "Show progress", "Back to progress") disappeared with
		// the old view — without this the focus would fall to <body>.
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
			return roomApi(this.room.code, '/progress')
		},
		progressParams() {
			return this.showScores ? { scores: 1 } : {}
		},
		pollDelay() {
			if (document.hidden) return null // pause -> onPollVisibility wakes it up
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
		// 409: the room no longer runs self-paced -> reload.
		onNotPaced() {
			this.$emit('reload')
		},

		// ── Actions (/pace) ─────────────────────────────────────────────────
		async post(body) {
			const { data } = await axios.post(roomApi(this.room.code, '/pace'), body, { timeout: MOD_TIMEOUT })
			this.$emit('room', data)
			return data
		},
		/*
		 * Frame of every /pace call: busy on; success -> fetch again immediately;
		 * 400 -> server message, fetch again; 409 -> server message, reload the room
		 * (it is in a different state on the server than here); 404/403 -> like the fetch.
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
			showError(serverMessage(e, fallback))
			if (st === 409) {
				this.$emit('reload')
				return
			}
			this.refresh()
		},
		runPrimary() {
			if (this.primary && !this.busy) this.primary.act()
		},
		// Without a deadline, closing releases immediately — hence the third way out. `release: true`
		// keeps the promise even if a second tab has set a deadline in the meantime.
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
		// With a deadline everything stays hidden after closing — reversible. That
		// is what the confirmation promises, and `release: false` keeps it even if
		// a second tab has meanwhile set the end to "When I close it"
		// (without the parameter "close" would then release immediately).
		async confirmCloseTimed() {
			if (!await pulseConfirm({
				title: t('pulse', 'Close the quiz now?'),
				text: t('pulse', 'Nobody can answer any more. The results stay hidden until you release them — until then you can reopen the quiz.') + this.pendingNote,
				confirmLabel: t('pulse', 'Close quiz'),
				cancelLabel: t('pulse', 'Cancel'),
			})) return
			return this.closeQuiz(false)
		},
		// `release` tells the server explicitly what the confirmation promised
		// — never "depending on the deadline" (PaceService::closeWindow).
		closeQuiz(release) {
			return this.act(() => this.post({ action: 'close', release }), t('pulse', 'Could not close the quiz.'))
		},
		/*
		 * Stop without releasing — ONE call: close {release: false}. The room
		 * is then "closed" without releasedAt, the deadline stays 0: grade,
		 * release, reopen ("Reopen …" preselects "When I close it") or
		 * reset.
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
		// Releasing from the open window (homework) closes at the same time —
		// whoever has not finished yet is stopped in the process.
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
		// Lock joining: whoever is already in still gets in.
		toggleJoinsLocked() {
			const on = !this.room.joinsLocked
			return this.act(() => this.post({ action: on ? 'lockJoins' : 'unlockJoins' }), t('pulse', 'Could not change joining.'))
		},
		toggleScores() {
			this.showScores = !this.showScores
			this.refresh()
		},
		openBeamer() {
			openProjector(this.room.code)
		},

		// ── Removing a person ───────────────────────────────────────────────
		rowMenu(p) {
			return [{ key: 'remove', label: t('pulse', 'Remove from the quiz'), icon: 'trash', danger: true, act: () => this.confirmRemove(p) }]
		},
		// The name becomes free; whoever lost their device starts over.
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

		// ── Change the end / reopen ─────────────────────────────────────────
		openExtend() {
			this.extendOpen = true
		},
		// The menu entry that opened the dialog no longer exists
		// afterwards — the focus goes to the menu trigger.
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

		// ── Grading free texts ──────────────────────────────────────────────
		questionOf(pollId) {
			const q = this.questions.find((x) => x.pollId === pollId)
			return q ? q.question : ''
		},
		// Per question (deck order), the shape that TextGrading knows.
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
		 * Grading and re-grading — the same endpoint as in the
		 * summary; it re-grades all identical answers. The response
		 * contains the accepted answers, i.e. the solution, and is deliberately
		 * discarded.
		 */
		async grade(group, sample, ok) {
			if (this.gradingBusy) return
			this.gradingBusy = true
			try {
				await axios.post(roomApi(this.room.code, '/polls/' + group.pollId + '/grade'), { answer: sample, correct: ok }, { timeout: MOD_TIMEOUT })
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
			// The graded entry is gone and the focus with it: on to the
			// next open one, otherwise to the grading heading.
			this.$nextTick(() => {
				if (document.activeElement && document.activeElement !== document.body) return
				const next = this.$refs.grading && this.$refs.grading.querySelector(':scope > .pace-grade-q .tg-ok:not(:disabled)')
				if (next) next.focus()
				else if (this.$refs.gradeHead) this.$refs.gradeHead.focus()
			})
		},
		// Main action "Check %n answers": to the grading, focus on its heading.
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
/* Frame like .mod-live: header, body (the only shrinking row), bar. */
.pace-run { position: relative; height: 100%; display: grid; grid-template-rows: auto minmax(0, 1fr) auto; background: var(--pulse-bg); color: var(--pulse-text); }

/* Header */
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

/* Body: people | side column; each column scrolls on its own. */
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

/* Table: numbers right-aligned in tabular figures, header stays put while scrolling. */
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
	/* Nextcloud sets table cells to nowrap globally — headers may wrap. */
	white-space: normal;
}
/* Long single German words („Beantwortet" = Answered, „Übersprungen" = Skipped)
   may hyphenate, otherwise at 1024 px they widen the table beyond the column.
   Only there: everywhere else the browser would also split „Rich-tig" (Correct)
   even with room to spare. */
.pace-table th.is-long { -webkit-hyphens: auto; hyphens: auto; }
.pace-table td { padding: 8px; border-block-end: 1px solid var(--pulse-border); vertical-align: middle; white-space: normal; }
.pace-table tbody tr:last-child td { border-block-end: 0; }
.pace-table .is-num { text-align: end; }
/* Row menu: as narrow as its button, at the right edge. */
.pace-table .pace-act { width: 1%; padding-block: 4px; padding-inline: 4px 0; text-align: end; }
.pace-name { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 6px; min-width: 5em; }
.pace-nick { font-weight: 700; overflow-wrap: anywhere; }
.pace-pos { font-weight: 700; white-space: nowrap; }
.pace-cell-chip { padding: 4px 8px; font-size: 12px; white-space: nowrap; vertical-align: middle; }
.pace-muted { color: var(--pulse-text-2); }
/* Screen readers only (table caption, header of the row menus). */
.pace-sr { position: absolute !important; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }

/* Side column */
.pace-side { display: flex; flex-direction: column; gap: 16px; overflow-y: auto; }
.pace-card { padding: 14px; border: 1px solid var(--pulse-border); border-radius: var(--pulse-r-card); background: var(--pulse-bg); }
.pace-side-head { margin: 0 0 10px; font-size: 15px; font-weight: 800; }
.pace-join-in { display: flex; align-items: center; gap: 16px; margin-block-end: 10px; }
/* QR deliberately fixed on white (scannable in the dark theme too) — like .qr-card in the deck. */
.pace-qr { flex: 0 0 104px; width: 104px; box-sizing: border-box; padding: 6px; background: #fff; border: 1px solid var(--pulse-border); border-radius: var(--pulse-r-el); }
.pace-join-code { min-width: 0; font-size: 30px; font-weight: 800; letter-spacing: 0.08em; line-height: 1.05; font-variant-numeric: tabular-nums; }
.pace-join-link { display: flex; align-items: flex-start; gap: 8px; }
.pace-join-url { flex: 1; min-width: 0; font-size: var(--t-sm); font-weight: 600; color: var(--pulse-primary); word-break: break-all; text-decoration: none; }
.pace-join-url:hover { text-decoration: underline; }

/* Grading: one sub-heading row per question, TextGrading below it (without the key). */
.pace-grading { font-size: var(--t-sm); }
.pace-grading .pace-side-head:focus-visible { outline: 2px solid var(--pulse-primary); outline-offset: 2px; }
.pace-grade-warn { margin: 0 0 10px; }
.pace-grade-q + .pace-grade-q { margin-block-start: 14px; }
.pace-grade-qhead { display: flex; gap: 6px; min-width: 0; margin: 0 0 6px; font-size: var(--t-sm); }
.pace-grade-k { flex: 0 0 auto; font-weight: 800; }
.pace-grade-qtext { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--pulse-text-2); }
/* In the narrow box: the usage hint only once, the total line not at all
   (the heading already counts). */
.pace-grading :deep(.tg-total) { display: none; }
.pace-grade-q + .pace-grade-q :deep(.tg-hint), .pace-checked :deep(.tg-hint) { display: none; }
.pace-checked { margin-block-start: 14px; padding-block-start: 8px; border-block-start: 1px solid var(--pulse-border); }
.pace-checked > summary { padding: 6px 0; font-weight: 700; color: var(--pulse-text-2); cursor: pointer; }
.pace-checked > summary:focus-visible { outline: 2px solid var(--pulse-primary); outline-offset: 2px; }
.pace-checked[open] > summary { margin-block-end: 8px; }

/* Projector preview: full column width, 16:9. */
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

/* Bar: one main action, next to it what applies right now, the counter on the right. */
.pace-bar { display: flex; align-items: center; gap: 12px 16px; flex-wrap: wrap; min-height: 96px; box-sizing: border-box; padding: 12px 20px; border-block-start: 1px solid var(--pulse-border); }
.pace-primary { min-width: 220px; }
.pace-status { flex: 0 1 auto; min-width: 0; max-width: 62ch; margin: 0; font-size: var(--t-sm); color: var(--pulse-text-2); }
.pace-done { flex: 0 0 auto; font-size: var(--t-sm); font-variant-numeric: tabular-nums; }

/* Up to 1200 px: set the table tighter next to the 360 px column, so that it fits
   without horizontal scrolling (1024 × 768) — even with the row menu and three
   chip columns ("to check", "Finished", "online"). */
@media (max-width: 1199px) {
	.pace-table th, .pace-table td { padding: 7px 4px; }
	.pace-table .pace-act { padding-block: 4px; padding-inline: 0; }
	.pace-cell-chip { padding-inline: 6px; }
}

/* Below 1000 px stacked: the body scrolls as a whole, the bar stays at the bottom. */
@media (max-width: 999px) {
	.pace-body { grid-template-columns: minmax(0, 1fr); align-content: start; overflow-y: auto; }
	.pace-side { overflow: visible; }
	.pace-table-wrap { overflow-y: visible; overflow-x: auto; }
}
</style>
