/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import Vue from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { t, n } from './util/l10n.js'
import AdminSettings from './components/AdminSettings.vue'

// Admin section "Pulse" in the administration settings (lib/Settings/Admin.php).
Vue.prototype.t = t
Vue.prototype.n = n

const initial = loadState('pulse', 'admin', { enabled: true, dailyLimit: 30, dailyMax: 1000 })

// eslint-disable-next-line no-new
new Vue({
	el: '#pulse-admin',
	render: (h) => h(AdminSettings, { props: { initial } }),
})
