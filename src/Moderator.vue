<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pulse-mod" :class="{ 'is-fs': isFullscreen, 'is-live': liveFrame, 'is-start': phase === 'start' }">
		<!--
		  Startbildschirm (§9.5): zweispaltig ab 1200 px. Vorher standen rund
		  150 px Leerraum über dem Kopfblock und „Meine Räume" begann so weit
		  unten, dass die erste Raumkarte angeschnitten war — beides löst die
		  zweite Spalte in einem Zug.
		-->
		<section v-if="phase === 'start'" class="start" :class="{ 'has-rooms': myRooms.length }">
			<div class="start-intro">
				<p class="eyebrow">Pulse</p>
				<h1>{{ t('pulse', 'Live poll or quiz') }}</h1>
				<p class="lede">{{ t('pulse', 'Build a deck of questions, project code and QR, and click through it during your talk.') }}</p>

				<!-- Ein CTA + Modus-Segmented (§4.1): erst Modus wählen, dann EIN Start-Knopf -->
				<div class="start-cta">
					<PulseSegmented v-model="startMode" :options="modeOptions" :label="t('pulse', 'Choose mode')" />
					<button class="pulse-btn is-primary is-lg" :disabled="busy" @click="startRoom(startMode)">
						<PulseIcon name="play" size="1.05em" /> {{ startMode === 'quiz' ? t('pulse', 'Start quiz') : t('pulse', 'Start poll') }}
					</button>
				</div>
				<p class="mode-note">
					<b>{{ t('pulse', 'Poll:') }}</b> {{ t('pulse', 'anonymous, multiple choice / word cloud / scale.') }} &nbsp;·&nbsp;
					<b>{{ t('pulse', 'Quiz:') }}</b> {{ t('pulse', 'with names, correct answer, countdown & leaderboard.') }}
				</p>

				<!-- Beitreten ist die seltenere Handlung und tritt deshalb als
				     Textknopf auf, nicht als zweiter Rahmenknopf neben dem Start. -->
				<a class="start-join" :href="joinPagePath" target="_blank" rel="noopener">{{ t('pulse', 'Join a room') }}</a>
			</div>

			<div v-if="myRooms.length" class="myrooms">
				<h2 class="myrooms-head">{{ t('pulse', 'My rooms') }}</h2>
				<ul class="myrooms-list">
					<li v-for="r in myRooms" :key="r.code" class="myroom">
						<button class="myroom-hit" :aria-label="t('pulse', 'Open room {code}', { code: r.code })" @click="openRoom(r.code)">
							<span class="myroom-body">
								<span v-if="r.title" class="myroom-title">{{ r.title }}</span>
								<span class="myroom-code" :class="{ 'is-sub': r.title }">{{ spaced(r.code) }}</span>
								<span class="myroom-meta">
									<span class="pulse-chip myroom-type" :class="r.mode === 'quiz' ? 'is-quiz' : 'is-accent'"><PulseIcon :name="r.mode === 'quiz' ? 'quiz' : 'poll'" size="1em" /> {{ r.mode === 'quiz' ? t('pulse', 'Quiz') : t('pulse', 'Poll') }}</span>
									<span v-if="r.pace === 'self' && r.window" class="pulse-chip myroom-chip myroom-pace" :class="paceChip(r.window).cls"><span v-if="paceChip(r.window).dot" class="pulse-chip-dot" />{{ paceChip(r.window).label }}</span>
									<span v-if="r.activePollId" class="pulse-chip is-live myroom-chip"><span class="pulse-chip-dot" />{{ t('pulse', 'Running') }}</span>
									<span class="myroom-count">{{ n('pulse', '%n question', '%n questions', r.pollCount) }} · {{ ago(r.createdAt) }}</span>
								</span>
							</span>
							<PulseIcon name="chevron" size="1.2em" class="myroom-go" />
						</button>
						<PulseMenu :items="roomMenu(r)" :label="t('pulse', 'More actions for this room')" small />
					</li>
				</ul>
			</div>
		</section>

		<!-- Zusammenfassung: alle Fragen mit Ergebnissen + CSV-Export -->
		<section v-else-if="phase === 'summary'" class="summary-view">
			<div class="deck-head">
				<h2>{{ t('pulse', 'Summary') }}</h2>
				<div class="deck-head-actions">
					<div class="btn-group">
						<button class="pulse-btn is-secondary is-sm" @click="goHome"><PulseIcon name="chevron" size="1em" class="ic-flip" /> {{ t('pulse', 'My rooms') }}</button>
						<button class="pulse-btn is-secondary is-sm summary-back" @click="summaryBack">{{ summaryToPace ? t('pulse', 'Back to progress') : t('pulse', 'Back to the deck') }}</button>
					</div>
					<!-- Eigenes Tempo: neben „Teilnehmende/Antworten als CSV" (Laufansicht)
					     ist das die dritte CSV — darum eindeutig benannt. -->
					<a class="pulse-btn is-primary is-sm export-btn" :href="exportUrl" download>{{ isPaced ? t('pulse', 'Results per question (CSV)') : t('pulse', 'Export CSV') }}</a>
				</div>
			</div>
			<!-- Solange das Quiz offen ist, zeigt diese Seite die Lösungen — nicht spiegeln. -->
			<p v-if="isPaced && paceState === 'open'" class="pulse-notice is-warn summary-warn"><PulseIcon name="lock" size="1.1em" class="pulse-notice-ico" /><span><b class="summary-warn-head">{{ t('pulse', 'Only for you') }}</b> {{ t('pulse', 'This page shows the solutions — do not mirror it while the quiz is open.') }}</span></p>
			<div v-if="summary.length" class="summary-list">
				<div v-for="(item, i) in summary" :key="item.poll.id" class="summary-item">
					<h3 class="summary-q">
						<span class="summary-num">{{ i + 1 }}</span>
						<span class="summary-qtext">{{ item.poll.question }}</span>
						<span class="summary-type">{{ typeLabel(item.poll.type) }}</span>
					</h3>
					<TextGrading v-if="item.poll.type === 'text'" :results="item.results" @grade="(ans, ok) => gradeSummaryAnswer(item, ans, ok)" />
					<ResultsView v-else :results="item.results" :correct-id="item.poll.correctOption || ''" :correct-ids="((item.poll.answerKey || {}).correct) || []" :answer-key="item.poll.answerKey || null" />
				</div>
			</div>
			<p v-else class="deck-empty">{{ t('pulse', 'No questions in the deck yet.') }}</p>
		</section>

		<!-- Deck-Editor: Fragen anlegen, sortieren, Ablauf einstellen (§9.6 — bleibt) -->
		<section v-else-if="phase === 'deck'" class="present">
			<div class="present-main">
				<!-- ── DECK-EDITOR ─────────────────────────────────────────── -->
				<div class="deck-head" :class="{ 'is-paced': isPaced }">
					<button class="pulse-btn is-secondary is-icon deck-back" :title="t('pulse', 'My rooms')" :aria-label="t('pulse', 'My rooms')" @click="goHome"><PulseIcon name="chevron" size="1.1em" class="ic-flip" /></button>
					<h2 v-if="!renaming" class="deck-title">
						<span class="deck-title-txt" :class="{ 'is-untitled': !roomTitle }">{{ roomTitle || (isQuiz ? t('pulse', 'Your quiz') : t('pulse', 'Your deck')) }}</span>
						<button class="pulse-btn is-secondary is-icon is-xs" :title="t('pulse', 'Name the room — helps you find it again under “My rooms”')" :aria-label="t('pulse', 'Name the room')" @click="startRename"><PulseIcon name="edit" size="1em" /></button>
					</h2>
					<form v-else class="deck-rename" @submit.prevent="saveRename">
						<input ref="renameInput" v-model="renameDraft" class="pinput deck-rename-input" type="text" maxlength="80" :placeholder="t('pulse', 'Room name (optional)')" :aria-label="t('pulse', 'Room name')" @keyup.esc="cancelRename">
						<button class="pulse-btn is-primary is-sm" type="submit">{{ t('pulse', 'Save') }}</button>
						<button class="pulse-btn is-secondary is-sm" type="button" @click="cancelRename">{{ t('pulse', 'Cancel') }}</button>
					</form>
					<span class="pulse-chip deck-mode" :class="isQuiz ? 'is-quiz' : 'is-accent'"><PulseIcon :name="isQuiz ? 'quiz' : 'poll'" size="1em" /> {{ isPaced ? t('pulse', 'Quiz · self-paced') : isQuiz ? t('pulse', 'Quiz') : t('pulse', 'Poll') }}</span>
					<span v-if="deckStateChip" class="pulse-chip deck-state" :class="deckStateChip.cls"><span v-if="deckStateChip.dot" class="pulse-chip-dot" />{{ deckStateChip.label }}</span>
					<span class="deck-head-spacer" />
					<PulseMenu :items="deckMenu" :label="t('pulse', 'More actions')" />
					<!-- Leeres Deck: der Kopf bleibt ohne Hauptaktion, die trägt der leere
					     Zustand in der Mitte. Steht ein Formular offen, tritt der Kopf
					     zurück — der gefüllte Knopf gehört dann dem Formular (R1), auch
					     dem Öffnen-Dialog im eigenen Tempo. -->
					<button v-if="deckPrimary" ref="deckPrimary" class="pulse-btn" :class="deckQuiet ? 'is-secondary' : 'is-primary'" @click="deckPrimary.act()"><PulseIcon :name="deckPrimary.icon" size="1em" /> {{ deckPrimary.label }}</button>
				</div>
				<p v-if="isPractice" class="pulse-notice is-warn practice-banner"><PulseIcon name="warning" size="1.1em" class="pulse-notice-ico" /><span>{{ t('pulse', 'Practice run active — votes do not count towards the leaderboard. For the real start switch the practice run off in the menu (empties the room).') }}</span></p>
				<!-- Eigenes Tempo ab dem Öffnen: die eingefrorene Reihenfolge bleibt bis
				     zum Zurücksetzen stehen. Solange offen, geht Zurücksetzen noch nicht —
				     deshalb dort der Zwischenschritt „erst schließen". -->
				<p v-if="deckLocked" class="pulse-notice is-info deck-lock-note"><PulseIcon name="lock" size="1.1em" class="pulse-notice-ico" /><span>{{ paceState === 'open' ? t('pulse', 'Close the quiz, then reset it to edit the questions.') : t('pulse', 'The questions are locked since the quiz was opened. Reset the room to edit them.') }}</span></p>

				<draggable v-if="deck.length" v-model="deck" tag="ol" class="deck-list"
					handle=".drag-handle" :animation="150" :disabled="deckLocked" @end="onReorder">
					<li v-for="(p, i) in deck" :key="p.id" class="deck-item" :class="{ 'is-current': p.id === currentId }">
						<span v-if="!deckLocked" class="drag-handle" aria-hidden="true" :title="t('pulse', 'Drag to reorder')"><PulseIcon name="drag" size="1.1em" /></span>
						<span class="deck-num">{{ i + 1 }}</span>
						<div class="deck-body">
							<button class="deck-q" :title="deckLocked ? null : t('pulse', 'Edit')" :disabled="deckLocked" @click="openComposer(p)">{{ p.question }}</button>
							<span class="deck-type"><span v-if="p.image" class="deck-imgflag" :title="t('pulse', 'This question has an image')">{{ t('pulse', 'Image') }}</span><span class="deck-tico" :class="'is-' + typeTag(p.type).c">{{ typeTag(p.type).t }}</span>{{ typeLabel(p.type) }}<template v-if="p.type === 'scale'"> · <b class="deck-submode">{{ scaleModeLabel(p) }}</b></template><span v-if="isQuiz"><template v-if="showRowLimit"> · {{ p.timeLimit }}s</template> · {{ answerHint(p) }}</span></span>
						</div>
						<span v-if="p.id === currentId" class="pulse-chip is-accent deck-current-chip">{{ t('pulse', 'Current') }}</span>
						<button v-if="!isPaced" class="pulse-btn is-sm is-secondary" @click="present(p.id)"><PulseIcon name="play" size="1em" /> {{ t('pulse', 'Show') }}</button>
						<PulseMenu v-if="!deckLocked" ref="rowMenus" :items="rowMenu(p, i)" :label="t('pulse', 'More actions for this question')" small />
					</li>
				</draggable>
				<div v-else class="empty-state">
					<PulseIcon :name="isQuiz ? 'quiz' : 'poll'" size="2.4em" class="empty-ico" />
					<p class="empty-title">{{ t('pulse', 'No questions yet') }}</p>
					<p class="empty-sub">{{ t('pulse', 'Start with the first question —') }} {{ isQuiz ? t('pulse', 'multiple choice, true/false, number guess and more') : t('pulse', 'multiple choice, word cloud or scale') }}.</p>
					<button v-if="!showComposer && !deckLocked" class="pulse-btn is-primary" @click="openComposer()">{{ t('pulse', 'Add the first question') }}</button>
				</div>

				<!-- Composer -->
				<div v-if="showComposer && !deckLocked" ref="composer" class="composer">
					<p v-if="editingId" class="pulse-notice is-warn edit-note"><PulseIcon name="warning" size="1.1em" class="pulse-notice-ico" /><span>{{ t('pulse', 'Editing resets the votes cast for this question so far.') }}</span></p>
					<PulseSegmented v-model="draft.type" :options="typeOptions" :label="t('pulse', 'Question type')" class="composer-seg" />

					<input ref="qInput" v-model="draft.question" class="pinput pinput--q" type="text" :placeholder="t('pulse', 'Your question …')" @keyup.enter="focusFirstOption">

					<!-- Bild zur Frage (optional). Wird beim Speichern hochgeladen und
					     serverseitig neu kodiert + verkleinert. -->
					<div class="img-row">
						<div v-if="draft.imagePreview" class="img-preview">
							<img :src="draft.imagePreview" :alt="t('pulse', 'Preview of the question image')">
							<button class="pulse-btn is-secondary is-icon is-sm img-drop" :title="t('pulse', 'Remove image')" :aria-label="t('pulse', 'Remove image')" @click="clearImage"><PulseIcon name="close" size="1.05em" /></button>
						</div>
						<label class="pulse-btn is-secondary is-sm img-pick">
							<PulseIcon name="edit" size="1em" /> {{ draft.imagePreview ? t('pulse', 'Replace image') : t('pulse', 'Add image') }}
							<input ref="imageInput" class="img-input" type="file" accept="image/png,image/jpeg,image/gif,image/webp" @change="pickImage">
						</label>
						<span class="img-hint">{{ t('pulse', 'PNG, JPEG, GIF or WebP · up to 5 MB') }}</span>
					</div>

					<!-- Multiple Choice + Mehrfachauswahl: Optionen -->
					<template v-if="draft.type === 'choice' || draft.type === 'multi' || draft.type === 'rank'">
						<p v-if="draft.type === 'rank'" class="quiz-hint">{{ isQuiz ? t('pulse', 'Enter the answers in the CORRECT order — that is the solution. On the phone they appear shuffled.') : t('pulse', 'The audience sorts these answers. The order here is only the starting line-up.') }}</p>
						<p v-else-if="isQuiz" class="quiz-hint">{{ draft.type === 'multi' ? t('pulse', 'Mark all correct answers:') : t('pulse', 'Mark the correct answer:') }}</p>
						<div v-for="(opt, i) in draft.options" :key="i" class="opt-row">
							<button v-if="isQuiz && draft.type !== 'rank'" class="opt-correct" :class="{ 'is-on': isOptCorrect(i), 'is-multi': draft.type === 'multi' }"
								:aria-pressed="isOptCorrect(i) ? 'true' : 'false'" :aria-label="isOptCorrect(i) ? t('pulse', 'Correct answer (marked)') : t('pulse', 'Mark as the correct answer')" :title="isOptCorrect(i) ? t('pulse', 'Correct') : t('pulse', 'Mark as correct')" @click="toggleCorrect(i)">
								<span class="opt-correct-box"><PulseIcon v-if="isOptCorrect(i)" name="check" size="0.95em" /></span>
								<span class="opt-correct-txt">{{ t('pulse', 'correct') }}</span>
							</button>
							<span v-if="draft.type === 'rank'" class="opt-rank-pos" aria-hidden="true">{{ i + 1 }}</span>
							<input v-model="draft.options[i]" ref="optionInputs" class="pinput" type="text" :placeholder="draft.type === 'rank' ? t('pulse', '{number}. answer', { number: i + 1 }) : t('pulse', 'Option {number}', { number: i + 1 })">
							<button v-if="draft.options.length > 2" class="pulse-btn is-secondary is-icon" :title="t('pulse', 'Remove option')" :aria-label="t('pulse', 'Remove option')" @click="removeOption(i)"><PulseIcon name="close" size="1.05em" /></button>
						</div>
						<button v-if="draft.options.length < 8" class="pulse-btn is-secondary is-sm" @click="addOption"><PulseIcon name="plus" size="1em" /> {{ t('pulse', 'Option') }}</button>
					</template>

					<!-- Zuordnung: je Zeile ein Paar. Rechts erscheint am Handy gemischt. -->
					<template v-else-if="draft.type === 'match'">
						<p class="quiz-hint">{{ isQuiz ? t('pulse', 'Each row is one correct pair — on the phone the right-hand column appears shuffled.') : t('pulse', 'Each row is one pair. The audience assigns the right column to the left one; there is no right or wrong.') }}</p>
						<div v-for="(pair, i) in draft.pairs" :key="i" class="pair-row">
							<span class="opt-rank-pos" aria-hidden="true">{{ i + 1 }}</span>
							<input v-model="pair.left" ref="optionInputs" class="pinput" type="text" :placeholder="t('pulse', 'Item {number}', { number: i + 1 })">
							<PulseIcon name="arrow-right" size="1.1em" class="pair-arrow" />
							<input v-model="pair.right" class="pinput" type="text" :placeholder="t('pulse', 'Belongs to {number}', { number: i + 1 })">
							<button v-if="draft.pairs.length > 2" class="pulse-btn is-secondary is-icon" :title="t('pulse', 'Remove pair')" :aria-label="t('pulse', 'Remove pair')" @click="removePair(i)"><PulseIcon name="close" size="1.05em" /></button>
						</div>
						<button v-if="draft.pairs.length < 8" class="pulse-btn is-secondary is-sm" @click="addPair"><PulseIcon name="plus" size="1em" /> {{ t('pulse', 'Pair') }}</button>
					</template>

					<!-- Wahr/Falsch -->
					<template v-else-if="draft.type === 'truefalse'">
						<p class="quiz-hint">{{ t('pulse', 'Which one is correct?') }}</p>
						<div class="tf-pick">
							<button class="pulse-btn" :class="{ 'is-on': draft.correctIndex === 0 }" @click="draft.correctIndex = 0">{{ t('pulse', 'True') }}</button>
							<button class="pulse-btn" :class="{ 'is-on': draft.correctIndex === 1 }" @click="draft.correctIndex = 1">{{ t('pulse', 'False') }}</button>
						</div>
					</template>

					<!-- Schätzfrage -->
					<template v-else-if="draft.type === 'number'">
						<div class="num-fields">
							<label class="words-hint">{{ t('pulse', 'Correct number') }}
								<input v-model="draft.target" class="pinput" type="number" inputmode="decimal" :placeholder="t('pulse', 'e.g. 1969')">
							</label>
							<label class="words-hint">{{ t('pulse', '± tolerance') }}
								<input v-model="draft.tolerance" class="pinput" type="number" inputmode="decimal" min="0" placeholder="0">
							</label>
						</div>
						<p class="quiz-hint">{{ t('pulse', 'Anyone within number ± tolerance counts as correct.') }}</p>
					</template>

					<!-- Freitext -->
					<template v-else-if="draft.type === 'text'">
						<p class="quiz-hint">{{ t('pulse', 'Accepted answer(s) — you can approve further spellings later during the reveal:') }}</p>
						<div v-for="(a, i) in draft.answers" :key="i" class="opt-row">
							<input v-model="draft.answers[i]" ref="optionInputs" class="pinput" type="text" :placeholder="i === 0 ? t('pulse', 'Correct answer') : t('pulse', 'Another accepted answer')">
							<button v-if="draft.answers.length > 1" class="pulse-btn is-secondary is-icon" :title="t('pulse', 'Remove answer')" :aria-label="t('pulse', 'Remove answer')" @click="removeAnswer(i)"><PulseIcon name="close" size="1.05em" /></button>
						</div>
						<button v-if="draft.answers.length < 8" class="pulse-btn is-secondary is-sm" @click="addAnswer"><PulseIcon name="plus" size="1em" /> {{ t('pulse', 'Answer') }}</button>
					</template>

					<!-- Skala (Umfrage) — Modus Einzel | Spektrum | Kompass -->
					<template v-else-if="draft.type === 'scale'">
						<div class="submode">
								<span class="submode-arrow" aria-hidden="true">▸</span>
								<span class="submode-key">{{ t('pulse', 'Scale mode') }}</span>
								<PulseSegmented v-model="draft.scaleMode" :options="scaleModeOptions" :label="t('pulse', 'Scale mode')" class="submode-seg" />
							</div>
						<!-- Einzel/Spektrum: gemeinsames Maximum X -->
						<label v-if="draft.scaleMode !== 'compass'" class="words-hint">{{ draft.scaleMode === 'spectrum' ? t('pulse', 'Scale 0 to') : t('pulse', 'Scale 1 to') }}
							<input v-model.number="draft.scaleMax" class="pinput pinput--num" type="number" min="2" max="20" step="1">
						</label>
						<!-- Einzel: optionale Pol-Labels an 1 und X -->
						<template v-if="draft.scaleMode === 'single'">
							<input v-model="draft.minLabel" class="pinput" type="text" :placeholder="t('pulse', 'Label for 1 (optional, e.g. “disagree”)')">
							<input v-model="draft.maxLabel" class="pinput" type="text" :placeholder="t('pulse', 'Label for {max} (optional, e.g. “fully agree”)', { max: draft.scaleMax })">
						</template>
						<!-- Spektrum: 3–8 Aspekte, je ein Regler von 0 bis X -->
						<template v-else-if="draft.scaleMode === 'spectrum'">
							<p class="quiz-hint">{{ t('pulse', 'Aspects (3–8) — one slider each from 0 to {max}:', { max: draft.scaleMax }) }}</p>
							<div v-for="(asp, i) in draft.aspects" :key="i" class="aspect-row">
								<span class="aspect-idx">{{ i + 1 }}</span>
								<div class="aspect-fields">
									<input v-model="draft.aspects[i].label" class="pinput" type="text" :placeholder="t('pulse', 'Aspect name (e.g. pace)')">
									<div class="aspect-poles">
										<input v-model="draft.aspects[i].poleLow" class="pinput pinput--pole" type="text" :placeholder="t('pulse', 'Pole at 0 (optional)')">
										<input v-model="draft.aspects[i].poleHigh" class="pinput pinput--pole" type="text" :placeholder="t('pulse', 'Pole at X (optional)')">
									</div>
								</div>
								<button v-if="draft.aspects.length > 3" class="pulse-btn is-secondary is-icon" :title="t('pulse', 'Remove aspect')" :aria-label="t('pulse', 'Remove aspect')" @click="removeAspect(i)"><PulseIcon name="close" size="1.05em" /></button>
							</div>
							<button v-if="draft.aspects.length < 8" class="pulse-btn is-secondary is-sm" @click="addAspect"><PulseIcon name="plus" size="1em" /> {{ t('pulse', 'Aspect') }}</button>
						</template>
						<!-- Kompass: gerahmte Sektionen (§4) — Achsen · Bereich & Darstellung · Ecklabels -->
						<template v-else>
							<div class="sgroup">
								<h4 class="sgroup-head">{{ t('pulse', 'Two axes') }}</h4>
								<div class="sgroup-in">
									<div class="axis-block">
										<p class="quiz-hint">{{ t('pulse', 'X axis (horizontal) — title + both poles (required)') }}</p>
										<input v-model="draft.axisX.title" class="pinput" type="text" :placeholder="t('pulse', 'Title of the X axis (e.g. practical ↔ theoretical)')">
										<div class="aspect-poles">
											<input v-model="draft.axisX.poleLow" class="pinput pinput--pole" type="text" :placeholder="t('pulse', 'Pole on the left (−)')">
											<input v-model="draft.axisX.poleHigh" class="pinput pinput--pole" type="text" :placeholder="t('pulse', 'Pole on the right (+)')">
										</div>
									</div>
									<div class="axis-block">
										<p class="quiz-hint">{{ t('pulse', 'Y axis (vertical) — title + both poles (required)') }}</p>
										<input v-model="draft.axisY.title" class="pinput" type="text" :placeholder="t('pulse', 'Title of the Y axis (e.g. alone ↔ together)')">
										<div class="aspect-poles">
											<input v-model="draft.axisY.poleLow" class="pinput pinput--pole" type="text" :placeholder="t('pulse', 'Pole at the bottom (−)')">
											<input v-model="draft.axisY.poleHigh" class="pinput pinput--pole" type="text" :placeholder="t('pulse', 'Pole at the top (+)')">
										</div>
									</div>
								</div>
							</div>
							<div class="sgroup">
								<h4 class="sgroup-head">{{ t('pulse', 'Range & display') }}</h4>
								<div class="sgroup-in">
									<div class="two-col">
										<label class="words-hint words-hint--col">{{ t('pulse', 'Range ± (per axis −R … +R)') }}
											<input v-model.number="draft.range" class="pinput pinput--num" type="number" min="2" max="20" step="1">
										</label>
										<label class="words-hint words-hint--col">{{ t('pulse', 'Heat map from … votes') }}
											<input v-model.number="draft.heatmapThreshold" class="pinput pinput--num" type="number" min="2" max="999" step="1">
										</label>
									</div>
								</div>
							</div>
							<div class="sgroup">
								<h4 class="sgroup-head">{{ t('pulse', 'Quadrant corner labels') }} <span class="sgroup-opt">· {{ t('pulse', 'optional') }}</span></h4>
								<div class="sgroup-in">
									<p class="quiz-hint">{{ t('pulse', 'They label the four quadrants of the field — placed as on the projector.') }}</p>
									<div class="corner-grid">
										<label class="corner-cell"><span class="corner-lbl">{{ t('pulse', 'Top left') }}</span><input v-model="draft.cornerLabels[2]" class="pinput pinput--pole" type="text" :placeholder="t('pulse', 'e.g. practical & together')"></label>
										<label class="corner-cell"><span class="corner-lbl">{{ t('pulse', 'Top right') }}</span><input v-model="draft.cornerLabels[3]" class="pinput pinput--pole" type="text" :placeholder="t('pulse', 'e.g. theoretical & together')"></label>
										<label class="corner-cell"><span class="corner-lbl">{{ t('pulse', 'Bottom left') }}</span><input v-model="draft.cornerLabels[0]" class="pinput pinput--pole" type="text" :placeholder="t('pulse', 'e.g. practical & alone')"></label>
										<label class="corner-cell"><span class="corner-lbl">{{ t('pulse', 'Bottom right') }}</span><input v-model="draft.cornerLabels[1]" class="pinput pinput--pole" type="text" :placeholder="t('pulse', 'e.g. theoretical & alone')"></label>
									</div>
								</div>
							</div>
						</template>
					</template>
					<!-- Wortwolke (Umfrage) -->
					<template v-else-if="draft.type === 'words'">
						<label class="words-hint">{{ t('pulse', 'Up to') }}
							<select v-model.number="draft.maxWords" class="pinput pinput--sel">
								<option v-for="n in 5" :key="n" :value="n">{{ n }}</option>
							</select>
							{{ t('pulse', 'words per person') }}</label>
					</template>

					<label v-if="isQuiz" class="words-hint quiz-time">{{ t('pulse', 'Time per question') }}
						<select v-model.number="draft.timeLimit" class="pinput pinput--sel">
							<option v-for="n in [10, 15, 20, 30, 45, 60, 90]" :key="n" :value="n">{{ n }} s</option>
						</select>
					</label>

					<div class="composer-actions">
						<button class="pulse-btn is-primary" :disabled="busy" @click="saveQuestion">{{ busy ? '…' : (editingId ? t('pulse', 'Save') : t('pulse', 'Add to the deck')) }}</button>
						<button class="pulse-btn is-secondary" @click="cancelComposer">{{ t('pulse', 'Cancel') }}</button>
					</div>
				</div>
				<button v-else-if="deck.length && !deckLocked" class="pulse-btn is-secondary add-btn" @click="openComposer()">{{ t('pulse', '+ Add question') }}</button>
			</div>

			<!-- Beitritts-Panel mit QR -->
			<aside class="present-aside">
				<div class="qr-card">
					<QrCode :value="joinUrlFull" />
				</div>
				<!-- Eine ruhige Beitritts-Einheit: Code (groß) + kurze Adresse + Kopieren (§5b). -->
				<span class="join-hint">{{ t('pulse', 'Join in — code at') }} <b>{{ joinBase }}</b></span>
				<span class="join-code">{{ spacedCode }}</span>
				<div class="join-link">
					<a class="join-url" :href="joinPath" target="_blank" rel="noopener">{{ joinUrl }}</a>
					<button class="pulse-btn is-secondary is-icon is-xs join-copy" :title="t('pulse', 'Copy join link')" :aria-label="t('pulse', 'Copy join link')" @click="copyJoin"><PulseIcon name="copy" size="1em" /></button>
				</div>
				<!-- Eigenes Tempo: statt „● live" die Statuszeile — wer schon da ist,
				     auch vor dem Öffnen. Sie hält zugleich das Fenster aktuell (Frist,
				     zweiter Tab). -->
				<span v-if="!isPaced" class="conn" role="status" :class="{ 'is-off': !online }">{{ online ? t('pulse', '● live') : t('pulse', '● offline — connecting …') }}</span>
				<PaceDeckStatus v-else
					:key="room.code"
					:code="room.code"
					:win="room.window"
					@counts="paceCounts = $event"
					@skew="paceSkew = $event"
					@window="onPaceWindow"
					@gone="onPaceGone"
					@not-paced="refreshRoom()" />
			</aside>
			<PaceOpenDialog v-if="paceDialog"
				:room="room"
				:skew="paceSkew"
				mode="open"
				@done="onPaceDone"
				@conflict="onPaceConflict"
				@end-practice="endPracticeThenOpen"
				@cancel="paceDialog = null" />
		</section>

		<!-- Eigenes Tempo ab dem Öffnen: Fortschritt je Person und EINE
		     Hauptaktion (schließen, freigeben …), mit eigenem /progress-Abruf. -->
		<PaceRun v-else-if="phase === 'pace'"
			:key="room.code"
			:room="room"
			:join-url="joinUrl"
			:join-url-full="joinUrlFull"
			:join-path="joinPath"
			:focus-title="paceFocus"
			@room="onPaceRoom"
			@window="onPaceWindow"
			@skew="paceSkew = $event"
			@go="onPaceGo"
			@reset="resetRoom"
			@end-practice="endPracticeThenOpen"
			@copy="copyJoin"
			@reload="loadRoom(room.code)" />

		<!--
		  Präsentation und Endstand (§9): EIN Rahmen — Kopfzeile, Leinwand-
		  Vorschau neben dem privaten Panel, Steuerleiste mit genau EINER
		  Hauptaktion. Vorher standen sechs gleichrangige Knöpfe nebeneinander,
		  der auffälligste war der destruktive („Finish"), und die häufigste
		  Handlung — weiterschalten und auflösen — hatte gar keinen Knopf,
		  sondern versteckte sich in Chevrons (N9).
		-->
		<section v-else class="mod-live">
			<header class="mod-top">
				<span class="mod-pos">{{ headPos }}</span>
				<CountdownRing v-if="showCountdown" class="mod-cd" :remaining="quizRemaining" :total="(currentPoll && currentPoll.timeLimit) || 0" />
				<span class="pulse-chip is-live mod-here" :title="t('pulse', '{count} participants active in the last few seconds', { count: presentCount })"><span class="pulse-chip-dot" />{{ t('pulse', '{count} here', { count: presentCount }) }}</span>
				<span v-if="isPractice" class="pulse-chip is-warning"><PulseIcon name="warning" size="1em" /> {{ t('pulse', 'Practice run') }}</span>
				<span v-if="isRevealAtEnd" class="pulse-chip is-accent"><PulseIcon name="check_ring" size="1em" /> {{ t('pulse', 'Reveal at the end') }}</span>
				<span class="mod-spacer" />
				<span v-if="room" class="mod-code" :title="t('pulse', 'Room code')">{{ spacedCode }}</span>
				<button class="pulse-btn is-secondary is-sm" :aria-expanded="joinOpen ? 'true' : 'false'" @click="toggleJoin">{{ t('pulse', 'Join') }}</button>
				<!-- Überlaufmenü: alles, was ein- bis zweimal je Sitzung gebraucht
				     wird, statt dauerhaft neben der Arbeitsfläche zu leuchten. -->
				<PulseMenu :items="menuItems" :label="t('pulse', 'More actions')" small />
				<p v-if="!online" class="pulse-notice is-warn mod-offline" role="status"><PulseIcon name="warning" size="1.1em" class="pulse-notice-ico" /><span>{{ t('pulse', 'No connection — the room is not answering. The main action stays disabled until it is back.') }}</span></p>
			</header>

			<div class="mod-body">
				<!-- Endstand: derselbe Rahmen, anderer Körper. Kein privates Panel —
				     nach finish() (/end) sieht der Saal denselben Stand. Nur der
				     Zwischenstand übers Menü bleibt beim Moderator (Kopf: „Rangliste“). -->
				<div v-if="phase === 'leaderboard'" class="mod-standings">
					<h2 class="mod-standings-head"><PulseIcon name="ranking" size="1em" /> {{ quizOver ? t('pulse', 'Final standings') : t('pulse', 'Leaderboard') }}</h2>
					<Leaderboard v-if="leaderboard.length" :rows="leaderboard" :podium="true" :limit="30" />
					<p v-else class="deck-empty">{{ t('pulse', 'No points yet — nobody has played along.') }}</p>
				</div>
				<template v-else>
					<!-- Leinwand-Vorschau statt Nachbildung: dieselbe Seite wie
					     /screen/{code}, in 16:9 verkleinert. Ein Nachbau wäre eine
					     zweite Wahrheit, die auseinanderläuft; so sieht der
					     Moderator, was der Saal sieht. Rein lesend — die Richtung
					     „Beamer spiegelt den Moderator" bleibt verboten. -->
					<div ref="canvas" class="mod-canvas">
						<div class="mod-canvas-in" :style="canvasStyle">
							<iframe v-if="room" class="mod-frame" :style="frameStyle" :src="screenPath" :title="t('pulse', 'Preview of the projector view')" />
						</div>
					</div>
					<!-- „Nur für dich" (E3): der Stand, den der Saal NICHT sieht.
					     Im Vollbild ist das Panel nicht ausgeblendet, sondern gar
					     nicht im Dokument — Vollbild ist genau der Modus, der
					     projiziert wird. Das ist keine Sicht-, sondern eine
					     Sicherheitsfrage (§9.3). -->
					<aside v-if="!isFullscreen" class="mod-private">
						<h2 class="mod-private-head"><PulseIcon name="lock" size="1.05em" /> {{ t('pulse', 'Only for you') }}</h2>
						<p class="mod-private-sub">{{ t('pulse', 'Live distribution — this panel never goes on the projector.') }}</p>
						<div v-if="currentPoll" class="mod-private-body">
							<TextGrading v-if="currentPoll.type === 'text'" :results="results" @grade="gradeAnswer" />
							<ResultsView v-else dense :results="results" :correct-id="currentPoll.correctOption || ''" :correct-ids="((currentPoll.answerKey || {}).correct) || []" :answer-key="currentPoll.answerKey || null" />
						</div>
						<p v-else class="mod-private-empty">{{ t('pulse', 'No question yet — the room shows the code and waits.') }}</p>
						<Leaderboard v-if="isQuiz && leaderboard.length" class="mod-private-lb" :rows="leaderboard" :limit="5" />

						<!-- Demo-/Testwerkzeug: aktive Frage mit vielen Stimmen befüllen (Vorschau) -->
						<div v-if="demoMode" class="demo-bar" role="group" :aria-label="t('pulse', 'Test tool: demo votes')">
							<span class="pulse-chip is-warning demo-chip"><span class="pulse-chip-dot" /> {{ t('pulse', 'Demo mode') }}</span>
							<span class="demo-hint">{{ t('pulse', 'only to check the display — real votes stay') }}</span>
							<span class="demo-spacer" />
							<label class="demo-lbl">{{ t('pulse', 'Number') }}
								<input v-model.number="demoCount" class="pinput pinput--num demo-count" type="number" min="1" max="200" step="5" :aria-label="t('pulse', 'Number of demo votes')">
							</label>
							<button class="pulse-btn is-secondary is-sm" :disabled="demoBusy" @click="seedDemo"><PulseIcon name="plus" size="1em" /> {{ t('pulse', 'Create votes') }}</button>
							<button class="pulse-btn is-danger is-sm" :disabled="demoBusy" @click="clearDemo"><PulseIcon name="trash" size="1em" /> {{ t('pulse', 'Clear demo') }}</button>
						</div>
					</aside>
				</template>
			</div>

			<footer class="mod-bar">
				<button class="pulse-btn is-secondary" :disabled="!canPrev || (phase === 'leaderboard' && quizOver)" @click="prev"><PulseIcon name="chevron" size="1em" class="ic-flip" /> {{ t('pulse', 'Back') }}</button>
				<!-- Der einzige gefüllte Knopf auf dem Schirm, und er weiß, was
				     gerade dran ist (§9.1). Destruktiv eingefärbt erst am Endstand. -->
				<button class="pulse-btn mod-primary is-lg" :class="primary.danger ? 'is-danger is-solid' : 'is-primary'" :disabled="!online || busy" :title="online ? '' : t('pulse', 'No connection to the room')" @click="runPrimary">
					<PulseIcon :name="primary.icon" size="1.05em" /> {{ primary.label }}
				</button>
				<button v-if="canNext && primary.key !== 'next' && !(phase === 'leaderboard' && quizOver)" class="pulse-btn is-secondary" @click="next">{{ t('pulse', 'Next') }} <PulseIcon name="chevron" size="1em" /></button>
				<span v-if="advanceIn !== null" class="pulse-chip is-accent auto-next" role="status"><PulseIcon name="timer" size="1em" /> {{ advanceLabel }} {{ t('pulse', 'in {seconds} s', { seconds: advanceIn }) }}</span>
				<button v-if="advanceIn !== null" class="pulse-btn is-secondary is-sm" @click="cancelAdvance">{{ t('pulse', 'Stop') }}</button>
				<span class="mod-spacer" />
				<!-- Die Zahl steht neben ihrem Bezug, nicht allein im Leeren (D20):
				     derselbe Baustein wie am Beamer, nur klein. -->
				<IntakeBoard v-if="phase === 'present' && currentPoll" class="mod-intake" :answered="resultsTotal" :present="presentCount" :compact="true" />
			</footer>

			<!-- Beitritt ist ein Zustand, keine Spalte (D20): offen, solange
			     niemand verbunden ist — dann wird er gebraucht —, danach
			     einmalig eingeklappt und über den Knopf in der Kopfzeile
			     erreichbar. Der Verweis steht nur hier, nicht dauerhaft. -->
			<div v-if="joinOpen" class="mod-joinwrap" @click.self="joinOpen = false">
				<aside class="mod-joinpanel" role="dialog" :aria-label="t('pulse', 'Join in')">
					<div class="mod-join-head">
						<h2>{{ t('pulse', 'Join in') }}</h2>
						<button class="pulse-btn is-secondary is-icon is-sm" :title="t('pulse', 'Close')" :aria-label="t('pulse', 'Close')" @click="joinOpen = false"><PulseIcon name="close" size="1.05em" /></button>
					</div>
					<div class="qr-card mod-join-qr"><QrCode :value="joinUrlFull" /></div>
					<span class="join-hint">{{ t('pulse', 'Join in — code at') }} <b>{{ joinBase }}</b></span>
					<span class="join-code">{{ spacedCode }}</span>
					<div class="join-link">
						<a class="join-url" :href="joinPath" target="_blank" rel="noopener">{{ joinUrl }}</a>
						<button class="pulse-btn is-secondary is-icon is-xs join-copy" :title="t('pulse', 'Copy join link')" :aria-label="t('pulse', 'Copy join link')" @click="copyJoin"><PulseIcon name="copy" size="1em" /></button>
					</div>
				</aside>
			</div>
		</section>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t, n } from './util/l10n.js'
