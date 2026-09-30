/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Pulse: the composer's contract per question type (the types each room mode
 * offers, and poll -> draft -> request body, i.e. what the server's validation
 * gets to see), the deck's type labels and answer hints, and the loss
 * sentence of the destructive confirmations. Moved out of Moderator.vue, which
 * keeps thin method wrappers for the names its template calls.
 *
 * Pure functions whose only dependency is t()/n() from util/l10n.js: no Vue
 * state, no router, no axios. That keeps the file runnable under plain
 * `node` (dev/unit/question-types.test.mjs).
 *
 * Section references (§…) point to the specification of the self-paced quiz,
 * which is not in the public repository (see "References in code comments" in
 * the README).
 */
import { t, n } from './l10n.js'

// Per question type in ONE place: deserialization (poll -> draft) and
// serialization+validation (draft -> body). Keeps the two composer sides
// in sync. toBody reports errors via fail(msg); the caller then aborts.
export function keptOptions(draft, body, fail) {
	const kept = []
	draft.options.forEach((o, i) => { if (o.trim() !== '') kept.push(i) })
	if (kept.length < 2) { fail(t('pulse', 'Please provide at least two options.')); return null }
	body.options = kept.map((i) => draft.options[i].trim())
	return kept
}
export const QUESTION_TYPES = {
	choice: {
		toDraft(poll, key, d) {
			d.options = (poll.options || []).map((o) => o.label)
			const ci = poll.correctOption ? (poll.options || []).findIndex((o) => o.id === poll.correctOption) : 0
			d.correctIndex = ci >= 0 ? ci : 0
		},
		toBody(draft, body, isQuiz, fail) {
			if (!isQuiz) {
				body.options = draft.options.map((o) => o.trim()).filter(Boolean)
				if (body.options.length < 2) fail(t('pulse', 'Please provide at least two options.'))
				return
			}
			const kept = keptOptions(draft, body, fail)
			if (!kept) return
			const ci = kept.indexOf(draft.correctIndex)
			if (ci < 0) { fail(t('pulse', 'Please mark the correct answer.')); return }
			body.correctIndex = ci
		},
	},
	rank: {
		toDraft(poll, key, d) {
			// In a quiz, the stored option order IS the solution.
			d.options = (poll.options || []).map((o) => o.label)
		},
		toBody(draft, body, isQuiz, fail) {
			body.options = draft.options.map((o) => o.trim()).filter(Boolean)
			if (body.options.length < 2) fail(t('pulse', 'Please provide at least two answers.'))
		},
	},
	match: {
		toDraft(poll, key, d) {
			const match = poll.match || {}
			const targets = match.targets || []
			const labelById = {}
			for (const target of targets) labelById[target.id] = target.label
			const map = key.map || {}
			// Quiz: the right column comes from the solution. Poll: there is none
			// there — the pairing is then the input order (item i to target i).
			d.pairs = (match.items || []).map((item, i) => ({
				left: item.label,
				right: labelById[map[item.id]] !== undefined ? labelById[map[item.id]] : ((targets[i] || {}).label || ''),
			}))
			while (d.pairs.length < 2) d.pairs.push({ left: '', right: '' })
		},
		toBody(draft, body, isQuiz, fail) {
			const pairs = draft.pairs
				.map((pair) => ({ left: (pair.left || '').trim(), right: (pair.right || '').trim() }))
				.filter((pair) => pair.left !== '' || pair.right !== '')
			if (pairs.some((pair) => pair.left === '' || pair.right === '')) {
				fail(t('pulse', 'Please fill in both sides of every pair.'))
				return
			}
			if (pairs.length < 2) {
				fail(t('pulse', 'Please provide at least two pairs.'))
				return
			}
			body.pairs = pairs
		},
	},
	multi: {
		toDraft(poll, key, d) {
			d.options = (poll.options || []).map((o) => o.label)
			const correct = key.correct || []
			d.correctIndexes = (poll.options || []).map((o, i) => (correct.includes(o.id) ? i : -1)).filter((i) => i >= 0)
		},
		toBody(draft, body, isQuiz, fail) {
			const kept = keptOptions(draft, body, fail)
			if (!kept) return
			const idx = kept.map((orig, pos) => (draft.correctIndexes.includes(orig) ? pos : -1)).filter((pp) => pp >= 0)
			if (!idx.length) { fail(t('pulse', 'Please mark at least one correct answer.')); return }
			body.correctIndexes = idx
		},
	},
	truefalse: {
		toDraft(poll, key, d) {
			const ci = poll.correctOption ? (poll.options || []).findIndex((o) => o.id === poll.correctOption) : 0
			d.correctIndex = ci >= 0 ? ci : 0
		},
		toBody(draft, body) {
			body.correctIndex = draft.correctIndex
		},
	},
	number: {
		toDraft(poll, key, d) {
			d.target = key.target !== undefined ? key.target : ''
			d.tolerance = key.tolerance !== undefined ? key.tolerance : 0
		},
		toBody(draft, body, isQuiz, fail) {
			if (draft.target === '' || draft.target === null || isNaN(Number(draft.target))) { fail(t('pulse', 'Please enter a target number.')); return }
			body.target = Number(draft.target)
			body.tolerance = draft.tolerance === '' ? 0 : Math.abs(Number(draft.tolerance)) || 0
		},
	},
	text: {
		toDraft(poll, key, d) {
			d.answers = (key.accepted && key.accepted.length) ? key.accepted.slice() : ['']
		},
		toBody(draft, body, isQuiz, fail) {
			body.answers = draft.answers.map((a) => a.trim()).filter(Boolean)
			if (!body.answers.length) fail(t('pulse', 'Please provide at least one correct answer.'))
		},
	},
	scale: {
		toDraft(poll, key, d) {
			const sc = poll.scale || {}
			d.scaleMode = ['spectrum', 'compass'].includes(sc.mode) ? sc.mode : 'single'
			d.scaleMax = sc.max || 5
			d.minLabel = sc.minLabel || ''
			d.maxLabel = sc.maxLabel || ''
			if (sc.mode === 'spectrum' && Array.isArray(sc.aspects) && sc.aspects.length) {
				d.aspects = sc.aspects.map((a) => ({ label: a.label || '', poleLow: a.poleLow || '', poleHigh: a.poleHigh || '' }))
			}
			if (sc.mode === 'compass') {
				d.range = sc.range || 5
				const ax = (a) => ({ title: (a || {}).title || '', poleLow: (a || {}).poleLow || '', poleHigh: (a || {}).poleHigh || '' })
				d.axisX = ax(sc.axisX)
				d.axisY = ax(sc.axisY)
				const cl = Array.isArray(sc.cornerLabels) ? sc.cornerLabels : []
				d.cornerLabels = [cl[0] || '', cl[1] || '', cl[2] || '', cl[3] || '']
				d.heatmapThreshold = sc.heatmapThreshold || 45
			}
		},
		toBody(draft, body, isQuiz, fail) {
			body.scaleMode = draft.scaleMode
			body.scaleMax = draft.scaleMax
			if (draft.scaleMode === 'spectrum') {
				const aspects = draft.aspects
					.map((a) => ({ label: (a.label || '').trim(), poleLow: (a.poleLow || '').trim(), poleHigh: (a.poleHigh || '').trim() }))
					.filter((a) => a.label !== '')
				if (aspects.length < 3) { fail(t('pulse', 'Please name at least three aspects.')); return }
				body.aspects = aspects.slice(0, 8)
			} else if (draft.scaleMode === 'compass') {
				const ax = (a) => ({ title: (a.title || '').trim(), poleLow: (a.poleLow || '').trim(), poleHigh: (a.poleHigh || '').trim() })
				const axisX = ax(draft.axisX)
				const axisY = ax(draft.axisY)
				if (!axisX.title || !axisX.poleLow || !axisX.poleHigh || !axisY.title || !axisY.poleLow || !axisY.poleHigh) {
					fail(t('pulse', 'Please give both axes a title and two pole labels each.')); return
				}
				body.range = draft.range
				body.axisX = axisX
				body.axisY = axisY
				body.cornerLabels = draft.cornerLabels.map((c) => (c || '').trim())
				body.heatmapThreshold = draft.heatmapThreshold || 45
			} else {
				body.minLabel = draft.minLabel
				body.maxLabel = draft.maxLabel
			}
		},
	},
	words: {
		toDraft(poll, key, d) {
			d.maxWords = poll.maxWords || 3
		},
		toBody(draft, body) {
			body.maxWords = draft.maxWords
		},
	},
}

