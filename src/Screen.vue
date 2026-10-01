<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="scr" :class="{ 'is-live': isLive, 'is-race': raceView }" :data-image="hasImage ? '' : null">
		<!-- Unknown room (§4.11) — branded, a clear message -->
		<div v-if="!roomExists" class="scr-center">
			<p class="scr-eyebrow">Pulse</p>
			<h1>{{ t('pulse', 'This room does not exist.') }}</h1>
			<p class="scr-muted">{{ t('pulse', 'Check the code and open the projector link again.') }}</p>
		</div>

		<!-- Self-paced (§3): race, closed, final standings — before the lobby.
		     Draft and "open, but nobody has started yet" fall through to the lobby.
		     Never question text, options or distributions (the payload has none). -->
		<template v-else-if="raceView">
			<header v-if="raceHead" class="scr-head">
				<h1 class="scr-q">{{ raceHead }}</h1>
			</header>
			<main class="scr-stage" ref="stage" :style="stageStyle">
				<StageRace v-if="paceState === 'open'" ref="race" :race="race" :board="raceBoard" :side="raceSide"
					:join-url="joinUrl" :join-url-full="joinUrlFull" :code="spacedCode" :tight="raceTight"
					:board-rows="raceBoardRows" />
				<!-- Released: the same final standings as at the end of a moderated quiz. -->
				<div v-else-if="raceFinal" class="scr-final">
					<p class="scr-final-title">{{ t('pulse', 'Final standings') }}</p>
					<Leaderboard class="scr-final-lb" :rows="leaderboard" :total="leaderboardTotal" :podium="true" :limit="5" split />
				</div>
				<!-- Closed: only the counter — even if the server sends a leaderboard along
				     with feedback after each question; when closing, the teacher confirmed
				     "hidden until release". -->
				<div v-else class="scr-race-note" role="status">
					<p class="scr-race-note-big">{{ raceNote[0] }}</p>
					<p class="scr-race-note-sub">{{ raceNote[1] }}</p>
				</div>
			</main>
			<div class="scr-meta">
				<div class="scr-meta-l">
					<span v-if="practice" class="scr-chip is-practice"><PulseIcon name="warning" size="1em" /> {{ t('pulse', 'Practice run') }}</span>
					<span class="scr-chip" :class="paceState === 'open' ? 'is-open' : 'is-done'">
						<span v-if="paceState === 'open'" class="scr-chip-dot" aria-hidden="true" />
						{{ raceChip }}
					</span>
					<span v-if="present > 0" class="scr-chip">{{ t('pulse', '{count} here', { count: present }) }}</span>
					<span v-if="!online" class="scr-chip is-off">{{ t('pulse', 'Connection lost …') }}</span>
				</div>
				<span class="scr-legend">{{ paceState === 'open' ? t('pulse', '100% = everyone who joined') : '' }}</span>
				<div v-if="paceState === 'open'" class="scr-join-mini" :title="joinUrl">
					<div class="scr-qr-mini"><QrCode :value="joinUrlFull" /></div>
					<span class="scr-pin-mini">{{ spacedCode }}</span>
				</div>
			</div>
		</template>

		<!-- Lobby: no active question yet -->
		<div v-else-if="!poll" class="scr-lobby" :class="{ 'is-paced': isPaced }">
			<p class="scr-eyebrow">Pulse</p>
			<h1 class="scr-lobby-title">{{ lobbyTitle }}</h1>
			<p v-if="lobbySub" class="scr-lobby-sub">{{ lobbySub }}</p>
			<p v-if="practice" class="scr-practice"><PulseIcon name="warning" size="1em" /> {{ t('pulse', 'Practice run') }}</p>
			<div class="scr-join">
				<div class="scr-qr"><QrCode :value="joinUrlFull" /></div>
				<div class="scr-join-txt">
					<p class="scr-join-hint">{{ t('pulse', 'Join in — point your phone camera at the code, or:') }}</p>
					<p class="scr-pin">{{ spacedCode }}</p>
					<p class="scr-join-url">{{ joinUrl }}</p>
				</div>
			</div>
			<p v-if="present > 0" class="scr-momentum" role="status">
				<span class="scr-momentum-dot" aria-hidden="true" />
				<b>{{ present }}</b> {{ n('pulse', 'person is already here', 'people are already here', present) }}
			</p>
			<!-- Self-paced, open with a deadline: in the counter's row, so that the
			     lobby does not grow past the edge even in the 1280×720 kiosk. -->
			<p v-if="lobbyWhen" class="scr-lobby-when"><PulseIcon name="calendar" size="1em" /> {{ lobbyWhen }}</p>
			<p v-if="!online" class="scr-conn" role="status">{{ t('pulse', 'Connection lost …') }}</p>
		</div>

		<!-- Active question: head · stage · meta bar (§2.1) -->
		<template v-else>
			<!-- At the end of the quiz the big screen belongs to the final standings: the
			     question of the last round would otherwise sit above it as a second heading (B2). -->
			<header v-if="!quizEnded && !hasImage" class="scr-head">
				<h1 class="scr-q">{{ poll.question }}</h1>
				<!-- Countdown on one axis with the question, not as a strip
				     above it (§6.5) — important, but not the question. -->
				<CountdownRing v-if="showCountdown" class="scr-cd" :remaining="remaining" :total="poll.timeLimit || 0" />
			</header>

			<main class="scr-stage" ref="stage" :style="stageStyle" :data-image="hasImage ? '' : null">
				<!-- Image question (§7.1): the image IS the content and gets a column of
				     its own; the head moves to the right column for it, so that question
				     and result are read together. Before, the image hung in the header with
				     3 % of the area and pushed the question out of the
				     centre. -->
				<template v-if="hasImage">
					<div class="scr-img-mat">
						<!-- The image arrives after the first paint and changes the stage height:
						     without this second pass the shrink loop measures against
						     an empty box (eight rows overflowed by 32 px). -->
						<img class="scr-img" :src="imageUrl(poll)" :alt="t('pulse', 'Image for the question')" @load="fitStage(true)">
					</div>
					<div class="scr-split-q">
						<h1 class="scr-q">{{ poll.question }}</h1>
						<CountdownRing v-if="showCountdown" class="scr-cd" :remaining="remaining" :total="poll.timeLimit || 0" />
					</div>
				</template>

				<!-- Final standings: a stage of its own, two columns (B1) -->
				<div v-if="quizEnded" class="scr-final">
					<p class="scr-final-title">{{ t('pulse', 'Final standings') }}</p>
					<Leaderboard class="scr-final-lb" :rows="leaderboard" :total="leaderboardTotal" :podium="true" :limit="5" split />
				</div>

				<!-- Word cloud: its own live engine (spiral layout) across the full stage.
				     It is the exception to E1 and keeps growing while open — the
				     growing IS the question type. -->
				<div v-else-if="showResults && results.type === 'words'" class="scr-cloud">
					<WordCloud :words="results.results" :total="results.total" :demo="demo"
						:distinct="results.resultsTotal || 0" :mentions="results.mentions || 0"
						:show-footer="false" :min-size="metaFs" @stats="cloudStats = $event" />
				</div>

				<!-- Revealed, but nobody answered (§7.9): no empty
				     chart. The stage shows what it was about and says so in words. -->
				<div v-else-if="emptyResult" class="scr-empty">
					<StageOpen v-if="!soloOpen" :poll="poll" />
					<p class="scr-empty-txt">{{ t('pulse', 'No answer received') }}</p>
				</div>

				<!-- Free text on the projector: frequency tiles (12× large … 1× small) -->
				<div v-else-if="showResults && results.type === 'text'" class="scr-ftcards">
					<div class="scr-ft-head">
						<span v-if="acceptedList.length" class="pulse-chip is-success scr-ft-accept"><PulseIcon name="check" size="1em" /> {{ acceptedList.join(' · ') }}</span>
						<span class="scr-ft-sum">{{ textSummary }}</span>
					</div>
					<div v-if="textCards.length" class="scr-ft-cloud">
						<span v-for="a in textCards" :key="a.norm" class="scr-ft-card" :class="'is-' + a.status" :style="{ fontSize: a.em + 'em' }">
							<span class="scr-ft-txt">{{ a.sample }}</span>
							<span class="scr-ft-mult">{{ a.count }}×</span>
						</span>
					</div>
					<p v-else class="scr-ft-empty">{{ t('pulse', 'No answers yet.') }}</p>
				</div>

				<!-- Reveal: result grammar (§2.4) -->
				<div v-else-if="showResults" class="scr-results" :class="{ 'scr-results--cols': resultCols }">
					<ResultsView :results="results" wide
						:correct-id="poll.correctOption || ''"
						:correct-ids="(poll.answerKey && poll.answerKey.correct) || []"
						:answer-key="poll.answerKey || null" />
				</div>

				<!-- Open stage (§6.1): answer options on the left, intake on the right.
				     If there is nothing to show — number guess, free text — the
				     intake fills the stage alone instead of leaving it empty as before. -->
				<!-- From six rows on the split flips: the answer options
				     get the full width, the intake becomes a flat strip
				     below. Side by side, 40 % of the screen stayed reserved for a
				     three-digit number while eight rows next to it fought for
				     every millimetre. -->
				<div v-else class="scr-open" :data-solo="soloOpen ? '' : null" :data-stack="stackOpen ? '' : null">
					<StageOpen v-if="!soloOpen" :poll="poll" />
					<IntakeBoard :key="poll.id" :answered="answered" :present="present"
						:frozen="timeUp || closedHidden" :compact="hasImage || stackOpen" />
				</div>
			</main>

			<!-- Meta bar: ONE place for secondary things (§2.5). The QR disappears
			     once the question is revealed — nobody joins any more then, and the top
			     right corner belongs to the content again. -->
			<div class="scr-meta">
				<div class="scr-meta-l">
					<span v-if="practice" class="scr-chip is-practice"><PulseIcon name="warning" size="1em" /> {{ t('pulse', 'Practice run') }}</span>
					<span class="scr-chip" :class="revealed ? 'is-done' : 'is-open'">
						<span v-if="!revealed && !timeUp && !closedHidden" class="scr-chip-dot" aria-hidden="true" />
						{{ stateText }}
					</span>
					<span v-if="allAnswered" class="scr-chip is-full">{{ t('pulse', 'complete') }}</span>
					<span v-if="!online" class="scr-chip is-off">{{ t('pulse', 'Connection lost …') }}</span>
				</div>
				<span class="scr-legend">{{ legend }}</span>
				<div v-if="showQr" class="scr-join-mini" :title="joinUrl">
					<div class="scr-qr-mini"><QrCode :value="joinUrlFull" /></div>
					<span class="scr-pin-mini">{{ spacedCode }}</span>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
