#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Unit test for src/util/routes.js, the one source of URLs and request time
// limits of the Vue frontend (src/), without a browser and without Nextcloud:
//
//   node dev/unit/routes.test.mjs      # exit 0 = all green
//
// - Golden list: the URL shapes the views use, with the HTTP verb, as the
//   exact string the views built by hand before routes.js existed. Twice:
//   without a webroot and without mod_rewrite (/index.php/apps/pulse/…),
//   and below a webroot with mod_rewrite (/nextcloud/apps/pulse/…). Every
//   one of those paths, with its verb, matches a route in appinfo/routes.php.
// - Call sites: every builder call under src/, read from the source, with
//   its path composed from the call as written and the verb of the axios
//   call it feeds, resolves in appinfo/routes.php: a request to an API
//   route, a link to a GET route. The public bundle reaches only public
//   routes, every axios call takes one of these URLs, and the time limits
//   are the two constants, the phone's on the public side.
// - util/csv.js reads the request token at call time; the query keeps
//   view= before requesttoken=.
// - The time limits, the projector window, and serverMessage() from
//   src/toast.js (the `||` of the copies it replaced).
// - Source guard: no other file under src/ builds an app URL.
//
// Not covered: the hand-written embed shell js/pulse-embed.js builds its
// own URLs (projector page, /s/{code}/state?spectate=1).
//
// generateUrl() is the real one from node_modules/@nextcloud/router. It
// reads window._oc_webroot, window.OC.config.modRewriteWorking and, without
// a webroot, window.location at call time; the test sets those globals.
import assert from 'node:assert/strict'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const APP = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

// The browser globals generateUrl(), openProjector() and getRequestToken() read.
const opened = []
globalThis.window = globalThis
globalThis.location = {
	protocol: 'https:',
	host: 'cloud.example.com:8443',
	origin: 'https://cloud.example.com:8443',
	pathname: '/index.php/apps/pulse/',
}
globalThis.open = (...args) => { opened.push(args) }
function setup({ webroot, rewrite }) {
	globalThis._oc_webroot = webroot
	globalThis.OC = { config: { modRewriteWorking: rewrite } }
}
setup({ webroot: '', rewrite: false })

const routes = await import('../../src/util/routes.js')
const { csvUrl } = await import('../../src/util/csv.js')
const { serverMessage } = await import('../../src/toast.js')
const {
	MOD_TIMEOUT, PHONE_TIMEOUT, roomsApi, roomApi, publicApi, pollImage,
	participantPage, joinPage, screenPage, roomPage, home, addinManifest, openProjector,
} = routes

let passed = 0
const failures = []
function test(name, fn) {
	try {
		fn()
		passed++
	} catch (e) {
		failures.push(name)
		console.log('FAIL ' + name + '\n     ' + String(e.message).split('\n').join('\n     '))
	}
}

const CODE = 'Ab3dE9'
const IMAGE = '7f3c9a1b.png'
const ODD_IMAGE = 'Image 1/ä&v=2.png' // pins encodeURIComponent
const TOKEN = 'tok/en+='

