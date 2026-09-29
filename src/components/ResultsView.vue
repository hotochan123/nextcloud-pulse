<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pulse-results">
		<!-- Multiple Choice / Mehrfachauswahl: Balken auf Gesamtstimmen normiert,
		     Optionsfarbe von der Live-Kachel durchgezogen (Palette-Brücke).
		     Am Beamer (wide) trägt eine Zeile alles — Plakette, Label und Wert
		     liegen IM Balken (§2.4); die Legende steht in der Meta-Leiste. -->
		<div v-if="results && (results.type === 'choice' || results.type === 'multi')" class="bars" :class="{ 'bars--stage': wide }">
			<div v-if="wide" class="srows srows--stretch" :class="{ 'is-tall': tallLabels }">
				<StageRow v-for="row in choiceRows" :key="row.id" :class="row.pal.cls"
					:badge="row.pal.letter" :label="row.label" :tall="tallLabels"
					:value="row.count + ' · ' + percent(row.count) + '%'"
					:pct="percent(row.count)"
					:state="choiceState(row)" :flag="choiceFlag(row)" />
			</div>
			<div v-for="row in (wide ? [] : choiceRows)" :key="row.id" class="bar-row" :class="[row.pal.cls, { 'is-correct': isCorrect(row.id), 'is-wrong': hasCorrect && !isCorrect(row.id), 'is-winner': !hasCorrect && isWinner(row.count) }]">
				<div class="bar-head">
					<span class="bar-label">
						<span class="pulse-badge bar-badge">{{ row.pal.letter }}</span>
						<span class="bar-text">{{ row.label }}</span>
						<span v-if="isCorrect(row.id)" class="bar-flag is-correct"><PulseIcon name="check" size="1em" /> {{ t('pulse', 'correct') }}</span>
						<span v-else-if="!hasCorrect && isWinner(row.count)" class="bar-flag is-winner"><PulseIcon name="check" size="1em" /> {{ t('pulse', 'leading') }}</span>
					</span>
					<span class="bar-count">{{ row.count }} · {{ percent(row.count) }}%</span>
				</div>
				<div class="bar-track"><div class="bar-fill" :style="{ width: percent(row.count) + '%' }" /></div>
			</div>
			<p v-if="!wide" class="norm-note">{{ results.type === 'multi' ? t('pulse', '100% = everyone who answered · the sum can exceed 100%') : t('pulse', '100% = all votes') }}</p>
		</div>

		<!-- Reihenfolge: Konsens-Rangliste. Balken = wie weit oben im Schnitt
		     (voll = Ø-Platz 1). Im Quiz steht daneben der richtige Platz. -->
		<div v-else-if="results && results.type === 'rank'" class="ranks" :class="{ 'bars--stage': wide }">
			<!-- Beamer: die Zeile trägt die VERTEILUNG der Plätze, nicht den
			     Durchschnitt (§7.5). Die Balkenlänge ist immer 100 % — sie
			     transportierte vorher Unterschiede von 0,3 Plätzen als volle
			     Längendifferenz und verschwieg dabei, ob ein Element unstrittig
			     ist oder polarisiert. -->
			<div v-if="wide" class="sstacks" :class="{ 'is-longhead': longHeads }">
				<StageStack v-for="(row, i) in results.results" :key="row.id"
					:rank="i + 1" :label="row.label"
					:segments="rankSegments(row)"
					:value="row.n ? 'Ø ' + num(row.average) : '—'"
					:aria-label="rankAria(row)" />
			</div>
			<div v-for="(row, i) in (wide ? [] : results.results)" :key="row.id" class="rank-row" :class="{ 'is-correct': rankCorrect(row.id, i), 'is-wrong': hasRankKey && !rankCorrect(row.id, i) }">
				<div class="bar-head">
					<span class="bar-label">
						<span class="pulse-badge bar-badge">{{ i + 1 }}</span>
						<span class="bar-text">{{ row.label }}</span>
						<span v-if="hasRankKey" class="bar-flag" :class="rankCorrect(row.id, i) ? 'is-correct' : 'is-wrong'">
							<PulseIcon :name="rankCorrect(row.id, i) ? 'check' : 'close'" size="1em" /> {{ t('pulse', 'place {place}', { place: rankTarget(row.id) }) }}
						</span>
					</span>
					<span class="bar-count">{{ t('pulse', 'avg. {value}', { value: row.n ? num(row.average) : '—' }) }}<span v-if="row.first" class="rank-first"> · {{ t('pulse', '{count}× first place', { count: row.first }) }}</span></span>
				</div>
				<div class="bar-track"><div class="bar-fill" :style="{ width: rankPct(row) + '%' }" /></div>
			</div>
			<p v-if="!wide" class="norm-note">{{ t('pulse', 'Sorted by average place · a full bar means top on average') }}</p>
		</div>

		<!-- Zuordnung: je Zeile die Verteilung über die Ziele. Im Quiz ist das
		     richtige Ziel grün markiert, in der Umfrage das meistgewählte. Am
		     Beamer (wide) steht nur die Spitze je Zeile, ab fünf Zeilen zweispaltig:
		     acht Paare mal acht Ziele wären 64 Balken auf einer Leinwand. -->
		<div v-else-if="results && results.type === 'match'" class="matchres" :class="{ 'is-wide': wide, 'is-dense': dense, 'is-cols': !wide && !dense && results.results.length > 4 }">
			<!-- Beamer: eine gestapelte Zeile je Paar zeigt die volle Verteilung
			     über die Ziele (§7.6). Vorher stand dort nur die Spitze — die
			     Zeile behauptete Einigkeit, wo eine knappe Mehrheit war. -->
			<div v-if="wide" class="sstacks" :class="{ 'is-longhead': longHeads }">
				<StageStack v-for="row in results.results" :key="row.id"
					:label="row.label"
					:segments="matchSegments(row)"
					:value="matchLead(row)"
					:aria-label="matchAria(row)" />
			</div>
			<div v-for="row in (wide ? [] : results.results)" :key="row.id" class="mres-row">
				<div class="mres-head">
					<span class="mres-item">{{ row.label }}</span>
					<span v-if="hasMatchKey" class="bar-flag is-correct">
						<PulseIcon name="check" size="1em" /> {{ matchTargetLabel(matchKeyOf(row.id)) }}
					</span>
					<span v-if="dense" class="mres-lead">{{ matchLead(row) }}</span>
				</div>
				<StageStack v-if="dense && row.n" :segments="matchSegments(row)" :aria-label="matchAria(row)" />
				<div v-else-if="row.n" class="bars bars--match">
					<div v-for="cell in matchCells(row)" :key="cell.id" class="bar-row"
						:class="{ 'is-correct': matchIsCorrect(row, cell), 'is-wrong': hasMatchKey && !matchIsCorrect(row, cell), 'is-winner': !hasMatchKey && cell.id === row.top }">
						<div class="bar-head">
							<span class="bar-label"><span class="bar-text">{{ cell.label }}</span></span>
							<span class="bar-count">{{ cell.count }} · {{ matchPercent(cell.count, row.n) }}%</span>
						</div>
						<div class="bar-track"><div class="bar-fill" :style="{ width: matchPercent(cell.count, row.n) + '%' }" /></div>
					</div>
				</div>
				<p v-else class="norm-note">{{ t('pulse', 'No assignment yet.') }}</p>
			</div>
			<p v-if="dense" class="norm-note">{{ hasMatchKey ? t('pulse', 'Segment = share per target · ring marks the correct target') : t('pulse', 'Segment = share per target') }}</p>
			<p v-else-if="!wide" class="norm-note">{{ hasMatchKey ? t('pulse', '100% = all answers in this line · green is the correct target') : t('pulse', '100% = all answers in this line') }}</p>
		</div>

		<!-- Schätzfrage: Zielzahl + Ø + Verteilung der Tipps (Histogramm) -->
		<div v-else-if="results && results.type === 'number'" class="dist" :class="{ 'bars--stage': wide }">
			<!-- Beamer: die drei Kennzahlen als EINE Zeile. Als Block nahmen sie
			     ein Drittel der Bühnenhöhe, das dann der Verteilung fehlte. -->
			<div v-if="wide" class="numline">
				<span class="numline-target">{{ numTarget }}</span>
				<span class="numline-lbl">{{ t('pulse', 'correct number') }}{{ numTol ? ' (± ' + numTol + ')' : '' }}</span>
				<span class="numline-sep" aria-hidden="true">·</span>
				<span class="numline-lbl">{{ t('pulse', '{count} correct', { count: results.correct }) }}</span>
				<template v-if="numAvg !== null">
					<span class="numline-sep" aria-hidden="true">·</span>
					<span class="numline-val">Ø {{ num(numAvg) }}</span>
				</template>
				<template v-if="scaleMedian !== null">
					<span class="numline-sep" aria-hidden="true">·</span>
					<span class="numline-val">{{ t('pulse', 'median {value}', { value: num(scaleMedian) }) }}</span>
				</template>
			</div>
			<div v-if="!wide" class="dist-metrics">
				<div class="metric metric--target">
					<span class="metric-val">{{ numTarget }}</span>
					<span class="metric-label">{{ t('pulse', 'correct number') }}{{ numTol ? ' (± ' + numTol + ')' : '' }} · {{ t('pulse', '{count} correct', { count: results.correct }) }}</span>
				</div>
				<div v-if="numAvg !== null" class="metric">
					<span class="metric-val metric-val--sub">{{ num(numAvg) }}</span>
					<span class="metric-label">{{ t('pulse', 'avg. of the guesses') }}</span>
				</div>
				<div v-if="scaleMedian !== null" class="metric">
					<span class="metric-val metric-val--sub">{{ num(scaleMedian) }}</span>
					<span class="metric-label">{{ t('pulse', 'Median') }}</span>
				</div>
			</div>
			<!-- Am Beamer trägt auch die Tipp-Verteilung das Zeilenmodell aus §2.4:
			     die alte Zwei-Zeilen-Grammatik passte bei fünf Werten selbst am
			     Boden der Schrumpf-Schleife nicht mehr auf die Bühne. -->
			<div v-if="wide && results.results.length" class="srows srows--stretch">
				<StageRow v-for="row in results.results" :key="row.value"
					:label="String(row.value)" :value="row.count + '×'"
					:pct="scalePercent(row.count)"
					:state="numWithin(row.value) ? 'correct' : ''"
					:flag="numWithin(row.value) ? t('pulse', 'correct') : ''" />
			</div>
			<div v-else-if="results.results.length" class="bars bars--dist">
				<div v-for="row in results.results" :key="row.value" class="bar-row" :class="{ 'is-correct': numWithin(row.value) }">
					<div class="bar-head">
						<span class="bar-label"><span v-if="numWithin(row.value)" class="bar-flag is-correct"><PulseIcon name="check" size="1em" /></span>{{ row.value }}</span>
						<span class="bar-count">{{ row.count }}×</span>
					</div>
					<div class="bar-track"><div class="bar-fill" :style="{ width: scalePercent(row.count) + '%' }" /></div>
				</div>
			</div>
			<p v-if="!wide" class="norm-note">{{ t('pulse', 'Distribution of the guesses · bars relative to the most frequent value') }}</p>
		</div>

		<!-- Freitext: EIN geteilter Baustein — read-only Variante von TextGrading -->
		<TextGrading v-else-if="results && results.type === 'text'" :results="results" readonly />

		<!-- Wortwolke: kompakte Vorschau (die Live-Engine läuft auf dem Beamer) -->
		<div v-else-if="results && results.type === 'words'" class="cloud">
			<span v-for="row in results.results" :key="row.word" class="cloud-word"
				:style="{ fontSize: cloudEm(row.count) + 'em', opacity: cloudOpacity(row.count) }">
				{{ row.word }}
			</span>
			<p v-if="!results.results.length" class="cloud-empty">{{ t('pulse', 'No words yet.') }}</p>
		</div>

		<!-- Skala · Spektrum: Radar (Ø-Kontur + Streuband + Neutral-Ring + „Du") — nummerierte Speichen + Kennzahl-Spalte, SVG-viewBox skaliert Handy→Beamer -->
		<div v-else-if="results && results.type === 'scale' && spectrumMode" class="spectrum-radar" :class="{ 'is-wide': wide }">
			<VizEmpty v-if="!total" />
			<!-- Beamer: Namen an den Speichen, kein Ziffern-Nachschlag (§7.3).
			     Unter drei Aspekten wird keine Fläche aufgespannt — dann sind es
			     Zeilen statt eines Radars. Der Editor lässt heute nur 3 bis 8 zu,
			     der Zweig ist die ehrliche Notlage statt einer Fehlermeldung. -->
			<div v-else-if="wide && spectrumRows.length < 3" class="srows srows--stretch">
				<StageRow v-for="row in spectrumRows" :key="row.id" :class="row.pal.cls"
					:badge="row.pal.letter" :label="row.label"
					:value="'Ø ' + num(row.average)"
					:pct="spectrumPct(row)" />
			</div>
			<StageRadar v-else-if="wide"
				:rows="spectrumRows" :max="results.max" :aria-label="radarAria" />
			<template v-else-if="radar">
				<div class="radar-main">
					<div class="radar-wrap">
						<svg viewBox="0 0 440 440" role="img" :aria-label="radarAria">
							<circle v-for="(r, i) in radar.rings" :key="'g' + i" :cx="radar.cx" :cy="radar.cy" :r="r" style="fill:none;stroke:var(--pulse-border)" />
							<circle :cx="radar.cx" :cy="radar.cy" :r="radar.neutral" style="fill:none;stroke:var(--pulse-text-2);stroke-dasharray:4 5;stroke-width:1.5" />
							<circle :cx="radar.cx" :cy="radar.cy" :r="radar.R" style="fill:none;stroke:var(--pulse-border-strong);stroke-width:1.75" />
							<line v-for="(p, i) in radar.spokes" :key="'s' + i" :x1="radar.cx" :y1="radar.cy" :x2="p[0]" :y2="p[1]" style="stroke:var(--pulse-border)" />
							<path :d="radar.band" style="fill:var(--pulse-viz-2);fill-opacity:.45;fill-rule:evenodd;stroke:none" />
							<polygon :points="radar.avgPoints" style="fill:var(--pulse-viz);fill-opacity:.12;stroke:var(--pulse-viz);stroke-width:3.5;stroke-linejoin:round" />
							<polygon v-if="radar.minePoints" :points="radar.minePoints" style="fill:none;stroke:var(--pulse-self);stroke-width:2.5;stroke-dasharray:2 4;stroke-linejoin:round" />
							<circle v-for="(nm, i) in radar.nums" :key="'d' + i" :cx="nm.dot[0]" :cy="nm.dot[1]" r="5" style="fill:var(--pulse-viz);stroke:var(--pulse-bg);stroke-width:2" />
							<g v-for="(nm, i) in radar.nums" :key="'n' + i">
								<circle :cx="nm.x" :cy="nm.y" r="14" class="rnum-bg" />
								<text :x="nm.x" :y="nm.y + 5" text-anchor="middle" class="rnum">{{ nm.n }}</text>
							</g>
							<circle :cx="radar.cx" :cy="radar.cy" r="2.5" style="fill:var(--pulse-text-2)" />
						</svg>
					</div>
					<ol class="viz-col">
						<li v-for="it in radarItems" :key="it.n" class="viz-item">
							<span class="viz-num">{{ it.n }}</span>
							<span class="viz-label">{{ it.label }}</span>
							<span class="viz-val">{{ t('pulse', 'avg. {value}', { value: it.avg }) }}</span>
						</li>
					</ol>
				</div>
				<div class="rlegend">
					<span><span class="rsw rsw-avg" /> {{ t('pulse', 'Average') }}</span>
					<span><span class="rsw rsw-band" /> {{ t('pulse', 'Spread (min–max)') }}</span>
					<span><span class="rsw rsw-neutral" /> {{ t('pulse', 'Neutral ({value})', { value: neutralLabel }) }}</span>
					<span v-if="radar.minePoints"><span class="rsw rsw-mine" /> {{ t('pulse', 'You') }}</span>
				</div>
			</template>
			<p v-else class="norm-note">{{ t('pulse', 'Too few aspects for a radar (at least 3).') }}</p>
		</div>

		<!-- Skala · Kompass: Quadrant Scatter/Heatmap + Schwerpunkt + „Du" — Chart + Kennzahl-Spalte, SVG-viewBox skaliert Handy→Beamer -->
		<div v-else-if="results && results.type === 'scale' && compassMode" class="compass-result" :class="{ 'is-wide': wide }">
			<VizEmpty v-if="!total" />
			<!-- Beamer: quadratisches Feld über die volle Bühnenhöhe, Punkte bis
			     zur Schwelle, darüber Heatmap. Kein „Du" — auf der Leinwand gibt
			     es kein Ich (§7.4). -->
			<StageCompass v-else-if="wide"
				:points="results.points || []" :centroid="results.centroid"
				:range="results.range || 5" :threshold="results.heatmapThreshold || 45"
				:axis-x="results.axisX || {}" :axis-y="results.axisY || {}"
				:corner-labels="results.cornerLabels || []" :aria-label="compassAria" />
			<template v-else-if="compass">
				<div class="compass-main">
					<div class="compass-wrap">
						<svg viewBox="0 0 460 440" role="img" :aria-label="compassAria">
							<line v-for="(l, i) in compass.grid" :key="'cg' + i" :x1="l.x1" :y1="l.y1" :x2="l.x2" :y2="l.y2" style="stroke:var(--pulse-border);stroke-width:1" />
							<rect v-for="(c, i) in compass.cells" :key="'hc' + i" :x="c.x" :y="c.y" :width="c.w" :height="c.h" :style="{ fill: 'var(--pulse-viz)', fillOpacity: c.op }" />
							<circle v-for="(d, i) in compass.dots" :key="'sd' + i" :cx="d.x" :cy="d.y" r="7" style="fill:var(--pulse-viz);fill-opacity:.5" />
							<rect :x="compass.frame.x" :y="compass.frame.y" :width="compass.frame.w" :height="compass.frame.h" rx="6" style="fill:none;stroke:var(--pulse-border-strong);stroke-width:1.75" />
							<line :x1="compass.nullV.x1" :y1="compass.nullV.y1" :x2="compass.nullV.x2" :y2="compass.nullV.y2" style="stroke:var(--pulse-text-2);stroke-width:1.75" />
							<line :x1="compass.nullH.x1" :y1="compass.nullH.y1" :x2="compass.nullH.x2" :y2="compass.nullH.y2" style="stroke:var(--pulse-text-2);stroke-width:1.75" />
							<text :x="compass.poles.top.x" :y="compass.poles.top.y" text-anchor="middle" class="flabel">{{ compass.poles.top.t }}</text>
							<text :x="compass.poles.bottom.x" :y="compass.poles.bottom.y" text-anchor="middle" class="flabel">{{ compass.poles.bottom.t }}</text>
							<text :x="compass.poles.right.x" :y="compass.poles.right.y" text-anchor="start" class="flabel">{{ compass.poles.right.t }}</text>
							<text :x="compass.poles.left.x" :y="compass.poles.left.y" text-anchor="end" class="flabel">{{ compass.poles.left.t }}</text>
							<text v-for="(cc, i) in compass.corners" :key="'cc' + i" :x="cc.x" :y="cc.y" :text-anchor="cc.anchor" class="fcorner">{{ cc.t }}</text>
							<template v-if="compass.centroid">
								<polygon :points="compass.centroid.points" style="fill:var(--pulse-viz);stroke:var(--pulse-bg);stroke-width:2" />
								<text :x="compass.centroid.x" :y="compass.centroid.ty" text-anchor="middle" class="fcorner" style="fill:var(--pulse-viz);font-weight:800">{{ t('pulse', 'Centre of gravity') }}</text>
							</template>
							<template v-if="compass.mine">
								<circle :cx="compass.mine.x" :cy="compass.mine.y" r="13" style="fill:none;stroke:var(--pulse-self);stroke-width:3" />
								<circle :cx="compass.mine.x" :cy="compass.mine.y" r="6" style="fill:var(--pulse-self);stroke:var(--pulse-bg);stroke-width:2" />
								<text :x="compass.mine.tx" :y="compass.mine.ty" class="fdu">{{ t('pulse', 'You') }}</text>
							</template>
						</svg>
					</div>
					<div class="viz-col compass-readout">
						<div class="viz-kpi">
							<span class="viz-kpi-lbl">{{ t('pulse', 'Centre of gravity') }}</span>
							<span class="viz-kpi-val">{{ compassReadout.centroid }}</span>
						</div>
						<div v-if="compassReadout.mine" class="viz-kpi is-self">
							<span class="viz-kpi-lbl">{{ t('pulse', 'You') }}</span>
							<span class="viz-kpi-val">{{ compassReadout.mine }}</span>
						</div>
					</div>
				</div>
				<div class="rlegend">
					<span><span class="rsw rsw-vote" /> {{ t('pulse', 'Vote') }}</span>
					<span v-if="compass && compass.heatmap"><span class="rsw rsw-heat" /> {{ t('pulse', 'Density') }}</span>
					<span><span class="rsw rsw-centroid" /> {{ t('pulse', 'Centre of gravity') }}</span>
					<span v-if="mine"><span class="rsw rsw-du" /> {{ t('pulse', 'You') }}</span>
				</div>
				<p v-if="!wide" class="norm-note">{{ compass && compass.heatmap ? t('pulse', 'Density (heat map) · centre of gravity = mean of all answers') : t('pulse', 'Scatter plot · centre of gravity = mean of all answers') }}</p>
			</template>
		</div>

		<!-- Skala · Einzel: Verteilung mit Ø-/Median-Marker IN der Verteilung -->
		<div v-else-if="results && results.type === 'scale'" class="dist" :class="{ 'bars--stage': wide }">
			<VizEmpty v-if="!total" />
			<!-- Beamer: Säulen über die volle Bühnenhöhe, Median über und
			     Mittelwert unter den Säulen (§7.2). -->
			<StageHistogram v-else-if="wide"
				:rows="results.results" :average="results.average"
				:median="scaleMedian" :min-label="results.minLabel" :max-label="results.maxLabel" />
			<template v-else>
				<div class="hist-wrap">
					<div class="hist" :class="{ 'hist--dense': results.results.length > 12 }">
						<div v-for="row in results.results" :key="row.value" class="hist-col">
							<span class="hist-cnt">{{ row.count || '' }}</span>
							<div class="hist-track">
								<div class="hist-bar" :class="{ 'is-zero': !row.count }" :style="row.count ? { height: scalePercent(row.count) + '%' } : null" />
							</div>
							<span class="hist-lab">{{ row.value }}</span>
						</div>
					</div>
					<div v-for="m in histMarks" :key="m.key" class="hmark" :class="'hmark--' + m.key" :style="{ left: m.pct + '%' }">
						<span class="hmark-flag">{{ m.label }}</span>
					</div>
				</div>
				<div v-if="results.minLabel || results.maxLabel" class="hist-ends">
					<span>{{ results.minLabel }}</span>
					<span>{{ results.maxLabel }}</span>
				</div>
				<p v-if="!wide" class="norm-note">{{ t('pulse', 'Distribution · column height = votes per value · scale {min}–{max}', { min: results.min, max: results.max }) }}</p>
			</template>
		</div>

		<p v-if="!wide && results && results.type !== 'text' && results.type !== 'words'" class="total">{{ n('pulse', '%n vote', '%n votes', total) }}</p>
		<p v-else-if="!wide && results && results.type === 'words'" class="total">{{ wordsSummary }}</p>
	</div>
</template>

<script>
import { fmtNum } from '../util/format.js'
import { option, rampStep, withPalette } from '../util/palette.js'
import PulseIcon from './ui/PulseIcon.vue'
import StageCompass from './StageCompass.vue'
import StageHistogram from './StageHistogram.vue'
import StageRadar from './StageRadar.vue'
import StageRow from './StageRow.vue'
import StageStack from './StageStack.vue'
import TextGrading from './TextGrading.vue'
import VizEmpty from './ui/VizEmpty.vue'

// Ab dieser Labellänge wird zweizeilig gerechnet — und zwar für ALLE Zeilen
// der Bühne gemeinsam, damit kein springendes Zeilenraster entsteht (§2.6).
const TALL_LABEL_CHARS = 34

export default {
	name: 'ResultsView',
	components: { PulseIcon, StageCompass, StageHistogram, StageRadar, StageRow, StageStack, TextGrading, VizEmpty },
	props: {
		results: { type: Object, default: null },
		// Quiz choice/truefalse: Options-ID der richtigen Antwort -> grün.
		correctId: { type: String, default: '' },
		// Quiz multi: mehrere richtige Options-IDs.
		correctIds: { type: Array, default: () => [] },
		// Quiz number/text: Zielzahl+Toleranz bzw. akzeptierte Antworten.
		answerKey: { type: Object, default: null },
		// Kompass: eigene abgegebene Position ({x,y}) — nur lokal beim eigenen Gerät gesetzt.
		mine: { type: Object, default: null },
		// Spektrum: eigene abgegebene Werte (aspectId -> Wert) für das „Du"-Polygon.
		mineAspects: { type: Object, default: null },
		// Beamer-Layout (breit): Chart höhengebunden + Kennzahl-Spalte rechts.
		wide: { type: Boolean, default: false },
		// Vorschau-Panel des Moderators: schmal, aber mit derselben Aussage wie die
		// Leinwand. Die Zuordnung zeigt hier eine gestapelte Zeile je Paar statt
		// einer Balkenliste je Ziel — acht Paare mal acht Ziele wären 64 Balken in
		// einer 340-px-Spalte, und die Reihenfolge der Frage ginge darin unter.
		dense: { type: Boolean, default: false },
	},
	computed: {
		total() {
			return this.results ? this.results.total : 0
		},
		// Zeilenköpfe der gestapelten Bühne stehen in einer festen Spalte (9 em),
		// damit alle Balken an derselben Kante beginnen. Namen wie „Container
		// orchestration" endeten darin als Auslassungspunkte, während rechts Platz
		// frei war: ab 14 Zeichen wird die Spalte breiter und zweizeilig.
		longHeads() {
			const rows = (this.results && this.results.results) || []
			return rows.some((r) => String(r.label || '').length > 14)
		},
		// Wortwolken-Summe: „N Nennungen · M verschiedene" (§7-Grammatik).
		wordsSummary() {
			const rows = (this.results && this.results.results) || []
			const mentions = rows.reduce((sum, r) => sum + (r.count || 0), 0)
			const distinct = rows.length
			return this.n('pulse', '%n mention', '%n mentions', mentions) + ' · ' + this.t('pulse', '{count} different', { count: distinct })
		},
		maxCount() {
			if (!this.results || !this.results.results || !this.results.results.length) return 0
			return Math.max(...this.results.results.map((r) => r.count))
		},
		// Zweizeilige Zeilenhöhe für die ganze Bühne, sobald EIN Label lang ist.
		tallLabels() {
			const rows = (this.results && this.results.results) || []
			return rows.some((r) => (r.label || '').length > TALL_LABEL_CHARS)
		},
		// Choice/Multi-Zeilen mit stabiler Palette (Buchstabe + Farbe A–H) —
		// dieselbe Zuordnung wie Live-Kachel (Beamer) und Handy (util/palette.js).
		choiceRows() {
			return withPalette(this.results && this.results.results)
		},
		// Gibt es überhaupt eine „richtige Antwort" (Quiz) -> Grün statt Sieger-Marke.
		hasCorrect() {
			return !!this.correctId || (Array.isArray(this.correctIds) && this.correctIds.length > 0)
		},
		// Reihenfolge: gibt es eine Lösung (Quiz, erst beim Auflösen mitgeliefert)?
		hasRankKey() {
			return !!(this.answerKey && Array.isArray(this.answerKey.order) && this.answerKey.order.length)
		},
		// Zuordnung: Lösung nur im Quiz und erst nach dem Auflösen vorhanden.
		hasMatchKey() {
			return !!(this.answerKey && this.answerKey.map && Object.keys(this.answerKey.map).length)
		},
		numTarget() {
			return this.answerKey && this.answerKey.target !== undefined ? this.answerKey.target : '?'
		},
		numTol() {
			return this.answerKey && this.answerKey.tolerance ? this.answerKey.tolerance : 0
		},
		// Ø der abgegebenen Tipps (gewichtet nach Häufigkeit).
		numAvg() {
			if (!this.results || !this.results.results || !this.results.results.length) return null
			let sum = 0, n = 0
			for (const r of this.results.results) {
				const v = Number(r.value)
				if (isNaN(v)) continue
				sum += v * r.count; n += r.count
			}
			if (!n) return null
			return Math.round((sum / n) * 10) / 10
		},
		// Median der Skala-Stimmen — aus kumulierten Counts (kein Werte-Array nötig).
		scaleMedian() {
			const rows = this.results && this.results.results
			if (!rows || !rows.length) return null
			const sorted = rows.slice().sort((a, b) => a.value - b.value)
			let n = 0
			for (const r of sorted) n += r.count
			if (!n) return null
			const loIdx = Math.floor((n - 1) / 2), hiIdx = Math.floor(n / 2)
			let cum = 0, lo = null, hi = null
			for (const r of sorted) {
				const e = cum + r.count
				if (lo === null && loIdx < e) lo = r.value
				if (hi === null && hiIdx < e) hi = r.value
				cum = e
				if (lo !== null && hi !== null) break
			}
			return lo === hi ? lo : Math.round(((lo + hi) / 2) * 10) / 10
		},
		// Ø/Median als Marker IN der Verteilung: Wert -> Mitte seiner Säule.
		// Die Säulen liegen lückenlos nebeneinander (Abstand steckt IM Spalten-
		// kasten), darum ist Wert v bei ((v-min)+0,5)/N — auch für Bruchwerte.
		histMarks() {
			const r = this.results
			if (!r || r.type !== 'scale' || this.spectrumMode || this.compassMode) return []
			const rows = r.results || []
			if (!rows.length || !this.total) return []
			const min = Number(rows[0].value), N = rows.length
			const pos = (v) => Math.max(0, Math.min(100, ((Number(v) - min) + 0.5) / N * 100))
			const out = []
			if (r.average !== null && r.average !== undefined) out.push({ key: 'avg', pct: Math.round(pos(r.average) * 10) / 10, label: this.t('pulse', 'avg. {value}', { value: fmtNum(r.average) }) })
			if (this.scaleMedian !== null) out.push({ key: 'med', pct: Math.round(pos(this.scaleMedian) * 10) / 10, label: this.t('pulse', 'median {value}', { value: fmtNum(this.scaleMedian) }) })
			return out
		},
		// Spektrum am Beamer: Aspekte mit Palette, damit der Sonderfall „zwei
		// Aspekte" dieselbe Zeilen-Grammatik trägt wie eine Auswahlfrage (§7.3).
		spectrumRows() {
			return withPalette(this.spectrumMode ? (this.results.results || []) : [])
		},
		// Spektrum-Ergebnis: Radar aus Aspekt-Ø/-min/-max (Zentrum=0, Rand=X).
		spectrumMode() {
			return !!this.results && this.results.mode === 'spectrum'
		},
		neutralLabel() {
			const x = this.results ? this.results.max : 0
			return fmtNum(x / 2)
		},
		radarAria() {
			if (!this.results) return ''
			const n = (this.results.results || []).length
			return this.t('pulse', 'Radar spectrum: {aspects} aspects, scale 0 to {max}', { aspects: n, max: this.results.max })
		},
		radar() {
			const rows = this.results && this.results.results
			if (!this.spectrumMode || !rows || rows.length < 3) return null
			const X = this.results.max || 1
			const N = rows.length
			// Quadratischer viewBox (440×440): die Aspekt-Namen stehen NICHT mehr am
			// Rand (Clipping-Quelle „ieherfähigkeit"), sondern als Nummer-Scheibchen
			// an der Speiche + ausgeschriebene Zuordnung in radarItems (Kennzahl-Spalte).
			const cx = 220, cy = 220, R = 170
			const r1 = (x) => Math.round(x * 10) / 10
			const ang = (i) => (-90 + i * 360 / N) * Math.PI / 180
			const pt = (v, i) => {
				const rr = (v / X) * R, a = ang(i)
				return [r1(cx + rr * Math.cos(a)), r1(cy + rr * Math.sin(a))]
			}
			const step = X > 10 ? 2 : 1
			const rings = []
			for (let g = step; g <= X; g += step) rings.push(r1(g / X * R))
			const spokes = rows.map((_, i) => pt(X, i))
			const avgPoints = rows.map((row, i) => pt(row.average, i).join(',')).join(' ')
			const band = 'M' + rows.map((row, i) => pt(row.max, i).join(',')).join('L') + 'Z '
				+ 'M' + rows.map((row, i) => pt(row.min, i).join(',')).join('L') + 'Z'
			// Nummer-Scheibchen am äußeren Speichenende (1..N) — kurz, clippt nie.
			const nums = rows.map((row, i) => {
				const a = ang(i)
				return {
					n: i + 1,
					x: r1(cx + (R + 20) * Math.cos(a)),
					y: r1(cy + (R + 20) * Math.sin(a)),
					dot: pt(row.average, i),
				}
			})
			// „Du"-Polygon nur, wenn für JEDEN Aspekt ein eigener Wert vorliegt.
			let minePoints = null
			const ma = this.mineAspects
			if (ma && rows.every((row) => typeof ma[row.id] === 'number')) {
				minePoints = rows.map((row, i) => pt(ma[row.id], i).join(',')).join(' ')
			}
			return { cx, cy, R, neutral: r1(0.5 * R), rings, spokes, avgPoints, band, nums, minePoints }
		},
		// Kennzahl-Spalte zum Radar: Nummer → Aspekt-Name → Ø (dt. Komma).
		radarItems() {
			const rows = this.results && this.results.results
			if (!this.spectrumMode || !rows) return []
			return rows.map((row, i) => ({
				n: i + 1,
				label: row.label,
				avg: fmtNum(row.average, 1, 1),
			}))
		},
		// Kompass-Ergebnis: Quadrant + Scatter/Heatmap + Schwerpunkt + eigener Punkt.
		compassMode() {
			return !!this.results && this.results.mode === 'compass'
		},
		compassAria() {
			if (!this.results) return ''
			const ax = this.results.axisX || {}, ay = this.results.axisY || {}
			return this.t('pulse', 'Compass: {total} answers, X {x}, Y {y}', { total: this.results.total, x: ax.title || '', y: ay.title || '' })
		},
		compass() {
			if (!this.compassMode || !this.results) return null
			const R = this.results.range || 5
			const cx = 230, cy = 210, H = 160
			const r1 = (n) => Math.round(n * 10) / 10
			const sx = (x) => r1(cx + (x / R) * H)
			const sy = (y) => r1(cy - (y / R) * H)
			const step = R > 10 ? 2 : 1
			const grid = []
			for (let g = -R; g <= R; g += step) {
				grid.push({ x1: sx(g), y1: sy(-R), x2: sx(g), y2: sy(R) })
				grid.push({ x1: sx(-R), y1: sy(g), x2: sx(R), y2: sy(g) })
			}
			const frame = { x: sx(-R), y: sy(R), w: 2 * H, h: 2 * H }
			const nullV = { x1: sx(0), y1: sy(-R), x2: sx(0), y2: sy(R) }
			const nullH = { x1: sx(-R), y1: sy(0), x2: sx(R), y2: sy(0) }
			const ax = this.results.axisX || {}, ay = this.results.axisY || {}
			const poles = {
				top: { x: cx, y: r1(sy(R) - 12), t: ay.poleHigh || '' },
				bottom: { x: cx, y: r1(sy(-R) + 22), t: ay.poleLow || '' },
				right: { x: r1(sx(R) + 10), y: cy + 4, t: ax.poleHigh || '' },
				left: { x: r1(sx(-R) - 10), y: cy + 4, t: ax.poleLow || '' },
			}
			const cl = this.results.cornerLabels || []
			const corners = []
			if (cl[0]) corners.push({ x: r1(sx(-R) + 6), y: r1(sy(-R) - 8), anchor: 'start', t: cl[0] })
			if (cl[1]) corners.push({ x: r1(sx(R) - 6), y: r1(sy(-R) - 8), anchor: 'end', t: cl[1] })
			if (cl[2]) corners.push({ x: r1(sx(-R) + 6), y: r1(sy(R) + 16), anchor: 'start', t: cl[2] })
			if (cl[3]) corners.push({ x: r1(sx(R) - 6), y: r1(sy(R) + 16), anchor: 'end', t: cl[3] })
			const total = this.results.total || 0
			const threshold = this.results.heatmapThreshold || 40
			const pts = this.results.points || []
			const heatmap = total >= threshold
			const dots = [], cells = []
			if (heatmap) {
				const bin = R > 10 ? 2 : 1
				const map = {}
				let maxc = 0
				for (const p of pts) {
					let gx = Math.floor((p.x + R) / bin) * bin - R
					let gy = Math.floor((p.y + R) / bin) * bin - R
					gx = Math.max(-R, Math.min(R - bin, gx))
					gy = Math.max(-R, Math.min(R - bin, gy))
					const k = gx + ',' + gy
					map[k] = (map[k] || 0) + 1
					if (map[k] > maxc) maxc = map[k]
				}
				const cw = r1((bin / R) * H)
				for (const k in map) {
					const parts = k.split(',')
					const gx = Number(parts[0]), gy = Number(parts[1])
					const op = maxc ? Math.round((map[k] / maxc) * 0.78 * 1000) / 1000 : 0
					if (op < 0.04) continue
					cells.push({ x: sx(gx), y: sy(gy + bin), w: cw, h: cw, op })
				}
			} else {
				for (const p of pts) dots.push({ x: sx(p.x), y: sy(p.y) })
			}
			const c = this.results.centroid
			let centroid = null
			if (c) {
				const X = sx(c.x), Y = sy(c.y), rr = 9
				centroid = { x: X, y: Y, ty: r1(Y - 14), points: `${X},${r1(Y - rr)} ${r1(X + rr)},${Y} ${X},${r1(Y + rr)} ${r1(X - rr)},${Y}` }
			}
			const m = this.mine
			let mine = null
			if (m && typeof m.x === 'number' && typeof m.y === 'number') {
				const X = sx(m.x), Y = sy(m.y)
				mine = { x: X, y: Y, tx: r1(X + 15), ty: r1(Y + 4) }
			}
			return { grid, frame, nullV, nullH, poles, corners, cells, dots, heatmap, centroid, mine }
		},
		// Kennzahl-Spalte zum Kompass: Schwerpunkt + „Du" als große tabellarische
		// X/Y-Anzeige (Werte in der Spalte statt am Rand, §1-Beamer-Layout).
		compassReadout() {
			if (!this.compassMode || !this.results) return { centroid: '–', mine: null }
			const sg = (v) => {
				const n = Math.round(Number(v) * 10) / 10
				const s = n > 0 ? '+' : (n < 0 ? '−' : '±')
				return s + fmtNum(Math.abs(v))
			}
			const fmt = (p) => (p && typeof p.x === 'number') ? 'X ' + sg(p.x) + ' · Y ' + sg(p.y) : '–'
			const c = this.results.centroid, m = this.mine
			return {
				centroid: c ? fmt(c) : '–',
				mine: (m && typeof m.x === 'number') ? fmt(m) : null,
			}
		},
	},
	methods: {
		// Einheitliches Kennzahl-Format: 1 Nachkommastelle in der Sprache der
		// Oberfläche, tabellenziffrig (Schätzfrage + Skala teilen es, §7-Grammatik).
		num(v) {
			if (v === null || v === undefined) return '–'
			return fmtNum(v)
		},
		// Lösungs-Kodierung der Bühnenzeile (§4.2): richtig = voll gesättigt +
		// Ring + Häkchen + Wort, falsch = entsättigt. Ohne Lösung (Umfrage)
		// bleibt die Zeile neutral; die Führung trägt nur das Wort.
		choiceState(row) {
			if (this.isCorrect(row.id)) return 'correct'
			return this.hasCorrect ? 'wrong' : ''
		},
		choiceFlag(row) {
			if (this.isCorrect(row.id)) return this.t('pulse', 'correct')
			if (!this.hasCorrect && this.isWinner(row.count)) return this.t('pulse', 'leading')
			return ''
		},
		// Gewinner = meiste Stimmen (Gleichstand -> alle markiert).
		isWinner(count) {
			return count > 0 && count === this.maxCount
		},
		isCorrect(id) {
			return (!!this.correctId && id === this.correctId)
				|| (Array.isArray(this.correctIds) && this.correctIds.includes(id))
		},
		numWithin(value) {
			if (!this.answerKey || this.answerKey.target === undefined) return false
			return Math.abs(Number(value) - Number(this.answerKey.target)) <= Number(this.answerKey.tolerance || 0)
		},
		// Anteil an den Gesamtstimmen (§5.1: eine Balken-Norm).
		// Balkenlänge: Ø-Platz 1 = voll, letzter Platz = kurz.
		rankPct(row) {
			const count = (this.results && this.results.results) ? this.results.results.length : 0
			if (!row.n || count < 2) return row.n ? 100 : 0
			return Math.max(4, Math.round((1 - (row.average - 1) / (count - 1)) * 100))
		},
		// Spektrum-Zeile: Ø als Anteil der Skala (nur der Zwei-Aspekte-Fall).
		spectrumPct(row) {
			const max = (this.results && this.results.max) || 1
			return Math.max(0, Math.min(100, Math.round((row.average / max) * 100)))
		},
		// Platzverteilung einer Reihenfolge-Zeile: Segment k = Anteil der Stimmen,
		// die dieses Element auf Platz k gesetzt haben. Plätze sind geordnet ->
		// Helligkeitsstaffel, keine Palette (§7.0).
		rankSegments(row) {
			const places = Array.isArray(row.places) ? row.places : []
			const sum = places.reduce((a, b) => a + b, 0)
			if (!sum) return []
			const n = places.length
			const target = this.rankTarget(row.id)
			return places.map((count, k) => {
				const step = rampStep(k + 1, n)
				const correct = this.hasRankKey && target === k + 1
				return {
					key: k,
					// Häkchen im Segment, nicht am Zeilenanfang (§7.6).
					text: (correct ? '✓ ' : '') + (k + 1),
					pct: Math.round((count / sum) * 1000) / 10,
					fill: step.fill,
					ink: step.ink,
					state: correct ? 'correct' : '',
				}
			})
		},
		rankAria(row) {
			const places = Array.isArray(row.places) ? row.places : []
			const parts = places.map((count, k) => this.t('pulse', 'place {place}', { place: k + 1 }) + ': ' + count)
			return row.label + ' — ' + parts.join(', ')
		},
		// Zuordnung: alle Ziele der Zeile als Segmente. Ziele sind ungeordnet ->
		// Palette A–H (§7.0). Im Quiz trägt das richtige Segment die Lösungs-
		// Kodierung, die übrigen sind entsättigt (§7.6).
		matchSegments(row) {
			const targets = (this.results && this.results.targets) || []
			const wanted = this.matchKeyOf(row.id)
			if (!row.n) return []
			return (row.targets || []).map((cell) => {
				const idx = targets.findIndex((target) => target.id === cell.id)
				const pal = option(idx < 0 ? 0 : idx)
				const correct = this.hasMatchKey && cell.id === wanted
				return {
					key: cell.id,
					text: (correct ? '✓ ' : '') + cell.label,
					pct: Math.round((cell.count / row.n) * 1000) / 10,
					fill: 'var(--opt-' + pal.letter.toLowerCase() + ')',
					ink: 'var(--opt-' + pal.letter.toLowerCase() + '-ink)',
					state: correct ? 'correct' : (this.hasMatchKey ? 'wrong' : ''),
				}
			})
		},
		// Rechts steht die Spitze der Zeile — die Zahl, nach der sich der Raum
		// einig ist (oder eben nicht).
		matchLead(row) {
			if (!row.n) return '—'
			const max = (row.targets || []).reduce((m, cell) => Math.max(m, cell.count), 0)
			return max + ' · ' + this.matchPercent(max, row.n) + '%'
		},
		matchAria(row) {
			const parts = (row.targets || []).filter((cell) => cell.count > 0)
				.map((cell) => cell.label + ': ' + cell.count)
			return row.label + ' — ' + (parts.length ? parts.join(', ') : this.t('pulse', 'No assignment yet.'))
		},
		// Soll-Platz (1-basiert) dieser Antwort laut Lösung.
		rankTarget(id) {
			if (!this.hasRankKey) return null
			return this.answerKey.order.indexOf(id) + 1
		},
		// Steht die Antwort im Konsens auf ihrem richtigen Platz?
		rankCorrect(id, i) {
			return this.hasRankKey && this.rankTarget(id) === i + 1
		},
		matchKeyOf(itemId) {
			return this.hasMatchKey ? (this.answerKey.map[itemId] || '') : ''
		},
		matchTargetLabel(targetId) {
			const hit = ((this.results && this.results.targets) || []).find((target) => target.id === targetId)
			return hit ? hit.label : '—'
		},
		matchIsCorrect(row, cell) {
			return this.hasMatchKey && cell.id === this.matchKeyOf(row.id)
		},
		// Nur gewählte Ziele zeigen — plus das richtige, auch wenn es niemand
		// getroffen hat (sonst fehlt beim Auflösen genau die Zeile, die zählt).
		matchCells(row) {
			const wanted = this.matchKeyOf(row.id)
			const shown = row.targets.filter((cell) => cell.count > 0 || cell.id === wanted)
			if (!this.wide) return shown
			// Beamer: die Spitze der Zeile (bei Gleichstand beide führenden) und
			// das richtige Ziel. Der Rest ist auf Entfernung ohnehin nicht lesbar.
			const max = shown.reduce((m, cell) => Math.max(m, cell.count), 0)
			const lead = shown.filter((cell) => cell.count === max && max > 0).slice(0, 2)
			if (!wanted || lead.some((cell) => cell.id === wanted)) return lead
			return lead.concat(shown.filter((cell) => cell.id === wanted))
		},
		matchPercent(count, n) {
			return n ? Math.round((count / n) * 100) : 0
		},
		percent(count) {
			return this.total ? Math.round((count / this.total) * 100) : 0
		},
		// Verteilungstypen: Balken relativ zum häufigsten Wert (Histogramm).
		scalePercent(count) {
			return this.maxCount ? Math.round((count / this.maxCount) * 100) : 0
		},
		cloudEm(count) {
			return this.maxCount ? Math.round((1 + (count / this.maxCount) * 1.6) * 100) / 100 : 1
		},
		cloudOpacity(count) {
			return this.maxCount ? 0.55 + 0.45 * (count / this.maxCount) : 1
		},
	},
}
</script>

<style scoped>
/* Zuordnung: je Item ein kleiner Block mit Balken darunter. */
.matchres { display: flex; flex-direction: column; gap: 1em; }
.mres-head { display: flex; align-items: center; gap: 0.6em; flex-wrap: wrap; margin-bottom: 0.35em; }
.mres-item { font-weight: 700; }
.bars--match { gap: 0.45em; }
/* Beamer: zwei Spalten ab fünf Zeilen, damit acht Paare in die Höhe passen.
   align-content:start — die Zeilen sollen oben stehen, nicht gestreckt werden. */
.matchres.is-cols { display: grid; grid-template-columns: 1fr 1fr; align-content: start; gap: 0.9em clamp(1.5em, 4vw, 3em); }
/* Vorschau-Panel: eine Zeile je Paar, Kopf darüber. Der Wert steht im Kopf,
   nicht neben dem Balken — in 340 px nähme eine eigene Wertspalte ein Fünftel. */
.matchres.is-dense { gap: 0.7em; }
.matchres.is-dense .mres-head { margin-bottom: 0.25em; }
.matchres.is-dense .mres-lead { margin-left: auto; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
.matchres.is-dense :deep(.sstack) { height: 1.9em; }
.matchres.is-wide .bar-row { margin-bottom: 0.45em; }
.matchres.is-wide .mres-head { margin-bottom: 0.25em; }

/* Alle Maße in em -> die Komponente skaliert über die Container-font-size:
   Handy klein, Beamer groß (§5.4, der Fix für „Auflösung bleibt zu klein"). */
.pulse-results { width: 100%; font-size: inherit; }

/* Bühne (Beamer, §2.4): die Zeilen bekommen die Resthöhe und teilen sie unter
   sich auf — vier Optionen füllen das Bild, acht schrumpfen bis zum Minimum
   und danach greift die Schrumpf-Schleife. */
.bars--stage { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
/* Kennzahlen behalten ihre Höhe, die Zeilen bekommen den Rest. */
.bars--stage .dist-metrics { flex: 0 0 auto; }
.matchres.is-wide { flex: 1 1 auto; min-height: 0; }
.matchres.is-wide .mres-row { min-width: 0; }
.matchres.is-wide .mres-item { font-weight: 700; }

/* Gestapelte Bühnenzeilen (§7.5/§7.6): wie .srows--stretch teilen sie sich die
   Resthöhe, damit vier Paare das Bild füllen und acht noch hineinpassen. */
.sstacks { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; justify-content: center; gap: 0.5em; }
/* Wie die Zeilenbühne (§2.4) teilen sich die Zeilen die Resthöhe, bleiben aber
   gedeckelt — sonst würden vier Zeilen zu Bändern. */
.sstacks > * { flex: 1 1 2.4em; min-height: 2.2em; max-height: 3.4em; }
/* Lange Zeilenköpfe: breitere Spalte, zwei Zeilen, keine Auslassungspunkte. */
.sstacks.is-longhead :deep(.sstack-head) {
	flex-basis: 12em; width: 12em;
	white-space: normal; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
}
.sstacks :deep(.sstack) { height: 100%; max-height: 3.2em; }

/* Schätzfrage am Beamer: Kennzahlen als eine Zeile in Klasse B. */
.numline { flex: 0 0 auto; display: flex; align-items: baseline; flex-wrap: wrap; gap: 0.4em; margin-bottom: 0.5em; font-weight: 700; }
.numline-target { font-size: 1.3em; font-weight: 900; color: var(--pulse-success); font-variant-numeric: tabular-nums; }
.numline-lbl { color: var(--pulse-meta); font-weight: 600; }
.numline-sep { color: var(--pulse-border-strong); }
.numline-val { font-variant-numeric: tabular-nums; }

.bar-row { margin-bottom: 1.1em; }
.bar-head {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	gap: 0.75em;
	margin-bottom: 0.35em;
	font-size: 1em;
}
.bar-label { display: inline-flex; align-items: center; gap: 0.5em; min-width: 0; font-weight: 600; }
.bar-text { min-width: 0; overflow: hidden; text-overflow: ellipsis; }
.bar-badge {
	/* Buchstaben-Badge in der Optionsfarbe (Palette-Brücke). */
	flex: 0 0 auto; font-size: 0.85em;
	background: var(--opt-fill, var(--pulse-fill)); color: var(--opt-ink, var(--pulse-text));
}
.bar-flag { flex: 0 0 auto; display: inline-flex; align-items: center; gap: 0.2em; font-weight: 800; font-size: 0.8em; }
.bar-flag.is-correct { color: var(--pulse-success); }
.bar-flag.is-winner { color: var(--pulse-warning); }
.is-correct .bar-text, .is-winner .bar-text { font-weight: 800; }
/* Nur die alten Zwei-Zeilen-Balken dämpfen. Die Bühnenzeile (§2.4) kodiert
   „falsch" über die Sättigung der Füllung — eine zusätzliche Deckkraft auf der
   ganzen Zeile nähme auch dem Text seinen Kontrast. */
.bar-row.is-wrong, .rank-row.is-wrong { opacity: 0.55; }
.bar-count { flex: 0 0 auto; color: var(--pulse-readout); font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
.bar-track {
	height: 2.1em;
	border-radius: var(--pulse-r-el);
	background: var(--pulse-fill);
	overflow: hidden;
}
.bar-fill {
	height: 100%;
	border-radius: var(--pulse-r-el);
	min-width: 0.2em;
	/* Palette-Brücke: Balken in der Optionsfarbe (nicht pauschal Primärblau). */
	background: var(--opt-fill, var(--pulse-primary));
	transition: width 0.7s cubic-bezier(.22, 1, .36, 1);
}
/* Verteilungs-Balken (Skala/Zahl) tragen keine Options-Palette -> Primär. */
.bars--dist .bar-fill { background: var(--pulse-primary); }

.ranks { display: flex; flex-direction: column; gap: 0.7em; }
.rank-row .bar-fill { background: var(--pulse-viz-1, var(--pulse-primary)); }
.rank-row.is-correct .bar-fill { background: var(--pulse-success); }
.rank-row.is-wrong .bar-fill { opacity: 0.5; }
.rank-first { color: var(--pulse-text-2); }
.norm-note { margin: 0.4em 0 0; font-size: 0.8em; color: var(--pulse-text-2); }

/* Verteilungs-Kennzahlen (Ø, Zielzahl) */
.dist-metrics { display: flex; flex-wrap: wrap; gap: 1.5em; justify-content: center; margin-bottom: 1.3em; }
.metric { text-align: center; }
.metric-val { display: block; font-size: 3.4em; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; color: var(--pulse-primary); }
.metric--target .metric-val { color: var(--pulse-success); }
.metric-val--sub { font-size: 2.4em; }
.metric-label { font-size: 0.85em; color: var(--pulse-text-2); }
.bar-label em { font-style: normal; color: var(--pulse-text-2); font-weight: 400; }

/* Skala-Histogramm (Handoff §6) — alles in em, skaliert Handy -> Beamer.
   KEIN gap: der Abstand steckt in der Säulenbreite (62 % der Spalte). Nur so
   liegt Wert v exakt bei ((v-min)+0,5)/N — die Rechnung, mit der die Ø-/Median-
   Marker positioniert werden. Mit gap würden sie gegen die Säulen verrutschen. */
.hist-wrap { position: relative; }
.hist { display: flex; align-items: flex-end; gap: 0; margin-top: 0.4em; border-bottom: 1px solid var(--pulse-border-strong); }
.hist-col { flex: 1 1 0; min-width: 0; display: flex; flex-direction: column; align-items: center; gap: 0.3em; }
.hist-cnt { font-size: 0.72em; line-height: 1; min-height: 1em; color: var(--pulse-text-2); font-variant-numeric: tabular-nums; }
.hist-track { width: 100%; height: 6.5em; display: flex; align-items: flex-end; }
.hist-bar { width: 62%; min-height: 0.15em; border-radius: 0.25em 0.25em 0 0; background: var(--pulse-viz); transition: height 0.7s cubic-bezier(.22, 1, .36, 1); }
/* Werte ohne Stimmen bleiben als ruhiger Sockel stehen -> vollständige Achse
   statt einer einsamen Säule im Leerraum. */
.hist-bar.is-zero { height: 0.3em; background: var(--pulse-border-strong); border-radius: 0.15em; }

/* Ø-/Median-Marker: Linie durch die Verteilung + Fähnchen oben.
   Ø durchgezogen in der Daten-Farbe, Median gestrichelt in Textfarbe —
   unterscheidbar über die FORM, nicht nur über Farbe (AA). */
.hmark { position: absolute; top: 0; bottom: 1.6em; width: 0; pointer-events: none; }
.hmark::before { content: ''; position: absolute; top: 1.5em; bottom: 0; left: -1.5px; width: 3px; }
.hmark--avg::before { background: var(--pulse-viz); }
.hmark--med::before { background: repeating-linear-gradient(to bottom, var(--pulse-text) 0 0.35em, transparent 0.35em 0.65em); }
.hmark-flag {
	position: absolute; top: 0; left: 0; transform: translateX(-50%);
	padding: 0.15em 0.5em; border-radius: 0.35em; white-space: nowrap;
	font-size: 0.72em; font-weight: 800; font-variant-numeric: tabular-nums;
	color: var(--pulse-bg);
}
.hmark--avg .hmark-flag { background: var(--pulse-viz); }
.hmark--med .hmark-flag { background: var(--pulse-text); top: 1.5em; }
.hist-lab { font-size: 0.85em; font-weight: 700; line-height: 1; color: var(--pulse-text); font-variant-numeric: tabular-nums; }
.hist-ends { display: flex; justify-content: space-between; gap: 1em; margin-top: 0.3em; font-size: 0.8em; color: var(--pulse-text-2); }
.hist-ends span { max-width: 45%; }
.hist-ends span:last-child { text-align: right; }
.hist--dense .hist-lab { font-size: 0.68em; }
.hist--dense .hist-cnt { font-size: 0.6em; }

/* Skala · Spektrum-Radar (Handoff §4) — SVG-viewBox skaliert Handy→Beamer. */
.spectrum-radar { display: flex; flex-direction: column; align-items: stretch; }
.radar-main { display: flex; flex-direction: column; align-items: center; gap: 0.6em; }
.radar-wrap { width: 100%; }
.radar-wrap svg { width: 100%; max-width: 42em; height: auto; display: block; margin: 0 auto; }
/* Nummer-Scheibchen am Speichenende — ersetzt den Text am Rand (kein Clipping). */
.rnum-bg { fill: var(--pulse-viz); stroke: var(--pulse-bg); stroke-width: 2; }
.rnum { fill: var(--pulse-bg); font-family: var(--pulse-mono); font-weight: 800; font-size: 15px; }
/* Kennzahl-Spalte: Nummer → Aspekt → Ø. Am Beamer (is-wide) rechts neben dem
   Chart -> nutzt die Breite; am Handy darunter. */
.viz-col { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.4em; min-width: 0; }
.viz-item { display: flex; align-items: baseline; gap: 0.55em; min-width: 0; }
.viz-num { flex: 0 0 auto; width: 1.7em; height: 1.7em; align-self: center; border-radius: 50%; display: inline-grid; place-items: center; background: var(--pulse-viz); color: var(--pulse-bg); font-family: var(--pulse-mono); font-weight: 800; font-size: 0.72em; }
.viz-label { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 700; }
.viz-val { flex: 0 0 auto; font-variant-numeric: tabular-nums; font-weight: 800; color: var(--pulse-viz); white-space: nowrap; }

/* Beamer-Layout: Chart an der Höhe (quadratisch), Kennzahl-Spalte rechts. */
.spectrum-radar.is-wide, .compass-result.is-wide { flex: 1 1 auto; min-height: 0; }
.is-wide .radar-main, .is-wide .compass-main { flex: 1 1 auto; min-height: 0; flex-direction: row; align-items: center; gap: clamp(1em, 3vw, 3em); }
/* Breite aus der Hoehe: das SVG traegt zwar ein viewBox-Seitenverhaeltnis,
   aber width:auto an einem Flex-Item loest Firefox zu 0 auf — Radar und
   Kompassfeld verschwanden am Beamer komplett. Mit aspect-ratio steht die
   Breite fest, bevor das SVG gemessen wird. */
.is-wide .radar-wrap, .is-wide .compass-wrap { flex: 0 1 auto; width: auto; height: 100%; display: flex; align-items: center; justify-content: center; }
.is-wide .radar-wrap { aspect-ratio: 1 / 1; }
.is-wide .compass-wrap { aspect-ratio: 460 / 440; }
.is-wide .radar-wrap svg, .is-wide .compass-wrap svg { width: 100%; height: 100%; max-width: 100%; max-height: 100%; margin: 0; }
.is-wide .viz-col { flex: 1 1 auto; min-height: 0; justify-content: center; gap: 0.7em; font-size: 1.2em; }
.rlabel { fill: var(--pulse-text); font-weight: 800; font-size: 16px; }
.rval { fill: var(--pulse-viz); font-weight: 800; font-size: 13px; }
.rlegend { display: flex; flex-wrap: wrap; gap: 1em; justify-content: center; margin-top: 0.6em; font-size: 0.8em; color: var(--pulse-text-2); }
.rsw { display: inline-block; width: 1.1em; height: 0.8em; border-radius: 0.2em; vertical-align: -0.1em; margin-right: 0.4em; }
.rsw-avg { background: var(--pulse-viz); }
.rsw-band { background: var(--pulse-viz-2); opacity: 0.7; }
.rsw-neutral { background: transparent; border: 1.5px dashed var(--pulse-text-2); }
.rsw-mine { background: transparent; border: 1.5px dashed var(--pulse-self); }

/* Skala · Kompass-Feld — SVG-viewBox skaliert Handy→Beamer. */
.compass-result { display: flex; flex-direction: column; align-items: stretch; }
.compass-main { display: flex; flex-direction: column; align-items: center; gap: 0.6em; }
.compass-wrap { width: 100%; }
.compass-wrap svg { width: 100%; max-width: 40em; height: auto; display: block; margin: 0 auto; }
/* Kompass-Kennzahlen: Schwerpunkt + „Du" als große X/Y-Anzeige. */
.compass-readout { align-items: center; gap: 0.9em; }
.is-wide .compass-readout { align-items: flex-start; }
.viz-kpi { display: flex; flex-direction: column; gap: 0.05em; }
.viz-kpi-lbl { font-size: 0.8em; color: var(--pulse-text-2); font-weight: 700; }
.viz-kpi-val { font-family: var(--pulse-mono); font-weight: 800; font-size: 1.5em; color: var(--pulse-viz); font-variant-numeric: tabular-nums; }
.viz-kpi.is-self .viz-kpi-val { color: var(--pulse-self); }
.flabel { fill: var(--pulse-text); font-weight: 800; font-size: 16px; }
.fcorner { fill: var(--pulse-text-2); font-weight: 700; font-size: 13px; }
.fdu { fill: var(--pulse-self); font-weight: 800; font-size: 15px; }
.rsw-vote { background: var(--pulse-viz); opacity: 0.5; border-radius: 50%; }
.rsw-heat { background: var(--pulse-viz); opacity: 0.5; }
.rsw-centroid { background: var(--pulse-viz); border-radius: 0.15em; transform: rotate(45deg); }
.rsw-du { background: var(--pulse-self); border-radius: 50%; }

.cloud {
	display: flex; flex-wrap: wrap; align-items: center; justify-content: center;
	gap: 0.4em 1.2em; line-height: 1.1; min-height: 5em;
}
.cloud-word { font-weight: 700; color: var(--pulse-primary); transition: font-size 0.5s cubic-bezier(.22, 1, .36, 1); }
.cloud-empty { color: var(--pulse-text-2); }

.total {
	margin-top: 0.8em;
	font-size: 0.85em;
	color: var(--pulse-text-2);
	text-align: right;
}

@media (prefers-reduced-motion: reduce) {
	.bar-fill, .cloud-word, .hist-bar { transition: none; }
}
</style>