/*
 * Section references (§…) and review IDs (B1, R3, …) point to the design notes
 * of the redesign and of the self-paced quiz, which are not in the public
 * repository (see "References in code comments" in the README).
 */
import axios from '@nextcloud/axios'
import { PHONE_TIMEOUT, publicApi, pollImage, participantPage } from './util/routes.js'
import { loadState } from '@nextcloud/initial-state'
import { formatCode, remainingSecs, fmtDeadline } from './util/format.js'
import { windowState, trimRaceBoard, RACE_BOARD_ROWS } from './util/pace.js'
import { reloadOnProtocolMismatch } from './util/protocol.js'
import pollingMixin from './mixins/polling.js'
import PulseIcon from './components/ui/PulseIcon.vue'
import ResultsView from './components/ResultsView.vue'
import CountdownRing from './components/CountdownRing.vue'
import IntakeBoard from './components/IntakeBoard.vue'
import StageOpen from './components/StageOpen.vue'
import Leaderboard from './components/Leaderboard.vue'
import WordCloud from './components/WordCloud.vue'
import QrCode from './components/QrCode.vue'
import StageRace from './components/StageRace.vue'

// Floor of the shrink loop (§2.3): below it the stage is only readable from 5 m —
// then there is too much content on it.
const FIT_FLOOR_PX = 24