export function emptyDraft() {
	return { type: 'choice', question: '', options: ['', ''], maxWords: 3, scaleMax: 5, scaleMode: 'single', aspects: [{ label: '', poleLow: '', poleHigh: '' }, { label: '', poleLow: '', poleHigh: '' }, { label: '', poleLow: '', poleHigh: '' }], range: 5, axisX: { title: '', poleLow: '', poleHigh: '' }, axisY: { title: '', poleLow: '', poleHigh: '' }, cornerLabels: ['', '', '', ''], heatmapThreshold: 45, minLabel: '', maxLabel: '', imageFile: null, imagePreview: '', imageExisting: '', imageRemove: false, correctIndex: 0, correctIndexes: [], target: '', tolerance: 0, answers: [''], pairs: [{ left: '', right: '' }, { left: '', right: '' }], timeLimit: 30 }
}

// Composer segmented control: question types per mode (§4.3, wrap-safe). The
// server accepts exactly these (DeckService::TYPES_QUIZ / TYPES_POLL).
export function typeOptionsFor(isQuiz) {
	return isQuiz
		? [
			{ value: 'choice', label: t('pulse', 'Multiple choice') },
			{ value: 'truefalse', label: t('pulse', 'True/False') },
			{ value: 'multi', label: t('pulse', 'Multiple answers') },
			{ value: 'number', label: t('pulse', 'Number guess') },
			{ value: 'text', label: t('pulse', 'Free text') },
			{ value: 'rank', label: t('pulse', 'Ranking') },
			{ value: 'match', label: t('pulse', 'Matching') },
		]
		: [
			{ value: 'choice', label: t('pulse', 'Multiple choice') },
			{ value: 'words', label: t('pulse', 'Word cloud') },
			{ value: 'scale', label: t('pulse', 'Scale') },
			{ value: 'rank', label: t('pulse', 'Ranking') },
			{ value: 'match', label: t('pulse', 'Matching') },
		]
}

