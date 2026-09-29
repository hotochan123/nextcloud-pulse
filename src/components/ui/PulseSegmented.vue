<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pseg"
		:class="{ 'is-disabled': disabled }"
		role="radiogroup"
		:aria-label="label || undefined"
		:aria-disabled="disabled ? 'true' : undefined"
		@keydown="onKey">
		<button v-for="(opt, i) in options"
			:key="String(opt.value)"
			ref="items"
			type="button"
			role="radio"
			:aria-checked="value === opt.value ? 'true' : 'false'"
			:tabindex="tabIndexFor(opt, i)"
			:class="['pseg-item', { 'is-active': value === opt.value }]"
			:disabled="disabled"
			@click="select(opt.value)">
			<PulseIcon v-if="opt.icon" :name="opt.icon" size="1.1em" />
			<span>{{ opt.label }}</span>
		</button>
	</div>
</template>

<script>
/*
 * PulseSegmented — Segmented-Control für Modus-/Typwahl (Handoff §4.5).
 * v-model (Vue2: prop `value` + Event `input`).
 * options: [{ value, label, icon? }].
 * disabled: die ganze Gruppe steht fest (z. B. „Rückmeldung" im Probelauf) —
 *   Knöpfe disabled, keine Pfeiltasten-Wahl, sichtbar gedämpft; die Wahl
 *   bleibt lesbar.
 *
 * §4.5-Falle: bei Umbruch KEIN Voll-Pill-Radius am Container (runde Enden
 * schieben äußere Buttons über die Box) -> moderater Radius + row-gap.
 *
 * §9.5: aktive Seite gefüllt, inaktive mit Rand und vollem Textkontrast — die
 * blasse inaktive Seite las sich vorher wie „deaktiviert", also gar nicht wie
 * eine Wahl. Rolle ist deshalb radiogroup/radio (nicht tablist): es wird
 * gewählt, nicht geblättert, und Pfeiltasten wechseln.
 */
import PulseIcon from './PulseIcon.vue'

export default {
	name: 'PulseSegmented',
	components: { PulseIcon },
	props: {
		value: { type: [String, Number, Boolean], default: null },
		options: { type: Array, required: true },
		label: { type: String, default: '' },
		disabled: { type: Boolean, default: false },
	},
	methods: {
		select(v) {
			if (this.disabled) return
			if (v !== this.value) this.$emit('input', v)
		},
		// Radiogruppe: genau EIN Segment liegt im Tab-Fluss (§9.5). Ohne das
		// müsste man sich durch alle Segmente tabben, um weiterzukommen.
		tabIndexFor(opt, i) {
			const active = this.options.findIndex((o) => o.value === this.value)
			return (active < 0 ? i === 0 : opt.value === this.value) ? 0 : -1
		},
		// Pfeiltasten wechseln die Wahl und nehmen den Fokus mit — so verhält
		// sich eine Radiogruppe, und die Wahl ist ohne Maus erreichbar.
		onKey(e) {
			const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[e.key]
			if (this.disabled || !step || !this.options.length) return
			e.preventDefault()
			const at = this.options.findIndex((o) => o.value === this.value)
			const next = ((at < 0 ? 0 : at + step) + this.options.length) % this.options.length
			this.select(this.options[next].value)
			this.$nextTick(() => {
				const el = (this.$refs.items || [])[next]
				if (el) el.focus()
			})
		},
	},
}
</script>

<style scoped>
.pseg {
	display: inline-flex;
	flex-wrap: wrap;
	gap: 4px;
	row-gap: 4px;
	padding: 4px;
	border: 1px solid var(--pulse-border);
	border-radius: 12px; /* moderat, NICHT --pulse-r-pill (Wrap-Falle §4.5) */
	background: var(--pulse-fill);
}
.pseg-item {
	font-family: inherit;
	font-size: var(--t-sm);
	font-weight: 600;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	min-height: 44px;
	padding: 0 14px;
	/* Die inaktive Seite trug --pulse-text-2 auf --pulse-fill (3,1:1) und las
	   sich wie „deaktiviert" statt wie „wählbar" (§9.5). Jetzt: voller
	   Textkontrast plus eigener Rand, damit sie als Wahl erkennbar ist. */
	border: 1px solid var(--pulse-border-strong);
	border-radius: 9px;
	background: transparent;
	color: var(--pulse-text);
	cursor: pointer;
	-webkit-appearance: none;
	appearance: none;
	transition: background .12s, color .12s;
}
.pseg-item.is-active {
	background: var(--pulse-primary);
	border-color: var(--pulse-primary);
	color: var(--pulse-on-primary);
	box-shadow: var(--pulse-shadow-card);
}
.pseg-item:focus-visible { outline: 2px solid var(--pulse-primary); outline-offset: 2px; }
/* Feste Wahl: gedämpft, aber lesbar — die aktive Seite bleibt erkennbar. */
.pseg.is-disabled { opacity: .55; }
/* Nextcloud färbt jeden deaktivierten <button> grau ein und halbiert die
   Deckkraft (core: button:disabled) — hier trägt die Gruppe die Dämpfung,
   die Segmente behalten ihre Optik. */
.pseg.is-disabled .pseg-item:disabled {
	opacity: 1;
	cursor: default;
	background: transparent;
	border-color: var(--pulse-border-strong);
	color: var(--pulse-text);
}
.pseg.is-disabled .pseg-item.is-active:disabled {
	background: var(--pulse-primary);
	border-color: var(--pulse-primary);
	color: var(--pulse-on-primary);
}

@media (prefers-reduced-motion: reduce) {
	.pseg-item { transition: none; }
}
</style>
