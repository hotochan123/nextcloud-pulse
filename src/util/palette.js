/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Canonical answer palette A–H — the ONE source for option colors.
 * Replaces the hard-coded OPT_COLORS array (formerly in Screen.vue) and carries the
 * color all the way from the live tile to the result bar: set `cls` on the
 * option element -> letter badge and bar fill inherit --opt-fill
 * /--opt-ink (see pulse-ds.css, classes .pulse-opt-*).
 *
 * The color values themselves live theme-independently in pulse-tokens.css (--opt-*).
 *
 * Section references (§…) point to the design notes of the redesign, which are
 * not in the public repository (see "References in code comments" in the
 * README).
 */

const KEYS = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']

/**
 * @param {number} index 0-based option index
 * @return {{letter:string, cls:string, isInk:boolean}}
 */
export function option(index) {
	const i = ((index % KEYS.length) + KEYS.length) % KEYS.length
	const key = KEYS[i]
	return {
		letter: String.fromCharCode(65 + i), // A … H
		cls: 'pulse-opt-' + key,             // sets --opt-fill/--opt-ink
		isInk: key === 'd',                  // amber: dark text on a light tile
	}
}

/**
 * List (options/result rows) with a stable palette assignment per index —
 * same index -> same color on the phone, the projector and in the result bar.
 * @param {Array} list options or result rows (null/undefined -> [])
 * @return {Array} the same objects, each extended with `pal` (see option())
 */
export function withPalette(list) {
	return (list || []).map((item, i) => ({ ...item, pal: option(i) }))
}

/*
 * Lightness ramp for ORDERED categories (§7.0): places, ranks, levels.
 * The A–H palette implies equal standing and is wrong here — the ramp
 * is built from one hue, not from eight new colors.
 *
 * @param {number} k 1-based step (1 = darkest)
 * @param {number} n number of steps
 * @return {{fill:string, ink:string}} CSS values for --seg-fill/--seg-ink
 */
export function rampStep(k, n) {
	const lift = n > 1 ? ((k - 1) / (n - 1)) * 70 : 0
	return {
		fill: `color-mix(in oklab, var(--pulse-primary), var(--pulse-screen-bg) ${Math.round(lift)}%)`,
		// From half the lightening on, the light contrast partner no longer holds up;
		// measured, not estimated (§3.1/§7.0).
		ink: lift >= 50 ? 'var(--pulse-text)' : 'var(--pulse-on-primary)',
	}
}
