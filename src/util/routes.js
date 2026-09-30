/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Pulse — every URL the Vue frontend (src/) requests or links to, and the
 * time limits of its requests, in one place. The embed shell
 * js/pulse-embed.js is hand-written and builds its own URLs (projector page,
 * /s/{code}/state); nothing here covers those.
 *
 * Each builder returns exactly the string the views used to put together by
 * hand; dev/unit/routes.test.mjs pins those strings, reads every call site
 * under src/ and checks each path with its verb against appinfo/routes.php.
 * generateUrl() puts the webroot and, without mod_rewrite, /index.php in
 * front. A query string goes on outside it: the image's ?v= here, the CSV
 * export's view= and requesttoken= in util/csv.js (which reads the token at
 * call time).
 *
 * Only URLs, no requests: the axios calls stay in the views, and the public
 * code keeps its literal axios import (PublicCrossSiteTest).
 */
import { generateUrl } from '@nextcloud/router'

// Time limit (ms) of the moderator requests that must not hang: the
// self-paced calls, the player list, the counts for a confirmation and the
// quiet room refresh. A hanging call would otherwise keep `busy`, and with
// it the main action, stuck.
export const MOD_TIMEOUT = 15000

// Time limit (ms) of the public requests in self-paced mode (phone and
// projector); in a moderated room they go out without one.
export const PHONE_TIMEOUT = 10000

/**
 * Moderator API: the list of my rooms (GET) and a new room (POST).
 * @return {string} URL
 */
export function roomsApi() {
	return generateUrl('/apps/pulse/api/1.0/rooms')
}

/**
 * Moderator API of one room.
 * @param {string} code room code
 * @param {string} [suffix] path below the room, e.g. '/pace' or '/polls/12/grade'
 * @return {string} URL
 */
export function roomApi(code, suffix = '') {
	return generateUrl('/apps/pulse/api/1.0/rooms/' + code + suffix)
}

/**
 * Public API of one room (phone and projector, no account).
 * @param {string} code room code
 * @param {string} [suffix] path below the room, e.g. '/state' or '/vote'
 * @return {string} URL
 */
export function publicApi(code, suffix = '') {
	return generateUrl('/apps/pulse/s/' + code + suffix)
}

/**
 * Image of a question. The file name goes on as ?v=, so that a replaced
 * image does not come from the browser cache.
 * @param {string} code room code
 * @param {number} pollId question id
 * @param {string} image file name of the image (poll.image)
 * @param {object} [opts] options
 * @param {boolean} [opts.public] true = the public route (phone, projector), otherwise the moderator's
 * @return {string} URL
 */
export function pollImage(code, pollId, image, { public: isPublic = false } = {}) {
	const path = '/polls/' + pollId + '/image'
	return (isPublic ? publicApi(code, path) : roomApi(code, path)) + '?v=' + encodeURIComponent(image)
}

/**
 * Participant page of a room: the join link and its QR code.
 * @param {string} code room code
 * @return {string} URL
 */
export function participantPage(code) {
	return generateUrl('/apps/pulse/s/' + code)
}

/**
 * Join page, where a code is typed in.
 * @return {string} URL
 */
export function joinPage() {
	return generateUrl('/apps/pulse/join')
}

/**
 * Projector page of a room.
 * @param {string} code room code
 * @return {string} URL
 */
export function screenPage(code) {
	return generateUrl('/apps/pulse/screen/' + code)
}

/**
 * The moderator's deep link to a room (history entry).
 * @param {string} code room code
 * @return {string} URL
 */
export function roomPage(code) {
	return generateUrl('/apps/pulse/room/' + code)
}

/**
 * The moderator's start page ("My rooms").
 * @return {string} URL
 */
export function home() {
	return generateUrl('/apps/pulse/')
}

/**
 * Office manifest of the PowerPoint add-in, filled in by the server.
 * @return {string} URL
 */
export function addinManifest() {
	return generateUrl('/apps/pulse/addin/manifest.xml')
}

/**
 * Open the projector page of a room in a window of its own. The window name
 * is per room: a second click reuses that window instead of opening another.
 * @param {string} code room code
 */
export function openProjector(code) {
	window.open(window.location.origin + screenPage(code), 'pulse-beamer-' + code)
}
