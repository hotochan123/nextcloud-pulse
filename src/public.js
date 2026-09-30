/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import Vue from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { t, n } from './util/l10n.js'
// Design-system foundation (on the phone + projector too): tokens + primitives, global.
import './styles/pulse-tokens.css'
import './styles/pulse-ds.css'
import Participant from './Participant.vue'
import Screen from './Screen.vue'

// Translations global: t/n are available in template and script (app ID
// "pulse"). On the public page the language comes from the browser
// (Accept-Language), not from an account.
Vue.prototype.t = t
Vue.prototype.n = n

// The same bundle for two public pages: /s/{code} = participant (voting),
// /screen/{code} = projector/audience view (display only). The controller sets the flag.
const isScreen = loadState('pulse', 'screen', false)

// eslint-disable-next-line no-new
new Vue({
	el: '#pulse-public-app',
	render: (h) => h(isScreen ? Screen : Participant),
})
