// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Pass mark of the load run (dev/sim/load.mjs), kept apart so that a unit test
// (dev/unit/load-verdict.test.mjs) can check it without a server.
//
// A run passes when no request ended in a 5xx, a timeout or a network error,
// every phone joined, and the p95 of the phones' /state requests in the load
// phase stays below the guideline (300 ms by default).

// Nearest-rank percentile of an ascending list; NaN for an empty one.
export function pct(sorted, p) {
	if (!sorted.length) return NaN
	return sorted[Math.min(sorted.length - 1, Math.max(0, Math.ceil((p / 100) * sorted.length) - 1))]
}

// A request counts as bad when it has no HTTP status (timeout, network error)
// or a 5xx.
export const isBad = (sample) => typeof sample.status !== 'number' || sample.status >= 500

// samples: [{key, status, ms, phase}], phones/joined: counts, p95Max: ms.
// Returns {pass, p95, bad, joined, phones, requests, reasons, line}.
export function verdict({ samples, phones, joined, p95Max = 300 }) {
	const state = samples.filter((x) => x.key === 'state' && x.phase === 'load').map((x) => x.ms).sort((a, b) => a - b)
	const p95 = pct(state, 95)
	const bad = samples.filter(isBad).length
	const reasons = []
	if (!state.length) reasons.push('no /state samples in the load phase')
	else if (!(p95 < p95Max)) reasons.push(`p95 ${Math.round(p95)} ms >= ${p95Max}`)
	if (bad) reasons.push(`${bad} 5xx/timeouts`)
	if (joined !== phones) reasons.push(`joined ${joined}/${phones}`)
	const pass = reasons.length === 0
	const shown = Number.isFinite(p95) ? Math.round(p95) : '—'
	const line = `LOAD: ${pass ? 'PASS' : 'FAIL'} p95 /state ${shown} ms (< ${p95Max}), 5xx ${bad}, joined ${joined}/${phones}, requests ${samples.length}` +
		(pass ? '' : '; ' + reasons.join(', '))
	return { pass, p95, bad, joined, phones, requests: samples.length, reasons, line }
}

// LOAD_P95_MAX: a positive number of milliseconds, anything else the default.
export function p95MaxFrom(value, fallback = 300) {
	const n = Number(value)
	return value !== undefined && value !== '' && Number.isFinite(n) && n > 0 ? n : fallback
}
