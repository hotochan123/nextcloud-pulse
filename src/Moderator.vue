<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pulse-mod" :class="{ 'is-fs': isFullscreen, 'is-live': liveFrame, 'is-start': phase === 'start' }">
		<!--
		  Start screen (§9.5): two columns from 1200 px. Before, there were about
		  150 px of empty space above the header block and "My rooms" started so
		  far down that the first room card was cut off — the second column
		  solves both in one go.
		-->
		<section v-if="phase === 'start'" class="start" :class="{ 'has-rooms': myRooms.length }">
			<div class="start-intro">
				<p class="eyebrow">Pulse</p>
				<h1>{{ t('pulse', 'Live poll or quiz') }}</h1>
				<p class="lede">{{ t('pulse', 'Build a deck of questions, project code and QR, and click through it during your talk.') }}</p>

				<!-- One CTA + mode segmented control (§4.1): pick the mode first, then ONE start button -->
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

				<!-- Joining is the rarer action and therefore appears as a
				     text button, not as a second outlined button next to Start. -->
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

		<!-- Summary: all questions with results + CSV export -->
		<section v-else-if="phase === 'summary'" class="summary-view">
			<div class="deck-head">
				<h2>{{ t('pulse', 'Summary') }}</h2>
				<div class="deck-head-actions">
					<div class="btn-group">
						<button class="pulse-btn is-secondary is-sm" @click="goHome"><PulseIcon name="chevron" size="1em" class="ic-flip" /> {{ t('pulse', 'My rooms') }}</button>
						<button class="pulse-btn is-secondary is-sm summary-back" @click="summaryBack">{{ summaryToPace ? t('pulse', 'Back to progress') : t('pulse', 'Back to the deck') }}</button>
					</div>
					<!-- Self-paced: next to "Participants/Answers as CSV" (run view)
					     this is the third CSV — hence the unambiguous name. -->
					<a class="pulse-btn is-primary is-sm export-btn" :href="exportUrl" download>{{ isPaced ? t('pulse', 'Results per question (CSV)') : t('pulse', 'Export CSV') }}</a>
				</div>
			</div>
			<!-- While the quiz is open, this page shows the solutions — do not mirror it. -->
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

		<!-- Deck editor: create and sort questions, set up the flow (§9.6 — stays) -->
		<section v-else-if="phase === 'deck'" class="present">
			<div class="present-main">
				<!-- ── DECK EDITOR ─────────────────────────────────────────── -->
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
					<!-- Empty deck: the header has no primary action, the empty state in the
					     middle carries it. While a form is open, the header steps
					     back — the filled button then belongs to the form (R1), including
					     the "Open quiz" dialog in self-paced mode. -->
					<button v-if="deckPrimary" ref="deckPrimary" class="pulse-btn" :class="deckQuiet ? 'is-secondary' : 'is-primary'" @click="deckPrimary.act()"><PulseIcon :name="deckPrimary.icon" size="1em" /> {{ deckPrimary.label }}</button>
				</div>
				<p v-if="isPractice" class="pulse-notice is-warn practice-banner"><PulseIcon name="warning" size="1.1em" class="pulse-notice-ico" /><span>{{ t('pulse', 'Practice run active — votes do not count towards the leaderboard. For the real start switch the practice run off in the menu (empties the room).') }}</span></p>
				<!-- Self-paced from opening on: the frozen order stays until the
				     reset. While the quiz is open, resetting is not possible yet —
				     hence the intermediate step "close first" there. -->
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

					<!-- Image for the question (optional). Uploaded on save and
					     re-encoded + downscaled on the server. -->
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

					<!-- Multiple choice + multiple answers: options -->
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

					<!-- Matching: one pair per row. The right side appears shuffled on the phone. -->
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

					<!-- True/false -->
					<template v-else-if="draft.type === 'truefalse'">
						<p class="quiz-hint">{{ t('pulse', 'Which one is correct?') }}</p>
						<div class="tf-pick">
							<button class="pulse-btn" :class="{ 'is-on': draft.correctIndex === 0 }" @click="draft.correctIndex = 0">{{ t('pulse', 'True') }}</button>
							<button class="pulse-btn" :class="{ 'is-on': draft.correctIndex === 1 }" @click="draft.correctIndex = 1">{{ t('pulse', 'False') }}</button>
						</div>
					</template>

					<!-- Number guess -->
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

					<!-- Free text -->
					<template v-else-if="draft.type === 'text'">
						<p class="quiz-hint">{{ t('pulse', 'Accepted answer(s) — you can approve further spellings later during the reveal:') }}</p>
						<div v-for="(a, i) in draft.answers" :key="i" class="opt-row">
							<input v-model="draft.answers[i]" ref="optionInputs" class="pinput" type="text" :placeholder="i === 0 ? t('pulse', 'Correct answer') : t('pulse', 'Another accepted answer')">
							<button v-if="draft.answers.length > 1" class="pulse-btn is-secondary is-icon" :title="t('pulse', 'Remove answer')" :aria-label="t('pulse', 'Remove answer')" @click="removeAnswer(i)"><PulseIcon name="close" size="1.05em" /></button>
						</div>
						<button v-if="draft.answers.length < 8" class="pulse-btn is-secondary is-sm" @click="addAnswer"><PulseIcon name="plus" size="1em" /> {{ t('pulse', 'Answer') }}</button>
					</template>

					<!-- Scale (poll) — mode Single | Spectrum | Compass -->
					<template v-else-if="draft.type === 'scale'">
						<div class="submode">
								<span class="submode-arrow" aria-hidden="true">▸</span>
								<span class="submode-key">{{ t('pulse', 'Scale mode') }}</span>
								<PulseSegmented v-model="draft.scaleMode" :options="scaleModeOptions" :label="t('pulse', 'Scale mode')" class="submode-seg" />
							</div>
						<!-- Single/Spectrum: shared maximum X -->
						<label v-if="draft.scaleMode !== 'compass'" class="words-hint">{{ draft.scaleMode === 'spectrum' ? t('pulse', 'Scale 0 to') : t('pulse', 'Scale 1 to') }}
							<input v-model.number="draft.scaleMax" class="pinput pinput--num" type="number" min="2" max="20" step="1">
						</label>
						<!-- Single: optional pole labels at 1 and X -->
						<template v-if="draft.scaleMode === 'single'">
							<input v-model="draft.minLabel" class="pinput" type="text" :placeholder="t('pulse', 'Label for 1 (optional, e.g. “disagree”)')">
							<input v-model="draft.maxLabel" class="pinput" type="text" :placeholder="t('pulse', 'Label for {max} (optional, e.g. “fully agree”)', { max: draft.scaleMax })">
						</template>
						<!-- Spectrum: 3–8 aspects, one slider each from 0 to X -->
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
						<!-- Compass: framed sections (§4) — axes · range & display · corner labels -->
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
					<!-- Word cloud (poll) -->
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

			<!-- Join panel with QR -->
			<aside class="present-aside">
				<div class="qr-card">
					<QrCode :value="joinUrlFull" />
				</div>
				<!-- One calm join unit: code (large) + short address + copy (§5b). -->
				<span class="join-hint">{{ t('pulse', 'Join in — code at') }} <b>{{ joinBase }}</b></span>
				<span class="join-code">{{ spacedCode }}</span>
				<div class="join-link">
					<a class="join-url" :href="joinPath" target="_blank" rel="noopener">{{ joinUrl }}</a>
					<button class="pulse-btn is-secondary is-icon is-xs join-copy" :title="t('pulse', 'Copy join link')" :aria-label="t('pulse', 'Copy join link')" @click="copyJoin"><PulseIcon name="copy" size="1em" /></button>
				</div>
				<!-- Self-paced: the status line instead of "● live" — who is already here,
				     even before opening. It also keeps the window up to date (deadline,
				     second tab). -->
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

		<!-- Self-paced from opening on: progress per person and ONE
		     primary action (close, release …), with its own /progress fetch. -->
		<PaceRun v-else-if="phase === 'pace'"
			:key="room.code"
			:room="room"
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
		  Presentation and final standings (§9): ONE frame — header, canvas
		  preview next to the private panel, control bar with exactly ONE
		  primary action. Before, six equally weighted buttons stood side by side,
		  the most prominent one was the destructive one ("Finish"), and the most
		  frequent action — advancing and revealing — had no button at all
		  but hid in chevrons (N9).
		-->
		<section v-else class="mod-live">
			<header class="mod-top">
				<span class="mod-pos">{{ headPos }}</span>
				<CountdownRing v-if="showCountdown" class="mod-cd" :remaining="quizRemaining" :total="(currentPoll && currentPoll.timeLimit) || 0" />
				<span class="pulse-chip is-live mod-here" :title="t('pulse', '{count} participants active in the last few seconds', { count: presentCount })"><span class="pulse-chip-dot" />{{ t('pulse', '{count} here', { count: presentCount }) }}</span>
				<span v-if="isPractice" class="pulse-chip is-warning"><PulseIcon name="warning" size="1em" /> {{ t('pulse', 'Practice run') }}</span>
				<span v-if="isRevealAtEnd" class="pulse-chip is-accent"><PulseIcon name="check_ring" size="1em" /> {{ t('pulse', 'Reveal at the end') }}</span>
				<span v-if="isQuiz && room && room.joinsLocked" class="pulse-chip is-locked"><PulseIcon name="lock" size="1em" /> {{ t('pulse', 'Joining locked') }}</span>
				<span class="mod-spacer" />
				<span v-if="room" class="mod-code" :title="t('pulse', 'Room code')">{{ spacedCode }}</span>
				<button class="pulse-btn is-secondary is-sm" :aria-expanded="joinOpen ? 'true' : 'false'" @click="toggleJoin">{{ t('pulse', 'Join') }}</button>
				<!-- Overflow menu: everything needed once or twice per session,
				     instead of glowing permanently next to the work area. -->
				<PulseMenu :items="menuItems" :label="t('pulse', 'More actions')" small />
				<p v-if="!online" class="pulse-notice is-warn mod-offline" role="status"><PulseIcon name="warning" size="1.1em" class="pulse-notice-ico" /><span>{{ t('pulse', 'No connection — the room is not answering. The main action stays disabled until it is back.') }}</span></p>
			</header>

			<div class="mod-body">
				<!-- Final standings: the same frame, a different body. No private panel —
				     after finish() (/end) the room sees the same standings. Only the
				     interim standings via the menu stay with the moderator (header: "Leaderboard"). -->
				<div v-if="phase === 'leaderboard'" class="mod-standings">
					<h2 class="mod-standings-head"><PulseIcon name="ranking" size="1em" /> {{ quizOver ? t('pulse', 'Final standings') : t('pulse', 'Leaderboard') }}</h2>
					<Leaderboard v-if="leaderboard.length" :rows="leaderboard" :podium="true" :limit="30" />
					<p v-else class="deck-empty">{{ t('pulse', 'No points yet — nobody has played along.') }}</p>
					<!-- Removing a name that should not stand here — also after the end.
					     Not in fullscreen: that is the mode that gets projected. -->
					<LivePlayers v-if="isQuiz && room && !isFullscreen"
						class="mod-standings-players"
						:code="room.code"
						:joins-locked="!!room.joinsLocked"
						@room="onPaceRoom"
						@removed="onPlayerRemoved"
						@conflict="refreshRoom(true)" />
				</div>
				<template v-else>
					<!-- Canvas preview instead of a replica: the same page as
					     /screen/{code}, scaled down to 16:9. A rebuild would be a
					     second truth that drifts apart; this way the moderator
					     sees what the room sees. Read-only — the direction
					     "projector mirrors the moderator" stays forbidden. -->
					<div ref="canvas" class="mod-canvas">
						<div class="mod-canvas-in" :style="canvasStyle">
							<iframe v-if="room" class="mod-frame" :style="frameStyle" :src="screenPath" :title="t('pulse', 'Preview of the projector view')" />
						</div>
					</div>
					<!-- "Only for you" (E3): the state the room does NOT see.
					     In fullscreen the panel is not hidden but not in the
					     document at all — fullscreen is exactly the mode that
					     gets projected. This is not a question of looks but one
					     of security (§9.3). -->
					<aside v-if="!isFullscreen" class="mod-private">
						<h2 class="mod-private-head"><PulseIcon name="lock" size="1.05em" /> {{ t('pulse', 'Only for you') }}</h2>
						<p class="mod-private-sub">{{ t('pulse', 'Live distribution — this panel never goes on the projector.') }}</p>
						<div v-if="currentPoll" class="mod-private-body">
							<TextGrading v-if="currentPoll.type === 'text'" :results="results" @grade="gradeAnswer" />
							<ResultsView v-else dense :results="results" :correct-id="currentPoll.correctOption || ''" :correct-ids="((currentPoll.answerKey || {}).correct) || []" :answer-key="currentPoll.answerKey || null" />
						</div>
						<p v-else class="mod-private-empty">{{ t('pulse', 'No question yet — the room shows the code and waits.') }}</p>
						<Leaderboard v-if="isQuiz && leaderboard.length" class="mod-private-lb" :rows="leaderboard" :limit="5" />
						<!-- Anyone with the code can put a name on the podium: remove it here (L1). -->
						<LivePlayers v-if="isQuiz && room"
							:code="room.code"
							:joins-locked="!!room.joinsLocked"
							@room="onPaceRoom"
							@removed="onPlayerRemoved"
							@conflict="refreshRoom(true)" />

						<!-- Demo/test tool: fill the active question with many votes (preview) -->
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
				<!-- The only filled button on the screen, and it knows what is
				     up next (§9.1). Colored as destructive only at the final standings. -->
				<button class="pulse-btn mod-primary is-lg" :class="primary.danger ? 'is-danger is-solid' : 'is-primary'" :disabled="!online || busy" :title="online ? '' : t('pulse', 'No connection to the room')" @click="runPrimary">
					<PulseIcon :name="primary.icon" size="1.05em" /> {{ primary.label }}
				</button>
				<button v-if="canNext && primary.key !== 'next' && !(phase === 'leaderboard' && quizOver)" class="pulse-btn is-secondary" @click="next">{{ t('pulse', 'Next') }} <PulseIcon name="chevron" size="1em" /></button>
				<span v-if="advanceIn !== null" class="pulse-chip is-accent auto-next" role="status"><PulseIcon name="timer" size="1em" /> {{ advanceLabel }} {{ t('pulse', 'in {seconds} s', { seconds: advanceIn }) }}</span>
				<button v-if="advanceIn !== null" class="pulse-btn is-secondary is-sm" @click="cancelAdvance">{{ t('pulse', 'Stop') }}</button>
				<span class="mod-spacer" />
				<!-- The number sits next to what it refers to, not alone in empty space (D20):
				     the same building block as on the projector, just small. -->
				<IntakeBoard v-if="phase === 'present' && currentPoll" class="mod-intake" :answered="resultsTotal" :present="presentCount" :compact="true" />
			</footer>

			<!-- Joining is a state, not a column (D20): open as long as
			     nobody is connected — that is when it is needed —, then
			     collapsed once and reachable via the button in the header.
			     The link sits only here, not permanently. -->
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
/*
 * Section references (§…) and review IDs (B1, R3, …) point to the design notes
 * of the redesign and of the self-paced quiz, which are not in the public
 * repository (see "References in code comments" in the README).
 */
