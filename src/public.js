/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import Vue from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { t, n } from './util/l10n.js'
// Design-System-Fundament (auch auf Handy + Beamer): Tokens + Primitive global.
import './styles/pulse-tokens.css'
import './styles/pulse-ds.css'
import Participant from './Participant.vue'
import Screen from './Screen.vue'

Vue.prototype.OC = window.OC
// Übersetzungen global: im Template und Skript steht t/n bereit (App-Kürzel
// "pulse"). Auf der öffentlichen Seite kommt die Sprache aus dem Browser
// (Accept-Language), nicht aus einem Konto.
Vue.prototype.t = t
Vue.prototype.n = n

// Dasselbe Bundle für zwei öffentliche Seiten: /s/{code} = Teilnehmer (abstimmen),
// /screen/{code} = Beamer-/Publikumsansicht (nur zeigen). Der Controller setzt das Flag.
const isScreen = loadState('pulse', 'screen', false)

// eslint-disable-next-line no-new
new Vue({
	el: '#pulse-public-app',
	render: (h) => h(isScreen ? Screen : Participant),
})
