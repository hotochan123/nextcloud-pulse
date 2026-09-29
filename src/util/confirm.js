/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * pulseConfirm — Promise-basierter Ersatz für window.confirm().
 * Montiert PulseConfirm imperativ an <body> und räumt danach auf.
 *
 *   if (!await pulseConfirm({ title: 'Raum löschen?', text: '…', danger: true,
 *                            confirmLabel: 'Endgültig löschen' })) return
 *
 * Mit altLabel gibt es einen dritten Ausgang: die Promise liefert dann 'alt'.
 */
import Vue from 'vue'
import PulseConfirm from '../components/ui/PulseConfirm.vue'

/**
 * @param {object} [options] title, text, confirmLabel, cancelLabel, danger, altLabel
 * @return {Promise<boolean|string>} true = bestätigt, false = abgebrochen,
 *   'alt' = dritter Knopf (nur mit altLabel)
 */
export function pulseConfirm(options = {}) {
	return new Promise((resolve) => {
		const mount = document.createElement('div')
		document.body.appendChild(mount)

		let settled = false
		const finish = (result) => {
			if (settled) return
			settled = true
			// $mount hat `mount` durch vm.$el ersetzt -> vm.$el aus dem DOM nehmen.
			const el = vm.$el
			vm.$destroy()
			if (el && el.parentNode) el.parentNode.removeChild(el)
			resolve(result)
		}

		const vm = new Vue({
			render: (h) => h(PulseConfirm, {
				props: { ...options },
				on: {
					confirm: () => finish(true),
					cancel: () => finish(false),
					alt: () => finish('alt'),
				},
			}),
		}).$mount(mount)
	})
}
