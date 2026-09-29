<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pl-cloud">
		<div ref="wrap" class="pl-cloud-wrap">
			<!-- Die Engine schreibt die Wort-<span>s in diesen Container. -->
			<div ref="cloud" class="pl-cloud-canvas" role="img" :aria-label="ariaLabel" />

			<!-- Leerzustand (deklarativ von Vue, nicht von der Engine) -->
			<div v-if="isEmpty" class="pl-cloud-empty">
				<PulseIcon name="edit" size="clamp(40px, 5vw, 72px)" />
				<b>{{ t('pulse', 'No answers yet') }}</b>
				<span>{{ t('pulse', 'Scan the code and type your word.') }}</span>
			</div>

			<!-- Ehrlicher Hinweis auf gekappte Top-N-Wörter (kein stilles Verwerfen) -->
			<div v-if="overflowN > 0" class="pl-cloud-overflow">{{ t('pulse', '+{count} more', { count: overflowN }) }}</div>
		</div>

		<!-- Zähler-Zeile: Wörter · Nennungen · dabei (auf dem Beamer ausgelagert) -->
		<div v-if="!isEmpty && showFooter" class="pl-cloud-foot">
			<span class="pl-stat"><b>{{ cWords }}</b> {{ n('pulse', 'word', 'words', cWords) }}</span>
			<span class="pl-stat"><b>{{ cVotes }}</b> {{ n('pulse', 'mention', 'mentions', cVotes) }}</span>
			<span v-if="cPeople" class="pl-stat"><b>{{ cPeople }}</b> {{ t('pulse', 'here') }}</span>
		</div>

		<!-- Screenreader-Live-Region: häufigste Antworten in Textform -->
		<p class="pl-sr-only" aria-live="polite">{{ srText }}</p>
	</div>
</template>

<script>
import { t } from '../util/l10n.js'
import PulseIcon from './ui/PulseIcon.vue'

// ── Engine-Konstanten (aus der framework-freien Referenz, §7) ──────────────
const PALETTE_VARS = ['--w1', '--w2', '--w3', '--w4', '--w5', '--w6', '--w7', '--w8']
const GAP = 9 // Mindestabstand zwischen Wort-Boxen (px)
const MARGIN = 10 // Sicherheitsrand zum Container (px)
const MAX_VISIBLE = 90 // harte Obergrenze sichtbarer Wörter — Rest als „+N weitere"
// Messschrift muss EXAKT der gerenderten Schrift entsprechen, sonst misst
// canvas falsch. Wir nutzen denselben System-Stack wie die .word-Regel unten
// (kein externer Font -> CSP-fest und Messung stimmt).
const FONT_FAMILY = 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'

