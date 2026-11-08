// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Rules for the numbers of the narrow scale histogram (phone and desk): a scale with many values (0–20 is 21
// columns) gives each column only a few pixels on a phone, less with browser zoom, and its numbers would run into
// each other and past the edge. The rules work on measured widths, not on screen sizes.

// Nice steps for a thinned axis; past the list, the step grows by tens.
const STEPS = [1, 2, 5, 10, 20, 50, 100]

/**
 * How far apart two visible axis numbers have to be, and which step of values shows.
 * @param {number} labW natural width of the widest axis number, in px
 * @param {number} colW width of one column, in px
 * @param {number} gapPx the least space between two numbers, in px
 * @return {{d: number, step: number}} d = least distance of two visible numbers in columns, step = the smallest
 *   nice step of values that is at least d (1: every value shows)
 */
export function axisStep(labW, colW, gapPx) {
	if (!(colW > 0) || !(labW > 0)) return { d: 1, step: 1 }
	const d = Math.max(1, Math.ceil((labW + gapPx) / colW - 1e-6))
	let step = STEPS.find((s) => s >= d)
	if (step === undefined) step = Math.ceil(d / 100) * 100
	return { d, step }
}

/**
 * The axis values that show: the first and the last always, between them every value divisible by step whose
 * distance in columns to the first and to the last value is at least d.
 * @param {Array<number|string>} values the scale's values in order, one per column
 * @param {number} d least distance of two visible numbers, in columns
 * @param {number} step the step of values
 * @return {Set<number>} the visible values
 */
export function shownAxisValues(values, d, step) {
	const nums = values.map(Number)
	const n = nums.length
	if (n === 0) return new Set()
	if (d <= 1) return new Set(nums)
	const out = new Set([nums[0], nums[n - 1]])
	for (let i = 1; i < n - 1; i++) {
		const v = nums[i]
		if (((v % step) + step) % step !== 0) continue
		if (i >= d && (n - 1 - i) >= d) out.add(v)
	}
	return out
}

/**
 * Whether the vote counts above the columns fit their columns. They cannot be thinned like the axis (each one is
 * data), so they show all or none.
 * @param {number} cntW natural width of the widest count, in px
 * @param {number} colW width of one column, in px
 * @param {number} gapPx the least space between two counts, in px
 * @return {boolean} true: every count shows
 */
export function countsFit(cntW, colW, gapPx) {
	if (!(colW > 0) || !(cntW > 0)) return true
	return cntW + gapPx <= colW + 1e-6
}

/**
 * Where the label of a tapped column sits: over the centre of its column, lined up with the chart's edge at the
 * first and the last column so it never passes it.
 * @param {number} i index of the column
 * @param {number} n number of columns
 * @return {{pct: number, edge: ('start'|'end'|null)}} pct = centre of the column as a percentage of the chart
 *   width; edge = 'start' (label starts at the column's left edge), 'end' (ends at its right edge), null (centred)
 */
export function tipPlace(i, n) {
	const cols = Math.max(1, n)
	const k = Math.max(0, Math.min(cols - 1, i))
	const pct = ((k + 0.5) / cols) * 100
	let edge = null
	if (cols > 1 && k === 0) edge = 'start'
	else if (cols > 1 && k === cols - 1) edge = 'end'
	return { pct, edge }
}

/**
 * Left edge of a label centred over a point, pushed in so it stays inside the chart.
 * @param {number} centre the point, in px from the chart's left edge
 * @param {number} w width of the label, in px
 * @param {number} W width of the chart, in px
 * @return {number} left edge of the label, in px (0 when the label is wider than the chart)
 */
export function tipLeft(centre, w, W) {
	return Math.max(0, Math.min(centre - w / 2, W - w))
}
