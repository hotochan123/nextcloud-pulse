<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pulse-lb" :class="{ 'is-animating': animate, 'is-split': split, 'is-solo': split && !listRows.length }">
		<p v-if="!rows.length" class="lb-empty">{{ t('pulse', 'No points yet.') }}</p>

		<!-- Final standings on the projector (§4.3): two columns. This uses the wide format,
		     halves the height needed and brings back places 4–8, which used to be
		     cut off by overflow:hidden (B1). The rank appears exactly
		     once per person — on the podium or in the badge. -->
		<template v-if="split && rows.length">
			<div class="podium-stage">
				<ol class="podium">
					<li v-for="(p, i) in podiumCols" :key="'sp' + i" class="podium-col" :class="'c' + p.rank" :style="{ order: p.order }">
						<div class="win-card" :class="{ 'you-ring': p.me }">
							<span class="podium-name">
								<span class="podium-name-txt">{{ p.nickname }}</span>
								<span v-if="p.me" class="pulse-du lb-du">{{ t('pulse', 'You') }}</span>
								<span v-if="p.shared" class="lb-tie">{{ t('pulse', 'shared') }}</span>
							</span>
							<span class="podium-score">{{ p.score }}</span>
						</div>
						<div class="riser" :class="metalCls(p.rank)"><span class="riser-rank" aria-hidden="true">{{ p.rank }}</span></div>
					</li>
				</ol>
			</div>
			<div v-if="listRows.length" class="lb-col">
				<div class="srows">
					<StageRow v-for="(r, i) in listRows" :key="'sr' + i"
						:badge="r.rank" :label="r.nickname" :value="String(r.score)" :pct="0" />
				</div>
				<p v-if="restCount" class="lb-rest">{{ t('pulse', '+{count} more', { count: restCount }) }}</p>
			</div>
		</template>

		<!-- Podium (top 3) — real podiums with a metal accent, staggered entrance,
		     "You" as a self ring + pill (design notes §2, not in the public repository). Visual order 2–1–3,
		     the animation runs by rank (bronze first, winner last). -->
		<div v-if="!split && podium && podiumCols.length" class="podium-stage">
			<div class="podium-glow" aria-hidden="true" />
			<ol class="podium">
				<li v-for="(p, i) in podiumCols" :key="'p' + i" class="podium-col" :class="'c' + p.rank" :style="{ order: p.order }">
					<div class="win-card" :class="{ 'you-ring': p.me }">
						<span class="medal" :class="metalCls(p.rank)" aria-hidden="true">{{ p.rank }}</span>
						<span class="podium-name">
							<span class="podium-name-txt">{{ p.nickname }}</span>
							<span v-if="p.me" class="pulse-du lb-du">{{ t('pulse', 'You') }}</span>
							<span v-if="p.shared" class="lb-tie">{{ t('pulse', 'shared') }}</span>
						</span>
						<span class="podium-score">{{ p.score }}</span>
					</div>
					<div class="riser" :class="metalCls(p.rank)"><span class="riser-rank" aria-hidden="true">{{ p.rank }}</span></div>
				</li>
			</ol>
		</div>

		<!-- List (from the first rank not on the podium – or complete if there is no podium) -->
		<ol v-if="!split" class="lb-list">
			<li v-for="(r, i) in listRows" :key="r.nickname + i" class="lb-row" :class="{ 'is-me': r.me }">
				<span class="lb-rank">{{ r.rank }}</span>
				<span class="lb-name"><span class="lb-name-txt">{{ r.nickname }}</span><span v-if="r.me" class="pulse-du lb-du">{{ t('pulse', 'You') }}</span><span v-if="r.shared" class="lb-tie">{{ t('pulse', 'shared') }}</span></span>
				<span class="lb-correct">{{ t('pulse', '{count} correct', { count: r.correct }) }}</span>
				<span class="lb-score">{{ r.score }}</span>
			</li>
		</ol>
	</div>
</template>

<script>
/*
 * Section references (§…) and review IDs (B1) point to the design notes of the
 * redesign and of the self-paced quiz, which are not in the public repository
 * (see "References in code comments" in the README).
 */