export default {
	name: 'Screen',
	mixins: [pollingMixin],
	components: { PulseIcon, ResultsView, CountdownRing, IntakeBoard, StageOpen, Leaderboard, WordCloud, QrCode, StageRace },
	data() {
		return {
			code: loadState('pulse', 'code', ''),
			roomExists: loadState('pulse', 'roomExists', false),
			practice: false,
			roomTitle: '',   // room name from publicState.room (empty = none)
			poll: null,
			results: null,
			cloudStats: null,
			stageFs: null,   // set by fitStage() when it has to shrink
			metaFs: 23,      // measured class C — lower bound of the word cloud (§7.7)
			answered: 0,
			present: 0,
			leaderboard: null,
			// The final standings arrive as the top 10 (lib/Service/PublicPayload.php);
			// this is how many there are in all.
			leaderboardTotal: 0,
			online: true,
			version: '',
			serverSkew: 0,
			nowSec: Math.floor(Date.now() / 1000),
			tickTimer: null,
			// Self-paced (§3.1): from /state?spectate=1 — empty/null when moderated.
			pace: '',
			paceWindow: null,
			race: null,
			// Tight race rows: fitPass sets it when the race does not fit at the
			// shrink limit (small kiosk, embed frame).
			raceTight: false,
			// Leaderboard rows beside the race: fitTight takes rows away when
			// tight rows are not enough either (embed frame).
			raceBoardRows: RACE_BOARD_ROWS,
		}
	},
	computed: {
		// ── Self-paced (§3) ────────────────────────────────────────────
		// When moderated, /state has neither room.pace nor window — every branch here
		// depends on isPaced; moderated everything stays as before.
		isPaced() {
			return this.pace === 'self'
		},
		// A deadline that has expired locally already counts as closed; the next
		// fetch confirms it.
		paceState() {
			return this.isPaced ? windowState(this.paceWindow, this.nowSec + this.serverSkew) : ''
		},
		// Race frame: closed, released or open with at least one
		// start. Draft and "open without starters" show the lobby (joining).
		raceView() {
			if (!this.isPaced || !this.roomExists) return false
			const st = this.paceState
			return st === 'closed' || st === 'released' || (st === 'open' && !!this.race && this.race.started > 0)
		},
		// Top of the open race, only with points: eight alphabetical "0" rows
		// at the start would be noise and would expose latecomers.
		raceBoard() {
			if (this.paceState !== 'open') return []
			return (this.leaderboard || []).filter((r) => r.score > 0)
		},
		// Right column of the race: in the first two minutes (and as long as
		// nobody has points) joining, after that the top. Homework with
		// "Reveal at the end" never has a leaderboard — joining stays there.
		raceSide() {
			if (!this.raceView || this.paceState !== 'open') return ''
			const openedAt = (this.paceWindow && this.paceWindow.openedAt) || 0
			if (this.nowSec + this.serverSkew - openedAt < 120 || !this.raceBoard.length) return 'join'
			return 'board'
		},
		// Released with a leaderboard: the existing final-standings block.
		raceFinal() {
			return this.paceState === 'released' && Array.isArray(this.leaderboard) && this.leaderboard.length > 0
		},
		// Head (class A): open shows the counter, closed the message, released
		// none — there the big screen belongs to the final standings.
		raceHead() {
			if (this.paceState === 'open') return this.finishedText
			if (this.paceState === 'closed') return this.t('pulse', 'The quiz is closed.')
			return ''
		},
		finishedText() {
			const r = this.race || {}
			return this.t('pulse', '{done} of {total} finished', { done: r.finished || 0, total: r.joined || 0 })
		},
		// Centred stage without bars: closed, practice run released (no
		// leaderboard), released without participants.
		raceNote() {
			if (this.paceState === 'closed') return [this.finishedText, this.t('pulse', 'Results come once your host releases them.')]
			if (this.leaderboard === null) return [this.t('pulse', 'Quiz finished'), this.t('pulse', 'Thanks for joining in.')]
			return [this.t('pulse', 'Final standings'), this.t('pulse', 'No points yet — nobody has played along.')]
		},
		raceChip() {
			if (this.paceState === 'open') {
				const closesAt = (this.paceWindow && this.paceWindow.closesAt) || 0
				return closesAt > 0 ? this.t('pulse', 'Open until {time}', { time: fmtDeadline(closesAt) }) : this.t('pulse', 'Open now')
			}
			return this.paceState === 'closed' ? this.t('pulse', 'Quiz closed') : this.t('pulse', 'Quiz finished')
		},
		// Lobby texts: moderated exactly the previous ones. In draft not "Starting
		// shortly" — a prepared homework would stand like that on the hallway screen for days.
		lobbyTitle() {
			if (this.paceState === 'draft') return this.roomTitle || this.t('pulse', 'Not open yet')
			if (this.paceState === 'open') return this.roomTitle || this.t('pulse', 'The quiz is open')
			return this.roomTitle || this.t('pulse', 'Starting shortly')
		},
		lobbySub() {
			if (this.paceState === 'open') return this.t('pulse', 'Join and start at your own pace.')
			if (!this.roomTitle) return ''
			return this.paceState === 'draft' ? this.t('pulse', 'Not open yet') : this.t('pulse', 'Starting shortly')
		},
		// Open with a deadline: when it ends, as an absolute time.
		lobbyWhen() {
			const closesAt = (this.paceState === 'open' && this.paceWindow && this.paceWindow.closesAt) || 0
			return closesAt > 0 ? this.t('pulse', 'Open until {time}', { time: fmtDeadline(closesAt) }) : ''
		},
		isLive() {
			return this.roomExists && !!this.poll
		},
		// Cloud test mode: projector URL with ?demo=1 -> WordCloud generates a living
		// cloud locally (no server) to check the look without real participants.
		// Only has an effect on a running word cloud question.
		demo() {
			return /(?:\?|&)demo=/.test(window.location.search)
		},
		// Revealed = the server has released the question (poll.revealed). The
		// display decision E1 depends on this alone; the status is not enough,
		// because 'locked' is still hidden with "Reveal at the end". Falls back to
		// the status only for a response without the field.
		revealed() {
			if (!this.poll) return false
			if (typeof this.poll.revealed === 'boolean') return this.poll.revealed
			return ['locked', 'ended'].includes(this.poll.status)
		},
		// The open stage holds the result back (E1) — except for the
		// word cloud, which keeps growing live (E2).
		showResults() {
			if (!this.results) return false
			return this.revealed || this.results.type === 'words'
		},
		// Image question: two-column stage, head on the right (§7.1). At the end of the quiz
		// the big screen belongs to the final standings, then the image no longer counts.
		hasImage() {
			return !!this.poll && !!this.poll.image && !this.quizEnded
		},
		// Revealed, but without a single vote. The word cloud has its
		// own empty state and is excluded.
		emptyResult() {
			return this.revealed && !!this.results && this.results.type !== 'words'
				&& !this.results.total
		},
		// Word cloud counter for the meta bar: only with an active word cloud with
		// at least one word.
		wordStats() {
			if (!this.results || this.results.type !== 'words') return null
			const s = this.cloudStats
			return s && s.words ? s : null
		},
		// Number guess and free text have no answer options — there the
		// intake fills the stage alone (§6.1). Exactly these two stages used to be
		// empty apart from a pen icon (D6).
		soloOpen() {
			return !!this.poll && ['number', 'text'].includes(this.poll.type)
		},
		// Rows shown by the open stage — options, aspects or pairs.
		// Decides the stacking AND the shrink key: the latter
		// only counted poll.options, which is empty for matching. Switching
		// from four pairs to eight therefore left the stage at the old
		// font size until a resize came along at some point.
		openRows() {
			const p = this.poll
			if (!p) return 0
			if (p.type === 'match') return ((p.match && p.match.items) || []).length
			if (p.type === 'scale') return ((p.scale && p.scale.aspects) || []).length
			return (p.options || []).length
		},
		// Only matching stacks. It is the only type that still carries something
		// below the rows (the target chips) — which is why half the width
		// becomes too narrow for it first. Eight options without chips still sit side by side
		// well; that was signed off in §11 and stays untouched.
		stackOpen() {
			return !!this.poll && this.poll.type === 'match' && !this.hasImage && this.openRows > 5
		},
		// Locked, but not revealed ("Reveal at the end" after locking):
		// no more answers, but no result yet either.
		closedHidden() {
			return !!this.poll && !this.revealed && ['locked', 'ended'].includes(this.poll.status)
		},
		// A countdown only exists in a quiz and only while the question is running.
		showCountdown() {
			return !this.quizEnded && !this.revealed && !this.closedHidden && this.remaining !== null
		},
		timeUp() {
			return this.remaining !== null && this.remaining <= 0 && !this.revealed
		},
		// "complete": everyone connected has answered.
		allAnswered() {
			return !this.revealed && !this.quizEnded && this.present > 0 && this.answered >= this.present
		},
		// State chip: the structural difference (bars yes/no) is the
		// loudest signal, the chip also names it in words — colour is
		// never the only carrier.
		stateText() {
			if (this.quizEnded) {
				const count = Math.max(this.leaderboardTotal, Array.isArray(this.leaderboard) ? this.leaderboard.length : 0)
				return this.t('pulse', 'Result') + ' · ' + this.n('pulse', '%n participant', '%n participants', count)
			}
			if (this.revealed) {
				const total = (this.results && this.results.total) || 0
				return this.t('pulse', 'Result') + ' · ' + this.n('pulse', '%n vote', '%n votes', total)
			}
			if (this.closedHidden) {
				return this.t('pulse', 'Answers closed') + ' · ' + this.answered + ' ' + this.n('pulse', 'answer', 'answers', this.answered)
			}
			if (this.timeUp) {
				return this.t('pulse', 'Time is up') + ' · ' + this.answered + ' ' + this.n('pulse', 'answer', 'answers', this.answered)
			}
			return this.t('pulse', 'Answers coming in') + ' · ' + this.answered + ' ' + this.n('pulse', 'answer', 'answers', this.answered)
		},
		// Explanatory text of the stage — today three places (footnote left, counter right,
		// QR at the top), in future one.
		legend() {
			const r = this.results
			// Without votes the legend explains a chart that is not even there.
			if (this.quizEnded || !this.showResults || !r || this.emptyResult) return ''
			switch (r.type) {
			case 'choice':
				return this.t('pulse', '100% = all votes')
			case 'multi':
				return this.t('pulse', '100% = everyone who answered · the sum can exceed 100%')
			case 'rank':
				// The row is always full; so what needs explaining is the
				// segment, not the length (§7.5).
				return this.t('pulse', 'Segment = share on this place · sorted by average place')
			case 'match':
				return (this.poll.answerKey && this.poll.answerKey.map)
					? this.t('pulse', 'Segment = share per target · ring marks the correct target')
					: this.t('pulse', 'Segment = share per target')
			case 'number':
				return this.t('pulse', 'Distribution of the guesses · bars relative to the most frequent value')
			case 'scale':
				if (r.mode === 'compass') return this.t('pulse', 'Centre = neutral')
				// The spectrum carries its legend below the radar (§7.3).
				if (r.mode === 'spectrum') return ''
				return this.t('pulse', 'Distribution · column height = votes per value · scale {min}–{max}', { min: r.min, max: r.max })
			case 'words': {
				const s = this.wordStats
				return s ? s.words + ' ' + this.n('pulse', 'word', 'words', s.words) + ' · ' + this.n('pulse', '%n mention', '%n mentions', s.votes) : ''
			}
			default:
				return ''
			}
		},
		// QR only while someone can join. Stateless: open = yes.
		showQr() {
			return !this.revealed && !this.quizEnded
		},
		spacedCode() {
			return formatCode(this.code)
		},
		joinPath() {
			return participantPage(this.code)
		},
		joinUrl() {
			return window.location.host + this.joinPath
		},
		joinUrlFull() {
			return window.location.origin + this.joinPath
		},
		// Quiz ended = the running question is 'ended' (set by "Finish quiz";
		// a question revealed on its own is 'locked'). Without a leaderboard —
		// practice run — the usual reveal stays.
		quizEnded() {
			return !!this.poll && this.poll.status === 'ended'
				&& Array.isArray(this.leaderboard) && this.leaderboard.length > 0
		},
		remaining() {
			return remainingSecs(this.poll, this.nowSec, this.serverSkew)
		},
		// The stage computes entirely in em; exactly this one px value is
		// written, everything else follows (§2.3).
		// Refit only on a structural change (question, type, row count) —
		// otherwise it would briefly jump back to the base size with every new vote.
		// The content (text, image, labels) belongs in it: an edited
		// running question with longer text would otherwise cut off the bottom rows.
		fitKey() {
			// Self-paced: everything that changes rows or columns — not the numbers IN
			// the rows (otherwise it would jump to the base size on every fetch).
			// When released, the length of the final standings counts (podium + list).
			if (this.raceView) {
				const r = this.race || {}
				const notStartedShown = this.paceState === 'open' && (r.joined || 0) > (r.started || 0)
				const board = this.paceState === 'released' ? (this.leaderboard || []).length : this.raceBoard.length
				return 'race:' + this.paceState + ':' + (r.n || 0) + ':' + board + ':' + this.raceSide + ':' + (notStartedShown ? 1 : 0)
					+ (this.raceTight ? ':t' + this.raceBoardRows : '')
			}
			if (!this.poll) return null
			const r = this.results
			const p = this.poll
			const labels = (list) => (list || []).map((o) => o.label).join('\u0001')
			const content = [p.question, p.image, labels(p.options), labels((p.match || {}).items),
				labels((p.match || {}).targets), JSON.stringify(p.scale || null)].join('\u0002')
			return p.id + ':' + content + ':' + (this.quizEnded
				? 'final:' + (this.leaderboard || []).length
				: (this.showResults && r)
					? r.type + ':' + (Array.isArray(r.results) ? r.results.length : 0)
					: 'open:' + this.poll.type + ':' + this.openRows)
		},
		stageStyle() {
			return this.stageFs ? { '--scr-stage-fs': this.stageFs + 'px' } : {}
		},
		// Distributions with many values (scale 1–10) otherwise run out at the bottom; from >6
		// rows on, two columns (5+5) -> bars stay large instead of shrinking to a
		// tiny font. The fit remains as a safety net for the rest.
		resultCols() {
			return this.resultRows > 6
		},
		resultRows() {
			const r = this.results
			return r && Array.isArray(r.results) ? r.results.length : 0
		},
		// Free text on the projector (§5b): accepted spellings as a chip list.
		acceptedList() {
			return (this.results && this.results.accepted) || []
		},
		// "N mentions · M different" — the same grammar as §5a.
		textSummary() {
			if (!this.results) return ''
			const total = this.results.total || 0
			// The public tally carries the 100 most frequent groups; answersTotal counts all.
			const distinct = this.results.answersTotal || (this.results.answers || []).length
			return this.n('pulse', '%n mention', '%n mentions', total) + ' · ' + this.t('pulse', '{count} different', { count: distinct })
		},
		// Frequency tiles: sorted by count descending, size ∝ frequency (em).
		textCards() {
			const rows = (this.results && this.results.answers) || []
			if (!rows.length) return []
			const max = Math.max.apply(null, rows.map((r) => r.count || 0)) || 1
			return rows.slice().sort((a, b) => (b.count || 0) - (a.count || 0)).map((a) => ({
				norm: a.norm, sample: a.sample, count: a.count, status: a.status || 'open',
				em: Math.round((1 + (a.count / max) * 1.4) * 100) / 100,
			}))
		},
	},
	watch: {
		// New/different stage -> refit the size (next tick, the DOM is ready then).
		// On the key rather than on the results: in a quiz those stay null from the lobby
		// until the reveal, so the first question would otherwise never be fitted.
		fitKey() {
			this.fitStage()
		},
		revealed() {
			this.fitStage(true)
		},
	},
	mounted() {
		// Projector = full-screen kiosk: hide the NC chrome. The body class gates the
		// global overrides -> the phone (Participant) stays intact.
		document.body.classList.add('pulse-beamer')
		window.addEventListener('resize', this.fitStage)
		window.addEventListener('resize', this.measureMeta)
		this.measureMeta()
		// Web fonts load after the first paint; without this second pass
		// the loop measures against the fallback font.
		if (document.fonts && document.fonts.ready) {
			document.fonts.ready.then(() => this.fitStage(true)).catch(() => {})
		}
		if (this.code && this.roomExists) {
			this.pollTick()
			this.tickTimer = setInterval(() => { this.nowSec = Math.floor(Date.now() / 1000) }, 500)
			document.addEventListener('visibilitychange', this.onPollVisibility)
		}
	},
	beforeDestroy() {
		this.pollStopped = true
		document.body.classList.remove('pulse-beamer')
		window.removeEventListener('resize', this.fitStage)
		window.removeEventListener('resize', this.measureMeta)
		this.pollClear()
		if (this.tickTimer) clearInterval(this.tickTimer)
		document.removeEventListener('visibilitychange', this.onPollVisibility)
	},
	methods: {
		base(suffix = '') {
			return publicApi(this.code, suffix)
		},
		// Image URL of the question (public route, file name as cache buster).
		imageUrl(poll) {
			if (!poll || !poll.image) return ''
			return pollImage(this.code, poll.id, poll.image, { public: true })
		},
		applyState(data) {
			// Different server protocol: old bundle from the cache -> reload once.
			if (reloadOnProtocolMismatch(data.protocol)) return
			this.poll = data.poll
			this.results = data.results
			// Reset the counter as soon as no word cloud is running (any more) — otherwise
			// old numbers would stay in the meta bar for a different question type.
			if (!data.results || data.results.type !== 'words') this.cloudStats = null
			if (data.room) this.roomTitle = data.room.title || ''
			this.answered = data.answered || 0
			this.present = data.present || 0
			if ('practice' in data) this.practice = !!data.practice
			this.leaderboard = data.leaderboard || null
			this.leaderboardTotal = data.leaderboardTotal || 0
			// Self-paced: when moderated all three fields are missing.
			this.pace = (data.room && data.room.pace) || ''
			this.paceWindow = data.window || null
			this.race = data.race || null
			this.serverSkew = (data.serverNow || Math.floor(Date.now() / 1000)) - Math.floor(Date.now() / 1000)
			if (data.version !== undefined) this.version = data.version
		},
		/*
		 * Shrink loop (§2.3): resets --scr-stage-fs, measures the stage
		 * and reduces the em base in 4 % steps until the content fits.
		 * Everything on the stage is em of this one value — which is why one
		 * write is enough, and podium and leaderboard follow too (that was B1: the
		 * podium computed in px and ignored the shrink factor, which is why
		 * places 4–8 were cut off at overflow:hidden).
		 */
		fitStage(force) {
			// Lobby: no stage to fit. Forget the key so that the
			// next question is fitted anew — even if it has the same shape
			// and the window has grown or shrunk while in the lobby.
			const key = this.fitKey
			if (key === null) {
				this._fitKey = null
				return
			}
			// Self-paced: a resize or a new shape of the race tries again
			// with airy rows — it only gets tight when nothing else
			// fits (fitTight). Resetting changes the key, the watcher
			// calls again right away; this call ends here. Only a
			// race key depends on raceTight: a moderated question after it (room
			// reset and switched) fits itself in THIS call —
			// otherwise the shrunken race font would stay until the reveal.
			const shape = key.replace(/:t\d+$/, '')
			if (this.raceTight && (force || shape !== this._raceShape)) {
				this.raceTight = false
				this.raceBoardRows = RACE_BOARD_ROWS
				if (this.raceView) return
			}
			if (this.raceView) this._raceShape = shape
			// Resize calls with the event object as argument -> force = truthy.
			this.measureMeta()
			if (!force && key === this._fitKey) return
			this._fitKey = key
			this.stageFs = null // first back to the clamp() start value
			// One chain at a time: a new one ends the one still running. Two chains
			// share stageFs and _fitBase; the older one could take the other's
			// smaller value for the floor and call fitTight at 29 px instead of
			// 24 — rows tight too early, the leaderboard trimmed too far.
			const gen = this._fitGen = (this._fitGen || 0) + 1
			this.$nextTick(() => requestAnimationFrame(() => this.fitPass(0, gen)))
		},
		// Measure class C on the real element instead of rebuilding the clamp() formula
		// in JavaScript — the lower bound of the word cloud is exactly the
		// size of the meta bar (§7.7).
		measureMeta() {
			const el = this.$el && this.$el.querySelector ? this.$el.querySelector('.scr-meta') : null
			if (el) this.metaFs = Math.round(parseFloat(getComputedStyle(el).fontSize)) || 23
		},
		fitPass(iter, gen) {
			const el = this.$refs.stage
			if (!el || gen !== this._fitGen) return
			// The start value is the computed clamp() value — on the first pass
			// there is no inline override on the stage any more.
			if (iter === 0) this._fitBase = parseFloat(getComputedStyle(el).fontSize) || 46
			if (el.scrollHeight <= el.clientHeight + 1) return
			if (iter >= 12) {
				// Twelve steps of 4 % end at 28 px — the floor of 24 px named in §2.3
				// could never be reached that way. One last jump there, then
				// that is it: whatever still does not fit is too much content for the
				// stage and should be shortened, not shrunk further.
				if (this.stageFs !== FIT_FLOOR_PX) {
					this.stageFs = FIT_FLOOR_PX
					this.$nextTick(() => requestAnimationFrame(() => this.fitPass(iter + 1, gen)))
				} else {
					this.fitTight()
				}
				return
			}
			const next = Math.max(FIT_FLOOR_PX, Math.round(this._fitBase * Math.pow(0.96, iter + 1)))
			if (this.stageFs !== null && next >= this.stageFs) { // floor reached
				this.fitTight()
				return
			}
			this.stageFs = next
			this.$nextTick(() => requestAnimationFrame(() => this.fitPass(iter + 1, gen)))
		},
		// Self-paced: if the open race does not fit even at the shrink limit,
		// the rows move closer together (StageRace `tight`). The key
		// changes, the watcher refits. In the embed frame (PowerPoint,
		// 1264×576) eight airy rows would otherwise overflow by 55 px.
		// If tight rows are not enough either — there, the leaderboard after
		// two minutes overflowed by 75 px and lost two rows mid-row — the
		// leaderboard gives up rows at the bottom (trimRaceBoard), a new key
		// again, until it fits or nothing is left to gain.
		fitTight() {
			if (!this.raceView || this.paceState !== 'open') return
			if (!this.raceTight) {
				this.raceTight = true
				return
			}
			// The overflow trimRaceBoard works from is the one at the floor.
			const el = this.$refs.stage
			const race = this.$refs.race
			if (!el || !race || this.stageFs !== FIT_FLOOR_PX) return
			const m = race.boardMeasure()
			const rows = trimRaceBoard({ ...m, overflow: el.scrollHeight - el.clientHeight })
			if (rows < m.shown) this.raceBoardRows = rows
		},
		// Single-flight in self-paced mode (mixins/polling.js): every fetch has a
		// timeout there; a returning tab would otherwise start a second chain.
		pollSingleFlight() {
			return this.isPaced
		},
		// Adaptive polling as for the participant, but as a pure spectator (spectate).
		pollDelay() {
			if (document.hidden) return null
			if (this.poll && !this.revealed) return 1300 // question running -> brisk (countdown/counter)
			if (this.poll) return 2000 // reveal visible -> update calmly
			// Self-paced open: the server keeps the projector state in 2 s buckets.
			if (this.isPaced && this.paceState === 'open') return 2000
			return this.idleStreak >= 3 ? 5000 : 2500 // lobby -> back off
		},
		async pollOnce() {
			try {
				const params = { spectate: 1 }
				if (this.version) params.v = this.version
				// In self-paced mode with a timeout: a hanging request would otherwise stall
				// the loop without "Connection lost" appearing (moderated
				// unchanged).
				const res = await axios.get(this.base('/state'), this.isPaced ? { params, timeout: PHONE_TIMEOUT } : { params })
				this.online = true
				if (res.status === 204) { // unchanged
					this.idleStreak++
					return
				}
				this.applyState(res.data)
				this.idleStreak = 0
			} catch (e) {
				if (e?.response?.status === 404) {
					// Room gone -> stop polling for good. Otherwise the projector tab keeps
					// querying the endpoint every few seconds; every "not found"
					// counts towards the brute-force action 'pulseRoomCode' and in the
					// end throttles the whole IP (429 "Too many requests").
					this.roomExists = false
					this.pollStopped = true
				} else {
					this.online = false
				}
			}
		},
	},
}
</script>