import { showError, showSuccess } from './toast.js'
import draggable from 'vuedraggable'
import ResultsView from './components/ResultsView.vue'
import QrCode from './components/QrCode.vue'
import Leaderboard from './components/Leaderboard.vue'
import TextGrading from './components/TextGrading.vue'
import PulseIcon from './components/ui/PulseIcon.vue'
import PulseSegmented from './components/ui/PulseSegmented.vue'
import PulseMenu from './components/ui/PulseMenu.vue'
import CountdownRing from './components/CountdownRing.vue'
import IntakeBoard from './components/IntakeBoard.vue'
import { pulseConfirm } from './util/confirm.js'
import { formatCode, remainingSecs, paceStateChip } from './util/format.js'
import { csvUrl } from './util/csv.js'
import { PACE_UI, isPacedRoom, windowState } from './util/pace.js'
import pollingMixin from './mixins/polling.js'
import { progressCounts } from './mixins/progress-poll.js'
import PaceOpenDialog from './components/PaceOpenDialog.vue'
import PaceDeckStatus from './components/PaceDeckStatus.vue'
import PaceRun from './components/PaceRun.vue'

// Pro Fragetyp an EINER Stelle: Deserialisierung (poll -> draft) und
// Serialisierung+Validierung (draft -> body). Hält die zwei Composer-Seiten
// synchron. toBody meldet Fehler über fail(msg); der Aufrufer bricht dann ab.
function keptOptions(draft, body, fail) {
	const kept = []
	draft.options.forEach((o, i) => { if (o.trim() !== '') kept.push(i) })
	if (kept.length < 2) { fail(t('pulse', 'Please provide at least two options.')); return null }
	body.options = kept.map((i) => draft.options[i].trim())
	return kept
}
const QUESTION_TYPES = {
	choice: {
		toDraft(poll, key, d) {
			d.options = (poll.options || []).map((o) => o.label)
			const ci = poll.correctOption ? (poll.options || []).findIndex((o) => o.id === poll.correctOption) : 0
			d.correctIndex = ci >= 0 ? ci : 0
		},
		toBody(draft, body, isQuiz, fail) {
			if (!isQuiz) {
				body.options = draft.options.map((o) => o.trim()).filter(Boolean)
				if (body.options.length < 2) fail(t('pulse', 'Please provide at least two options.'))
				return
			}
			const kept = keptOptions(draft, body, fail)
			if (!kept) return
			const ci = kept.indexOf(draft.correctIndex)
			if (ci < 0) { fail(t('pulse', 'Please mark the correct answer.')); return }
			body.correctIndex = ci
		},
	},
	rank: {
		toDraft(poll, key, d) {
			// Die gespeicherte Optionsreihenfolge IST im Quiz die Lösung.
			d.options = (poll.options || []).map((o) => o.label)
		},
		toBody(draft, body, isQuiz, fail) {
			body.options = draft.options.map((o) => o.trim()).filter(Boolean)
			if (body.options.length < 2) fail(t('pulse', 'Please provide at least two answers.'))
		},
	},
	match: {
		toDraft(poll, key, d) {
			const match = poll.match || {}
			const targets = match.targets || []
			const labelById = {}
			for (const target of targets) labelById[target.id] = target.label
			const map = key.map || {}
			// Quiz: die rechte Spalte kommt aus der Lösung. Umfrage: dort gibt es
			// keine — die Paarung ist dann die Eingabefolge (Item i zu Ziel i).
			d.pairs = (match.items || []).map((item, i) => ({
				left: item.label,
				right: labelById[map[item.id]] !== undefined ? labelById[map[item.id]] : ((targets[i] || {}).label || ''),
			}))
			while (d.pairs.length < 2) d.pairs.push({ left: '', right: '' })
		},
		toBody(draft, body, isQuiz, fail) {
			const pairs = draft.pairs
				.map((pair) => ({ left: (pair.left || '').trim(), right: (pair.right || '').trim() }))
				.filter((pair) => pair.left !== '' || pair.right !== '')
			if (pairs.some((pair) => pair.left === '' || pair.right === '')) {
				fail(t('pulse', 'Please fill in both sides of every pair.'))
				return
			}
			if (pairs.length < 2) {
				fail(t('pulse', 'Please provide at least two pairs.'))
				return
			}
			body.pairs = pairs
		},
	},
	multi: {
		toDraft(poll, key, d) {
			d.options = (poll.options || []).map((o) => o.label)
			const correct = key.correct || []
			d.correctIndexes = (poll.options || []).map((o, i) => (correct.includes(o.id) ? i : -1)).filter((i) => i >= 0)
		},
		toBody(draft, body, isQuiz, fail) {
			const kept = keptOptions(draft, body, fail)
			if (!kept) return
			const idx = kept.map((orig, pos) => (draft.correctIndexes.includes(orig) ? pos : -1)).filter((pp) => pp >= 0)
			if (!idx.length) { fail(t('pulse', 'Please mark at least one correct answer.')); return }
			body.correctIndexes = idx
		},
	},
	truefalse: {
		toDraft(poll, key, d) {
			const ci = poll.correctOption ? (poll.options || []).findIndex((o) => o.id === poll.correctOption) : 0
			d.correctIndex = ci >= 0 ? ci : 0
		},
		toBody(draft, body) {
			body.correctIndex = draft.correctIndex
		},
	},
	number: {
		toDraft(poll, key, d) {
			d.target = key.target !== undefined ? key.target : ''
			d.tolerance = key.tolerance !== undefined ? key.tolerance : 0
		},
		toBody(draft, body, isQuiz, fail) {
			if (draft.target === '' || draft.target === null || isNaN(Number(draft.target))) { fail(t('pulse', 'Please enter a target number.')); return }
			body.target = Number(draft.target)
			body.tolerance = draft.tolerance === '' ? 0 : Math.abs(Number(draft.tolerance)) || 0
		},
	},
	text: {
		toDraft(poll, key, d) {
			d.answers = (key.accepted && key.accepted.length) ? key.accepted.slice() : ['']
		},
		toBody(draft, body, isQuiz, fail) {
			body.answers = draft.answers.map((a) => a.trim()).filter(Boolean)
			if (!body.answers.length) fail(t('pulse', 'Please provide at least one correct answer.'))
		},
	},
	scale: {
		toDraft(poll, key, d) {
			const sc = poll.scale || {}
			d.scaleMode = ['spectrum', 'compass'].includes(sc.mode) ? sc.mode : 'single'
			d.scaleMax = sc.max || 5
			d.minLabel = sc.minLabel || ''
			d.maxLabel = sc.maxLabel || ''
			if (sc.mode === 'spectrum' && Array.isArray(sc.aspects) && sc.aspects.length) {
				d.aspects = sc.aspects.map((a) => ({ label: a.label || '', poleLow: a.poleLow || '', poleHigh: a.poleHigh || '' }))
			}
			if (sc.mode === 'compass') {
				d.range = sc.range || 5
				const ax = (a) => ({ title: (a || {}).title || '', poleLow: (a || {}).poleLow || '', poleHigh: (a || {}).poleHigh || '' })
				d.axisX = ax(sc.axisX)
				d.axisY = ax(sc.axisY)
				const cl = Array.isArray(sc.cornerLabels) ? sc.cornerLabels : []
				d.cornerLabels = [cl[0] || '', cl[1] || '', cl[2] || '', cl[3] || '']
				d.heatmapThreshold = sc.heatmapThreshold || 45
			}
		},
		toBody(draft, body, isQuiz, fail) {
			body.scaleMode = draft.scaleMode
			body.scaleMax = draft.scaleMax
			if (draft.scaleMode === 'spectrum') {
				const aspects = draft.aspects
					.map((a) => ({ label: (a.label || '').trim(), poleLow: (a.poleLow || '').trim(), poleHigh: (a.poleHigh || '').trim() }))
					.filter((a) => a.label !== '')
				if (aspects.length < 3) { fail(t('pulse', 'Please name at least three aspects.')); return }
				body.aspects = aspects.slice(0, 8)
			} else if (draft.scaleMode === 'compass') {
				const ax = (a) => ({ title: (a.title || '').trim(), poleLow: (a.poleLow || '').trim(), poleHigh: (a.poleHigh || '').trim() })
				const axisX = ax(draft.axisX)
				const axisY = ax(draft.axisY)
				if (!axisX.title || !axisX.poleLow || !axisX.poleHigh || !axisY.title || !axisY.poleLow || !axisY.poleHigh) {
					fail(t('pulse', 'Please give both axes a title and two pole labels each.')); return
				}
				body.range = draft.range
				body.axisX = axisX
				body.axisY = axisY
				body.cornerLabels = draft.cornerLabels.map((c) => (c || '').trim())
				body.heatmapThreshold = draft.heatmapThreshold || 45
			} else {
				body.minLabel = draft.minLabel
				body.maxLabel = draft.maxLabel
			}
		},
	},
	words: {
		toDraft(poll, key, d) {
			d.maxWords = poll.maxWords || 3
		},
		toBody(draft, body) {
			body.maxWords = draft.maxWords
		},
	},
}

