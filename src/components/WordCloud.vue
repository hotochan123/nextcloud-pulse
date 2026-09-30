<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pl-cloud">
		<div ref="wrap" class="pl-cloud-wrap">
			<!-- The engine writes the word <span>s into this container. -->
			<div ref="cloud" class="pl-cloud-canvas" role="img" :aria-label="ariaLabel" />

			<!-- Empty state (declarative, rendered by Vue, not by the engine) -->
			<div v-if="isEmpty" class="pl-cloud-empty">
				<PulseIcon name="edit" size="clamp(40px, 5vw, 72px)" />
				<b>{{ t('pulse', 'No answers yet') }}</b>
				<span>{{ t('pulse', 'Scan the code and type your word.') }}</span>
			</div>

			<!-- Honest hint at capped top-N words (no silent dropping) -->
			<div v-if="overflowN > 0" class="pl-cloud-overflow">{{ t('pulse', '+{count} more', { count: overflowN }) }}</div>
		</div>

		<!-- Counter row: words · mentions · here (moved out on the projector) -->
		<div v-if="!isEmpty && showFooter" class="pl-cloud-foot">
			<span class="pl-stat"><b>{{ cWords }}</b> {{ n('pulse', 'word', 'words', cWords) }}</span>
			<span class="pl-stat"><b>{{ cVotes }}</b> {{ n('pulse', 'mention', 'mentions', cVotes) }}</span>
			<span v-if="cPeople" class="pl-stat"><b>{{ cPeople }}</b> {{ t('pulse', 'here') }}</span>
		</div>

		<!-- Screen reader live region: most frequent answers as text -->
		<p class="pl-sr-only" aria-live="polite">{{ srText }}</p>
	</div>
</template>

<script>
/*
 * Section references (§…) point to the design notes of the redesign, which are
 * not in the public repository (see "References in code comments" in the
 * README).
 */
import { t } from '../util/l10n.js'
import PulseIcon from './ui/PulseIcon.vue'

// ── Engine constants (from the framework-free reference, §7) ───────────────
const PALETTE_VARS = ['--w1', '--w2', '--w3', '--w4', '--w5', '--w6', '--w7', '--w8']
const GAP = 9 // minimum gap between word boxes (px)
const MARGIN = 10 // safety margin to the container (px)
const MAX_VISIBLE = 90 // hard cap on visible words — the rest shows as "+{count} more"
// The measuring font must match the rendered font EXACTLY, otherwise
// canvas measures wrong. We use the same system stack as the .word rule below
// (no external font -> CSP-safe, and the measurement is right).
const FONT_FAMILY = 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'

// ── Demo/test mode (client-side, no server) ───────────────────────────────
// Generates a living cloud locally so that look/animation can be checked
// without real participants. To activate: projector URL with ?demo=1.
const DEMO_SEED = [['Vertrauen', 5], ['Zukunft', 7], ['Erinnerung', 9], ['Verantwortung', 6], ['Dialog', 4], ['Frieden', 3], ['Familie', 3], ['Hoffnung', 2]]
const DEMO_POOL = [
	t('pulse', 'Justice'), t('pulse', 'Courage'), t('pulse', 'Home'), t('pulse', 'Freedom'),
	t('pulse', 'Witnesses'), t('pulse', 'Warning'), t('pulse', 'Learning'), t('pulse', 'Transformation'),
	t('pulse', 'Community'), t('pulse', 'Respect'), t('pulse', 'History'), t('pulse', 'Preserving'),
	t('pulse', 'Voice'), t('pulse', 'Truth'), t('pulse', 'Photos'), t('pulse', 'Loss'),
	t('pulse', 'Peace'), t('pulse', 'Trust'), t('pulse', 'Future'), t('pulse', 'Dialogue'),
	t('pulse', 'Hope'), t('pulse', 'Remembrance'), t('pulse', 'Humanity'), t('pulse', 'Responsibility'),
	t('pulse', 'Coming to terms'), t('pulse', 'Solidarity'), t('pulse', 'Dignity'), t('pulse', 'Never again'),
	t('pulse', 'Togetherness'), t('pulse', 'Family'),
]

