<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="scr" :class="{ 'is-live': isLive, 'is-race': raceView }" :data-image="hasImage ? '' : null">
		<!-- Raum unbekannt (§4.11) — gebrandet, klare Ansage -->
		<div v-if="!roomExists" class="scr-center">
			<p class="scr-eyebrow">Pulse</p>
			<h1>{{ t('pulse', 'This room does not exist.') }}</h1>
			<p class="scr-muted">{{ t('pulse', 'Check the code and open the projector link again.') }}</p>
		</div>

		<!-- Eigenes Tempo (§3): Rennen, geschlossen, Endstand — vor der Lobby.
		     Entwurf und „offen, aber noch niemand gestartet" fallen in die Lobby.
		     Nie Fragetext, Optionen oder Verteilungen (der Payload hat keine). -->
		<template v-else-if="raceView">
			<header v-if="raceHead" class="scr-head">
				<h1 class="scr-q">{{ raceHead }}</h1>
			</header>
			<main class="scr-stage" ref="stage" :style="stageStyle">
				<StageRace v-if="paceState === 'open'" :race="race" :board="raceBoard" :side="raceSide"
					:join-url="joinUrl" :join-url-full="joinUrlFull" :code="spacedCode" :tight="raceTight" />
				<!-- Freigegeben: derselbe Endstand wie am Ende eines moderierten Quiz. -->
				<div v-else-if="raceFinal" class="scr-final">
					<p class="scr-final-title">{{ t('pulse', 'Final standings') }}</p>
					<Leaderboard class="scr-final-lb" :rows="leaderboard" :podium="true" :limit="5" split />
				</div>
				<!-- Geschlossen: nur der Zähler — auch wenn der Server bei Rückmeldung je
				     Frage eine Rangliste mitschickt; die Lehrkraft hat beim Schließen
				     „verborgen bis zur Freigabe" bestätigt. -->
				<div v-else class="scr-race-note" role="status">
					<p class="scr-race-note-big">{{ raceNote[0] }}</p>
					<p class="scr-race-note-sub">{{ raceNote[1] }}</p>
				</div>
			</main>
			<div class="scr-meta">
				<div class="scr-meta-l">
					<span v-if="practice" class="scr-chip is-practice"><PulseIcon name="warning" size="1em" /> {{ t('pulse', 'Practice run') }}</span>
					<span class="scr-chip" :class="paceState === 'open' ? 'is-open' : 'is-done'">
						<span v-if="paceState === 'open'" class="scr-chip-dot" aria-hidden="true" />
						{{ raceChip }}
					</span>
					<span v-if="present > 0" class="scr-chip">{{ t('pulse', '{count} here', { count: present }) }}</span>
					<span v-if="!online" class="scr-chip is-off">{{ t('pulse', 'Connection lost …') }}</span>
				</div>
				<span class="scr-legend">{{ paceState === 'open' ? t('pulse', '100% = everyone who joined') : '' }}</span>
				<div v-if="paceState === 'open'" class="scr-join-mini" :title="joinUrl">
					<div class="scr-qr-mini"><QrCode :value="joinUrlFull" /></div>
					<span class="scr-pin-mini">{{ spacedCode }}</span>
				</div>
			</div>
		</template>

		<!-- Lobby: noch keine aktive Frage -->
		<div v-else-if="!poll" class="scr-lobby" :class="{ 'is-paced': isPaced }">
			<p class="scr-eyebrow">Pulse</p>
			<h1 class="scr-lobby-title">{{ lobbyTitle }}</h1>
			<p v-if="lobbySub" class="scr-lobby-sub">{{ lobbySub }}</p>
			<p v-if="practice" class="scr-practice"><PulseIcon name="warning" size="1em" /> {{ t('pulse', 'Practice run') }}</p>
			<div class="scr-join">
				<div class="scr-qr"><QrCode :value="joinUrlFull" /></div>
				<div class="scr-join-txt">
					<p class="scr-join-hint">{{ t('pulse', 'Join in — point your phone camera at the code, or:') }}</p>
					<p class="scr-pin">{{ spacedCode }}</p>
					<p class="scr-join-url">{{ joinUrl }}</p>
				</div>
			</div>
			<p v-if="present > 0" class="scr-momentum" role="status">
				<span class="scr-momentum-dot" aria-hidden="true" />
				<b>{{ present }}</b> {{ n('pulse', 'person is already here', 'people are already here', present) }}
			</p>
			<!-- Eigenes Tempo, offen mit Frist: in der Zeile des Zählers, damit die
			     Lobby auch im 1280×720-Kiosk nicht über den Rand wächst. -->
			<p v-if="lobbyWhen" class="scr-lobby-when"><PulseIcon name="calendar" size="1em" /> {{ lobbyWhen }}</p>
			<p v-if="!online" class="scr-conn" role="status">{{ t('pulse', 'Connection lost …') }}</p>
		</div>

		<!-- Aktive Frage: Kopf · Bühne · Meta-Leiste (§2.1) -->
		<template v-else>
			<!-- Am Quiz-Ende gehört die Leinwand dem Endstand: die Frage der letzten
			     Runde stünde sonst als zweite Überschrift darüber (B2). -->
			<header v-if="!quizEnded && !hasImage" class="scr-head">
				<h1 class="scr-q">{{ poll.question }}</h1>
				<!-- Countdown auf einer Achse mit der Frage, nicht als Streifen
				     darüber (§6.5) — wichtig, aber nicht die Frage. -->
				<CountdownRing v-if="showCountdown" class="scr-cd" :remaining="remaining" :total="poll.timeLimit || 0" />
			</header>

			<main class="scr-stage" ref="stage" :style="stageStyle" :data-image="hasImage ? '' : null">
				<!-- Bildfrage (§7.1): das Bild IST der Inhalt und bekommt eine eigene
				     Spalte; der Kopf wandert dafür in die rechte Spalte, damit Frage
				     und Ergebnis zusammen gelesen werden. Vorher hing das Bild mit
				     3 % der Fläche in der Kopfzeile und drückte die Frage aus der
				     Mitte. -->
				<template v-if="hasImage">
					<div class="scr-img-mat">
						<!-- Das Bild kommt nach dem ersten Paint an und ändert die Bühnenhöhe:
						     ohne diesen zweiten Durchlauf misst die Schrumpf-Schleife gegen
						     einen leeren Kasten (acht Zeilen liefen 32 px über). -->
						<img class="scr-img" :src="imageUrl(poll)" :alt="t('pulse', 'Image for the question')" @load="fitStage(true)">
					</div>
					<div class="scr-split-q">
						<h1 class="scr-q">{{ poll.question }}</h1>
						<CountdownRing v-if="showCountdown" class="scr-cd" :remaining="remaining" :total="poll.timeLimit || 0" />
					</div>
				</template>

				<!-- Endstand: eigene Bühne, zwei Spalten (B1) -->
				<div v-if="quizEnded" class="scr-final">
					<p class="scr-final-title">{{ t('pulse', 'Final standings') }}</p>
					<Leaderboard class="scr-final-lb" :rows="leaderboard" :podium="true" :limit="5" split />
				</div>

				<!-- Wortwolke: eigene Live-Engine (Spiral-Layout) über die volle Bühne.
				     Sie ist die Ausnahme von E1 und wächst auch offen weiter — das
				     Wachsen IST der Fragetyp. -->
				<div v-else-if="showResults && results.type === 'words'" class="scr-cloud">
					<WordCloud :words="results.results" :total="results.total" :demo="demo"
						:show-footer="false" :min-size="metaFs" @stats="cloudStats = $event" />
				</div>

				<!-- Aufgelöst, aber niemand hat geantwortet (§7.9): kein leeres
				     Diagramm. Die Bühne zeigt, worum es ging, und sagt es in Worten. -->
				<div v-else-if="emptyResult" class="scr-empty">
					<StageOpen v-if="!soloOpen" :poll="poll" />
					<p class="scr-empty-txt">{{ t('pulse', 'No answer received') }}</p>
				</div>

				<!-- Freitext am Beamer: Häufigkeits-Kacheln (12× groß … 1× klein) -->
				<div v-else-if="showResults && results.type === 'text'" class="scr-ftcards">
					<div class="scr-ft-head">
						<span v-if="acceptedList.length" class="pulse-chip is-success scr-ft-accept"><PulseIcon name="check" size="1em" /> {{ acceptedList.join(' · ') }}</span>
						<span class="scr-ft-sum">{{ textSummary }}</span>
					</div>
					<div v-if="textCards.length" class="scr-ft-cloud">
						<span v-for="a in textCards" :key="a.norm" class="scr-ft-card" :class="'is-' + a.status" :style="{ fontSize: a.em + 'em' }">
							<span class="scr-ft-txt">{{ a.sample }}</span>
							<span class="scr-ft-mult">{{ a.count }}×</span>
						</span>
					</div>
					<p v-else class="scr-ft-empty">{{ t('pulse', 'No answers yet.') }}</p>
				</div>

				<!-- Auflösung: Ergebnis-Grammatik (§2.4) -->
				<div v-else-if="showResults" class="scr-results" :class="{ 'scr-results--cols': resultCols }">
					<ResultsView :results="results" wide
						:correct-id="poll.correctOption || ''"
						:correct-ids="(poll.answerKey && poll.answerKey.correct) || []"
						:answer-key="poll.answerKey || null" />
				</div>

				<!-- Offene Bühne (§6.1): Antwortmöglichkeiten links, Eingang rechts.
				     Gibt es nichts zu zeigen — Schätzfrage, Freitext —, füllt der
				     Eingang die Bühne allein, statt wie bisher leer zu bleiben. -->
				<!-- Ab sechs Zeilen kippt die Aufteilung: die Antwortmöglichkeiten
				     bekommen die volle Breite, der Eingang wird zum flachen Streifen
				     darunter. Nebeneinander blieben 40 % der Leinwand für eine
				     dreistellige Zahl reserviert, während daneben acht Zeilen um
				     jeden Millimeter kämpften. -->
				<div v-else class="scr-open" :data-solo="soloOpen ? '' : null" :data-stack="stackOpen ? '' : null">
					<StageOpen v-if="!soloOpen" :poll="poll" />
					<IntakeBoard :key="poll.id" :answered="answered" :present="present"
						:frozen="timeUp || closedHidden" :compact="hasImage || stackOpen" />
				</div>
			</main>

			<!-- Meta-Leiste: EIN Ort für Nebensachen (§2.5). Der QR verschwindet,
			     sobald aufgelöst ist — dort tritt niemand mehr bei, und die Ecke
			     oben rechts gehört wieder dem Inhalt. -->
			<div class="scr-meta">
				<div class="scr-meta-l">
					<span v-if="practice" class="scr-chip is-practice"><PulseIcon name="warning" size="1em" /> {{ t('pulse', 'Practice run') }}</span>
					<span class="scr-chip" :class="revealed ? 'is-done' : 'is-open'">
						<span v-if="!revealed && !timeUp && !closedHidden" class="scr-chip-dot" aria-hidden="true" />
						{{ stateText }}
					</span>
					<span v-if="allAnswered" class="scr-chip is-full">{{ t('pulse', 'complete') }}</span>
					<span v-if="!online" class="scr-chip is-off">{{ t('pulse', 'Connection lost …') }}</span>
				</div>
				<span class="scr-legend">{{ legend }}</span>
				<div v-if="showQr" class="scr-join-mini" :title="joinUrl">
					<div class="scr-qr-mini"><QrCode :value="joinUrlFull" /></div>
					<span class="scr-pin-mini">{{ spacedCode }}</span>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { formatCode, remainingSecs, fmtDeadline } from './util/format.js'