<style scoped>
/* ── Kiosk frame (§2.1) ────────────────────────────────────────────
   Full-bleed, no scrolling, no NC chrome. The rows are assigned
   fixed (grid-row), so the stage keeps 1fr even when the head
   or the countdown is missing. */
.scr {
	position: fixed;
	inset: 0;
	z-index: 1;
	display: grid;
	grid-template-rows: 1fr;
	overflow: hidden;
	box-sizing: border-box;
	padding: var(--scr-pad);
	background: var(--pulse-screen-bg);
	color: var(--pulse-text);
	font-family: var(--font-face, system-ui, -apple-system, sans-serif);
}
.scr.is-live, .scr.is-race { grid-template-rows: auto 1fr auto; }
.scr-head { grid-row: 1; }
.scr-stage { grid-row: 2; }
.scr-meta { grid-row: 3; }
/* Image question: no head of its own — the question sits in the right column of the
   stage, so that question and result are read together (§7.1). */
.scr.is-live[data-image] { grid-template-rows: 1fr auto; }
.scr[data-image] .scr-stage { grid-row: 1; }
.scr[data-image] .scr-meta { grid-row: 2; }
.scr-muted { color: var(--pulse-text-2); }

/* ── Error ─────────────────────────────────────────────────────── */
.scr-center { margin: auto; text-align: center; }
.scr-center h1 { font-size: clamp(28px, 4vw, 48px); margin: 0 0 8px; }

