/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

// Three entry points: main = moderator, public = participant, styles = only the
// token layer + design system for pages without Vue (the embed shell).
// webpack-vue-config prefixes the app name -> js/pulse-main.js, js/pulse-public.js
webpackConfig.entry = {
	main: { import: path.join(__dirname, 'src', 'main.js') },
	public: { import: path.join(__dirname, 'src', 'public.js') },
	styles: { import: path.join(__dirname, 'src', 'styles.js') },
}

// clean:true (from the NC config) empties js/ before every build and would take the
// hand-written js/pulse-embed.js (not a webpack entry) with it -> keep it.
webpackConfig.output.clean = { keep: (asset) => asset.includes('pulse-embed') }

module.exports = webpackConfig
