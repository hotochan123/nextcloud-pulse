// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

/*
 * AI quiz draft (components/AiDraftDialog.vue): what the server's draft
 * questions become on the way into the deck. Pure functions, tested in
 * dev/unit/ai-draft.test.mjs.
 *
 * A draft question is {type: 'choice'|'multi', question, options: [string],
 * correct: [index]} (AiDraftService::parse on the server, already cleaned).
 */

/** Time limit of a drafted question: the composer's default. */
export const DRAFT_TIME_LIMIT = 30
export const COUNT_MIN = 5
export const COUNT_MAX = 10
export const COUNT_DEFAULT = 8
/** Characters of a topic (AiDraftService::TOPIC_MAX). */
export const TOPIC_MAX = 2000
/** Files the picker offers (SourceText::extract reads these). */
export const PICK_MIMES = [
	'text/plain',
	'text/markdown',
	'text/csv',
	'application/pdf',
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	'application/vnd.oasis.opendocument.text',
	'application/vnd.oasis.opendocument.presentation',
]

/**
 * The body of POST …/polls for one draft question, the same a hand-made
 * question of that type sends (QUESTION_TYPES[type].toBody in a quiz).
 * @param {{type: string, question: string, options: string[], correct: number[]}} q draft question
 * @return {object} request body
 */
export function toPollBody(q) {
	const body = {
		type: q.type === 'multi' ? 'multi' : 'choice',
		question: q.question,
		options: q.options.slice(),
		timeLimit: DRAFT_TIME_LIMIT,
	}
	if (body.type === 'multi') body.correctIndexes = q.correct.slice()
	else body.correctIndex = q.correct[0]
	return body
}

/**
 * The questions of a preview that are ticked, in their order.
 * @param {object[]} questions draft questions
 * @param {boolean[]} picked tick per question
 * @return {object[]} ticked questions
 */
export function pickedQuestions(questions, picked) {
	return questions.filter((q, i) => picked[i])
}

/**
 * The file name of a picked path (shown in the dialog).
 * @param {string} path path in the account's Files
 * @return {string} last segment
 */
export function baseName(path) {
	const parts = String(path || '').split('/').filter(Boolean)
	return parts.length ? parts[parts.length - 1] : ''
}