import axios from '@nextcloud/axios'
import { MOD_TIMEOUT, roomsApi, roomApi, pollImage, participantPage, joinPage, screenPage, roomPage, home, addinManifest, openProjector } from './util/routes.js'
import { t, n } from './util/l10n.js'
import { showError, showSuccess, serverMessage } from './toast.js'
import draggable from 'vuedraggable'
import ResultsView from './components/ResultsView.vue'
import QrCode from './components/QrCode.vue'
import Leaderboard from './components/Leaderboard.vue'
import LivePlayers from './components/LivePlayers.vue'
import TextGrading from './components/TextGrading.vue'
import PulseIcon from './components/ui/PulseIcon.vue'
import PulseSegmented from './components/ui/PulseSegmented.vue'
import PulseMenu from './components/ui/PulseMenu.vue'
import CountdownRing from './components/CountdownRing.vue'
import IntakeBoard from './components/IntakeBoard.vue'
import { pulseConfirm } from './util/confirm.js'
import { formatCode, remainingSecs, paceStateChip, fmtAgo } from './util/format.js'
import { csvUrl } from './util/csv.js'
import { isPacedRoom, windowState, progressCounts } from './util/pace.js'
import { QUESTION_TYPES, emptyDraft, typeOptionsFor, typeTag, scaleModeLabel, typeLabel, answerHint, lossText } from './util/question-types.js'
import pollingMixin from './mixins/polling.js'
import PaceOpenDialog from './components/PaceOpenDialog.vue'
import PaceDeckStatus from './components/PaceDeckStatus.vue'
import PaceRun from './components/PaceRun.vue'