import { windowState } from './util/pace.js'
import { reloadOnProtocolMismatch } from './util/protocol.js'
import pollingMixin from './mixins/polling.js'
import PulseIcon from './components/ui/PulseIcon.vue'
import ResultsView from './components/ResultsView.vue'
import CountdownRing from './components/CountdownRing.vue'
import IntakeBoard from './components/IntakeBoard.vue'
import StageOpen from './components/StageOpen.vue'
import Leaderboard from './components/Leaderboard.vue'
import WordCloud from './components/WordCloud.vue'
import QrCode from './components/QrCode.vue'
import StageRace from './components/StageRace.vue'

// Boden der Schrumpf-Schleife (§2.3): darunter ist die Bühne nur noch aus 5 m
// lesbar — dann liegt zu viel Inhalt darauf.
const FIT_FLOOR_PX = 24

export default {
	name: 'Screen',
	mixins: [pollingMixin],
	components: { PulseIcon, ResultsView, CountdownRing, IntakeBoard, StageOpen, Leaderboard, WordCloud, QrCode, StageRace },
	data() {
		return {
			code: loadState('pulse', 'code', ''),
			roomExists: loadState('pulse', 'roomExists', false),
			practice: false,
			roomTitle: '',   // Raumname aus publicState.room (leer = keiner)
			poll: null,
			results: null,
			cloudStats: null,
			stageFs: null,   // von fitStage() gesetzt, wenn geschrumpft werden muss
			metaFs: 23,      // gemessene Klasse C — Untergrenze der Wortwolke (§7.7)
			answered: 0,
			present: 0,
			leaderboard: null,
			online: true,
			version: '',
			serverSkew: 0,
			nowSec: Math.floor(Date.now() / 1000),
			tickTimer: null,
			// Eigenes Tempo (§3.1): aus /state?spectate=1 — moderiert leer/null.
			pace: '',
			paceWindow: null,
			race: null,
			// Enge Rennzeilen: fitPass setzt es, wenn das Rennen auf der
			// Schrumpf-Grenze nicht passt (kleiner Kiosk, Einbett-Rahmen).
			raceTight: false,
		}
	},
	computed: {
		// ── Eigenes Tempo (§3) ─────────────────────────────────────────
		// Moderiert hat /state weder room.pace noch window — jeder Zweig hier
		// hängt an isPaced, moderiert bleibt alles wie bisher.
		isPaced() {
			return this.pace === 'self'
		},
		// Eine lokal abgelaufene Frist gilt schon als geschlossen; der nächste
		// Abruf bestätigt es.
		paceState() {
			return this.isPaced ? windowState(this.paceWindow, this.nowSec + this.serverSkew) : ''
		},
		// Rennrahmen: geschlossen, freigegeben oder offen mit mindestens einem
		// Start. Entwurf und „offen ohne Starter" zeigen die Lobby (Beitritt).
		raceView() {
			if (!this.isPaced || !this.roomExists) return false
			const st = this.paceState
			return st === 'closed' || st === 'released' || (st === 'open' && !!this.race && this.race.started > 0)
		},
		// Spitze im offenen Rennen, nur mit Punkten: acht alphabetische „0"-Zeilen
		// zu Beginn wären Rauschen und stellten Nachzügler aus.
		raceBoard() {
			if (this.paceState !== 'open') return []
			return (this.leaderboard || []).filter((r) => r.score > 0)
		},
		// Rechte Spalte des Rennens: in den ersten zwei Minuten (und solange
		// niemand Punkte hat) der Beitritt, danach die Spitze. Hausaufgabe mit
		// „Auflösung am Ende" hat nie eine Rangliste — dort bleibt der Beitritt.
		raceSide() {
			if (!this.raceView || this.paceState !== 'open') return ''
			const openedAt = (this.paceWindow && this.paceWindow.openedAt) || 0
			if (this.nowSec + this.serverSkew - openedAt < 120 || !this.raceBoard.length) return 'join'
			return 'board'
		},
		// Freigegeben mit Rangliste: der bestehende Endstand-Block.
		raceFinal() {
			return this.paceState === 'released' && Array.isArray(this.leaderboard) && this.leaderboard.length > 0
		},
		// Kopf (Klasse A): offen der Zähler, geschlossen die Ansage, freigegeben
		// keiner — dort gehört die Leinwand dem Endstand.
		raceHead() {
			if (this.paceState === 'open') return this.finishedText
			if (this.paceState === 'closed') return this.t('pulse', 'The quiz is closed.')
			return ''
		},
		finishedText() {
			const r = this.race || {}
			return this.t('pulse', '{done} of {total} finished', { done: r.finished || 0, total: r.joined || 0 })
		},
		// Mittige Bühne ohne Balken: geschlossen, Probelauf freigegeben (keine
		// Rangliste), freigegeben ohne Teilnehmende.
		raceNote() {
			if (this.paceState === 'closed') return [this.finishedText, this.t('pulse', 'Results come once your host releases them.')]
			if (this.leaderboard === null) return [this.t('pulse', 'Quiz finished'), this.t('pulse', 'Thanks for joining in.')]
			return [this.t('pulse', 'Final standings'), this.t('pulse', 'No points yet — nobody has played along.')]
		},
		raceChip() {
			if (this.paceState === 'open') {
				const closesAt = (this.paceWindow && this.paceWindow.closesAt) || 0
				return closesAt > 0 ? this.t('pulse', 'Open until {time}', { time: fmtDeadline(closesAt) }) : this.t('pulse', 'Open now')
			}
			return this.paceState === 'closed' ? this.t('pulse', 'Quiz closed') : this.t('pulse', 'Quiz finished')
		},
		// Lobby-Texte: moderiert exakt die bisherigen. Im Entwurf nicht „Starting
		// shortly" — eine vorbereitete Hausaufgabe steht so tagelang am Flurbildschirm.
		lobbyTitle() {
			if (this.paceState === 'draft') return this.roomTitle || this.t('pulse', 'Not open yet')
			if (this.paceState === 'open') return this.roomTitle || this.t('pulse', 'The quiz is open')
			return this.roomTitle || this.t('pulse', 'Starting shortly')
		},
		lobbySub() {
			if (this.paceState === 'open') return this.t('pulse', 'Join and start at your own pace.')
			if (!this.roomTitle) return ''
			return this.paceState === 'draft' ? this.t('pulse', 'Not open yet') : this.t('pulse', 'Starting shortly')
		},
		// Offen mit Frist: absolut, wann Schluss ist.
		lobbyWhen() {
			const closesAt = (this.paceState === 'open' && this.paceWindow && this.paceWindow.closesAt) || 0
			return closesAt > 0 ? this.t('pulse', 'Open until {time}', { time: fmtDeadline(closesAt) }) : ''
		},
		isLive() {
			return this.roomExists && !!this.poll
		},
		// Wolken-Testmodus: Beamer-URL mit ?demo=1 -> WordCloud generiert lokal
		// eine lebende Wolke (kein Server), um die Optik ohne echte Teilnehmende
		// zu prüfen. Wirkt nur auf einer laufenden Wortwolken-Frage.
		demo() {
			return /(?:\?|&)demo=/.test(window.location.search)
		},
		// Aufgelöst = der Server hat die Frage freigegeben (poll.revealed). Die
		// Anzeige-Entscheidung E1 hängt allein daran; der Status genügt nicht,
		// weil 'locked' bei „Auflösung am Ende" noch verdeckt ist. Rückfall auf
		// den Status nur für eine Antwort ohne das Feld.
		revealed() {
			if (!this.poll) return false
			if (typeof this.poll.revealed === 'boolean') return this.poll.revealed
			return ['locked', 'ended'].includes(this.poll.status)
		},
		// Die offene Bühne hält das Ergebnis zurück (E1) — außer bei der
		// Wortwolke, die live weiterwächst (E2).
		showResults() {
			if (!this.results) return false
			return this.revealed || this.results.type === 'words'
		},
		// Bildfrage: zweispaltige Bühne, Kopf rechts (§7.1). Am Quiz-Ende gehört
		// die Leinwand dem Endstand, dann zählt das Bild nicht mehr.
		hasImage() {
			return !!this.poll && !!this.poll.image && !this.quizEnded
		},
		// Aufgelöst, aber ohne eine einzige Stimme. Die Wortwolke hat ihren
		// eigenen Leerzustand und ist ausgenommen.
		emptyResult() {
			return this.revealed && !!this.results && this.results.type !== 'words'
				&& !this.results.total
		},
		// Wortwolken-Zähler für die Meta-Leiste: nur bei aktiver Wortwolke mit
		// mindestens einem Wort.
		wordStats() {
			if (!this.results || this.results.type !== 'words') return null
			const s = this.cloudStats
			return s && s.words ? s : null
		},
		// Schätzfrage und Freitext haben keine Antwortmöglichkeiten — dort füllt
		// der Eingang die Bühne allein (§6.1). Genau diese beiden Bühnen waren
		// bisher leer bis auf ein Stift-Symbol (D6).
		soloOpen() {
			return !!this.poll && ['number', 'text'].includes(this.poll.type)
		},
		// Zeilen, die die offene Bühne zeigt — Optionen, Aspekte oder Paare.
		// Entscheidet über die Stapelung UND über den Schrumpf-Schlüssel: der
		// zählte nur poll.options, und die ist bei der Zuordnung leer. Der Wechsel
		// von vier Paaren auf acht ließ die Bühne deshalb auf der alten
		// Schriftgröße stehen, bis irgendwann ein Resize kam.
		openRows() {
			const p = this.poll
			if (!p) return 0
			if (p.type === 'match') return ((p.match && p.match.items) || []).length
			if (p.type === 'scale') return ((p.scale && p.scale.aspects) || []).length
			return (p.options || []).length
		},
		// Nur die Zuordnung stapelt. Sie ist der einzige Typ, der unter den
		// Zeilen noch etwas trägt (die Ziel-Chips) — deshalb wird ihr die halbe
		// Breite zuerst zu eng. Acht Optionen ohne Chips stehen nebeneinander
		// weiterhin gut; das ist in §11 abgenommen und bleibt unangetastet.
		stackOpen() {
			return !!this.poll && this.poll.type === 'match' && !this.hasImage && this.openRows > 5
		},
		// Gesperrt, aber nicht aufgelöst („Auflösung am Ende" nach dem Sperren):
		// keine Antworten mehr, aber auch noch kein Ergebnis.
		closedHidden() {
			return !!this.poll && !this.revealed && ['locked', 'ended'].includes(this.poll.status)
		},
		// Countdown gibt es nur im Quiz und nur, solange die Frage läuft.
		showCountdown() {
			return !this.quizEnded && !this.revealed && !this.closedHidden && this.remaining !== null
		},
		timeUp() {
			return this.remaining !== null && this.remaining <= 0 && !this.revealed
		},
		// „vollständig": alle Verbundenen haben geantwortet.
		allAnswered() {
			return !this.revealed && !this.quizEnded && this.present > 0 && this.answered >= this.present
		},
		// Zustands-Chip: der strukturelle Unterschied (Balken ja/nein) ist das
		// lauteste Signal, der Chip benennt ihn zusätzlich in Worten — Farbe ist
		// nie der einzige Träger.
		stateText() {
			if (this.quizEnded) {
				const count = Array.isArray(this.leaderboard) ? this.leaderboard.length : 0
				return this.t('pulse', 'Result') + ' · ' + this.n('pulse', '%n participant', '%n participants', count)
			}
			if (this.revealed) {
				const total = (this.results && this.results.total) || 0
				return this.t('pulse', 'Result') + ' · ' + this.n('pulse', '%n vote', '%n votes', total)
			}
			if (this.closedHidden) {
				return this.t('pulse', 'Answers closed') + ' · ' + this.answered + ' ' + this.n('pulse', 'answer', 'answers', this.answered)
			}
			if (this.timeUp) {
				return this.t('pulse', 'Time is up') + ' · ' + this.answered + ' ' + this.n('pulse', 'answer', 'answers', this.answered)
			}
			return this.t('pulse', 'Answers coming in') + ' · ' + this.answered + ' ' + this.n('pulse', 'answer', 'answers', this.answered)
		},
		// Erklärtext der Bühne — heute drei Orte (Fußnote links, Zähler rechts,
		// QR oben), künftig einer.
		legend() {
			const r = this.results
			// Ohne Stimmen erklärt die Legende eine Grafik, die gar nicht da ist.
			if (this.quizEnded || !this.showResults || !r || this.emptyResult) return ''
			switch (r.type) {
			case 'choice':
				return this.t('pulse', '100% = all votes')
			case 'multi':
				return this.t('pulse', '100% = everyone who answered · the sum can exceed 100%')
			case 'rank':
				// Die Zeile ist immer voll; erklärt werden muss deshalb das
				// Segment, nicht die Länge (§7.5).
				return this.t('pulse', 'Segment = share on this place · sorted by average place')
			case 'match':
				return (this.poll.answerKey && this.poll.answerKey.map)
					? this.t('pulse', 'Segment = share per target · ring marks the correct target')
					: this.t('pulse', 'Segment = share per target')
			case 'number':
				return this.t('pulse', 'Distribution of the guesses · bars relative to the most frequent value')
			case 'scale':
				if (r.mode === 'compass') return this.t('pulse', 'Centre = neutral')
				// Das Spektrum trägt seine Legende unter dem Radar (§7.3).
				if (r.mode === 'spectrum') return ''
				return this.t('pulse', 'Distribution · column height = votes per value · scale {min}–{max}', { min: r.min, max: r.max })
			case 'words': {
				const s = this.wordStats
				return s ? s.words + ' ' + this.n('pulse', 'word', 'words', s.words) + ' · ' + this.n('pulse', '%n mention', '%n mentions', s.votes) : ''
			}
			default:
				return ''
			}
		},
		// QR nur, solange jemand beitreten kann. Zustandslos: offen = ja.
		showQr() {
			return !this.revealed && !this.quizEnded
		},
		spacedCode() {
			return formatCode(this.code)
		},
		joinPath() {
			return generateUrl('/apps/pulse/s/' + this.code)
		},
		joinUrl() {
			return window.location.host + this.joinPath
		},
		joinUrlFull() {
			return window.location.origin + this.joinPath
		},
		// Quiz beendet = die laufende Frage ist 'ended' (das setzt „Quiz beenden";
		// eine einzeln aufgelöste Frage steht auf 'locked'). Ohne Rangliste —
		// Probelauf — bleibt es bei der gewohnten Auflösung.
		quizEnded() {
			return !!this.poll && this.poll.status === 'ended'
				&& Array.isArray(this.leaderboard) && this.leaderboard.length > 0
		},
		remaining() {
			return remainingSecs(this.poll, this.nowSec, this.serverSkew)
		},
		// Die Bühne rechnet vollständig in em; genau dieser eine px-Wert wird
		// geschrieben, alles andere folgt (§2.3).
		// Nur bei struktureller Änderung neu einpassen (Frage, Typ, Zeilenzahl) —
		// sonst spränge es bei jeder neuen Stimme kurz auf Basisgröße zurück.
		// Die Fassung (Text, Bild, Beschriftungen) gehört hinein: eine bearbeitete
		// laufende Frage mit längerem Text schnitte sonst die unteren Zeilen ab.
		fitKey() {
			// Eigenes Tempo: alles, was Zeilen oder Spalten ändert — die Zahlen IN
			// den Zeilen nicht (sonst spränge es bei jedem Abruf auf Basisgröße).
			// Freigegeben zählt die Länge des Endstands (Podium + Liste).
			if (this.raceView) {
				const r = this.race || {}
				const notStartedShown = this.paceState === 'open' && (r.joined || 0) > (r.started || 0)
				const board = this.paceState === 'released' ? (this.leaderboard || []).length : this.raceBoard.length
				return 'race:' + this.paceState + ':' + (r.n || 0) + ':' + board + ':' + this.raceSide + ':' + (notStartedShown ? 1 : 0)
					+ (this.raceTight ? ':t' : '')
			}
			if (!this.poll) return null
			const r = this.results
			const p = this.poll
			const labels = (list) => (list || []).map((o) => o.label).join('\u0001')
			const content = [p.question, p.image, labels(p.options), labels((p.match || {}).items),
				labels((p.match || {}).targets), JSON.stringify(p.scale || null)].join('\u0002')
			return p.id + ':' + content + ':' + (this.quizEnded
				? 'final:' + (this.leaderboard || []).length
				: (this.showResults && r)
					? r.type + ':' + (Array.isArray(r.results) ? r.results.length : 0)
					: 'open:' + this.poll.type + ':' + this.openRows)
		},
		stageStyle() {
			return this.stageFs ? { '--scr-stage-fs': this.stageFs + 'px' } : {}
		},
		// Verteilungen mit vielen Werten (Skala 1–10) laufen sonst unten raus; ab >6
		// Zeilen zweispaltig (5+5) -> Balken bleiben groß statt auf Mini-Font zu
		// schrumpfen. Der Fit bleibt als Netz für den Rest.
		resultCols() {
			return this.resultRows > 6
		},
		resultRows() {
			const r = this.results
			return r && Array.isArray(r.results) ? r.results.length : 0
		},
		// Freitext-Beamer (§5b): akzeptierte Schreibweisen als Chip-Liste.
		acceptedList() {
			return (this.results && this.results.accepted) || []
		},
		// „N Nennungen · M verschiedene" — dieselbe Grammatik wie §5a.
		textSummary() {
			if (!this.results) return ''
			const total = this.results.total || 0
			const distinct = (this.results.answers || []).length
			return this.n('pulse', '%n mention', '%n mentions', total) + ' · ' + this.t('pulse', '{count} different', { count: distinct })
		},
		// Häufigkeits-Kacheln: nach Anzahl absteigend, Größe ∝ Häufigkeit (em).
		textCards() {
			const rows = (this.results && this.results.answers) || []
			if (!rows.length) return []
			const max = Math.max.apply(null, rows.map((r) => r.count || 0)) || 1
			return rows.slice().sort((a, b) => (b.count || 0) - (a.count || 0)).map((a) => ({
				norm: a.norm, sample: a.sample, count: a.count, status: a.status || 'open',
				em: Math.round((1 + (a.count / max) * 1.4) * 100) / 100,
			}))
		},
	},
	watch: {
		// Neue/andere Bühne -> Größe neu einpassen (nächster Tick, DOM steht dann).
		// Am Schlüssel statt an den Ergebnissen: die bleiben im Quiz von der Lobby
		// bis zum Auflösen null, die erste Frage würde sonst nie eingepasst.
		fitKey() {
			this.fitStage()
		},
		revealed() {
			this.fitStage(true)
		},
	},
	mounted() {
		// Beamer = Vollbild-Kiosk: NC-Chrome ausblenden. Body-Klasse gatet die
		// globalen Overrides -> Handy (Participant) bleibt heil.
		document.body.classList.add('pulse-beamer')
		window.addEventListener('resize', this.fitStage)
		window.addEventListener('resize', this.measureMeta)
		this.measureMeta()
		// Web-Fonts laden nach dem ersten Paint nach; ohne diesen zweiten Pass
		// misst die Schleife gegen die Fallback-Schrift.
		if (document.fonts && document.fonts.ready) {
			document.fonts.ready.then(() => this.fitStage(true)).catch(() => {})
		}
		if (this.code && this.roomExists) {
			this.pollTick()
			this.tickTimer = setInterval(() => { this.nowSec = Math.floor(Date.now() / 1000) }, 500)
			document.addEventListener('visibilitychange', this.onPollVisibility)
		}
	},
	beforeDestroy() {
		this.pollStopped = true
		document.body.classList.remove('pulse-beamer')
		window.removeEventListener('resize', this.fitStage)
		window.removeEventListener('resize', this.measureMeta)
		this.pollClear()
		if (this.tickTimer) clearInterval(this.tickTimer)
		document.removeEventListener('visibilitychange', this.onPollVisibility)
	},
	methods: {
		base(suffix = '') {
			return generateUrl('/apps/pulse/s/' + this.code + suffix)
		},
		// Bild-URL der Frage (öffentliche Route, Dateiname als Cache-Buster).
		imageUrl(poll) {
			if (!poll || !poll.image) return ''
			return generateUrl('/apps/pulse/s/' + this.code + '/polls/' + poll.id + '/image') + '?v=' + encodeURIComponent(poll.image)
		},
		applyState(data) {
			// Anderes Server-Protokoll: altes Bundle aus dem Cache -> einmal neu laden.
			if (reloadOnProtocolMismatch(data.protocol)) return
			this.poll = data.poll
			this.results = data.results
			// Zähler zurücksetzen, sobald keine Wortwolke (mehr) läuft — sonst
			// blieben alte Zahlen bei einer anderen Fragenart in der Meta-Leiste.
			if (!data.results || data.results.type !== 'words') this.cloudStats = null
			if (data.room) this.roomTitle = data.room.title || ''
			this.answered = data.answered || 0
			this.present = data.present || 0
			if ('practice' in data) this.practice = !!data.practice
			this.leaderboard = data.leaderboard || null
			// Eigenes Tempo: moderiert fehlen alle drei Felder.
			this.pace = (data.room && data.room.pace) || ''
			this.paceWindow = data.window || null
			this.race = data.race || null
			this.serverSkew = (data.serverNow || Math.floor(Date.now() / 1000)) - Math.floor(Date.now() / 1000)
			if (data.version !== undefined) this.version = data.version
		},
		/*
		 * Schrumpf-Schleife (§2.3): setzt --scr-stage-fs zurück, misst die Bühne
		 * und verkleinert die em-Basis in 4-%-Schritten, bis der Inhalt passt.
		 * Alles auf der Bühne ist em von diesem einen Wert — deshalb genügt ein
		 * Schreibzugriff, und auch Podest und Rangliste folgen (das war B1: das
		 * Podium rechnete in px und ignorierte den Schrumpffaktor, weshalb die
		 * Plätze 4–8 am overflow:hidden abgeschnitten wurden).
		 */
		fitStage(force) {
			// Lobby: keine Bühne einzupassen. Den Schlüssel vergessen, damit die
			// nächste Frage neu eingepasst wird — auch wenn sie dieselbe Form hat
			// und das Fenster in der Lobby größer oder kleiner geworden ist.
			const key = this.fitKey
			if (key === null) {
				this._fitKey = null
				return
			}
			// Eigenes Tempo: ein Resize oder eine neue Form des Rennens versucht es
			// wieder mit luftigen Zeilen — eng wird es nur, wenn es anders nicht
			// passt (fitTight). Das Zurücksetzen ändert den Schlüssel, der Watcher
			// ruft gleich noch einmal; dieser Aufruf endet hier. Nur ein
			// Rennschlüssel hängt an raceTight: eine moderierte Frage danach (Raum
			// zurückgesetzt und umgeschaltet) passt sich in DIESEM Aufruf ein —
			// sonst bliebe die geschrumpfte Rennschrift bis zur Auflösung stehen.
			const shape = key.replace(/:t$/, '')
			if (this.raceTight && (force || shape !== this._raceShape)) {
				this.raceTight = false
				if (this.raceView) return
			}
			if (this.raceView) this._raceShape = shape
			// Resize ruft mit dem Event-Objekt als Argument -> force = truthy.
			this.measureMeta()
			if (!force && key === this._fitKey) return
			this._fitKey = key
			this.stageFs = null // erst auf den clamp()-Startwert zurück
			this.$nextTick(() => requestAnimationFrame(() => this.fitPass(0)))
		},
		// Klasse C am echten Element messen statt die clamp()-Formel im
		// JavaScript nachzubauen — die Untergrenze der Wortwolke ist genau die
		// Größe der Meta-Leiste (§7.7).
		measureMeta() {
			const el = this.$el && this.$el.querySelector ? this.$el.querySelector('.scr-meta') : null
			if (el) this.metaFs = Math.round(parseFloat(getComputedStyle(el).fontSize)) || 23
		},
		fitPass(iter) {
			const el = this.$refs.stage
			if (!el) return
			// Startwert ist der berechnete clamp()-Wert — beim ersten Durchlauf
			// steht kein Inline-Override mehr auf der Bühne.
			if (iter === 0) this._fitBase = parseFloat(getComputedStyle(el).fontSize) || 46
			if (el.scrollHeight <= el.clientHeight + 1) return
			if (iter >= 12) {
				// Zwölf Schritte à 4 % enden bei 28 px — der in §2.3 genannte Boden
				// von 24 px wäre so nie erreichbar. Ein letzter Sprung dorthin, dann
				// ist Schluss: was dann noch nicht passt, ist zu viel Inhalt für die
				// Bühne und gehört gekürzt, nicht weiter geschrumpft.
				if (this.stageFs !== FIT_FLOOR_PX) {
					this.stageFs = FIT_FLOOR_PX
					this.$nextTick(() => requestAnimationFrame(() => this.fitPass(iter + 1)))
				} else {
					this.fitTight()
				}
				return
			}
			const next = Math.max(FIT_FLOOR_PX, Math.round(this._fitBase * Math.pow(0.96, iter + 1)))
			if (this.stageFs !== null && next >= this.stageFs) { // Boden erreicht
				this.fitTight()
				return
			}
			this.stageFs = next
			this.$nextTick(() => requestAnimationFrame(() => this.fitPass(iter + 1)))
		},
		// Eigenes Tempo: passt das offene Rennen auch auf der Schrumpf-Grenze
		// nicht, rücken die Zeilen zusammen (StageRace `tight`). Der Schlüssel
		// ändert sich, der Watcher passt neu ein. Im Einbett-Rahmen (PowerPoint,
		// 1264×576) liefen acht luftige Zeilen sonst 55 px über.
		fitTight() {
			if (this.raceView && this.paceState === 'open' && !this.raceTight) this.raceTight = true
		},
		// Im eigenen Tempo einflugig (mixins/polling.js): jeder Abruf hat dort ein
		// Timeout; ein zurückkehrender Tab startete sonst eine zweite Kette.
		pollSingleFlight() {
			return this.isPaced
		},
		// Adaptives Polling wie beim Teilnehmer, aber als reiner Zuschauer (spectate).
		pollDelay() {
			if (document.hidden) return null
			if (this.poll && !this.revealed) return 1300 // Frage läuft -> flott (Countdown/Zähler)
			if (this.poll) return 2000 // Auflösung sichtbar -> ruhig aktualisieren
			// Eigenes Tempo offen: der Server hält den Beamer-Zustand in 2-s-Eimern.
			if (this.isPaced && this.paceState === 'open') return 2000
			return this.idleStreak >= 3 ? 5000 : 2500 // Lobby -> zurückfahren
		},
		async pollOnce() {
			try {
				const params = { spectate: 1 }
				if (this.version) params.v = this.version
				// Im eigenen Tempo mit Timeout: eine hängende Anfrage hielte sonst
				// die Schleife an, ohne dass „Connection lost" erschiene (moderiert
				// unverändert).
				const res = await axios.get(this.base('/state'), this.isPaced ? { params, timeout: 10000 } : { params })
				this.online = true
				if (res.status === 204) { // unverändert
					this.idleStreak++
					return
				}
				this.applyState(res.data)
				this.idleStreak = 0
			} catch (e) {
				if (e?.response?.status === 404) {
					// Raum weg -> dauerhaft aufhören zu pollen. Sonst fragt der Beamer-Tab
					// den Endpunkt alle paar Sekunden weiter ab; jedes „nicht gefunden"
					// zählt auf die Brute-Force-Aktion 'pulseRoomCode' und drosselt am
					// Ende die ganze IP (429 „Zu viele Anfragen").
					this.roomExists = false
					this.pollStopped = true
				} else {
					this.online = false
				}
			}
		},
	},
}
</script>