export default {
	name: 'WordCloud',
	components: { PulseIcon },
	props: {
		// Snapshot from the server: [{ word, count }], sorted descending.
		words: { type: Array, default: () => [] },
		// Number of people (votes) — for the "here" counter.
		total: { type: Number, default: 0 },
		// The public tally only carries the 100 most frequent words
		// (lib/Service/PublicPayload.php). These are the true numbers of distinct
		// words and of mentions (0 = unknown: count what `words` holds).
		distinct: { type: Number, default: 0 },
		mentions: { type: Number, default: 0 },
		// Demo/test mode: ignores `words`, generates a living cloud locally.
		demo: { type: Boolean, default: false },
		// Show the component's own counter row. Off on the projector: the numbers move via
		// the `stats` event into the Screen header (compact cards next to QR/PIN).
		showFooter: { type: Boolean, default: true },
		// Lower bound for the word size in px. On stage that is class C: anything
		// smaller would no longer be readable from the room and falls into
		// "+{count} more" (§7.7). 11 px remains the value for the phone preview.
		minSize: { type: Number, default: 11 },
	},
	data() {
		return {
			isEmpty: true,
			overflowN: 0,
			srText: t('pulse', 'No answers yet.'),
			cWords: 0,
			cVotes: 0,
			cPeople: 0,
		}
	},
	computed: {
		ariaLabel() {
			return t('pulse', 'Live word cloud of the answers')
		},
	},
	watch: {
		words() { if (!this._demo) this.applySnapshot() },
		total() { if (!this._demo) this.cPeople = this.total || 0 },
	},
	created() {
		// Non-reactive engine state (Vue should not track the maps/canvas).
		this._words = new Map() // Map<display word, frequency>
		this._els = new Map() // Map<display word, HTMLElement>
		this._measurer = document.createElement('canvas').getContext('2d')
		this._palCache = []
		this._ro = null
		this._rzTimer = null
		this._alive = false
		this._W = 0
		this._H = 0
		this._demo = false
		this._demoTimer = null
		this._demoPeople = 0
		this._lastStats = '' // guard: fire the `stats` event only on a real change
	},
	mounted() {
		this._alive = true
		this._demo = !!this.demo
		// Fill the map: in demo mode from the seed, otherwise from the server prop.
		this._words = new Map()
		if (this._demo) {
			for (const [w, n] of DEMO_SEED) this._words.set(w, n)
			this._demoPeople = 12
		} else {
			for (const row of this.words || []) {
				if (row && row.word) this._words.set(String(row.word), Number(row.count) || 0)
			}
		}
		// Observe the container size (the projector cell also changes without a
		// window resize, e.g. when siblings appear) -> lay out again.
		if (typeof ResizeObserver !== 'undefined') {
			this._ro = new ResizeObserver(this.scheduleRender)
			this._ro.observe(this.$refs.wrap)
		}
		window.addEventListener('resize', this.scheduleRender)
		// Wait for fonts (correct measurement), then let the layout settle.
		;(document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve())
			.then(() => { this.settle(); if (this._demo) this.scheduleLive() })
		this.$nextTick(() => this.settle())
	},
	beforeDestroy() {
		this._alive = false
		if (this._ro) this._ro.disconnect()
		window.removeEventListener('resize', this.scheduleRender)
		clearTimeout(this._rzTimer)
		clearTimeout(this._demoTimer)
	},
	methods: {
		// Trigger a re-layout, throttled (ResizeObserver / window resize).
		scheduleRender() {
			clearTimeout(this._rzTimer)
			this._rzTimer = setTimeout(() => this.render(), 120)
		},
		// ── Demo/test mode: locally running "live feed" ────────────────────────
		scheduleLive() {
			if (!this._alive || !this._demo) return
			const delay = 900 + Math.random() * 1600
			this._demoTimer = setTimeout(() => {
				this.demoAddWord(DEMO_POOL[Math.floor(Math.random() * DEMO_POOL.length)])
				this.scheduleLive()
			}, delay)
		},
		demoAddWord(text) {
			const disp = this.norm(text)
			if (!disp) return
			const k = this.key(disp)
			let found = null
			for (const w of this._words.keys()) { if (this.key(w) === k) { found = w; break } }
			if (found) this._words.set(found, this._words.get(found) + 1)
			else this._words.set(disp, 1)
			this._demoPeople++
			this.render(found || disp)
		},
		// After mounting, keep checking over a few frames until the measured
		// container width is stable (the flex layout is often not finished at
		// mounted). Only once the width stops changing does it rest.
		settle(prevW, tries) {
			if (!this._alive || !this.$refs.wrap) return
			if (tries == null) tries = 12
			const w = Math.round(this.$refs.wrap.getBoundingClientRect().width)
			this.render()
			if (tries > 0 && w !== prevW) {
				requestAnimationFrame(() => this.settle(w, tries - 1))
			}
		},
		// ── pure functions (constant over a word's lifecycle) ──────────────────
		norm(s) { return String(s).replace(/\s+/g, ' ').trim() },
		key(s) { return this.norm(s).toLocaleLowerCase('de') },
		hashStr(s) {
			let h = 2166136261
			for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619) }
			return h >>> 0
		},
		colorFor(word) {
			return this._palCache[this.hashStr('c' + this.key(word)) % PALETTE_VARS.length]
		},
		// No word stands vertically any more (§7.7): rotated text reads badly from the
		// room, and legibility at a distance beats packing density. The price is a
		// lower density — "+{count} more" kicks in a little earlier for it.
		baseT(s) { return 'translate(-50%,-50%) scale(' + s + ')' },
		measFont(size) { return '700 ' + size + 'px ' + FONT_FAMILY },
		sizeScale() {
			const w = this._W || 800
			const h = this._H || 400
			// Tie the size to width AND height, so the cloud also fills a tall
			// projector area instead of staying small in the middle.
			const MAXS = Math.max(44, Math.min(150, w / 10, h / 3.6))
			const MINS = Math.max(16, Math.min(34, MAXS * 0.27))
			return { MINS, MAXS }
		},

		// ── collision-free spiral placement ────────────────────────────────────
		// `shrink` scales the size range down globally (fit loop).
		computeLayout(shrink) {
			const W = this._W, H = this._H
			const res = { layout: new Map(), placed: 0, attempted: 0, hidden: 0 }
			if (!this._words.size || W < 2 || H < 2) return res
			let entries = [...this._words.entries()].sort((a, b) => b[1] - a[1]) // most frequent first
			res.hidden = Math.max(0, entries.length - MAX_VISIBLE) // top-N cap (no silent dropping)
			entries = entries.slice(0, MAX_VISIBLE)
			res.attempted = entries.length
			const counts = entries.map((e) => e[1])
			const min = Math.min(...counts), max = Math.max(...counts)
			const base = this.sizeScale()
			const MINS = Math.max(this.minSize, base.MINS * shrink)
			const MAXS = Math.max(MINS, base.MAXS * shrink)
			const cx0 = W / 2, cy0 = H / 2
			const placed = []
			for (const [word, c] of entries) {
				// With identical frequency (early/uniform cloud) use a medium size
				// instead of the maximum — otherwise all count-1 words max out and crowd.
				const t = (max === min) ? 0.5 : (c - min) / (max - min)
				const size = MINS + Math.pow(t, 0.72) * (MAXS - MINS)
				this._measurer.font = this.measFont(size)
				const suffix = c >= 2 ? ' ·' + c : ''
				const textW = this._measurer.measureText(word + suffix).width
				const textH = size * 0.96
				const w = textW
				const h = textH
				let angle = 0, radius = 0, x = cx0, y = cy0, ok = false, iter = 0
				while (iter < 2600) {
					x = cx0 + radius * Math.cos(angle)
					y = cy0 + radius * Math.sin(angle) * 0.82 // ellipse: a bit wider than tall, but fills the area
					if (x - w / 2 >= MARGIN && x + w / 2 <= W - MARGIN && y - h / 2 >= MARGIN && y + h / 2 <= H - MARGIN) {
						let hit = false
						for (const p of placed) {
							if (Math.abs(x - p.x) < (w + p.w) / 2 + GAP && Math.abs(y - p.y) < (h + p.h) / 2 + GAP) { hit = true; break }
						}
						if (!hit) { ok = true; break }
					}
					angle += 0.35; radius += 0.9; iter++
				}
				if (!ok) {
					if (placed.length === 0) { x = cx0; y = cy0 } // first word goes to the center if need be
					else continue // doesn't fit (yet) -> the fit loop shrinks right away
				}
				placed.push({ x, y, w, h })
				res.layout.set(word, { x, y, size })
				res.placed++
			}
			return res
		},
		// Fit loop: shrinks the font sizes until ALL words fit,
		// instead of overlapping them or silently dropping them (like Mentimeter).
		computeLayoutFitted() {
			let shrink = 1, res = this.computeLayout(shrink), guard = 0
			while (res.placed < res.attempted && shrink > 0.5 && guard++ < 6) {
				shrink = Math.max(0.5, shrink - 0.11)
				res = this.computeLayout(shrink)
			}
			return res
		},

		// ── Rendering & animation ──────────────────────────────────────────────
		render(pulseWord) {
			if (!this.$refs.cloud || !this.$refs.wrap) return
			// Measure the container on the wrap rect (more reliable than clientWidth of the
			// absolutely positioned canvas; also correct under flex stretch).
			const rect = this.$refs.wrap.getBoundingClientRect()
			this._W = Math.max(0, Math.round(rect.width))
			this._H = Math.max(0, Math.round(rect.height))
			// Pull the color palette from the theme vars once per render (theme-safe).
			const cs = getComputedStyle(document.documentElement)
			this._palCache = PALETTE_VARS.map((v) => cs.getPropertyValue(v).trim())

			const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches
			const fit = this.computeLayoutFitted()
			const layout = fit.layout
			const cloud = this.$refs.cloud

			// Fade out words that are not currently visible (removed OR not placed
			// this round for lack of space -> never leave them hanging at the old position).
			for (const [word, el] of this._els) {
				if (!layout.has(word)) {
					el.style.opacity = '0'
					el.style.transform = this.baseT(0.4)
					setTimeout(() => el.remove(), 400)
					this._els.delete(word)
				}
			}

			for (const [word, info] of layout) {
				let el = this._els.get(word)
				const isNew = !el
				if (isNew) {
					el = document.createElement('span')
					el.className = 'pl-word entering'
					el.textContent = word
					const cnt = document.createElement('span')
					cnt.className = 'cnt'
					el.appendChild(cnt)
					cloud.appendChild(el)
					this._els.set(word, el)
				}
				const c = this._words.get(word)
				// Counter: 0.45 em of the word, never below the lower bound. If a
				// word itself is close to the lower bound, its counter is dropped entirely —
				// then the word size alone carries the frequency (§7.7).
				const cntEl = el.querySelector('.cnt')
				const showCnt = c >= 2 && info.size >= this.minSize * 1.2
				cntEl.textContent = showCnt ? '·' + c : ''
				cntEl.style.fontSize = Math.max(this.minSize, info.size * 0.45) + 'px'
				el.style.color = this.colorFor(word)
				el.style.fontSize = info.size + 'px'
				el.style.left = info.x + 'px'
				el.style.top = info.y + 'px'
				if (isNew) {
					el.style.opacity = '0'
					el.style.transform = this.baseT(reduce ? 1 : 0.35)
					requestAnimationFrame(() => requestAnimationFrame(() => {
						el.classList.remove('entering')
						el.style.opacity = '1'
						el.style.transform = this.baseT(1)
					}))
				} else {
					el.style.transform = this.baseT(1)
					if (word === pulseWord && !reduce && el.animate) {
						el.animate(
							[{ transform: this.baseT(1) }, { transform: this.baseT(1.2) }, { transform: this.baseT(1) }],
							{ duration: 480, easing: 'cubic-bezier(.34,1.56,.64,1)' },
						)
					}
				}
			}

			// Declarative state -> Vue renders empty/overflow/counters/SR region.
			this.isEmpty = this._words.size === 0
			// Words the server left out count as "+N more" as well.
			const cut = this._demo ? 0 : Math.max(0, this.distinct - this._words.size)
			this.overflowN = fit.hidden + Math.max(0, fit.attempted - fit.placed) + cut
			this.cWords = this._words.size + cut
			this.cVotes = Math.max(this._demo ? 0 : this.mentions, [...this._words.values()].reduce((a, b) => a + b, 0))
			this.cPeople = this._demo ? this._demoPeople : (this.total || 0)
			// Pass the counters up (projector header) — only on a real change,
			// so that the frequent render passes (settle/resize) don't spam.
			const statsKey = this.cWords + '/' + this.cVotes + '/' + this.cPeople
			if (statsKey !== this._lastStats) {
				this._lastStats = statsKey
				this.$emit('stats', { words: this.cWords, votes: this.cVotes, people: this.cPeople })
			}
			const top = [...this._words.entries()].sort((a, b) => b[1] - a[1]).slice(0, 10)
				.map(([w, n]) => w + ' (' + n + ')').join(', ')
			this.srText = this._words.size ? t('pulse', 'Most frequent answers: {words}', { words: top }) : t('pulse', 'No answers yet.')
		},

		// ── Data source: server snapshot instead of a live socket (§7) ─────────
		// We fill `words` from the prop and render; the entry that grew last
		// is passed on as pulseWord (a small pop, as with the live feed).
		applySnapshot(initial) {
			const next = new Map()
			for (const row of this.words || []) {
				if (row && row.word) next.set(String(row.word), Number(row.count) || 0)
			}
			let pulse = null
			if (!initial) {
				for (const [w, c] of next) {
					const old = this._words.get(w)
					if (old === undefined || c > old) { pulse = w; break } // the first increase is enough for the pop
				}
			}
			this._words = next
			this.render(pulse)
		},
	},
}
</script>