export default {
	name: 'Moderator',
	mixins: [pollingMixin],
	components: { ResultsView, QrCode, Leaderboard, TextGrading, PulseIcon, PulseSegmented, PulseMenu, CountdownRing, IntakeBoard, draggable, PaceOpenDialog, PaceDeckStatus, PaceRun },
	data() {
		return {
			phase: 'start', // 'start' | 'deck' | 'present' | 'summary' | 'leaderboard' | 'pace' (Laufansicht, eigenes Tempo)
			startMode: 'poll', // Modus-Segmented auf dem Startbildschirm (§4.1)
			room: null,
			myRooms: [],
			summary: [],
			leaderboard: [],
			deck: [],
			currentId: 0,
			showComposer: false,
			editingId: 0,
			results: null,
			presentCount: 0,
			demoMode: /(?:\?|&)demo=/.test(window.location.search),
			demoCount: 25,
			demoBusy: false,
			online: true,
			busy: false,
			isFullscreen: false,
			joinOpen: false,   // Beitritts-Panel — ein Zustand, keine Spalte (§9.4)
			frameScale: 0.5,   // Maßstab der Leinwand-Vorschau (1280×720 -> Kasten)
			tickTimer: null,
			advanceTimer: null,  // Auto-Weiterschalten nach Timer-Ende
			advanceAtSec: 0,     // lokale Zielsekunde (0 = nichts geplant)
			polling: false,
			resVersion: '',
			serverSkew: 0,
			nowSec: Math.floor(Date.now() / 1000),
			renaming: false,   // Deck-Kopf: Inline-Umbenennen offen?
			renameDraft: '',
			draft: this.emptyDraft(),
			// ── Quiz im eigenen Tempo ──
			paceDialog: null,    // null | 'open' — Öffnen-Dialog im Deck
			paceFocus: false,    // nächste Laufansicht setzt den Fokus auf ihren Titel
			paceCounts: null,    // letzter Zählstand aus PaceDeckStatus (Bestätigungstexte)
			paceSkew: 0,         // Serverzeit − Laptopzeit in s (aus /progress)
			paceBusy: false,     // Umschalten läuft (zählen, bestätigen, senden)
			summaryFrom: 'deck', // woher die Zusammenfassung geöffnet wurde ('deck' | 'pace')
		}
	},
	computed: {
		// Frei gewählter Raumname; leer = die Oberfläche fällt auf „Dein Deck/Quiz" zurück.
		roomTitle() {
			return (this.room && this.room.title) || ''
		},
		isQuiz() {
			return !!this.room && this.room.mode === 'quiz'
		},
		// Start-Segmented: Umfrage vs. Quiz.
		modeOptions() {
			return [
				{ value: 'poll', label: t('pulse', 'Poll'), icon: 'poll' },
				{ value: 'quiz', label: t('pulse', 'Quiz'), icon: 'quiz' },
			]
		},
		// Composer-Segmented für den Skala-Modus (Umfrage): Einzel vs. Spektrum.
		scaleModeOptions() {
			return [
				{ value: 'single', label: t('pulse', 'Single') },
				{ value: 'spectrum', label: t('pulse', 'Spectrum') },
				{ value: 'compass', label: t('pulse', 'Compass') },
			]
		},
		// Composer-Segmented: Fragetypen je Modus (§4.3, wrap-fest).
		typeOptions() {
			return this.isQuiz
				? [
					{ value: 'choice', label: t('pulse', 'Multiple choice') },
					{ value: 'truefalse', label: t('pulse', 'True/False') },
					{ value: 'multi', label: t('pulse', 'Multiple answers') },
					{ value: 'number', label: t('pulse', 'Number guess') },
					{ value: 'text', label: t('pulse', 'Free text') },
					{ value: 'rank', label: t('pulse', 'Ranking') },
					{ value: 'match', label: t('pulse', 'Matching') },
				]
				: [
					{ value: 'choice', label: t('pulse', 'Multiple choice') },
					{ value: 'words', label: t('pulse', 'Word cloud') },
					{ value: 'scale', label: t('pulse', 'Scale') },
					{ value: 'rank', label: t('pulse', 'Ranking') },
					{ value: 'match', label: t('pulse', 'Matching') },
				]
		},
		isPractice() {
			return !!this.room && !!this.room.practice
		},
		isRevealAtEnd() {
			return !!this.room && !!this.room.revealAtEnd
		},
		// ── Quiz im eigenen Tempo (Stufe 4) ─────────────────────────────────
		// Jeder neue Zweig hängt hieran; moderiert bleibt alles wie gehabt.
		isPaced() {
			return isPacedRoom(this.room)
		},
		// Fensterzustand gegen die Serverzeit: eine Frist, die gerade abläuft,
		// schließt das Deck sofort — der nächste Abruf bestätigt es nur.
		// Moderiert '' (und dann ohne Abhängigkeit vom Sekundentakt).
		paceState() {
			return this.isPaced ? windowState(this.room.window, this.nowSec + this.paceSkew) : ''
		},
		// Ab dem Öffnen bis zum Zurücksetzen: keine Fragen anlegen, bearbeiten,
		// löschen oder sortieren (der Server sagt sonst 409).
		deckLocked() {
			return this.isPaced && !!this.paceState && this.paceState !== 'draft'
		},
		// Zustands-Chip im Deck-Kopf — im Entwurf keiner.
		deckStateChip() {
			return this.deckLocked ? this.paceChip(this.room.window) : null
		},
		// „· 30s ·" je Zeile nur, wenn die Zeit auch gilt (offen ohne Timer: nein).
		showRowLimit() {
			return !this.isPaced || this.paceState === 'draft' || !!(this.room.window && this.room.window.timed)
		},
		spacedCode() {
			return this.room ? formatCode(this.room.code) : ''
		},
		joinPath() {
			return this.room ? generateUrl('/apps/pulse/s/' + this.room.code) : ''
		},
		joinUrl() {
			return this.room ? window.location.host + this.joinPath : ''
		},
		joinUrlFull() {
			return this.room ? window.location.origin + this.joinPath : ''
		},
		screenUrl() {
			return this.room ? window.location.origin + generateUrl('/apps/pulse/screen/' + this.room.code) : ''
		},
		joinBase() {
			return window.location.host + generateUrl('/apps/pulse/join')
		},
		joinPagePath() {
			return generateUrl('/apps/pulse/join')
		},
		// Mit Requesttoken in der Query: ein <a download> schickt keinen Header,
		// ohne Token antwortete die Route mit 412 (util/csv.js).
		exportUrl() {
			return this.room ? csvUrl(this.room.code) : ''
		},
		// Aus der Laufansicht geöffnet: dorthin geht es zurück, nicht ins Deck.
		summaryToPace() {
			return this.isPaced && this.summaryFrom === 'pace'
		},
		currentIndex() {
			return this.deck.findIndex((p) => p.id === this.currentId)
		},
		currentPoll() {
			return this.currentIndex >= 0 ? this.deck[this.currentIndex] : null
		},
		canPrev() {
			return this.currentIndex > 0
		},
		canNext() {
			return this.currentIndex >= 0 && this.currentIndex < this.deck.length - 1
		},
		resultsTotal() {
			return this.results ? this.results.total : 0
		},
		// ── Quiz ────────────────────────────────────────────────────────────
		quizStatus() {
			return this.currentPoll ? this.currentPoll.status : 'active'
		},
		quizRemaining() {
			return this.isQuiz ? remainingSecs(this.results, this.nowSec, this.serverSkew) : null
		},
		// Sekunden bis zum automatischen Weiterschalten (null = nichts geplant).
		advanceIn() {
			return this.advanceAtSec ? Math.max(0, this.advanceAtSec - this.nowSec) : null
		},
		// Was autoAdvance gleich tut — nach der letzten Frage im Probelauf gibt
		// es keinen Endstand, nur (bei „am Ende") die Auflösung.
		advanceLabel() {
			if (this.canNext) return t('pulse', 'Next question')
			return this.isPractice ? t('pulse', 'Reveal') : t('pulse', 'Final standings')
		},

		// ── Präsentationsansicht (§9) ───────────────────────────────────────
		// Präsentation, Endstand und die Laufansicht im eigenen Tempo teilen sich
		// einen Rahmen: volle Höhe, eigene Kopf- und Fußzeile, kein Seitenrand
		// von .pulse-mod.
		liveFrame() {
			return this.phase === 'present' || this.phase === 'leaderboard' || this.phase === 'pace'
		},
		// Aufgelöst = der Server hat die Frage freigegeben. Dieselbe Regel wie
		// StateService::publicState — nicht ein zweites, lokales „schon gezeigt".
		// Bei „Auflösung am Ende" zählt nur 'ended': ein 'locked' entsteht dort
		// nur, wenn der Schalter nach dem Auflösen umgelegt wurde, und ist für
		// den Saal verdeckt.
		isRevealed() {
			if (!this.currentPoll) return false
			const st = this.currentPoll.status
			return this.isQuiz && this.isRevealAtEnd ? st === 'ended' : st === 'locked' || st === 'ended'
		},
		showCountdown() {
			return this.isQuiz && !this.isRevealed && this.quizRemaining !== null
		},
		headPos() {
			// Endstand erst, wenn das Quiz beendet ist — mittendrin ist es ein
			// Zwischenstand, den nur der Moderator sieht.
			if (this.phase === 'leaderboard') return this.quizOver ? t('pulse', 'Final standings') : t('pulse', 'Leaderboard')
			if (!this.currentPoll) return t('pulse', 'No question yet')
			return t('pulse', 'Question {number} of {total}', { number: this.currentIndex + 1, total: this.deck.length })
		},
		// Die Leinwand-Vorschau zeigt die echte Beamer-Seite (same-origin), nicht
		// einen Nachbau: zwei Wahrheiten laufen sonst auseinander.
		screenPath() {
			return this.room ? generateUrl('/apps/pulse/screen/' + this.room.code) : ''
		},
		canvasStyle() {
			return { width: Math.round(1280 * this.frameScale) + 'px', height: Math.round(720 * this.frameScale) + 'px' }
		},
		frameStyle() {
			return { transform: 'scale(' + this.frameScale + ')' }
		},
		/*
		 * DIE Hauptaktion (§9.1) — der einzige gefüllte Knopf, und er weiß, was
		 * gerade dran ist. Vorher hatte die häufigste Handlung überhaupt keinen
		 * Knopf, während der destruktive dauerhaft leuchtete.
		 */
		primary() {
			if (this.phase === 'leaderboard') {
				// Ohne laufende Frage gibt es nichts zu beenden (/end liefe ins Leere).
				return this.quizOver || !this.currentPoll
					? { key: 'deck', label: t('pulse', 'Back to the deck'), icon: 'chevron', act: this.backToDeck }
					: { key: 'end', label: t('pulse', 'Finish quiz'), icon: 'check', act: this.finish, danger: true }
			}
			if (!this.currentPoll) {
				return { key: 'add', label: t('pulse', 'Add a question'), icon: 'plus', act: this.addQuestion }
			}
			// „Auflösung am Ende": je Frage wird nicht aufgelöst, es geht nur weiter.
			if (this.isQuiz && this.isRevealAtEnd) {
				if (this.canNext) return { key: 'next', label: t('pulse', 'Next question'), icon: 'play', act: this.next }
				// Probelauf: auflösen ja — dafür ist er da, man prüft die Fragen —,
				// Endstand nein. Danach beendet derselbe Knopf den Lauf.
				if (this.isPractice) {
					return this.isRevealed
						? { key: 'finish', label: t('pulse', 'Finish'), icon: 'check', act: this.finish }
						: { key: 'reveal', label: t('pulse', 'Reveal'), icon: 'eye', act: this.revealLast }
				}
				return { key: 'end', label: t('pulse', 'Reveal & final standings'), icon: 'ranking', act: this.finish }
			}
			if (!this.isRevealed) {
				return { key: 'reveal', label: t('pulse', 'Reveal'), icon: 'eye', act: () => this.setLock(true) }
			}
			if (this.canNext) {
				return { key: 'next', label: t('pulse', 'Next question'), icon: 'play', act: this.next }
			}
			if (this.isQuiz && !this.isPractice) {
				// finish, nicht openLeaderboard: der Endstand soll mit diesem einen
				// Klick auf Beamer und Handys stehen, nicht nur hier.
				return { key: 'standings', label: t('pulse', 'Show final standings'), icon: 'ranking', act: this.finish }
			}
			return { key: 'finish', label: t('pulse', 'Finish'), icon: 'check', act: this.finish }
		},
		// Quiz vorbei = die letzte Frage steht auf 'ended' (das setzt /end).
		quizOver() {
			return this.deck.some((p) => p.status === 'ended')
		},
		/*
		 * Deck-Kopf: eine Zeile, drei Bedienelemente. Vorher standen hier neun
		 * Knöpfe in drei fest verdrahteten Gruppen — Navigation, Zustandsschalter
		 * und Ansichten gleich laut nebeneinander, bei jeder Breite (Handoff
		 * „Übersichten" B1).
		 *
		 * Die beiden Quiz-Schalter sind hier KEINE Knöpfe mehr, deren Beschriftung
		 * mitwandert: als `menuitemcheckbox` steht der Text fest und das Häkchen
		 * trägt den Zustand (R3). „Practice run off" war sonst genauso gut als
		 * Zustand wie als Wirkung zu lesen — und die falsche Lesart kostet den
		 * echten Durchlauf.
		 */
		deckMenu() {
			const items = []
			const paced = this.isPaced
			items.push({ key: 'fs', label: this.isFullscreen ? t('pulse', 'Exit full screen') : t('pulse', 'Full screen'), icon: 'fullscreen', act: this.toggleFullscreen })
			if (this.room) {
				items.push({ key: 'beamer', label: t('pulse', 'Projector'), icon: 'beamer', act: this.openBeamer })
				items.push({ key: 'addin', label: t('pulse', 'PowerPoint add-in'), icon: 'copy', act: this.downloadAddin })
			}
			if (this.deck.length) {
				items.push({ key: 'summary', label: t('pulse', 'Summary'), icon: 'poll', act: this.openSummary })
			}
			// Im eigenen Tempo gibt es weder eine Zwischenrangliste zum Zeigen noch
			// ein „Auflösen am Ende" — das Fenster regelt beides.
			if (this.isQuiz && !this.isPractice && this.deck.length && !paced) {
				items.push({ key: 'lb', label: this.quizOver ? t('pulse', 'Final standings') : t('pulse', 'Leaderboard'), icon: 'ranking', act: this.openLeaderboard })
			}
			if (this.isQuiz) {
				if (!paced) {
					items.push({ key: 'revealend', label: t('pulse', 'Reveal at the end'), icon: 'check_ring', checked: this.isRevealAtEnd, act: this.toggleRevealAtEnd })
				}
				// Probelauf beenden geht auch geschlossen (dabei gehen nur Testdaten verloren).
				items.push({ key: 'practice', label: t('pulse', 'Practice run'), icon: 'warning', checked: this.isPractice, act: this.togglePractice, ...this.paceLock(!this.isPractice) })
				// PACE_UI ist seit der Freischaltung (4.6) an; bis dahin brauchte der
				// Schalter ?pace=1. Ein Raum im eigenen Tempo zeigt ihn in jedem Fall.
				if (PACE_UI || paced) {
					items.push({ key: 'pace', label: t('pulse', 'Self-paced'), checked: paced, act: this.togglePace, ...this.paceLock(true) })
				}
			}
			if (paced && this.paceState !== 'released') {
				items.push({ key: 'lock', label: t('pulse', 'Lock joining'), checked: !!this.room.joinsLocked, act: this.toggleJoinsLocked })
			}
			if (this.deck.length) {
				items.push({ key: 'reset', label: paced ? t('pulse', 'Reset quiz') : t('pulse', 'Reset votes'), icon: 'reset', act: this.resetRoom, danger: true, ...this.paceLock(false) })
			}
			return items
		},
		/*
		 * DIE Hauptaktion im Deck-Kopf. Moderiert „Start presenting"; im eigenen
		 * Tempo „Open quiz …" (Entwurf) — die Präsentation ist dort 409. Nach
		 * dem Öffnen führt „Show progress" zurück in die Laufansicht. Leeres
		 * Deck: keine.
		 */
		deckPrimary() {
			if (!this.deck.length) return null
			if (!this.isPaced) return { key: 'present', label: t('pulse', 'Start presenting'), icon: 'play', act: this.startPresenting }
			if (this.paceState === 'draft') return { key: 'open', label: t('pulse', 'Open quiz …'), icon: 'play', act: this.openPaceDialog }
			return { key: 'progress', label: t('pulse', 'Show progress'), icon: 'users', act: this.showProgress }
		},
		// Die Laufansicht gehört zu einem geöffneten Raum im eigenen Tempo. Steht
		// sie noch, während der Raum zurückgesetzt (Entwurf), umgestellt oder aus
		// einem zweiten Tab verändert wurde, geht es zurück ins Deck.
		paceRunStale() {
			return this.phase === 'pace' && (!this.isPaced || this.paceState === 'draft')
		},
		// Steht ein Formular offen, gehört ihm der gefüllte Knopf (R1).
		deckQuiet() {
			return this.showComposer || this.renaming || !!this.paceDialog
		},
		/*
		 * Überlaufmenü: alles, was ein- bis zweimal je Sitzung gebraucht wird.
		 * „Beenden" steht hier unten, solange das Quiz läuft — ein rot gefärbter
		 * Knopf neben der Arbeitsfläche wird über 90 Minuten entweder ignoriert
		 * oder versehentlich getroffen.
		 */
		menuItems() {
			const items = []
			// Wieder öffnen: im Quiz startet das den Timer neu (setCurrent), in der
			// Umfrage genügt das Entriegeln — setCurrent rührt dort den Status nicht an.
			if (this.phase === 'present' && this.isRevealed && !this.isRevealAtEnd) {
				items.push({ key: 'reopen', label: t('pulse', 'Open again'), icon: 'reset',
					act: this.isQuiz ? this.reopen : () => this.setLock(false) })
			}
			if (this.phase === 'present' && this.currentPoll) {
				items.push({ key: 'reset', label: t('pulse', 'Reset this question'), icon: 'reset', act: this.resetCurrent })
			}
			items.push({ key: 'fs', label: this.isFullscreen ? t('pulse', 'Exit full screen') : t('pulse', 'Full screen'), icon: 'fullscreen', act: this.toggleFullscreen })
			items.push({ key: 'beamer', label: t('pulse', 'Projector'), icon: 'beamer', act: this.openBeamer })
			if (this.isQuiz && !this.isPractice && this.phase !== 'leaderboard') {
				items.push({ key: 'lb', label: t('pulse', 'Leaderboard'), icon: 'ranking', act: this.openLeaderboard })
			}
			items.push({ key: 'summary', label: t('pulse', 'Summary'), icon: 'poll', act: this.openSummary })
			items.push({ key: 'deck', label: t('pulse', 'Overview'), icon: 'edit', act: this.backToDeck })
			items.push({ key: 'home', label: t('pulse', 'My rooms'), icon: 'chevron', act: this.goHome })
			if (this.phase === 'present') {
				items.push({ key: 'finish', label: this.isQuiz ? t('pulse', 'Finish quiz') : t('pulse', 'Finish'), icon: 'close', act: this.finish, danger: true })
			}
			return items
		},
	},
	watch: {
		// Der Maßstab der Vorschau hängt am Kasten, der Kasten an der Phase.
		phase(v) {
			this.$nextTick(this.fitFrame)
			if (v !== 'pace') this.paceFocus = false
		},
		// Raum nicht mehr im eigenen Tempo (umgeschaltet, anderer Tab): alte
		// Zahlen und ein offener Dialog gehören zum vorigen Zustand.
		isPaced(v) {
			if (!v) {
				this.paceCounts = null
				this.paceDialog = null
			}
		},
		paceRunStale(v) {
			if (v) this.phase = 'deck'
		},
		// Timer-Ende (Übergang auf 0, genau einmal) treibt das Quiz von selbst
		// weiter — kein manuelles Klicken mehr nötig:
		//  · Auflösung je Frage: erst auflösen (Antwort + Rangliste), kurz zeigen,
		//    dann automatisch zur nächsten Frage (bzw. Endstand nach der letzten).
		//  · Auflösung am Ende: kurze Atempause, dann direkt weiter.
		// Der geplante Sprung ist per „Anhalten" abbrechbar (advanceIn/cancelAdvance).
		quizRemaining(val, old) {
			if (!(this.isQuiz && val === 0 && old > 0 && this.phase === 'present' && this.currentId)) {
				return
			}
			if (this.isRevealAtEnd) {
				// Schon aufgelöst (Probelauf: „Auflösen" vor Ablauf geklickt) -> nichts
				// mehr zu tun; sonst beendete der Timer den Lauf ungefragt.
				if (!this.isRevealed) this.scheduleAdvance(2)
			} else if (this.quizStatus !== 'locked') {
				this.setLock(true)
				// Freitext braucht manuelle Bewertung -> nicht automatisch weiter.
				// Probelauf nach der letzten Frage: es gibt keinen Endstand, den
				// Lauf beendet der Moderator selbst („Beenden").
				const text = !!this.currentPoll && this.currentPoll.type === 'text'
				if (!text && (this.canNext || !this.isPractice)) this.scheduleAdvance(8)
			}
		},
	},
	created() {
		// Absichtlich außerhalb von data(): Vue soll den Beobachter nicht in einen
		// reaktiven Proxy hüllen.
		this.canvasRo = null
		this.canvasWatched = null
	},
	async mounted() {
		document.addEventListener('fullscreenchange', this.onFsChange)
		document.addEventListener('visibilitychange', this.onPollVisibility)
		document.addEventListener('keydown', this.onEscape)
		window.addEventListener('resize', this.fitFrame)
		this.observeCanvas()
		this.tickTimer = setInterval(() => { this.nowSec = Math.floor(Date.now() / 1000) }, 500)
		const m = window.location.pathname.match(/\/room\/([A-Za-z0-9]{6})$/)
		if (m) {
			// Deep-Link/Refresh -> Raum laden; scheitert er, normaler Start + Liste.
			const ok = await this.loadRoom(m[1])
			if (!ok) this.fetchMyRooms()
		} else {
			this.fetchMyRooms()
		}
	},
	updated() {
		this.observeCanvas()
	},
	beforeDestroy() {
		this.pollStopped = true
		this.stopPolling()
		if (this.tickTimer) clearInterval(this.tickTimer)
		document.removeEventListener('fullscreenchange', this.onFsChange)
		document.removeEventListener('visibilitychange', this.onPollVisibility)
		document.removeEventListener('keydown', this.onEscape)
		window.removeEventListener('resize', this.fitFrame)
		if (this.canvasRo) this.canvasRo.disconnect()
	},
	methods: {
		emptyDraft() {
			return { type: 'choice', question: '', options: ['', ''], maxWords: 3, scaleMax: 5, scaleMode: 'single', aspects: [{ label: '', poleLow: '', poleHigh: '' }, { label: '', poleLow: '', poleHigh: '' }, { label: '', poleLow: '', poleHigh: '' }], range: 5, axisX: { title: '', poleLow: '', poleHigh: '' }, axisY: { title: '', poleLow: '', poleHigh: '' }, cornerLabels: ['', '', '', ''], heatmapThreshold: 45, minLabel: '', maxLabel: '', imageFile: null, imagePreview: '', imageExisting: '', imageRemove: false, correctIndex: 0, correctIndexes: [], target: '', tolerance: 0, answers: [''], pairs: [{ left: '', right: '' }, { left: '', right: '' }], timeLimit: 30 }
		},
		// Kurz-Tag + Farbklasse fürs Deck-Icon (SK = Skala-Familie in „Du"-Magenta).
		typeTag(type) {
			return ({
				choice: { t: t('pulse', 'MC'), c: 'mc' }, words: { t: t('pulse', 'WC'), c: 'wc' }, scale: { t: t('pulse', 'SC'), c: 'sc' },
				truefalse: { t: t('pulse', 'TF'), c: 'wf' }, multi: { t: t('pulse', 'MA'), c: 'ma' }, number: { t: '#', c: 'nu' }, text: { t: t('pulse', 'Tx'), c: 'tx' },
				rank: { t: '1-2-3', c: 'rk' }, match: { t: t('pulse', 'MT'), c: 'mt' },
			})[type] || { t: t('pulse', 'MC'), c: 'mc' }
		},
		// Skala-Submodus fürs Deck-Untertitel.
		scaleModeLabel(p) {
			const m = (p.scale && p.scale.mode) || 'single'
			return m === 'spectrum' ? t('pulse', 'Spectrum') : m === 'compass' ? t('pulse', 'Compass') : t('pulse', 'Single')
		},
		typeLabel(type) {
			return {
				words: t('pulse', 'Word cloud'), scale: t('pulse', 'Scale'), truefalse: t('pulse', 'True/False'), rank: t('pulse', 'Ranking'), match: t('pulse', 'Matching'),
				multi: t('pulse', 'Multiple answers'), number: t('pulse', 'Number guess'), text: t('pulse', 'Free text'),
			}[type] || t('pulse', 'Multiple choice')
		},
		// Kurzer Antwort-Hinweis je Quiz-Frage in der Deck-Liste.
		answerHint(poll) {
			if (poll.type === 'truefalse') {
				const o = (poll.options || []).find((x) => x.id === poll.correctOption)
				return o ? o.label : '—'
			}
			if (poll.type === 'choice') return t('pulse', 'Answer {letter}', { letter: this.correctLetter(poll) })
			if (poll.type === 'multi') return n('pulse', '%n correct', '%n correct', ((poll.answerKey || {}).correct || []).length)
			if (poll.type === 'number') {
				const k = poll.answerKey || {}
				return '= ' + (k.target ?? '?') + (k.tolerance ? ' ±' + k.tolerance : '')
			}
			if (poll.type === 'text') return t('pulse', 'Free text')
			// Reihenfolge: die Lösung ist die Optionsreihenfolge — Zahl statt Aufzählung.
			if (poll.type === 'rank') return n('pulse', '%n in order', '%n in order', (poll.options || []).length)
			if (poll.type === 'match') return n('pulse', '%n pair', '%n pairs', ((poll.match || {}).items || []).length)
			return ''
		},
		// Composer: ist Option i als richtig markiert (choice=Radio, multi=Mehrfach)?
		isOptCorrect(i) {
			return this.draft.type === 'multi' ? this.draft.correctIndexes.includes(i) : this.draft.correctIndex === i
		},
		toggleCorrect(i) {
			if (this.draft.type === 'multi') {
				const at = this.draft.correctIndexes.indexOf(i)
				if (at >= 0) this.draft.correctIndexes.splice(at, 1)
				else this.draft.correctIndexes.push(i)
			} else {
				this.draft.correctIndex = i
			}
		},
		addPair() {
			if (this.draft.pairs.length < 8) this.draft.pairs.push({ left: '', right: '' })
		},
		removePair(i) {
			if (this.draft.pairs.length > 2) this.draft.pairs.splice(i, 1)
		},
		addAnswer() {
			if (this.draft.answers.length < 8) this.draft.answers.push('')
		},
		removeAnswer(i) {
			this.draft.answers.splice(i, 1)
		},
		addAspect() {
			if (this.draft.aspects.length < 8) this.draft.aspects.push({ label: '', poleLow: '', poleHigh: '' })
		},
		removeAspect(i) {
			if (this.draft.aspects.length > 3) this.draft.aspects.splice(i, 1)
		},
		// Quiz: Buchstabe (A, B, …) der richtigen Antwort einer Frage.
		correctLetter(poll) {
			if (!poll || !poll.options) return '—'
			const i = poll.options.findIndex((o) => o.id === poll.correctOption)
			return i >= 0 ? String.fromCharCode(65 + i) : '—'
		},
		api(code, suffix = '') {
			return generateUrl('/apps/pulse/api/1.0/rooms/' + code + suffix)
		},

		// ── Meine Räume ─────────────────────────────────────────────────────
		spaced(code) {
			return formatCode(code)
		},
		ago(ts) {
			const s = Math.max(0, Math.floor(Date.now() / 1000) - ts)
			if (s < 90) return t('pulse', 'just now')
			const m = Math.floor(s / 60)
			if (m < 60) return t('pulse', '{count} min ago', { count: m })
			const h = Math.floor(m / 60)
			if (h < 24) return t('pulse', '{count} h ago', { count: h })
			const d = Math.floor(h / 24)
			return n('pulse', '%n day ago', '%n days ago', d)
		},
		async fetchMyRooms() {
			try {
				const { data } = await axios.get(generateUrl('/apps/pulse/api/1.0/rooms'))
				this.myRooms = data
			} catch (e) {
				// keine Liste -> Start-Screen bleibt schlicht
			}
		},
		// Raum per Code laden und in Editor/Präsentation springen. Genutzt von
		// Deep-Link-Refresh und „Meine Räume".
		async loadRoom(code) {
			try {
				const { data } = await axios.get(this.api(code))
				this.room = data
				this.deck = data.polls || []
				this.currentId = data.activePollId || 0
				this.paceDialog = null
				this.paceCounts = null
				window.history.replaceState(null, '', generateUrl('/apps/pulse/room/' + code))
				// Eigenes Tempo: nie die Präsentation (jede Cursor-Aktion ist dort
				// 409), auch nicht bei leerem Deck — und kein Ergebnis-Polling: im
				// Entwurf holt PaceDeckStatus die Zahlen, danach die Laufansicht.
				if (isPacedRoom(data)) {
					this.phase = windowState(data.window) === 'draft' ? 'deck' : 'pace'
					return true
				}
				if (this.currentId !== 0) {
					this.phase = 'present'
					this.startPolling()
				} else if (!this.deck.length) {
					// §9.8: leeres Deck -> Präsentationsansicht mit „Frage anlegen".
					this.phase = 'present'
				} else {
					this.phase = 'deck'
				}
				return true
			} catch (e) {
				return false
			}
		},
		async openRoom(code) {
			if (!(await this.loadRoom(code))) {
				showError(t('pulse', 'Room not found — it may have been deleted.'))
				this.fetchMyRooms()
			}
		},
		// Beitritts-Deep-Link in die Zwischenablage (§5b). Fallback für ältere
		// Browser/kein Clipboard-API (z. B. http-Kontext): temporäres Textfeld.
		async copyJoin() {
			const url = this.joinUrlFull
			try {
				if (navigator.clipboard && window.isSecureContext) {
					await navigator.clipboard.writeText(url)
				} else {
					const ta = document.createElement('textarea')
					ta.value = url
					ta.style.position = 'fixed'
					ta.style.opacity = '0'
					document.body.appendChild(ta)
					ta.select()
					document.execCommand('copy')
					document.body.removeChild(ta)
				}
				showSuccess(t('pulse', 'Join link copied.'))
			} catch (e) {
				showError(t('pulse', 'Could not copy the link.'))
			}
		},
		// Raum als Vorlage kopieren: der Server legt die Kopie mit denselben
		// Fragen an (ohne Stimmen). Wir bleiben auf der Liste — die Kopie taucht
		// dort als neuester Eintrag auf.
		async duplicateRoom(r) {
			if (this.busy) return
			this.busy = true
			try {
				const { data } = await axios.post(this.api(r.code, '/duplicate'))
				await this.fetchMyRooms()
				showSuccess(t('pulse', 'Copy created: {room}', { room: data.title || this.spaced(data.code) }))
			} catch (e) {
				showError(t('pulse', 'Could not copy the room.'))
			} finally {
				this.busy = false
			}
		},
		async deleteRoom(r) {
			let text = t('pulse', 'Room {code} will be deleted, with all its questions and votes. This cannot be undone.', { code: this.spaced(r.code) })
			// Eigenes Tempo, offen oder geschlossen: vorher zählen, wer drin ist —
			// eine laufende Hausaufgabe wäre sonst mit einem Klick samt Antworten weg.
			const listed = r.pace === 'self' && r.window ? windowState(r.window, this.nowSec + this.paceSkew) : ''
			if (listed === 'open' || listed === 'closed') {
				const counts = await this.fetchCounts(r.code, true)
				const state = (counts && counts.state) || listed
				if (state === 'open' && counts && counts.joined > 0) {
					text += ' ' + n('pulse', 'The quiz is open and %n person has joined — their answers are lost too.', 'The quiz is open and %n people have joined — their answers are lost too.', counts.joined)
				} else if (state === 'closed') {
					const loss = this.lossText(counts, 'closed')
					if (loss) text += ' ' + loss
				}
			}
			if (!await pulseConfirm({
				title: t('pulse', 'Really delete this room?'),
				text,
				confirmLabel: t('pulse', 'Delete permanently'),
				cancelLabel: t('pulse', 'Cancel'),
				danger: true,
			})) return
			try {
				await axios.delete(this.api(r.code))
				this.myRooms = this.myRooms.filter((x) => x.code !== r.code)
			} catch (e) {
				showError(t('pulse', 'Could not delete the room.'))
			}
		},

		// ── Bild zur Frage ──────────────────────────────────────────────────
		// URL fürs Moderator-Bild; der Dateiname hängt als ?v= dran, damit ein
		// ersetztes Bild nicht aus dem Browser-Cache kommt.
		modImageUrl(poll) {
			if (!this.room || !poll || !poll.image) return ''
			return this.api(this.room.code, '/polls/' + poll.id + '/image') + '?v=' + encodeURIComponent(poll.image)
		},
		pickImage(ev) {
			const file = ev.target.files && ev.target.files[0]
			if (!file) return
			if (file.size > 5 * 1024 * 1024) { showError(t('pulse', 'Please choose an image of up to 5 MB.')); return }
			this.$set(this.draft, 'imageFile', file)
			this.$set(this.draft, 'imagePreview', URL.createObjectURL(file))
			this.$set(this.draft, 'imageRemove', false)
		},
		clearImage() {
			this.$set(this.draft, 'imageFile', null)
			this.$set(this.draft, 'imagePreview', '')
			// Ein bereits gespeichertes Bild wird erst beim Speichern gelöscht.
			this.$set(this.draft, 'imageRemove', !!this.draft.imageExisting)
			if (this.$refs.imageInput) this.$refs.imageInput.value = ''
		},
		// Nach dem Speichern der Frage: Bild hochladen bzw. entfernen. Gibt die
		// aktualisierte Frage zurück (mit neuem image-Dateinamen).
		async syncImage(poll) {
			try {
				const base = this.api(this.room.code, '/polls/' + poll.id + '/image')
				if (this.draft.imageFile) {
					const form = new FormData()
					form.append('image', this.draft.imageFile)
					const { data } = await axios.post(base, form)
					return data
				}
				if (this.draft.imageRemove) {
					const { data } = await axios.delete(base)
					return data
				}
			} catch (e) {
				showError(e?.response?.data?.message || t('pulse', 'The image could not be saved.'))
			}
			return poll
		},

		// ── Umbenennen ──────────────────────────────────────────────────────
		startRename() {
			this.renameDraft = this.roomTitle
			this.renaming = true
			this.$nextTick(() => { if (this.$refs.renameInput) this.$refs.renameInput.focus() })
		},
		cancelRename() {
			this.renaming = false
		},
		async saveRename() {
			const title = this.renameDraft.trim()
			this.renaming = false
			if (!this.room || title === this.roomTitle) return
			try {
				const { data } = await axios.post(this.api(this.room.code, '/title'), { title })
				// Nur den Titel übernehmen: applyRoom würde Ergebnisse/Rangliste leeren.
				this.room = { ...this.room, title: data.title }
				const row = this.myRooms.find((r) => r.code === this.room.code)
				if (row) row.title = data.title
			} catch (e) {
				showError(t('pulse', 'Could not rename the room.'))
			}
		},

		// ── Zurücksetzen / Probelauf ────────────────────────────────────────
		// Nimmt eine frische roomView (Server liefert sie nach Reset/Umschalten)
		// und spiegelt sie lokal: Deck neu, Stimmen/Rangliste weg, Cursor auf 0.
		applyRoom(data) {
			this.room = data
			this.deck = data.polls || []
			this.currentId = data.activePollId || 0
			this.results = null
			this.leaderboard = []
			this.resVersion = ''
			// Der Raum ruht nach dem Leeren -> falls wir präsentierten, zurück zum Deck.
			if (this.currentId === 0) {
				this.stopPolling()
				if (this.phase === 'present') this.phase = 'deck'
			}
		},
		// counts: optionaler Zählstand (die Laufansicht hat einen eigenen), sonst
		// der letzte aus PaceDeckStatus. Aus dem Menü kommt kein Argument.
		async resetRoom(counts) {
			let text = t('pulse', 'All votes, participants and the leaderboard in this room will be deleted. The questions stay.')
			if (this.isPaced) {
				const known = counts && typeof counts === 'object' && 'joined' in counts ? counts : this.paceCounts
				const loss = this.lossText(known, this.paceState)
				text = t('pulse', 'All participants, answers and the leaderboard are deleted, and the quiz goes back to preparation — you can edit the questions and open it again. The questions stay.') + (loss ? ' ' + loss : '')
			}
			if (!await pulseConfirm({
				title: t('pulse', 'Reset the room?'),
				text,
				confirmLabel: t('pulse', 'Reset'),
				cancelLabel: t('pulse', 'Cancel'),
				danger: true,
			})) return
			try {
				const { data } = await axios.post(this.api(this.room.code, '/reset'))
				this.applyRoom(data)
			} catch (e) {
				this.failWrite(e, t('pulse', 'Could not reset the room.'))
			}
		},
		async toggleRevealAtEnd() {
			// Reines Ablauf-Flag — leert nichts. Server liefert frische roomView.
			const on = !this.isRevealAtEnd
			try {
				const { data } = await axios.post(this.api(this.room.code, '/reveal'), { on })
				this.applyRoom(data)
			} catch (e) {
				this.failWrite(e, t('pulse', 'Could not switch the reveal mode.'))
			}
		},
		// Gibt zurück, ob umgeschaltet wurde (endPracticeThenOpen öffnet danach
		// den Dialog neu).
		async togglePractice() {
			const on = !this.isPractice
			let text = on
				? t('pulse', 'The votes, participants and leaderboard collected so far will be cleared — the questions stay.')
				: t('pulse', 'Everything is reset for the real run: the test votes and test participants are cleared.')
			// Einschalten im eigenen Tempo leert echte Ergebnisse — mit Zahl sagen.
			if (on && this.isPaced) {
				const loss = this.lossText(this.paceCounts, this.paceState)
				if (loss) text += ' ' + loss
			}
			if (!await pulseConfirm({
				title: on ? t('pulse', 'Start the practice run?') : t('pulse', 'End the practice run?'),
				text,
				confirmLabel: on ? t('pulse', 'Start practice run') : t('pulse', 'End practice run'),
				cancelLabel: t('pulse', 'Cancel'),
				danger: true,
			})) return false
			try {
				const { data } = await axios.post(this.api(this.room.code, '/practice'), { on })
				this.applyRoom(data)
				return true
			} catch (e) {
				this.failWrite(e, t('pulse', 'Could not switch the practice run.'))
				return false
			}
		},

		// ── Quiz im eigenen Tempo: Deck, Umschalter, Öffnen (§0.9, §1.1–1.5) ──
		/*
		 * Sperren aus Tabelle §1.3 als Menü-Felder: offen -> aus mit „Close the
		 * quiz first.", geschlossen (nur wo closedToo) -> aus mit „Release or
		 * reset first." — eine nicht freigegebene Hausaufgabe soll nicht durch
		 * einen Schalter verschwinden. Moderiert: nie gesperrt.
		 */
		paceLock(closedToo) {
			if (!this.isPaced) return {}
			if (this.paceState === 'open') return { disabled: true, hint: t('pulse', 'Close the quiz first.') }
			if (closedToo && this.paceState === 'closed') return { disabled: true, hint: t('pulse', 'Release or reset first.') }
			return {}
		},
		// Zustands-Chip eines Fensters (Deck-Kopf §1.4, Raumliste §1.9) — die
		// Frist zählt gegen die Serverzeit. Ein Entwurf heißt in der Liste
		// schlicht „Self-paced"; im Deck-Kopf steht dafür kein Chip.
		paceChip(win) {
			return paceStateChip(win, this.nowSec + this.paceSkew)
		},
		/*
		 * Zählstand für zerstörende Bestätigungen — eine Anfrage, keine Schleife.
		 * Eigenes Tempo über /progress (ohne Version), moderiert über die
		 * Rangliste (eine Zeile je Person). Fehler -> null: die Bestätigung
		 * kommt dann ohne Zahlen, aber immer als „danger".
		 */
		async fetchCounts(code, paced) {
			try {
				if (paced) {
					const { data } = await axios.get(this.api(code, '/progress'), { timeout: 15000 })
					return progressCounts(data)
				}
				const { data } = await axios.get(this.api(code, '/leaderboard'), { timeout: 15000 })
				return { joined: Array.isArray(data) ? data.length : 0 }
			} catch (e) {
				return null
			}
		},
		/*
		 * Was eine zerstörende Aktion kostet, als Zusatzsatz zur Bestätigung
		 * (§0.9). Ohne Zählstand oder ohne Beigetretene: ''. Moderiert zählt
		 * wie der Entwurf (wer drin ist, muss neu beitreten).
		 */
		lossText(counts, state) {
			if (!counts || !counts.joined) return ''
			if (state === 'closed' || state === 'released') {
				const vars = {
					participants: n('pulse', '%n participant', '%n participants', counts.joined),
					answers: n('pulse', '%n answer', '%n answers', counts.answers || 0),
				}
				return state === 'closed'
					? t('pulse', '{participants} and {answers} will be deleted. The results have not been released yet — download “Participants as CSV” first if you need them.', vars)
					: t('pulse', '{participants} and {answers} will be deleted. Download “Participants as CSV” first if you still need them.', vars)
			}
			return n('pulse', '%n person who already joined will have to join again.', '%n people who already joined will have to join again.', counts.joined)
		},
		/*
		 * Schreibender Aufruf gescheitert: die Servermeldung statt eines
		 * Pauschaltexts (sie sagt, WARUM). Eine 409 heißt, der Raum steht auf
		 * dem Server anders als hier — neu laden; beim Sortieren stellt das
		 * zugleich die Reihenfolge wieder her, die vuedraggable schon lokal
		 * umgestellt hatte.
		 */
		failWrite(e, fallback) {
			showError(e?.response?.data?.message || fallback)
			if (e?.response?.status === 409) this.refreshRoom(true)
		},
		/*
		 * 409 auf eine Cursor-Aktion der Präsentation (Zeigen, Blättern,
		 * Auflösen, Beenden): der Raum läuft inzwischen im eigenen Tempo —
		 * moderiert gibt es die 409 nicht (RoomApiController::paced). Dann die
		 * Servermeldung zeigen und den Raum neu laden, statt mit einem
		 * Pauschaltext in der überholten Präsentation zu bleiben. true = erledigt.
		 */
		staleLive(e) {
			if (e?.response?.status !== 409 || !this.room) return false
			showError(e.response.data?.message || t('pulse', 'Could not switch.'))
			this.stopPolling()
			this.loadRoom(this.room.code)
			return true
		},
		/*
		 * Raum neu laden OHNE Phasenwechsel (Deck bleibt Deck). Sperrt sich das
		 * Deck dabei, während der Composer offen steht, geht er zu — mit dem
		 * Grund als Toast, außer der Aufrufer hat die Servermeldung schon
		 * gezeigt (quiet). Fehler still: die nächste Aktion zeigt sie.
		 */
		async refreshRoom(quiet = false) {
			if (!this.room) return
			const code = this.room.code
			try {
				const { data } = await axios.get(this.api(code), { timeout: 15000 })
				if (!this.room || this.room.code !== code) return
				this.room = data
				this.deck = data.polls || []
				if (this.deckLocked && this.showComposer) {
					this.cancelComposer()
					if (!quiet) showError(t('pulse', 'The questions are locked since the quiz was opened. Reset the room to edit them.'))
				}
			} catch (e) {
				// still
			}
		},
		// Raum-JSON aus einem /pace-Aufruf übernehmen — ohne applyRoom (das
		// stoppt Polling und leert Ergebnisse, beides gibt es hier nicht).
		onPaceRoom(data) {
			this.room = data
			this.deck = data.polls || []
			// Wer selbst geöffnet hat, weiß, warum das Formular zugeht.
			if (this.deckLocked && this.showComposer) this.cancelComposer()
			// Ein Entwurf gehört ins Deck. Sonst bleibt die Ansicht, wo sie ist:
			// „Lock joining" im Deck-Menü soll nicht in die Laufansicht springen.
			if (windowState(data.window) === 'draft') this.phase = 'deck'
		},
		// Frisches Fenster aus /progress. Wechselt der Zustand, auch den Raum
		// neu laden (Beitrittssperre, Fenster im Raum-JSON, Deck-Sperre).
		onPaceWindow(w) {
			if (!this.room || !w) return
			const before = this.room.window ? this.room.window.state : ''
			this.$set(this.room, 'window', w)
			if (w.state !== before) this.refreshRoom()
		},
		onPaceGone() {
			showError(t('pulse', 'Room not found — it may have been deleted.'))
			this.goHome()
		},
		openPaceDialog() {
			this.paceDialog = 'open'
		},
		// Frisch geöffnet: weiter in die Laufansicht — dort steht der Link zum Teilen.
		onPaceDone(data) {
			this.paceDialog = null
			this.onPaceRoom(data)
			if (windowState(data.window) !== 'draft') this.enterPace()
		},
		showProgress() {
			this.enterPace()
		},
		// Per Klick in die Laufansicht: der Auslöser (Dialog, „Show progress",
		// „Back to progress") verschwindet mit der alten Ansicht, der Fokus fiele
		// auf <body>. PaceRun setzt ihn dann auf ihren Titel.
		enterPace() {
			this.paceFocus = true
			this.phase = 'pace'
		},
		// Menü der Laufansicht: Übersicht (Deck), Zusammenfassung, Meine Räume.
		onPaceGo(p) {
			if (p === 'deck') this.phase = 'deck'
			else if (p === 'summary') this.openSummary('pace')
			else if (p === 'home') this.goHome()
		},
		// 409 aus dem Dialog: jemand anderes war schneller (zweiter Tab, Add-in).
		onPaceConflict(message) {
			showError(message)
			this.paceDialog = null
			this.refreshRoom(true)
		},
		// „End practice run" im Dialog: erst der Dialog weg (keine Bestätigung
		// über einem Formular), dann ohne Probelauf neu öffnen.
		async endPracticeThenOpen() {
			this.paceDialog = null
			if (await this.togglePractice()) this.paceDialog = 'open'
		},
		/*
		 * Moderiert ↔ eigenes Tempo (§1.2). Leert den Raum — gefragt wird aber
		 * nur, wenn dabei etwas verloren geht: ohne Beigetretene (und im eigenen
		 * Tempo nur im Entwurf) wird sofort umgeschaltet.
		 */
		async togglePace() {
			if (!this.room || this.paceBusy) return
			this.paceBusy = true
			try {
				const paced = this.isPaced
				const on = !paced
				const code = this.room.code
				const counts = paced ? this.paceCounts : await this.fetchCounts(code, false)
				const quiet = !!counts && counts.joined === 0 && (!paced || this.paceState === 'draft')
				if (!quiet) {
					const text = on
						? t('pulse', 'Everyone works through the questions on their own phone, at their own pace — you open and close the quiz instead of presenting it. Participants, answers and the leaderboard collected so far are cleared; the questions stay.')
						: t('pulse', 'You present the questions one by one again. Participants, answers and the leaderboard collected so far are cleared; the questions stay.')
					const loss = this.lossText(counts, paced ? this.paceState : 'draft')
					if (!await pulseConfirm({
						title: on ? t('pulse', 'Switch to self-paced?') : t('pulse', 'Switch to moderated?'),
						text: loss ? text + ' ' + loss : text,
						confirmLabel: on ? t('pulse', 'Switch to self-paced') : t('pulse', 'Switch to moderated'),
						cancelLabel: t('pulse', 'Cancel'),
						danger: true,
					})) return
				}
				const { data } = await axios.post(this.api(code, '/pace'), { action: 'set', pace: on ? 'self' : 'live' }, { timeout: 15000 })
				this.applyRoom(data)
				this.phase = 'deck'
			} catch (e) {
				this.failWrite(e, t('pulse', 'Could not switch the pace.'))
			} finally {
				this.paceBusy = false
			}
		},
		// Beitritt sperren: wer schon drin ist, kommt weiter herein.
		async toggleJoinsLocked() {
			if (!this.room) return
			const on = !this.room.joinsLocked
			try {
				const { data } = await axios.post(this.api(this.room.code, '/pace'), { action: on ? 'lockJoins' : 'unlockJoins' }, { timeout: 15000 })
				this.onPaceRoom(data)
			} catch (e) {
				this.failWrite(e, t('pulse', 'Could not change joining.'))
			}
		},

		// ── Präsentationsansicht (§9) ───────────────────────────────────────
		runPrimary() {
			this.primary.act()
		},
		toggleJoin() {
			this.joinOpen = !this.joinOpen
		},
		onEscape(e) {
			if (e.key !== 'Escape') return
			if (this.joinOpen) this.joinOpen = false
		},
		// Leere Bühne: der Weg zurück ins Deck mit offenem Composer (§9.8).
		addQuestion() {
			this.backToDeck()
			this.openComposer()
		},
		/*
		 * Maßstab der Leinwand-Vorschau: die Beamer-Seite rechnet in vw/vh und
		 * braucht deshalb ihren echten Kiosk (1280×720) — verkleinert wird per
		 * transform, nicht per Fenstergröße. Sonst läge im iframe ein anderes
		 * Layout als im Saal, und die Vorschau wäre wertlos.
		 */
		fitFrame() {
			const box = this.$refs.canvas
			if (!box) return
			const k = Math.min(box.clientWidth / 1280, box.clientHeight / 720)
			if (k > 0.05 && Math.abs(k - this.frameScale) > 0.002) this.frameScale = k
		},
		/*
		 * Der Kasten ändert seine Größe auch ohne Fenster-Ereignis: das private
		 * Panel geht, das Vollbild kommt. Beim Vollbild liegt die neue Geometrie
		 * erst NACH dem Neuzeichnen vor — ein fitFrame() im selben Tick misst
		 * noch die alte. Der ResizeObserver misst, wenn es so weit ist.
		 */
		observeCanvas() {
			const box = this.$refs.canvas || null
			if (box === this.canvasWatched) return
			if (this.canvasRo) this.canvasRo.disconnect()
			this.canvasWatched = box
			if (box && window.ResizeObserver) {
				if (!this.canvasRo) this.canvasRo = new window.ResizeObserver(() => this.fitFrame())
				this.canvasRo.observe(box)
			}
			this.fitFrame()
		},

		// ── Vollbild ────────────────────────────────────────────────────────
		toggleFullscreen() {
			if (document.fullscreenElement) {
				document.exitFullscreen()
			} else if (this.$el.requestFullscreen) {
				this.$el.requestFullscreen().catch(() => {})
			}
		},
		onFsChange() {
			this.isFullscreen = document.fullscreenElement === this.$el
			// Der Wechsel ins Vollbild ändert die Geometrie erst nach dem
			// Neuzeichnen, und ohne Fenster-Ereignis meldet sich nichts von
			// selbst — deshalb ein paar Nachmessungen statt einer.
			this.$nextTick(this.fitFrame)
			for (const ms of [120, 350, 800]) setTimeout(this.fitFrame, ms)
		},

		// ── Raum ────────────────────────────────────────────────────────────
		async startRoom(mode) {
			this.busy = true
			try {
				const { data } = await axios.post(generateUrl('/apps/pulse/api/1.0/rooms'), { mode })
				this.room = data
				this.deck = []
				this.currentId = 0
				this.paceDialog = null
				this.paceCounts = null
				this.phase = 'deck'
				this.showComposer = true
				this.draft = this.emptyDraft()
				window.history.replaceState(null, '', generateUrl('/apps/pulse/room/' + data.code))
			} catch (e) {
				showError(t('pulse', 'The room could not be created.'))
			} finally {
				this.busy = false
			}
		},

		// ── Composer / Deck bauen ───────────────────────────────────────────
		openComposer(poll = null) {
			if (poll && poll.id) {
				this.editingId = poll.id
				const d = this.emptyDraft()
				d.type = poll.type
				d.question = poll.question
				d.timeLimit = poll.timeLimit || 30
				const spec = QUESTION_TYPES[poll.type]
				if (spec) spec.toDraft(poll, poll.answerKey || {}, d)
				d.imageExisting = poll.image || ''
				d.imagePreview = poll.image ? this.modImageUrl(poll) : ''
				this.draft = d
			} else {
				this.editingId = 0
				this.draft = this.emptyDraft()
			}
			this.showComposer = true
			// Bei sieben Fragen öffnet sich das Formular sonst unter der Falz und
			// es sieht aus, als sei nichts passiert.
			this.$nextTick(() => {
				if (this.$refs.composer) this.$refs.composer.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
				if (this.$refs.qInput) this.$refs.qInput.focus()
			})
		},
		cancelComposer() {
			this.showComposer = false
			this.editingId = 0
		},
		addOption() {
			if (this.draft.options.length < 8) this.draft.options.push('')
		},
		removeOption(i) {
			this.draft.options.splice(i, 1)
			// Markierung der richtigen Antwort nachziehen (choice: Radio).
			if (this.draft.correctIndex === i) this.draft.correctIndex = 0
			else if (this.draft.correctIndex > i) this.draft.correctIndex--
			// … und die Mehrfach-Markierungen (multi).
			this.draft.correctIndexes = this.draft.correctIndexes
				.filter((x) => x !== i)
				.map((x) => (x > i ? x - 1 : x))
		},
		focusFirstOption() {
			const el = this.$refs.optionInputs && this.$refs.optionInputs[0]
			if (el) el.focus()
		},
		async saveQuestion() {
			const type = this.draft.type
			const body = { type, question: this.draft.question.trim() }
			if (!body.question) {
				showError(t('pulse', 'Please enter a question.'))
				return
			}
			if (this.isQuiz) body.timeLimit = this.draft.timeLimit
			let ok = true
			const fail = (m) => { showError(m); ok = false }
			const spec = QUESTION_TYPES[type]
			if (spec) spec.toBody(this.draft, body, this.isQuiz, fail)
			if (!ok) return
			this.busy = true
			try {
				if (this.editingId) {
					let { data } = await axios.put(this.api(this.room.code, '/polls/' + this.editingId), body)
					data = await this.syncImage(data)
					const idx = this.deck.findIndex((p) => p.id === this.editingId)
					if (idx >= 0) this.$set(this.deck, idx, data)
					// Bearbeitete Frage läuft gerade? Ergebnisse neu laden (Stimmen wurden geleert).
					if (this.editingId === this.currentId) this.fetchResults()
				} else {
					let { data } = await axios.post(this.api(this.room.code, '/polls'), body)
					data = await this.syncImage(data)
					this.deck.push(data)
				}
				this.showComposer = false
				this.editingId = 0
				this.draft = this.emptyDraft()
			} catch (e) {
				this.failWrite(e, t('pulse', 'Could not save.'))
			} finally {
				this.busy = false
			}
		},
		async onReorder() {
			// vuedraggable hat this.deck bereits umsortiert -> Reihenfolge persistieren.
			try {
				await axios.post(this.api(this.room.code, '/deck/order'), { order: this.deck.map((p) => p.id) })
			} catch (e) {
				this.failWrite(e, t('pulse', 'Could not save the order.'))
			}
		},
		// ↑/↓-Sortierung (barrierefrei, zusätzlich zum Drag): Frage mit dem
		// Nachbarn tauschen und die neue Reihenfolge wie beim Drag persistieren.
		// Deck-Zeile: eine sichtbare Handlung („Show"), der Rest hängt am ⋯ (R5).
		rowMenu(p, i) {
			return [
				{ key: 'edit', label: t('pulse', 'Edit'), icon: 'edit', act: () => this.openComposer(p) },
				{ key: 'up', label: t('pulse', 'Move up'), icon: 'arrow-up', disabled: i === 0, act: () => this.moveQuestion(i, -1) },
				{ key: 'down', label: t('pulse', 'Move down'), icon: 'arrow-down', disabled: i === this.deck.length - 1, act: () => this.moveQuestion(i, 1) },
				{ key: 'del', label: t('pulse', 'Delete question'), icon: 'trash', act: () => this.deleteQuestion(p), danger: true },
			]
		},
		// Raumkarte: die Karte selbst öffnet den Raum (R6), hier liegt der Rest.
		roomMenu(r) {
			return [
				{ key: 'copy', label: t('pulse', 'Copy as a template'), icon: 'copy', disabled: this.busy, act: () => this.duplicateRoom(r) },
				{ key: 'del', label: t('pulse', 'Delete room'), icon: 'trash', act: () => this.deleteRoom(r), danger: true },
			]
		},
		moveQuestion(i, dir) {
			const j = i + dir
			if (j < 0 || j >= this.deck.length) return
			const arr = this.deck.slice()
			const tmp = arr[i]
			arr[i] = arr[j]
			arr[j] = tmp
			this.deck = arr
			this.onReorder()
			// Ohne Maus reißt sonst der Faden: die Frage sitzt jetzt eine Zeile
			// weiter, ihr Menü hat sich beim Klick geschlossen, und der Fokus fiele
			// auf den Seitenanfang zurück. Also mitwandern.
			this.$nextTick(() => {
				const menu = (this.$refs.rowMenus || [])[j]
				if (menu) menu.focusTrigger()
			})
		},
		async deleteQuestion(poll) {
			if (!await pulseConfirm({
				title: t('pulse', 'Delete the question?'),
				text: t('pulse', 'This question and its votes will be deleted. This cannot be undone.'),
				confirmLabel: t('pulse', 'Delete permanently'),
				cancelLabel: t('pulse', 'Cancel'),
				danger: true,
			})) return
			try {
				await axios.delete(this.api(this.room.code, '/polls/' + poll.id))
				this.deck = this.deck.filter((p) => p.id !== poll.id)
				if (this.currentId === poll.id) this.currentId = 0
			} catch (e) {
				this.failWrite(e, t('pulse', 'Could not delete the question.'))
			}
		},

		// ── Navigation / Cursor ─────────────────────────────────────────────
		async setCurrentApi(pollId) {
			await axios.post(this.api(this.room.code, '/current'), { pollId })
			// Im Quiz startet der Server den Timer neu und öffnet die Frage -> lokal
			// spiegeln. Ein früherer Endstand ('ended') ist damit vorbei; der
			// Server macht daraus wieder eine aufgelöste Frage (unmarkEnded).
			// Die Umfrage rührt den Status beim Blättern nicht an (pausiert bleibt pausiert).
			const idx = this.deck.findIndex((p) => p.id === pollId)
			if (this.isQuiz && idx >= 0) this.$set(this.deck[idx], 'status', 'active')
			if (this.isQuiz && pollId) {
				this.deck.forEach((p) => { if (p.id !== pollId && p.status === 'ended') this.$set(p, 'status', 'locked') })
			}
		},
		// Gemeinsamer Cursor-Wechsel für „Zeigen" und „Blättern".
		async selectPoll(pollId) {
			await this.setCurrentApi(pollId)
			this.currentId = pollId
			this.resVersion = ''
		},
		async present(pollId) {
			try {
				await this.selectPoll(pollId)
				this.phase = 'present'
				this.startPolling()
			} catch (e) {
				if (!this.staleLive(e)) showError(t('pulse', 'Could not start the question.'))
			}
		},
		startPresenting() {
			if (this.deck.length) this.present(this.deck[0].id)
		},
		async goto(pollId) {
			this.cancelAdvance() // manueller Wechsel bricht ein geplantes Auto-Weiter ab
			try {
				await this.selectPoll(pollId)
				this.results = null
				// Von der Zwischen-Rangliste aus: der Saal ist jetzt auf der Frage,
				// also auch der Moderator (samt Polling für Timer und Auto-Weiter).
				if (this.phase === 'leaderboard') {
					this.phase = 'present'
					this.startPolling()
					return
				}
				this.fetchResults()
			} catch (e) {
				if (!this.staleLive(e)) showError(t('pulse', 'Could not switch.'))
			}
		},
		next() {
			if (this.canNext) this.goto(this.deck[this.currentIndex + 1].id)
		},
		prev() {
			if (this.canPrev) this.goto(this.deck[this.currentIndex - 1].id)
		},
		// ── Auto-Weiterschalten nach Timer-Ende ─────────────────────────────
		// Weiter zur nächsten Frage; nach der letzten genau das tun, was der
		// jeweilige „letzte-Frage"-Button tut (Modus-abhängig).
		autoAdvance() {
			if (this.canNext) { this.next(); return }
			// Nach der letzten Frage dasselbe wie der Hauptknopf: Endstand für alle
			// Bildschirme zugleich. Im Probelauf gibt es keinen — bei „am Ende"
			// einmal auflösen, beenden tut der Moderator selbst.
			if (!this.isPractice) this.finish()
			else if (this.isRevealAtEnd && !this.isRevealed) this.revealLast()
		},
		scheduleAdvance(seconds) {
			this.cancelAdvance()
			this.advanceAtSec = Math.floor(Date.now() / 1000) + seconds
			this.advanceTimer = setTimeout(() => {
				this.advanceTimer = null
				this.advanceAtSec = 0
				this.autoAdvance()
			}, seconds * 1000)
		},
		cancelAdvance() {
			if (this.advanceTimer) { clearTimeout(this.advanceTimer); this.advanceTimer = null }
			this.advanceAtSec = 0
		},
		backToDeck() {
			this.stopPolling()
			this.phase = 'deck'
		},
		// Zurück zur Pulse-Übersicht (Startbildschirm mit „Meine Räume").
		openBeamer() {
			if (!this.room) return
			window.open(this.screenUrl, 'pulse-beamer-' + this.room.code)
		},
		/*
		 * Office-Manifest der eigenen Instanz holen. Der Server füllt die
		 * Adressen ein (AddinController) — von Hand ist daran nichts mehr zu
		 * ändern, und genau das war vorher die Fehlerquelle.
		 */
		downloadAddin() {
			window.location.href = generateUrl('/apps/pulse/addin/manifest.xml')
			showSuccess(t('pulse', 'Manifest downloaded. Register it in PowerPoint once — see the add-in guide in the Pulse README.'))
		},
		goHome() {
			this.stopPolling()
			this.room = null
			this.deck = []
			this.currentId = 0
			this.results = null
			this.leaderboard = []
			this.summary = []
			this.showComposer = false
			this.paceDialog = null
			this.paceCounts = null
			this.phase = 'start'
			window.history.replaceState(null, '', generateUrl('/apps/pulse/'))
			this.fetchMyRooms()
		},
		// Endstand/Zusammenfassung laden: Polling stoppen, Daten holen, Phase setzen.
		async loadPhase(endpoint, key, errMsg) {
			if (!this.room) return
			this.stopPolling()
			try {
				const { data } = await axios.get(this.api(this.room.code, endpoint))
				this[key] = data
				this.phase = key
			} catch (e) {
				showError(errMsg)
			}
		},
		openSummary(from) {
			this.summaryFrom = from === 'pace' ? 'pace' : 'deck'
			return this.loadPhase('/summary', 'summary', t('pulse', 'Could not load the summary.'))
		},
		// Zurück, woher die Zusammenfassung kam. Ist der Raum inzwischen wieder
		// ein Entwurf (Zurücksetzen im zweiten Tab), gehört er ins Deck.
		summaryBack() {
			if (this.summaryToPace && this.paceState !== 'draft') this.enterPace()
			else this.phase = 'deck'
		},
		openLeaderboard() {
			return this.loadPhase('/leaderboard', 'leaderboard', t('pulse', 'Could not load the leaderboard.'))
		},
		async finish() {
			// Quiz beenden heißt: die laufende Frage auf 'ended' setzen und den
			// Cursor stehen lassen. Nur so zeigt der Beamer den Endstand — mit
			// geleertem Cursor (setCurrent 0) fiele er zurück in die Lobby. Gilt
			// für beide Auflösungs-Arten; „am Ende" braucht das ohnehin, damit
			// die Teilnehmer jetzt Auflösung + Endstand sehen.
			if (this.isQuiz && !this.isPractice) {
				// Scheitert /end, bleibt der Saal auf der letzten Frage — dann nicht
				// so tun, als stünde der Endstand überall, sondern hierbleiben.
				if (!await this.postEnd()) return
				this.stopPolling()
				this.openLeaderboard()
				return
			}
			// Umfrage und Probelauf: kein Endstand -> Raum zurück in die Lobby,
			// Moderator zurück aufs Deck.
			try {
				await this.setCurrentApi(0)
			} catch (e) {
				// weiter, auch wenn das Setzen scheitert
			}
			this.stopPolling()
			this.currentId = 0
			this.phase = 'deck'
		},

		// Probelauf mit „Auflösung am Ende", letzte Frage: einmal auflösen, damit
		// alle ihre Antworten prüfen können (ohne Rangliste — die blendet der
		// Server im Probelauf aus). Der Moderator bleibt auf der Frage; „Beenden"
		// schickt den Raum danach in die Lobby. Nur Hauptknopf und Auto-Weiter
		// rufen das — „Quiz beenden" im Menü beendet mitten im Deck sofort.
		async revealLast() {
			this.cancelAdvance()
			if (!await this.postEnd()) return
			this.resVersion = ''
			this.fetchResults()
		},

		// POST /end und den Zustand lokal spiegeln: die laufende Frage steht
		// jetzt auf 'ended' (quizOver/isRevealed hängen daran).
		async postEnd() {
			try {
				await axios.post(this.api(this.room.code, '/end'))
			} catch (e) {
				if (!this.staleLive(e)) showError(t('pulse', 'Could not finish the quiz.'))
				return false
			}
			if (this.currentIndex >= 0) this.$set(this.deck[this.currentIndex], 'status', 'ended')
			return true
		},

		// ── Aktuelle Frage steuern ──────────────────────────────────────────
		async setLock(lock) {
			const verb = lock ? 'lock' : 'unlock'
			try {
				await axios.post(this.api(this.room.code, '/polls/' + this.currentId + '/' + verb))
				if (this.currentIndex >= 0) {
					this.$set(this.deck[this.currentIndex], 'status', lock ? 'locked' : 'active')
				}
				// Reveal/Pause sofort sichtbar machen (nicht auf den nächsten Tick warten).
				this.resVersion = ''
				this.fetchResults()
			} catch (e) {
				if (!this.staleLive(e)) showError(lock ? t('pulse', 'Could not reveal.') : t('pulse', 'Could not resume.'))
			}
		},
		// Quiz: aufgelöste Frage neu öffnen = Timer neu starten (setCurrent).
		async reopen() {
			await this.present(this.currentId)
		},
		async resetCurrent() {
			try {
				await axios.post(this.api(this.room.code, '/polls/' + this.currentId + '/reset'))
				this.fetchResults()
			} catch (e) {
				showError(t('pulse', 'Could not reset.'))
			}
		},
		// ── Demo-/Testmodus: aktive Frage mit synthetischen Stimmen befüllen ──
		async seedDemo() {
			if (this.demoBusy || !this.room || !this.currentId) return
			this.demoBusy = true
			try {
				const { data } = await axios.post(this.api(this.room.code, '/demo'), { count: this.demoCount })
				this.resVersion = ''
				this.fetchResults()
				showSuccess(t('pulse', '{seeded} demo votes created — {total} in total now.', { seeded: data.seeded, total: data.total }))
			} catch (e) {
				showError(e?.response?.data?.message || t('pulse', 'Could not create demo votes.'))
			} finally {
				this.demoBusy = false
			}
		},
		async clearDemo() {
			if (this.demoBusy || !this.room) return
			this.demoBusy = true
			try {
				const { data } = await axios.delete(this.api(this.room.code, '/demo'))
				this.resVersion = ''
				this.fetchResults()
				showSuccess(data.removed ? t('pulse', '{count} demo votes removed.', { count: data.removed }) : t('pulse', 'There are no demo votes.'))
			} catch (e) {
				showError(t('pulse', 'Could not remove the demo votes.'))
			} finally {
				this.demoBusy = false
			}
		},
		// Freitext bewerten (Präsentations-Reveal): Antwort ✓/✗ -> Server wertet
		// alle gleichen Schreibweisen nach und liefert frische Ergebnisse+Rangliste.
		async gradeAnswer(answer, correct) {
			try {
				const { data } = await axios.post(this.api(this.room.code, '/polls/' + this.currentId + '/grade'), { answer, correct })
				this.results = data
				if (data.leaderboard) this.leaderboard = data.leaderboard
				this.resVersion = ''
			} catch (e) {
				showError(e?.response?.data?.message || t('pulse', 'Could not grade.'))
			}
		},
		// Freitext bewerten aus der Zusammenfassung (aktualisiert nur diese Frage).
		async gradeSummaryAnswer(item, answer, correct) {
			try {
				const { data } = await axios.post(this.api(this.room.code, '/polls/' + item.poll.id + '/grade'), { answer, correct })
				this.$set(item, 'results', data)
			} catch (e) {
				showError(e?.response?.data?.message || t('pulse', 'Could not grade.'))
			}
		},

		// ── Ergebnis-Polling (adaptiv + Änderungs-Version) ──────────────────
		async fetchResults() {
			if (!this.currentId) return
			try {
				const params = this.resVersion ? { v: this.resVersion } : {}
				const res = await axios.get(this.api(this.room.code, '/polls/' + this.currentId + '/results'), { params })
				this.online = true
				if (res.status === 204) { // unverändert
					this.idleStreak++
					return
				}
				const data = res.data
				// Der Raum läuft inzwischen im eigenen Tempo (ein anderer Tab hat
				// umgeschaltet): diese Präsentation ist überholt. Nichts übernehmen —
				// die Ergebnisse im eigenen Tempo verdecken bei „am Ende" nichts —,
				// sondern den Raum neu laden: loadRoom führt ins Deck bzw. in die
				// Laufansicht.
				if (data.pace === 'self' && !this.isPaced) {
					this.stopPolling()
					this.loadRoom(this.room.code)
					return
				}
				this.results = data
				this.presentCount = data.present || 0
				/*
				 * Das Beitritts-Panel öffnet sich NICHT von selbst. Früher klappte es
				 * hier auf, solange presentCount 0 war — und weil das im Ergebnis-Takt
				 * (im Quiz jede 1,2 s) neu entschieden wurde, sprang es nach jedem
				 * Schließen über ×, Klick daneben oder Escape sofort wieder auf. In einem
				 * noch leeren Raum war es damit gar nicht zuzubekommen.
				 * Der Code steht ohnehin dauerhaft in der Kopfzeile und groß auf der
				 * Leinwand; wer den QR-Code braucht, holt ihn über „Mitmachen".
				 */
				if (data.serverNow) this.serverSkew = data.serverNow - Math.floor(Date.now() / 1000)
				if (data.leaderboard) this.leaderboard = data.leaderboard
				if (data.version !== undefined) this.resVersion = data.version
				this.idleStreak = 0
			} catch (e) {
				if (e?.response?.status === 404) { this.results = null; return }
				this.online = false
			}
		},
		pollDelay() {
			if (document.hidden) return null // pausieren -> onPollVisibility weckt auf
			// Quiz mit laufendem Timer oder frische Stimmen -> flott; sonst zurückfahren.
			if (this.isQuiz && !this.isRevealed) return 1200
			return this.idleStreak >= 3 ? 4000 : 1800
		},
		// Moderator pollt nur in bestimmten Phasen (Frage präsentiert) -> Gate.
		pollActive() {
			return this.polling
		},
		pollOnce() {
			return this.fetchResults()
		},
		startPolling() {
			this.stopPolling()
			this.polling = true
			this.pollTick()
		},
		stopPolling() {
			this.polling = false
			this.pollClear()
			this.cancelAdvance()
		},
	},
}
</script>