// ── Demo-/Testmodus (client-seitig, kein Server) ──────────────────────────
// Erzeugt lokal eine lebende Wolke, damit man Optik/Animation ohne echte
// Teilnehmende prüfen kann. Aktivierung: Beamer-URL mit ?demo=1.
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
		// Snapshot vom Server: [{ word, count }], absteigend sortiert.
		words: { type: Array, default: () => [] },
		// Zahl der Personen (Stimmen) — für den „dabei"-Zähler.
		total: { type: Number, default: 0 },
		// Demo-/Testmodus: ignoriert `words`, generiert lokal eine lebende Wolke.
		demo: { type: Boolean, default: false },
		// Eigene Zähler-Zeile zeigen. Auf dem Beamer aus: die Zahlen wandern per
		// `stats`-Event in die Screen-Kopfzeile (kompakte Karten neben QR/PIN).
		showFooter: { type: Boolean, default: true },
		// Untergrenze der Wortgröße in px. Auf der Bühne ist das Klasse C: was
		// kleiner würde, ist aus dem Saal nicht mehr lesbar und fällt in
		// „+N weitere" (§7.7). 11 px bleibt der Wert für die Handy-Vorschau.
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
		// Nicht-reaktiver Engine-Zustand (Vue soll die Maps/Canvas nicht tracken).
		this._words = new Map() // Map<Anzeigewort, Häufigkeit>
		this._els = new Map() // Map<Anzeigewort, HTMLElement>
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
		this._lastStats = '' // Guard: `stats`-Event nur bei echter Änderung feuern
	},
	mounted() {
		this._alive = true
		this._demo = !!this.demo
		// Map füllen: im Demo-Modus aus dem Seed, sonst aus dem Server-Prop.
		this._words = new Map()
		if (this._demo) {
			for (const [w, n] of DEMO_SEED) this._words.set(w, n)
			this._demoPeople = 12
		} else {
			for (const row of this.words || []) {
				if (row && row.word) this._words.set(String(row.word), Number(row.count) || 0)
			}
		}
		// Container-Größe beobachten (Beamer-Zelle ändert sich auch ohne
		// Fensterresize, z.B. wenn Geschwister erscheinen) -> neu layouten.
		if (typeof ResizeObserver !== 'undefined') {
			this._ro = new ResizeObserver(this.scheduleRender)
			this._ro.observe(this.$refs.wrap)
		}
		window.addEventListener('resize', this.scheduleRender)
		// Fonts abwarten (korrekte Vermessung), dann Layout einschwingen lassen.
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
		// Neu-Layout gedrosselt anstoßen (ResizeObserver / Fensterresize).
		scheduleRender() {
			clearTimeout(this._rzTimer)
			this._rzTimer = setTimeout(() => this.render(), 120)
		},
		// ── Demo-/Testmodus: lokal laufender „Live-Feed" ───────────────────────
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
		// Nach dem Einhängen über einige Frames nachfassen, bis die gemessene
		// Container-Breite steht (Flex-Layout ist beim mounted oft noch nicht
		// fertig). Erst wenn sich die Breite nicht mehr ändert, ruht es.
		settle(prevW, tries) {
			if (!this._alive || !this.$refs.wrap) return
			if (tries == null) tries = 12
			const w = Math.round(this.$refs.wrap.getBoundingClientRect().width)
			this.render()
			if (tries > 0 && w !== prevW) {
				requestAnimationFrame(() => this.settle(w, tries - 1))
			}
		},
		// ── reine Funktionen (über den Lebenszyklus eines Worts konstant) ──────
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
		// Kein Wort steht mehr senkrecht (§7.7): gedreht liest sich aus dem Saal
		// schlecht, und Fernlesbarkeit geht vor Packdichte. Der Preis ist eine
		// geringere Dichte — „+N weitere" greift dafür etwas früher.
		baseT(s) { return 'translate(-50%,-50%) scale(' + s + ')' },
		measFont(size) { return '700 ' + size + 'px ' + FONT_FAMILY },
		sizeScale() {
			const w = this._W || 800
			const h = this._H || 400
			// Größe an Breite UND Höhe koppeln, damit die Wolke auch eine hohe
			// Beamer-Fläche füllt statt zentral klein zu bleiben.
			const MAXS = Math.max(44, Math.min(150, w / 10, h / 3.6))
			const MINS = Math.max(16, Math.min(34, MAXS * 0.27))
			return { MINS, MAXS }
		},

		// ── kollisionsfreie Spiral-Platzierung ─────────────────────────────────
		// `shrink` skaliert die Größenspanne global herunter (Fit-Schleife).
		computeLayout(shrink) {
			const W = this._W, H = this._H
			const res = { layout: new Map(), placed: 0, attempted: 0, hidden: 0 }
			if (!this._words.size || W < 2 || H < 2) return res
			let entries = [...this._words.entries()].sort((a, b) => b[1] - a[1]) // häufigstes zuerst
			res.hidden = Math.max(0, entries.length - MAX_VISIBLE) // Top-N-Kappung (kein stilles Droppen)
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
				// Bei identischer Häufigkeit (frühe/uniforme Wolke) mittlere Größe
				// statt Maximum — sonst maxen alle Count-1-Wörter aus und drängeln.
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
					y = cy0 + radius * Math.sin(angle) * 0.82 // Ellipse: etwas breiter als hoch, füllt aber die Fläche
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
					if (placed.length === 0) { x = cx0; y = cy0 } // erstes Wort notfalls ins Zentrum
					else continue // passt (noch) nicht -> Fit-Schleife schrumpft gleich
				}
				placed.push({ x, y, w, h })
				res.layout.set(word, { x, y, size })
				res.placed++
			}
			return res
		},
		// Fit-Schleife: schrumpft die Schriftgrößen, bis ALLE Wörter passen,
		// statt sie zu überlagern oder still zu verwerfen (wie Mentimeter).
		computeLayoutFitted() {
			let shrink = 1, res = this.computeLayout(shrink), guard = 0
			while (res.placed < res.attempted && shrink > 0.5 && guard++ < 6) {
				shrink = Math.max(0.5, shrink - 0.11)
				res = this.computeLayout(shrink)
			}
			return res
		},

		// ── Rendering & Animation ──────────────────────────────────────────────
		render(pulseWord) {
			if (!this.$refs.cloud || !this.$refs.wrap) return
			// Container am Wrap-Rect vermessen (verlässlicher als clientWidth des
			// absolut positionierten Kanvas; steht auch bei Flex-Stretch korrekt).
			const rect = this.$refs.wrap.getBoundingClientRect()
			this._W = Math.max(0, Math.round(rect.width))
			this._H = Math.max(0, Math.round(rect.height))
			// Farb-Palette einmal pro Render aus den Theme-Vars ziehen (theme-fest).
			const cs = getComputedStyle(document.documentElement)
			this._palCache = PALETTE_VARS.map((v) => cs.getPropertyValue(v).trim())

			const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches
			const fit = this.computeLayoutFitted()
			const layout = fit.layout
			const cloud = this.$refs.cloud

			// Wörter ausblenden, die aktuell nicht sichtbar sind (entfernt ODER
			// diese Runde aus Platzgründen nicht platziert -> nie an alter Position hängen lassen).
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
				// Zähler: 0,45 em des Wortes, nie unter der Untergrenze. Bleibt ein
				// Wort selbst dicht an der Untergrenze, entfällt sein Zähler ganz —
				// dann trägt allein die Wortgröße die Häufigkeit (§7.7).
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

			// Deklarativer Zustand -> Vue rendert Empty/Overflow/Zähler/SR-Region.
			this.isEmpty = this._words.size === 0
			this.overflowN = fit.hidden + Math.max(0, fit.attempted - fit.placed)
			this.cWords = this._words.size
			this.cVotes = [...this._words.values()].reduce((a, b) => a + b, 0)
			this.cPeople = this._demo ? this._demoPeople : (this.total || 0)
			// Zähler nach oben reichen (Beamer-Kopfzeile) — nur bei echter Änderung,
			// damit die häufigen Render-Durchläufe (settle/resize) nicht spammen.
			const statsKey = this.cWords + '/' + this.cVotes + '/' + this.cPeople
			if (statsKey !== this._lastStats) {
				this._lastStats = statsKey
				this.$emit('stats', { words: this.cWords, votes: this.cVotes, people: this.cPeople })
			}
			const top = [...this._words.entries()].sort((a, b) => b[1] - a[1]).slice(0, 10)
				.map(([w, n]) => w + ' (' + n + ')').join(', ')
			this.srText = this._words.size ? t('pulse', 'Most frequent answers: {words}', { words: top }) : t('pulse', 'No answers yet.')
		},

		// ── Datenquelle: Server-Snapshot statt Live-Socket (§7) ────────────────
		// Wir befüllen `words` aus dem Prop und rendern; den zuletzt gewachsenen
		// Eintrag geben wir als pulseWord weiter (kleiner Pop wie beim Live-Feed).
		applySnapshot(initial) {
			const next = new Map()
			for (const row of this.words || []) {
				if (row && row.word) next.set(String(row.word), Number(row.count) || 0)
			}
			let pulse = null
			if (!initial) {
				for (const [w, c] of next) {
					const old = this._words.get(w)
					if (old === undefined || c > old) { pulse = w; break } // erster Zuwachs reicht für den Pop
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
	/* Volle Breite erzwingen: sonst bestimmt die schmale, in-flow liegende
	   Zähler-Zeile per Shrink-to-fit die Boxbreite (das Kanvas ist absolut und
	   trägt 0 bei) -> Wolke säße in einer ~400px-Box links. */
	width: 100%;
	min-width: 0;
	align-self: stretch;
}

/* Bühne für die Wolke — füllt per flex:1 die verfügbare Höhe. min-height:0,
   damit die Spalte schrumpfen kann und die Zähler-Zeile darunter immer passt
   (sonst drückt eine große min-height die Zeile aus #content). Auf dem Beamer
   liefert die Flex-Kette reichlich Höhe; 120px nur als Kollaps-Schutz. */
.pl-cloud-wrap {
	position: relative;
	flex: 1 1 auto;
	width: 100%;
	min-height: 120px;
}
.pl-cloud-canvas { position: absolute; inset: 0; overflow: hidden; }

/* Leerzustand */
.pl-cloud-empty {
	position: absolute; inset: 0;
	display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px;
	text-align: center; color: var(--pulse-text-2);
}
.pl-cloud-empty b { color: var(--pulse-text); font-size: clamp(20px, 2.4vw, 32px); }
.pl-cloud-empty span { font-size: clamp(14px, 1.4vw, 20px); max-width: 26ch; }

/* „+N weitere" */
.pl-cloud-overflow {
	position: absolute; right: 8px; bottom: 8px;
	font-size: clamp(13px, 1.2vw, 18px); font-weight: 600; color: var(--pulse-text-2);
	background: var(--pulse-fill); border: 1px solid var(--pulse-border);
	border-radius: var(--pulse-r-pill); padding: 4px 14px; pointer-events: none;
}

/* Zähler-Zeile */
.pl-cloud-foot {
	flex: 0 0 auto; display: flex; gap: clamp(20px, 3vw, 48px); justify-content: center;
	padding-top: clamp(12px, 1.6vw, 24px);
}
.pl-stat { color: var(--pulse-text-2); font-size: clamp(15px, 1.5vw, 24px); }
.pl-stat b { color: var(--pulse-text); font-weight: 900; font-variant-numeric: tabular-nums; margin-right: 4px; }

.pl-sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
</style>

<!--
	Un-scoped: die Wort-<span>s erzeugt die Engine imperativ (document.create-
	Element), also OHNE Vues data-v-Attribut. Scoped-CSS würde sie nie treffen
	(-> Wörter flössen inline). Der `pl-word`-Präfix hält es trotzdem kollisionsarm.
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
