/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Pulse: the layout of the projector's word cloud (components/WordCloud.vue) —
 * collision-free spiral placement plus the fit loop, as pure functions. The
 * component keeps the DOM, the animation and the canvas that measures the
 * text and hands the measuring in as `measure(text, size)`. Without Vue and
 * without a DOM, so dev/unit/wordcloud.test.mjs runs it in plain Node.
 *
 * Section references (§…) point to the design notes of the redesign, which are
 * not in the public repository (see "References in code comments" in the
 * README).
 */

export const GAP = 9 // minimum gap between word boxes (px)
export const MARGIN = 10 // safety margin to the container (px)
export const MAX_VISIBLE = 90 // hard cap on visible words — the rest shows as "+{count} more"

/**
 * Size range of the words (px) for a cloud area of W × H px.
 * @param {number} W area width
 * @param {number} H area height
 * @return {{MINS:number, MAXS:number}}
 */
export function sizeScale(W, H) {
	const w = W || 800
	const h = H || 400
	// Tie the size to width AND height, so the cloud also fills a tall
	// projector area instead of staying small in the middle.
	const MAXS = Math.max(44, Math.min(150, w / 10, h / 3.6))
	const MINS = Math.max(16, Math.min(34, MAXS * 0.27))
	return { MINS, MAXS }
}

/**
 * Collision-free spiral placement. `shrink` scales the size range down
 * globally (fit loop).
 * @param {Map<string, number>} words display word -> frequency
 * @param {{W:number, H:number, minSize:number, measure:function(string, number):number}} box
 *   area size, lower bound of the word size, and the width in px of a text set bold at a size in px
 * @param {number} shrink factor on the size range (1 … 0.5)
 * @return {{layout:Map<string, {x:number, y:number, size:number}>, placed:number, attempted:number, hidden:number}}
 */
export function layoutCloud(words, { W, H, minSize, measure }, shrink) {
	const res = { layout: new Map(), placed: 0, attempted: 0, hidden: 0 }
	if (!words.size || W < 2 || H < 2) return res
	let entries = [...words.entries()].sort((a, b) => b[1] - a[1]) // most frequent first
	res.hidden = Math.max(0, entries.length - MAX_VISIBLE) // top-N cap (no silent dropping)
	entries = entries.slice(0, MAX_VISIBLE)
	res.attempted = entries.length
	const counts = entries.map((e) => e[1])
	const min = Math.min(...counts), max = Math.max(...counts)
	const base = sizeScale(W, H)
	const MINS = Math.max(minSize, base.MINS * shrink)
	const MAXS = Math.max(MINS, base.MAXS * shrink)
	const maxW = W - 2 * MARGIN
	const cx0 = W / 2, cy0 = H / 2
	const placed = []
	for (const [word, c] of entries) {
		// With identical frequency (early/uniform cloud) use a medium size
		// instead of the maximum — otherwise all count-1 words max out and crowd.
		const t = (max === min) ? 0.5 : (c - min) / (max - min)
		let size = MINS + Math.pow(t, 0.72) * (MAXS - MINS)
		const suffix = c >= 2 ? ' ·' + c : ''
		let textW = measure(word + suffix, size)
		// A word wider than the whole area (the phone takes 40 characters) would be
		// clipped on both sides: the first word goes to the center even when it does
		// not fit, and the fit loop only shrinks while a word is left over. So such a
		// word gets a size at which it fits across — the first one the full width,
		// any later one a little less, because a box exactly as wide as the area only
		// fits at the center, which the first word already holds; slightly narrower,
		// it finds a row above or below. Every word that already fits keeps its size
		// and its place.
		if (maxW > 0 && textW > maxW) {
			const capW = placed.length === 0 ? maxW : maxW * 0.85
			size = size * capW / textW
			textW = capW
		}
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
}

/**
 * Fit loop: shrinks the font sizes until ALL words fit, instead of
 * overlapping them or silently dropping them (like Mentimeter).
 * @param {Map<string, number>} words display word -> frequency
 * @param {{W:number, H:number, minSize:number, measure:function(string, number):number}} box see layoutCloud()
 * @return {{layout:Map<string, {x:number, y:number, size:number}>, placed:number, attempted:number, hidden:number}}
 */
export function fitCloud(words, box) {
	let shrink = 1, res = layoutCloud(words, box, shrink), guard = 0
	while (res.placed < res.attempted && shrink > 0.5 && guard++ < 6) {
		shrink = Math.max(0.5, shrink - 0.11)
		res = layoutCloud(words, box, shrink)
	}
	return res
}
