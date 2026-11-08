/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Filter before the projector (desk): which buttons an answer gets for its
 * state from the server (ModerationService::state). Pure, without strings,
 * so dev/unit/moderation.test.mjs can check it without a browser.
 *
 *   visible  on the projector              -> Hide
 *   pending  waiting for the AI check      -> Hide
 *   held     flagged by the AI, held back  -> Show, Hide
 *   hidden   hidden by the moderator       -> Show (undo)
 */
export const MOD_STATES = ['visible', 'pending', 'held', 'hidden']

/** @return {Array<'show'|'hide'>} */
export function moderationActions(state) {
	if (state === 'held') return ['show', 'hide']
	if (state === 'hidden') return ['show']
	return ['hide']
}

/** Does an answer in this state reach the projector? */
export function onProjector(state) {
	return state === 'visible' || state === undefined
}
