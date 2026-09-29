<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pulse-menu" @keydown="onKey">
		<button ref="trigger"
			class="pulse-btn is-secondary is-icon"
			:class="small ? 'is-sm' : ''"
			type="button"
			aria-haspopup="menu"
			:aria-expanded="open ? 'true' : 'false'"
			:title="label"
			:aria-label="label"
			@click="toggle">
			<PulseIcon name="more" size="1.2em" />
		</button>
		<ul v-if="open" ref="list" class="pulse-menu-list" role="menu" :aria-label="label">
			<li v-for="(item, i) in items" :key="item.key" role="none">
				<hr v-if="i === firstDanger && i > 0" class="pulse-menu-sep">
				<button ref="entries"
					class="pulse-menu-item"
					:class="{ 'is-danger': item.danger, 'is-checkable': item.checked !== undefined, 'has-hint': !!item.hint }"
					:data-key="item.key"
					type="button"
					:role="item.checked === undefined ? 'menuitem' : 'menuitemcheckbox'"
					:aria-checked="item.checked === undefined ? undefined : (item.checked ? 'true' : 'false')"
					:disabled="!!item.disabled"
					tabindex="-1"
					@click="run(item)">
					<PulseIcon v-if="item.checked === undefined" :name="item.icon" size="1.05em" />
					<PulseIcon v-else-if="item.checked" name="check" size="1.05em" class="pulse-menu-tick" />
					<span v-else class="pulse-menu-tick" aria-hidden="true" />
					<span v-if="item.hint" class="pulse-menu-body">
						<span class="pulse-menu-txt">{{ item.label }}</span>
						<span class="pulse-menu-hint">{{ item.hint }}</span>
					</span>
					<span v-else class="pulse-menu-txt">{{ item.label }}</span>
				</button>
			</li>
		</ul>
	</div>
</template>

<script>
/*
 * PulseMenu — the overflow menu (overview design notes §3.1, rule R2; not in the public repository).
 *
 * The presentation view had it as a one-off in Moderator.vue since stage 5;
 * with the deck header, the deck row and the room card it would have become
 * four copies.
 *
 * items: [{ key, label, icon, act, danger?, checked?, disabled?, hint? }]
 *   checked set (even false) -> the entry is a TOGGLE: fixed text,
 *   the tick carries the state (R3). Otherwise the label changes with the state,
 *   and nobody knows whether it says what is, or what will happen.
 *   danger -> to the end, with a separator before it (R4).
 *   hint -> second line below the label: WHY an entry is currently off
 *   ("Close the quiz first."). Only the icon and label are dimmed then, the
 *   reason stays fully readable — otherwise the explanation would be just as faint as
 *   what it explains.
 *
 * Keyboard: arrows move, Home/End jump, Escape closes and returns the
 * focus to the trigger — without that you end up at the top of the page
 * after closing.
 */
import PulseIcon from './PulseIcon.vue'

