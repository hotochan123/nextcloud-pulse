/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import Vue from 'vue'
import { t, n } from './util/l10n.js'
// Design-system foundation: load tokens + primitives globally (un-scoped),
// so they reach the moderator and every scoped component style.
import './styles/pulse-tokens.css'
import './styles/pulse-ds.css'
import Moderator from './Moderator.vue'

// Translations globally: t/n are available in template and script (app id
// "pulse"). Nextcloud loads the language file l10n/<lang>.js before this bundle.
Vue.prototype.t = t
Vue.prototype.n = n

// eslint-disable-next-line no-new
new Vue({
	el: '#pulse-app',
	render: (h) => h(Moderator),
})
