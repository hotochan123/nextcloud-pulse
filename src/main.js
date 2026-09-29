/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import Vue from 'vue'
import { t, n } from './util/l10n.js'
// Design-System-Fundament: Tokens + Primitive global laden (un-scoped),
// damit sie den Moderator und alle scoped Component-Styles erreichen.
import './styles/pulse-tokens.css'
import './styles/pulse-ds.css'
import Moderator from './Moderator.vue'

Vue.prototype.OC = window.OC
// Übersetzungen global: im Template und Skript steht t/n bereit (App-Kürzel
// "pulse"). Die Sprachdatei l10n/<lang>.js hängt Nextcloud vor dieses Bundle.
Vue.prototype.t = t
Vue.prototype.n = n

// eslint-disable-next-line no-new
new Vue({
	el: '#pulse-app',
	render: (h) => h(Moderator),
})