export default {
	name: 'PulseMenu',
	components: { PulseIcon },
	props: {
		items: { type: Array, required: true },
		label: { type: String, default: '' },
		// Header rows with the small button size (presentation view, deck row).
		small: { type: Boolean, default: false },
	},
	data() {
		return { open: false }
	},
	computed: {
		// Separator exactly once, before the first red entry (R4).
		firstDanger() {
			return this.items.findIndex((it) => it.danger)
		},
	},
	beforeDestroy() {
		document.removeEventListener('click', this.onDocClick, true)
	},
	methods: {
		toggle() {
			this.open ? this.close() : this.show()
		},
		show() {
			this.open = true
			// Capture phase: the click that opens another menu reliably closes
			// this one before anything happens there.
			document.addEventListener('click', this.onDocClick, true)
			this.$nextTick(() => this.focusAt(0))
		},
		close(refocus = false) {
			if (!this.open) return
			this.open = false
			document.removeEventListener('click', this.onDocClick, true)
			if (refocus && this.$refs.trigger) this.$refs.trigger.focus()
		},
		// Callable from outside: after a deck row is moved, the focus moves along
		// to the menu of the moved question.
		focusTrigger() {
			if (this.$refs.trigger) this.$refs.trigger.focus()
		},
		onDocClick(e) {
			if (!this.$el.contains(e.target)) this.close()
		},
		run(item) {
			if (item.disabled) return
			// Toggles keep the menu open: you rarely set exactly one
			// setting and want to see the result right away.
			const stay = item.checked !== undefined
			if (!stay) this.close()
			item.act()
			if (stay) this.$nextTick(() => this.focusKey(item.key))
		},
		entryEls() {
			return (this.$refs.entries || []).filter((el) => el && !el.disabled)
		},
		focusAt(i) {
			const els = this.entryEls()
			if (els.length) els[(i + els.length) % els.length].focus()
		},
		focusKey(key) {
			const el = this.entryEls().find((e) => e.dataset.key === key)
			if (el) el.focus()
		},
		onKey(e) {
			if (e.key === 'Escape') {
				if (!this.open) return
				e.stopPropagation() // otherwise Moderator.vue would also close the join panel
				this.close(true)
				return
			}
			if (!this.open) {
				if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') { /* the click handles it */ }
				return
			}
			const els = this.entryEls()
			if (!els.length) return
			const at = els.indexOf(document.activeElement)
			const step = { ArrowDown: 1, ArrowUp: -1 }[e.key]
			if (step) { e.preventDefault(); this.focusAt((at < 0 ? 0 : at + step)) } else if (e.key === 'Home') { e.preventDefault(); this.focusAt(0) } else if (e.key === 'End') { e.preventDefault(); this.focusAt(els.length - 1) }
		},
	},
}
</script>

<style scoped>
.pulse-menu { position: relative; display: inline-flex; }
.pulse-menu-list {
	position: absolute;
	z-index: 30;
	inset-block-start: calc(100% + 6px);
	inset-inline-end: 0;
	min-width: 240px;
	margin: 0;
	padding: 6px;
	list-style: none;
	background: var(--pulse-bg);
	border: 1px solid var(--pulse-border-strong);
	border-radius: var(--pulse-r-el);
	box-shadow: var(--pulse-shadow-pop);
}
.pulse-menu-item {
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
	min-height: 40px;
	padding: 0 10px;
	border: 0;
	border-radius: var(--pulse-r-el);
	background: transparent;
	color: var(--pulse-text);
	font: inherit;
	font-size: var(--t-sm);
	font-weight: 600;
	text-align: start;
	cursor: pointer;
}
.pulse-menu-item:hover:not(:disabled) { background: var(--pulse-hover); }
.pulse-menu-item:disabled { color: var(--pulse-text-2); opacity: .5; cursor: default; }
.pulse-menu-item.is-danger { color: var(--pulse-error); }
.pulse-menu-item:focus-visible { outline: 2px solid var(--pulse-primary); outline-offset: -2px; }
/* The tick takes up its space even when unset — otherwise the
   label shifts on toggling, and the toggle feels like a jump. */
.pulse-menu-tick { width: 1.05em; height: 1.05em; flex: 0 0 auto; }
.pulse-menu-txt { flex: 1; min-width: 0; }
/* Entry with a reason: two lines, only icon/tick + label dimmed. */
.pulse-menu-item.has-hint { padding-block: 6px; }
.pulse-menu-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.pulse-menu-hint { font-size: var(--t-sm); font-weight: 400; color: var(--pulse-text-2); line-height: 1.35; }
.pulse-menu-item.has-hint:disabled { opacity: 1; background: transparent; }
.pulse-menu-item.has-hint:disabled > .pulse-icon,
.pulse-menu-item.has-hint:disabled > .pulse-menu-tick,
.pulse-menu-item.has-hint:disabled .pulse-menu-txt { opacity: .5; }
.pulse-menu-sep { margin: 6px 4px; border: 0; border-block-start: 1px solid var(--pulse-border); }
</style>
