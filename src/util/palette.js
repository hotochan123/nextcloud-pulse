/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Kanonische Antwort-Palette A–H — die EINE Quelle für Optionsfarben.
 * Ersetzt das hartkodierte OPT_COLORS-Array (bisher Screen.vue) und reicht die
 * Farbe von der Live-Kachel bis in den Ergebnis-Balken durch: `cls` auf das
 * Options-Element setzen -> Buchstaben-Badge und Balken-Fill erben --opt-fill
 * /--opt-ink (siehe pulse-ds.css, Klassen .pulse-opt-*).
 *
 * Die Farbwerte selbst leben theme-unabhängig in pulse-tokens.css (--opt-*).
 */

const KEYS = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']

/**
 * @param {number} index 0-basierter Optionsindex
 * @return {{letter:string, cls:string, isInk:boolean}}
 */
export function option(index) {
	const i = ((index % KEYS.length) + KEYS.length) % KEYS.length
	const key = KEYS[i]
	return {
		letter: String.fromCharCode(65 + i), // A … H
		cls: 'pulse-opt-' + key,             // setzt --opt-fill/--opt-ink
		isInk: key === 'd',                  // Amber: dunkler Text auf heller Kachel
	}
}

/**
 * Liste (Optionen/Ergebniszeilen) mit stabiler Palette-Zuordnung je Index —
 * derselbe Index -> dieselbe Farbe auf Handy, Beamer und im Ergebnis-Balken.
 * @param {Array} list Optionen bzw. Ergebniszeilen (null/undefined -> [])
 * @return {Array} dieselben Objekte, jeweils um `pal` (siehe option()) ergänzt
 */
export function withPalette(list) {
	return (list || []).map((item, i) => ({ ...item, pal: option(i) }))
}

/*
 * Helligkeitsstaffel für GEORDNETE Kategorien (§7.0): Plätze, Ränge, Stufen.
 * Die Palette A–H behauptet Gleichrang und ist hier falsch — die Staffel
 * entsteht aus einem Ton, nicht aus acht neuen Farben.
 *
 * @param {number} k 1-basierte Stufe (1 = dunkelste)
 * @param {number} n Anzahl Stufen
 * @return {{fill:string, ink:string}} CSS-Werte für --seg-fill/--seg-ink
 */
export function rampStep(k, n) {
	const lift = n > 1 ? ((k - 1) / (n - 1)) * 70 : 0
	return {
		fill: `color-mix(in oklab, var(--pulse-primary), var(--pulse-screen-bg) ${Math.round(lift)}%)`,
		// Ab der halben Aufhellung trägt der helle Kontrastpartner nicht mehr;
		// gemessen, nicht geschätzt (§3.1/§7.0).
		ink: lift >= 50 ? 'var(--pulse-text)' : 'var(--pulse-on-primary)',
	}
}
