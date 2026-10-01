<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pace-race" :class="{ 'has-side': !!side, 'is-dense': dense }">
		<!-- Left: where people are right now. At the top those who have not started,
		     one row per question, at the bottom those who are through. From eleven
		     questions two columns, from 21 groups — "Finished" is always full width below. -->
		<div ref="bars" class="srows pace-race-rows">
			<StageRow v-if="idle"
				class="pace-race-row is-idle"
				:label="t('pulse', 'Not started')"
				:value="num(idle.value)"
				:pct="pct(idle.value)" />
			<div v-if="layout.cols === 2" class="pace-race-grid" :style="{ '--rows': layout.perCol }">
				<StageRow v-for="row in steps"
					:key="row.key"
					class="pace-race-row"
					:label="labelOf(row)"
					:value="num(row.value)"
					:pct="pct(row.value)" />
			</div>
			<template v-else>
				<StageRow v-for="row in steps"
					:key="row.key"
					class="pace-race-row"
					:label="labelOf(row)"
					:value="num(row.value)"
					:pct="pct(row.value)" />
			</template>
			<StageRow class="pace-race-row is-done"
				:label="t('pulse', 'Finished')"
				:value="num(finished)"
				:pct="pct(finished)" />
		</div>

		<!-- On the right, in the first two minutes (and as long as nobody has points),
		     joining: in a classroom people are still arriving, and the mini QR in
		     the meta bar alone is not enough for that. After that the leaders. -->
		<aside v-if="side === 'join'" class="pace-race-side pace-race-join">
			<div class="pace-race-qr">
				<QrCode :value="joinUrlFull" />
			</div>
			<p class="pace-race-pin">
				{{ code }}
			</p>
			<p class="pace-race-url">
				{{ joinUrl }}
			</p>
		</aside>
		<aside v-else-if="side === 'board'" ref="board" class="pace-race-side pace-race-board">
			<p class="pace-race-side-head">
				{{ t('pulse', 'Leaderboard') }}
			</p>
			<Leaderboard :rows="board" :limit="boardRows" />
		</aside>
	</div>
</template>

<script>
/*
 * Self-paced projector: the race (spec §3.3, not in the public repository).
 *
 * Numbers only — never question text, options or distributions; the component
 * receives no question data. Every person sits in exactly one row (the
 * server counts those who are finished no longer on their last question):
 * "Not started" + questions + "Finished" = joined. Every bar is relative to
 * "joined", together they add up to 100 % — hence the legend
 * "100% = everyone who joined" in the projector's meta bar.
 *
 * Everything in em: the projector's shrink loop only writes
 * --scr-stage-fs. The frame `ref="stage"` stays in Screen.vue.
 */
import { raceRows, RACE_BOARD_ROWS } from '../util/pace.js'
import { fmtNum } from '../util/format.js'
import StageRow from './StageRow.vue'
import Leaderboard from './Leaderboard.vue'
import QrCode from './QrCode.vue'

// From this many rows in the tallest column the rows move closer: twelve
// rows (not started + ten + finished) must fit above the 24 px shrink limit
// even in the 1280×720 kiosk (PaceRun preview, PowerPoint).
const DENSE_ROWS = 8

export default {
	name: 'StageRace',
	components: { StageRow, Leaderboard, QrCode },
	props: {
		// `race` from /state?spectate=1: {n, joined, started, finished, onQuestion}
		race: { type: Object, required: true },
		// Leaders with points > 0 (open only), at most eight rows
		board: { type: Array, default: () => [] },
		// 'join' | 'board' | '' — right column
		side: { type: String, default: '' },
		joinUrl: { type: String, default: '' },
		joinUrlFull: { type: String, default: '' },
		// Room code, already grouped ("ABC DEF")
		code: { type: String, default: '' },
		// From the projector: the airy layout does not fit even at the shrink limit.
		tight: { type: Boolean, default: false },
		// From the projector: leaderboard rows — fewer when even tight rows do not fit.
		boardRows: { type: Number, default: RACE_BOARD_ROWS },
	},
	computed: {
		// The component only appears in the open window — "Not started" belongs to it.
		layout() {
			return raceRows(this.race, true)
		},
		idle() {
			return this.layout.rows.find((r) => r.kind === 'idle') || null
		},
		steps() {
			return this.layout.rows.filter((r) => r.kind === 'q' || r.kind === 'group')
		},
		finished() {
			const row = this.layout.rows.find((r) => r.kind === 'done')
			return row ? row.value : 0
		},
		joined() {
			return Math.max(1, Number(this.race.joined) || 0)
		},
		// Rows in the tallest column: "Not started" + questions per column + "Finished".
		// Also dense when the projector asks for it (small kiosk, embed frame).
		dense() {
			if (this.tight) return true
			const perCol = this.layout.cols === 2 ? this.layout.perCol : this.steps.length
			return (this.idle ? 1 : 0) + perCol + 1 > DENSE_ROWS
		},
	},
	methods: {
		labelOf(row) {
			return row.kind === 'group'
				? this.t('pulse', 'Questions {from}–{to}', { from: row.from, to: row.to })
				: this.t('pulse', 'Question {number}', { number: row.k })
		},
		num(v) {
			return fmtNum(v, 0)
		},
		// Every bar relative to "joined"; together 100 % (see above).
		pct(v) {
			return Math.min(100, (Number(v) || 0) / this.joined * 100)
		},
		// For the projector's shrink loop (Screen.vue fitTight): leaderboard rows
		// shown, how much taller the leaderboard is than the bars, row pitch.
		boardMeasure() {
			const board = this.$refs.board
			const bars = this.$refs.bars
			const rows = board ? board.querySelectorAll('.lb-row') : []
			if (!board || !bars || rows.length < 2) return { shown: rows.length, excess: 0, pitch: 0 }
			return {
				shown: rows.length,
				excess: board.getBoundingClientRect().height - bars.getBoundingClientRect().height,
				pitch: rows[1].getBoundingClientRect().top - rows[0].getBoundingClientRect().top,
			}
		},
	},
}
</script>

