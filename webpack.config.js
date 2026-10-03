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

// css-loader takes its sourceMap default from devtool, which the NC config also sets
// for production -> every <style> block landed in the bundle a second time, as an
// inline source map with the whole .vue file in it. JS keeps its separate .map files.
// Should a new NC config write css-loader differently, stop instead of quietly
// shipping the maps again.
let cssLoaders = 0
for (const rule of webpackConfig.module.rules) {
	if (Array.isArray(rule.use)) {
		rule.use = rule.use.map((loader) => {
			if (loader !== 'css-loader') {
				return loader
			}
			cssLoaders++
			return { loader, options: { sourceMap: false } }
		})
	}
}
if (cssLoaders === 0) {
	throw new Error('webpack.config.js: no css-loader found to switch its source maps off')
}

module.exports = webpackConfig