<style scoped>
.pulse-mod {
	/* NC's #content ist ein row-Flex-Container -> ohne flex/width bliebe dieses
	   Wurzel-div content-breit und klebte links (margin:auto hätte keinen Platz). */
	flex: 1 1 auto;
	width: 100%;
	min-width: 0;
	min-height: 100%;
	/* NC's #content ist fixhoch und schneidet Überstand ab -> dieses Wurzel-div
	   muss selbst scrollen, sonst ist z.B. eine lange Raumliste unter dem Falz
	   nicht erreichbar. max-height bindet die Höhe an den Flex-Parent. */
	max-height: 100%;
	overflow-y: auto;
	background: var(--pulse-bg);
	color: var(--pulse-text);
	padding: 40px min(5vw, 64px);
	box-sizing: border-box;
	font-family: var(--font-face, system-ui, -apple-system, sans-serif);
}
.pulse-mod.is-fs { overflow: auto; }
/* Präsentation und Endstand füllen die Fläche selbst (eigene Kopf-/Fußzeile) —
   der Seitenrand von .pulse-mod würde den Rahmen doppelt rahmen. */
.pulse-mod.is-live { padding: 0; overflow: hidden; }
/* §9.5: der Kopfblock beginnt bei 64 px ab Fensterkante (NC-Kopf 50 px + 14),
   nicht bei rund 150 — der Leerraum kostete die erste Raumkarte. */
