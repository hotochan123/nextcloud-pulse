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
 * Projector preview (specification §1.7): the real projector page in an iframe,
 * scaled down into a 16:9 box. The same mechanism as the
 * canvas preview of the presentation (Moderator.vue fitFrame/observeCanvas),
 * which stays unchanged.
 *
 * No replica: two sources of truth would drift apart. The projector page computes
 * in vw/vh and needs its real kiosk size (1280×720) — scaling is done via
 * transform, not via the window size, otherwise the iframe would hold a different
 * layout than in the auditorium, and the preview would prove nothing.
 *
 * Test bench: the iframe polls /s/{code}/state?spectate=1. Before deleting
 * a room, first leave the page that shows it (brute force, §6.4).
 *
 * Section references (§…) point to the specification of the self-paced quiz,
 * which is not in the public repository (see "References in code comments" in
 * the README).
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
		// Deliberately outside data(): Vue should not wrap the observer in
		// a reactive proxy.
		this.ro = null
	},
	mounted() {
		// The box also changes its width without a window event (column
		// below instead of beside) — the ResizeObserver measures when that happens.
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
		// Scale 1280×720 -> box; 2 px for the border, otherwise the
		// box would overflow the column by exactly those 2 px.
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