<style scoped>
/* ── Kiosk-Rahmen (§2.1) ───────────────────────────────────────────
   Vollflächig, kein Scrollen, kein NC-Chrome. Die Zeilen sind fest
   zugeteilt (grid-row), damit die Bühne auch dann 1fr behält, wenn Kopf
   oder Countdown fehlen. */
.scr {
	position: fixed;
	inset: 0;
	z-index: 1;
	display: grid;
	grid-template-rows: 1fr;
	overflow: hidden;
	box-sizing: border-box;
	padding: var(--scr-pad);
	background: var(--pulse-screen-bg);
	color: var(--pulse-text);
	font-family: var(--font-face, system-ui, -apple-system, sans-serif);
}
.scr.is-live, .scr.is-race { grid-template-rows: auto 1fr auto; }
.scr-head { grid-row: 1; }
.scr-stage { grid-row: 2; }
.scr-meta { grid-row: 3; }
/* Bildfrage: kein eigener Kopf — die Frage steht in der rechten Spalte der
   Bühne, damit Frage und Ergebnis zusammen gelesen werden (§7.1). */
.scr.is-live[data-image] { grid-template-rows: 1fr auto; }
.scr[data-image] .scr-stage { grid-row: 1; }
.scr[data-image] .scr-meta { grid-row: 2; }
.scr-muted { color: var(--pulse-text-2); }

