<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pulse-results">
		<!-- Multiple choice / multiple answers: bars normalised to the total votes,
		     option colour carried over from the live tile (palette bridge).
		     On the projector (wide) one row carries everything — badge, label and value
		     sit INSIDE the bar (§2.4); the legend is in the meta bar. -->
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

		<!-- Ranking: consensus ranking. Bar = how high up on average
		     (full = avg. place 1). In a quiz the correct place is shown next to it. -->
		<div v-else-if="results && results.type === 'rank'" class="ranks" :class="{ 'bars--stage': wide }">
			<!-- Projector: the row carries the DISTRIBUTION of places, not the
			     average (§7.5). The bar length is always 100 % — before, it
			     conveyed differences of 0.3 places as a full difference in length
			     and hid whether an item is undisputed
			     or polarising. -->
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

		<!-- Matching: per row the distribution across the targets. In a quiz the
		     correct target is marked green, in a poll the most chosen one. On the
		     projector (wide) only the top per row is shown, in two columns from five rows:
		     eight pairs times eight targets would be 64 bars on one screen. -->
		<div v-else-if="results && results.type === 'match'" class="matchres" :class="{ 'is-wide': wide, 'is-dense': dense, 'is-cols': !wide && !dense && results.results.length > 4 }">
			<!-- Projector: one stacked row per pair shows the full distribution
			     across the targets (§7.6). Before, only the top was shown there — the
			     row claimed agreement where there was only a narrow majority. -->
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

		<!-- Number guess: target number + avg. + distribution of the guesses (histogram) -->
		<div v-else-if="results && results.type === 'number'" class="dist" :class="{ 'bars--stage': wide }">
			<!-- Projector: the three figures as ONE row. As a block they took
			     a third of the stage height, which the distribution then lacked. -->
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
			<!-- On the projector the distribution of guesses also uses the row model from §2.4:
			     the old two-line grammar no longer fit five values on the stage, even at the
			     bottom of the shrink loop. -->
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

		<!-- Free text: ONE shared building block — read-only variant of TextGrading -->
		<TextGrading v-else-if="results && results.type === 'text'" :results="results" readonly />

		<!-- Word cloud: compact preview (the live engine runs on the projector) -->
		<div v-else-if="results && results.type === 'words'" class="cloud">
			<span v-for="row in results.results" :key="row.word" class="cloud-word"
				:style="{ fontSize: cloudEm(row.count) + 'em', opacity: cloudOpacity(row.count) }">
				{{ row.word }}
			</span>
			<!-- The public tally carries the 100 most frequent words (PublicPayload). -->
			<span v-if="cloudMore" class="cloud-more">{{ t('pulse', '+{count} more', { count: cloudMore }) }}</span>
			<p v-if="!results.results.length" class="cloud-empty">{{ t('pulse', 'No words yet.') }}</p>
		</div>

		<!-- Scale · Spectrum: radar (avg. outline + spread band + neutral ring + "You") — numbered spokes + figures column, SVG viewBox scales phone→projector -->
		<div v-else-if="results && results.type === 'scale' && spectrumMode" class="spectrum-radar" :class="{ 'is-wide': wide }">
			<VizEmpty v-if="!total" />
			<!-- Projector: names at the spokes, no looking up numbers (§7.3).
			     Below three aspects no area is spanned — then it is
			     rows instead of a radar. Today the editor only allows 3 to 8;
			     the branch is the honest fallback instead of an error message. -->
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

		<!-- Scale · Compass: quadrant scatter/heat map + centre of gravity + "You" — chart + figures column, SVG viewBox scales phone→projector -->
		<div v-else-if="results && results.type === 'scale' && compassMode" class="compass-result" :class="{ 'is-wide': wide }">
			<VizEmpty v-if="!total" />
			<!-- Projector: square field across the full stage height, dots up to
			     the threshold, a heat map above it. No "You" — on the big screen there
			     is no me (§7.4). -->
			<StageCompass v-else-if="wide"
				:points="results.points || []" :point-count="results.pointsTotal || 0" :centroid="results.centroid"
				:range="results.range || 5" :threshold="heatmapThreshold"
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

		<!-- Scale · Single: distribution with avg./median marker INSIDE the distribution -->
		<div v-else-if="results && results.type === 'scale'" class="dist" :class="{ 'bars--stage': wide }">
			<VizEmpty v-if="!total" />
			<!-- Projector: columns across the full stage height, median above and
			     mean below the columns (§7.2). -->
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
/*
 * Section references (§…) point to the design notes of the redesign and of the
 * self-paced quiz, which are not in the public repository (see "References in
 * code comments" in the README).
 */
