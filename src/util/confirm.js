/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * pulseConfirm — promise-based replacement for window.confirm().
 * Mounts PulseConfirm imperatively on <body> and cleans up afterwards.
 *
 *   if (!await pulseConfirm({ title: 'Delete room?', text: '…', danger: true,
 *                            confirmLabel: 'Delete permanently' })) return
 *
 * With altLabel there is a third outcome: the promise then resolves to 'alt'.
 */
import Vue from 'vue'
import PulseConfirm from '../components/ui/PulseConfirm.vue'

/**
 * @param {object} [options] title, text, confirmLabel, cancelLabel, danger, altLabel
 * @return {Promise<boolean|string>} true = confirmed, false = cancelled,
 *   'alt' = third button (only with altLabel)
 */
export function pulseConfirm(options = {}) {
	return new Promise((resolve) => {
		const mount = document.createElement('div')
		document.body.appendChild(mount)

		let settled = false
		const finish = (result) => {
			if (settled) return
			settled = true
			// $mount replaced `mount` with vm.$el -> take vm.$el out of the DOM.
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