<style scoped>
/* One column without a side block, otherwise bars on the left (1.35) and joining or
   leaderboard on the right (1), both vertically centred on the stage — "safe": if it
   does overflow, something is missing at the bottom, not at top and bottom at once. */
.pace-race {
	flex: 1 1 auto;
	min-height: 0;
	display: grid;
	grid-template-columns: minmax(0, 1fr);
	gap: 1.4em;
	align-items: safe center;
	--race-h: 1.9em;
	--race-gap: 0.4em;
}
.pace-race.has-side { grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr); }
.pace-race > * { min-width: 0; min-height: 0; }
/* Many rows: tighter and labelled a notch smaller, so that twelve rows
   stay above the shrink limit even in the small kiosk. */
.pace-race.is-dense { --race-h: 1.3em; --race-gap: 0.25em; }
.pace-race.is-dense .pace-race-row :deep(.srow-text) { font-size: 0.85em; }

.pace-race-rows { gap: var(--race-gap); }
/* No .srows--stretch (trap: height:100% spread over the neighbours) —
   fixed row height, the loop shrinks the font. */
.pace-race-rows > .srow,
.pace-race-grid > .srow { flex: 0 0 auto; height: var(--race-h); }
/* 11–20 questions: two columns, the left one top to bottom first. Without
   grid-template-rows, grid-auto-flow: column would produce a single
   row with n columns. */
.pace-race-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	grid-auto-flow: column;
	grid-template-rows: repeat(var(--rows), auto);
	gap: var(--race-gap) 1em;
	min-width: 0;
}
.pace-race-grid > * { min-width: 0; }

/* ── Right column ──────────────────────────────────────────────── */
.pace-race-side { display: flex; flex-direction: column; min-width: 0; }
.pace-race-side-head { margin: 0 0 0.35em; font-weight: 800; line-height: 1.15; }

/* Joining: QR on a white card (scannability, also on a dark projector), code
   grouped, short URL. */
.pace-race-join { align-items: center; gap: 0.4em; text-align: center; }
.pace-race-qr {
	width: 7em; box-sizing: border-box;
	background: #fff; padding: 0.3em;
	border: 3px solid var(--pulse-border-strong); border-radius: var(--pulse-r-card);
	box-shadow: var(--pulse-shadow-pop);
}
.pace-race-pin {
	margin: 0.2em 0 0; padding: 0.05em 0.45em;
	background: var(--pulse-primary); color: var(--pulse-on-primary);
	border-radius: var(--pulse-r-card);
	font-weight: 900; font-size: 1.5em; line-height: 1.2; letter-spacing: 0.1em;
	font-variant-numeric: tabular-nums; white-space: nowrap;
}
/* Secondary-text grey instead of --pulse-readout: that one would stay #33465e
   on the public page (data-themes="") even in the dark. */
.pace-race-url { margin: 0; max-width: 100%; font-size: 0.6em; font-weight: 700; color: var(--pulse-text-2); word-break: break-all; }

/* Leaders: eight leaderboard rows, tighter than on the phone — they share the
   height with the bars next to them. */
.pace-race-board :deep(.lb-list) { font-size: 0.8em; }
.pace-race-board :deep(.lb-row) { padding: 0.2em 0.6em; margin-bottom: 0.25em; }
</style>
