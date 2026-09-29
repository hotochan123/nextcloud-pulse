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
 * PulseSegmented — segmented control for mode/type choice (design notes §4.5, not in the public repository).
 * v-model (Vue2: prop `value` + event `input`).
 * options: [{ value, label, icon? }].
 * disabled: the whole group is fixed (e.g. the feedback choice in a practice run) —
 *   buttons disabled, no arrow-key selection, visibly dimmed; the choice
 *   stays readable.
 *
 * §4.5 trap: when wrapping, NO full pill radius on the container (round ends
 * push the outer buttons over the box) -> moderate radius + row-gap.
 *
 * §9.5: active side filled, inactive with a border and full text contrast — the
 * faint inactive side used to read as "disabled", i.e. not as
 * a choice at all. That is why the role is radiogroup/radio (not tablist): you
 * choose, you do not page, and arrow keys switch.
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
		// Radio group: exactly ONE segment is in the tab order (§9.5). Without that
		// you would have to tab through every segment to get past it.
		tabIndexFor(opt, i) {
			const active = this.options.findIndex((o) => o.value === this.value)
			return (active < 0 ? i === 0 : opt.value === this.value) ? 0 : -1
		},
		// Arrow keys change the choice and take the focus along — that is how
		// a radio group behaves, and the choice can be reached without a mouse.
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
	border-radius: 12px; /* moderate, NOT --pulse-r-pill (wrap trap §4.5) */
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
	/* The inactive side had --pulse-text-2 on --pulse-fill (3.1:1) and read
	   as "disabled" instead of "selectable" (§9.5). Now: full
	   text contrast plus its own border, so it is recognisable as a choice. */
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
/* Fixed choice: dimmed, but readable — the active side stays recognisable. */
.pseg.is-disabled { opacity: .55; }
/* Nextcloud greys out every disabled <button> and halves its
   opacity (core: button:disabled) — here the group carries the dimming,
   the segments keep their look. */
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