<style scoped>
.pl-cloud {
	display: flex;
	flex-direction: column;
	flex: 1 1 auto;
	min-height: 0;
	/* Force full width: otherwise the narrow in-flow counter row sets
	   the box width via shrink-to-fit (the canvas is absolute and contributes
	   0) -> the cloud would sit in a ~400px box on the left. */
	width: 100%;
	min-width: 0;
	align-self: stretch;
}

/* Stage for the cloud — fills the available height via flex:1. min-height:0
   so the column can shrink and the counter row below always fits
   (otherwise a large min-height pushes the row out of #content). On the projector
   the flex chain provides plenty of height; 120px only as collapse protection. */
.pl-cloud-wrap {
	position: relative;
	flex: 1 1 auto;
	width: 100%;
	min-height: 120px;
}
.pl-cloud-canvas { position: absolute; inset: 0; overflow: hidden; }

/* Empty state */
.pl-cloud-empty {
	position: absolute; inset: 0;
	display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px;
	text-align: center; color: var(--pulse-text-2);
}
.pl-cloud-empty b { color: var(--pulse-text); font-size: clamp(20px, 2.4vw, 32px); }
.pl-cloud-empty span { font-size: clamp(14px, 1.4vw, 20px); max-width: 26ch; }

/* "+{count} more" */
.pl-cloud-overflow {
	position: absolute; right: 8px; bottom: 8px;
	font-size: clamp(13px, 1.2vw, 18px); font-weight: 600; color: var(--pulse-text-2);
	background: var(--pulse-fill); border: 1px solid var(--pulse-border);
	border-radius: var(--pulse-r-pill); padding: 4px 14px; pointer-events: none;
}