.pulse-mod.is-start { padding-block-start: 14px; }

/* Start */
.start { max-width: 1180px; margin: 0 auto; display: grid; gap: 40px; align-items: start; }
/* Ab 1200 px zwei Spalten: links der Kopfblock, rechts die Räume. Einspaltig
   bleibt der Kopfblock unter 300 px, damit die erste Raumkarte über der Falz
   steht (§9.5). */
@media (min-width: 1200px) { .start { grid-template-columns: minmax(0, 440px) minmax(0, 1fr); gap: 56px; } }
.start-intro { max-width: 620px; }
.eyebrow { color: var(--pulse-primary); letter-spacing: 0.18em; text-transform: uppercase; font-weight: 700; font-size: 13px; margin: 0 0 12px; }
.start h1 { font-size: clamp(30px, 4.5vw, 48px); margin: 0 0 16px; }
.lede { color: var(--pulse-text-2); font-size: 18px; margin: 0 0 32px; }
.mode-note { color: var(--pulse-text-2); font-size: 13px; margin: 18px 0 0; }
.mode-note b { color: var(--pulse-text); }
/* Textknopf, kein zweiter Rahmenknopf: Beitreten ist die seltenere Handlung
   und soll nicht mit dem Start konkurrieren (§9.5). */
.start-join { display: inline-block; margin-top: 18px; font-size: 15px; font-weight: 600; color: var(--pulse-primary); text-decoration: underline; text-underline-offset: 3px; }
.start-join:hover { color: var(--pulse-primary-hover); }