/* ── Lobby ─────────────────────────────────────────────────────── */
.scr-lobby { margin: auto; text-align: center; max-width: 1100px; }
.scr-eyebrow { color: var(--pulse-primary); letter-spacing: 0.2em; text-transform: uppercase; font-weight: 800; font-size: clamp(14px, 1.4vw, 20px); margin: 0 0 12px; }
.scr-lobby-title { font-size: clamp(36px, 6vw, 84px); margin: 0 0 32px; line-height: 1.05; }
.scr-join { display: flex; align-items: center; justify-content: center; gap: clamp(24px, 5vw, 72px); flex-wrap: wrap; }
/* QR card: white background (scannability) + theme-independent border, even on a dark projector. */
.scr-qr { width: clamp(200px, 26vw, 380px); background: #fff; padding: 16px; border: 3px solid var(--pulse-border-strong); border-radius: var(--pulse-r-card); box-shadow: var(--pulse-shadow-pop); }
.scr-join-txt { text-align: left; }
.scr-join-hint { font-size: clamp(16px, 1.8vw, 24px); color: var(--pulse-text-2); margin: 0 0 12px; max-width: 12em; }
.scr-pin {
	display: inline-block; background: var(--pulse-primary); color: var(--pulse-on-primary);
	font-weight: 900; font-size: clamp(40px, 6vw, 84px); letter-spacing: 0.12em;
	padding: 8px 28px; border-radius: var(--pulse-r-card); margin: 0 0 12px; font-variant-numeric: tabular-nums;
}
.scr-join-url { font-size: clamp(15px, 1.5vw, 22px); color: var(--pulse-readout); font-weight: 700; margin: 0; word-break: break-all; }
.scr-lobby-sub { font-size: clamp(18px, 2.4vw, 30px); color: var(--pulse-text-2); margin: -4px 0 4px; }
/* Self-paced, open with a deadline: when it ends (absolute) — as a second
   pill next to the momentum counter, same height, neutral. */
.scr-lobby-when {
	display: inline-flex; align-items: center; gap: 12px;
	margin: clamp(20px, 3vw, 40px) 8px 0; padding: 10px 22px;
	border-radius: var(--pulse-r-pill);
	background: var(--pulse-bar-track); color: var(--pulse-text);
	font-size: clamp(16px, 1.8vw, 26px); font-weight: 700;
}

/* Momentum: "N people are already here" with a live dot (reduced-motion safe). */
.scr-momentum {
	display: inline-flex; align-items: center; gap: 12px; align-self: center;
	margin: clamp(20px, 3vw, 40px) 0 0; padding: 10px 22px;
	border-radius: var(--pulse-r-pill);
	background: var(--pulse-success-soft); color: var(--pulse-text);
	font-size: clamp(16px, 1.8vw, 26px); font-weight: 700;
}
.scr-momentum b { font-size: 1.15em; font-weight: 900; font-variant-numeric: tabular-nums; }
.scr-momentum-dot { width: 0.7em; height: 0.7em; border-radius: 50%; background: var(--pulse-success); animation: scr-beat 1.4s ease-in-out infinite; }
@keyframes scr-beat { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.35; transform: scale(0.7); } }
.scr-practice {
	display: inline-flex; align-items: center; gap: 8px; padding: 6px 16px; border-radius: var(--pulse-r-pill); font-weight: 800;
	font-size: clamp(14px, 1.4vw, 20px);
	color: var(--pulse-warning); background: var(--pulse-warning-soft);
}

