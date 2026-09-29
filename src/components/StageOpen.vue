<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="sopen">
		<p v-if="hint" class="sopen-hint">{{ hint }}</p>

		<!-- Optionen, Elemente, Aspekte: dieselbe Zeile wie die Auflösung,
		     aber als Umriss und ohne jeden Wert. -->
		<div v-if="rows.length" class="srows srows--stretch" :class="{ 'is-tall': tall }">
			<StageRow v-for="r in rows" :key="r.id" :class="r.cls"
				:badge="r.badge" :label="r.label" :tall="tall" outline />
		</div>

		<!-- Skala einzel: die Achse mit beiden Polen — ablesbar, was am Handy
		     zu tun ist, ohne die Verteilung zu verraten. -->
		<div v-else-if="scaleMode === 'single'" class="sopen-scale">
			<ol class="sopen-ticks">
				<li v-for="v in ticks" :key="v" class="sopen-tick">{{ v }}</li>
			</ol>
			<div v-if="scale.minLabel || scale.maxLabel" class="sopen-poles">
				<span>{{ scale.minLabel }}</span>
				<span>{{ scale.maxLabel }}</span>
			</div>
		</div>

		<!-- Kompass: das Achsenkreuz ist Orientierung. Sobald ein Punkt darauf
		     liegt, ist die Verteilung verraten — also keiner. -->
		<div v-else-if="scaleMode === 'compass'" class="sopen-compass">
			<span class="cp-pole cp-top">{{ axisY.poleHigh }}</span>
			<span class="cp-pole cp-left">{{ axisX.poleLow }}</span>
			<div class="cp-field" aria-hidden="true" />
			<span class="cp-pole cp-right">{{ axisX.poleHigh }}</span>
			<span class="cp-pole cp-bottom">{{ axisY.poleLow }}</span>
		</div>

		<!-- Zuordnung: Zeilen oben, Ziele als Chips darunter. Elemente und Ziele
		     nie als zwei gleich hohe Spalten nebeneinander — die läsen sich
		     zeilenweise als Lösung. Die Elemente unter sich dürfen umbrechen. -->
		<div v-else-if="poll.type === 'match'" class="sopen-match">
			<div class="srows srows--stretch" :class="{ 'is-cols': matchCols }"
				:style="matchCols ? { '--rows': Math.ceil(matchItems.length / 2) } : null">
				<StageRow v-for="item in matchItems" :key="item.id" :label="item.label" outline />
			</div>
			<ul class="sopen-chips">
				<li v-for="target in matchTargets" :key="target.id" class="sopen-chip">{{ target.label }}</li>
			</ul>
		</div>
	</div>
</template>

<script>
/*
 * Inhalt der offenen Bühne (Redesign §6.3).
 *
 * Regel für alle Typen: die Antwortmöglichkeiten sind lesbar, Werte nicht. Wer
 * den Blick hebt, soll ablesen können, was am Handy zu tun ist — ohne dass die
 * Leinwand das Ergebnis vorwegnimmt (E1/E4).
 */
import { withPalette } from '../util/palette.js'
import StageRow from './StageRow.vue'

const TALL_LABEL_CHARS = 34

export default {
	name: 'StageOpen',
	components: { StageRow },
	props: {
		poll: { type: Object, required: true },
	},
	computed: {
		scale() {
			return this.poll.scale || {}
		},
		scaleMode() {
			return this.poll.type === 'scale' ? (this.scale.mode || 'single') : ''
		},
		axisX() {
			return this.scale.axisX || {}
		},
		axisY() {
			return this.scale.axisY || {}
		},
		ticks() {
			const min = Number(this.scale.min ?? 1)
			const max = Number(this.scale.max ?? 5)
			const out = []
			for (let v = min; v <= max; v++) out.push(v)
			return out
		},
		// Zeilen der offenen Bühne: Optionen mit Palette, Reihenfolge-Elemente
		// ohne Nummer (eine Nummerierung behauptete eine Reihenfolge, bevor
		// abgestimmt wurde), Spektrum-Aspekte als Namensliste.
		rows() {
			const p = this.poll
			if (['choice', 'multi', 'truefalse'].includes(p.type) && Array.isArray(p.options)) {
				return withPalette(p.options).map((o) => ({ id: o.id, label: o.label, badge: o.pal.letter, cls: o.pal.cls }))
			}
			if (p.type === 'rank' && Array.isArray(p.options)) {
				return p.options.map((o) => ({ id: o.id, label: o.label, badge: '', cls: '' }))
			}
			if (this.scaleMode === 'spectrum') {
				return (this.scale.aspects || []).map((a) => ({ id: a.id, label: a.label, badge: '', cls: '' }))
			}
			return []
		},
		tall() {
			return this.rows.some((r) => (r.label || '').length > TALL_LABEL_CHARS)
		},
		matchItems() {
			return (this.poll.match && this.poll.match.items) || []
		},
		// Ab sechs Paaren steht die Zeilenliste zweispaltig (§Zuordnung R2).
		matchCols() {
			return this.matchItems.length > 5
		},
		matchTargets() {
			const targets = ((this.poll.match && this.poll.match.targets) || []).slice()
			return targets.sort((a, b) => a.label.localeCompare(b.label))
		},
		hint() {
			if (this.poll.type === 'multi') return this.t('pulse', 'Multiple answers possible')
			if (this.poll.type === 'rank') return this.t('pulse', 'Sort on your phone')
			if (this.poll.type === 'match') return this.t('pulse', 'Assign on your phone')
			return ''
		},
	},
}
</script>