/* ── Fehler ────────────────────────────────────────────────────── */
.scr-center { margin: auto; text-align: center; }
.scr-center h1 { font-size: clamp(28px, 4vw, 48px); margin: 0 0 8px; }

/* ── Lobby ─────────────────────────────────────────────────────── */
.scr-lobby { margin: auto; text-align: center; max-width: 1100px; }
.scr-eyebrow { color: var(--pulse-primary); letter-spacing: 0.2em; text-transform: uppercase; font-weight: 800; font-size: clamp(14px, 1.4vw, 20px); margin: 0 0 12px; }
.scr-lobby-title { font-size: clamp(36px, 6vw, 84px); margin: 0 0 32px; line-height: 1.05; }
.scr-join { display: flex; align-items: center; justify-content: center; gap: clamp(24px, 5vw, 72px); flex-wrap: wrap; }
/* QR-Karte: weißer Grund (Scanbarkeit) + theme-feste Umrandung, auch auf dunklem Beamer. */
.scr-qr { width: clamp(200px, 26vw, 380px); background: #fff; padding: 16px; border: 3px solid var(--pulse-border-strong); border-radius: var(--pulse-r-card); box-shadow: var(--pulse-shadow-pop); }
.scr-join-txt { text-align: left; }
.scr-join-hint { font-size: clamp(16px, 1.8vw, 24px); color: var(--pulse-text-2); margin: 0 0 12px; max-width: 12em; }
.scr-pin {
	display: inline-block; background: var(--pulse-primary); color: var(--pulse-on-primary);
	font-weight: 900; font-size: clamp(40px, 6vw, 84px); letter-spacing: 0.12em;
	padding: 8px 28px; border-radius: var(--pulse-r-card); margin: 0 0 12px; font-variant-numeric: tabular-nums;
}
.scr-join-url { font-size: clamp(15px, 1.5vw, 22px); color: var(--pulse-readout); font-weight: 700; margin: 0; word-break: break-all; }
.scr-lobby-sub { font-size: clamp(18px, 2.4vw, 30px); color: var(--pulse-text-2); margin: -4px 0 4px; }
/* Eigenes Tempo, offen mit Frist: wann Schluss ist (absolut) — als zweite
   Pille neben dem Momentum-Zähler, gleiche Höhe, neutral. */
.scr-lobby-when {
	display: inline-flex; align-items: center; gap: 12px;
	margin: clamp(20px, 3vw, 40px) 8px 0; padding: 10px 22px;
	border-radius: var(--pulse-r-pill);
	background: var(--pulse-bar-track); color: var(--pulse-text);
	font-size: clamp(16px, 1.8vw, 26px); font-weight: 700;
}

/* Momentum: „N schon dabei" mit lebendem Punkt (reduced-motion-fest). */
.scr-momentum {
	display: inline-flex; align-items: center; gap: 12px; align-self: center;
	margin: clamp(20px, 3vw, 40px) 0 0; padding: 10px 22px;
	border-radius: var(--pulse-r-pill);
	background: var(--pulse-success-soft); color: var(--pulse-text);
	font-size: clamp(16px, 1.8vw, 26px); font-weight: 700;
}
.scr-momentum b { font-size: 1.15em; font-weight: 900; font-variant-numeric: tabular-nums; }
.scr-momentum-dot { width: 0.7em; height: 0.7em; border-radius: 50%; background: var(--pulse-success); animation: scr-beat 1.4s ease-in-out infinite; }
@keyframes scr-beat { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.35; transform: scale(0.7); } }
.scr-practice {
	display: inline-flex; align-items: center; gap: 8px; padding: 6px 16px; border-radius: var(--pulse-r-pill); font-weight: 800;
	font-size: clamp(14px, 1.4vw, 20px);
	color: var(--pulse-warning); background: var(--pulse-warning-soft);
}

