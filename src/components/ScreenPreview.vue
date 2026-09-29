<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div ref="box" class="spv">
		<div class="spv-in" :style="boxStyle">
			<iframe class="spv-frame" :style="frameStyle" :src="src" :title="title" />
		</div>
	</div>
</template>

<script>
/*
 * Beamer-Vorschau (Spezifikation §1.7): die echte Beamer-Seite im iframe,
 * verkleinert in einen 16:9-Kasten. Dieselbe Mechanik wie die
 * Leinwand-Vorschau der Präsentation (Moderator.vue fitFrame/observeCanvas),
 * die unverändert bleibt.
 *
 * Kein Nachbau: zwei Wahrheiten liefen auseinander. Die Beamer-Seite rechnet
 * in vw/vh und braucht ihren echten Kiosk (1280×720) — verkleinert wird per
 * transform, nicht per Fenstergröße, sonst läge im iframe ein anderes Layout
 * als im Saal, und die Vorschau bewiese nichts.
 *
 * Prüfstand: das iframe pollt /s/{code}/state?spectate=1. Vor dem Löschen
 * eines Raums erst weg von der Seite, die es zeigt (Brute-Force, §6.4).
 */
const W = 1280
const H = 720

export default {
	name: 'ScreenPreview',
	props: {
		src: { type: String, required: true },
		title: { type: String, default: '' },
	},
	data() {
		return { scale: 0.25 }
	},
	computed: {
		boxStyle() {
			return { width: Math.round(W * this.scale) + 'px', height: Math.round(H * this.scale) + 'px' }
		},
		frameStyle() {
			return { transform: 'scale(' + this.scale + ')' }
		},
	},
	created() {
		// Absichtlich außerhalb von data(): Vue soll den Beobachter nicht in
		// einen reaktiven Proxy hüllen.
		this.ro = null
	},
	mounted() {
		// Der Kasten ändert seine Breite auch ohne Fenster-Ereignis (Spalte
		// darunter statt daneben) — der ResizeObserver misst, wenn es so weit ist.
		if (window.ResizeObserver) {
			this.ro = new window.ResizeObserver(() => this.fit())
			this.ro.observe(this.$refs.box)
		} else {
			window.addEventListener('resize', this.fit)
		}
		this.fit()
	},
	beforeDestroy() {
		if (this.ro) this.ro.disconnect()
		window.removeEventListener('resize', this.fit)
	},
	methods: {
		// Maßstab 1280×720 -> Kasten; 2 px für den Rahmen, sonst liefe der
		// Kasten um genau diese 2 px über die Spalte.
		fit() {
			const box = this.$refs.box
			if (!box) return
			const k = Math.min((box.clientWidth - 2) / W, (box.clientHeight - 2) / H)
			if (k > 0.05 && Math.abs(k - this.scale) > 0.002) this.scale = k
		},
	},
}
</script>

<style scoped>
.spv { width: 100%; min-width: 0; aspect-ratio: 16 / 9; display: grid; place-items: center; }
.spv-in { position: relative; box-sizing: content-box; overflow: hidden; border: 1px solid var(--pulse-border-strong); border-radius: var(--pulse-r-card); background: var(--pulse-screen-bg); }
.spv-frame { width: 1280px; height: 720px; border: 0; transform-origin: top left; display: block; }
</style>
