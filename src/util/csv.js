/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Pulse — the moderator's CSV downloads with a request token.
 *
 * The export route only has #[NoAdminRequired]; Nextcloud's SecurityMiddleware
 * therefore requires a request token. An <a href download> or
 * location.href sends no header -> 412 (the same error that
 * CHANGELOG 0.17 fixed for the image route). Request::passesCSRFCheck
 * also reads `requesttoken` from the query, though, so that is where it goes.
 */
import { roomApi } from './routes.js'
import { getRequestToken } from '@nextcloud/auth'

/**
 * URL of the CSV export. Read the token at call time, do not keep it:
 * Nextcloud can swap it during a session.
 * @param {string} code room code
 * @param {string} [view] '' (results per question) | 'players' | 'answers' (self-paced only)
 * @return {string} URL with request token
 */
export function csvUrl(code, view = '') {
	const q = (view ? 'view=' + encodeURIComponent(view) + '&' : '')
		+ 'requesttoken=' + encodeURIComponent(getRequestToken() || '')
	return roomApi(code, '/export') + '?' + q
}

/**
 * Download a CSV (buttons without <a>). The response is a download
 * (Content-Disposition), so the page stays where it is.
 * @param {string} code room code
 * @param {string} [view] see csvUrl
 */
export function downloadCsv(code, view = '') {
	window.location.assign(csvUrl(code, view))
}