/* ── Kopf (Klasse A) ───────────────────────────────────────────── */
.scr-head {
	display: flex; align-items: center; gap: 1em;
	max-height: 26vh; overflow: hidden;
	margin-bottom: clamp(12px, 1.6vw, 28px);
	/* Unterlängen-Reserve: die Zeile clippt, 1,08 liegt unter der natürlichen
	   Texthöhe — ohne Reserve verliert „g" seine Unterlänge (§2.6). */
	padding-bottom: 0.16em;
}
/* ── Bildfrage (§7.1/§7.8) ─────────────────────────────────────── */
/* Zwei Spalten: Bild links über die volle Bühnenhöhe, rechts Frage und Inhalt.
   Die Bildspalte ist `auto`, nicht ein festes Drittel — ein hochformatiges Bild
   schrumpft in der Breite und gibt den Platz an den Inhalt ab, statt eine leere
   Spalte zu hinterlassen. */
.scr-stage[data-image] {
	display: grid;
	grid-template-columns: auto minmax(0, 1fr);
	grid-template-rows: auto minmax(0, 1fr);
	align-items: center;
	gap: 0.4em 1.2em;
}
.scr-stage[data-image] > .scr-img-mat { grid-column: 1; grid-row: 1 / span 2; align-self: center; }
.scr-stage[data-image] > .scr-split-q { grid-column: 2; grid-row: 1; }
/* Was sonst noch auf der Bühne steht (Ergebnis, offene Bühne, Leerzustand),
   landet in der rechten unteren Zelle — egal welcher Zweig gerade rendert. */
