// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Rules for labels on the projector stage that would otherwise be cut where it matters.

// From this many shared leading characters on, a label keeps its end whole when the stage cuts it to one line.
export const SHARED_START_CHARS = 16
// An end longer than this is no short distinguishing end ("Variante 1") but text of its own: the label then stays one
// text as before, cut at its end like any long label (a long end beside the start would leave no room for the start).
export const MAX_TAIL_CHARS = 24

/**
 * The distinguishing end of each label: options that share a long start ("… Variante 1", "… Variante 2") lose
 * exactly their difference when a one-line row cuts them at the end. For each label, the longest start it shares
 * with any other label is found (in code points); from SHARED_START_CHARS on, its end is returned: from the word
 * where the difference begins, with the word before it for context ("Variante 1", not "1"), the space staying with
 * the start; without a space there, from the first differing character. An end of more than MAX_TAIL_CHARS, or
 * none at all: ''.
 * @param {string[]} labels the labels of one question, in order
 * @return {string[]} one end per label, '' where the label needs none
 */
export function labelTails(labels) {
	const cps = labels.map((l) => Array.from(typeof l === 'string' ? l : ''))
	return cps.map((a, i) => {
		if (a.length === 0) return ''
		let p = 0
		cps.forEach((b, j) => {
			if (j === i) return
			let k = 0
			while (k < a.length && k < b.length && a[k] === b[k]) k++
			if (k > p) p = k
		})
		if (p < SHARED_START_CHARS || p >= a.length) return ''
		// start of the word in which the labels differ (a space there: the next word)
		let s = a[p] === ' ' ? p + 1 : p
		while (s > 0 && a[s - 1] !== ' ') s--
		// one word more for context, as long as some start is left to cut
		if (s > 1) {
			let t = s - 1
			while (t > 0 && a[t - 1] !== ' ') t--
			if (t > 0) s = t
		}
		if (s === 0) {
			s = p
			// never split a base character from its combining marks or a joined emoji sequence
			while (s < a.length && /^[\p{M}‍️\u{1F3FB}-\u{1F3FF}]$/u.test(a[s])) s++
			if (s > 0 && a[s - 1] === '‍') s++
			if (s >= a.length) return ''
		}
		return a.length - s > MAX_TAIL_CHARS ? '' : a.slice(s).join('')
	})
}

/**
 * Do labels placed on the stage cover each other or leave their box? Rectangles as from getBoundingClientRect
 * ({ left, top, right, bottom }); a touch of up to 1 px counts as no overlap (rounding).
 * @param {Array<{left: number, top: number, right: number, bottom: number}>} rects the labels
 * @param {{left: number, top: number, right: number, bottom: number}} box the area they must stay in
 * @return {boolean}
 */
export function labelsCollide(rects, box) {
	const T = 1
	for (let i = 0; i < rects.length; i++) {
		const a = rects[i]
		if (a.left < box.left - T || a.top < box.top - T || a.right > box.right + T || a.bottom > box.bottom + T) return true
		for (let j = i + 1; j < rects.length; j++) {
			const b = rects[j]
			const w = Math.min(a.right, b.right) - Math.max(a.left, b.left)
			const h = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top)
			if (w > T && h > T) return true
		}
	}
	return false
}
