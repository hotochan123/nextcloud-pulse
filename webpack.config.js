/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

// Drei Einstiegspunkte: main = Moderator, public = Teilnehmer, styles = nur
// Token-Schicht + Design-System für Seiten ohne Vue (die Einbett-Shell).
// webpack-vue-config prefixt den App-Namen -> js/pulse-main.js, js/pulse-public.js
webpackConfig.entry = {
	main: { import: path.join(__dirname, 'src', 'main.js') },
	public: { import: path.join(__dirname, 'src', 'public.js') },
	styles: { import: path.join(__dirname, 'src', 'styles.js') },
}

// clean:true (aus dem NC-Config) leert js/ vor jedem Build und würde die
// handgeschriebene js/pulse-embed.js (kein Webpack-Entry) mitreißen -> behalten.
webpackConfig.output.clean = { keep: (asset) => asset.includes('pulse-embed') }

module.exports = webpackConfig