/* align-self: stretch ist Pflicht — mit dem zentrierenden align-items bliebe der
   Inhalt inhaltshoch und liefe unten aus dem Kiosk (38 px). */
.scr-stage[data-image] > *:not(.scr-img-mat):not(.scr-split-q) { grid-column: 2; grid-row: 2; min-height: 0; align-self: stretch; }
/* Offene Bildfrage: Antwortmöglichkeiten oben, Eingang schmal darunter — er
   wandert NICHT in die Meta-Leiste, er bleibt sichtbar (§7.1). */
.scr-stage[data-image] .scr-open { grid-template-columns: 1fr; grid-template-rows: minmax(0, 1fr) auto; gap: 0.6em; }
/* Frage und Ring rechnen in der Bühnengröße, sobald sie IN der Bühne stehen —
   sonst nähme die Schrumpf-Schleife sie nicht mit (§2.3). */
.scr-split-q { display: flex; align-items: center; gap: 0.6em; min-width: 0; }
.scr-split-q .scr-q { font-size: 1.5em; -webkit-line-clamp: 2; }
.scr-split-q .scr-cd { font-size: 1.5em; }
.scr-img-mat {
	display: grid; place-items: center; min-width: 0; min-height: 0;
	box-sizing: border-box;
	/* Deckel am Viewport, nicht am Grid-Track: die Bildspalte ist `auto`, also
	   bemisst sie sich am Bild — ein Prozent-Deckel wäre ein Ringschluss und das
	   Bild schöbe die Bühne aus dem Kiosk (hochkant: 152 px). 72vh liegt sicher
	   unter der Bühnenhöhe (Rand, Kopf und Meta-Leiste abgezogen). */
	max-width: 46vw;
	padding: 0.4em; border-radius: 0.2em;
	background: var(--pulse-image-mat); border: 1px solid var(--pulse-border);
}
/* Der Filter ist im Hellen `none`; im Dunkeln nimmt er weißen Bildgründen das
   Leuchten, ohne Farben zu verfälschen (§7.8). */