/* Meine Räume */
.myrooms { text-align: left; }
@media (max-width: 1199px) { .myrooms { margin-top: 8px; } }
.myrooms-head { font-size: 15px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--pulse-text-2); margin: 0 0 12px; }
.myrooms-list { list-style: none; margin: 0; padding: 0; }
.myroom {
	display: flex; align-items: center; gap: 14px;
	background: var(--pulse-hover);
	border: 2px solid var(--pulse-border);
	border-radius: var(--border-radius-container, 12px);
	padding: 12px 16px; margin-bottom: 10px;
}
.myroom-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.myroom-title { font-size: 19px; font-weight: 700; line-height: 1.25; overflow-wrap: anywhere; }
.myroom-code { font-size: 22px; font-weight: 800; letter-spacing: 0.06em; font-variant-numeric: tabular-nums; }
/* Mit Titel führt der Name — der Code rutscht zur Nebenzeile. */
.myroom-code.is-sub { font-size: 15px; font-weight: 700; color: var(--pulse-text-2); }
.myroom-meta { font-size: 13px; color: var(--pulse-text-2); }
/* Typ deutlich: farbiges Icon + Text-Chip (Quiz = Quiz-Lila, Umfrage = Blau). */
.myroom-type { font-size: 12px; }

/* Zentriertes Zwei-Spalten-Layout */
.present { display: flex; gap: 40px; align-items: flex-start; max-width: 1180px; margin: 0 auto; }
.present-main { flex: 1; min-width: 0; }
.present-aside {
	flex: 0 0 300px;
	display: flex;
	flex-direction: column;
	gap: 4px;
	position: sticky;
	top: 40px;
}
@media (max-width: 880px) {
	.present { flex-direction: column-reverse; }
	.present-aside { flex-basis: auto; width: 100%; max-width: 340px; position: static; margin: 0 auto; }
}