export default {
	name: 'Moderator',
	mixins: [pollingMixin],
	components: { ResultsView, QrCode, Leaderboard, LivePlayers, TextGrading, PulseIcon, PulseSegmented, PulseMenu, CountdownRing, IntakeBoard, draggable, PaceOpenDialog, PaceDeckStatus, PaceRun },
	data() {
		return {
			phase: 'start', // 'start' | 'deck' | 'present' | 'summary' | 'leaderboard' | 'pace' (run view, self-paced)
			startMode: 'poll', // mode segmented control on the start screen (§4.1)
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
			joinOpen: false,   // join panel — a state, not a column (§9.4)
			frameScale: 0.5,   // scale of the canvas preview (1280×720 -> box)
			tickTimer: null,
			advanceTimer: null,  // auto-advance after the timer ends
			advanceAtSec: 0,     // local target second (0 = nothing scheduled)
			polling: false,
			resVersion: '',
			serverSkew: 0,
			nowSec: Math.floor(Date.now() / 1000),
			renaming: false,   // deck header: inline rename open?
			renameDraft: '',
			draft: emptyDraft(),
			// ── Self-paced quiz ──
			paceDialog: null,    // null | 'open' — the "open quiz" dialog in the deck
			paceFocus: false,    // the next run view puts the focus on its title
			paceCounts: null,    // last counts from PaceDeckStatus (confirmation texts)
			paceSkew: 0,         // server time − laptop time in s (from /progress)
			paceBusy: false,     // switching in progress (count, confirm, send)
			summaryFrom: 'deck', // where the summary was opened from ('deck' | 'pace')
		}
	},
	computed: {
		// Freely chosen room name; empty = the UI falls back to "Your deck/quiz".
		roomTitle() {
			return (this.room && this.room.title) || ''
		},
		isQuiz() {
			return !!this.room && this.room.mode === 'quiz'
		},
		// Start segmented control: poll vs. quiz.
		modeOptions() {
			return [
				{ value: 'poll', label: t('pulse', 'Poll'), icon: 'poll' },
				{ value: 'quiz', label: t('pulse', 'Quiz'), icon: 'quiz' },
			]
		},
		// Composer segmented control for the scale mode (poll): single vs. spectrum.
		scaleModeOptions() {
			return [
				{ value: 'single', label: t('pulse', 'Single') },
				{ value: 'spectrum', label: t('pulse', 'Spectrum') },
				{ value: 'compass', label: t('pulse', 'Compass') },
			]
		},
		// Composer segmented control: question types per mode (util/question-types.js).
		typeOptions() {
			return typeOptionsFor(this.isQuiz)
		},
		isPractice() {
			return !!this.room && !!this.room.practice
		},
		isRevealAtEnd() {
			return !!this.room && !!this.room.revealAtEnd
		},
		// ── Self-paced quiz (stage 4) ───────────────────────────────────────
		// Every new branch hangs off this; moderated, everything stays as before.
		isPaced() {
			return isPacedRoom(this.room)
		},
		// Window state against the server time: a deadline that is just expiring
		// closes the deck immediately — the next fetch merely confirms it.
		// Moderated: '' (and then without depending on the seconds tick).
		paceState() {
			return this.isPaced ? windowState(this.room.window, this.nowSec + this.paceSkew) : ''
		},
		// From opening until the reset: no creating, editing, deleting or
		// sorting questions (otherwise the server says 409).
		deckLocked() {
			return this.isPaced && !!this.paceState && this.paceState !== 'draft'
		},
		// State chip in the deck header — none in draft.
		deckStateChip() {
			return this.deckLocked ? this.paceChip(this.room.window) : null
		},
		// "· 30s ·" per row only when the time actually applies (open without a timer: no).
		showRowLimit() {
			return !this.isPaced || this.paceState === 'draft' || !!(this.room.window && this.room.window.timed)
		},
		spacedCode() {
			return this.room ? formatCode(this.room.code) : ''
		},
		joinPath() {
			return this.room ? participantPage(this.room.code) : ''
		},
		joinUrl() {
			return this.room ? window.location.host + this.joinPath : ''
		},
		joinUrlFull() {
			return this.room ? window.location.origin + this.joinPath : ''
		},
		joinBase() {
			return window.location.host + joinPage()
		},
		joinPagePath() {
			return joinPage()
		},
		// With the request token in the query: an <a download> sends no header,
		// and without the token the route answered with 412 (util/csv.js).
		exportUrl() {
			return this.room ? csvUrl(this.room.code) : ''
		},
		// Opened from the run view: going back leads there, not into the deck.
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
		// Seconds until auto-advance (null = nothing scheduled).
		advanceIn() {
			return this.advanceAtSec ? Math.max(0, this.advanceAtSec - this.nowSec) : null
		},
		// What autoAdvance is about to do — after the last question of a practice run there
		// are no final standings, only the reveal (with "Reveal at the end").
		advanceLabel() {
			if (this.canNext) return t('pulse', 'Next question')
			return this.isPractice ? t('pulse', 'Reveal') : t('pulse', 'Final standings')
		},

		// ── Presentation view (§9) ──────────────────────────────────────────
		// Presentation, final standings and the self-paced run view share
		// one frame: full height, their own header and footer, no page margin
		// from .pulse-mod.
		liveFrame() {
			return this.phase === 'present' || this.phase === 'leaderboard' || this.phase === 'pace'
		},
		// Revealed = the server has released the question. The same rule as
		// StateService::publicState — not a second, local "already shown".
		// With "Reveal at the end" only 'ended' counts: a 'locked' arises there
		// only when the switch was flipped after revealing, and it is hidden from
		// the room.
		isRevealed() {
			if (!this.currentPoll) return false
			const st = this.currentPoll.status
			return this.isQuiz && this.isRevealAtEnd ? st === 'ended' : st === 'locked' || st === 'ended'
		},
		showCountdown() {
			return this.isQuiz && !this.isRevealed && this.quizRemaining !== null
		},
		headPos() {
			// Final standings only once the quiz has ended — in the middle these are
			// interim standings that only the moderator sees.
			if (this.phase === 'leaderboard') return this.quizOver ? t('pulse', 'Final standings') : t('pulse', 'Leaderboard')
			if (!this.currentPoll) return t('pulse', 'No question yet')
			return t('pulse', 'Question {number} of {total}', { number: this.currentIndex + 1, total: this.deck.length })
		},
		// The canvas preview shows the real projector page (same-origin), not
		// a rebuild: two truths would otherwise drift apart.
		screenPath() {
			return this.room ? screenPage(this.room.code) : ''
		},
		canvasStyle() {
			return { width: Math.round(1280 * this.frameScale) + 'px', height: Math.round(720 * this.frameScale) + 'px' }
		},
		frameStyle() {
			return { transform: 'scale(' + this.frameScale + ')' }
		},
		/*
		 * THE primary action (§9.1) — the only filled button, and it knows what is
		 * up next. Before, the most frequent action had no button at all,
		 * while the destructive one glowed permanently.
		 */
		primary() {
			if (this.phase === 'leaderboard') {
				// Without a running question there is nothing to finish (/end would go nowhere).
				return this.quizOver || !this.currentPoll
					? { key: 'deck', label: t('pulse', 'Back to the deck'), icon: 'chevron', act: this.backToDeck }
					: { key: 'end', label: t('pulse', 'Finish quiz'), icon: 'check', act: this.finish, danger: true }
			}
			if (!this.currentPoll) {
				return { key: 'add', label: t('pulse', 'Add a question'), icon: 'plus', act: this.addQuestion }
			}
			// "Reveal at the end": questions are not revealed one by one, it just moves on.
			if (this.isQuiz && this.isRevealAtEnd) {
				if (this.canNext) return { key: 'next', label: t('pulse', 'Next question'), icon: 'play', act: this.next }
				// Practice run: reveal yes — that is what it is for, you check the questions —,
				// final standings no. Afterwards the same button ends the run.
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
				// finish, not openLeaderboard: with this one click the final standings
				// should show on the projector and the phones, not just here.
				return { key: 'standings', label: t('pulse', 'Show final standings'), icon: 'ranking', act: this.finish }
			}
			return { key: 'finish', label: t('pulse', 'Finish'), icon: 'check', act: this.finish }
		},
		// Quiz over = the last question is 'ended' (set by /end).
		quizOver() {
			return this.deck.some((p) => p.status === 'ended')
		},
		/*
		 * Deck header: one row, three controls. Before, there were nine
		 * buttons here in three hard-wired groups — navigation, state switches
		 * and views equally loud side by side, at every width (overview design
		 * notes B1, not in the public repository).
		 *
		 * The two quiz switches are NO LONGER buttons whose label changes
		 * along with them: as a `menuitemcheckbox` the text stays fixed and the check mark
		 * carries the state (R3). "Practice run off" could otherwise be read as a
		 * state just as well as an effect — and the wrong reading costs the
		 * real run.
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
			// Self-paced mode has neither interim standings to show nor a
			// "Reveal at the end" — the window governs both.
			if (this.isQuiz && !this.isPractice && this.deck.length && !paced) {
				items.push({ key: 'lb', label: this.quizOver ? t('pulse', 'Final standings') : t('pulse', 'Leaderboard'), icon: 'ranking', act: this.openLeaderboard })
			}
			if (this.isQuiz) {
				if (!paced) {
					items.push({ key: 'revealend', label: t('pulse', 'Reveal at the end'), icon: 'check_ring', checked: this.isRevealAtEnd, act: this.toggleRevealAtEnd })
				}
				// Ending the practice run also works while closed (only test data is lost).
				items.push({ key: 'practice', label: t('pulse', 'Practice run'), icon: 'warning', checked: this.isPractice, act: this.togglePractice, ...this.paceLock(!this.isPractice) })
				items.push({ key: 'pace', label: t('pulse', 'Self-paced'), checked: paced, act: this.togglePace, ...this.paceLock(true) })
			}
			// Self-paced until the release; a moderated quiz at any time (L1).
			if (this.isQuiz && (!paced || this.paceState !== 'released')) {
				items.push({ key: 'lock', label: t('pulse', 'Lock joining'), checked: !!this.room.joinsLocked, act: this.toggleJoinsLocked })
			}
			if (this.deck.length) {
				items.push({ key: 'reset', label: paced ? t('pulse', 'Reset quiz') : t('pulse', 'Reset votes'), icon: 'reset', act: this.resetRoom, danger: true, ...this.paceLock(false) })
			}
			return items
		},
		/*
		 * THE primary action in the deck header. Moderated: "Start presenting"; self-paced:
		 * "Open quiz …" (draft) — presenting is a 409 there. After
		 * opening, "Show progress" leads back to the run view. Empty
		 * deck: none.
		 */
		deckPrimary() {
			if (!this.deck.length) return null
			if (!this.isPaced) return { key: 'present', label: t('pulse', 'Start presenting'), icon: 'play', act: this.startPresenting }
			if (this.paceState === 'draft') return { key: 'open', label: t('pulse', 'Open quiz …'), icon: 'play', act: this.openPaceDialog }
			return { key: 'progress', label: t('pulse', 'Show progress'), icon: 'users', act: this.showProgress }
		},
		// The run view belongs to an opened self-paced room. If it is still
		// up while the room was reset (draft), switched over or changed from
		// a second tab, it goes back to the deck.
		paceRunStale() {
			return this.phase === 'pace' && (!this.isPaced || this.paceState === 'draft')
		},
		// While a form is open, the filled button belongs to it (R1).
		deckQuiet() {
			return this.showComposer || this.renaming || !!this.paceDialog
		},
		/*
		 * Overflow menu: everything needed once or twice per session.
		 * "Finish" sits down here while the quiz is running — a red button
		 * next to the work area either gets ignored over 90 minutes
		 * or hit by accident.
		 */
		menuItems() {
			const items = []
			// Open again: in a quiz this restarts the timer (setCurrent); in a
			// poll unlocking is enough — setCurrent does not touch the status there.
			if (this.phase === 'present' && this.isRevealed && !this.isRevealAtEnd) {
				items.push({ key: 'reopen', label: t('pulse', 'Open again'), icon: 'reset',
					act: this.isQuiz ? this.reopen : () => this.setLock(false) })
			}
			if (this.phase === 'present' && this.currentPoll) {
				items.push({ key: 'reset', label: t('pulse', 'Reset this question'), icon: 'reset', act: this.resetCurrent })
			}
			// Once everyone is in, new names stay out; whoever is playing gets back in.
			if (this.isQuiz && this.room) {
				items.push({ key: 'lock', label: t('pulse', 'Lock joining'), checked: !!this.room.joinsLocked, act: this.toggleJoinsLocked })
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
		// The preview's scale depends on the box, the box on the phase.
		phase(v) {
			this.$nextTick(this.fitFrame)
			if (v !== 'pace') this.paceFocus = false
		},
		// Room no longer self-paced (switched over, other tab): old
		// numbers and an open dialog belong to the previous state.
		isPaced(v) {
			if (!v) {
				this.paceCounts = null
				this.paceDialog = null
			}
		},
		paceRunStale(v) {
			if (v) this.phase = 'deck'
		},
		// The timer ending (transition to 0, exactly once) drives the quiz on by
		// itself — no more manual clicking needed:
		//  · Reveal per question: reveal first (answer + leaderboard), show briefly,
		//    then automatically on to the next question (or final standings after the last).
		//  · Reveal at the end: a short breather, then straight on.
		// The scheduled jump can be cancelled via "Stop" (advanceIn/cancelAdvance).
		quizRemaining(val, old) {
			if (!(this.isQuiz && val === 0 && old > 0 && this.phase === 'present' && this.currentId)) {
				return
			}
			if (this.isRevealAtEnd) {
				// Already revealed (practice run: "Reveal" clicked before time ran out) -> nothing
				// left to do; otherwise the timer would end the run unasked.
				if (!this.isRevealed) this.scheduleAdvance(2)
			} else if (this.quizStatus !== 'locked') {
				this.setLock(true)
				// Free text needs manual grading -> no automatic advance.
				// Practice run after the last question: there are no final standings, the
				// moderator ends the run themselves ("Finish").
				const text = !!this.currentPoll && this.currentPoll.type === 'text'
				if (!text && (this.canNext || !this.isPractice)) this.scheduleAdvance(8)
			}
		},
	},
	created() {
		// Deliberately outside data(): Vue should not wrap the observer in a
		// reactive proxy.
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
			// Deep link/refresh -> load the room; if that fails, normal start + list.
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
		// Deck labels (util/question-types.js), called by the template.
		typeTag,
		scaleModeLabel,
		typeLabel,
		answerHint,
		// Composer: is option i marked as correct (choice = radio, multi = multiple)?
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
		api(code, suffix = '') {
			return roomApi(code, suffix)
		},

		// ── My rooms ────────────────────────────────────────────────────────
		spaced(code) {
			return formatCode(code)
		},
		// Room list: fmtAgo's texts, against the laptop clock.
		ago(ts) {
			return fmtAgo(ts, Date.now() / 1000)
		},
		async fetchMyRooms() {
			try {
				const { data } = await axios.get(roomsApi())
				this.myRooms = data
			} catch (e) {
				// no list -> the start screen stays plain
			}
		},
		// Load a room by code and jump into the editor/presentation. Used by
		// the deep-link refresh and "My rooms".
		async loadRoom(code) {
			try {
				const { data } = await axios.get(this.api(code))
				this.room = data
				this.deck = data.polls || []
				this.currentId = data.activePollId || 0
				this.paceDialog = null
				this.paceCounts = null
				window.history.replaceState(null, '', roomPage(code))
				// Self-paced: never the presentation (every cursor action is a 409
				// there), not even with an empty deck — and no results polling: in
				// draft PaceDeckStatus fetches the numbers, afterwards the run view does.
				if (isPacedRoom(data)) {
					this.phase = windowState(data.window) === 'draft' ? 'deck' : 'pace'
					return true
				}
				if (this.currentId !== 0) {
					this.phase = 'present'
					this.startPolling()
				} else if (!this.deck.length) {
					// §9.8: empty deck -> presentation view with "Add a question".
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
		// Join deep link to the clipboard (§5b). Fallback for older
		// browsers/no Clipboard API (e.g. http context): a temporary text field.
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
		// Copy a room as a template: the server creates the copy with the same
		// questions (without votes). We stay on the list — the copy shows up
		// there as the newest entry.
		async duplicateRoom(r) {
			if (this.busy) return
			this.busy = true
			try {
				const { data } = await axios.post(this.api(r.code, '/duplicate'))
				await this.fetchMyRooms()
				showSuccess(t('pulse', 'Copy created: {room}', { room: data.title || this.spaced(data.code) }))
			} catch (e) {
				// Caps and the rate limit say why (400/429 with a message).
				showError(serverMessage(e, t('pulse', 'Could not copy the room.')))
			} finally {
				this.busy = false
			}
		},
		async deleteRoom(r) {
			let text = t('pulse', 'Room {code} will be deleted, with all its questions and votes. This cannot be undone.', { code: this.spaced(r.code) })
			// Self-paced, open or closed: count first who is in —
			// a running homework would otherwise be gone with one click, answers and all.
			const listed = r.pace === 'self' && r.window ? windowState(r.window, this.nowSec + this.paceSkew) : ''
			if (listed === 'open' || listed === 'closed') {
				const counts = await this.fetchCounts(r.code, true)
				const state = (counts && counts.state) || listed
				if (state === 'open' && counts && counts.joined > 0) {
					text += ' ' + n('pulse', 'The quiz is open and %n person has joined — their answers are lost too.', 'The quiz is open and %n people have joined — their answers are lost too.', counts.joined)
				} else if (state === 'closed') {
					const loss = lossText(counts, 'closed')
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

		// ── Question image ──────────────────────────────────────────────────
		// URL for the moderator image; the file name is appended as ?v= so that a
		// replaced image does not come from the browser cache.
		modImageUrl(poll) {
			if (!this.room || !poll || !poll.image) return ''
			return pollImage(this.room.code, poll.id, poll.image)
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
			// An image that was already saved is only deleted on save.
			this.$set(this.draft, 'imageRemove', !!this.draft.imageExisting)
			if (this.$refs.imageInput) this.$refs.imageInput.value = ''
		},
		// After saving the question: upload or remove the image. Returns the
		// updated question (with the new image file name).
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
				showError(serverMessage(e, t('pulse', 'The image could not be saved.')))
			}
			return poll
		},

		// ── Rename ──────────────────────────────────────────────────────────
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
				// Take over only the title: applyRoom would clear results/leaderboard.
				this.room = { ...this.room, title: data.title }
				const row = this.myRooms.find((r) => r.code === this.room.code)
				if (row) row.title = data.title
			} catch (e) {
				showError(t('pulse', 'Could not rename the room.'))
			}
		},

		// ── Reset / practice run ────────────────────────────────────────────
		// Takes a fresh roomView (the server returns it after a reset/switch)
		// and mirrors it locally: new deck, votes/leaderboard gone, cursor at 0.
		applyRoom(data) {
			this.room = data
			this.deck = data.polls || []
			this.currentId = data.activePollId || 0
			this.results = null
			this.leaderboard = []
			this.resVersion = ''
			// The room rests after being cleared -> if we were presenting, back to the deck.
			if (this.currentId === 0) {
				this.stopPolling()
				if (this.phase === 'present') this.phase = 'deck'
			}
		},
		// counts: optional counts (the run view has its own), otherwise
		// the last ones from PaceDeckStatus. The menu passes no argument.
		async resetRoom(counts) {
			let text = t('pulse', 'All votes, participants and the leaderboard in this room will be deleted. The questions stay.')
			if (this.isPaced) {
				const known = counts && typeof counts === 'object' && 'joined' in counts ? counts : this.paceCounts
				const loss = lossText(known, this.paceState)
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
			// A pure flow flag — clears nothing. The server returns a fresh roomView.
			const on = !this.isRevealAtEnd
			try {
				const { data } = await axios.post(this.api(this.room.code, '/reveal'), { on })
				this.applyRoom(data)
			} catch (e) {
				this.failWrite(e, t('pulse', 'Could not switch the reveal mode.'))
			}
		},
		// Returns whether it was switched (endPracticeThenOpen then reopens
		// the dialog).
		async togglePractice() {
			const on = !this.isPractice
			let text = on
				? t('pulse', 'The votes, participants and leaderboard collected so far will be cleared — the questions stay.')
				: t('pulse', 'Everything is reset for the real run: the test votes and test participants are cleared.')
			// Switching on in self-paced mode clears real results — say so with a number.
			if (on && this.isPaced) {
				const loss = lossText(this.paceCounts, this.paceState)
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

		// ── Self-paced quiz: deck, switches, opening (§0.9, §1.1–1.5) ──
		/*
		 * Locks from table §1.3 as menu fields: open -> disabled with "Close the
		 * quiz first.", closed (only where closedToo) -> disabled with "Release or
		 * reset first." — an unreleased homework should not vanish through
		 * a switch. Moderated: never locked.
		 */
		paceLock(closedToo) {
			if (!this.isPaced) return {}
			if (this.paceState === 'open') return { disabled: true, hint: t('pulse', 'Close the quiz first.') }
			if (closedToo && this.paceState === 'closed') return { disabled: true, hint: t('pulse', 'Release or reset first.') }
			return {}
		},
		// State chip of a window (deck header §1.4, room list §1.9) — the
		// deadline counts against the server time. In the list a draft is simply
		// called "Self-paced"; the deck header shows no chip for it.
		paceChip(win) {
			return paceStateChip(win, this.nowSec + this.paceSkew)
		},
		/*
		 * Counts for destructive confirmations — one request, no loop.
		 * Self-paced via /progress (without a version), moderated via the
		 * leaderboard (one row per person). Error -> null: the confirmation
		 * then comes without numbers, but always as "danger".
		 */
		async fetchCounts(code, paced) {
			try {
				if (paced) {
					const { data } = await axios.get(this.api(code, '/progress'), { timeout: MOD_TIMEOUT })
					return progressCounts(data)
				}
				const { data } = await axios.get(this.api(code, '/leaderboard'), { timeout: MOD_TIMEOUT })
				return { joined: Array.isArray(data) ? data.length : 0 }
			} catch (e) {
				return null
			}
		},
		/*
		 * A write call failed: show the server message instead of a generic text
		 * (it says WHY). A 409 means the room is in a different state on the
		 * server than here — reload; when sorting, this also restores the order
		 * that vuedraggable had already changed locally.
		 */
		failWrite(e, fallback) {
			showError(serverMessage(e, fallback))
			if (e?.response?.status === 409) this.refreshRoom(true)
		},
		/*
		 * 409 on a cursor action of the presentation (show, page, reveal,
		 * finish): the room now runs self-paced — moderated rooms never
		 * get the 409 (RoomApiController::paced). Then show the server
		 * message and reload the room instead of staying in the outdated
		 * presentation with a generic text. true = handled.
		 */
		staleLive(e) {
			if (e?.response?.status !== 409 || !this.room) return false
			showError(serverMessage(e, t('pulse', 'Could not switch.')))
			this.stopPolling()
			this.loadRoom(this.room.code)
			return true
		},
		/*
		 * Reload the room WITHOUT a phase change (deck stays deck). If the deck
		 * gets locked while the composer is open, the composer closes — with the
		 * reason as a toast, unless the caller has already shown the server
		 * message (quiet). Errors stay silent: the next action shows them.
		 */
		async refreshRoom(quiet = false) {
			if (!this.room) return
			const code = this.room.code
			try {
				const { data } = await axios.get(this.api(code), { timeout: MOD_TIMEOUT })
				if (!this.room || this.room.code !== code) return
				this.room = data
				this.deck = data.polls || []
				if (this.deckLocked && this.showComposer) {
					this.cancelComposer()
					if (!quiet) showError(t('pulse', 'The questions are locked since the quiz was opened. Reset the room to edit them.'))
				}
			} catch (e) {
				// silent
			}
		},
		// Take over the room JSON from a /pace call — without applyRoom (that
		// stops polling and clears results, neither of which exists here).
		onPaceRoom(data) {
			this.room = data
			this.deck = data.polls || []
			// Whoever opened it themselves knows why the form closes.
			if (this.deckLocked && this.showComposer) this.cancelComposer()
			// A draft belongs in the deck. Otherwise the view stays where it is:
			// "Lock joining" in the deck menu should not jump into the run view.
			if (windowState(data.window) === 'draft') this.phase = 'deck'
		},
		// Fresh window from /progress. If the state changes, reload the room
		// too (join lock, window in the room JSON, deck lock).
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
		// Freshly opened: on into the run view — that is where the link to share is.
		onPaceDone(data) {
			this.paceDialog = null
			this.onPaceRoom(data)
			if (windowState(data.window) !== 'draft') this.enterPace()
		},
		showProgress() {
			this.enterPace()
		},
		// Clicking into the run view: the trigger (dialog, "Show progress",
		// "Back to progress") disappears with the old view, and focus would fall
		// on <body>. PaceRun then puts it on its title.
		enterPace() {
			this.paceFocus = true
			this.phase = 'pace'
		},
		// Run view menu: overview (deck), summary, my rooms.
		onPaceGo(p) {
			if (p === 'deck') this.phase = 'deck'
			else if (p === 'summary') this.openSummary('pace')
			else if (p === 'home') this.goHome()
		},
		// 409 from the dialog: someone else was faster (second tab, add-in).
		onPaceConflict(message) {
			showError(message)
			this.paceDialog = null
			this.refreshRoom(true)
		},
		// "End practice run" in the dialog: first the dialog goes away (no confirmation
		// on top of a form), then reopen without the practice run.
		async endPracticeThenOpen() {
			this.paceDialog = null
			if (await this.togglePractice()) this.paceDialog = 'open'
		},
		/*
		 * Moderated ↔ self-paced (§1.2). Clears the room — but it only asks
		 * when something gets lost in the process: with nobody joined (and in
		 * self-paced mode only in draft) it switches immediately.
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
					const loss = lossText(counts, paced ? this.paceState : 'draft')
					if (!await pulseConfirm({
						title: on ? t('pulse', 'Switch to self-paced?') : t('pulse', 'Switch to moderated?'),
						text: loss ? text + ' ' + loss : text,
						confirmLabel: on ? t('pulse', 'Switch to self-paced') : t('pulse', 'Switch to moderated'),
						cancelLabel: t('pulse', 'Cancel'),
						danger: true,
					})) return
				}
				const { data } = await axios.post(this.api(code, '/pace'), { action: 'set', pace: on ? 'self' : 'live' }, { timeout: MOD_TIMEOUT })
				this.applyRoom(data)
				this.phase = 'deck'
			} catch (e) {
				this.failWrite(e, t('pulse', 'Could not switch the pace.'))
			} finally {
				this.paceBusy = false
			}
		},
		// Lock joining: whoever is already in can still get back in.
		async toggleJoinsLocked() {
			if (!this.room) return
			const on = !this.room.joinsLocked
			try {
				const { data } = await axios.post(this.api(this.room.code, '/pace'), { action: on ? 'lockJoins' : 'unlockJoins' }, { timeout: MOD_TIMEOUT })
				this.onPaceRoom(data)
			} catch (e) {
				this.failWrite(e, t('pulse', 'Could not change joining.'))
			}
		},
		// A player was removed (LivePlayers): show the leaderboard without them
		// right away instead of on the next change.
		onPlayerRemoved() {
			if (this.phase === 'leaderboard') {
				this.reloadLeaderboard()
				return
			}
			this.resVersion = ''
			this.fetchResults()
		},
		// Like openLeaderboard, but without touching the phase or the polling.
		async reloadLeaderboard() {
			if (!this.room) return
			try {
				const { data } = await axios.get(this.api(this.room.code, '/leaderboard'), { timeout: MOD_TIMEOUT })
				if (Array.isArray(data)) this.leaderboard = data
			} catch (e) {
				// stays as it is; the next opening fetches it again
			}
		},

		// ── Presentation view (§9) ──────────────────────────────────────────
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
		// Empty stage: the way back into the deck with the composer open (§9.8).
		addQuestion() {
			this.backToDeck()
			this.openComposer()
		},
		/*
		 * Scale of the canvas preview: the projector page computes in vw/vh and
		 * therefore needs its real kiosk (1280×720) — it is scaled down via
		 * transform, not via the window size. Otherwise the iframe would hold a
		 * different layout than the room, and the preview would be worthless.
		 */
		fitFrame() {
			const box = this.$refs.canvas
			if (!box) return
			const k = Math.min(box.clientWidth / 1280, box.clientHeight / 720)
			if (k > 0.05 && Math.abs(k - this.frameScale) > 0.002) this.frameScale = k
		},
		/*
		 * The box also changes size without a window event: the private
		 * panel goes, fullscreen comes. With fullscreen the new geometry only
		 * exists AFTER the repaint — a fitFrame() in the same tick still
		 * measures the old one. The ResizeObserver measures when the time comes.
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

		// ── Fullscreen ──────────────────────────────────────────────────────
		toggleFullscreen() {
			if (document.fullscreenElement) {
				document.exitFullscreen()
			} else if (this.$el.requestFullscreen) {
				this.$el.requestFullscreen().catch(() => {})
			}
		},
		onFsChange() {
			this.isFullscreen = document.fullscreenElement === this.$el
			// Switching to fullscreen changes the geometry only after the
			// repaint, and without a window event nothing reports it by
			// itself — hence a few re-measurements instead of one.
			this.$nextTick(this.fitFrame)
			for (const ms of [120, 350, 800]) setTimeout(this.fitFrame, ms)
		},

		// ── Room ────────────────────────────────────────────────────────────
		async startRoom(mode) {
			this.busy = true
			try {
				const { data } = await axios.post(roomsApi(), { mode })
				this.room = data
				this.deck = []
				this.currentId = 0
				this.paceDialog = null
				this.paceCounts = null
				this.phase = 'deck'
				this.showComposer = true
				this.draft = emptyDraft()
				window.history.replaceState(null, '', roomPage(data.code))
			} catch (e) {
				// Caps and the rate limit say why (400/429 with a message).
				showError(serverMessage(e, t('pulse', 'The room could not be created.')))
			} finally {
				this.busy = false
			}
		},

		// ── Composer / building the deck ────────────────────────────────────
		openComposer(poll = null) {
			if (poll && poll.id) {
				this.editingId = poll.id
				const d = emptyDraft()
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
				this.draft = emptyDraft()
			}
			this.showComposer = true
			// With seven questions the form would otherwise open below the fold and
			// it would look as if nothing had happened.
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
			// Keep the correct-answer mark in step (choice: radio).
			if (this.draft.correctIndex === i) this.draft.correctIndex = 0
			else if (this.draft.correctIndex > i) this.draft.correctIndex--
			// … and the multiple marks (multi).
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
					// Is the edited question running right now? Reload the results (the votes were cleared).
					if (this.editingId === this.currentId) this.fetchResults()
				} else {
					let { data } = await axios.post(this.api(this.room.code, '/polls'), body)
					data = await this.syncImage(data)
					this.deck.push(data)
				}
				this.showComposer = false
				this.editingId = 0
				this.draft = emptyDraft()
			} catch (e) {
				this.failWrite(e, t('pulse', 'Could not save.'))
			} finally {
				this.busy = false
			}
		},
		async onReorder() {
			// vuedraggable has already re-sorted this.deck -> persist the order.
			try {
				await axios.post(this.api(this.room.code, '/deck/order'), { order: this.deck.map((p) => p.id) })
			} catch (e) {
				this.failWrite(e, t('pulse', 'Could not save the order.'))
			}
		},
		// ↑/↓ sorting (accessible, in addition to dragging): swap the question with its
		// neighbor and persist the new order just like after a drag.
		// Deck row: one visible action ("Show"), the rest hangs off the ⋯ (R5).
		rowMenu(p, i) {
			return [
				{ key: 'edit', label: t('pulse', 'Edit'), icon: 'edit', act: () => this.openComposer(p) },
				{ key: 'up', label: t('pulse', 'Move up'), icon: 'arrow-up', disabled: i === 0, act: () => this.moveQuestion(i, -1) },
				{ key: 'down', label: t('pulse', 'Move down'), icon: 'arrow-down', disabled: i === this.deck.length - 1, act: () => this.moveQuestion(i, 1) },
				{ key: 'del', label: t('pulse', 'Delete question'), icon: 'trash', act: () => this.deleteQuestion(p), danger: true },
			]
		},
		// Room card: the card itself opens the room (R6), the rest lives here.
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
			// Without a mouse the thread would otherwise break: the question now sits one row
			// further on, its menu closed on the click, and the focus would fall back
			// to the top of the page. So move along with it.
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

		// ── Navigation / cursor ─────────────────────────────────────────────
		async setCurrentApi(pollId) {
			await axios.post(this.api(this.room.code, '/current'), { pollId })
			// In a quiz the server restarts the timer and opens the question -> mirror
			// that locally. Any earlier final standings ('ended') are thereby over; the
			// server turns them back into a revealed question (unmarkEnded).
			// A poll does not touch the status when paging (paused stays paused).
			const idx = this.deck.findIndex((p) => p.id === pollId)
			if (this.isQuiz && idx >= 0) this.$set(this.deck[idx], 'status', 'active')
			if (this.isQuiz && pollId) {
				this.deck.forEach((p) => { if (p.id !== pollId && p.status === 'ended') this.$set(p, 'status', 'locked') })
			}
		},
		// Shared cursor change for "Show" and paging.
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
			this.cancelAdvance() // a manual change cancels a scheduled auto-advance
			try {
				await this.selectPoll(pollId)
				this.results = null
				// From the interim leaderboard: the room is now on the question,
				// so the moderator is too (including polling for the timer and auto-advance).
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
		// ── Auto-advance after the timer ends ───────────────────────────────
		// On to the next question; after the last one do exactly what the
		// respective "last question" button does (depends on the mode).
		autoAdvance() {
			if (this.canNext) { this.next(); return }
			// After the last question the same as the primary button: final standings on all
			// screens at once. A practice run has none — with "Reveal at the end" it
			// reveals once; the moderator finishes the run themselves.
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
		// Back to the Pulse overview (start screen with "My rooms").
		openBeamer() {
			if (!this.room) return
			openProjector(this.room.code)
		},
		/*
		 * Fetch the Office manifest of this instance. The server fills in the
		 * addresses (AddinController) — nothing has to be changed by hand
		 * any more, and that was exactly the source of errors before.
		 */
		downloadAddin() {
			window.location.href = addinManifest()
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
			window.history.replaceState(null, '', home())
			this.fetchMyRooms()
		},
		// Load final standings/summary: stop polling, fetch the data, set the phase.
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
		// Back to where the summary came from. If the room has meanwhile become a
		// draft again (reset in a second tab), it belongs in the deck.
		summaryBack() {
			if (this.summaryToPace && this.paceState !== 'draft') this.enterPace()
			else this.phase = 'deck'
		},
		openLeaderboard() {
			return this.loadPhase('/leaderboard', 'leaderboard', t('pulse', 'Could not load the leaderboard.'))
		},
		async finish() {
			// Finishing the quiz means: set the running question to 'ended' and
			// leave the cursor where it is. Only then does the projector show the final standings —
			// with a cleared cursor (setCurrent 0) it would fall back into the lobby. This applies
			// to both reveal modes; "Reveal at the end" needs it anyway so that
			// the participants now see the reveal + final standings.
			if (this.isQuiz && !this.isPractice) {
				// If /end fails, the room stays on the last question — then don't
				// pretend the final standings are up everywhere, stay here instead.
				if (!await this.postEnd()) return
				this.stopPolling()
				this.openLeaderboard()
				return
			}
			// Poll and practice run: no final standings -> room back to the lobby,
			// moderator back to the deck.
			try {
				await this.setCurrentApi(0)
			} catch (e) {
				// carry on even if setting it fails
			}
			this.stopPolling()
			this.currentId = 0
			this.phase = 'deck'
		},

		// Practice run with "Reveal at the end", last question: reveal once so that
		// everyone can check their answers (without a leaderboard — the server hides
		// it in a practice run). The moderator stays on the question; "Finish"
		// then sends the room to the lobby. Only the primary button and auto-advance
		// call this — "Finish quiz" in the menu finishes immediately, even mid-deck.
		async revealLast() {
			this.cancelAdvance()
			if (!await this.postEnd()) return
			this.resVersion = ''
			this.fetchResults()
		},

		// POST /end and mirror the state locally: the running question is
		// now 'ended' (quizOver/isRevealed depend on it).
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

		// ── Controlling the current question ────────────────────────────────
		async setLock(lock) {
			const verb = lock ? 'lock' : 'unlock'
			try {
				await axios.post(this.api(this.room.code, '/polls/' + this.currentId + '/' + verb))
				if (this.currentIndex >= 0) {
					this.$set(this.deck[this.currentIndex], 'status', lock ? 'locked' : 'active')
				}
				// Make reveal/pause visible immediately (don't wait for the next tick).
				this.resVersion = ''
				this.fetchResults()
			} catch (e) {
				if (!this.staleLive(e)) showError(lock ? t('pulse', 'Could not reveal.') : t('pulse', 'Could not resume.'))
			}
		},
		// Quiz: reopening a revealed question = restarting the timer (setCurrent).
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
		// ── Demo/test mode: fill the active question with synthetic votes ──
		async seedDemo() {
			if (this.demoBusy || !this.room || !this.currentId) return
			this.demoBusy = true
			try {
				const { data } = await axios.post(this.api(this.room.code, '/demo'), { count: this.demoCount })
				this.resVersion = ''
				this.fetchResults()
				showSuccess(t('pulse', '{seeded} demo votes created — {total} in total now.', { seeded: data.seeded, total: data.total }))
			} catch (e) {
				showError(serverMessage(e, t('pulse', 'Could not create demo votes.')))
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
		// Grade free text (presentation reveal): answer ✓/✗ -> the server re-grades
		// all identical spellings and returns fresh results + leaderboard.
		async gradeAnswer(answer, correct) {
			try {
				const { data } = await axios.post(this.api(this.room.code, '/polls/' + this.currentId + '/grade'), { answer, correct })
				this.results = data
				if (data.leaderboard) this.leaderboard = data.leaderboard
				this.resVersion = ''
			} catch (e) {
				showError(serverMessage(e, t('pulse', 'Could not grade.')))
			}
		},
		// Grade free text from the summary (updates only this question).
		async gradeSummaryAnswer(item, answer, correct) {
			try {
				const { data } = await axios.post(this.api(this.room.code, '/polls/' + item.poll.id + '/grade'), { answer, correct })
				this.$set(item, 'results', data)
			} catch (e) {
				showError(serverMessage(e, t('pulse', 'Could not grade.')))
			}
		},

		// ── Results polling (adaptive + change version) ─────────────────────
		async fetchResults() {
			if (!this.currentId) return
			try {
				const params = this.resVersion ? { v: this.resVersion } : {}
				const res = await axios.get(this.api(this.room.code, '/polls/' + this.currentId + '/results'), { params })
				this.online = true
				if (res.status === 204) { // unchanged
					this.idleStreak++
					return
				}
				const data = res.data
				// The room now runs self-paced (another tab switched it
				// over): this presentation is outdated. Take over nothing —
				// self-paced results hide nothing with "Reveal at the end" —,
				// reload the room instead: loadRoom leads into the deck or the
				// run view.
				if (data.pace === 'self' && !this.isPaced) {
					this.stopPolling()
					this.loadRoom(this.room.code)
					return
				}
				this.results = data
				this.presentCount = data.present || 0
				/*
				 * The join panel does NOT open by itself. It used to pop open here
				 * as long as presentCount was 0 — and because that was re-decided on every
				 * results tick (every 1.2 s in a quiz), it sprang open again right after
				 * every close via ×, a click outside or Escape. In a room that was still
				 * empty it could not be closed at all.
				 * The code is shown permanently in the header anyway, and large on the
				 * canvas; whoever needs the QR code gets it via "Join".
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
			if (document.hidden) return null // pause -> onPollVisibility wakes it up
			// Quiz with a running timer or fresh votes -> fast; otherwise back off.
			if (this.isQuiz && !this.isRevealed) return 1200
			return this.idleStreak >= 3 ? 4000 : 1800
		},
		// The moderator polls only in certain phases (question presented) -> gate.
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
	/* NC's #content is a row flex container -> without flex/width this root
	   div would stay content-wide and stick to the left (margin:auto would have no room). */
	flex: 1 1 auto;
	width: 100%;
	min-width: 0;
	min-height: 100%;
	/* NC's #content has a fixed height and clips overflow -> this root div
	   has to scroll itself, otherwise e.g. a long room list below the fold
	   is unreachable. max-height binds the height to the flex parent. */
	max-height: 100%;
	overflow-y: auto;
	background: var(--pulse-bg);
	color: var(--pulse-text);
	padding: 40px min(5vw, 64px);
	box-sizing: border-box;
	font-family: var(--font-face, system-ui, -apple-system, sans-serif);
}
.pulse-mod.is-fs { overflow: auto; }
/* Presentation and final standings fill the area themselves (own header/footer) —
   the page margin of .pulse-mod would frame the frame twice. */
.pulse-mod.is-live { padding: 0; overflow: hidden; }
/* §9.5: the header block starts 64 px from the window edge (NC header 50 px + 14),
   not at around 150 — the empty space cost the first room card. */
.pulse-mod.is-start { padding-block-start: 14px; }

/* Start */
.start { max-width: 1180px; margin: 0 auto; display: grid; gap: 40px; align-items: start; }
/* From 1200 px two columns: header block on the left, rooms on the right. In one
   column the header block stays under 300 px so that the first room card sits above
   the fold (§9.5). */
@media (min-width: 1200px) { .start { grid-template-columns: minmax(0, 440px) minmax(0, 1fr); gap: 56px; } }
.start-intro { max-width: 620px; }
.eyebrow { color: var(--pulse-primary); letter-spacing: 0.18em; text-transform: uppercase; font-weight: 700; font-size: 13px; margin: 0 0 12px; }
.start h1 { font-size: clamp(30px, 4.5vw, 48px); margin: 0 0 16px; }
.lede { color: var(--pulse-text-2); font-size: 18px; margin: 0 0 32px; }
.mode-note { color: var(--pulse-text-2); font-size: 13px; margin: 18px 0 0; }
.mode-note b { color: var(--pulse-text); }
/* Text button, not a second outlined button: joining is the rarer action
   and should not compete with Start (§9.5). */
.start-join { display: inline-block; margin-top: 18px; font-size: 15px; font-weight: 600; color: var(--pulse-primary); text-decoration: underline; text-underline-offset: 3px; }
.start-join:hover { color: var(--pulse-primary-hover); }

/* My rooms */
.myrooms { text-align: left; }
@media (max-width: 1199px) { .myrooms { margin-top: 8px; } }
.myrooms-head { font-size: 15px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--pulse-text-2); margin: 0 0 12px; }
.myrooms-list { list-style: none; margin: 0; padding: 0; }
.myroom {
	display: flex; align-items: center;
	border: 2px solid var(--pulse-border);
	margin-bottom: 10px;
}
.myroom-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.myroom-title { font-size: 19px; font-weight: 700; line-height: 1.25; overflow-wrap: anywhere; }
.myroom-code { font-size: 22px; font-weight: 800; letter-spacing: 0.06em; font-variant-numeric: tabular-nums; }
/* With a title, the name leads — the code moves to the secondary line. */
.myroom-code.is-sub { font-size: 15px; font-weight: 700; color: var(--pulse-text-2); }
.myroom-meta { font-size: 13px; color: var(--pulse-text-2); }
/* Type clearly visible: colored icon + text chip (quiz = quiz purple, poll = blue). */
.myroom-type { font-size: 12px; }

/* Centered two-column layout */
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
/* Short address + copy as one calm line (§5b). */
.join-link { display: flex; align-items: center; gap: 8px; }
.join-url { flex: 1; min-width: 0; font-size: clamp(14px, 1.4vw, 18px); font-weight: 600; color: var(--pulse-primary); word-break: break-all; text-decoration: none; }
.join-url:hover { text-decoration: underline; }
.join-copy { flex: 0 0 auto; }
.conn { font-size: 12px; margin-top: 10px; color: var(--pulse-success); }
.conn.is-off { color: var(--pulse-error); }

/* Deck editor */
.deck-head { display: flex; align-items: center; margin-bottom: 20px; gap: 10px; flex-wrap: wrap; }
.deck-head h2 { margin: 0; font-size: 24px; }
.deck-title { display: inline-flex; align-items: center; gap: 8px; min-width: 0; }
.deck-title-txt { overflow-wrap: anywhere; }
.deck-title-txt.is-untitled { color: var(--pulse-text-2); }
.deck-rename { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
/* Inherits border/focus from .pinput; only the title look and width here. */
.deck-rename-input { font-size: 20px; font-weight: 700; width: min(380px, 60vw); }
.deck-list { list-style: none; margin: 0 0 16px; padding: 0; }
.deck-item {
	display: flex; align-items: center; gap: 14px;
	padding: 12px 16px; margin-bottom: 10px;
	border: 2px solid var(--pulse-border);
}
.drag-handle { cursor: grab; font-size: 18px; line-height: 1; user-select: none; }
.drag-handle:active { cursor: grabbing; }
.deck-item.sortable-chosen { opacity: 0.9; }
.deck-item.sortable-ghost { opacity: 0.4; border-color: var(--pulse-primary); }
.deck-num { font-weight: 800; color: var(--pulse-text-2); font-variant-numeric: tabular-nums; min-width: 20px; }
.edit-note { color: var(--pulse-warning); font-size: 13px; margin: 0 0 14px; }
.deck-body { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.deck-type { font-size: 12px; color: var(--pulse-text-2); display: flex; align-items: center; gap: 0.5em; flex-wrap: wrap; }
/* Type tag in the deck (MC/WC/SC/…): SC carries the "you" magenta of the scale family. */
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

/* Quiz composer */
.quiz-hint { font-size: 13px; color: var(--pulse-text-2); margin: 4px 0 8px; }
.quiz-time { margin-top: 6px; }
/* .opt-correct is redefined in the second style block (shape+color+text, §4.6). */
.tf-pick { display: flex; gap: 12px; margin: 8px 0; }
.tf-pick .pulse-btn { min-width: 96px; }
.num-fields { display: flex; gap: 20px; flex-wrap: wrap; margin: 6px 0; }
.num-fields .words-hint { flex-direction: column; align-items: flex-start; gap: 4px; }
.num-fields input { width: 140px; }

/* Summary */
.summary-view { max-width: 760px; margin: 0 auto; }
.summary-warn { margin: 0 0 24px; }
.summary-warn-head { display: block; }
.summary-item { margin-bottom: 36px; padding-bottom: 28px; border-bottom: 1px solid var(--pulse-border); }
.summary-item:last-child { border-bottom: none; }
.summary-q { font-size: 20px; margin: 0 0 20px; display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
.summary-num { font-weight: 800; color: var(--pulse-text-2); font-variant-numeric: tabular-nums; }
.summary-qtext { flex: 1; min-width: 0; }
.summary-type { font-size: 12px; font-weight: 400; color: var(--pulse-text-2); }

/* Presentation — status/chips/notice now come from the DS (2nd block).
   Only the layout leftovers remain here. */
.practice-banner { margin: 4px 0 20px; }
/* Demo/test bar (§4): on --pulse-*, warning chip + dashed warning-tinted
   frame = "does not count", actions clearly separated (ghost vs. danger outline). */
.demo-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 16px; padding: 10px 14px; border: 1px dashed var(--pulse-warning); border-radius: var(--pulse-r-el); background: var(--pulse-warning-soft); }
.demo-chip { flex: 0 0 auto; }
.demo-lbl { display: inline-flex; align-items: center; gap: 7px; font-size: var(--t-sm); font-weight: 700; color: var(--pulse-warning); }
.demo-count { width: 5em; }
.demo-spacer { flex: 1 1 auto; }
.demo-hint { font-size: var(--t-sm); font-weight: 600; color: var(--pulse-warning); }
/* ── Presentation view (§9) ───────────────────────────────────────────────
   Three rows: header (64), body (rest), control bar (96). The body is the
   only row allowed to shrink — minmax(0,1fr), otherwise the
   content pushes the bar out of view. */
.mod-live { position: relative; height: 100%; display: grid; grid-template-rows: auto minmax(0, 1fr) auto; background: var(--pulse-bg); }
.mod-top { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; min-height: 64px; padding: 8px 20px; border-block-end: 1px solid var(--pulse-border); }
.mod-pos { font-size: 16px; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
.mod-cd { font-size: 20px; }
.mod-here { font-variant-numeric: tabular-nums; }
.mod-spacer { flex: 1 1 auto; }
.mod-code { font-size: 18px; font-weight: 800; letter-spacing: 0.08em; font-variant-numeric: tabular-nums; }
/* Full row in the wrapping header — no grid cell of its own. */
.mod-offline { flex: 1 0 100%; margin: 0; }

/* Body: preview + private panel */
.mod-body { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 20px; min-height: 0; padding: 16px 20px; }
/* Below 1000 px stacked — the control bar stays at the bottom (§9.8). */
@media (max-width: 999px) { .mod-body { grid-template-columns: minmax(0, 1fr); grid-template-rows: auto minmax(0, 1fr); overflow-y: auto; } }
.mod-canvas { position: relative; min-width: 0; min-height: 0; display: grid; place-items: center; }
.mod-canvas-in { position: relative; overflow: hidden; border: 1px solid var(--pulse-border-strong); border-radius: var(--pulse-r-card); background: var(--pulse-screen-bg); }
/* The kiosk computes in vw/vh: the iframe MUST be 1280×720 and is scaled down via
   transform. A smaller window would give a different layout than
   in the room — and with it a preview that proves nothing. */
.mod-frame { width: 1280px; height: 720px; border: 0; transform-origin: top left; display: block; }
.mod-private { min-width: 0; display: flex; flex-direction: column; gap: 8px; overflow-y: auto; padding: 14px; border: 1px solid var(--pulse-border-strong); border-radius: var(--pulse-r-card); background: var(--pulse-surface, var(--pulse-hover)); font-size: 14px; }
.mod-private-head { display: flex; align-items: center; gap: 8px; margin: 0; font-size: 15px; font-weight: 800; }
.mod-private-sub { margin: 0 0 4px; font-size: 12px; color: var(--pulse-text-2); }
.mod-private-body { min-width: 0; }
.mod-private-empty { color: var(--pulse-text-2); }
.mod-private-lb { margin-top: 8px; }
.mod-standings { min-width: 0; overflow-y: auto; grid-column: 1 / -1; font-size: clamp(16px, 1.6vw, 21px); }
.mod-standings-head { display: flex; align-items: center; gap: 8px; margin: 0 0 12px; font-size: 24px; }
.mod-standings-players { max-width: 480px; margin-block-start: 20px; }

/* Control bar: one primary action, paging on the left, the intake on the right */
.mod-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; min-height: 96px; padding: 12px 20px; border-block-start: 1px solid var(--pulse-border); }
.mod-primary { min-width: 220px; }
/* The same building block as on the projector, just small — number, reference, dot grid. */
.mod-intake { font-size: 11px; }
.mod-intake :deep(.intake-num) { font-size: 1.9em; }

/* Joining: a state instead of a column (§9.4) */
.mod-joinwrap { position: absolute; inset: 0; z-index: 40; display: flex; justify-content: flex-end; background: rgba(0, 0, 0, 0.22); }
/* Column, not flow: hint, code and link are <span>s from the old
   QR column — side by side they ran into each other. */
.mod-joinpanel { width: min(420px, 92vw); padding: 20px; overflow-y: auto; display: flex; flex-direction: column; align-items: stretch; gap: 4px; background: var(--pulse-bg); border-inline-start: 1px solid var(--pulse-border-strong); box-shadow: var(--pulse-shadow-pop); }
.mod-joinpanel .join-code { font-size: 40px; }
.mod-joinpanel .join-hint { line-height: 1.5; }
.mod-join-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.mod-join-head h2 { margin: 0; font-size: 20px; }
.mod-join-qr { margin-bottom: 12px; }

/* Fullscreen = the projected mode: only the canvas. The control bar comes back
   on hover so you can advance without leaving
   fullscreen — no answers are shown there. */
.pulse-mod.is-fs .mod-live { grid-template-rows: minmax(0, 1fr); }
.pulse-mod.is-fs .mod-top, .pulse-mod.is-fs .mod-offline { display: none; }
/* Without the private panel, its 360 px column would otherwise remain — the
   preview would be narrower than the screen in fullscreen. */
.pulse-mod.is-fs .mod-body { padding: 0; grid-template-columns: minmax(0, 1fr); }
.pulse-mod.is-fs .mod-canvas-in { border: 0; border-radius: 0; }
.pulse-mod.is-fs .mod-bar { position: absolute; inset-inline: 0; inset-block-end: 0; background: var(--pulse-bg); transform: translateY(100%); transition: transform 0.15s ease; }
.pulse-mod.is-fs .mod-bar:hover, .pulse-mod.is-fs .mod-bar:focus-within { transform: none; }

/* Quiz presentation: countdown + leaderboard */
/* Transition = tick interval (1 s), so the bar glides continuously instead of
   jumping every second (0.5 s glided only halfway and then stood still). */

/* Composer */
.opt-row { display: flex; gap: 8px; margin-bottom: 10px; align-items: center; }
.words-hint { display: inline-flex; align-items: center; gap: 8px; }
.words-hint--col { flex-direction: column; align-items: flex-start; gap: 4px; }
/* Image row in the composer + image in the presentation */
.img-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin: 12px 0 4px; }
.img-preview { position: relative; line-height: 0; }
.img-preview img { max-height: 96px; max-width: 200px; border-radius: var(--pulse-r-el); border: 1px solid var(--pulse-border); }
.img-drop { position: absolute; top: -8px; inset-inline-end: -8px; }
.img-pick { cursor: pointer; }
.img-input { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
.img-hint { font-size: 12px; color: var(--pulse-text-2); }
.composer-actions { display: flex; gap: 10px; margin-top: 20px; }
.composer { padding: 20px; border: 1px solid var(--pulse-border); }

/* Type ▸ mode (§4): the scale mode is a sub-choice of "Scale" — indented,
   with a connector and smaller chip switches (not a second segmented control). */
.submode { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; margin: 2px 0 18px; margin-left: 8px; padding-left: 16px; border-left: 2px solid var(--pulse-border-strong); }
.submode-arrow { color: var(--pulse-text-2); font-weight: 800; }
.submode-key { font-size: var(--t-cap); font-weight: 700; color: var(--pulse-text-2); text-transform: uppercase; letter-spacing: 0.06em; }
.submode-seg { margin: 0; }
.submode-seg :deep(.pseg) { padding: 3px; }
.submode-seg :deep(.pseg-item) { font-size: var(--t-cap); padding: 5px 10px; }

/* Framed section groups (§4): strong header on a fill + framed content. */
.sgroup { border: 1px solid var(--pulse-border-strong); border-radius: var(--pulse-r-el); background: var(--pulse-bg); overflow: hidden; margin-bottom: 12px; }
.sgroup-head { margin: 0; padding: 10px 14px; font-size: var(--t-sm); font-weight: 800; background: var(--pulse-fill); color: var(--pulse-text); border-bottom: 1px solid var(--pulse-border); }
.sgroup-opt { font-weight: 600; color: var(--pulse-text-2); }
.sgroup-in { padding: 14px; display: flex; flex-direction: column; gap: 12px; }
/* Axes sit borderless inside the group's frame (the group frames, not each axis). */
.sgroup-in .axis-block { border: 0; padding: 0; margin: 0; }
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.corner-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.corner-cell { display: flex; flex-direction: column; gap: 4px; }
.corner-lbl { font-size: var(--t-cap); font-weight: 700; color: var(--pulse-text-2); }

/* Inputs */
.pinput {
	padding: 11px 14px;
	font-size: 16px;
	width: 100%;
	box-sizing: border-box;
}
/* Uniform, visible focus ring on all input fields (§8). */
.pinput:focus-visible { outline: 2px solid var(--pulse-primary); outline-offset: 1px; border-color: var(--pulse-primary); }
.pinput--q { font-size: 20px; margin-bottom: 18px; }
/* NC gives <select> a fixed height globally -> with our padding the line gets
   cut off at the bottom. Lift the fixed height so the box grows with its content. */
.pinput--sel { width: auto; height: auto !important; min-height: 40px; padding: 8px 12px !important; line-height: normal; }
.pinput--num { width: 5.5em; height: auto !important; min-height: 40px; padding: 8px 12px !important; line-height: normal; }
/* Spectrum composer: aspect row (number · name + pole pair · remove) */
.aspect-row { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 10px; }
.aspect-idx { flex: 0 0 auto; width: 24px; min-height: 40px; display: grid; place-items: center; font-weight: 800; color: var(--pulse-text-2); font-variant-numeric: tabular-nums; }
.aspect-fields { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
.aspect-poles { display: flex; gap: 6px; }
.aspect-poles .pinput--pole { width: 100%; font-size: var(--t-sm); }
.axis-block { border: 1px solid var(--pulse-border); border-radius: var(--pulse-r-el); padding: 10px 12px; margin-bottom: 10px; display: flex; flex-direction: column; gap: 6px; }

.ic-flip { transform: scaleX(-1); }

</style>

<!-- Additions for steps 4/6/7/8/10/11 — DS-like building blocks + token alignment.
     Second scoped block: new classes + overrides in source order after the
     old rules (same specificity -> the later one wins). -->
<style scoped>
/* Start: one CTA + mode segmented control (§4.1) */
.start-cta { display: flex; flex-direction: column; align-items: flex-start; gap: 16px; }
.start-cta .is-lg { min-width: 240px; }

/* My rooms: icon badge + text status chip */
.myroom { border-radius: var(--pulse-r-card); border-color: var(--pulse-border); background: var(--pulse-hover); }
.myroom-meta { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.myroom-chip { font-size: 12px; }

/* Button groups: only the summary still has more than one action. */
.deck-head-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-inline-start: auto; }
.btn-group { display: inline-flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.btn-group + .btn-group { margin-left: 6px; padding-left: 12px; border-left: 1px solid var(--pulse-border); }
.export-btn { text-decoration: none; }

/* Deck editor */
.deck-item { border-radius: var(--pulse-r-card); border-color: var(--pulse-border); background: var(--pulse-hover); }
.deck-item.is-current { border-color: var(--pulse-primary); }
.drag-handle { color: var(--pulse-text-2); display: inline-flex; }
.deck-current-chip { flex: 0 0 auto; font-size: 12px; }

/* ── Overviews tidied up (overview design notes, not in the public repository) ───
   Deck header: one row, three controls. Before, nine buttons in three
   groups, wrapping at every width (B1). */
.deck-head-spacer { flex: 1 1 auto; }
.deck-back { flex: 0 0 auto; }
.deck-mode { flex: 0 0 auto; font-size: 12px; }

/* The question IS the edit button (R5) — so the pencil per row goes
   away. Nextcloud styles native <button> globally via element selectors
   and so beats every class: without !important, border, padding and
   min-height would come from there. */
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

/* Room card: the card itself opens the room (R6). A filled "Open" per
   card would have meant seven primary actions for seven rooms (B4). */
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

/* The explanatory text is for the first visit (R7). On narrow screens it was just as
   present on the tenth visit and pushed the first room card to the bottom edge (B5). */
@media (max-width: 1199px) {
	.start.has-rooms .lede,
	.start.has-rooms .mode-note { display: none; }
	.start.has-rooms h1 { font-size: 30px; margin-block-end: 12px; }
}

/* Empty state (§4.11): branded + one CTA */
.empty-state { text-align: center; padding: 40px 16px; display: flex; flex-direction: column; align-items: center; gap: 10px; }
.empty-ico { color: var(--pulse-primary); opacity: 0.85; }
.empty-title { font-size: 20px; font-weight: 800; margin: 4px 0 0; }
.empty-sub { color: var(--pulse-text-2); margin: 0 0 8px; max-width: 40ch; }

/* Composer */
.composer { border-radius: var(--pulse-r-card); border-color: var(--pulse-border); background: var(--pulse-hover); }
.composer-seg { margin: 4px 0 18px; }
/* Correct marker (§4.6): shape (box) + color (green) + text ("correct") */
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

/* Inputs on Pulse tokens */
.pinput { background: var(--pulse-bg) !important; color: var(--pulse-text); border: 2px solid var(--pulse-border-strong); border-radius: var(--pulse-r-el); }
.pinput:focus { border-color: var(--pulse-primary); }
.pinput::placeholder { color: var(--pulse-text-2); }

/* Auto-advance chip in the control bar */
.auto-next { font-variant-numeric: tabular-nums; }

/* ── Self-paced quiz: deck (stage 4.2) ──────────────────────────────────── */
.deck-state { flex: 0 0 auto; font-size: 12px; font-variant-numeric: tabular-nums; }
/* Header in self-paced mode: title and actions on top, the status chips (mode,
   "Open until …") in their own row below. With a long title and a deadline
   chip, "Show progress" would otherwise break alone into the second row. The
   ::after is the line break; the DOM order stays. */
.deck-head.is-paced { row-gap: 4px; }
.deck-head.is-paced::after { content: ''; order: 1; flex-basis: 100%; height: 0; }
.deck-head.is-paced .deck-mode,
.deck-head.is-paced .deck-state { order: 2; }
.deck-lock-note { margin: 4px 0 20px; }
/* Locked question: stays fully readable — NC dims every disabled
   <button> to half opacity, which would look like it was deleted. */
.deck-q:disabled { opacity: 1 !important; cursor: default; }
.deck-q:disabled:hover { text-decoration: none; }
.myroom-pace { font-variant-numeric: tabular-nums; }

</style>