<style scoped>
.sopen { display: flex; flex-direction: column; min-height: 0; min-width: 0; gap: 0.4em; }
.sopen > .srows, .sopen-match, .sopen-scale, .sopen-compass { flex: 1 1 auto; min-height: 0; }
/* .srows--stretch trägt height:100 % — richtig, wenn die Zeilenliste die Bühne
   allein füllt. Hier ist sie EIN Kind unter mehreren: der Hinweis steht darüber,
   bei der Zuordnung die Chips darunter. 100 % der Elternhöhe hieß, sich über
   beide zu legen, und genau das tat sie (der Hinweis lag im ersten Kasten, die
   Chips im vorletzten). Die Höhe kommt hier aus dem Flex. */
.sopen > .srows, .sopen-match > .srows { height: auto; }
.sopen-hint { flex: 0 0 auto; margin: 0; color: var(--pulse-meta); font-weight: 600; font-size: 0.6em; }

/* Skala einzel: Achse mit Werten und beiden Polen. */
.sopen-scale { display: flex; flex-direction: column; justify-content: center; gap: 0.5em; }
.sopen-ticks {
	list-style: none; margin: 0; padding: 0.4em 0 0;
	display: flex; justify-content: space-between; align-items: center;
	border-top: 0.08em solid var(--pulse-border-strong);
}
.sopen-tick { font-weight: 700; font-variant-numeric: tabular-nums; }
.sopen-poles { display: flex; justify-content: space-between; gap: 1em; color: var(--pulse-meta); font-weight: 600; }
.sopen-poles span { max-width: 45%; }
.sopen-poles span:last-child { text-align: right; }

/* Kompass: Achsenkreuz ohne einen einzigen Punkt. */
.sopen-compass {
	display: grid; place-items: center;
	grid-template-columns: auto 1fr auto;
	grid-template-rows: auto 1fr auto;
	gap: 0.3em;
}
.cp-pole { color: var(--pulse-text); font-weight: 700; text-align: center; }
.cp-top { grid-column: 2; grid-row: 1; }
.cp-left { grid-column: 1; grid-row: 2; }
.cp-right { grid-column: 3; grid-row: 2; }
.cp-bottom { grid-column: 2; grid-row: 3; }
.cp-field {
	grid-column: 2; grid-row: 2;
	position: relative; aspect-ratio: 1 / 1; height: 100%; max-width: 100%;
	border: 0.06em solid var(--pulse-border-strong); border-radius: 0.2em;
}
.cp-field::before, .cp-field::after { content: ''; position: absolute; background: var(--pulse-border-strong); }
.cp-field::before { left: 0; right: 0; top: 50%; height: 0.05em; transform: translateY(-50%); }
.cp-field::after { top: 0; bottom: 0; left: 50%; width: 0.05em; transform: translateX(-50%); }

/* Zuordnung: Zeilen, darunter die Ziele als lose Chips. */
.sopen-match { display: flex; flex-direction: column; gap: 0.6em; }
.sopen-match > .srows { flex: 1 1 auto; min-height: 0; }
/* Ab sechs Paaren zweispaltig — dieselbe Schwelle wie bei der Auflösung. Acht
   volle Bühnenbreiten für je ein Wort („DNS") stehen zu lassen und dafür die
   Schrift zu schrumpfen, ist die falsche Reihenfolge. Die Spalten laufen von
   oben nach unten, damit die Reihenfolge der Frage erhalten bleibt. */
.sopen-match > .srows.is-cols {
	display: grid; grid-auto-flow: column;
	grid-template-columns: 1fr 1fr;
	grid-template-rows: repeat(var(--rows, 4), minmax(0, 1fr));
	gap: 0.4em 1.4em;
}
/* Im Raster teilen die Spuren die Höhe unter sich auf. Die Mindesthöhe der
   Zeile stammt aus der einspaltigen Bühne (1,9 em) und ist hier zu viel: vier
   Spuren plus Chips plus Eingang überschritten die Bühne, und die Zeilen liefen
   in ihre Abstände. */
.sopen-match > .srows.is-cols > .srow { min-height: 0; max-height: none; }
.sopen-chips { flex: 0 0 auto; list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 0.35em; }
.sopen-chip { background: var(--pulse-bar-track); color: var(--pulse-text); border-radius: 999px; padding: 0.2em 0.7em; font-weight: 700; font-size: 0.8em; }
</style>
