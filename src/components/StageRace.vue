<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pace-race" :class="{ 'has-side': !!side, 'is-dense': dense }">
		<!-- Links: wo die Leute gerade sind. Oben, wer noch nicht gestartet hat,
		     je Frage eine Zeile, unten, wer durch ist. Ab elf Fragen zwei
		     Spalten, ab 21 Gruppen — „Finished" steht immer voll breit darunter. -->
		<div class="srows pace-race-rows">
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

		<!-- Rechts in den ersten zwei Minuten (und solange niemand Punkte hat) der
		     Beitritt: im Klassenraum kommen noch Leute dazu, und die Mini-QR in
		     der Meta-Leiste allein reicht dafür nicht. Danach die Spitze. -->
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
		<aside v-else-if="side === 'board'" class="pace-race-side pace-race-board">
			<p class="pace-race-side-head">
				{{ t('pulse', 'Leaderboard') }}
			</p>
			<Leaderboard :rows="board" :limit="8" />
		</aside>
	</div>
</template>

<script>
/*
 * Beamer im eigenen Tempo: das Rennen (Spezifikation §3.3).
 *
 * Nur Zahlen — nie Fragetext, Optionen oder Verteilungen; die Komponente
 * bekommt keine Frage-Daten. Jede Person steht in genau einer Zeile (der
 * Server zählt, wer fertig ist, nicht mehr auf seiner letzten Frage):
 * „Not started“ + Fragen + „Finished“ = beigetreten. Jeder Balken ist auf
 * „beigetreten“ bezogen, zusammen ergeben sie 100 % — daher die Legende
 * „100% = everyone who joined“ in der Meta-Leiste des Beamers.
 *
 * Alles in em: die Schrumpf-Schleife des Beamers schreibt nur
 * --scr-stage-fs. Der Rahmen `ref="stage"` bleibt in Screen.vue.
 */
import { raceRows } from '../util/pace.js'
import { fmtNum } from '../util/format.js'
import StageRow from './StageRow.vue'
import Leaderboard from './Leaderboard.vue'
import QrCode from './QrCode.vue'

// Ab so vielen Zeilen in der höchsten Spalte rücken die Zeilen enger: zwölf
// Zeilen (nicht gestartet + zehn + fertig) müssen auch im 1280×720-Kiosk
// (PaceRun-Vorschau, PowerPoint) über der Schrumpf-Grenze von 24 px passen.
const DENSE_ROWS = 8

export default {
	name: 'StageRace',
	components: { StageRow, Leaderboard, QrCode },
	props: {
		// `race` aus /state?spectate=1: {n, joined, started, finished, onQuestion}
		race: { type: Object, required: true },
		// Spitze mit Punkten > 0 (nur offen), höchstens acht Zeilen
		board: { type: Array, default: () => [] },
		// 'join' | 'board' | '' — rechte Spalte
		side: { type: String, default: '' },
		joinUrl: { type: String, default: '' },
		joinUrlFull: { type: String, default: '' },
		// Raumcode, schon gruppiert („ABC DEF")
		code: { type: String, default: '' },
		// Vom Beamer: luftig passt es auch auf der Schrumpf-Grenze nicht.
		tight: { type: Boolean, default: false },
	},
	computed: {
		// Die Komponente steht nur im offenen Fenster — „Not started" gehört dazu.
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
		// Zeilen in der höchsten Spalte: „Not started" + Fragen je Spalte + „Finished".
		// Eng auch, wenn der Beamer es verlangt (kleiner Kiosk, Einbett-Rahmen).
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
		// Jeder Balken auf „beigetreten“ bezogen; zusammen 100 % (s. oben).
		pct(v) {
			return Math.min(100, (Number(v) || 0) / this.joined * 100)
		},
	},
}
</script>

<style scoped>
/* Eine Spalte ohne Seitenblock, sonst Balken links (1,35) und Beitritt bzw.
   Rangliste rechts (1), beide senkrecht mittig auf der Bühne — „safe": läuft
   es doch über, fehlt unten etwas, nicht oben und unten zugleich. */
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
/* Viele Zeilen: enger und eine Spur kleiner beschriftet, damit zwölf Zeilen
   auch im kleinen Kiosk über der Schrumpf-Grenze bleiben. */
.pace-race.is-dense { --race-h: 1.3em; --race-gap: 0.25em; }
.pace-race.is-dense .pace-race-row :deep(.srow-text) { font-size: 0.85em; }

.pace-race-rows { gap: var(--race-gap); }
/* Kein .srows--stretch (Falle: height:100% legte sich über die Nachbarn) —
   feste Zeilenhöhe, die Schleife schrumpft die Schrift. */
.pace-race-rows > .srow,
.pace-race-grid > .srow { flex: 0 0 auto; height: var(--race-h); }
/* 11–20 Fragen: zwei Spalten, erst die linke von oben nach unten. Ohne
   grid-template-rows entstünde bei grid-auto-flow: column eine einzige
   Zeile mit n Spalten. */
.pace-race-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	grid-auto-flow: column;
	grid-template-rows: repeat(var(--rows), auto);
	gap: var(--race-gap) 1em;
	min-width: 0;
}
.pace-race-grid > * { min-width: 0; }

/* ── Rechte Spalte ─────────────────────────────────────────────── */
.pace-race-side { display: flex; flex-direction: column; min-width: 0; }
.pace-race-side-head { margin: 0 0 0.35em; font-weight: 800; line-height: 1.15; }

/* Beitritt: QR auf weißer Karte (Scanbarkeit, auch auf dunklem Beamer), Code
   gruppiert, Kurz-URL. */
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
/* Nebentext-Grau statt --pulse-readout: das bliebe auf der öffentlichen Seite
   (data-themes="") auch im Dunkeln #33465e. */
.pace-race-url { margin: 0; max-width: 100%; font-size: 0.6em; font-weight: 700; color: var(--pulse-text-2); word-break: break-all; }

/* Spitze: acht Zeilen der Rangliste, enger als am Handy — sie teilen sich die
   Höhe mit den Balken daneben. */
.pace-race-board :deep(.lb-list) { font-size: 0.8em; }
.pace-race-board :deep(.lb-row) { padding: 0.2em 0.6em; margin-bottom: 0.25em; }
</style>