import StageRow from './StageRow.vue'

export default {
	name: 'Leaderboard',
	components: { StageRow },
	props: {
		rows: { type: Array, default: () => [] },
		podium: { type: Boolean, default: false },
		// How many rows the list shows (top N); one's own row is appended if needed.
		limit: { type: Number, default: 10 },
		// Final standings on the projector: podium on the left, places 4–8 on the right (§4.3).
		split: { type: Boolean, default: false },
		// Only show the top, do NOT append one's own row — at the end of the quiz
		// it appears below in the "Around you" excerpt (§8.7).
		topOnly: { type: Boolean, default: false },
	},
	data() {
		return {
			// One-shot switch: the entrance animation should run ONCE when it appears,
			// not on every polling update (otherwise the podium fidgets).
			// The CSS animations have fill-mode `both` -> the end state stays.
			animate: false,
		}
	},
	computed: {
		// Shared ranks (ex aequo): rank -> count. > 1 -> "shared" marker.
		sharedRanks() {
			const count = {}
			for (const r of this.rows) count[r.rank] = (count[r.rank] || 0) + 1
			return count
		},
		// Podium: people with rank ≤ 3, at most 3 columns. Placement by sort
		// index (0 = best -> centre, 1 -> left, 2 -> right), NOT by rank — otherwise
		// two shared rank-2 entries collide on the same position (bug 21-49-53). With
		// 1-2-2 two silvers thus flank the gold; bronze drops out automatically because
		// there is no rank 3. "shared" marks shared ranks.
		podiumCols() {
			if (!this.podium) return []
			const cols = this.rows.filter((r) => r.rank <= 3).slice(0, 3)
			// Winner dominant: 3 columns -> centre; 2 -> left; 1 -> centred (flex).
			const orderMap = { 1: [1], 2: [1, 2], 3: [2, 1, 3] }
			const orderFor = orderMap[cols.length] || cols.map((_, k) => k + 1)
			return cols.map((r, i) => ({
				...r,
				order: orderFor[i] || (i + 1),
				shared: (this.sharedRanks[r.rank] || 0) > 1,
			}))
		},
		listRows() {
			// With a podium skip the podium people, otherwise all – cut to limit,
			// but always keep one's own row visible. shared for the "shared" chip.
			const start = this.podium ? this.podiumCols.length : 0
			const rest = this.rows.slice(start)
			const capped = rest.slice(0, this.limit)
			// On the projector there is no "me": the appended own row would be the
			// row of whoever happens to operate the browser (§7.4).
			const me = (this.split || this.topOnly) ? null : rest.find((r) => r.me)
			if (me && !capped.includes(me)) capped.push(me)
			return capped.map((r) => ({ ...r, shared: (this.sharedRanks[r.rank] || 0) > 1 }))
		},
		// How many people are still behind the rows shown —
		// "+N more" in the final standings (§4.3).
		restCount() {
			const shown = (this.podium ? this.podiumCols.length : 0) + this.listRows.length
			return Math.max(0, this.rows.length - shown)
		},
	},
	mounted() {
		// Switch on after the first paint -> the keyframes apply exactly once.
		this.$nextTick(() => requestAnimationFrame(() => { this.animate = true }))
	},
	methods: {
		// Metal class: gold/silver/bronze for medal and podium.
		metalCls(rank) {
			return rank === 1 ? 'is-gold' : rank === 2 ? 'is-silver' : 'is-bronze'
		},
	},
}
</script>

<style scoped>
/* em -> scales via the container font-size (moderator small, projector large). */
.pulse-lb { width: 100%; font-size: inherit; }
.lb-empty { color: var(--pulse-text-2); text-align: center; }

/* ── Final standings on the projector (§4.3) ──────────────────────────────
   Two columns: podium on the left, places 4–8 on the right in the row model from §2.4 —
   no component of its own. Height calculation: left column ≈ 9.5 em, right
   5 × 2.4 em + 4 × 0.5 em = 14 em; 14 em is what counts. */