.scr-img { display: block; max-width: 44vw; max-height: 68vh; object-fit: contain; filter: var(--pulse-image-filter); }

/* Aufgelöst ohne Stimmen (§7.9) */
.scr-empty { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; justify-content: center; gap: 0.6em; }
.scr-empty-txt { margin: 0; text-align: center; font-weight: 700; color: var(--pulse-meta); }
/* Ring auf der Fragen-Achse: er misst sich an der Fragegröße (§6.5). */
.scr-cd { font-size: var(--scr-q-fs); }
.scr-q {
	font-size: var(--scr-q-fs); line-height: 1.08; margin: 0; flex: 1; min-width: 0; font-weight: 800;
	text-wrap: balance;
	overflow: hidden; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical;
}

/* ── Bühne ─────────────────────────────────────────────────────── */
/* min-height:0 ist Pflicht: ohne sie wächst die Bühne über den Kasten und
   der Vergleich scrollHeight/clientHeight in fitPass() geht nie an. */
.scr-stage {
	min-height: 0; overflow: hidden;
	font-size: var(--scr-stage-fs);
	display: flex; flex-direction: column;
}
.scr-stage > * { min-height: 0; }
/* Offene Bühne (§6.1): links ablesen, rechts der Eingang. Ohne
   Antwortmöglichkeiten (Schätzfrage, Freitext) füllt der Eingang die Bühne. */
.scr-open { flex: 1 1 auto; min-height: 0; display: grid; grid-template-columns: 1.4fr 1fr; gap: 2em; align-items: center; }
.scr-open[data-solo] { grid-template-columns: 1fr; place-items: center; }
/* Viele Zeilen: dieselbe Stapelung wie neben einem Bild (§7.1) — Inhalt oben
   über die volle Breite, Eingang als flacher Streifen darunter. */
.scr-open[data-stack] { grid-template-columns: 1fr; grid-template-rows: minmax(0, 1fr) auto; gap: 0.8em; align-items: stretch; }
.scr-open > * { min-width: 0; min-height: 0; }
.scr-open > .sopen { align-self: stretch; }

