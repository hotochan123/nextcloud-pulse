/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Pulse — Protokollnummer zwischen Server und Bundle (Handy + Beamer).
 * Dieselbe Zahl wie Application::PROTOCOL (ProtocolConstantTest hält beide
 * gleich). Meldet /state eine andere, läuft im Tab noch ein Skript aus dem
 * Browser-Cache gegen einen neueren (oder älteren) Server: einmal neu laden
 * holt das passende Bundle.
 */

export const PROTOCOL = 3

// Vermerk je Tab: für welches Server-Protokoll schon neu geladen wurde.
const MARK_KEY = 'pulse-protocol-reload'

/**
 * true = Seite wird neu geladen. Höchstens einmal je Server-Protokoll und Tab
 * (sessionStorage), sonst entstünde bei hängendem Cache eine Reload-Schleife;
 * ohne sessionStorage kein Reload.
 * @param {number|undefined} serverProtocol `protocol` aus /state
 * @return {boolean} true, wenn der Reload angestoßen wurde (Aufrufer bricht ab)
 */
export function reloadOnProtocolMismatch(serverProtocol) {
	if (serverProtocol === undefined || serverProtocol === null) return false
	if (Number(serverProtocol) === PROTOCOL) return false
	const mark = String(serverProtocol)
	try {
		// Im Einbett-Rahmen des PowerPoint-Add-ins ist das ein Drittanbieter-
		// Speicher: je nach Browser wirft schon der Zugriff auf sessionStorage
		// (SecurityError) oder er fehlt ganz — dann lieber ohne Reload weiter.
		const store = window.sessionStorage
		if (!store || store.getItem(MARK_KEY) === mark) return false
		store.setItem(MARK_KEY, mark)
		// Nur neu laden, wenn der Vermerk wirklich steht: ein Speicher, der
		// Schreibzugriffe still verwirft, ergäbe sonst genau die Schleife.
		if (store.getItem(MARK_KEY) !== mark) return false
		window.location.reload()
		return true
	} catch (e) {
		return false
	}
}