/* ── Head (class A) ────────────────────────────────────────────── */
.scr-head {
	display: flex; align-items: center; gap: 1em;
	max-height: 26vh; overflow: hidden;
	margin-bottom: clamp(12px, 1.6vw, 28px);
	/* Descender reserve: the line clips, 1.08 is below the natural
	   text height — without a reserve "g" loses its descender (§2.6). */
	padding-bottom: 0.16em;
}
/* ── Image question (§7.1/§7.8) ────────────────────────────────── */
/* Two columns: image on the left across the full stage height, question and content on the right.
   The image column is `auto`, not a fixed third — a portrait image
   shrinks in width and hands the space to the content instead of leaving an empty
   column behind. */
.scr-stage[data-image] {
	display: grid;
	grid-template-columns: auto minmax(0, 1fr);
	grid-template-rows: auto minmax(0, 1fr);
	align-items: center;
	gap: 0.4em 1.2em;
}
.scr-stage[data-image] > .scr-img-mat { grid-column: 1; grid-row: 1 / span 2; align-self: center; }
.scr-stage[data-image] > .scr-split-q { grid-column: 2; grid-row: 1; }
/* Whatever else sits on the stage (result, open stage, empty state)
   ends up in the bottom right cell — no matter which branch is rendering. */
/* align-self: stretch is mandatory — with the centring align-items the
   content would stay content-sized and run out of the bottom of the kiosk (38 px). */
