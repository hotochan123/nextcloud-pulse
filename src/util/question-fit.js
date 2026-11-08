// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Rules for the question on the projector: it always shows whole (no line clamp). A long question first gets at
// most a share of its frame; when the stage still does not fit at its floor, the question shrinks further. The
// size is a factor k on the question's base font size (--scr-q-k), stepped down, never below a floor in px.

/** One step of the question factor: 8 % smaller. */
export const Q_STEP = 0.92
/** The question never gets smaller than this, in px. */
export const Q_FLOOR_PX = 14
/** The share of its frame (head: the page, beside an image: the stage) a question may take before it shrinks. */
export const Q_SHARE = 0.4

/**
 * The next, smaller question factor, or null when the question is at its floor already.
 * @param {number} k the factor now (1 = base size)
 * @param {number} basePx the base font size of the question, in px (at k = 1)
 * @return {number|null}
 */
export function nextQuestionK(k, basePx) {
	if (!(basePx > 0) || !(k > 0)) return null
	const floorK = Q_FLOOR_PX / basePx
	if (k <= floorK + 1e-6) return null
	return Math.max(floorK, Math.round(k * Q_STEP * 10000) / 10000)
}

/**
 * Whether a question box is taller than its share of the frame.
 * @param {number} height the question's height, in px
 * @param {number} frame the height of its frame, in px
 * @return {boolean}
 */
export function questionTooTall(height, frame) {
	return frame > 0 && height > frame * Q_SHARE + 0.5
}