.qr-card { background: #fff; border-radius: var(--border-radius-container, 16px); padding: 16px; margin-bottom: 14px; border: 1px solid var(--pulse-border); }
.join-hint { font-size: 12px; color: var(--pulse-text-2); text-transform: uppercase; letter-spacing: 0.1em; }
.join-hint b { color: var(--pulse-text); font-weight: 700; }
.join-code { font-size: clamp(34px, 4vw, 52px); font-weight: 800; letter-spacing: 0.08em; color: var(--pulse-text); font-variant-numeric: tabular-nums; line-height: 1.05; margin: 2px 0 6px; }
/* Kurze Adresse + Kopieren als eine ruhige Zeile (§5b). */
.join-link { display: flex; align-items: center; gap: 8px; }
.join-url { flex: 1; min-width: 0; font-size: clamp(14px, 1.4vw, 18px); font-weight: 600; color: var(--pulse-primary); word-break: break-all; text-decoration: none; }
.join-url:hover { text-decoration: underline; }
.join-copy { flex: 0 0 auto; }
.conn { font-size: 12px; margin-top: 10px; color: var(--pulse-success); }
.conn.is-off { color: var(--pulse-error); }

/* Deck-Editor */
.deck-head { display: flex; align-items: center; margin-bottom: 20px; gap: 10px; flex-wrap: wrap; }
.deck-head h2 { margin: 0; font-size: 24px; }
.deck-title { display: inline-flex; align-items: center; gap: 8px; min-width: 0; }
.deck-title-txt { overflow-wrap: anywhere; }
.deck-title-txt.is-untitled { color: var(--pulse-text-2); }
.deck-rename { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
/* Erbt Rahmen/Fokus von .pinput, nur Titel-Optik und Breite hier. */
.deck-rename-input { font-size: 20px; font-weight: 700; width: min(380px, 60vw); }
.deck-head-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.deck-list { list-style: none; margin: 0 0 16px; padding: 0; }
.deck-item {
	display: flex; align-items: center; gap: 14px;
	background: var(--pulse-hover);
	border-radius: var(--border-radius-container, 12px);
	padding: 12px 16px; margin-bottom: 10px;
	border: 2px solid var(--pulse-border);
}
.deck-item.is-current { border-color: var(--pulse-primary); }
.drag-handle { cursor: grab; color: var(--pulse-text-2); font-size: 18px; line-height: 1; user-select: none; }
.drag-handle:active { cursor: grabbing; }
.deck-item.sortable-chosen { opacity: 0.9; }
.deck-item.sortable-ghost { opacity: 0.4; border-color: var(--pulse-primary); }
.deck-num { font-weight: 800; color: var(--pulse-text-2); font-variant-numeric: tabular-nums; min-width: 20px; }
.edit-note { color: var(--pulse-warning); font-size: 13px; margin: 0 0 14px; }
.deck-body { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.deck-q { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.deck-type { font-size: 12px; color: var(--pulse-text-2); display: flex; align-items: center; gap: 0.5em; flex-wrap: wrap; }
/* Typ-Tag im Deck (MC/WC/SK/…): SK trägt das „Du"-Magenta der Skala-Familie. */
.deck-tico {
	display: inline-grid; place-items: center; min-width: 2.1em; height: 1.6em; padding: 0 0.4em;
	border-radius: 0.35em; font-size: 0.82em; font-weight: 800; letter-spacing: 0.02em;
	background: var(--pulse-fill); color: var(--pulse-text-2); border: 1px solid var(--pulse-border);
}
.deck-tico.is-rk { font-size: 10px; letter-spacing: -0.02em; }
.pair-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.pair-row .pinput { flex: 1 1 0; min-width: 0; }
.pair-arrow { color: var(--pulse-text-2); }
.deck-imgflag { padding: 0 0.5em; height: 1.6em; display: inline-grid; place-items: center; border-radius: 0.35em; background: var(--pulse-primary-soft); color: var(--pulse-primary); font-weight: 700; }
.opt-rank-pos {
	flex: 0 0 auto; min-width: 2em; height: 2em; display: inline-flex; align-items: center; justify-content: center;
	border-radius: var(--pulse-r-pill); background: var(--pulse-fill); color: var(--pulse-text-2); font-weight: 800;
}
.deck-tico.is-sc { background: var(--pulse-self-soft); color: var(--pulse-self); border-color: transparent; }
.deck-submode { color: var(--pulse-readout); font-weight: 800; }
.deck-empty { color: var(--pulse-text-2); margin: 8px 0 20px; }
.add-btn { margin-top: 4px; }

/* Quiz-Composer */
.quiz-hint { font-size: 13px; color: var(--pulse-text-2); margin: 4px 0 8px; }
.quiz-time { margin-top: 6px; }
/* .opt-correct wird im zweiten Style-Block neu definiert (Form+Farbe+Text, §4.6). */
.tf-pick { display: flex; gap: 12px; margin: 8px 0; }
.tf-pick .pulse-btn { min-width: 96px; }
.num-fields { display: flex; gap: 20px; flex-wrap: wrap; margin: 6px 0; }
.num-fields .words-hint { flex-direction: column; align-items: flex-start; gap: 4px; }
.num-fields input { width: 140px; }

/* Zusammenfassung */
.summary-view { max-width: 760px; margin: 0 auto; }
.summary-warn { margin: 0 0 24px; }
.summary-warn-head { display: block; }
.summary-item { margin-bottom: 36px; padding-bottom: 28px; border-bottom: 1px solid var(--pulse-border); }
.summary-item:last-child { border-bottom: none; }
.summary-q { font-size: 20px; margin: 0 0 20px; display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
.summary-num { font-weight: 800; color: var(--pulse-text-2); font-variant-numeric: tabular-nums; }
.summary-qtext { flex: 1; min-width: 0; }
.summary-type { font-size: 12px; font-weight: 400; color: var(--pulse-text-2); }

/* Endstand */
.lb-view { max-width: 640px; margin: 0 auto; }

/* Präsentation — Status/Chips/Notice kommen jetzt aus dem DS (2. Block).
   Hier nur noch die Layout-Reste. */
.practice-banner { margin: 4px 0 20px; }
/* Demo-/Testleiste (§4): auf --pulse-*, Warn-Chip + gestrichelter warngetönter
   Rahmen = „zählt nicht", Aktionen klar getrennt (Ghost vs. Danger-Outline). */
.demo-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 16px; padding: 10px 14px; border: 1px dashed var(--pulse-warning); border-radius: var(--pulse-r-el); background: var(--pulse-warning-soft); }
.demo-chip { flex: 0 0 auto; }
.demo-lbl { display: inline-flex; align-items: center; gap: 7px; font-size: var(--t-sm); font-weight: 700; color: var(--pulse-warning); }
.demo-count { width: 5em; }
.demo-spacer { flex: 1 1 auto; }
.demo-hint { font-size: var(--t-sm); font-weight: 600; color: var(--pulse-warning); }
/* ── Präsentationsansicht (§9) ────────────────────────────────────────────
   Drei Zeilen: Kopf (64), Körper (Rest), Steuerleiste (96). Der Körper ist die
   einzige Zeile, die schrumpfen darf — minmax(0,1fr), sonst schiebt der
   Inhalt die Leiste aus dem Bild. */
.mod-live { position: relative; height: 100%; display: grid; grid-template-rows: auto minmax(0, 1fr) auto; background: var(--pulse-bg); }
.mod-top { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; min-height: 64px; padding: 8px 20px; border-block-end: 1px solid var(--pulse-border); }
.mod-pos { font-size: 16px; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
.mod-cd { font-size: 20px; }
.mod-here { font-variant-numeric: tabular-nums; }
.mod-spacer { flex: 1 1 auto; }
.mod-code { font-size: 18px; font-weight: 800; letter-spacing: 0.08em; font-variant-numeric: tabular-nums; }
/* Volle Zeile in der umbrechenden Kopfzeile — kein eigenes Rasterfeld. */
.mod-offline { flex: 1 0 100%; margin: 0; }

/* Körper: Vorschau + privates Panel */
.mod-body { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 20px; min-height: 0; padding: 16px 20px; }
/* Unter 1000 px untereinander — die Steuerleiste bleibt unten (§9.8). */
@media (max-width: 999px) { .mod-body { grid-template-columns: minmax(0, 1fr); grid-template-rows: auto minmax(0, 1fr); overflow-y: auto; } }
.mod-canvas { position: relative; min-width: 0; min-height: 0; display: grid; place-items: center; }
.mod-canvas-in { position: relative; overflow: hidden; border: 1px solid var(--pulse-border-strong); border-radius: var(--pulse-r-card); background: var(--pulse-screen-bg); }
/* Der Kiosk rechnet in vw/vh: das iframe MUSS 1280×720 groß sein und wird per
   transform verkleinert. Ein kleineres Fenster ergäbe ein anderes Layout als
   im Saal — und damit eine Vorschau, die nichts beweist. */
.mod-frame { width: 1280px; height: 720px; border: 0; transform-origin: top left; display: block; }
.mod-private { min-width: 0; display: flex; flex-direction: column; gap: 8px; overflow-y: auto; padding: 14px; border: 1px solid var(--pulse-border-strong); border-radius: var(--pulse-r-card); background: var(--pulse-surface, var(--pulse-hover)); font-size: 14px; }
.mod-private-head { display: flex; align-items: center; gap: 8px; margin: 0; font-size: 15px; font-weight: 800; }
.mod-private-sub { margin: 0 0 4px; font-size: 12px; color: var(--pulse-text-2); }
.mod-private-body { min-width: 0; }
.mod-private-empty { color: var(--pulse-text-2); }
.mod-private-lb { margin-top: 8px; }
.mod-standings { min-width: 0; overflow-y: auto; grid-column: 1 / -1; font-size: clamp(16px, 1.6vw, 21px); }
.mod-standings-head { display: flex; align-items: center; gap: 8px; margin: 0 0 12px; font-size: 24px; }

/* Steuerleiste: eine Hauptaktion, links das Blättern, rechts der Eingang */
.mod-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; min-height: 96px; padding: 12px 20px; border-block-start: 1px solid var(--pulse-border); }
.mod-primary { min-width: 220px; }
/* Derselbe Baustein wie am Beamer, nur klein — Zahl, Bezug, Punktraster. */
.mod-intake { font-size: 11px; }
.mod-intake :deep(.intake-num) { font-size: 1.9em; }

/* Beitritt: Zustand statt Spalte (§9.4) */
.mod-joinwrap { position: absolute; inset: 0; z-index: 40; display: flex; justify-content: flex-end; background: rgba(0, 0, 0, 0.22); }
/* Spalte, nicht Fluss: Hinweis, Code und Verweis sind <span>s aus der alten
   QR-Spalte — nebeneinander liefen sie ineinander. */
.mod-joinpanel { width: min(420px, 92vw); padding: 20px; overflow-y: auto; display: flex; flex-direction: column; align-items: stretch; gap: 4px; background: var(--pulse-bg); border-inline-start: 1px solid var(--pulse-border-strong); box-shadow: var(--pulse-shadow-pop); }
.mod-joinpanel .join-code { font-size: 40px; }
.mod-joinpanel .join-hint { line-height: 1.5; }
.mod-join-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.mod-join-head h2 { margin: 0; font-size: 20px; }
.mod-join-qr { margin-bottom: 12px; }

/* Vollbild = der projizierte Modus: nur die Leinwand. Die Steuerleiste kommt
   auf Hover zurück, damit man weiterschalten kann, ohne das Vollbild zu
   verlassen — Antworten stehen dort keine. */
.pulse-mod.is-fs .mod-live { grid-template-rows: minmax(0, 1fr); }
.pulse-mod.is-fs .mod-top, .pulse-mod.is-fs .mod-offline { display: none; }
/* Ohne privates Panel bleibt sonst dessen 360-px-Spalte stehen — die
   Vorschau wäre im Vollbild schmaler als der Schirm. */
.pulse-mod.is-fs .mod-body { padding: 0; grid-template-columns: minmax(0, 1fr); }
.pulse-mod.is-fs .mod-canvas-in { border: 0; border-radius: 0; }
.pulse-mod.is-fs .mod-bar { position: absolute; inset-inline: 0; inset-block-end: 0; background: var(--pulse-bg); transform: translateY(100%); transition: transform 0.15s ease; }
.pulse-mod.is-fs .mod-bar:hover, .pulse-mod.is-fs .mod-bar:focus-within { transform: none; }

/* Quiz-Präsentation: Countdown + Rangliste */
/* Transition = Tick-Intervall (1 s), damit der Balken durchgehend gleitet statt
   pro Sekunde zu springen (0.5 s glitt nur halb und stand dann still). */

/* Composer */
.opt-row { display: flex; gap: 8px; margin-bottom: 10px; align-items: center; }
.words-hint { display: inline-flex; align-items: center; gap: 8px; }
.words-hint--col { flex-direction: column; align-items: flex-start; gap: 4px; }
/* Bildzeile im Composer + Bild in der Präsentation */
.img-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin: 12px 0 4px; }
.img-preview { position: relative; line-height: 0; }
.img-preview img { max-height: 96px; max-width: 200px; border-radius: var(--pulse-r-el); border: 1px solid var(--pulse-border); }
.img-drop { position: absolute; top: -8px; inset-inline-end: -8px; }
.img-pick { cursor: pointer; }
.img-input { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
.img-hint { font-size: 12px; color: var(--pulse-text-2); }
.composer-actions { display: flex; gap: 10px; margin-top: 20px; }
.composer { background: var(--pulse-hover); border-radius: var(--border-radius-container, 14px); padding: 20px; border: 1px solid var(--pulse-border); }

/* Typ ▸ Modus (§4): der Skala-Modus ist eine Unterwahl von „Skala" — eingerückt,
   mit Konnektor und kleineren Chip-Schaltern (nicht ein zweites Segmented). */
.submode { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; margin: 2px 0 18px; margin-left: 8px; padding-left: 16px; border-left: 2px solid var(--pulse-border-strong); }
.submode-arrow { color: var(--pulse-text-2); font-weight: 800; }
.submode-key { font-size: var(--t-cap); font-weight: 700; color: var(--pulse-text-2); text-transform: uppercase; letter-spacing: 0.06em; }
.submode-seg { margin: 0; }
.submode-seg :deep(.pseg) { padding: 3px; }
.submode-seg :deep(.pseg-item) { font-size: var(--t-cap); padding: 5px 10px; }

/* Gerahmte Sektionsgruppen (§4): kräftiger Kopf auf Füllfläche + gerahmter Inhalt. */
.sgroup { border: 1px solid var(--pulse-border-strong); border-radius: var(--pulse-r-el); background: var(--pulse-bg); overflow: hidden; margin-bottom: 12px; }
.sgroup-head { margin: 0; padding: 10px 14px; font-size: var(--t-sm); font-weight: 800; background: var(--pulse-fill); color: var(--pulse-text); border-bottom: 1px solid var(--pulse-border); }
.sgroup-opt { font-weight: 600; color: var(--pulse-text-2); }
.sgroup-in { padding: 14px; display: flex; flex-direction: column; gap: 12px; }
/* Achsen sitzen randlos im Rahmen der Gruppe (die Gruppe rahmt, nicht jede Achse). */
.sgroup-in .axis-block { border: 0; padding: 0; margin: 0; }
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.corner-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.corner-cell { display: flex; flex-direction: column; gap: 4px; }
.corner-lbl { font-size: var(--t-cap); font-weight: 700; color: var(--pulse-text-2); }

/* Eingaben */
.pinput {
	background: var(--pulse-bg) !important;
	color: var(--pulse-text);
	border: 2px solid var(--pulse-border-strong);
	border-radius: var(--border-radius-element, 8px);
	padding: 11px 14px;
	font-size: 16px;
	width: 100%;
	box-sizing: border-box;
}
/* Einheitlicher, sichtbarer Fokus-Ring auf allen Eingabefeldern (§8). */
.pinput:focus-visible { outline: 2px solid var(--pulse-primary); outline-offset: 1px; border-color: var(--pulse-primary); }
.pinput:focus { border-color: var(--pulse-primary); }
.pinput--q { font-size: 20px; margin-bottom: 18px; }
/* NC gibt <select> global eine feste Höhe -> mit unserem Padding wird die Zeile
   unten abgeschnitten. Feste Höhe aufheben, damit die Box zum Inhalt wächst. */
.pinput--sel { width: auto; height: auto !important; min-height: 40px; padding: 8px 12px !important; line-height: normal; }
.pinput--num { width: 5.5em; height: auto !important; min-height: 40px; padding: 8px 12px !important; line-height: normal; }
/* Spektrum-Composer: Aspekt-Zeile (Nummer · Name + Pol-Paar · Entfernen) */
.aspect-row { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 10px; }
.aspect-idx { flex: 0 0 auto; width: 24px; min-height: 40px; display: grid; place-items: center; font-weight: 800; color: var(--pulse-text-2); font-variant-numeric: tabular-nums; }
.aspect-fields { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
.aspect-poles { display: flex; gap: 6px; }
.aspect-poles .pinput--pole { width: 100%; font-size: var(--t-sm); }
.axis-block { border: 1px solid var(--pulse-border); border-radius: var(--pulse-r-el); padding: 10px 12px; margin-bottom: 10px; display: flex; flex-direction: column; gap: 6px; }
.pinput::placeholder { color: var(--pulse-text-2); }

.ic-flip { transform: scaleX(-1); }

</style>

<!-- Ergänzungen Schritt 4/6/7/8/10/11 — DS-nahe Bausteine + Token-Angleich.
     Zweiter scoped-Block: neue Klassen + Overrides in Quell-Reihenfolge nach den
     Alt-Regeln (gleiche Spezifität -> spätere gewinnt). -->
<style scoped>
/* Start: ein CTA + Modus-Segmented (§4.1) */
.start-cta { display: flex; flex-direction: column; align-items: flex-start; gap: 16px; }
.start-cta .is-lg { min-width: 240px; }

/* Meine Räume: Icon-Badge + Text-Status-Chip */
.myroom { border-radius: var(--pulse-r-card); border-color: var(--pulse-border); background: var(--pulse-hover); }
.myroom-meta { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.myroom-chip { font-size: 12px; }

/* Button-Gruppen: nur noch die Zusammenfassung hat mehr als eine Handlung. */
.deck-head-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-inline-start: auto; }
.btn-group { display: inline-flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.btn-group + .btn-group { margin-left: 6px; padding-left: 12px; border-left: 1px solid var(--pulse-border); }
.export-btn { text-decoration: none; }

/* Deck-Editor */
.deck-item { border-radius: var(--pulse-r-card); border-color: var(--pulse-border); background: var(--pulse-hover); }
.deck-item.is-current { border-color: var(--pulse-primary); }
.drag-handle { color: var(--pulse-text-2); display: inline-flex; }
.deck-current-chip { flex: 0 0 auto; font-size: 12px; }

/* ── Übersichten aufgeräumt (Handoff docs/uebersicht-2026-07) ─────────────
   Deck-Kopf: eine Zeile, drei Bedienelemente. Vorher neun Knöpfe in drei
   Gruppen, bei jeder Breite umgebrochen (B1). */
.deck-head-spacer { flex: 1 1 auto; }
.deck-back { flex: 0 0 auto; }
.deck-mode { flex: 0 0 auto; font-size: 12px; }

/* Die Frage IST der Knopf zum Bearbeiten (R5) — damit fällt der Stift je
   Zeile weg. Nextcloud stylt native <button> global über Element-Selektoren
   und schlägt damit jede Klasse: ohne !important kämen Rahmen, Polsterung und
   Mindesthöhe von dort. */
.deck-q {
	display: block;
	width: 100%;
	min-height: 0 !important;
	margin: 0 !important;
	padding: 0 !important;
	border: 0 !important;
	background: transparent !important;
	color: inherit !important;
	font: inherit !important;
	font-weight: 600 !important;
	text-align: start;
	cursor: pointer;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.deck-q:hover { text-decoration: underline; text-underline-offset: 3px; }
.deck-q:focus-visible { outline: 2px solid var(--pulse-primary); outline-offset: 2px; border-radius: 4px; }

/* Raumkarte: die Karte selbst öffnet den Raum (R6). Ein gefülltes „Open" je
   Karte hätte bei sieben Räumen sieben Hauptaktionen ergeben (B4). */
.myroom { padding: 0; gap: 0; }
.myroom-hit {
	flex: 1;
	min-width: 0;
	display: flex;
	align-items: center;
	gap: 14px;
	min-height: 0 !important;
	margin: 0 !important;
	padding: 12px 16px !important;
	border: 0 !important;
	border-radius: var(--pulse-r-card) !important;
	background: transparent !important;
	color: inherit !important;
	font: inherit !important;
	text-align: start;
	cursor: pointer;
}
.myroom-hit:hover { background: var(--pulse-fill) !important; }
.myroom-hit:focus-visible { outline: 2px solid var(--pulse-primary); outline-offset: -2px; }
.myroom-body { align-items: flex-start; }
.myroom-go { flex: 0 0 auto; color: var(--pulse-text-2); }
.myroom .pulse-menu { margin-inline-end: 10px; }

/* Erklärtext ist für den ersten Besuch (R7). Schmal stand er beim zehnten
   Besuch genauso da und drückte die erste Raumkarte an den unteren Rand (B5). */
@media (max-width: 1199px) {
	.start.has-rooms .lede,
	.start.has-rooms .mode-note { display: none; }
	.start.has-rooms h1 { font-size: 30px; margin-block-end: 12px; }
}

/* Empty-State (§4.11): gebrandet + ein CTA */
.empty-state { text-align: center; padding: 40px 16px; display: flex; flex-direction: column; align-items: center; gap: 10px; }
.empty-ico { color: var(--pulse-primary); opacity: 0.85; }
.empty-title { font-size: 20px; font-weight: 800; margin: 4px 0 0; }
.empty-sub { color: var(--pulse-text-2); margin: 0 0 8px; max-width: 40ch; }

/* Composer */
.composer { border-radius: var(--pulse-r-card); border-color: var(--pulse-border); background: var(--pulse-hover); }
.composer-seg { margin: 4px 0 18px; }
/* Korrekt-Marker (§4.6): Form (Box) + Farbe (grün) + Text („richtig") */
.opt-correct {
	appearance: none !important; -webkit-appearance: none;
	flex: 0 0 auto; display: inline-flex; align-items: center; gap: 8px;
	min-height: 44px; padding: 0 14px 0 10px;
	border: 2px solid var(--pulse-border-strong); border-radius: var(--pulse-r-pill);
	background: var(--pulse-bg); color: var(--pulse-text-2);
	font: inherit; font-size: 14px; font-weight: 700; cursor: pointer;
	box-shadow: none; margin: 0;
	transition: background 0.1s ease, border-color 0.1s ease, color 0.1s ease;
}
.opt-correct:focus-visible { outline: 2px solid var(--pulse-primary); outline-offset: 2px; }
.opt-correct-box {
	flex: 0 0 auto; display: grid; place-items: center;
	width: 22px; height: 22px; border-radius: 50%;
	border: 2px solid var(--pulse-border-strong); color: #fff;
}
.opt-correct.is-multi .opt-correct-box { border-radius: var(--pulse-r-el); }
.opt-correct.is-on { border-color: var(--pulse-success); color: var(--pulse-success); background: var(--pulse-success-soft); }
.opt-correct.is-on .opt-correct-box { background: var(--pulse-success); border-color: var(--pulse-success); }
@media (prefers-reduced-motion: reduce) { .opt-correct { transition: none; } }

/* Eingaben auf Pulse-Tokens */
.pinput { background: var(--pulse-bg) !important; color: var(--pulse-text); border: 2px solid var(--pulse-border-strong); border-radius: var(--pulse-r-el); }
.pinput:focus { border-color: var(--pulse-primary); }
.pinput::placeholder { color: var(--pulse-text-2); }

/* Auto-Weiter-Chip in der Steuerleiste */
.auto-next { font-variant-numeric: tabular-nums; }

/* ── Quiz im eigenen Tempo: Deck (Stufe 4.2) ────────────────────────────── */
.deck-state { flex: 0 0 auto; font-size: 12px; font-variant-numeric: tabular-nums; }
/* Kopf im eigenen Tempo: Titel und Aktionen oben, die Status-Chips (Modus,
   „Open until …") in einer eigenen Zeile darunter. Mit langem Titel und
   Frist-Chip brach sonst „Show progress" allein in die zweite Zeile. Das
   ::after ist der Zeilenumbruch; die DOM-Reihenfolge bleibt. */
.deck-head.is-paced { row-gap: 4px; }
.deck-head.is-paced::after { content: ''; order: 1; flex-basis: 100%; height: 0; }
.deck-head.is-paced .deck-mode,
.deck-head.is-paced .deck-state { order: 2; }
.deck-lock-note { margin: 4px 0 20px; }
/* Gesperrte Frage: bleibt voll lesbar — NC dimmt jeden deaktivierten
   <button> auf halbe Deckkraft, das sähe aus wie gelöscht. */
.deck-q:disabled { opacity: 1 !important; cursor: default; }
.deck-q:disabled:hover { text-decoration: none; }
.myroom-pace { font-variant-numeric: tabular-nums; }

</style>
