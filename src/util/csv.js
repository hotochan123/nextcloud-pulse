/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Pulse — CSV-Downloads des Moderators mit Requesttoken.
 *
 * Die Export-Route hat nur #[NoAdminRequired]; die SecurityMiddleware von
 * Nextcloud verlangt deshalb einen Requesttoken. Ein <a href download> oder
 * location.href schickt keinen Header mit -> 412 (derselbe Fehler, den
 * CHANGELOG 0.17 für die Bildroute behoben hat). Request::passesCSRFCheck
 * liest `requesttoken` aber auch aus der Query, also hängt er dort.
 */
import { generateUrl } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'

/**
 * Adresse des CSV-Exports. Den Token beim Aufruf lesen, nicht einmal merken:
 * Nextcloud kann ihn im Lauf einer Sitzung tauschen.
 * @param {string} code Raum-Code
 * @param {string} [view] '' (Ergebnisse je Frage) | 'players' | 'answers' (nur eigenes Tempo)
 * @return {string} URL mit Requesttoken
 */
export function csvUrl(code, view = '') {
	const q = (view ? 'view=' + encodeURIComponent(view) + '&' : '')
		+ 'requesttoken=' + encodeURIComponent(getRequestToken() || '')
	return generateUrl('/apps/pulse/api/1.0/rooms/' + code + '/export') + '?' + q
}

/**
 * CSV herunterladen (Knöpfe ohne <a>). Die Antwort ist ein Download
 * (Content-Disposition), die Seite bleibt also stehen.
 * @param {string} code Raum-Code
 * @param {string} [view] s. csvUrl
 */
export function downloadCsv(code, view = '') {
	window.location.assign(csvUrl(code, view))
}