/* Counter row */
.pl-cloud-foot {
	flex: 0 0 auto; display: flex; gap: clamp(20px, 3vw, 48px); justify-content: center;
	padding-top: clamp(12px, 1.6vw, 24px);
}
.pl-stat { color: var(--pulse-text-2); font-size: clamp(15px, 1.5vw, 24px); }
.pl-stat b { color: var(--pulse-text); font-weight: 900; font-variant-numeric: tabular-nums; margin-right: 4px; }

.pl-sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
</style>

<!--
	Un-scoped: the engine creates the word <span>s imperatively
	(document.createElement), i.e. WITHOUT Vue's data-v attribute. Scoped CSS would
	never match them (-> words would flow inline). The `pl-word` prefix still keeps collisions unlikely.
-->
<style>
.pl-word {
	position: absolute;
	font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
	font-weight: 700;
	line-height: 1;
	white-space: nowrap;
	user-select: none;
	transform: translate(-50%, -50%) scale(1);
	transform-origin: center;
	transition:
		left 0.8s cubic-bezier(.22, 1, .36, 1), top 0.8s cubic-bezier(.22, 1, .36, 1),
		font-size 0.55s cubic-bezier(.22, 1, .36, 1), opacity 0.5s ease, transform 0.5s ease;
	will-change: left, top, transform;
}
.pl-word.entering { opacity: 0; transform: translate(-50%, -50%) scale(.35); }
.pl-word .cnt { font-weight: 600; opacity: .55; margin-left: .28em; vertical-align: .22em; }

@media (prefers-reduced-motion: reduce) {
	.pl-word {
		transition: left 0.3s linear, top 0.3s linear, font-size 0.2s linear, opacity 0.3s ease;
	}
	.pl-word.entering { transform: translate(-50%, -50%) scale(1); }
}
</style>
