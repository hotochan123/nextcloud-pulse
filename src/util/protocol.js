/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Pulse — protocol number between server and bundle (phone + projector).
 * The same number as Application::PROTOCOL (ProtocolConstantTest keeps both
 * equal). If /state reports a different one, the tab is still running a script from the
 * browser cache against a newer (or older) server: reloading once
 * fetches the matching bundle.
 */

export const PROTOCOL = 3

// Marker per tab: for which server protocol a reload has already happened.
const MARK_KEY = 'pulse-protocol-reload'

/**
 * true = the page is being reloaded. At most once per server protocol and tab
 * (sessionStorage), otherwise a stuck cache would cause a reload loop;
 * without sessionStorage no reload.
 * @param {number|undefined} serverProtocol `protocol` from /state
 * @return {boolean} true if the reload was triggered (the caller aborts)
 */
export function reloadOnProtocolMismatch(serverProtocol) {
	if (serverProtocol === undefined || serverProtocol === null) return false
	if (Number(serverProtocol) === PROTOCOL) return false
	const mark = String(serverProtocol)
	try {
		// In the embed frame of the PowerPoint add-in this is third-party
		// storage: depending on the browser, even accessing sessionStorage throws
		// (SecurityError) or it is missing entirely — then better carry on without a reload.
		const store = window.sessionStorage
		if (!store || store.getItem(MARK_KEY) === mark) return false
		store.setItem(MARK_KEY, mark)
		// Only reload if the marker really is set: storage that silently
		// discards writes would otherwise produce exactly that loop.
		if (store.getItem(MARK_KEY) !== mark) return false
		window.location.reload()
		return true
	} catch (e) {
		return false
	}
}