.is-split { display: grid; grid-template-columns: 1fr 1fr; gap: 2em; align-items: center; height: 100%; min-height: 0; }
.is-split.is-solo { grid-template-columns: 1fr; }
/* Without min-height/min-width 0 a grid column grows to its minimum width
   (three podiums side by side) and pushes the other column out of view. */
.is-split > * { min-width: 0; }
.is-split .podium-stage { margin: 0; }
.is-split .podium { gap: 1em; min-width: 0; }
.is-split .podium-col { flex: 1 1 0; max-width: 8em; min-width: 0; }
/* Wrap names instead of truncating: "Peregrine falcon" with an ellipsis is
   useless from 10 m, but readable on two lines. */
.is-split .podium-name { flex-wrap: wrap; justify-content: center; text-align: center; }
.is-split .podium-name-txt { white-space: normal; overflow: visible; text-overflow: clip; overflow-wrap: anywhere; line-height: 1.15; }
/* The podium carried the rank number as its own font size (1.6 em) — and because
   its height is expressed in em, that made it 1.6 times too tall. The size
   now sits on the number, the height is calculated against the stage. */
.is-split .riser { width: 5.5em; margin-inline: auto; font-size: 1em; }
.is-split .riser-rank { font-size: 1.6em; }
.is-split .podium-name { font-weight: 700; }
.is-split .podium-score { font-size: 1.2em; }
.is-split .riser.is-gold { height: 6em; }
.is-split .riser.is-silver { height: 4.8em; }
.is-split .riser.is-bronze { height: 4em; }
.lb-col { min-width: 0; }
.lb-rest { margin: 0.5em 0 0; font-size: 0.5em; color: var(--pulse-meta); }

/* ── Podium (design notes §2, not in the public repository) ───────────────── */
.podium-stage { position: relative; margin: 0 0 1.8em; }
/* Subtle gold glow behind place 1 (no confetti). */
.podium-glow {
	position: absolute; left: 50%; top: 4%; width: 62%; height: 78%;
	transform: translateX(-50%); pointer-events: none; opacity: 0;
	background: radial-gradient(60% 60% at 50% 32%,
		color-mix(in oklab, var(--pulse-gold) 42%, transparent) 0%, transparent 70%);
}
.is-animating .podium-glow { animation: pulse-glow 1400ms ease-out 4.35s both; }

.podium { list-style: none; display: flex; align-items: flex-end; justify-content: center; gap: 0.9em; margin: 0; padding: 0; }
.podium-col { display: flex; flex-direction: column; align-items: center; flex: 0 1 8em; min-width: 0; }
.win-card { display: flex; flex-direction: column; align-items: center; gap: 0.3em; padding: 0.35em 0.5em; border-radius: var(--pulse-r-card); max-width: 100%; min-width: 0; }
/* "You" on the podium: self ring around the winner card. */
.win-card.you-ring { outline: 3px solid var(--pulse-self); outline-offset: 3px; }

