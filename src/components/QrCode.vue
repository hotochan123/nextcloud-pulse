<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- eslint-disable-next-line vue/no-v-html (SVG string from the QR lib, not user input) -->
	<div class="qr" v-html="svg" />
</template>

<script>
import qrcode from 'qrcode-generator'

export default {
	name: 'QrCode',
	props: {
		value: { type: String, required: true },
	},
	computed: {
		svg() {
			if (!this.value) return ''
			const qr = qrcode(0, 'M') // 0 = automatic version, M = medium error correction
			qr.addData(this.value)
			qr.make()
			// scalable: no fixed width/height -> scalable via CSS; margin 4 = quiet zone.
			return qr.createSvgTag({ scalable: true, margin: 4 })
		},
	},
}
</script>

<style scoped>
.qr { width: 100%; line-height: 0; }
.qr :deep(svg) { width: 100%; height: auto; display: block; }
/* The lib renders <rect fill="white"> as the background and <path fill="black"> as the
   modules. Do NOT give both the same colour, or the modules disappear. */
/* Deliberately FIXED white/dark (not theme-dependent) — otherwise not scannable on a dark NC theme. */
.qr :deep(rect) { fill: #fff; }
.qr :deep(path) { fill: #141225; }
</style>
