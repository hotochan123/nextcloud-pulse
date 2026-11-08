#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Unit test for src/components/TextGrading.vue: renders the component (Vue 2,
// no browser, no mount) for each place that uses it and checks which parts a
// row shows. Grading views (desk summary, self-paced run) show the ✓/✗ buttons
// and no status badge; the read-only results view shows only the badge; the
// live desk of a moderated room adds the hide/show controls.
//
//   node dev/unit/text-grading.test.mjs      # exit 0 = all green
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const APP = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const require = createRequire(import.meta.url)
const Vue = require('vue/dist/vue.runtime.common.js')
const compiler = require('vue-template-compiler')

Vue.config.productionTip = false
Vue.config.devtools = false
Vue.prototype.t = (app, s, vars) => (vars ? s.replace(/\{(\w+)\}/g, (_, k) => vars[k]) : s)
Vue.prototype.n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', count)

// The component's own script, with its two child components as named stubs.
const sfc = compiler.parseComponent(readFileSync(join(APP, 'src/components/TextGrading.vue'), 'utf8'))
const script = sfc.script.content
	.replace(/^import .*$/gm, '')
	.replace('export default', 'return')
// eslint-disable-next-line no-new-func
const options = new Function('PulseIcon', 'ModerationControls', script)(
	{ name: 'PulseIcon', render: (h) => h('i') },
	{ name: 'ModerationControls', render: (h) => h('span') },
)
const { render, staticRenderFns, errors } = compiler.compileToFunctions(sfc.template.content)
assert.deepEqual(errors || [], [], 'template compiles')
const TextGrading = Vue.extend({ ...options, render, staticRenderFns })

const RESULTS = {
	accepted: ['Jupiter'],
	total: 6,
	answers: [
		{ norm: 'jupiter', sample: 'Jupiter', count: 3, status: 'accepted', mod: 'visible' },
		{ norm: 'saturn', sample: 'Saturn', count: 2, status: 'rejected', mod: 'hidden' },
		{ norm: 'jupiterr', sample: 'jupiterr', count: 1, status: 'open', mod: 'held' },
	],
}

// One summary per row: the classes of its direct children, component stubs by name.
function rows(propsData) {
	const vm = new TextGrading({ propsData: { results: RESULTS, ...propsData } })
	const out = []
	const walk = (v) => {
		if (!v) return
		if (v.tag === 'li') {
			out.push((v.children || []).filter((c) => c.tag).map((c) => {
				if (c.componentOptions) return c.componentOptions.Ctor.options.name
				const cls = [v.data && v.data.staticClass, c.data && c.data.staticClass].filter(Boolean)
				return cls[1] || c.tag
			}))
			return
		}
		;(v.children || []).forEach(walk)
	}
	walk(vm._render())
	return out
}

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

const GRADING = ['tg-count', 'tg-text', 'tg-actions']
const READONLY = ['tg-count', 'tg-text', 'tg-status']

test('desk summary (Moderator.vue, no moderatable): buttons, no badge', () => {
	assert.deepEqual(rows({}), [GRADING, GRADING, GRADING])
})

test('self-paced grading (PaceRun.vue, keyless + busy): buttons, no badge', () => {
	assert.deepEqual(rows({ keyless: true, busy: true }), [GRADING, GRADING, GRADING])
})

test('read-only results view (ResultsView.vue): badge, no buttons', () => {
	assert.deepEqual(rows({ readonly: true }), [READONLY, READONLY, READONLY])
})

test('live desk of a moderated room: buttons and hide/show controls, no badge', () => {
	const row = [...GRADING, 'ModerationControls']
	assert.deepEqual(rows({ moderatable: true }), [row, row, row])
})

test('a row without a moderation state gets no controls on the live desk', () => {
	const vm = new TextGrading({ propsData: { moderatable: true, results: { ...RESULTS, answers: [{ ...RESULTS.answers[0], mod: undefined }] } } })
	const li = vm._render().children.find((c) => c.tag === 'ul').children[0]
	assert.ok(!li.children.some((c) => c.componentOptions && c.componentOptions.Ctor.options.name === 'ModerationControls'))
	assert.ok(!li.children.some((c) => c.data && c.data.staticClass === 'tg-status'))
})

console.log(`\n==== TextGrading.vue: ${passed} ok / ${failures.length} failed ====`)
process.exit(failures.length ? 1 : 0)