.scr-stage[data-image] > *:not(.scr-img-mat):not(.scr-split-q) { grid-column: 2; grid-row: 2; min-height: 0; align-self: stretch; }
/* Open image question: answer options on top, intake narrow below — it
   does NOT move into the meta bar, it stays visible (§7.1). */
.scr-stage[data-image] .scr-open { grid-template-columns: 1fr; grid-template-rows: minmax(0, 1fr) auto; gap: 0.6em; }
/* Question and ring compute in the stage size as soon as they sit IN the stage —
   otherwise the shrink loop would not take them along (§2.3). */
.scr-split-q { display: flex; align-items: center; gap: 0.6em; min-width: 0; }
.scr-split-q .scr-q { font-size: 1.5em; -webkit-line-clamp: 2; }
.scr-split-q .scr-cd { font-size: 1.5em; }
.scr-img-mat {
	display: grid; place-items: center; min-width: 0; min-height: 0;
	box-sizing: border-box;
	/* Cap on the viewport, not on the grid track: the image column is `auto`, so
	   it sizes itself by the image — a percentage cap would be circular and the
	   image would push the stage out of the kiosk (portrait: 152 px). 72vh sits safely
	   below the stage height (padding, head and meta bar subtracted). */
	max-width: 46vw;
	padding: 0.4em; border-radius: 0.2em;
	background: var(--pulse-image-mat); border: 1px solid var(--pulse-border);
}
/* The filter is `none` in light mode; in dark mode it takes the glare off white image
   backgrounds without distorting colours (§7.8). */
.scr-img { display: block; max-width: 44vw; max-height: 68vh; object-fit: contain; filter: var(--pulse-image-filter); }

/* Revealed without votes (§7.9) */
.scr-empty { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; justify-content: center; gap: 0.6em; }
.scr-empty-txt { margin: 0; text-align: center; font-weight: 700; color: var(--pulse-meta); }
/* Ring on the question axis: it sizes itself by the question size (§6.5). */
.scr-cd { font-size: var(--scr-q-fs); }
.scr-q {
	font-size: var(--scr-q-fs); line-height: 1.08; margin: 0; flex: 1; min-width: 0; font-weight: 800;
	text-wrap: balance;
	overflow: hidden; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical;
}

/* ── Stage ─────────────────────────────────────────────────────── */
/* min-height:0 is mandatory: without it the stage grows beyond the box and
   the scrollHeight/clientHeight comparison in fitPass() never kicks in. */
.scr-stage {
	min-height: 0; overflow: hidden;
	font-size: var(--scr-stage-fs);
	display: flex; flex-direction: column;
}
.scr-stage > * { min-height: 0; }
/* Open stage (§6.1): read on the left, intake on the right. Without
   answer options (number guess, free text) the intake fills the stage. */
.scr-open { flex: 1 1 auto; min-height: 0; display: grid; grid-template-columns: 1.4fr 1fr; gap: 2em; align-items: center; }
.scr-open[data-solo] { grid-template-columns: 1fr; place-items: center; }
/* Many rows: the same stacking as next to an image (§7.1) — content on top
   across the full width, intake as a flat strip below. */
.scr-open[data-stack] { grid-template-columns: 1fr; grid-template-rows: minmax(0, 1fr) auto; gap: 0.8em; align-items: stretch; }
.scr-open > * { min-width: 0; min-height: 0; }
.scr-open > .sopen { align-self: stretch; }