.medal {
	width: 2.1em; height: 2.1em; border-radius: 50%; display: grid; place-items: center;
	font-family: var(--pulse-mono); font-weight: 800; font-size: 1em; line-height: 1;
	border: 2px solid var(--pulse-bg); box-shadow: 0 2px 6px rgba(0, 0, 0, 0.28);
}
.medal.is-gold { background: radial-gradient(circle at 35% 30%, var(--pulse-gold-2), var(--pulse-gold)); color: #3a2a00; }
.medal.is-silver { background: radial-gradient(circle at 35% 30%, var(--pulse-silver-2), var(--pulse-silver)); color: #25292e; }
.medal.is-bronze { background: radial-gradient(circle at 35% 30%, var(--pulse-bronze-2), var(--pulse-bronze)); color: #2e1a0c; }
.is-animating .medal { animation: pulse-pop 460ms cubic-bezier(.34, 1.56, .64, 1) both; }

.podium-name { display: inline-flex; align-items: center; gap: 0.4em; font-weight: 700; max-width: 100%; min-width: 0; }
.podium-name-txt { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.podium-score { font-variant-numeric: tabular-nums; font-weight: 800; color: var(--pulse-primary); }

.riser {
	width: 100%; margin-top: 0.4em; border-radius: var(--pulse-r-el) var(--pulse-r-el) 0 0;
	display: grid; place-items: center;
	color: var(--pulse-bg); font-family: var(--pulse-mono); font-weight: 800; font-size: 1.6em; line-height: 1;
}
.riser.is-gold { height: 5.4em; background: linear-gradient(180deg, var(--pulse-gold), color-mix(in oklab, var(--pulse-gold) 74%, #000)); }
.riser.is-silver { height: 3.7em; background: linear-gradient(180deg, var(--pulse-silver), color-mix(in oklab, var(--pulse-silver) 74%, #000)); }
.riser.is-bronze { height: 2.4em; background: linear-gradient(180deg, var(--pulse-bronze), color-mix(in oklab, var(--pulse-bronze) 74%, #000)); }
.riser-rank { opacity: 0.85; }

/* Staggered entrance: bronze (c3) first, winner (c1) last. Delays apply
   per RANK (class), independent of the visual order via `order`. */
.is-animating .podium-col { animation: pulse-rise 620ms cubic-bezier(.2, .7, .2, 1) both; }
.is-animating .podium-col.c3 { animation-delay: 500ms; }
.is-animating .podium-col.c2 { animation-delay: 2300ms; }
.is-animating .podium-col.c1 { animation-delay: 4300ms; }
.is-animating .podium-col.c3 .medal { animation-delay: 1050ms; }
.is-animating .podium-col.c2 .medal { animation-delay: 2850ms; }
.is-animating .podium-col.c1 .medal { animation-delay: 4850ms; }

@keyframes pulse-rise { from { opacity: 0; transform: translateY(2.2em); } to { opacity: 1; transform: none; } }
@keyframes pulse-pop { 0% { transform: scale(0); } 70% { transform: scale(1.18); } 100% { transform: scale(1); } }
@keyframes pulse-glow { from { opacity: 0; } 40% { opacity: 1; } to { opacity: 0.65; } }

.lb-list { list-style: none; margin: 0; padding: 0; }
.lb-row {
	display: flex; align-items: center; gap: 0.75em;
	padding: 0.6em 0.9em; margin-bottom: 0.4em;
	background: var(--pulse-hover);
	border: 2px solid transparent;
	border-radius: var(--pulse-r-el);
}
/* One's own row in the "You" colour (§6: "You" is the same colour everywhere). */
.lb-row.is-me { border-color: var(--pulse-self); background: var(--pulse-self-soft); }
.lb-rank { font-weight: 800; color: var(--pulse-text-2); min-width: 1.5em; font-variant-numeric: tabular-nums; text-align: right; }
.lb-name { flex: 1; min-width: 0; display: inline-flex; align-items: center; gap: 0.5em; font-weight: 600; }
.lb-name-txt { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lb-correct { color: var(--pulse-text-2); font-size: 0.85em; font-variant-numeric: tabular-nums; white-space: nowrap; }
.lb-score { font-weight: 800; font-variant-numeric: tabular-nums; min-width: 3em; text-align: right; color: var(--pulse-primary); }

/* "You" pill in the self colour — overrides the global (primary blue) .pulse-du. */
.lb-du { background: var(--pulse-self-soft); color: var(--pulse-self); }

/* "shared" chip on a points tie (ex aequo) — neutral, carries the message
   in addition to the same medal number/height (colour is never the only carrier). */
.lb-tie { flex: 0 0 auto; font-size: 0.7em; font-weight: 700; padding: 0.1em 0.5em; border-radius: var(--pulse-r-pill); background: var(--pulse-fill); color: var(--pulse-text); white-space: nowrap; }

@media (prefers-reduced-motion: reduce) {
	.is-animating .podium-col,
	.is-animating .medal,
	.is-animating .podium-glow { animation: none !important; }
	.is-animating .podium-glow { opacity: 0.5; }
}
</style>