import { fmtNum, hasTallLabel, HEATMAP_THRESHOLD } from '../util/format.js'
import { option, rampStep, withPalette } from '../util/palette.js'
import PulseIcon from './ui/PulseIcon.vue'
import StageCompass from './StageCompass.vue'
import StageHistogram from './StageHistogram.vue'
import StageRadar from './StageRadar.vue'
import StageRow from './StageRow.vue'
import StageStack from './StageStack.vue'
import TextGrading from './TextGrading.vue'
import VizEmpty from './ui/VizEmpty.vue'

export default {
	name: 'ResultsView',
	components: { PulseIcon, StageCompass, StageHistogram, StageRadar, StageRow, StageStack, TextGrading, VizEmpty },
	props: {
		results: { type: Object, default: null },
		// Quiz choice/truefalse: option ID of the correct answer -> green.
		correctId: { type: String, default: '' },
		// Quiz multi: several correct option IDs.
		correctIds: { type: Array, default: () => [] },
		// Quiz number/text: target number + tolerance, or accepted answers.
		answerKey: { type: Object, default: null },
		// Compass: own submitted position ({x,y}) — only set locally on one's own device.
		mine: { type: Object, default: null },
		// Spectrum: own submitted values (aspectId -> value) for the "You" polygon.
		mineAspects: { type: Object, default: null },
		// Projector layout (wide): stage rows and Stage* charts fill the stage height; no figures column, notes or vote total.
		wide: { type: Boolean, default: false },
		// Moderator's preview panel: narrow, but saying the same as the
		// big screen. Matching shows one stacked row per pair here instead of
		// a list of bars per target — eight pairs times eight targets would be 64 bars in
		// a 340 px column, and the order of the question would get lost in them.
		dense: { type: Boolean, default: false },
	},
	computed: {
		total() {
			return this.results ? this.results.total : 0
		},
		// Row heads of the stacked stage sit in a fixed column (9 em),
		// so all bars start at the same edge. Names like "Container
		// orchestration" ended in an ellipsis there while there was space
		// on the right: from 14 characters on, the column gets wider and two lines tall.
		longHeads() {
			const rows = (this.results && this.results.results) || []
			return rows.some((r) => String(r.label || '').length > 14)
		},
		// Word cloud total: "N mentions · M different" (§7 grammar).
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
		// Two-line row height for the whole stage as soon as ONE label is long.
		tallLabels() {
			return hasTallLabel((this.results && this.results.results) || [])
		},
		// Choice/multi rows with a stable palette (letter + colour A–H) —
		// the same mapping as the live tile (projector) and the phone (util/palette.js).
		choiceRows() {
			return withPalette(this.results && this.results.results)
		},
		// Is there a "correct answer" at all (quiz) -> green instead of the winner mark.
		hasCorrect() {
			return !!this.correctId || (Array.isArray(this.correctIds) && this.correctIds.length > 0)
		},
		// Ranking: is there a solution (quiz, only delivered with the reveal)?
		hasRankKey() {
			return !!(this.answerKey && Array.isArray(this.answerKey.order) && this.answerKey.order.length)
		},
		// Matching: a solution exists only in a quiz and only after the reveal.
		hasMatchKey() {
			return !!(this.answerKey && this.answerKey.map && Object.keys(this.answerKey.map).length)
		},
		numTarget() {
			return this.answerKey && this.answerKey.target !== undefined ? this.answerKey.target : '?'
		},
		numTol() {
			return this.answerKey && this.answerKey.tolerance ? this.answerKey.tolerance : 0
		},
		// Avg. of the submitted guesses (weighted by frequency).
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
		// Median of the scale votes — from cumulative counts (no value array needed).
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
		// Avg./median as markers INSIDE the distribution: value -> centre of its column.
		// The columns sit next to each other without gaps (the spacing is INSIDE the column
		// box), so value v is at ((v-min)+0.5)/N — also for fractional values.
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
		// Spectrum on the projector: aspects with a palette, so the special case "two
		// aspects" uses the same row grammar as a choice question (§7.3).
		spectrumRows() {
			return withPalette(this.spectrumMode ? (this.results.results || []) : [])
		},
		// Spectrum result: radar from aspect avg./min/max (centre=0, edge=X).
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
			// Square viewBox (440×440): the aspect names are NO longer at the
			// edge (a source of clipping — long names lost their first letters), but as number discs
			// at the spoke + the spelled-out mapping in radarItems (figures column).
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
			// Number discs at the outer end of the spokes (1..N) — short, never clips.
			const nums = rows.map((row, i) => {
				const a = ang(i)
				return {
					n: i + 1,
					x: r1(cx + (R + 20) * Math.cos(a)),
					y: r1(cy + (R + 20) * Math.sin(a)),
					dot: pt(row.average, i),
				}
			})
			// "You" polygon only when there is an own value for EVERY aspect.
			let minePoints = null
			const ma = this.mineAspects
			if (ma && rows.every((row) => typeof ma[row.id] === 'number')) {
				minePoints = rows.map((row, i) => pt(ma[row.id], i).join(',')).join(' ')
			}
			return { cx, cy, R, neutral: r1(0.5 * R), rings, spokes, avgPoints, band, nums, minePoints }
		},
		// Figures column for the radar: number → aspect name → avg. (decimal comma in German).
		radarItems() {
			const rows = this.results && this.results.results
			if (!this.spectrumMode || !rows) return []
			return rows.map((row, i) => ({
				n: i + 1,
				label: row.label,
				avg: fmtNum(row.average, 1, 1),
			}))
		},
		// Compass result: quadrant + scatter/heat map + centre of gravity + own dot.
		compassMode() {
			return !!this.results && this.results.mode === 'compass'
		},
		// Words the server left out of the public tally (resultsTotal counts all).
		cloudMore() {
			const r = this.results
			if (!r || r.type !== 'words' || !Array.isArray(r.results)) return 0
			return Math.max(0, (r.resultsTotal || 0) - r.results.length)
		},
		// From this many answers a heat map instead of single dots: ONE value
		// for the phone and moderator field (compass) and the projector
		// (StageCompass). The server always sends it; the constant is the same
		// default for a result without it.
		heatmapThreshold() {
			return (this.results && this.results.heatmapThreshold) || HEATMAP_THRESHOLD
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
			// Stored order [bottom-left, bottom-right, top-left, top-right]
			// (DeckService::buildCorners), the same on the projector
			// (StageCompass); dev/unit/question-types.test.mjs compares them.
			const cl = this.results.cornerLabels || []
			const corners = []
			if (cl[0]) corners.push({ x: r1(sx(-R) + 6), y: r1(sy(-R) - 8), anchor: 'start', t: cl[0] })
			if (cl[1]) corners.push({ x: r1(sx(R) - 6), y: r1(sy(-R) - 8), anchor: 'end', t: cl[1] })
			if (cl[2]) corners.push({ x: r1(sx(-R) + 6), y: r1(sy(R) + 16), anchor: 'start', t: cl[2] })
			if (cl[3]) corners.push({ x: r1(sx(R) - 6), y: r1(sy(R) + 16), anchor: 'end', t: cl[3] })
			const total = this.results.total || 0
			const pts = this.results.points || []
			const heatmap = total >= this.heatmapThreshold
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
		// Figures column for the compass: centre of gravity + "You" as a large tabular
		// X/Y readout (values in the column instead of at the edge, §1 projector layout).
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
		// Uniform figure format: 1 decimal place in the language of the
		// UI, tabular digits (number guess + scale share it, §7 grammar).
		num(v) {
			if (v === null || v === undefined) return '–'
			return fmtNum(v)
		},
		// Solution encoding of the stage row (§4.2): correct = fully saturated +
		// ring + check mark + word, wrong = desaturated. Without a solution (poll)
		// the row stays neutral; only the word marks the lead.
		choiceState(row) {
			if (this.isCorrect(row.id)) return 'correct'
			return this.hasCorrect ? 'wrong' : ''
		},
		choiceFlag(row) {
			if (this.isCorrect(row.id)) return this.t('pulse', 'correct')
			if (!this.hasCorrect && this.isWinner(row.count)) return this.t('pulse', 'leading')
			return ''
		},
		// Winner = most votes (tie -> all marked).
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
		// Share of the total votes (§5.1: one bar norm).
		// Bar length: avg. place 1 = full, last place = short.
		rankPct(row) {
			const count = (this.results && this.results.results) ? this.results.results.length : 0
			if (!row.n || count < 2) return row.n ? 100 : 0
			return Math.max(4, Math.round((1 - (row.average - 1) / (count - 1)) * 100))
		},
		// Spectrum row: avg. as a share of the scale (only the two-aspect case).
		spectrumPct(row) {
			const max = (this.results && this.results.max) || 1
			return Math.max(0, Math.min(100, Math.round((row.average / max) * 100)))
		},
		// Place distribution of a ranking row: segment k = share of the votes
		// that put this item in place k. Places are ordered ->
		// lightness ramp, no palette (§7.0).
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
					// Check mark in the segment, not at the start of the row (§7.6).
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
		// Matching: all targets of the row as segments. Targets are unordered ->
		// palette A–H (§7.0). In a quiz the correct segment carries the solution
		// encoding, the others are desaturated (§7.6).
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
		// On the right is the top of the row — the figure on which the room
		// agrees (or not).
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
		// Target place (1-based) of this answer according to the solution.
		rankTarget(id) {
			if (!this.hasRankKey) return null
			return this.answerKey.order.indexOf(id) + 1
		},
		// Is the answer in its correct place in the consensus?
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
		// Only show chosen targets — plus the correct one, even if nobody
		// picked it (otherwise exactly the row that matters is missing at the reveal).
		matchCells(row) {
			const wanted = this.matchKeyOf(row.id)
			const shown = row.targets.filter((cell) => cell.count > 0 || cell.id === wanted)
			if (!this.wide) return shown
			// Projector: the top of the row (both leaders in a tie) and
			// the correct target. The rest is unreadable from a distance anyway.
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
		// Distribution types: bars relative to the most frequent value (histogram).
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
/* Matching: per item a small block with bars below it. */
.matchres { display: flex; flex-direction: column; gap: 1em; }
.mres-head { display: flex; align-items: center; gap: 0.6em; flex-wrap: wrap; margin-bottom: 0.35em; }
.mres-item { font-weight: 700; }
.bars--match { gap: 0.45em; }
/* Projector: two columns from five rows on, so eight pairs fit in the height.
   align-content:start — the rows should sit at the top, not be stretched. */
.matchres.is-cols { display: grid; grid-template-columns: 1fr 1fr; align-content: start; gap: 0.9em clamp(1.5em, 4vw, 3em); }
/* Preview panel: one row per pair, head above it. The value sits in the head,
   not next to the bar — in 340 px a value column of its own would take a fifth. */
.matchres.is-dense { gap: 0.7em; }
.matchres.is-dense .mres-head { margin-bottom: 0.25em; }
.matchres.is-dense .mres-lead { margin-left: auto; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
.matchres.is-dense :deep(.sstack) { height: 1.9em; }

/* All sizes in em -> the component scales via the container font-size:
   small on the phone, large on the projector (§5.4, the fix for "reveal stays too small"). */
.pulse-results { width: 100%; font-size: inherit; }

/* Stage (projector, §2.4): the rows get the remaining height and share it
   among themselves — four options fill the screen, eight shrink down to the minimum
   and after that the shrink loop takes over. */
.bars--stage { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
.matchres.is-wide { flex: 1 1 auto; min-height: 0; }

/* Stacked stage rows (§7.5/§7.6): like .srows--stretch they share the
   remaining height, so that four pairs fill the screen and eight still fit. */
.sstacks { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; justify-content: center; gap: 0.5em; }
/* Like the row stage (§2.4) the rows share the remaining height, but stay
   capped — otherwise four rows would turn into bands. */
.sstacks > * { flex: 1 1 2.4em; min-height: 2.2em; max-height: 3.4em; }
/* Long row heads: wider column, two lines, no ellipsis. */
.sstacks.is-longhead :deep(.sstack-head) {
	flex-basis: 12em; width: 12em;
	white-space: normal; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
}
.sstacks :deep(.sstack) { height: 100%; max-height: 3.2em; }

/* Number guess on the projector: figures as one row in class B. */
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
	/* Letter badge in the option colour (palette bridge). */
	flex: 0 0 auto; font-size: 0.85em;
	background: var(--opt-fill, var(--pulse-fill)); color: var(--opt-ink, var(--pulse-text));
}
.bar-flag { flex: 0 0 auto; display: inline-flex; align-items: center; gap: 0.2em; font-weight: 800; font-size: 0.8em; }
.bar-flag.is-correct { color: var(--pulse-success); }
.bar-flag.is-winner { color: var(--pulse-warning); }
.is-correct .bar-text, .is-winner .bar-text { font-weight: 800; }
/* Only dim the old two-line bars. The stage row (§2.4) encodes
   "wrong" through the saturation of the fill — extra opacity on the
   whole row would also take the text's contrast away. */
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
	/* Palette bridge: bar in the option colour (not blanket primary blue). */
	background: var(--opt-fill, var(--pulse-primary));
	transition: width 0.7s cubic-bezier(.22, 1, .36, 1);
}
/* Distribution bars (scale/number) carry no option palette -> primary. */
.bars--dist .bar-fill { background: var(--pulse-primary); }

.ranks { display: flex; flex-direction: column; gap: 0.7em; }
.rank-row .bar-fill { background: var(--pulse-viz-1, var(--pulse-primary)); }
.rank-row.is-correct .bar-fill { background: var(--pulse-success); }
.rank-row.is-wrong .bar-fill { opacity: 0.5; }
.rank-first { color: var(--pulse-text-2); }
.norm-note { margin: 0.4em 0 0; font-size: 0.8em; color: var(--pulse-text-2); }

/* Distribution figures (avg., target number) */
.dist-metrics { display: flex; flex-wrap: wrap; gap: 1.5em; justify-content: center; margin-bottom: 1.3em; }
.metric { text-align: center; }
.metric-val { display: block; font-size: 3.4em; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; color: var(--pulse-primary); }
.metric--target .metric-val { color: var(--pulse-success); }
.metric-val--sub { font-size: 2.4em; }
.metric-label { font-size: 0.85em; color: var(--pulse-text-2); }
.bar-label em { font-style: normal; color: var(--pulse-text-2); font-weight: 400; }

/* Scale histogram (handoff §6; design notes, not in the public repository) —
   everything in em, scales phone -> projector.
   NO gap: the spacing is in the column width (62 % of the column). Only then
   does value v sit exactly at ((v-min)+0.5)/N — the formula used to position the avg./median
   markers. With a gap they would drift against the columns. */
.hist-wrap { position: relative; }
.hist { display: flex; align-items: flex-end; gap: 0; margin-top: 0.4em; border-bottom: 1px solid var(--pulse-border-strong); }
.hist-col { flex: 1 1 0; min-width: 0; display: flex; flex-direction: column; align-items: center; gap: 0.3em; }
.hist-cnt { font-size: 0.72em; line-height: 1; min-height: 1em; color: var(--pulse-text-2); font-variant-numeric: tabular-nums; }
.hist-track { width: 100%; height: 6.5em; display: flex; align-items: flex-end; }
.hist-bar { width: 62%; min-height: 0.15em; border-radius: 0.25em 0.25em 0 0; background: var(--pulse-viz); transition: height 0.7s cubic-bezier(.22, 1, .36, 1); }
/* Values without votes stay as a quiet plinth -> a complete axis
   instead of a lonely column in empty space. */
.hist-bar.is-zero { height: 0.3em; background: var(--pulse-border-strong); border-radius: 0.15em; }

/* Avg./median marker: a line through the distribution + a small flag on top.
   Avg. solid in the data colour, median dashed in the text colour —
   distinguishable by SHAPE, not only by colour (AA). */
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

/* Scale · spectrum radar (handoff §4; design notes, not in the public
   repository) — SVG viewBox scales phone→projector. */
.spectrum-radar { display: flex; flex-direction: column; align-items: stretch; }
.radar-main { display: flex; flex-direction: column; align-items: center; gap: 0.6em; }
.radar-wrap { width: 100%; }
.radar-wrap svg { width: 100%; max-width: 42em; height: auto; display: block; margin: 0 auto; }
/* Number discs at the spoke end — replace the text at the edge (no clipping). */
.rnum-bg { fill: var(--pulse-viz); stroke: var(--pulse-bg); stroke-width: 2; }
.rnum { fill: var(--pulse-bg); font-family: var(--pulse-mono); font-weight: 800; font-size: 15px; }
/* Figures column: number → aspect → avg., below the chart. */
.viz-col { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.4em; min-width: 0; }
.viz-item { display: flex; align-items: baseline; gap: 0.55em; min-width: 0; }
.viz-num { flex: 0 0 auto; width: 1.7em; height: 1.7em; align-self: center; border-radius: 50%; display: inline-grid; place-items: center; background: var(--pulse-viz); color: var(--pulse-bg); font-family: var(--pulse-mono); font-weight: 800; font-size: 0.72em; }
.viz-label { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 700; }
.viz-val { flex: 0 0 auto; font-variant-numeric: tabular-nums; font-weight: 800; color: var(--pulse-viz); white-space: nowrap; }

/* Projector: the container takes the rest of the stage height; StageRadar and StageCompass size the chart. */
.spectrum-radar.is-wide, .compass-result.is-wide { flex: 1 1 auto; min-height: 0; }
.rlegend { display: flex; flex-wrap: wrap; gap: 1em; justify-content: center; margin-top: 0.6em; font-size: 0.8em; color: var(--pulse-text-2); }
.rsw { display: inline-block; width: 1.1em; height: 0.8em; border-radius: 0.2em; vertical-align: -0.1em; margin-right: 0.4em; }
.rsw-avg { background: var(--pulse-viz); }
.rsw-band { background: var(--pulse-viz-2); opacity: 0.7; }
.rsw-neutral { background: transparent; border: 1.5px dashed var(--pulse-text-2); }
.rsw-mine { background: transparent; border: 1.5px dashed var(--pulse-self); }

/* Scale · compass field — SVG viewBox scales phone→projector. */
.compass-result { display: flex; flex-direction: column; align-items: stretch; }
.compass-main { display: flex; flex-direction: column; align-items: center; gap: 0.6em; }
.compass-wrap { width: 100%; }
.compass-wrap svg { width: 100%; max-width: 40em; height: auto; display: block; margin: 0 auto; }
/* Compass figures: centre of gravity + "You" as a large X/Y readout. */
.compass-readout { align-items: center; gap: 0.9em; }
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
.cloud-more { color: var(--pulse-text-2); font-size: 0.85em; font-weight: 600; }

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