// ── Golden list ──────────────────────────────────────────────────────────
// [call sites, verb, URL from routes.js, the string the views built by hand]
// The rows are URL shapes: each calls a builder with the arguments its call
// sites pass and names those sites, but does not read them; the call-site
// scan further down does. Without a webroot and without mod_rewrite. The
// hand-built expressions were generateUrl('/apps/pulse/…' + code + …), with
// ?v= and the CSV query appended outside it.
const GOLDEN = () => [
	// Moderator.vue
	['Moderator fetchMyRooms', 'GET', roomsApi(), '/index.php/apps/pulse/api/1.0/rooms'],
	['Moderator startRoom', 'POST', roomsApi(), '/index.php/apps/pulse/api/1.0/rooms'],
	['Moderator api(code) load/refresh', 'GET', roomApi(CODE), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9'],
	['Moderator api(code) delete', 'DELETE', roomApi(CODE), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9'],
	['Moderator duplicate', 'POST', roomApi(CODE, '/duplicate'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/duplicate'],
	['Moderator rename', 'POST', roomApi(CODE, '/title'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/title'],
	['Moderator reset room', 'POST', roomApi(CODE, '/reset'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/reset'],
	['Moderator reveal at the end', 'POST', roomApi(CODE, '/reveal'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/reveal'],
	['Moderator practice', 'POST', roomApi(CODE, '/practice'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/practice'],
	['Moderator counts (self-paced)', 'GET', roomApi(CODE, '/progress'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/progress'],
	['Moderator leaderboard', 'GET', roomApi(CODE, '/leaderboard'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/leaderboard'],
	['Moderator summary', 'GET', roomApi(CODE, '/summary'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/summary'],
	['Moderator pace', 'POST', roomApi(CODE, '/pace'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/pace'],
	['Moderator add question', 'POST', roomApi(CODE, '/polls'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/polls'],
	['Moderator save question', 'PUT', roomApi(CODE, '/polls/' + 42), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/polls/42'],
	['Moderator delete question', 'DELETE', roomApi(CODE, '/polls/' + 42), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/polls/42'],
	['Moderator deck order', 'POST', roomApi(CODE, '/deck/order'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/deck/order'],
	['Moderator current', 'POST', roomApi(CODE, '/current'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/current'],
	['Moderator end quiz', 'POST', roomApi(CODE, '/end'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/end'],
	['Moderator lock', 'POST', roomApi(CODE, '/polls/' + 42 + '/' + 'lock'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/polls/42/lock'],
	['Moderator unlock', 'POST', roomApi(CODE, '/polls/' + 42 + '/' + 'unlock'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/polls/42/unlock'],
	['Moderator reset question', 'POST', roomApi(CODE, '/polls/' + 42 + '/reset'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/polls/42/reset'],
	['Moderator results', 'GET', roomApi(CODE, '/polls/' + 42 + '/results'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/polls/42/results'],
	['Moderator grade', 'POST', roomApi(CODE, '/polls/' + 42 + '/grade'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/polls/42/grade'],
	['Moderator demo votes', 'POST', roomApi(CODE, '/demo'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/demo'],
	['Moderator remove demo votes', 'DELETE', roomApi(CODE, '/demo'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/demo'],
	['Moderator upload image', 'POST', roomApi(CODE, '/polls/' + 42 + '/image'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/polls/42/image'],
	['Moderator remove image', 'DELETE', roomApi(CODE, '/polls/' + 42 + '/image'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/polls/42/image'],
	['Moderator modImageUrl', 'GET', pollImage(CODE, 42, IMAGE), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/polls/42/image?v=7f3c9a1b.png'],
	['Moderator modImageUrl, odd file name', 'GET', pollImage(CODE, 42, ODD_IMAGE), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/polls/42/image?v=Image%201%2F%C3%A4%26v%3D2.png'],
	['Moderator joinPath', 'GET', participantPage(CODE), '/index.php/apps/pulse/s/Ab3dE9'],
	['Moderator joinBase/joinPagePath', 'GET', joinPage(), '/index.php/apps/pulse/join'],
	['Moderator screenPath (canvas)', 'GET', screenPage(CODE), '/index.php/apps/pulse/screen/Ab3dE9'],
	['Moderator history entry', 'GET', roomPage(CODE), '/index.php/apps/pulse/room/Ab3dE9'],
	['Moderator goHome', 'GET', home(), '/index.php/apps/pulse/'],
	['Moderator downloadAddin', 'GET', addinManifest(), '/index.php/apps/pulse/addin/manifest.xml'],
	// util/csv.js (Moderator export button, PaceRun downloads)
	['csvUrl results per question', 'GET', csvUrl(CODE), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/export?requesttoken=tok%2Fen%2B%3D'],
	['csvUrl players', 'GET', csvUrl(CODE, 'players'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/export?view=players&requesttoken=tok%2Fen%2B%3D'],
	['csvUrl answers', 'GET', csvUrl(CODE, 'answers'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/export?view=answers&requesttoken=tok%2Fen%2B%3D'],
	// PaceRun.vue, PaceDeckStatus.vue, PaceOpenDialog.vue, LivePlayers.vue
	['PaceRun screenPath (preview)', 'GET', screenPage(CODE), '/index.php/apps/pulse/screen/Ab3dE9'],
	['PaceRun/PaceDeckStatus progressUrl', 'GET', roomApi(CODE, '/progress'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/progress'],
	['PaceRun/PaceOpenDialog/LivePlayers pace', 'POST', roomApi(CODE, '/pace'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/pace'],
	['PaceRun grade', 'POST', roomApi(CODE, '/polls/' + 42 + '/grade'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/polls/42/grade'],
	['LivePlayers load', 'GET', roomApi(CODE, '/leaderboard'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/leaderboard'],
	// Participant.vue, mixins/pace-phone.js, Screen.vue
	['Participant joinPagePath', 'GET', joinPage(), '/index.php/apps/pulse/join'],
	['Participant/Screen state', 'GET', publicApi(CODE, '/state'), '/index.php/apps/pulse/s/Ab3dE9/state'],
	['Participant summary', 'GET', publicApi(CODE, '/summary'), '/index.php/apps/pulse/s/Ab3dE9/summary'],
	['Participant vote', 'POST', publicApi(CODE, '/vote'), '/index.php/apps/pulse/s/Ab3dE9/vote'],
	['Participant join', 'POST', publicApi(CODE, '/join'), '/index.php/apps/pulse/s/Ab3dE9/join'],
	['pace-phone next', 'POST', publicApi(CODE, '/next'), '/index.php/apps/pulse/s/Ab3dE9/next'],
	// The join-code check counts towards the brute-force throttle, so its URL
	// must not change; the call-site scan below holds Participant.go() to it.
	['Participant go: code check', 'GET', publicApi(CODE, '/state'), '/index.php/apps/pulse/s/Ab3dE9/state'],
	['Participant go: navigate', 'GET', participantPage(CODE), '/index.php/apps/pulse/s/Ab3dE9'],
	['Participant/Screen imageUrl', 'GET', pollImage(CODE, 42, IMAGE, { public: true }), '/index.php/apps/pulse/s/Ab3dE9/polls/42/image?v=7f3c9a1b.png'],
	['Participant/Screen imageUrl, odd file name', 'GET', pollImage(CODE, 42, ODD_IMAGE, { public: true }), '/index.php/apps/pulse/s/Ab3dE9/polls/42/image?v=Image%201%2F%C3%A4%26v%3D2.png'],
	['Screen joinPath', 'GET', participantPage(CODE), '/index.php/apps/pulse/s/Ab3dE9'],
	['base() without a suffix', 'GET', publicApi(CODE), '/index.php/apps/pulse/s/Ab3dE9'],
]

// ── appinfo/routes.php ───────────────────────────────────────────────────
// Each entry: name, controller, url, verb, requirements. A placeholder
// without a requirement matches one path segment, as in Nextcloud's router.
const ROUTES = (() => {
	const src = readFileSync(join(APP, 'appinfo', 'routes.php'), 'utf8')
	const starts = [...src.matchAll(/\['name'\s*=>/g)].map((m) => m.index)
	return starts.map((start, i) => {
		const entry = src.slice(start, starts[i + 1] ?? src.length)
		const name = entry.match(/'name'\s*=>\s*'([^']*)'/)[1]
		const url = entry.match(/'url'\s*=>\s*'([^']*)'/)[1]
		const verb = entry.match(/'verb'\s*=>\s*'([A-Z]+)'/)[1]
		// The values are single-quoted PHP strings: '\d+' is the regex \d+.
		const reqs = entry.split(/'requirements'\s*=>/)[1] || ''
		const requirement = (ph) => (reqs.match(new RegExp("'" + ph + "'\\s*=>\\s*'([^']*)'")) || [])[1]
		const pattern = ('/apps/pulse' + url).split(/(\{\w+\})/).map((part) => {
			const ph = part.match(/^\{(\w+)\}$/)
			return ph ? '(?:' + (requirement(ph[1]) || '[^/]+') + ')' : part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
		}).join('')
		return { name, controller: name.split('#')[0], url, verb, re: new RegExp('^' + pattern + '$') }
	})
})()

// Controllers of routes.php: the JSON APIs (what axios may call), and what
// the public bundle (no account) may reach.
const API = new Set(['roomApi', 'publicVote'])
const PUBLIC = new Set(['public', 'publicVote'])

function routeOf(url, prefix, verb) {
	const path = url.slice(prefix.length).split('?')[0]
	return ROUTES.find((r) => r.verb === verb && r.re.test(path))
}

test('routes.php: all 43 url entries parsed, each with a verb and a controller', () => {
	assert.equal(ROUTES.length, 43)
	for (const r of ROUTES) assert.match(r.verb, /^(GET|POST|PUT|DELETE)$/, r.url)
	assert.deepEqual([...new Set(ROUTES.map((r) => r.controller))].sort(), ['addin', 'page', 'public', 'publicVote', 'roomApi'])
	// the parser picked up the requirements: a 6-character code only
	const show = ROUTES.find((r) => r.url === '/s/{code}')
	assert.ok(show.re.test('/apps/pulse/s/Ab3dE9'))
	assert.ok(!show.re.test('/apps/pulse/s/Ab3dE'))
	const poll = ROUTES.find((r) => r.url === '/api/1.0/rooms/{code}/polls/{pollId}' && r.verb === 'PUT')
	assert.ok(poll.re.test('/apps/pulse/api/1.0/rooms/Ab3dE9/polls/42'))
	assert.ok(!poll.re.test('/apps/pulse/api/1.0/rooms/Ab3dE9/polls/x'))
})

for (const scenario of [
	{ label: 'no webroot, no mod_rewrite', webroot: '', rewrite: false, prefix: '/index.php' },
	{ label: 'webroot /nextcloud, mod_rewrite', webroot: '/nextcloud', rewrite: true, prefix: '/nextcloud' },
]) {
	setup(scenario)
	globalThis._nc_auth_requestToken = TOKEN
	const rows = GOLDEN()
	// The golden strings are written without a webroot; the other scenario
	// only swaps that prefix (generateUrl: webroot, then /index.php unless
	// mod_rewrite works).
	const expected = (s) => s.replace('/index.php/apps/pulse/', scenario.prefix + '/apps/pulse/')

	test(`golden list (${scenario.label}): the same strings as the hand-built URLs`, () => {
		for (const [site, , actual, want] of rows) assert.equal(actual, expected(want), site)
	})

	test(`golden list (${scenario.label}): every path with its verb has a route in routes.php`, () => {
		for (const [site, verb, actual] of rows) {
			assert.ok(actual.startsWith(scenario.prefix + '/apps/pulse/'), site + ': ' + actual)
			assert.ok(routeOf(actual, scenario.prefix, verb), `${site}: no ${verb} route for ${actual}`)
		}
	})

	test(`openProjector (${scenario.label}): the projector page, absolute, in a window per room`, () => {
		opened.length = 0
		openProjector(CODE)
		// origin (with the scheme), where the join text uses host
		assert.deepEqual(opened, [[expected('https://cloud.example.com:8443/index.php/apps/pulse/screen/Ab3dE9'), 'pulse-beamer-Ab3dE9']])
		const path = opened[0][0].slice(globalThis.location.origin.length)
		assert.ok(routeOf(path, scenario.prefix, 'GET'), path)
	})
}
setup({ webroot: '', rewrite: false })

test('csvUrl: reads the request token at call time, never keeps it', () => {
	globalThis._nc_auth_requestToken = 'first'
	assert.equal(csvUrl(CODE, 'players'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/export?view=players&requesttoken=first')
	globalThis._nc_auth_requestToken = 'second'
	assert.equal(csvUrl(CODE, 'players'), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/export?view=players&requesttoken=second')
	globalThis._nc_auth_requestToken = undefined
	assert.equal(csvUrl(CODE), '/index.php/apps/pulse/api/1.0/rooms/Ab3dE9/export?requesttoken=')
})

test('time limits: 15 s for the moderator, 10 s for phone and projector', () => {
	assert.equal(MOD_TIMEOUT, 15000)
	assert.equal(PHONE_TIMEOUT, 10000)
})

test('serverMessage: the server message, else the fallback (|| semantics)', () => {
	const fb = 'Could not grade.'
	assert.equal(serverMessage({ response: { status: 400, data: { message: 'Too long.' } } }, fb), 'Too long.')
	assert.equal(serverMessage({ response: { status: 400, data: { message: '' } } }, fb), fb)
	assert.equal(serverMessage({ response: { status: 500, data: {} } }, fb), fb)
	assert.equal(serverMessage({ response: { status: 500, data: '' } }, fb), fb)
	assert.equal(serverMessage({ response: {} }, fb), fb)
	assert.equal(serverMessage({ code: 'ECONNABORTED' }, fb), fb) // timeout: no response
	assert.equal(serverMessage(undefined, fb), fb)
	assert.equal(serverMessage(null, fb), fb)
})

// ── Source guard ─────────────────────────────────────────────────────────
function walk(dir) {
	return readdirSync(dir).flatMap((name) => {
		const p = join(dir, name)
		return statSync(p).isDirectory() ? walk(p) : [p]
	})
}

test('src/: app URLs and generateUrl only in src/util/routes.js', () => {
	const offenders = []
	for (const file of walk(join(APP, 'src'))) {
		const rel = relative(APP, file).split('\\').join('/')
		if (rel === 'src/util/routes.js' || !/\.(js|vue|mjs)$/.test(rel)) continue
		const text = readFileSync(file, 'utf8')
		if (text.includes('apps/pulse/')) offenders.push(rel + ': apps/pulse/')
		if (text.includes('@nextcloud/router')) offenders.push(rel + ': @nextcloud/router')
	}
	assert.deepEqual(offenders, [])
})

// ── Call sites ───────────────────────────────────────────────────────────
// The golden list pins what the builders return; this part reads the views.
// For every builder call under src/ the path is composed from the call as
// written: string literals stay, the room code becomes CODE, any other
// operand 42 (an id), except the operands in OPERANDS, which are path
// segments and take the values their source gives them. The verb is that of
// the axios call the URL feeds: as its first argument, through a const
// (Moderator.syncImage) or through a method an axios call takes
// (progressUrl() in mixins/progress-poll.js). A URL no axios call takes is
// a link, an <img> source or a download: GET. Outside the builder nothing
// changes the URL but the host or origin in front of an absolute link and
// a query string behind it.

// The source with comment lines blanked out; offsets stay the same.
function codeOnly(text) {
	return text.split('\n').map((line) => (/^\s*(\/\/|\/\*|\*)/.test(line) ? ' '.repeat(line.length) : line)).join('\n')
}
const lineOf = (text, at) => text.slice(0, at).split('\n').length

// Index of the quote that closes the string literal opening at `i`.
function stringEnd(s, i) {
	for (let j = i + 1; j < s.length; j++) {
		if (s[j] === '\\') j++
		else if (s[j] === s[i]) return j
	}
	throw new Error('unterminated string at ' + i)
}

// The top-level pieces of `s` between `sep` characters (outside brackets and strings).
function splitTop(s, sep) {
	const parts = []
	let depth = 0
	let start = 0
	for (let i = 0; i < s.length; i++) {
		const c = s[i]
		if (c === "'" || c === '"' || c === '`') i = stringEnd(s, i)
		else if ('([{'.includes(c)) depth++
		else if (')]}'.includes(c)) depth--
		else if (c === sep && depth === 0) {
			parts.push(s.slice(start, i).trim())
			start = i + 1
		}
	}
	parts.push(s.slice(start).trim())
	return parts.filter((p) => p !== '')
}

// The argument texts of the call whose '(' is at `open`, and the index
// after its ')'.
function callArgs(s, open) {
	let depth = 0
	for (let i = open; i < s.length; i++) {
		const c = s[i]
		if (c === "'" || c === '"' || c === '`') i = stringEnd(s, i)
		else if ('([{'.includes(c)) depth++
		else if (')]}'.includes(c) && --depth === 0) return { args: splitTop(s.slice(open + 1, i), ','), end: i + 1 }
	}
	throw new Error('unbalanced call at ' + open)
}

// The value of a string literal, or null for any other expression.
function literal(expr) {
	const m = expr.match(/^'((?:[^'\\]|\\.)*)'$/) || expr.match(/^"((?:[^"\\]|\\.)*)"$/)
	if (m) return m[1].replace(/\\(.)/g, '$1')
	const tpl = expr.match(/^`([^`]*)`$/)
	return tpl ? tpl[1].replace(/\$\{[^}]*\}/g, '42') : null
}

// Operands that are path segments, not ids, with the values their source
// gives them (checked against the source below).
const OPERANDS = {
	'src/Moderator.vue': {
		verb: ['lock', 'unlock'], // setLock(): const verb = lock ? 'lock' : 'unlock'
		endpoint: ['/summary', '/leaderboard'], // loadPhase(endpoint, …) from openSummary/openLeaderboard
	},
}

// Every value a path argument can take: literals as written, OPERANDS by
// their list, anything else an id.
function pathValues(expr, operands) {
	if (expr === undefined) return ['']
	let values = ['']
	for (const part of splitTop(expr, '+')) {
		const lit = literal(part)
		const options = lit !== null ? [lit] : (operands[part] || ['42'])
		values = values.flatMap((v) => options.map((o) => v + o))
	}
	return values
}

function imageOptions(expr) {
	if (expr === undefined) return {}
	assert.equal(expr.replace(/\s+/g, ' '), '{ public: true }', 'pollImage options')
	return { public: true }
}

// Per builder: whether its first argument is the room code, and the URLs
// the call can produce (args = the argument texts). this.api()/this.base()
// are the views' own wrappers of roomApi()/publicApi() (checked below).
const BUILDERS = {
	roomsApi: { paths: () => [roomsApi()] },
	roomApi: { code: true, paths: (a, ops) => pathValues(a[1], ops).map((s) => roomApi(CODE, s)) },
	'this.api': { code: true, paths: (a, ops) => pathValues(a[1], ops).map((s) => roomApi(CODE, s)) },
	publicApi: { code: true, paths: (a, ops) => pathValues(a[1], ops).map((s) => publicApi(CODE, s)) },
	'this.base': { suffix: true, paths: (a, ops) => pathValues(a[0], ops).map((s) => publicApi(CODE, s)) },
	pollImage: { code: true, paths: (a) => [pollImage(CODE, 42, IMAGE, imageOptions(a[3]))] },
	participantPage: { code: true, paths: () => [participantPage(CODE)] },
	joinPage: { paths: () => [joinPage()] },
	screenPage: { code: true, paths: () => [screenPage(CODE)] },
	openProjector: { code: true, paths: () => [screenPage(CODE)] }, // origin + screenPage (test above)
	roomPage: { code: true, paths: () => [roomPage(CODE)] },
	home: { paths: () => [home()] },
	addinManifest: { paths: () => [addinManifest()] },
	csvUrl: { code: true, paths: () => [csvUrl(CODE)] },
	downloadCsv: { code: true, paths: () => [csvUrl(CODE)] }, // location.assign(csvUrl(code, view))
}
const CALL = new RegExp('(?<![\\w$.])(' + Object.keys(BUILDERS).map((b) => b.replace('.', '\\.')).join('|') + ')\\(', 'g')

const SRC = walk(join(APP, 'src'))
	.map((file) => relative(APP, file).split('\\').join('/'))
	.filter((rel) => /\.(js|vue|mjs)$/.test(rel) && rel !== 'src/util/routes.js')
	.map((rel) => ({ rel, text: codeOnly(readFileSync(join(APP, rel), 'utf8')) }))

// The method a position sits in: the nearest `name(…) {` line above it.
const KEYWORDS = new Set(['if', 'for', 'while', 'switch', 'catch', 'function'])
function enclosingMethod(text, at) {
	let name = null
	for (const m of text.slice(0, at).matchAll(/^[ \t]*(?:async\s+)?(\w+)\s*\([^()\n]*\)\s*\{[ \t]*$/gm)) {
		if (!KEYWORDS.has(m[1])) name = m[1]
	}
	return name
}

// The files the public entry (Participant, Screen) and the moderator entry
// pull in, from their relative imports.
function closure(entry) {
	const seen = new Set()
	const visit = (rel) => {
		if (seen.has(rel)) return
		seen.add(rel)
		const text = codeOnly(readFileSync(join(APP, rel), 'utf8'))
		for (const m of text.matchAll(/^import\s+(?:[^'"]*?\s+from\s+)?'(\.{1,2}\/[^']+)'/gm)) {
			const target = relative(APP, join(APP, dirname(rel), m[1])).split('\\').join('/')
			if (/\.(js|vue)$/.test(target)) visit(target)
		}
	}
	visit(entry)
	return seen
}
const PUBLIC_FILES = closure('src/public.js')
const MAIN_FILES = closure('src/main.js')

// Collect the call sites; the wrappers api(code, suffix)/base(suffix) pass
// their suffix through and are listed apart.
const sites = []
const wrappers = []
for (const { rel, text } of SRC) {
	for (const m of text.matchAll(CALL)) {
		if (/function\s+$/.test(text.slice(Math.max(0, m.index - 20), m.index))) continue // the definition
		const { args, end } = callArgs(text, m.index + m[0].length - 1)
		const site = { rel, at: m.index, end, builder: m[1], args, method: enclosingMethod(text, m.index) }
		site.where = `${rel}:${lineOf(text, m.index)} ${m[1]}(${args.join(', ')})`
		if ((m[1] === 'roomApi' || m[1] === 'publicApi') && args[1] === 'suffix') wrappers.push(site)
		else sites.push(site)
	}
}

// The axios calls the URL of a site goes out with, as [{verb, key}]; empty
// when it is not a request. key = file@offset of the axios call.
const AXIOS_VERB = '(get|post|put|delete)'
function requests(site) {
	const { text } = SRC.find((f) => f.rel === site.rel)
	const from = Math.max(0, site.at - 300)
	const before = text.slice(from, site.at)
	let m = before.match(new RegExp('axios\\.' + AXIOS_VERB + '\\(\\s*$'))
	if (m) return [{ verb: m[1], key: site.rel + '@' + (from + m.index) }]
	const found = (re, files, start = 0) => files.flatMap((f) => [...f.text.slice(start).matchAll(re)]
		.map((u) => ({ verb: u[1], key: f.rel + '@' + (start + u.index) })))
	m = before.match(/\bconst (\w+) = $/)
	if (m) return found(new RegExp('axios\\.' + AXIOS_VERB + '\\(\\s*' + m[1] + '\\s*[,)]', 'g'), [{ rel: site.rel, text }], site.at)
	if (/\breturn\s+(?:[^\n;?]*\?\s*)?$/.test(before) && site.method) {
		return found(new RegExp('axios\\.' + AXIOS_VERB + '\\(\\s*this\\.' + site.method + '\\(', 'g'), SRC)
	}
	return []
}

// Resolve every site: its paths, the axios calls it feeds, and a route per
// path and verb (null = none in routes.php).
const resolved = sites.map((site) => {
	const out = { ...site, requests: requests(site), routes: [], error: null }
	try {
		const spec = BUILDERS[site.builder]
		// Nothing changes the URL outside the builder: in front only the host
		// or origin of an absolute link, behind only a query string.
		const { text } = SRC.find((f) => f.rel === site.rel)
		const before = text.slice(Math.max(0, site.at - 80), site.at)
		if (/\+\s*$/.test(before)) assert.match(before, /window\.location\.(host|origin)\s*\+\s*$/, 'something in front of the URL')
		if (/^\s*\+/.test(text.slice(site.end))) assert.match(text.slice(site.end), /^\s*\+\s*'\?/, 'something behind the URL that is not a query')
		if (spec.code) assert.ok(site.args.length >= 1 && !/['"`+]/.test(site.args[0]), 'the first argument is the room code: ' + site.args[0])
		else if (spec.suffix) assert.ok(site.args.length <= 1, 'one suffix at most')
		else assert.equal(site.args.length, 0, 'no arguments')
		const paths = spec.paths(site.args, OPERANDS[site.rel] || {})
		const verbs = out.requests.length ? [...new Set(out.requests.map((r) => r.verb.toUpperCase()))] : ['GET']
		for (const path of paths) {
			for (const verb of verbs) out.routes.push({ path, verb, route: routeOf(path, '/index.php', verb) || null })
		}
	} catch (e) {
		out.error = e.message
	}
	return out
})

test('call sites: the scan finds every builder of routes.js in use', () => {
	assert.ok(sites.length > 40, 'only ' + sites.length + ' sites')
	const used = new Set(sites.map((s) => s.builder))
	assert.deepEqual(Object.keys(BUILDERS).filter((b) => !used.has(b)), [])
})

test('call sites: api()/base() are the wrappers of roomApi()/publicApi() and pass the suffix through', () => {
	assert.deepEqual(wrappers.map((w) => `${w.rel} ${w.method}() -> ${w.builder}`).sort(), [
		'src/Moderator.vue api() -> roomApi',
		'src/Participant.vue base() -> publicApi',
		'src/Screen.vue base() -> publicApi',
	])
	// this.base() in mixins/pace-phone.js is Participant's
	for (const s of sites.filter((x) => x.builder === 'this.api')) assert.equal(s.rel, 'src/Moderator.vue', s.where)
	for (const s of sites.filter((x) => x.builder === 'this.base')) assert.ok(['src/Participant.vue', 'src/Screen.vue', 'src/mixins/pace-phone.js'].includes(s.rel), s.where)
})

test('call sites: OPERANDS hold the values the source gives them', () => {
	const mod = SRC.find((f) => f.rel === 'src/Moderator.vue').text
	assert.ok(mod.includes("const verb = lock ? 'lock' : 'unlock'"), 'setLock()')
	const phases = [...mod.matchAll(/this\.loadPhase\(('[^']*')/g)].map((m) => literal(m[1]))
	assert.deepEqual(phases.sort(), [...OPERANDS['src/Moderator.vue'].endpoint].sort())
})

test('call sites: every path with its verb resolves in routes.php (requests: an API route, links: a GET route)', () => {
	const wrong = []
	for (const s of resolved) {
		if (s.error) wrong.push(s.where + ': ' + s.error)
		for (const r of s.routes) {
			if (!r.route) wrong.push(`${s.where}: no ${r.verb} route for ${r.path}`)
			else if (s.requests.length && !API.has(r.route.controller)) wrong.push(`${s.where}: axios ${r.verb} to the page ${r.route.url}`)
		}
	}
	assert.deepEqual(wrong, [])
})

test('call sites: the public bundle reaches only public routes', () => {
	for (const f of ['src/Participant.vue', 'src/Screen.vue', 'src/mixins/pace-phone.js']) assert.ok(PUBLIC_FILES.has(f), f)
	for (const f of ['src/Moderator.vue', 'src/components/PaceRun.vue', 'src/util/csv.js', 'src/mixins/progress-poll.js']) {
		assert.ok(MAIN_FILES.has(f) && !PUBLIC_FILES.has(f), f)
	}
	const wrong = []
	for (const s of resolved.filter((x) => PUBLIC_FILES.has(x.rel))) {
		for (const r of s.routes) if (r.route && !PUBLIC.has(r.route.controller)) wrong.push(`${s.where}: ${r.verb} ${r.route.url} (${r.route.name})`)
	}
	assert.deepEqual(wrong, [])
})

test('call sites: every axios call under src/ takes a URL from routes.js', () => {
	const claimed = new Set(resolved.flatMap((s) => s.requests.map((r) => r.key)))
	const unclaimed = []
	for (const { rel, text } of SRC) {
		for (const m of text.matchAll(/\baxios\s*(?:\.\s*\w+\s*)?\(/g)) {
			if (!claimed.has(rel + '@' + m.index)) unclaimed.push(`${rel}:${lineOf(text, m.index)} ${text.slice(m.index, m.index + 60).split('\n')[0]}`)
		}
	}
	assert.deepEqual(unclaimed, [])
	assert.ok(claimed.size > 30, 'only ' + claimed.size + ' axios calls')
})

test('call sites: the join-code check in Participant.go() is GET /s/{code}/state (brute-force throttle)', () => {
	const checks = resolved.filter((s) => s.rel === 'src/Participant.vue' && s.method === 'go' && s.requests.length)
	assert.deepEqual(checks.flatMap((s) => s.routes.map((r) => `${r.verb} ${r.route && r.route.url}`)), ['GET /s/{code}/state'])
})

test('time limits: only the two constants, PHONE_TIMEOUT in the public bundle, MOD_TIMEOUT elsewhere', () => {
	const seen = new Set()
	const wrong = []
	for (const { rel, text } of SRC) {
		const want = PUBLIC_FILES.has(rel) ? 'PHONE_TIMEOUT' : 'MOD_TIMEOUT'
		for (const m of text.matchAll(/\btimeout\s*:\s*([^,}\s]+)/g)) {
			seen.add(m[1])
			if (m[1] !== want) wrong.push(`${rel}:${lineOf(text, m.index)} timeout: ${m[1]} (want ${want})`)
		}
	}
	assert.deepEqual(wrong, [])
	assert.deepEqual([...seen].sort(), ['MOD_TIMEOUT', 'PHONE_TIMEOUT'])
})

console.log(`\n==== routes.js: ${passed} ok / ${failures.length} failed ====`)
process.exit(failures.length ? 1 : 0)
