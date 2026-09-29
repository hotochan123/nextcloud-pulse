<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- eslint-disable-next-line vue/no-v-html (SVG-String der QR-Lib, kein Nutzer-Input) -->
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
			const qr = qrcode(0, 'M') // 0 = Version automatisch, M = mittlere Fehlerkorrektur
			qr.addData(this.value)
			qr.make()
			// scalable: kein festes width/height -> per CSS skalierbar; margin 4 = Quiet-Zone.
			return qr.createSvgTag({ scalable: true, margin: 4 })
		},
	},
}
</script>

<style scoped>
.qr { width: 100%; line-height: 0; }
.qr >>> svg { width: 100%; height: auto; display: block; }
/* Die Lib rendert <rect fill="white"> als Hintergrund und <path fill="black"> als
   Module. NICHT beide gleich einfärben, sonst verschwinden die Module. */
/* Bewusst FIX weiß/dunkel (nicht theme-abhängig) — sonst auf dunklem NC-Theme nicht scanbar. */
.qr >>> rect { fill: #fff; }
.qr >>> path { fill: #141225; }
</style>