// Short tag + color class for the deck icon (SC = the scale family, in the "you" magenta).
export function typeTag(type) {
	return ({
		choice: { t: t('pulse', 'MC'), c: 'mc' }, words: { t: t('pulse', 'WC'), c: 'wc' }, scale: { t: t('pulse', 'SC'), c: 'sc' },
		truefalse: { t: t('pulse', 'TF'), c: 'wf' }, multi: { t: t('pulse', 'MA'), c: 'ma' }, number: { t: '#', c: 'nu' }, text: { t: t('pulse', 'Tx'), c: 'tx' },
		rank: { t: '1-2-3', c: 'rk' }, match: { t: t('pulse', 'MT'), c: 'mt' },
	})[type] || { t: t('pulse', 'MC'), c: 'mc' }
}

// Scale submode for the deck subtitle.
export function scaleModeLabel(p) {
	const m = (p.scale && p.scale.mode) || 'single'
	return m === 'spectrum' ? t('pulse', 'Spectrum') : m === 'compass' ? t('pulse', 'Compass') : t('pulse', 'Single')
}

export function typeLabel(type) {
	return {
		words: t('pulse', 'Word cloud'), scale: t('pulse', 'Scale'), truefalse: t('pulse', 'True/False'), rank: t('pulse', 'Ranking'), match: t('pulse', 'Matching'),
		multi: t('pulse', 'Multiple answers'), number: t('pulse', 'Number guess'), text: t('pulse', 'Free text'),
	}[type] || t('pulse', 'Multiple choice')
}

// Short answer hint per quiz question in the deck list.
export function answerHint(poll) {
	if (poll.type === 'truefalse') {
		const o = (poll.options || []).find((x) => x.id === poll.correctOption)
		return o ? o.label : '—'
	}
	if (poll.type === 'choice') return t('pulse', 'Answer {letter}', { letter: correctLetter(poll) })
	if (poll.type === 'multi') return n('pulse', '%n correct', '%n correct', ((poll.answerKey || {}).correct || []).length)
	if (poll.type === 'number') {
		const k = poll.answerKey || {}
		return '= ' + (k.target ?? '?') + (k.tolerance ? ' ±' + k.tolerance : '')
	}
	if (poll.type === 'text') return t('pulse', 'Free text')
	// Ranking: the solution is the option order — a count instead of a list.
	if (poll.type === 'rank') return n('pulse', '%n in order', '%n in order', (poll.options || []).length)
	if (poll.type === 'match') return n('pulse', '%n pair', '%n pairs', ((poll.match || {}).items || []).length)
	return ''
}

// Quiz: letter (A, B, …) of a question's correct answer.
export function correctLetter(poll) {
	if (!poll || !poll.options) return '—'
	const i = poll.options.findIndex((o) => o.id === poll.correctOption)
	return i >= 0 ? String.fromCharCode(65 + i) : '—'
}

/*
 * What a destructive action costs, as an extra sentence for the confirmation
 * (§0.9). Without counts or without anyone joined: ''. Moderated counts
 * like the draft (whoever is in has to join again).
 */
export function lossText(counts, state) {
	if (!counts || !counts.joined) return ''
	if (state === 'closed' || state === 'released') {
		const vars = {
			participants: n('pulse', '%n participant', '%n participants', counts.joined),
			answers: n('pulse', '%n answer', '%n answers', counts.answers || 0),
		}
		return state === 'closed'
			? t('pulse', '{participants} and {answers} will be deleted. The results have not been released yet — download “Participants as CSV” first if you need them.', vars)
			: t('pulse', '{participants} and {answers} will be deleted. Download “Participants as CSV” first if you still need them.', vars)
	}
	return n('pulse', '%n person who already joined will have to join again.', '%n people who already joined will have to join again.', counts.joined)
}
