<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<svg class="pulse-icon"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		stroke-width="2"
		stroke-linecap="round"
		stroke-linejoin="round"
		:style="{ width: dim, height: dim }"
		:role="label ? 'img' : undefined"
		:aria-label="label || undefined"
		:aria-hidden="label ? undefined : 'true'"
		focusable="false"
		v-html="inner" />
</template>

<script>
/*
 * PulseIcon — EIN durchgängiges SVG-Stroke-Set (Material-nah), ersetzt den
 * Emoji/Unicode-Mix (✓/🎉/★/🥇/⛶/👁). stroke:currentColor -> erbt die
 * Textfarbe des Kontexts. Größe via `size` (em bevorzugt, damit es mitskaliert).
 * Bedeutungstragende Icons bekommen ein `label` (role=img); dekorative sind
 * aria-hidden.
 */

// Innerer SVG-Inhalt je Name (viewBox 0 0 24 24, Stroke-Icons).
const ICONS = {
	check: '<polyline points="4 12 10 18 20 6"/>',
	close: '<line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/>',
	eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
	fullscreen: '<path d="M4 9V4h5"/><path d="M20 9V4h-5"/><path d="M4 15v5h5"/><path d="M20 15v5h-5"/>',
	timer: '<circle cx="12" cy="13" r="8"/><path d="M12 13V9"/><path d="M9 2h6"/>',
	beamer: '<rect x="2" y="6" width="20" height="11" rx="2"/><path d="M8 21h8"/>',
	poll: '<line x1="6" y1="20" x2="6" y2="12"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="18" y1="20" x2="18" y2="9"/>',
	quiz: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.8.4-1 .9-1 1.7"/><line x1="12" y1="17" x2="12" y2="17"/>',
	ranking: '<path d="M8 21V9h8v12"/><path d="M4 21V13h4"/><path d="M16 21V5h4v16"/>',
	chevron: '<polyline points="9 6 15 12 9 18"/>',
	drag: '<circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/>',
	trash: '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/>',
	edit: '<path d="M4 20h4L18.5 9.5a2.12 2.12 0 0 0-3-3L5 17v3z"/><path d="M13.5 6.5l3 3"/>',
	'arrow-up': '<line x1="12" y1="19" x2="12" y2="5"/><polyline points="6 11 12 5 18 11"/>',
	'arrow-down': '<line x1="12" y1="5" x2="12" y2="19"/><polyline points="6 13 12 19 18 13"/>',
	'arrow-right': '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="13 6 19 12 13 18"/>',
	warning: '<path d="M12 3 2 20h20L12 3z"/><line x1="12" y1="10" x2="12" y2="14"/><line x1="12" y1="17.5" x2="12" y2="17.5"/>',
	play: '<polygon points="7 4 20 12 7 20 7 4"/>',
	reset: '<path d="M3 12a9 9 0 1 0 3-6.7"/><polyline points="3 3 3 8 8 8"/>',
	check_ring: '<circle cx="12" cy="12" r="9"/><polyline points="8 12 11 15 16 9"/>',
	plus: '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
	copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
	// Schloss + Überlaufmenü (§9): „Nur für dich" trägt das Schloss NEBEN dem
	// Wort — das Symbol allein trägt die Aussage nicht.
	lock: '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
	more: '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
	// Quiz im eigenen Tempo: „wird geprüft", Frist, „fertig", Teilnehmende, CSV.
	hourglass: '<path d="M6 3h12"/><path d="M6 21h12"/><path d="M8 3v3l4 6 4-6V3"/><path d="M8 21v-3l4-6 4 6v3"/>',
	calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="M8 3v4"/><path d="M16 3v4"/>',
	flag: '<path d="M5 21V4"/><path d="M5 4h12l-2.5 4.5L17 13H5"/>',
	users: '<circle cx="9" cy="8" r="3.5"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8"/><path d="M18 14.6A6 6 0 0 1 21 20"/>',
	download: '<line x1="12" y1="4" x2="12" y2="15"/><polyline points="7 10 12 15 17 10"/><path d="M5 20h14"/>',
}

export default {
	name: 'PulseIcon',
	props: {
		name: { type: String, required: true },
		size: { type: [Number, String], default: '1.25em' },
		label: { type: String, default: '' },
	},
	computed: {
		inner() {
			if (!ICONS[this.name] && this.name && process.env.NODE_ENV !== 'production') {
				// Nur im Dev auffällig, nicht crashen (im Prod-Bundle wegoptimiert).
				// eslint-disable-next-line no-console
				console.warn('[PulseIcon] unbekanntes Icon:', this.name)
			}
			return ICONS[this.name] || ''
		},
		dim() {
			return typeof this.size === 'number' ? this.size + 'px' : this.size
		},
	},
}
</script>

<style scoped>
.pulse-icon {
	display: inline-block;
	vertical-align: -0.15em;
	flex: 0 0 auto;
}
</style>