.scr-results { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
/* Viele Verteilungswerte (Skala 1–10) -> zweispaltig, damit die Balken groß
   bleiben statt auf Mini-Font zu schrumpfen. Der Ø-Block bleibt oben voll breit. */
.scr-results--cols :deep(.bars--dist) { columns: 2; column-gap: clamp(28px, 4vw, 72px); }
.scr-results--cols :deep(.bars--dist .bar-row) { break-inside: avoid; }
/* Die Ergebnis-Wurzel füllt die Resthöhe als Flex-Spalte, damit die
   höhengebundenen Quadrat-Charts (Radar/Kompass — .is-wide in ResultsView) eine
   definierte Höhe erben. */
.scr-results :deep(.pulse-results) { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }

/* Freitext-Beamer: Häufigkeits-Kacheln. Alles em -> skaliert mit fitStage. */
.scr-ftcards { flex: 1 1 auto; display: flex; flex-direction: column; min-height: 0; }
.scr-ft-head { display: flex; align-items: center; gap: 0.5em; flex-wrap: wrap; margin-bottom: 0.4em; }
.scr-ft-accept { font-size: 0.55em; }
.scr-ft-sum { margin-left: auto; font-size: 0.55em; font-weight: 700; color: var(--pulse-meta); }
.scr-ft-cloud { display: flex; flex-wrap: wrap; align-content: flex-start; gap: 0.4em; }
.scr-ft-card {
	display: inline-flex; align-items: center; gap: 0.5em;
	padding: 0.35em 0.7em; border-radius: var(--pulse-r-el);
	background: var(--pulse-bar-track); border: 2px solid var(--pulse-border);
	font-weight: 700; line-height: 1.1;
}
.scr-ft-card.is-accepted { background: var(--pulse-success-soft); border-color: var(--pulse-success); color: var(--pulse-success); }
.scr-ft-card.is-rejected { opacity: 0.5; text-decoration: line-through; }
.scr-ft-card.is-open { border-style: dashed; }
.scr-ft-txt { min-width: 0; }
.scr-ft-mult { font-variant-numeric: tabular-nums; font-weight: 800; font-size: 0.72em; color: var(--pulse-meta); background: var(--pulse-screen-bg); padding: 0.1em 0.45em; border-radius: var(--pulse-r-pill); }
.scr-ft-card.is-accepted .scr-ft-mult { color: var(--pulse-success); }
.scr-ft-empty { color: var(--pulse-text-2); }

/* Eigenes Tempo ohne Balken (§3.2): geschlossen der Zähler, freigegeben ohne
   Rangliste der Abschluss — mittig, groß, in em der Bühne. */
.scr-race-note { margin: auto; max-width: 22em; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 0.5em; }
.scr-race-note-big { margin: 0; font-size: 1.6em; font-weight: 800; line-height: 1.1; padding-bottom: 0.08em; font-variant-numeric: tabular-nums; text-wrap: balance; }
.scr-race-note-sub { margin: 0; font-size: 0.8em; font-weight: 600; line-height: 1.25; color: var(--pulse-text-2); text-wrap: balance; }

/* Endstand: eine Überschrift, darunter Podium + Plätze 4–8 (§4.3). */
.scr-final { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; gap: 0.5em; }
.scr-final-title { margin: 0; text-align: center; font-weight: 900; letter-spacing: 0.02em; font-size: var(--scr-q-fs); line-height: 1.08; }
.scr-final-lb { flex: 1 1 auto; min-height: 0; }

/* Wortwolken-Bühne: füllt die restliche Beamer-Höhe (Engine braucht Größe > 0)
   und die VOLLE Breite (sonst schrumpft die Box auf die Zähler-Zeile). */
.scr-cloud { flex: 1 1 auto; display: flex; min-height: 0; width: 100%; min-width: 0; align-self: stretch; }

/* ── Meta-Leiste (§2.5) ────────────────────────────────────────── */
.scr-meta {
	display: grid;
	grid-template-columns: auto 1fr auto;
	align-items: center;
	gap: 1em;
	margin-top: clamp(12px, 1.4vw, 24px);
	padding-top: 0.75em;
	border-top: 1px solid var(--pulse-border);
	font-size: var(--scr-meta-fs);
	color: var(--pulse-meta);
}
.scr-meta-l { display: flex; align-items: center; gap: 0.6em; }
.scr-chip {
	display: inline-flex; align-items: center; gap: 0.45em;
	padding: 0.25em 0.8em; border-radius: var(--pulse-r-pill);
	font-weight: 700; white-space: nowrap;
	background: var(--pulse-bar-track); color: var(--pulse-text);
}
.scr-chip.is-open { color: var(--pulse-state-open); background: var(--pulse-success-soft); }
.scr-chip.is-done { color: var(--pulse-state-done); }
.scr-chip.is-practice { color: var(--pulse-warning); background: var(--pulse-warning-soft); }
.scr-chip.is-off { color: var(--pulse-error); background: var(--pulse-error-soft); }
.scr-chip.is-full { color: var(--pulse-primary); background: var(--pulse-primary-soft); }
/* Eigenes Tempo: die öffentliche Seite trägt data-themes="" — die festen
   Dunkelwerte aus pulse-tokens.css greifen dort nicht, --pulse-meta und
   --pulse-state-done blieben #33465e auf dunklem Grund. Die neuen Rahmen
   nehmen deshalb Rollen, die dem NC-Theme folgen (moderiert unverändert). */
.scr.is-race .scr-meta { color: var(--pulse-text-2); }
.scr.is-race .scr-chip.is-done { color: var(--pulse-text); }
.scr-lobby.is-paced .scr-join-url { color: var(--pulse-text-2); }
.scr-chip-dot { width: 0.55em; height: 0.55em; border-radius: 50%; background: currentColor; animation: scr-beat 1.6s ease-in-out infinite; }
.scr-legend { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
/* QR + Code: der Code bleibt in Klasse B — aus der letzten Reihe ist er der
   eigentliche Beitrittsweg, einen QR aus 12 m zu scannen funktioniert nicht. */
.scr-join-mini { display: flex; align-items: center; gap: 0.6em; flex: 0 0 auto; }
.scr-qr-mini { width: 88px; background: #fff; padding: 4px; border: 2px solid var(--pulse-border-strong); border-radius: var(--pulse-r-el); }
.scr-pin-mini { font-weight: 800; font-size: var(--scr-stage-fs); letter-spacing: 0.08em; color: var(--pulse-text); font-variant-numeric: tabular-nums; }

.scr-conn { margin-top: 24px; color: var(--pulse-error); font-size: 16px; }

@media (prefers-reduced-motion: reduce) {
	.scr-momentum-dot, .scr-chip-dot { animation: none; }
}
</style>

<!--
	Un-scoped + per Body-Klasse gegatet: greift NUR auf dem Beamer (Screen.vue
	setzt `body.pulse-beamer`), nicht auf dem Handy.

	Der Beamer ist ein Kiosk (D3): keine NC-Kopfleiste, kein blauer Rand, keine
	weiße Karte darin. Die Bühne selbst liegt `position: fixed` über allem; hier
	fällt nur weg, was sonst darunter oder darüber sichtbar bliebe (Kopfleiste,
	Gast-Fußzeile, Seiten-Hintergrund).
-->
<style>
body.pulse-beamer #header { display: none !important; }
body.pulse-beamer footer.guest-box { display: none !important; }
body.pulse-beamer #content {
	margin: 0 !important;
	padding: 0 !important;
	border-radius: 0 !important;
	background: var(--pulse-screen-bg) !important;
}
</style>
