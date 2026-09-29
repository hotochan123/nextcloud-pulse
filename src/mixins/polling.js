/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Adaptive Polling-Schleife — rekursives setTimeout statt festem Intervall:
 * flott während etwas läuft, gedrosselt im Leerlauf, pausiert bei verstecktem
 * Tab (onPollVisibility weckt auf). Geteilt von Participant (Handy), Screen
 * (Beamer) und Moderator (Steuerpult); vorher dreimal fast wortgleich kopiert.
 *
 * Der View liefert die zwei variablen Teile als Methoden:
 *   · pollDelay(): number|null — Wartezeit bis zum nächsten Abruf in ms
 *                                (null = Tab versteckt, Schleife ruht)
 *   · pollOnce(): Promise      — genau EIN Abruf (setzt Daten, wirft selbst nicht)
 * Optional pollActive(): boolean (Default true) — zusätzliches Gate; der
 * Moderator pollt nur in bestimmten Phasen (this.polling).
 * Optional pollSingleFlight(): boolean (Default false) — höchstens EINE
 * Anfrage unterwegs. Ohne das startet ein Tab, der zurückkommt, während ein
 * Abruf noch hängt, eine zweite Kette neben der ersten (beide planen danach
 * weiter). Moderiert bleibt es aus: dort hat der Abruf kein Timeout, und
 * genau dieser Neustart holt eine hängende Schleife zurück.
 *
 * Dauerhaftes Aus (Raum weg -> 404, oder View zerstört): pollStopped = true.
 * Wichtig, weil ein weiterhämmernder Tab sonst die pulseRoomCode-Bruteforce-
 * Drossel triggert (429 „Zu viele Anfragen").
 */
export default {
	data() {
		return {
			pollTimer: null,    // aktueller setTimeout-Handle
			pollStopped: false, // dauerhaft aus (Raum weg / zerstört)
			idleStreak: 0,      // „unverändert" (204) in Folge -> Drosselung
			pollBusy: false,    // ein Abruf unterwegs (nur einflugig)
			pollAgain: false,   // währenddessen gewünschter Sofort-Abruf
		}
	},
	methods: {
		// Default-Gate; Views mit Phasen (Moderator) überschreiben das.
		pollActive() {
			return true
		},
		pollScheduleNext() {
			if (this.pollStopped || !this.pollActive()) return
			const d = this.pollDelay()
			if (d === null) return // Tab versteckt: onPollVisibility weckt auf
			this.pollTimer = setTimeout(this.pollTick, d)
		},
		// Default aus; Handy und Beamer schalten es im eigenen Tempo ein.
		pollSingleFlight() {
			return false
		},
		pollTick() {
			if (!this.pollSingleFlight()) {
				this.pollOnce().finally(this.pollScheduleNext)
				return
			}
			// Einflugig: läuft schon ein Abruf, nur den Wunsch merken — die
			// laufende Kette holt sofort nach, sobald sie zurück ist.
			if (this.pollBusy) {
				this.pollAgain = true
				return
			}
			this.pollClear()
			this.pollBusy = true
			this.pollOnce().finally(() => {
				this.pollBusy = false
				if (this.pollAgain) {
					this.pollAgain = false
					if (!this.pollStopped && this.pollActive() && this.pollDelay() !== null) {
						this.pollTick()
						return
					}
				}
				this.pollScheduleNext()
			})
		},
		onPollVisibility() {
			if (this.pollStopped || !this.pollActive()) return
			if (!document.hidden) {
				// Zurück im Vordergrund -> sofort nachziehen.
				if (this.pollTimer) clearTimeout(this.pollTimer)
				this.pollTick()
			}
		},
		pollClear() {
			if (this.pollTimer) {
				clearTimeout(this.pollTimer)
				this.pollTimer = null
			}
		},
	},
}