.scr-results { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
/* Many distribution values (scale 1–10) -> two columns, so that the bars stay
   large instead of shrinking to a tiny font. The avg. block stays full width on top. */
.scr-results--cols :deep(.bars--dist) { columns: 2; column-gap: clamp(28px, 4vw, 72px); }
.scr-results--cols :deep(.bars--dist .bar-row) { break-inside: avoid; }
/* The results root fills the remaining height as a flex column, so that the
   height-bound square charts (radar/compass — .is-wide in ResultsView) inherit a
   defined height. */
.scr-results :deep(.pulse-results) { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }

/* Free text on the projector: frequency tiles. Everything em -> scales with fitStage. */
.scr-ftcards { flex: 1 1 auto; display: flex; flex-direction: column; min-height: 0; }
.scr-ft-head { display: flex; align-items: center; gap: 0.5em; flex-wrap: wrap; margin-bottom: 0.4em; }
.scr-ft-accept { font-size: 0.55em; }
.scr-ft-sum { margin-left: auto; font-size: 0.55em; font-weight: 700; color: var(--pulse-meta); }
.scr-ft-cloud { display: flex; flex-wrap: wrap; align-content: flex-start; gap: 0.4em; }
.scr-ft-card {
	display: inline-flex; align-items: center; gap: 0.5em;
	padding: 0.35em 0.7em; border-radius: var(--pulse-r-el);
	background: var(--pulse-bar-track); border: 2px solid var(--pulse-border);
	font-weight: 700; line-height: 1.1;
}
.scr-ft-card.is-accepted { background: var(--pulse-success-soft); border-color: var(--pulse-success); color: var(--pulse-success); }
.scr-ft-card.is-rejected { opacity: 0.5; text-decoration: line-through; }
.scr-ft-card.is-open { border-style: dashed; }
.scr-ft-txt { min-width: 0; }
.scr-ft-mult { font-variant-numeric: tabular-nums; font-weight: 800; font-size: 0.72em; color: var(--pulse-meta); background: var(--pulse-screen-bg); padding: 0.1em 0.45em; border-radius: var(--pulse-r-pill); }
.scr-ft-card.is-accepted .scr-ft-mult { color: var(--pulse-success); }
.scr-ft-empty { color: var(--pulse-text-2); }

/* Self-paced without bars (§3.2): closed shows the counter, released without
   a leaderboard the closing note — centred, large, in em of the stage. */
.scr-race-note { margin: auto; max-width: 22em; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 0.5em; }
.scr-race-note-big { margin: 0; font-size: 1.6em; font-weight: 800; line-height: 1.1; padding-bottom: 0.08em; font-variant-numeric: tabular-nums; text-wrap: balance; }
.scr-race-note-sub { margin: 0; font-size: 0.8em; font-weight: 600; line-height: 1.25; color: var(--pulse-text-2); text-wrap: balance; }

/* Final standings: one heading, below it podium + places 4–8 (§4.3). */
.scr-final { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; gap: 0.5em; }
.scr-final-title { margin: 0; text-align: center; font-weight: 900; letter-spacing: 0.02em; font-size: var(--scr-q-fs); line-height: 1.08; }
.scr-final-lb { flex: 1 1 auto; min-height: 0; }

/* Word cloud stage: fills the remaining projector height (the engine needs a size > 0)
   and the FULL width (otherwise the box shrinks to the counter row). */
.scr-cloud { flex: 1 1 auto; display: flex; min-height: 0; width: 100%; min-width: 0; align-self: stretch; }

/* ── Meta bar (§2.5) ───────────────────────────────────────────── */
.scr-meta {
	display: grid;
	grid-template-columns: auto 1fr auto;
	align-items: center;
	gap: 1em;
	margin-top: clamp(12px, 1.4vw, 24px);
	padding-top: 0.75em;
	border-top: 1px solid var(--pulse-border);
	font-size: var(--scr-meta-fs);
	color: var(--pulse-meta);
}
.scr-meta-l { display: flex; align-items: center; gap: 0.6em; }
.scr-chip {
	display: inline-flex; align-items: center; gap: 0.45em;
	padding: 0.25em 0.8em; border-radius: var(--pulse-r-pill);
	font-weight: 700; white-space: nowrap;
	background: var(--pulse-bar-track); color: var(--pulse-text);
}
.scr-chip.is-open { color: var(--pulse-state-open); background: var(--pulse-success-soft); }
.scr-chip.is-done { color: var(--pulse-state-done); }
.scr-chip.is-practice { color: var(--pulse-warning); background: var(--pulse-warning-soft); }
.scr-chip.is-off { color: var(--pulse-error); background: var(--pulse-error-soft); }
.scr-chip.is-full { color: var(--pulse-primary); background: var(--pulse-primary-soft); }
/* Self-paced: the public page carries data-themes="" — the fixed
   dark values from pulse-tokens.css do not apply there, --pulse-meta and
   --pulse-state-done stayed #33465e on a dark background. The new frames
   therefore use roles that follow the NC theme (moderated unchanged). */
.scr.is-race .scr-meta { color: var(--pulse-text-2); }
.scr.is-race .scr-chip.is-done { color: var(--pulse-text); }
.scr-lobby.is-paced .scr-join-url { color: var(--pulse-text-2); }
.scr-chip-dot { width: 0.55em; height: 0.55em; border-radius: 50%; background: currentColor; animation: scr-beat 1.6s ease-in-out infinite; }
.scr-legend { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
/* QR + code: the code stays in class B — from the back row it is the
   actual way to join; scanning a QR from 12 m does not work. */
.scr-join-mini { display: flex; align-items: center; gap: 0.6em; flex: 0 0 auto; }
.scr-qr-mini { width: 88px; background: #fff; padding: 4px; border: 2px solid var(--pulse-border-strong); border-radius: var(--pulse-r-el); }
.scr-pin-mini { font-weight: 800; font-size: var(--scr-stage-fs); letter-spacing: 0.08em; color: var(--pulse-text); font-variant-numeric: tabular-nums; }

.scr-conn { margin-top: 24px; color: var(--pulse-error); font-size: 16px; }

@media (prefers-reduced-motion: reduce) {
	.scr-momentum-dot, .scr-chip-dot { animation: none; }
}
</style>

<!--
	Un-scoped + gated by a body class: applies ONLY on the projector (Screen.vue
	sets `body.pulse-beamer`), not on the phone.

	The projector is a kiosk (D3): no NC header bar, no blue border, no
	white card inside it. The stage itself sits `position: fixed` above everything; here
	only what would otherwise stay visible below or above it is removed (header bar,
	guest footer, page background).
-->
<style>
body.pulse-beamer #header { display: none !important; }
body.pulse-beamer footer.guest-box { display: none !important; }
body.pulse-beamer #content {
	margin: 0 !important;
	padding: 0 !important;
	border-radius: 0 !important;
	background: var(--pulse-screen-bg) !important;
}
</style>
