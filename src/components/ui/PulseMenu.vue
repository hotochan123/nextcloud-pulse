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
 * PulseMenu — das Überlaufmenü (Handoff „Übersichten" §3.1, Regel R2).
 *
 * Die Präsentationsansicht hatte es seit Etappe 5 als Einzelstück in
 * Moderator.vue; mit dem Deck-Kopf, der Deck-Zeile und der Raumkarte wären es
 * vier Abschriften geworden.
 *
 * items: [{ key, label, icon, act, danger?, checked?, disabled?, hint? }]
 *   checked gesetzt (auch false) -> der Eintrag ist ein SCHALTER: fester Text,
 *   Häkchen trägt den Zustand (R3). Sonst wandert das Label mit dem Zustand,
 *   und niemand weiß, ob dort steht, was ist, oder was passiert.
 *   danger -> ans Ende, mit Trennlinie davor (R4).
 *   hint -> zweite Zeile unter dem Label: WARUM ein Eintrag gerade aus ist
 *   („Close the quiz first."). Gedämpft werden dann nur Icon und Label, der
 *   Grund bleibt voll lesbar — sonst wäre die Erklärung genauso blass wie
 *   das, was sie erklärt.
 *
 * Tastatur: Pfeile wandern, Home/End springen, Escape schließt und gibt den
 * Fokus an den Auslöser zurück — ohne das landet man nach dem Schließen am
 * Anfang der Seite.
 */
import PulseIcon from './PulseIcon.vue'

export default {
	name: 'PulseMenu',
	components: { PulseIcon },
	props: {
		items: { type: Array, required: true },
		label: { type: String, default: '' },
		// Kopfzeilen mit kleiner Knopfgröße (Präsentationsansicht, Deck-Zeile).
		small: { type: Boolean, default: false },
	},
	data() {
		return { open: false }
	},
	computed: {
		// Trennlinie genau einmal, vor dem ersten roten Eintrag (R4).
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
			// Capture-Phase: der Klick, der ein anderes Menü öffnet, schließt
			// dieses hier zuverlässig, bevor dort etwas passiert.
			document.addEventListener('click', this.onDocClick, true)
			this.$nextTick(() => this.focusAt(0))
		},
		close(refocus = false) {
			if (!this.open) return
			this.open = false
			document.removeEventListener('click', this.onDocClick, true)
			if (refocus && this.$refs.trigger) this.$refs.trigger.focus()
		},
		// Von außen aufrufbar: nach dem Verschieben einer Deck-Zeile wandert der
		// Fokus an das Menü der verschobenen Frage mit.
		focusTrigger() {
			if (this.$refs.trigger) this.$refs.trigger.focus()
		},
		onDocClick(e) {
			if (!this.$el.contains(e.target)) this.close()
		},
		run(item) {
			if (item.disabled) return
			// Schalter halten das Menü offen: man stellt selten genau eine
			// Einstellung und will das Ergebnis sofort sehen.
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
				e.stopPropagation() // sonst schließt Moderator.vue zusätzlich das Beitritts-Panel
				this.close(true)
				return
			}
			if (!this.open) {
				if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') { /* Klick erledigt das */ }
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
/* Das Häkchen belegt seinen Platz auch ungesetzt — sonst rutscht die
   Beschriftung beim Umschalten, und der Schalter wirkt wie ein Sprung. */
.pulse-menu-tick { width: 1.05em; height: 1.05em; flex: 0 0 auto; }
.pulse-menu-txt { flex: 1; min-width: 0; }
/* Eintrag mit Begründung: zweizeilig, gedämpft nur Icon/Häkchen + Label. */
.pulse-menu-item.has-hint { padding-block: 6px; }
.pulse-menu-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.pulse-menu-hint { font-size: var(--t-sm); font-weight: 400; color: var(--pulse-text-2); line-height: 1.35; }
.pulse-menu-item.has-hint:disabled { opacity: 1; background: transparent; }
.pulse-menu-item.has-hint:disabled > .pulse-icon,
.pulse-menu-item.has-hint:disabled > .pulse-menu-tick,
.pulse-menu-item.has-hint:disabled .pulse-menu-txt { opacity: .5; }
.pulse-menu-sep { margin: 6px 4px; border: 0; border-block-start: 1px solid var(--pulse-border); }
</style>
