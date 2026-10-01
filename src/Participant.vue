<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pulse-part">
		<div class="sheet">
			<!-- Join: enter the 6-character code (§4.6 — its own step, unchanged here) -->
			<!-- Overall summary: all questions with the result + your own answer.
			     Sits deliberately BEFORE the live branches and lies on top of them; as soon as the
			     moderator opens another question, it closes by itself. -->
			<div v-if="review" class="review">
				<div class="review-head">
					<h1 class="review-h">{{ isPaced && reviewState !== 'released' ? t('pulse', 'Your answers') : t('pulse', 'All results') }}</h1>
					<button class="pulse-btn is-secondary is-sm" @click="closeReview">{{ t('pulse', 'Back') }}</button>
				</div>
				<p v-if="review.title" class="review-title">{{ review.title }}</p>
				<p v-if="review.practice" class="pulse-chip is-warning practice-chip"><PulseIcon name="warning" size="1em" /> {{ t('pulse', 'Practice run · does not count') }}</p>
				<p v-if="myRank" class="myrank review-myrank">{{ t('pulse', 'Place') }} <b>{{ myRank.rank }}</b> · {{ n('pulse', '%n point', '%n points', myRank.score) }}</p>
				<!-- Self-paced before the release: no place, but your own points (§2.8). -->
				<p v-else-if="isPaced && review.myScore != null" class="myrank review-myrank">{{ n('pulse', '%n point', '%n points', review.myScore) }}</p>
				<ol class="review-list">
					<li v-for="(it, i) in review.items" :key="it.poll.id" class="review-item">
						<h2 class="review-q"><span class="review-num">{{ i + 1 }}</span><span class="review-q-text">{{ it.poll.question }}</span></h2>
						<p v-if="it.mine" class="review-mine" :class="{ 'is-ok': it.mine.correct === true, 'is-no': it.mine.correct === false }">
							<span class="review-mine-lbl">{{ t('pulse', 'Your answer:') }}</span> <b>{{ myAnswerText(it) }}</b>
							<span v-if="it.mine.correct === true" class="pulse-chip is-success review-chip"><PulseIcon name="check" size="1em" /> +{{ it.mine.points }}</span>
							<span v-else-if="it.mine.correct === false" class="pulse-chip is-error review-chip"><PulseIcon name="close" size="1em" /> {{ t('pulse', 'missed') }}</span>
							<!-- Self-paced: no verdict yet (free text still open, or "At the end"). -->
							<span v-else-if="it.mine.verdict === 'pending'" class="pulse-chip is-warning review-chip"><PulseIcon name="hourglass" size="1em" /> {{ t('pulse', 'Being checked') }}</span>
							<span v-else-if="it.mine.verdict === 'saved'" class="pulse-chip is-neutral review-chip"><PulseIcon name="check" size="1em" /> {{ t('pulse', 'Answer saved') }}</span>
						</p>
						<p v-else class="muted review-mine is-none">{{ t('pulse', 'Not answered') }}</p>
						<ResultsView v-if="it.revealed && it.results" :results="it.results" :correct-id="it.poll.correctOption || ''" :correct-ids="(it.poll.answerKey && it.poll.answerKey.correct) || []" :answer-key="it.poll.answerKey || null" />
						<p v-else class="muted review-hidden">{{ t('pulse', 'Not revealed yet.') }}</p>
					</li>
				</ol>
				<Leaderboard v-if="review.leaderboard && review.leaderboard.length" :rows="review.leaderboard" :limit="10" :me="review.leaderboardMe || null" />
				<button class="pulse-btn is-secondary review-back" @click="closeReview">{{ t('pulse', 'Back') }}</button>
			</div>

			<div v-else-if="!code" class="center entry">
				<p class="eyebrow-p">Pulse</p>
				<h1>{{ t('pulse', 'Enter code') }}</h1>
				<div class="digits" role="group" :aria-label="t('pulse', 'Room code, 6 characters')">
					<input v-for="i in 6" :key="i" ref="digits"
						class="digit" type="text" maxlength="1"
						autocapitalize="characters" autocomplete="off" spellcheck="false"
						:value="digits[i - 1]" :aria-label="t('pulse', 'Character {number} of 6', { number: i })"
						@input="onDigit(i - 1, $event)"
						@keydown.delete="onDelete(i - 1, $event)"
						@paste="onPaste">
				</div>
				<p v-if="entryError" class="entry-error">{{ entryError }}</p>
				<button class="submit" :disabled="!entryValid || checking" @click="go">
					{{ checking ? t('pulse', 'Checking …') : t('pulse', 'Join') }}
				</button>
			</div>

			<!-- Unknown room (§4.11) — no dead end: a way back to the code entry -->
			<div v-else-if="!roomExists" class="center empty-part">
				<p class="eyebrow-p">Pulse</p>
				<h1>{{ t('pulse', 'This room does not exist.') }}</h1>
				<p class="muted">{{ t('pulse', 'That code is not right — type a new one.') }}</p>
				<a class="pulse-btn is-primary empty-cta" :href="joinPagePath">{{ t('pulse', 'Enter a new code') }}</a>
			</div>

			<!-- Quiz: choose a name (§4.6). In self-paced mode only while joining is still
			     possible (draft/open) — with a notice after being removed or after a new
			     round, and the deadline, so that latecomers see how much time is left (§2.3). -->
			<div v-else-if="isQuiz && !nickname && !paceNoJoin" class="center nick">
				<p class="code-badge">{{ spacedCode }}</p>
				<p v-if="roomTitle" class="room-title">{{ roomTitle }}</p>
				<p v-if="isPaced && paceNotice" class="pulse-notice is-info pace-notice" role="status">
					<PulseIcon :name="paceNotice === 'removed' ? 'warning' : 'reset'" size="1.1em" class="pulse-notice-ico" />
					<span>{{ paceNotice === 'removed' ? t('pulse', 'You were removed from this quiz.') : t('pulse', 'A new round has started — choose your name.') }}</span>
				</p>
				<PulseIcon name="quiz" size="2.4em" class="nick-ico" />
				<h1>{{ t('pulse', 'What is your name?') }}</h1>
				<p class="muted nick-hint">{{ t('pulse', 'Your name appears on the leaderboard.') }}</p>
				<p v-if="isPaced && paceUntil" class="pace-when">
					<PulseIcon name="calendar" size="1.1em" />
					<span>{{ t('pulse', 'Open until {time}', { time: paceUntil }) }}</span>
					<span v-if="closesInMin" class="pulse-chip is-warning pace-soon">{{ n('pulse', 'Closes in %n min', 'Closes in %n min', closesInMin) }}</span>
				</p>
				<label class="nick-field">
					<input v-model="nickInput" class="nick-input" type="text" maxlength="24"
						autocomplete="off" :placeholder="t('pulse', 'Your name')" @keyup.enter="join">
					<span class="nick-count">{{ nickInput.length }} / 24</span>
				</label>
				<p v-if="nickError" class="entry-error">{{ nickError }}</p>
				<button class="submit" :disabled="joining || !nickInput.trim()" @click="join">
					{{ joining ? '…' : (isPaced ? t('pulse', 'Next') : t('pulse', 'Let’s go')) }}
				</button>
				<p v-if="isPaced && paceState === 'open'" class="muted pace-device">{{ t('pulse', 'Started on another device? Ask your host — you would start again from question 1.') }}</p>
			</div>

			<!-- ══════════ SELF-PACED: CARDS WITHOUT A QUESTION (§2.2) ══════════ -->
			<!-- util/pace.js (paceCard) decides which card. Never when moderated. The
			     key rebuilds every card: otherwise e.g. "See your answers" would take over
			     the element and focus of the "Next" that was just tapped. -->
			<div v-else-if="paceCard" :key="'pace-' + paceCard" class="center pace-card" :data-card="paceCard">
				<p v-if="practice" class="pace-practice"><span class="pulse-chip is-warning practice-chip"><PulseIcon name="warning" size="1em" /> {{ t('pulse', 'Practice run · does not count') }}</span></p>

				<!-- Without a name and closed: joining is no longer possible. -->
				<template v-if="paceCard === 'shut'">
					<p class="code-badge">{{ spacedCode }}</p>
					<p v-if="roomTitle" class="room-title">{{ roomTitle }}</p>
					<PulseIcon name="lock" size="2.4em" class="state-ico" />
					<h1 class="pace-h" tabindex="-1">{{ t('pulse', 'The quiz is closed.') }}</h1>
				</template>

				<!-- With a name, no question yet: wait (draft), start, continue. -->
				<template v-else-if="paceCard === 'wait' || paceCard === 'start' || paceCard === 'continue'">
					<p class="code-badge">{{ spacedCode }}</p>
					<p v-if="roomTitle" class="room-title">{{ roomTitle }}</p>
					<h1 class="you pace-you" tabindex="-1">{{ t('pulse', 'Ready, {name}', { name: nickname }) }}</h1>
					<template v-if="paceCard === 'wait'">
						<p class="wait-dot" />
						<p class="muted pace-line">{{ t('pulse', 'The quiz has not started yet.') }}</p>
						<p v-if="present > 0" class="muted pace-momentum"><b>{{ present }}</b> {{ n('pulse', 'person is already here', 'people are already here', present) }}</p>
					</template>
					<!-- Start (§2.4): only this tap starts the personal clock. -->
					<template v-else-if="paceCard === 'start'">
						<p class="pace-facts">{{ n('pulse', '%n question', '%n questions', paceWindow.total) }} · {{ paceWindow.timed ? t('pulse', 'with time limit') : t('pulse', 'no time limit') }} · {{ paceWindow.feedback === 'end' ? t('pulse', 'results at the end') : t('pulse', 'feedback after each question') }}</p>
						<p v-if="paceUntil" class="pace-when">
							<PulseIcon name="calendar" size="1.1em" />
							<span>{{ t('pulse', 'Open until {time}', { time: paceUntil }) }}</span>
							<span v-if="closesInMin" class="pulse-chip is-warning pace-soon">{{ n('pulse', 'Closes in %n min', 'Closes in %n min', closesInMin) }}</span>
						</p>
						<ul class="muted pace-hints">
							<li>{{ t('pulse', 'Once started, there is no going back to earlier questions.') }}</li>
							<li>{{ t('pulse', 'Finish on this device — your progress is saved in this browser.') }}</li>
							<li v-if="paceWindow.timed">{{ t('pulse', 'Each question’s timer keeps running while you’re away.') }}</li>
						</ul>
						<button class="submit" :disabled="nextBusy" @click="nextStep">{{ nextBusy ? '…' : t('pulse', 'Start quiz') }}</button>
					</template>
					<!-- Started, but no open question (aborted between two questions): /next heals it. -->
					<template v-else>
						<p class="muted pace-line">{{ t('pulse', 'Your quiz continues.') }}</p>
						<button class="submit" :disabled="nextBusy" @click="nextStep">{{ nextBusy ? '…' : t('pulse', 'Next') }}</button>
					</template>
				</template>

				<!-- Through (§2.7): "done" means no question left and progress.finished. -->
				<template v-else-if="paceCard === 'through'">
					<PulseIcon name="flag" size="2.4em" class="pace-flag" />
					<h1 class="pace-h" tabindex="-1">{{ t('pulse', 'You’re through!') }}</h1>
					<p v-if="myScore !== null" class="pace-score">{{ n('pulse', '%n point', '%n points', myScore) }}</p>
					<p v-if="myScore !== null && !practice && paceClosesAt === 0" class="muted pace-line">{{ t('pulse', 'The final standings follow when the quiz ends.') }}</p>
					<p v-else-if="myScore !== null && !practice" class="muted pace-line">{{ t('pulse', 'The final standings follow once your host releases the results.') }}</p>
					<p v-else class="muted pace-line">{{ t('pulse', 'Results come once your host releases them.') }}</p>
					<button class="pulse-btn is-secondary review-cta" :disabled="reviewBusy" @click="openReview">{{ reviewBusy ? t('pulse', 'Loading …') : t('pulse', 'See your answers') }}</button>
				</template>

				<!-- Closed with a name: never started -> only the heading. -->
				<template v-else-if="paceCard === 'closed'">
					<PulseIcon name="lock" size="2.4em" class="state-ico" />
					<h1 class="pace-h" tabindex="-1">{{ t('pulse', 'The quiz is closed.') }}</h1>
					<template v-if="progress && progress.started">
						<p v-if="paceLostDraft" class="pulse-notice is-warn pace-notice" role="status">
							<PulseIcon name="warning" size="1.1em" class="pulse-notice-ico" />
							<span>{{ t('pulse', 'Your last answer was not sent in time.') }}</span>
						</p>
						<p v-if="!progress.finished" class="muted pace-line">{{ t('pulse', 'You did not finish every question — your answers so far count.') }}</p>
						<p v-if="myScore !== null" class="pace-score">{{ n('pulse', '%n point', '%n points', myScore) }}</p>
						<p class="muted pace-line">{{ t('pulse', 'Results come once your host releases them.') }}</p>
						<button class="pulse-btn is-secondary review-cta" :disabled="reviewBusy" @click="openReview">{{ reviewBusy ? t('pulse', 'Loading …') : t('pulse', 'See your answers') }}</button>
					</template>
				</template>

				<!-- Released without a leaderboard (practice run, nobody joined): over. -->
				<template v-else>
					<h1 class="end-title pace-h" tabindex="-1">{{ t('pulse', 'Quiz finished') }}</h1>
					<p v-if="nickname && myScore !== null" class="pace-score">{{ n('pulse', '%n point', '%n points', myScore) }}</p>
					<p class="end-thanks">{{ t('pulse', 'Thanks for joining in.') }}<br><span class="code-badge is-inline">{{ spacedCode }}</span></p>
					<button v-if="nickname" class="pulse-btn is-secondary review-cta" :disabled="reviewBusy" @click="openReview">{{ reviewBusy ? t('pulse', 'Loading …') : t('pulse', 'See all results') }}</button>
				</template>
			</div>

			<!-- Waiting for a question -->
			<div v-else-if="!poll && !paceFinal" class="center">
				<p class="code-badge">{{ spacedCode }}</p>
				<p v-if="roomTitle" class="room-title">{{ roomTitle }}</p>
				<p v-if="isQuiz" class="you">{{ t('pulse', 'Ready, {name}', { name: nickname }) }}</p>
				<p class="wait-dot" />
				<p class="muted">{{ t('pulse', 'Waiting for the next question …') }}</p>
				<button class="pulse-btn is-secondary review-cta" :disabled="reviewBusy" @click="openReview">
					{{ reviewBusy ? t('pulse', 'Loading …') : t('pulse', 'See all results') }}
				</button>
			</div>

			<!-- ══════════ REVEALED ══════════ -->
			<!-- Quiz: your own result first, then the distribution, then the
			     leaderboard excerpt. Poll: your own answer first (§8.6). -->
			<div v-else-if="quizEnded || paceFinal" class="h-app">
				<div class="h-scroll">
					<p class="end-title">{{ t('pulse', 'Quiz finished') }}</p>
					<div v-if="myRank" class="big-place">
						<span class="big-place-n">{{ myRank.rank }}</span>
						<span class="big-place-of">{{ t('pulse', 'of {total}', { total: leaderboardCount }) }} · {{ n('pulse', '%n point', '%n points', myRank.score) }}</span>
					</div>
					<!-- No podium replica: the podium is the big screen's job (§8.7). -->
					<Leaderboard :rows="leaderboard" :limit="3" :top-only="rankContext.length > 0" :me="leaderboardMe" />
					<template v-if="rankContext.length">
						<p class="h-hint">{{ t('pulse', 'Around you') }}</p>
						<Leaderboard :rows="rankContext" :limit="rankContext.length" />
					</template>
					<p class="end-thanks">{{ t('pulse', 'Thanks for joining in.') }}<br><span class="code-badge is-inline">{{ spacedCode }}</span></p>
				</div>
				<div class="h-submit">
					<button class="pulse-btn is-secondary" :disabled="reviewBusy" @click="openReview">
						{{ reviewBusy ? t('pulse', 'Loading …') : t('pulse', 'See all results') }}
					</button>
				</div>
			</div>

			<div v-else-if="revealed" class="h-app">
				<div class="h-scroll">
					<span v-if="practice" class="pulse-chip is-warning practice-chip"><PulseIcon name="warning" size="1em" /> {{ t('pulse', 'Practice run · does not count') }}</span>
					<h1 class="q">{{ poll.question }}</h1>

					<!-- 1. Your own result sits ABOVE the distribution — it is what
					     people look for first (§8.6). -->
					<div v-if="isQuiz && myResult && myResult.answered" class="verdict" :class="myResult.correct ? 'is-ok' : 'is-no'">
						<span class="verdict-mark"><PulseIcon :name="myResult.correct ? 'check' : 'close'" size="2em" /></span>
						<span class="verdict-text">{{ myResult.correct ? t('pulse', 'Correct!') : t('pulse', 'Not this time') }}</span>
						<span v-if="myResult.correct" class="pulse-chip is-success verdict-points">+{{ myResult.points }}</span>
						<span v-else class="verdict-sub">{{ t('pulse', 'No point — the correct answer is marked.') }}</span>
						<span v-if="myRank" class="verdict-sub">{{ t('pulse', 'Place') }} <b>{{ myRank.rank }}</b> · {{ n('pulse', '%n point', '%n points', myRank.score) }}</span>
					</div>
					<div v-else-if="!isQuiz && voted" class="mine-box">
						<span class="mine-k">{{ t('pulse', 'Your answer') }}</span>
						<span class="mine-v">{{ myPickLabel || myAnswerText({ poll, mine: { value: myValue } }) }}</span>
						<span v-if="myPickShare" class="mine-s">{{ myPickShare }}</span>
					</div>
					<p v-else class="band is-mut">{{ t('pulse', 'Did not answer this round.') }}</p>

					<!-- 2. The distribution. -->
					<ResultsView :results="results" :mine="voted ? compassMine : null" :mine-aspects="voted ? spectrumMine : null"
						:correct-id="poll.correctOption || ''"
						:correct-ids="(poll.answerKey && poll.answerKey.correct) || []"
						:answer-key="poll.answerKey || null" />

					<!-- 3. Leaderboard excerpt: your own place plus two on either side. -->
					<template v-if="isQuiz && rankContext.length">
						<p class="h-hint">{{ t('pulse', 'Around you') }}</p>
						<Leaderboard :rows="rankContext" :limit="rankContext.length" />
					</template>
				</div>
				<div class="h-submit">
					<button class="pulse-btn is-secondary" :disabled="reviewBusy" @click="openReview">
						{{ reviewBusy ? t('pulse', 'Loading …') : t('pulse', 'See all results') }}
					</button>
				</div>
			</div>

			<!-- Self-paced: time is up, nothing submitted (§2.6). "Next" only once
			     the SERVER reports the time is up (progress.timeUp) — the local
			     countdown reaches 0 up to a second earlier, and before that /next would be
			     a silent no-op. `!busy`: an answer in the last second does not make
			     "Time is up" flash. -->
			<div v-else-if="isPaced && expired && !voted && !busy" class="center pace-timeup">
				<p v-if="practice" class="pace-practice"><span class="pulse-chip is-warning practice-chip"><PulseIcon name="warning" size="1em" /> {{ t('pulse', 'Practice run · does not count') }}</span></p>
				<p v-if="progress" class="pace-pos">
					<span>{{ t('pulse', 'Question {number} of {total}', { number: progress.k, total: progress.n }) }}<template v-if="paceUntil"> · {{ t('pulse', 'Open until {time}', { time: paceUntil }) }}</template></span>
					<span v-if="closesInMin" class="pulse-chip is-warning pace-soon">{{ n('pulse', 'Closes in %n min', 'Closes in %n min', closesInMin) }}</span>
				</p>
				<h1 class="q" tabindex="-1">{{ poll.question }}</h1>
				<PulseIcon name="timer" size="2.4em" class="state-ico" />
				<p class="muted pace-line" role="status">{{ paceLast ? t('pulse', 'Time is up.') : t('pulse', 'Time is up — on to the next question.') }}</p>
				<button class="submit pace-next" :disabled="!canNext" :aria-busy="nextBusy ? 'true' : 'false'" @click="nextStep">
					{{ nextBusy ? '…' : (progress && progress.timeUp ? nextLabel : t('pulse', 'One moment …')) }}
				</button>
			</div>

			<!-- Time is up, nothing submitted (quiz only, moderated) -->
			<div v-else-if="isQuiz && !isPaced && expired && !voted" class="center">
				<h1 class="q">{{ poll.question }}</h1>
				<PulseIcon name="timer" size="2.4em" class="state-ico" />
				<p class="muted">{{ t('pulse', 'Time is up — the reveal is coming.') }}</p>
			</div>

			<!-- ══════════ ANSWERING ══════════ -->
			<!-- One frame for both modes (§8.1): the content scrolls at the top, the
			     submit zone sits at the bottom as its own grid row. -->
			<div v-else class="h-app">
				<div ref="scroll" class="h-scroll">
					<span v-if="practice" class="pulse-chip is-warning practice-chip"><PulseIcon name="warning" size="1em" /> {{ t('pulse', 'Practice run · does not count') }}</span>
					<!-- Self-paced: "Question k of n" from progress (never poll.position), then
					     the deadline; in the last quarter of an hour the chip (§2.5). -->
					<p v-if="isPaced && progress" class="pace-pos">
						<span>{{ t('pulse', 'Question {number} of {total}', { number: progress.k, total: progress.n }) }}<template v-if="paceUntil"> · {{ t('pulse', 'Open until {time}', { time: paceUntil }) }}</template></span>
						<span v-if="closesInMin" class="pulse-chip is-warning pace-soon">{{ n('pulse', 'Closes in %n min', 'Closes in %n min', closesInMin) }}</span>
					</p>
					<div v-if="remaining !== null" class="timer" :class="{ 'is-low': remaining <= 5 }" role="status" :aria-label="n('pulse', '%n second left', '%n seconds left', remaining)">
						<div class="timer-track"><div class="timer-fill" :style="{ width: timerPct + '%' }" /></div>
						<span class="timer-num">{{ remaining }}</span>
					</div>

					<!-- Image: fixed box, flatter from five options on; tapping enlarges it. -->
					<button v-if="poll.image" type="button" class="h-img" :class="{ 'is-small': optCount > 4 }"
						:aria-label="t('pulse', 'Enlarge image')" @click="zoom = true">
						<img :src="imageUrl(poll)" :alt="t('pulse', 'Image for the question')">
					</button>

					<h1 class="q" :tabindex="isPaced ? -1 : null">{{ poll.question }}</h1>
					<p v-if="showTapHint" class="h-hint">{{ t('pulse', 'Tapping sends right away — you can change it just after.') }}</p>

					<!-- Choice and True/False: in the quiz the tap submits immediately (§8.0). -->
					<div v-if="poll.type === 'choice' || poll.type === 'truefalse'" class="choices">
						<button v-for="o in optList" :key="o.id"
							class="choice" :class="[o.pal.cls, { 'is-quiz': isQuiz, 'is-picked': isPicked(o.id), 'is-dim': sent && !isPicked(o.id) }]"
							:aria-checked="isPicked(o.id)" role="radio" :disabled="busy || locked || paceHold" @click="pickOne(o.id, $event)">
							<span class="pulse-badge choice-badge">{{ o.pal.letter }}</span>
							<span class="choice-label">{{ o.label }}</span>
							<PulseIcon v-if="isPicked(o.id)" name="check" size="1.2em" class="choice-tick" />
						</button>
					</div>

					<!-- Multiple choice: tap, then submit -->
					<div v-else-if="poll.type === 'multi'" class="choices">
						<button v-for="o in optList" :key="o.id"
							class="choice" :class="[o.pal.cls, { 'is-quiz': isQuiz, 'is-picked': multiPick.includes(o.id), 'is-dim': sent && !multiPick.includes(o.id) }]"
							:aria-checked="multiPick.includes(o.id)" role="checkbox" :disabled="busy || locked || paceHold" @click="toggleMulti(o.id)">
							<span class="pulse-badge choice-badge">{{ o.pal.letter }}</span>
							<span class="choice-label">{{ o.label }}</span>
							<PulseIcon v-if="multiPick.includes(o.id)" name="check" size="1.2em" class="choice-tick" />
						</button>
					</div>

					<!-- Estimation question / free text -->
					<div v-else-if="poll.type === 'number'" class="free-vote">
						<input v-model="numInput" class="pulse-input is-lg" type="number" inputmode="decimal"
							:disabled="locked" :placeholder="t('pulse', 'Your number')" @keyup.enter="doSubmit">
					</div>
					<div v-else-if="poll.type === 'text'" class="free-vote">
						<input v-model="textInput" class="pulse-input is-lg" type="text" maxlength="100"
							autocomplete="off" :disabled="locked" :placeholder="t('pulse', 'Your answer')" @keyup.enter="doSubmit">
					</div>

					<!-- Ordering: ↑/↓ instead of drag — keyboard-proof and accurate -->
					<div v-else-if="poll.type === 'rank'" class="rank-field">
						<p class="h-hint">{{ t('pulse', 'Put the answers into your order — place 1 goes on top.') }}</p>
						<ol ref="rankList" class="rank-list">
							<li v-for="(o, i) in rankOrder" :key="o.id" class="rank-item" :data-rank-id="o.id">
								<span class="rank-pos">{{ i + 1 }}</span>
								<span class="rank-label">{{ o.label }}</span>
								<span class="rank-move">
									<button class="pulse-btn is-secondary is-icon rank-arrow" :disabled="busy || locked || i === 0" :aria-label="t('pulse', '{answer} up', { answer: o.label })" @click="moveRank(i, -1)"><PulseIcon name="arrow-up" size="1.1em" /></button>
									<button class="pulse-btn is-secondary is-icon rank-arrow" :disabled="busy || locked || i === rankOrder.length - 1" :aria-label="t('pulse', '{answer} down', { answer: o.label })" @click="moveRank(i, 1)"><PulseIcon name="arrow-down" size="1.1em" /></button>
								</span>
							</li>
						</ol>
					</div>

					<!-- Matching: a row plus a picker sheet instead of bare select fields (§8.3) -->
					<div v-else-if="poll.type === 'match'" class="match-field">
						<p class="h-hint">{{ t('pulse', 'Assign the fitting answer to every line.') }}</p>
						<ul class="mrow-list">
							<li v-for="item in matchItems" :key="item.id">
								<button type="button" class="mrow" :disabled="busy || locked" @click="openSheet(item.id)">
									<span class="mrow-cat">{{ item.label }}</span>
									<span class="mrow-chip" :class="[matchPickPal(item.id), { 'is-set': matchPick[item.id] }]">
										{{ matchPick[item.id] ? matchLabelOf(matchPick[item.id]) : t('pulse', 'Choose') }}
									</span>
								</button>
							</li>
						</ul>
					</div>

					<!-- Scale -->
					<div v-else-if="poll.type === 'scale' && scaleMode === 'single'" class="scale-field">
						<div class="val-readout" :class="{ 'is-empty': scaleVal === null }" aria-hidden="true">
							<span class="val-num">{{ scaleVal === null ? '–' : scaleVal }}</span><span class="val-unit">/ {{ poll.scale.max }}</span>
						</div>
						<input class="scale-range is-uni" type="range" :min="poll.scale.min" :max="poll.scale.max" step="1" :value="scaleVal === null ? poll.scale.min : scaleVal" :disabled="busy || locked" :aria-label="scaleAria" :aria-valuetext="scaleVal === null ? t('pulse', 'not chosen yet') : String(scaleVal)" :style="{ '--pct': scalePct + '%' }" @input="onScaleInput" @pointerdown="onScaleInput">
						<div class="scale-ends">
							<span>{{ poll.scale.min }}<template v-if="poll.scale.minLabel"> · {{ poll.scale.minLabel }}</template></span>
							<span>{{ poll.scale.max }}<template v-if="poll.scale.maxLabel"> · {{ poll.scale.maxLabel }}</template></span>
						</div>
					</div>

					<!-- Spectrum: bipolar sliders -->
					<div v-else-if="poll.type === 'scale' && scaleMode === 'spectrum'" class="spectrum-field">
						<p class="h-hint">{{ t('pulse', 'The sliders sit neutral on the middle mark — move only what differs for you.') }}</p>
						<div v-for="asp in poll.scale.aspects" :key="asp.id" class="aspect">
							<div class="aspect-head">
								<span class="aspect-name">{{ asp.label }}</span>
								<span class="aspect-val" :class="{ 'is-neutral': spectrumVals[asp.id] === neutralValue }" aria-hidden="true">{{ spectrumVals[asp.id] }}</span>
							</div>
							<div class="track-wrap">
								<span class="mid-tick" :style="{ left: neutralPct + '%' }" aria-hidden="true" />
								<input class="scale-range is-bip" type="range" :min="poll.scale.min" :max="poll.scale.max" step="1" :value="spectrumVals[asp.id]" :disabled="busy || locked" :aria-label="aspectAria(asp)" :aria-valuetext="String(spectrumVals[asp.id])" :style="spectrumFill(asp.id)" @input="onSpectrumInput(asp.id, $event)">
							</div>
							<div class="scale-ends">
								<span>{{ poll.scale.min }}<template v-if="asp.poleLow"> · {{ asp.poleLow }}</template></span>
								<span>{{ poll.scale.max }}<template v-if="asp.poleHigh"> · {{ asp.poleHigh }}</template></span>
							</div>
						</div>
					</div>

					<!-- Compass: pad, pole labels horizontal, values in words (§8.4) -->
					<div v-else-if="poll.type === 'scale'" class="compass-field">
						<div class="pad-wrap">
							<span class="pad-pole">{{ poll.scale.axisY.poleHigh }}</span>
							<div ref="pad" class="pad" role="application" :aria-label="compassAria" @pointerdown="onPadDown" @pointermove="onPadMove" @pointerup="onPadUp" @pointercancel="onPadUp">
								<span class="pad-axis pad-axis-v" />
								<span class="pad-axis pad-axis-h" />
								<span class="pad-side pad-side-l">{{ poll.scale.axisX.poleLow }}</span>
								<span class="pad-side pad-side-r">{{ poll.scale.axisX.poleHigh }}</span>
								<span class="pad-thumb" :style="compassThumbStyle" />
							</div>
							<span class="pad-pole">{{ poll.scale.axisY.poleLow }}</span>
						</div>
						<!-- In words with the number as an extra, not "PACE 0 · SCOPE 0". -->
						<div class="axwords">
							<p><span class="axwords-k">{{ poll.scale.axisX.title }}:</span> {{ axisWord('x') }}</p>
							<p><span class="axwords-k">{{ poll.scale.axisY.title }}:</span> {{ axisWord('y') }}</p>
						</div>
						<div class="axrow">
							<span class="axrow-name">{{ poll.scale.axisX.title }}</span>
							<input class="scale-range is-self" type="range" :min="-compassRange" :max="compassRange" step="1" :value="compassX" :disabled="busy || locked" :aria-label="axisAria('x')" @input="setCompassX($event)">
						</div>
						<div class="axrow">
							<span class="axrow-name">{{ poll.scale.axisY.title }}</span>
							<input class="scale-range is-self" type="range" :min="-compassRange" :max="compassRange" step="1" :value="compassY" :disabled="busy || locked" :aria-label="axisAria('y')" @input="setCompassY($event)">
						</div>
					</div>

					<!-- Word cloud -->
					<div v-else class="words">
						<p class="h-hint">{{ n('pulse', 'Up to %n word — one per field.', 'Up to %n words — one per field.', poll.maxWords) }}</p>
						<label v-for="i in poll.maxWords" :key="i" class="word-field">
							<input v-model="wordInputs[i - 1]" class="pulse-input" type="text" maxlength="40"
								:disabled="locked" :aria-label="t('pulse', 'Word {number}', { number: i })"
								:placeholder="i === 1 ? t('pulse', 'Your word …') : t('pulse', 'Another word (optional)')">
							<span class="word-count">{{ (wordInputs[i - 1] || '').length }} / 40</span>
						</label>
					</div>

					<!-- Self-paced without a timer: skip the question (§2.5). Below the answers,
					     not in the submit zone — "Next question" was there a moment ago, and a
					     double tap would have skipped the new question unanswered. Only armed 1.5 s
					     after every new question (skipArmed). No confirmation. -->
					<button v-if="isPaced && poll.timeLimit === 0 && !voted && !busy" type="button" class="pulse-btn is-tertiary is-sm pace-skip"
						:disabled="!skipArmed || !canNext" @click="nextStep">
						{{ paceLast ? t('pulse', 'Skip and finish') : t('pulse', 'Skip question') }}
					</button>
				</div>

				<!-- Submit zone: its own grid row, never scrolls along (§8.1) -->
				<div class="h-submit">
					<!-- Correction window: confirmation plus "Change", below it a
					     hairline. No second number — it would compete with the question's
					     countdown (§8.0). -->
					<div v-if="fixOpen" class="fix-bar" role="status">
						<PulseIcon name="check" size="1.2em" />
						<span>{{ t('pulse', 'Answer sent') }}</span>
						<button class="fix-btn" @click="startChange">{{ t('pulse', 'Change') }}</button>
						<span class="fix-line" :style="{ width: fixPct + '%' }" aria-hidden="true" />
					</div>
					<!-- Self-paced (§2.5): verdict band and Next. Neutral before the final
					     verdict — no green flash — and never the solution. "Next" only with
					     the verdict (canNext). data-verdict is for the test rig (none = none
					     yet); never colour alone: icon + word. -->
					<template v-else-if="sent && isPaced">
						<p class="band pace-verdict" :class="paceBand.cls" role="status" :data-verdict="paceVerdict || 'none'">
							<PulseIcon :name="paceBand.icon" size="1.1em" /> {{ paceBand.text }}
							<span v-if="paceVerdict === 'correct'" class="pulse-chip is-success pace-points">+{{ myResult.points }}</span>
						</p>
						<button class="pulse-btn is-primary submit-btn pace-next" :disabled="!canNext" :aria-busy="nextBusy ? 'true' : 'false'" @click="nextStep">
							{{ nextBusy ? '…' : nextLabel }}
						</button>
					</template>
					<template v-else-if="sent">
						<p class="band is-ok" role="status"><PulseIcon name="check" size="1.1em" /> {{ t('pulse', 'Answer sent') }}</p>
						<p v-if="intakeText" class="h-note">{{ intakeText }}</p>
						<button v-if="canChange" class="pulse-btn is-secondary" @click="startChange">{{ t('pulse', 'Change answer') }}</button>
					</template>
					<template v-else>
						<p v-if="submitNote" class="h-note">{{ submitNote }}</p>
						<button v-if="needsSubmit" class="pulse-btn is-primary submit-btn" :disabled="busy || locked || paceHold || !submitReady" @click="doSubmit">
							{{ busy ? '…' : submitLabel }}
						</button>
					</template>
				</div>
			</div>

			<!-- Picker sheet for matching (§8.3) — lies ABOVE the scroll area,
			     not inside it: a sheet inside the scroll container would be clipped. -->
			<template v-if="sheetFor">
				<div class="scrim" @click="closeSheet" />
				<div class="msheet" role="dialog" aria-modal="true" :aria-label="t('pulse', 'Choose an answer')">
					<h2 class="msheet-h">{{ matchLabelOfItem(sheetFor) }}</h2>
					<div class="msheet-opts">
						<button v-for="(target, i) in matchTargets" :key="target.id" type="button"
							class="msheet-opt" :class="[optPal(i).cls, { 'is-used': usedTarget(target.id, sheetFor) }]"
							@click="chooseTarget(target.id)">
							<span class="pulse-badge msheet-badge">{{ optPal(i).letter }}</span>
							<span>{{ target.label }}</span>
							<small v-if="usedTarget(target.id, sheetFor)">{{ t('pulse', 'already used') }}</small>
						</button>
					</div>
					<button class="pulse-btn is-secondary msheet-x" @click="closeSheet">{{ t('pulse', 'Back') }}</button>
				</div>
			</template>

			<!-- Full-screen image (§8.1) -->
			<div v-if="zoom" class="h-zoom" @click="zoom = false">
				<img :src="imageUrl(poll)" :alt="t('pulse', 'Image for the question')">
				<button class="h-zoom-x" :aria-label="t('pulse', 'Close')" @click.stop="zoom = false"><PulseIcon name="close" size="1.4em" /></button>
			</div>
			<!-- Connection status -->
			<p v-if="code && roomExists && !online" class="conn-off" role="status">{{ t('pulse', 'Connection lost — trying again …') }}</p>
			<!-- Self-paced: announcement for screen readers on card and question changes (§2.11) -->
			<p v-if="isPaced" class="pace-sr" aria-live="polite">{{ paceAnnounce }}</p>
		</div>
	</div>
</template>

<script>
/*
 * The participant view (phone). Section references (§…) point to the design
 * notes of the redesign and of the self-paced quiz, which are not in the public
 * repository (see "References in code comments" in the README).
 */
import axios from '@nextcloud/axios'
import { PHONE_TIMEOUT, publicApi, pollImage, participantPage, joinPage } from './util/routes.js'
import { loadState } from '@nextcloud/initial-state'
import { t, n } from './util/l10n.js'
import { showError, serverMessage } from './toast.js'
import { option, withPalette } from './util/palette.js'
import { formatCode, remainingSecs, remainingPct } from './util/format.js'
import { reloadOnProtocolMismatch } from './util/protocol.js'
import pollingMixin from './mixins/polling.js'
import pacePhone from './mixins/pace-phone.js'
import { phoneDelay } from './util/pace.js'
import PulseIcon from './components/ui/PulseIcon.vue'
import ResultsView from './components/ResultsView.vue'
import Leaderboard from './components/Leaderboard.vue'

export default {
	name: 'Participant',
	mixins: [pollingMixin, pacePhone],
	components: { PulseIcon, ResultsView, Leaderboard },
	data() {
		return {
			code: loadState('pulse', 'code', ''),
			roomExists: loadState('pulse', 'roomExists', false),
			mode: '',
			roomTitle: '',   // room name from publicState.room (empty = none)
			review: null,       // loaded overall summary (null = live view)
			reviewBusy: false,
			reviewAt: 0,        // question ID when opened — if it changes, we close
			practice: false,
			poll: null,
			results: null,
			voted: false,
			changing: false,
			busy: false,
			online: true,
			wordInputs: [],
			multiPick: [],   // multiple choice: currently tapped option IDs
			scaleVal: null,  // scale slider (null = not answered yet)
			spectrumVals: {},  // spectrum: aspectId -> value (start = middle)
			compassX: 0,        // compass: X position (−R..R)
			compassY: 0,        // compass: Y position (−R..R)
			compassMine: null,  // own submitted position ({x,y}) for the "You" dot
			spectrumMine: null, // own submitted aspect values for the "You" polygon
			padDown: false,     // 2D pad: pointer gesture active
			numInput: '',     // estimation question
			rankOrder: [],    // own order (ordering question), pre-filled shuffled
			matchPick: {},    // matching: itemId -> targetId (empty = still open)
			matchTargets: [], // right column, shuffled once (otherwise row i = target i would be the solution)
			textInput: '',   // free text
			version: '',
			lastPollKey: null,
			submitSeq: 0,       // counts submits; older /state responses are discarded
			digits: ['', '', '', '', '', ''],
			entryError: '',
			checking: false,
			// Quiz
			nickname: null,
			nickInput: '',
			nickError: '',
			joining: false,
			myResult: null,
			leaderboard: null,
			// Public leaderboards are the top 10 only (lib/Service/PublicPayload.php):
			// true length, one's own row and its neighbours come separately.
			leaderboardTotal: 0,
			leaderboardMe: null,
			leaderboardAround: [],
			// The selection before submitting (poll, single answer) and the
			// submitted answer from the server state — we need both to keep
			// your own card marked even after sending (§8.5).
			singlePick: null,
			myValue: null,
			answered: 0,
			present: 0,
			// Correction window (§8.0): until when "Change" is shown, how long the
			// window was (for the hairline) and whether it has been used up.
			fixUntil: 0,
			fixWindow: 3,
			fixUsed: false,
			// The first quiz question of the session gets the hint line.
			hintShown: false,
			// Picker sheet for matching: itemId or null.
			sheetFor: null,
			zoom: false,
			serverSkew: 0,
			nowSec: Math.floor(Date.now() / 1000),
			tickTimer: null,
		}
	},
	computed: {
		spacedCode() {
			return formatCode(this.code)
		},
		// No dead end on "room not found" (§4.11): back to the code entry.
		joinPagePath() {
			return joinPage()
		},
		hasWords() {
			return this.wordInputs.some((w) => (w || '').trim() !== '')
		},
		// Matching: left column in editor order (it is the reading direction).
		matchItems() {
			return (this.poll && this.poll.match && this.poll.match.items) || []
		},
		// Submit only once every row has a target — the server rejects a half
		// matching anyway.
		matchComplete() {
			const items = this.matchItems
			return items.length > 0 && items.every((item) => !!this.matchPick[item.id])
		},
		// Scale slider: fill level in % ALWAYS from (v-min)/(max-min), not v/max,
		// otherwise the fill is wrong for min=1 (design notes §4, not in the public repository).
		scalePct() {
			if (!this.poll || !this.poll.scale || this.scaleVal === null) return 0
			const { min, max } = this.poll.scale
			return max > min ? Math.round(((this.scaleVal - min) / (max - min)) * 100) : 0
		},
		scaleAria() {
			if (!this.poll || !this.poll.scale) return ''
			const sc = this.poll.scale
			const lo = sc.minLabel ? ` (${sc.minLabel})` : ''
			const hi = sc.maxLabel ? ` (${sc.maxLabel})` : ''
			return this.t('pulse', '{question} – scale from {min}{low} to {max}{high}', { question: this.poll.question, min: sc.min, low: lo, max: sc.max, high: hi })
		},
		scaleMode() {
			return this.poll && this.poll.scale ? (this.poll.scale.mode || 'single') : 'single'
		},
		// Spectrum answer: copy of the slider values (aspectId -> value) for submitting.
		// Neutral value of the scale — start value, middle mark and zero point of the
		// bipolar fill are THE SAME value. Previously the mark sat at 50 %
		// and the start value next to it; on 0–5, "sits in the middle" looked wrong.
		neutralValue() {
			if (!this.poll || !this.poll.scale) return 0
			const { min, max } = this.poll.scale
			return Math.floor((Number(min) + Number(max)) / 2)
		},
		neutralPct() {
			if (!this.poll || !this.poll.scale) return 50
			const { min, max } = this.poll.scale
			return max > min ? Math.round(((this.neutralValue - min) / (max - min)) * 1000) / 10 : 50
		},
		spectrumPayload() {
			return { ...this.spectrumVals }
		},
		compassRange() {
			return this.poll && this.poll.scale ? (this.poll.scale.range || 5) : 5
		},
		compassThumbStyle() {
			const R = this.compassRange
			const left = ((this.compassX / R + 1) / 2) * 100
			const top = (1 - (this.compassY / R + 1) / 2) * 100
			return { left: left + '%', top: top + '%' }
		},
		compassAria() {
			if (!this.poll || !this.poll.scale || !this.poll.scale.axisX) return ''
			const sc = this.poll.scale
			return this.t('pulse', '2D field: X {x}, Y {y}; drag the dot or use the sliders', { x: sc.axisX.title, y: sc.axisY.title })
		},
		// Choice/multi options with a stable palette assignment (letter + colour A–H).
		// The same index -> the same colour on the phone, the projector and in the result bar.
		optList() {
			return withPalette(this.poll && this.poll.options)
		},
		entryCode() {
			return this.digits.join('')
		},
		entryValid() {
			return /^[A-Z0-9]{6}$/.test(this.entryCode)
		},
		// ── Quiz ────────────────────────────────────────────────────────────
		isQuiz() {
			return this.mode === 'quiz'
		},
		// Revealed is what the server says (poll.revealed) — the status alone is not
		// enough: with "Reveal at the end", 'locked' is still hidden. The
		// fallback to the status only applies to a response without that field.
		revealed() {
			if (!this.poll) return false
			if (typeof this.poll.revealed === 'boolean') return this.poll.revealed
			return this.poll.status === 'locked' || this.poll.status === 'ended'
		},
		// Remaining seconds (server time via skew), null when there is no limit.
		remaining() {
			return remainingSecs(this.poll, this.nowSec, this.serverSkew)
		},
		expired() {
			return this.remaining === 0
		},
		timerPct() {
			return remainingPct(this.poll, this.remaining)
		},
		myRank() {
			const src = this.review || { leaderboard: this.leaderboard, leaderboardMe: this.leaderboardMe }
			return (src.leaderboard || []).find((r) => r.me) || src.leaderboardMe || null
		},
		// "of {total}": the length of the whole leaderboard, not of the top 10 sent.
		leaderboardCount() {
			return Math.max(this.leaderboardTotal || 0, (this.leaderboard || []).length)
		},
		// Quiz ended: your own place first, not the last question (§8.7).
		quizEnded() {
			return this.isQuiz && !!this.poll && this.poll.status === 'ended'
				&& Array.isArray(this.leaderboard) && this.leaderboard.length > 0
		},
		// Leaderboard excerpt: your own place plus two above and two below.
		// The server cuts it from the full list (leaderboardAround); the
		// top 10 alone would lack it from place 11 on.
		rankContext() {
			const rows = this.leaderboard || []
			const at = rows.findIndex((r) => r.me)
			// If you are in the top 3 anyway, the excerpt would be a repetition.
			if (at >= 0 && at < 3) return []
			if (this.leaderboardAround.length) return this.leaderboardAround
			if (at < 0) return []
			return rows.slice(Math.max(0, at - 2), at + 3)
		},
		optCount() {
			return (this.poll && Array.isArray(this.poll.options)) ? this.poll.options.length : 0
		},
		// "sent" means: submitted and not in change mode right now.
		sent() {
			return this.voted && !this.changing
		},
		// The cards stay visible after sending, but can no longer be used (§8.5).
		locked() {
			return this.sent || !!(this.poll && this.poll.status !== 'active')
		},
		// Hint line only for the first quiz question of a session and only where
		// a tap really submits immediately.
		showTapHint() {
			return this.isQuiz && !this.voted && !this.hintShown
				&& !!this.poll && ['choice', 'truefalse'].includes(this.poll.type)
		},
		fixOpen() {
			return this.isQuiz && this.sent && !this.fixUsed && !this.revealed
				&& this.nowSec < this.fixUntil
		},
		fixPct() {
			if (!this.fixWindow) return 0
			return Math.max(0, Math.min(100, ((this.fixUntil - this.nowSec) / this.fixWindow) * 100))
		},
		// Poll: change until the reveal. Quiz: only in the correction window, and
		// that one carries its own button.
		canChange() {
			return !this.isQuiz && !this.revealed && !!this.poll && this.poll.status === 'active'
		},
		// Intake count — the same number as on the projector (§8.5).
		intakeText() {
			if (!this.answered) return ''
			// Presence is a 15-second window, the answers are not —
			// without a cap, after a break it would say "42 of 25".
			const total = Math.max(this.present, this.answered)
			return total > this.answered
				? this.t('pulse', '{count} of {total} have answered', { count: this.answered, total })
				: this.answered + ' ' + this.n('pulse', 'answer', 'answers', this.answered)
		},
		// Which types need a submit button? Not choice and True/False in the
		// quiz — there the tap submits (§8.0).
		needsSubmit() {
			if (!this.poll) return false
			if (this.isQuiz && ['choice', 'truefalse'].includes(this.poll.type)) return false
			return true
		},
		submitReady() {
			if (!this.poll) return false
			switch (this.poll.type) {
			case 'choice':
			case 'truefalse':
				return !!this.singlePick
			case 'multi':
				return this.multiPick.length > 0
			case 'number':
				return this.numInput !== '' && !isNaN(Number(this.numInput))
			case 'text':
				return !!this.textInput.trim()
			case 'rank':
				return this.rankOrder.length >= 2
			case 'match':
				return this.matchComplete
			case 'words':
				return this.hasWords
			case 'scale':
				return this.scaleMode === 'single' ? this.scaleVal !== null : true
			default:
				return true
			}
		},
		submitLabel() {
			if (this.poll && this.poll.type === 'multi' && this.multiPick.length) {
				return this.t('pulse', '{count} selected · submit', { count: this.multiPick.length })
			}
			return this.t('pulse', 'Submit')
		},
		// The reason why "Submit" does not work (yet) sits above the button.
		submitNote() {
			if (!this.poll) return ''
			if (this.poll.type === 'match') {
				return this.t('pulse', '{count} of {total} assigned', { count: this.matchDone, total: this.matchItems.length })
			}
			if (this.poll.type === 'scale' && this.scaleMode === 'single') {
				return this.scaleVal === null
					? this.t('pulse', 'Drag the slider to your value. Nothing chosen yet.')
					: this.t('pulse', 'Your value — you can still move it.')
			}
			if (this.poll.type === 'scale' && this.scaleMode === 'compass') {
				return this.t('pulse', 'Drag the dot or use the sliders. Centre = neutral (counts).')
			}
			return ''
		},
		matchDone() {
			return this.matchItems.filter((item) => this.matchPick[item.id]).length
		},
		// Poll reveal: your own answer in words plus how many share it.
		myPickLabel() {
			const rows = (this.results && this.results.results) || []
			const mine = this.myValue
			if (mine === null || mine === undefined) return ''
			if (typeof mine === 'string') {
				const hit = rows.find((r) => r.id === mine)
				return hit ? hit.label : ''
			}
			if (typeof mine === 'number') return String(mine)
			return ''
		},
		myPickShare() {
			const rows = (this.results && this.results.results) || []
			const hit = rows.find((r) => r.id === this.myValue)
			if (!hit || !this.results.total) return ''
			return this.t('pulse', '{count} of {total} chose the same', { count: hit.count, total: this.results.total })
		},
	},
	mounted() {
		if (this.code && this.roomExists) {
			this.pollTick()
			// Local clock for the countdown (the server skew is re-synced on every fetch).
			this.tickTimer = setInterval(() => { this.nowSec = Math.floor(Date.now() / 1000) }, 500)
			document.addEventListener('visibilitychange', this.onPollVisibility)
		}
	},
	beforeDestroy() {
		this.pollStopped = true
		this.pollClear()
		if (this.tickTimer) clearInterval(this.tickTimer)
		document.removeEventListener('visibilitychange', this.onPollVisibility)
	},
	methods: {
		// ── Matching ────────────────────────────────────────────────────────
		initMatch(poll, mine) {
			if (!poll || poll.type !== 'match') return { targets: [], pick: {} }
			const targets = ((poll.match || {}).targets || []).slice()
			for (let i = targets.length - 1; i > 0; i--) {
				const j = Math.floor(Math.random() * (i + 1))
				;[targets[i], targets[j]] = [targets[j], targets[i]]
			}
			const pick = {}
			const valid = {}
			for (const target of targets) valid[target.id] = true
			for (const item of (poll.match || {}).items || []) {
				const earlier = mine && typeof mine === 'object' ? mine[item.id] : ''
				pick[item.id] = valid[earlier] ? earlier : ''
			}
			return { targets, pick }
		},
		submitMatch() {
			this.submit({ ...this.matchPick })
		},

		// ── Ordering question ───────────────────────────────────────────────
		// Starting line-up: your own earlier answer, otherwise SHUFFLED — otherwise
		// the editor order (= the solution) would be the default in the quiz.
		initRank(poll, mine) {
			if (!poll || poll.type !== 'rank') return []
			const opts = (poll.options || []).slice()
			if (Array.isArray(mine) && mine.length === opts.length) {
				const byId = {}
				for (const o of opts) byId[o.id] = o
				const sorted = mine.map((id) => byId[id]).filter(Boolean)
				if (sorted.length === opts.length) return sorted
			}
			for (let i = opts.length - 1; i > 0; i--) {
				const j = Math.floor(Math.random() * (i + 1))
				;[opts[i], opts[j]] = [opts[j], opts[i]]
			}
			return opts
		},
		moveRank(i, dir) {
			if (this.paceHold) return
			const j = i + dir
			if (j < 0 || j >= this.rankOrder.length) return
			const arr = this.rankOrder.slice()
			const tmp = arr[i]
			arr[i] = arr[j]
			arr[j] = tmp
			this.rankOrder = arr
			// Keep the keyboard on the item that moved. The keyed patch moves one of
			// the two <li>s with insertBefore; on "down" that is the one holding the
			// pressed arrow, and the browser drops the focus to <body>. At the top or
			// bottom the pressed arrow turns disabled and loses the focus as well;
			// then the item's other arrow takes it. The item is found by its id.
			const id = String(tmp.id)
			this.$nextTick(() => {
				const list = this.$refs.rankList
				const li = list ? Array.from(list.children).find((el) => el.dataset.rankId === id) : null
				if (!li) return
				const [up, down] = li.querySelectorAll('.rank-arrow')
				const target = (dir < 0 ? [up, down] : [down, up]).find((b) => b && !b.disabled)
				if (target) target.focus()
			})
		},
		submitRank() {
			this.submit(this.rankOrder.map((o) => o.id))
		},

		// ── Overall summary ─────────────────────────────────────────────────
		// All questions with the result, your own answer and (quiz) the reveal.
		// Only loaded at the press of a button — no continuous polling.
		async openReview() {
			if (this.reviewBusy) return
			this.reviewBusy = true
			try {
				const { data } = await axios.get(this.base('/summary'), this.isPaced ? { timeout: PHONE_TIMEOUT } : undefined)
				if (!data.available) {
					showError(t('pulse', 'The results have not been released yet.'))
					return
				}
				this.reviewAt = this.poll ? this.poll.id : 0
				// Self-paced: when the window state changes, it closes itself (§2.1).
				this.reviewState = (this.paceWindow && this.paceWindow.state) || ''
				this.review = data
				this.toTop()
			} catch (e) {
				showError(t('pulse', 'Could not load the results.'))
				if (this.isPaced && !e?.response) this.online = false
			} finally {
				this.reviewBusy = false
			}
		},
		closeReview() {
			this.review = null
			this.toTop()
		},
		// To the top of the page: the root is the scroll container (see .pulse-part),
		// the window only where a page around it scrolls.
		toTop() {
			this.$nextTick(() => {
				if (this.$el) this.$el.scrollTop = 0
				window.scrollTo(0, 0)
			})
		},
		// Make your own answer readable — it is stored differently per question type.
		myAnswerText(item) {
			const v = item.mine ? item.mine.value : null
			if (v === null || v === undefined || v === '') return '—'
			const labels = {}
			for (const o of item.poll.options || []) labels[o.id] = o.label
			if (typeof v === 'string') return labels[v] || v
			if (typeof v === 'number') return String(v)
			if (Array.isArray(v)) return v.map((x) => labels[x] || x).join(", ") || "—"
			if (typeof v === 'object') {
				// Matching: "item → target" per row.
				const match = item.poll.match
				if (match) {
					const labelById = {}
					for (const target of match.targets || []) labelById[target.id] = target.label
					const rows = (match.items || [])
						.filter((it) => v[it.id])
						.map((it) => this.t('pulse', '{item} → {target}', { item: it.label, target: labelById[v[it.id]] || '?' }))
					return rows.length ? rows.join(' · ') : this.t('pulse', 'submitted')
				}
				// Compass: coordinates; spectrum: one value per aspect.
				if ('x' in v && 'y' in v) return `${v.x} / ${v.y}`
				const aspects = (item.poll.scale && item.poll.scale.aspects) || []
				const parts = aspects.filter((a) => a.id in v).map((a) => `${a.label}: ${v[a.id]}`)
				return parts.length ? parts.join(' · ') : this.t('pulse', 'submitted')
			}
			return String(v)
		},

		// ── State polling ───────────────────────────────────────────────────
		// Image URL of the question (public, only while the question is running/revealed).
		imageUrl(poll) {
			if (!poll || !poll.image) return ''
			return pollImage(this.code, poll.id, poll.image, { public: true })
		},
		base(suffix = '') {
			return publicApi(this.code, suffix)
		},
		// ID plus the shape and version of the question. The order of the IDs does
		// not matter: before the reveal, ordering/matching come shuffled, afterwards
		// as stored. The start time does NOT belong in it: "Show" and "Reopen"
		// set it anew but keep the votes — otherwise the phone would lose
		// inputs and a correction window that is still open.
		pollKey(poll) {
			if (!poll) return null
			const ids = (list) => (list || []).map((o) => o.id).sort().join(',')
			const match = poll.match || {}
			return [poll.id, poll.type, poll.maxWords, poll.question, poll.image,
				ids(poll.options), ids(match.items), ids(match.targets), JSON.stringify(poll.scale || null)].join('|')
		},
		applyState(data) {
			// Different server protocol: old bundle from the cache -> reload once.
			if (reloadOnProtocolMismatch(data.protocol)) return
			// Self-paced (§2.1): BEFORE the existing lines — applyPace still needs
			// the old question and `voted`. Decided on the payload, not on isPaced:
			// on the first state `mode` is still empty.
			const paced = !!data.room && data.room.mode === 'quiz' && data.room.pace === 'self'
			if (paced) this.applyPace(data)
			const newId = data.poll ? data.poll.id : null
			// Close an open summary as soon as the moderator moves on.
			if (this.review && (newId || 0) !== this.reviewAt) this.review = null
			// Reset the local selection on a new question — and on an edited one: then
			// the ID stays, but options/pairs have new IDs, and the old ones
			// would be rejected on submit.
			const key = this.pollKey(data.poll)
			if (key !== this.lastPollKey) {
				this.lastPollKey = key
				this.changing = false
				this.singlePick = null
				this.fixUntil = 0
				this.fixUsed = false
				this.sheetFor = null
				this.zoom = false
				this.wordInputs = data.poll ? new Array(data.poll.maxWords).fill('') : []
				this.multiPick = []
				this.scaleVal = null
				this.spectrumVals = this.initSpectrum(data.poll)
				this.compassX = 0
				this.compassY = 0
				this.compassMine = null
				this.spectrumMine = null
				this.numInput = ''
				this.rankOrder = this.initRank(data.poll, null)
				const fresh = this.initMatch(data.poll, null)
				this.matchTargets = fresh.targets
				this.matchPick = fresh.pick
				this.textInput = ''
			} else if (this.voted && !data.hasVoted) {
				// Own vote gone (question edited or reset):
				// the next answer gets its full correction window again,
				// and no "You" dot points at the deleted vote any more.
				this.changing = false
				this.fixUntil = 0
				this.fixUsed = false
				this.compassMine = null
				this.spectrumMine = null
			}
			this.poll = data.poll
			this.results = data.results
			this.voted = data.hasVoted
			this.myValue = data.myValue
			this.answered = data.answered || 0
			this.present = data.present || 0
			// Already voted? Then show your own order, not a new shuffle.
			if (data.poll && data.poll.type === 'rank' && Array.isArray(data.myValue) && !this.changing) {
				this.rankOrder = this.initRank(data.poll, data.myValue)
			}
			// Already matched? Then show your own matching (the shuffled
			// target column stays), not empty fields again.
			if (data.poll && data.poll.type === 'match' && data.myValue && typeof data.myValue === 'object' && !this.changing) {
				const mine = this.initMatch(data.poll, data.myValue)
				if (!this.matchTargets.length) this.matchTargets = mine.targets
				this.matchPick = mine.pick
			}
			// Multiple choice, number and free text: after a reload show your own answer
			// instead of an empty, locked field.
			if (data.poll && data.hasVoted && !this.changing && data.myValue !== null && data.myValue !== undefined) {
				if (data.poll.type === 'multi' && Array.isArray(data.myValue) && !this.multiPick.length) this.multiPick = data.myValue.slice()
				if (data.poll.type === 'number' && this.numInput === '') this.numInput = String(data.myValue)
				if (data.poll.type === 'text' && this.textInput === '' && typeof data.myValue === 'string') this.textInput = data.myValue
			}
			if (data.room) {
				this.mode = data.room.mode || 'poll'
				this.roomTitle = data.room.title || ''
			}
			if ('practice' in data) this.practice = !!data.practice
			this.serverSkew = (data.serverNow || Math.floor(Date.now() / 1000)) - Math.floor(Date.now() / 1000)
			// Quiz extras (keys only present in quiz mode)
			if ('nickname' in data) this.nickname = data.nickname
			this.myResult = data.myResult || null
			this.leaderboard = data.leaderboard || null
			this.leaderboardTotal = data.leaderboardTotal || 0
			this.leaderboardMe = data.leaderboardMe || null
			this.leaderboardAround = Array.isArray(data.leaderboardAround) ? data.leaderboardAround : []
			if (data.version !== undefined) this.version = data.version
			// Moderated: room.pace is missing -> '' (isPaced off).
			this.pace = (data.room && data.room.pace) || ''
		},

		// ── Adaptive polling loop ───────────────────────────────────────────
		// Recursive setTimeout instead of a fixed interval: fast while a question
		// is running, slower while waiting, paused while the tab is hidden.
		pollDelay() {
			// Self-paced: its own timing table (§2.10). There /state builds the full
			// state on every fetch, and a phone sits on it for the whole question.
			if (this.isPaced) {
				return document.hidden ? null : phoneDelay({
					isPaced: true,
					state: this.paceState,
					poll: this.poll,
					voted: this.voted,
					myResult: this.myResult,
					progress: this.progress,
					remaining: this.remaining,
				})
			}
			if (document.hidden) return null // pause -> onPollVisibility wakes it up
			if (this.poll) return 1300 // question visible -> fast
			return this.idleStreak >= 3 ? 5000 : 2500 // waiting room -> back off
		},
		async pollOnce() {
			// A response that overtook a submit (request started before the vote,
			// arrived after it) is stale: it would report "not voted yet"
			// and tear down the correction window and the selection.
			const seq = this.submitSeq
			try {
				const params = this.version ? { v: this.version } : {}
				// Self-paced with a timeout: a hanging request would otherwise stop the
				// loop (unchanged when moderated).
				const res = await axios.get(this.base('/state'), this.isPaced ? { params, timeout: PHONE_TIMEOUT } : { params })
				this.online = true
				if (seq !== this.submitSeq) return
				if (res.status === 204) { // unchanged
					this.idleStreak++
					return
				}
				this.applyState(res.data)
				this.idleStreak = 0
			} catch (e) {
				if (e?.response?.status === 404) {
					// Room gone -> stop polling for good. Otherwise the tab keeps hammering the
					// endpoint; every "not found" counts towards the brute-force
					// action 'pulseRoomCode' and in the end throttles the whole IP (429).
					this.roomExists = false
					this.pollStopped = true
				} else {
					this.online = false
				}
			}
		},
		/*
		 * Send a vote. `keyboard` only tells the server how long the
		 * correction window may be (§8.0): via keyboard or screen reader,
		 * the way back to the card is longer than with the thumb.
		 */
		async submit(value, keyboard = false) {
			this.busy = true
			this.submitSeq++
			const wasChanging = this.changing
			try {
				// pollId: if the moderator has moved on in the meantime, the server
				// rejects it instead of crediting the answer to the new question.
				// Self-paced with a timeout: a hanging vote would otherwise hold
				// `busy` — answers, "Next" and the time running out would be locked.
				const { data } = await axios.post(this.base('/vote'), { value, keyboard, pollId: this.poll ? this.poll.id : null }, this.isPaced ? { timeout: PHONE_TIMEOUT } : undefined)
				this.applyState(data)
				this.voted = true
				this.changing = false
				this.online = true
				if (this.isQuiz) {
					if (wasChanging) {
						// Exactly one correction — after that the answer is final.
						this.fixUsed = true
						this.fixUntil = 0
					} else {
						this.fixWindow = keyboard ? 6 : 3
						this.fixUntil = this.nowSec + this.fixWindow
					}
					this.hintShown = true
				}
			} catch (e) {
				showError(serverMessage(e, t('pulse', 'Your vote could not be saved.')))
				// Self-paced: a rejected correction (window shut, time up) does not leave
				// the correction mode open (§2.1 c) — the polling catches up.
				const status = e?.response?.status || 0
				if (this.isPaced && this.changing && status >= 400 && status < 500) this.paceEndChange()
				// Timeout/network: "Connection lost …", the polling fetches the state.
				if (this.isPaced && !e?.response) this.online = false
			} finally {
				this.submitSeq++
				this.busy = false
			}
		},
		/*
		 * Tap a single answer. In the quiz this submits immediately (§8.0); in
		 * a poll it only selects — there is no time pressure there, so
		 * no reason to risk a mistap either.
		 *
		 * `event.detail === 0` means: the click came from the keyboard (Enter or
		 * space bar), not from a pointing device.
		 */
		pickOne(id, event) {
			// paceHold: self-paced, the new question is only 0.6 s old (double tap on "Next").
			if (this.locked || this.busy || this.paceHold) return
			if (!this.isQuiz) {
				this.singlePick = id
				return
			}
			this.submit(id, !!event && event.detail === 0)
		},
		isPicked(id) {
			if (this.singlePick !== null) return this.singlePick === id
			return this.voted && this.myValue === id
		},
		// One submit path for all types with a button — there is one zone (§8.1).
		doSubmit() {
			if (this.busy || this.locked || this.paceHold || !this.submitReady) return
			switch (this.poll.type) {
			case 'choice':
			case 'truefalse':
				this.submit(this.singlePick); break
			case 'multi':
				this.submit(this.multiPick.slice()); break
			case 'number':
				this.submitNumber(); break
			case 'text':
				this.submitText(); break
			case 'rank':
				this.submitRank(); break
			case 'match':
				this.submitMatch(); break
			case 'words':
				this.submitWords(); break
			case 'scale':
				if (this.scaleMode === 'single') this.submit(this.scaleVal)
				else if (this.scaleMode === 'spectrum') this.submitSpectrum()
				else this.submitCompass()
				break
			}
		},
		// ── Picker sheet for matching (§8.3) ────────────────────────────────
		openSheet(itemId) {
			if (this.locked || this.busy || this.paceHold) return
			this.sheetFor = itemId
		},
		closeSheet() {
			this.sheetFor = null
		},
		chooseTarget(targetId) {
			if (!this.sheetFor) return
			// With 1:1 matching the choice swaps: whoever already had the target becomes free.
			for (const item of this.matchItems) {
				if (item.id !== this.sheetFor && this.matchPick[item.id] === targetId) {
					this.$set(this.matchPick, item.id, '')
				}
			}
			this.$set(this.matchPick, this.sheetFor, targetId)
			this.sheetFor = null
		},
		usedTarget(targetId, exceptItemId) {
			return this.matchItems.some((item) => item.id !== exceptItemId && this.matchPick[item.id] === targetId)
		},
		matchLabelOf(targetId) {
			const hit = this.matchTargets.find((target) => target.id === targetId)
			return hit ? hit.label : ''
		},
		matchLabelOfItem(itemId) {
			const hit = this.matchItems.find((item) => item.id === itemId)
			return hit ? hit.label : ''
		},
		matchPickPal(itemId) {
			const at = this.matchTargets.findIndex((target) => target.id === this.matchPick[itemId])
			return at < 0 ? '' : option(at).cls
		},
		optPal(i) {
			return option(i)
		},
		// Compass in words (§8.4): five levels instead of "PACE 0 · SCOPE 0".
		axisWord(axis) {
			const value = axis === 'x' ? this.compassX : this.compassY
			const ax = axis === 'x' ? this.poll.scale.axisX : this.poll.scale.axisY
			const range = this.compassRange || 1
			const share = value / range
			if (Math.abs(share) < 0.15) return this.t('pulse', 'neutral') + ' (0)'
			const pole = share > 0 ? (ax.poleHigh || '') : (ax.poleLow || '')
			const word = Math.abs(share) >= 0.6
				? this.t('pulse', 'strongly {pole}', { pole })
				: this.t('pulse', 'rather {pole}', { pole })
			return word + ' (' + this.signed(value) + ')'
		},
		submitWords() {
			const words = this.wordInputs.map((w) => (w || '').trim()).filter(Boolean)
			if (!words.length) return
			this.submit(words)
		},
		// Multiple choice: toggle an option (only final on submit).
		toggleMulti(id) {
			if (this.paceHold) return
			const at = this.multiPick.indexOf(id)
			if (at >= 0) this.multiPick.splice(at, 1)
			else this.multiPick.push(id)
		},
		submitNumber() {
			if (this.busy || this.numInput === '' || isNaN(Number(this.numInput))) return
			this.submit(Number(this.numInput))
		},
		submitText() {
			const t = this.textInput.trim()
			if (this.busy || !t) return
			this.submit(t)
		},
		onScaleInput(e) {
			// The first movement counts as an answer (before that null = not answered).
			this.scaleVal = Number(e.target.value)
		},
		// Spectrum: start values = neutral value; counts as a valid neutral answer.
		initSpectrum(poll) {
			const out = {}
			if (poll && poll.scale && poll.scale.mode === 'spectrum' && Array.isArray(poll.scale.aspects)) {
				const mid = Math.floor((Number(poll.scale.min) + Number(poll.scale.max)) / 2)
				for (const a of poll.scale.aspects) out[a.id] = mid
			}
			return out
		},
		// Bipolar fill: runs from the neutral mark to the thumb (not from 0).
		// That way the bar reads as "deviation from neutral", not as an amount.
		spectrumFill(id) {
			if (!this.poll || !this.poll.scale) return {}
			const { min, max } = this.poll.scale
			const v = this.spectrumVals[id]
			const vPct = max > min ? ((v - min) / (max - min)) * 100 : 0
			const n = this.neutralPct
			return { '--lo': Math.min(n, vPct) + '%', '--hi': Math.max(n, vPct) + '%' }
		},
		// Signed compass display (+2 / 0 / −2, a real minus sign).
		signed(n) {
			return n > 0 ? '+' + n : (n < 0 ? '−' + Math.abs(n) : '0')
		},
		submitSpectrum() {
			this.spectrumMine = { ...this.spectrumVals }
			this.submit(this.spectrumPayload)
		},
		onSpectrumInput(id, e) {
			this.$set(this.spectrumVals, id, Number(e.target.value))
		},
		aspectAria(asp) {
			const sc = this.poll.scale
			const lo = asp.poleLow ? ` (${asp.poleLow})` : ''
			const hi = asp.poleHigh ? ` (${asp.poleHigh})` : ''
			return this.t('pulse', '{aspect} – scale from {min}{low} to {max}{high}', { aspect: asp.label, min: sc.min, low: lo, max: sc.max, high: hi })
		},
		// ── Compass: 2D pad ↔ synchronised X/Y sliders ──────────────────────
		onPadDown(e) {
			this.padDown = true
			if (this.$refs.pad && this.$refs.pad.setPointerCapture) {
				try { this.$refs.pad.setPointerCapture(e.pointerId) } catch (err) { /* no pointer capture */ }
			}
			this.padFromClient(e)
		},
		onPadMove(e) {
			if (this.padDown) this.padFromClient(e)
		},
		onPadUp() {
			this.padDown = false
		},
		padFromClient(e) {
			const el = this.$refs.pad
			if (!el) return
			const r = el.getBoundingClientRect()
			if (!r.width || !r.height) return
			const px = (e.clientX - r.left) / r.width
			const py = (e.clientY - r.top) / r.height
			this.setCompassXY((px * 2 - 1) * this.compassRange, (1 - py * 2) * this.compassRange)
		},
		setCompassXY(nx, ny) {
			const R = this.compassRange
			this.compassX = Math.max(-R, Math.min(R, Math.round(nx)))
			this.compassY = Math.max(-R, Math.min(R, Math.round(ny)))
		},
		setCompassX(e) {
			this.setCompassXY(Number(e.target.value), this.compassY)
		},
		setCompassY(e) {
			this.setCompassXY(this.compassX, Number(e.target.value))
		},
		axisAria(which) {
			const sc = this.poll.scale
			const a = which === 'y' ? sc.axisY : sc.axisX
			return this.t('pulse', '{title}: {low} (−{range}) to {high} (+{range})', { title: a.title, low: a.poleLow, high: a.poleHigh, range: sc.range })
		},
		submitCompass() {
			this.compassMine = { x: this.compassX, y: this.compassY }
			this.submit({ x: this.compassX, y: this.compassY })
		},
		startChange() {
			this.changing = true
			this.singlePick = null
			if (this.poll && this.poll.type === 'words') {
				this.wordInputs = new Array(this.poll.maxWords).fill('')
			} else if (this.scaleMode === 'spectrum') {
				this.spectrumVals = this.initSpectrum(this.poll)
				this.spectrumMine = null
			} else if (this.scaleMode === 'compass') {
				this.compassX = 0
				this.compassY = 0
				this.compassMine = null
			}
		},

		// ── Joining the quiz ────────────────────────────────────────────────
		async join() {
			const name = this.nickInput.trim()
			if (!name || this.joining) return
			this.joining = true
			this.nickError = ''
			// Like submit: a /state started before joining (nickname: null)
			// would otherwise arrive afterwards — the name screen would flash back, and
			// in self-paced mode it would wrongly say "removed" (§2.1).
			this.submitSeq++
			try {
				// Self-paced with a timeout (otherwise `joining` would hang).
				const { data } = await axios.post(this.base('/join'), { nickname: name }, this.isPaced ? { timeout: PHONE_TIMEOUT } : undefined)
				this.applyState(data)
				// Self-paced: focus on the heading of the new card (§2.11).
				if (this.isPaced) this.paceFocusNext = true
			} catch (e) {
				this.nickError = serverMessage(e, t('pulse', 'Joining failed.'))
				if (this.isPaced && !e?.response) this.online = false
			} finally {
				this.submitSeq++
				this.joining = false
			}
		},

		// ── Code entry (join page) ──────────────────────────────────────────
		onDigit(i, e) {
			const v = (e.target.value || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(-1)
			e.target.value = v
			this.$set(this.digits, i, v)
			this.entryError = ''
			if (v && i < 5) {
				this.$refs.digits[i + 1].focus()
			}
			// §4.8: NO auto-submit after the 6th character — "Join" is the one action.
		},
		onDelete(i, e) {
			if (this.digits[i] === '' && i > 0) {
				e.preventDefault()
				this.$set(this.digits, i - 1, '')
				this.$refs.digits[i - 1].focus()
			}
		},
		onPaste(e) {
			e.preventDefault()
			const chars = (e.clipboardData.getData('text') || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 6).split('')
			for (let i = 0; i < 6; i++) this.$set(this.digits, i, chars[i] || '')
			const next = Math.min(chars.length, 5)
			this.$nextTick(() => { if (this.$refs.digits[next]) this.$refs.digits[next].focus() })
			// §4.8: do not submit automatically after pasting either — focus on "Join".
		},
		async go() {
			if (!this.entryValid || this.checking) return
			this.checking = true
			this.entryError = ''
			try {
				// Check existence before navigating -> show the error right here.
				await axios.get(publicApi(this.entryCode, '/state'))
				window.location.href = participantPage(this.entryCode)
			} catch (e) {
				this.entryError = t('pulse', 'That code does not exist.')
				this.checking = false
			}
		},
	},
}
</script>

<style scoped>
.pulse-part {
	/* Must fill the row flex container #content, otherwise the internal
	   justify-content:center does not apply and the card sticks to the left. */
	flex: 1 1 auto;
	width: 100%;
	min-width: 0;
	/* svh, not vh and not dvh (§8.1): vh ignores the browser bar, dvh
	   changes when the bar shows or hides and makes the layout jump. svh
	   is the conservative size — what fits with it always fits. */
	/* The Nextcloud header bar sits above the page and is not part of the
	   height budget (§8.1) — without the deduction the submit bar slides exactly
	   its own height below the fold. Nor is the margin Nextcloud leaves below
	   #content from 1024 px on: its last 8 px would lie under the clip edge. */
	min-height: calc(100vh - var(--header-height, 50px) - var(--body-container-margin, 0px));
	min-height: calc(100svh - var(--header-height, 50px) - var(--body-container-margin, 0px));
	background: var(--pulse-bg);
	color: var(--pulse-text);
	display: flex;
	justify-content: center;
	align-items: stretch;
	box-sizing: border-box;
	font-family: var(--font-face, system-ui, -apple-system, sans-serif);
	/* The scroll container of the phone page. Nextcloud's #content has a fixed
	   height and overflow: clip, so the page itself never scrolls: the overall
	   summary with ten questions was 5,000 px tall and stopped at the screen
	   edge. The answer view still scrolls inside .h-scroll — it fills exactly
	   this height and does not overflow it. Sideways nothing scrolls, as
	   before under #content (overflow-y: auto alone would make x auto too). */
	overflow-y: auto;
	overflow-x: hidden;
}
.sheet {
	width: 100%; max-width: 460px; min-height: 0;
	display: flex; flex-direction: column;
	padding: 16px 16px 0;
	box-sizing: border-box;
}

/* Answer view: the content scrolls, the submit bar never does. Sticky would be wrong —
   it only pins while scrolling and, with short content, slid directly below the
   cards instead of to the bottom edge (§8.1). */
.h-app { flex: 1 1 auto; min-height: 0; display: grid; grid-template-rows: minmax(0, 1fr) auto; }
.h-scroll { min-height: 0; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; }
.h-submit {
	padding: 16px 0;
	padding-bottom: max(16px, env(safe-area-inset-bottom, 0px));
	background: var(--pulse-bg);
	display: flex; flex-direction: column; gap: 8px;
}
.h-submit .submit-btn { margin: 0; min-height: 56px; }
.h-note { margin: 0; font-size: var(--t-sm); font-weight: 600; color: var(--pulse-text-2); text-align: center; }
.h-hint { margin: 0; font-size: var(--t-sm); font-weight: 600; color: var(--pulse-text-2); }
/* The image box needs a FIXED height: with max-height the image keeps its
   natural height and overflow:hidden clips it instead of shrinking it (§8.1). */
.h-img {
	flex: none; height: 26%; min-height: 96px;
	display: grid; place-items: center; overflow: hidden;
	padding: 6px; border: 1px solid var(--pulse-border); border-radius: var(--pulse-r-card);
	background: var(--pulse-image-mat);
	cursor: zoom-in;
}
.h-img.is-small { height: 16%; }
.h-img img { display: block; max-width: 100%; max-height: 100%; object-fit: contain; filter: var(--pulse-image-filter); }
/* Full-screen layer: close button outside the image, ≥ 48 px (§8.1). */
.h-zoom { position: fixed; inset: 0; z-index: 60; background: rgba(0, 0, 0, .88); display: grid; place-items: center; padding: 16px; }
.h-zoom img { max-width: 100%; max-height: 100%; object-fit: contain; }
.h-zoom-x {
	position: absolute; top: 12px; right: 12px; width: 48px; height: 48px;
	border-radius: 50%; border: 0; background: rgba(255, 255, 255, .16); color: #fff;
	display: grid; place-items: center; cursor: pointer;
}

.center { text-align: center; padding-top: 12vh; }
.muted { color: var(--pulse-text-2); }
.you { font-weight: 700; margin: 0 0 20px; }

/* Code entry (§4.6 — its own step, only the tokens migrated) */
.entry .eyebrow-p { color: var(--pulse-primary); letter-spacing: 0.18em; text-transform: uppercase; font-weight: 700; font-size: var(--t-sm); margin: 0 0 8px; }
.entry h1 { font-size: var(--t-h1); margin: 0 0 20px; }
/* §4.8: 6 boxes with a guaranteed minimum width ≥44px — even at 320px, without horizontal scrolling. */
.digits { display: flex; gap: clamp(4px, 1.8vw, 10px); justify-content: center; margin-bottom: 14px; }
.digit {
	flex: 1 1 44px;
	min-width: 44px;
	max-width: 58px;
	aspect-ratio: 3 / 4;
	border: 2px solid var(--pulse-border-strong);
	border-radius: var(--pulse-r-el);
	background: var(--pulse-bg);
	color: var(--pulse-text);
	font-size: 30px;
	font-weight: 800;
	text-align: center;
	text-transform: uppercase;
	box-sizing: border-box;
	font-variant-numeric: tabular-nums;
}
.digit:focus { outline: none; border-color: var(--pulse-primary); }
.digit:focus-visible { outline: 2px solid var(--pulse-primary); outline-offset: 2px; }
.entry-error { color: var(--pulse-error); font-size: var(--t-sm); margin: 0 0 12px; }
.entry .submit { width: 100%; }

/* §4.11 room not found: branded + one CTA (no dead end) */
.empty-part { padding-top: 12vh; }
.empty-part h1 { font-size: var(--t-h2); margin: 6px 0 8px; }
.empty-cta { display: inline-flex; margin-top: 18px; text-decoration: none; }

/* Nickname: visible counter up to 24 */
.nick-ico { color: var(--pulse-primary); margin: 0 0 6px; }
.nick-field { position: relative; display: block; margin-bottom: 14px; }
.nick-field .nick-input { margin-bottom: 0; }
.nick-count { position: absolute; right: 12px; bottom: 10px; font-size: var(--t-cap); color: var(--pulse-text-2); font-variant-numeric: tabular-nums; pointer-events: none; }

/* State icon (expired etc.) */
.state-ico { color: var(--pulse-text-2); margin: 0 0 8px; }

/* Quiz: choosing a name (§4.6 — its own step) */
.nick { padding-top: 8vh; }
.nick h1 { font-size: var(--t-h1); margin: 0 0 6px; }
.nick-hint { margin: 0 0 20px; font-size: var(--t-sm); }
.nick-input {
	width: 100%;
	border: 2px solid var(--pulse-border-strong);
	border-radius: var(--pulse-r-card);
	padding: 16px;
	font-size: var(--t-lead);
	font-weight: 600;
	text-align: center;
	background: var(--pulse-bg);
	color: var(--pulse-text);
	box-sizing: border-box;
	margin-bottom: 14px;
}
.nick-input:focus { outline: none; border-color: var(--pulse-primary); }
.nick .submit { width: 100%; }

/* Room name below the code — tells the person where they have landed. */
.room-title { font-size: 17px; font-weight: 700; line-height: 1.3; margin: 2px 0 6px; overflow-wrap: anywhere; }
.code-badge {
	display: inline-block;
	background: var(--pulse-primary); color: var(--pulse-on-primary);
	font-weight: 800; font-size: var(--t-lead); letter-spacing: 0.1em;
	padding: 8px 16px; border-radius: var(--pulse-r-pill); margin-bottom: 24px;
	font-variant-numeric: tabular-nums;
}
/* Waiting pulse */
.wait-dot {
	width: 14px; height: 14px; border-radius: 50%;
	background: var(--pulse-primary); margin: 0 auto 16px;
	animation: wait-pulse 1.4s ease-in-out infinite;
}
@keyframes wait-pulse {
	0%, 100% { transform: scale(0.8); opacity: 0.5; }
	50% { transform: scale(1.3); opacity: 1; }
}

.q { font-size: var(--t-h2); line-height: 1.25; margin: 0 0 24px; }

/* Practice-run chip (replaces .practice-tag) */
.practice-chip { margin: 0 0 14px; }

/* Countdown */
.timer { display: flex; align-items: center; gap: 12px; margin-bottom: 22px; }
.timer-track { flex: 1; height: 12px; border-radius: var(--pulse-r-pill); background: var(--pulse-fill); overflow: hidden; }
/* 1 s = tick interval -> the bar glides continuously instead of in one-second steps. */
.timer-fill { height: 100%; border-radius: var(--pulse-r-pill); background: var(--pulse-primary); transition: width 1s linear; }
.timer-num { font-size: var(--t-lead); font-weight: 800; font-variant-numeric: tabular-nums; min-width: 2ch; text-align: right; }
/* Urgency ≤ 5 s: not only colour — the number pulses as well. */
.timer.is-low .timer-fill { background: var(--pulse-error); }
.timer.is-low .timer-num { color: var(--pulse-error); animation: timer-pulse 1s ease-in-out infinite; }
@keyframes timer-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

/* Quiz reveal: verdict */
.verdict { display: flex; flex-direction: column; align-items: center; gap: 8px; margin-bottom: 24px; }
.verdict-mark { width: 72px; height: 72px; border-radius: 50%; display: grid; place-items: center; color: #fff; }
.verdict.is-ok .verdict-mark { background: var(--pulse-success); }
.verdict.is-no .verdict-mark { background: var(--pulse-error); }
.verdict-text { font-size: var(--t-h2); font-weight: 800; }
.verdict.is-ok .verdict-text { color: var(--pulse-success); }
.verdict.is-no .verdict-text { color: var(--pulse-error); }
.verdict-points { font-size: var(--t-body); margin-top: 2px; }
.verdict-sub { font-size: var(--t-sm); color: var(--pulse-text-2); }
.myrank { text-align: center; margin: 20px 0; font-size: 17px; }
.myrank b { color: var(--pulse-primary); font-variant-numeric: tabular-nums; }

/* ── Phone answer option (letter + colour A–H, selection = border + tick) ── */
/* Grid instead of a flex column: grid-auto-rows: 1fr makes all cards as tall
   as the tallest — otherwise only one jumps for a two-line label (§8.9). */
.choices { display: grid; grid-auto-rows: 1fr; gap: 10px; }
/* Poll: answer colour as a tint with a coloured border. Quiz: full surface.
   The difference is deliberate — eight full-colour cards would be noise in a
   poll; in the quiz they are what you grab (§8.2). */
.choice {
	appearance: none; -webkit-appearance: none;
	display: flex; align-items: center; gap: 12px;
	min-height: 64px; padding: 10px 12px;
	border: 1.5px solid color-mix(in oklab, var(--opt-fill, var(--pulse-border)), var(--pulse-bg) 70%);
	border-radius: var(--pulse-r-card);
	background: color-mix(in oklab, var(--opt-fill, var(--pulse-bg)), var(--pulse-bg) 92%);
	color: var(--pulse-text);
	box-shadow: none;
	font-size: 17px; font-weight: 600; line-height: 1.25; text-align: left;
	width: 100%; cursor: pointer;
	transition: transform 0.08s ease, box-shadow 0.12s ease;
}
.choice.is-quiz {
	background: var(--opt-fill, var(--pulse-primary));
	border-color: var(--opt-fill, var(--pulse-primary));
	color: var(--opt-ink, var(--pulse-on-primary));
	font-weight: 700;
}
.choice.is-dim { filter: saturate(.25); opacity: 0.72; }
.choice-badge { flex: 0 0 32px; width: 32px; height: 32px; border-radius: 8px; font-size: 15px; }
.choice.is-quiz .choice-badge { background: var(--opt-ink, var(--pulse-on-primary)); color: var(--opt-fill, var(--pulse-primary)); }
.choice-tick { margin-left: auto; flex: 0 0 auto; }
.choice-label { flex: 1; min-width: 0; }
.choice:hover:not(:disabled) { border-color: var(--pulse-primary); }
.choice:active:not(:disabled) { transform: translateY(2px); }
/* NC dims native buttons globally with `opacity: .5` — that would take the
   colour away from your own chosen card in the "sent" state. The OTHER cards
   (.is-dim) are the ones to dim, not your own. */
.choice:disabled { opacity: 1 !important; cursor: default; }
/* Ring on the INSIDE — required, not taste: overflow-y:auto forces overflow-x
   to auto as well, and the scroll container clips an outer
   shadow on all four edges (§8.2). */
.choice.is-picked { border-color: var(--pulse-primary); box-shadow: 0 0 0 2px var(--pulse-primary) inset; font-weight: 800; }

/* Estimation question / free text / word cloud: input field + submit */
.free-vote, .words { display: flex; flex-direction: column; gap: 14px; }
.pulse-input {
	appearance: none; -webkit-appearance: none;
	border: 2px solid var(--pulse-border-strong);
	border-radius: var(--pulse-r-card);
	background: var(--pulse-bg); color: var(--pulse-text);
	font-size: var(--t-body); font-weight: 600;
	padding: 16px 18px; width: 100%; box-sizing: border-box;
}
.pulse-input.is-lg { font-size: var(--t-lead); text-align: center; }
.pulse-input:focus { outline: none; border-color: var(--pulse-primary); }
.pulse-input::placeholder { color: var(--pulse-text-2); font-weight: 400; }
/* Word cloud input: one word per field + character counter (limit 40, what the server keeps) */
/* ── Ordering ─────────────────────────────────────────────────────────── */
.rank-field { display: flex; flex-direction: column; gap: 10px; }
.rank-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.rank-item {
	display: flex; align-items: center; gap: 10px; padding: 10px 12px;
	border: 2px solid var(--pulse-border); border-radius: var(--pulse-r-card, 14px);
	background: var(--pulse-bg);
}
.rank-pos {
	flex: 0 0 auto; min-width: 1.9em; height: 1.9em; display: inline-flex; align-items: center; justify-content: center;
	border-radius: var(--pulse-r-pill); background: var(--pulse-primary); color: var(--pulse-primary-text, #fff);
	font-weight: 800; font-variant-numeric: tabular-nums;
}
.rank-label { flex: 1; min-width: 0; font-size: 17px; font-weight: 600; overflow-wrap: anywhere; }
.rank-move { display: flex; gap: 6px; flex: 0 0 auto; }
.word-field { display: block; }
.word-count { display: block; margin-top: 4px; font-size: var(--t-cap); color: var(--pulse-text-2); text-align: right; }

/* ── Scale family: ONE value display, ONE slider building block (refinement §1) ──
   (Design notes, not in the public repository.)
   In all three modes the value is the receipt for the same gesture -> set the
   same everywhere: a tabular mono number above the input. The slider
   shares the track (9 px), thumb (28 px), focus and 44 px hit area; only the
   fill distinguishes the modes (unipolar / bipolar / none). */
.scale-field { display: flex; flex-direction: column; gap: 14px; }

.val-readout { display: flex; justify-content: center; align-items: baseline; gap: 5px; padding: 6px 0 2px; }
.val-num {
	font-family: var(--pulse-mono); font-weight: 800; font-size: 40px; line-height: 1;
	letter-spacing: -0.02em; color: var(--pulse-text); font-variant-numeric: tabular-nums;
}
.val-unit { font-family: var(--pulse-mono); font-weight: 700; font-size: var(--t-lead); color: var(--pulse-text-2); }
/* "Nothing chosen yet": grey dash, submit locked (see template). */
.val-readout.is-empty .val-num { color: var(--pulse-text-2); opacity: 0.55; }

.scale-range { -webkit-appearance: none; appearance: none; width: 100%; height: 44px; background: transparent; cursor: pointer; margin: 0; }
.scale-range:disabled { cursor: default; opacity: 0.6; }
.scale-range:focus-visible { outline: 2px solid var(--pulse-primary); outline-offset: 4px; border-radius: var(--pulse-r-el); }
.scale-range::-webkit-slider-runnable-track { height: 9px; border-radius: 999px; background: var(--pulse-fill); }
.scale-range::-moz-range-track { height: 9px; border-radius: 999px; background: var(--pulse-fill); }
.scale-range::-webkit-slider-thumb {
	-webkit-appearance: none; width: 28px; height: 28px; margin-top: -9.5px; border-radius: 50%;
	background: var(--thumb, var(--pulse-primary)); border: 3px solid var(--pulse-bg); box-shadow: 0 1px 5px rgba(0, 0, 0, 0.35);
}
.scale-range::-moz-range-thumb {
	width: 28px; height: 28px; border-radius: 50%; border: 3px solid var(--pulse-bg);
	background: var(--thumb, var(--pulse-primary)); box-shadow: 0 1px 5px rgba(0, 0, 0, 0.35);
}
/* Single: unipolar — fill 0 → thumb (reads as fill level/amount). */
.scale-range.is-uni::-webkit-slider-runnable-track {
	background: linear-gradient(90deg, var(--pulse-primary) var(--pct, 0%), var(--pulse-fill) var(--pct, 0%));
}
.scale-range.is-uni::-moz-range-progress { height: 9px; border-radius: 999px; background: var(--pulse-primary); }
/* Compass: thumb in the "You" colour — the same colour as the pad dot and readout. */
.scale-range.is-self { --thumb: var(--pulse-self); }

.scale-ends { display: flex; justify-content: space-between; font-size: var(--t-cap); color: var(--pulse-text-2); gap: 12px; }
.scale-ends span { max-width: 45%; }
.scale-ends span:last-child { text-align: right; }

/* Spectrum: BIPOLAR sliders — fill from the neutral mark to the thumb (§3.1).
   The mark sits on the neutral value, not flatly at 50 %. */
.spectrum-field { display: flex; flex-direction: column; gap: 18px; }
.aspect { display: flex; flex-direction: column; gap: 6px; }
.aspect-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; }
.aspect-name { font-weight: 700; font-size: var(--t-body); }
.aspect-val { font-family: var(--pulse-mono); font-weight: 800; color: var(--pulse-text); font-variant-numeric: tabular-nums; font-size: var(--t-body); }
.aspect-val.is-neutral { color: var(--pulse-text-2); }
.track-wrap { position: relative; }
/* The tick sits BELOW the slider: the opaque thumb covers it, the
   transparent slider surface lets the stubs above/below the track show through. */
.track-wrap .mid-tick {
	position: absolute; top: 50%; transform: translate(-50%, -50%);
	width: 2px; height: 20px; border-radius: 2px; background: var(--pulse-text-2);
	pointer-events: none; z-index: 0;
}
.track-wrap .scale-range { position: relative; z-index: 1; }
.scale-range.is-bip::-webkit-slider-runnable-track {
	background:
		linear-gradient(90deg,
			transparent 0 var(--lo, 50%),
			var(--pulse-primary) var(--lo, 50%) var(--hi, 50%),
			transparent var(--hi, 50%) 100%),
		var(--pulse-fill);
}
.scale-range.is-bip::-moz-range-track {
	background:
		linear-gradient(90deg,
			transparent 0 var(--lo, 50%),
			var(--pulse-primary) var(--lo, 50%) var(--hi, 50%),
			transparent var(--hi, 50%) 100%),
		var(--pulse-fill);
}

/* Compass: 2D pad + synchronised X/Y sliders; dot/thumb/readout in "You" magenta. */
.compass-field { display: flex; flex-direction: column; gap: 14px; }
.pad-wrap { display: flex; flex-direction: column; align-items: center; gap: 6px; }
/* All four pole labels horizontal (§8.4): top and bottom outside, left and
   right inside at the edge. Rotated in tiny type they were unreadable. */
.pad-pole { font-size: var(--t-sm); font-weight: 600; color: var(--pulse-meta); text-align: center; max-width: 18ch; }
.pad-side { position: absolute; top: 50%; transform: translateY(-50%); font-size: var(--t-cap); font-weight: 600; color: var(--pulse-meta); max-width: 8ch; }
.pad-side-l { left: 6px; }
.pad-side-r { right: 6px; text-align: right; }
.axwords { display: flex; flex-direction: column; gap: 2px; }
.axwords p { margin: 0; font-size: var(--t-body); font-weight: 600; }
.axwords-k { color: var(--pulse-meta); }
.pad { position: relative; width: 100%; max-width: 220px; aspect-ratio: 1 / 1; border: 1.5px solid var(--pulse-border-strong); border-radius: 12px; background: var(--pulse-fill); touch-action: none; cursor: crosshair; }
.pad-axis { position: absolute; background: var(--pulse-border-strong); }
.pad-axis-v { left: 50%; top: 0; width: 2px; height: 100%; transform: translateX(-1px); }
.pad-axis-h { top: 50%; left: 0; height: 2px; width: 100%; transform: translateY(-1px); }
.pad-thumb { position: absolute; width: 34px; height: 34px; border-radius: 50%; background: var(--pulse-self); border: 3px solid var(--pulse-bg); box-shadow: 0 2px 6px rgba(0, 0, 0, 0.35); transform: translate(-50%, -50%); }
.pad:focus-within { outline: 2px solid var(--pulse-primary); outline-offset: 3px; }
.axrow { display: flex; flex-direction: column; gap: 2px; }
.axrow-name { font-weight: 700; font-size: var(--t-sm); color: var(--pulse-text); }

/* Submit button (voting) + join submit */
.submit-btn { width: 100%; margin-top: 4px; }
.submit {
	appearance: none !important;
	border: none !important;
	border-radius: var(--pulse-r-pill) !important;
	box-shadow: none !important;
	background: var(--pulse-primary) !important;
	color: var(--pulse-on-primary) !important;
	font-size: var(--t-body); font-weight: 700;
	padding: 16px !important; min-height: 44px;
	cursor: pointer; margin: 4px 0 0 !important; height: auto !important;
}
.submit:hover:not(:disabled) { background: var(--pulse-primary-hover) !important; }
.submit:disabled { opacity: 0.45; }

.conn-off { text-align: center; font-size: var(--t-sm); color: var(--pulse-error); margin-top: 24px; }

/* ── Bands, correction window, picker sheet (§8.0/§8.3/§8.5/§8.7) ─────── */
.band { display: flex; align-items: center; gap: 8px; margin: 0; padding: 12px; border-radius: var(--pulse-r-el); font-weight: 700; }
.band.is-ok { background: var(--pulse-success-soft); color: var(--pulse-success); }
.band.is-mut { background: var(--pulse-fill); color: var(--pulse-text-2); }

.fix-bar {
	position: relative; overflow: hidden;
	display: flex; align-items: center; gap: 10px;
	padding: 12px 14px; border-radius: var(--pulse-r-el);
	background: var(--pulse-success-soft); color: var(--pulse-success);
	font-weight: 700;
}
.fix-btn {
	margin-left: auto; min-height: 44px; padding: 0 18px;
	border-radius: var(--pulse-r-pill); border: 1.5px solid currentColor;
	background: transparent; color: inherit; font-weight: 700; font-size: var(--t-body);
	cursor: pointer;
}
/* Hairline instead of a second number: a second countdown would compete with
   the question's (§8.0). */
.fix-line { position: absolute; left: 0; bottom: 0; height: 3px; background: currentColor; transition: width 0.5s linear; }

.mine-box { border: 1.5px solid var(--pulse-border); border-radius: var(--pulse-r-card); padding: 14px; background: var(--pulse-fill); }
.mine-k { display: block; font-size: var(--t-sm); font-weight: 600; color: var(--pulse-meta); }
/* A single word runs up to 40 characters, wider than the card at 26px: break it
   rather than push the card past the screen edge. */
.mine-v { display: block; font-size: 26px; font-weight: 700; line-height: 1.2; padding-block: 0.14em; overflow-wrap: anywhere; }
.mine-s { display: block; font-size: var(--t-body); font-weight: 600; color: var(--pulse-meta); }

.end-title { margin: 0; font-size: var(--t-h2); font-weight: 800; text-align: center; }
.big-place { text-align: center; padding-block: 8px; }
.big-place-n { display: block; font-size: 88px; font-weight: 800; line-height: 1; letter-spacing: -0.04em; padding-block: 0.06em; color: var(--pulse-primary); font-variant-numeric: tabular-nums; }
.big-place-of { display: block; font-size: 17px; font-weight: 600; color: var(--pulse-meta); }
.end-thanks { margin: 0; text-align: center; font-weight: 600; color: var(--pulse-text-2); }
.code-badge.is-inline { display: inline-block; margin-top: 8px; }

/* Matching: row with a target chip, the whole row is the hit area (§8.3) */
.mrow-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.mrow {
	display: flex; align-items: center; gap: 10px; width: 100%;
	min-height: 64px; padding: 10px 12px;
	border: 1.5px solid var(--pulse-border); border-radius: var(--pulse-r-card);
	background: var(--pulse-bg); color: var(--pulse-text);
	font-size: var(--t-body); font-weight: 600; text-align: left; cursor: pointer;
}
.mrow-cat { min-width: 0; padding-block: 0.14em; }
.mrow-chip {
	margin-left: auto; flex: 0 0 auto; min-height: 40px;
	display: inline-flex; align-items: center; padding: 8px 14px;
	border-radius: var(--pulse-r-pill); font-weight: 700;
	background: var(--pulse-fill); color: var(--pulse-text-2);
}
.mrow-chip.is-set { background: var(--opt-fill, var(--pulse-primary)); color: var(--opt-ink, var(--pulse-on-primary)); }

.scrim { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.34); z-index: 40; }
.msheet {
	position: fixed; inset: auto 0 0 0; z-index: 41;
	max-height: 80svh; overflow-y: auto;
	background: var(--pulse-bg); border-top: 1px solid var(--pulse-border);
	border-radius: 18px 18px 0 0; padding: 16px;
	padding-bottom: max(16px, env(safe-area-inset-bottom, 0px));
	box-shadow: 0 -12px 30px rgba(0, 0, 0, 0.22);
}
.msheet-h { margin: 0 0 12px; font-size: 17px; font-weight: 700; }
.msheet-opts { display: flex; flex-direction: column; gap: 8px; }
.msheet-opt {
	display: flex; align-items: center; gap: 10px; width: 100%;
	min-height: 56px; padding: 8px 12px;
	border: 1.5px solid color-mix(in oklab, var(--opt-fill, var(--pulse-border)), var(--pulse-bg) 70%);
	border-radius: var(--pulse-r-card);
	background: color-mix(in oklab, var(--opt-fill, var(--pulse-bg)), var(--pulse-bg) 90%);
	color: var(--pulse-text); font-size: var(--t-body); font-weight: 700;
	text-align: left; cursor: pointer;
}
/* Already taken: recognisable, but still selectable — the choice then swaps. */
.msheet-opt.is-used { filter: saturate(0.25); }
.msheet-opt small { margin-left: auto; font-size: var(--t-sm); font-weight: 600; color: var(--pulse-meta); }
.msheet-badge { flex: 0 0 32px; width: 32px; height: 32px; border-radius: 8px; font-size: 15px; background: var(--opt-fill, var(--pulse-primary)); color: var(--opt-ink, var(--pulse-on-primary)); }
.msheet-x { width: 100%; margin-top: 12px; }

/* Hit areas (§8.8): the ordering arrows measured 36 px. */
.rank-arrow { min-width: 44px; min-height: 44px; }

/* Narrow devices (§8.9): the padding shrinks, cards and type stay. */
@media (max-width: 359px) {
	.sheet { padding-inline: 12px; }
	.choice { padding: 10px; gap: 10px; }
}
/* Landscape (§8.9): question and image on the left, answers on the right; the
   submit bar stays at the bottom across the full width. */
@media (orientation: landscape) and (max-height: 500px) {
	.sheet { max-width: 820px; }
	.h-scroll { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; align-content: start; }
	.h-img { height: auto; max-height: 40svh; }
	.h-scroll > .choices, .h-scroll > .rank-field, .h-scroll > .match-field,
	.h-scroll > .free-vote, .h-scroll > .words, .h-scroll > .scale-field,
	.h-scroll > .spectrum-field, .h-scroll > .compass-field { grid-column: 2; grid-row: 1 / span 4; }
}

@media (prefers-reduced-motion: reduce) {
	.fix-line { transition: none; }
	.wait-dot { animation: none; }
	.timer.is-low .timer-num { animation: none; }
	.choice { transition: none; }
	.scale-range.is-uni::-moz-range-progress { transition: none; }
	.timer-fill { transition: none; }
}

/* ── Overall summary ("All results") ────────────────────────────────────── */
/* Room below "Back": the end of the scroll range is the end of the summary. */
.review { display: flex; flex-direction: column; gap: 14px; padding-bottom: 16px; }
.review-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.review-h { margin: 0; font-size: 22px; }
.review-title { margin: 0; font-size: 15px; color: var(--pulse-text-2); overflow-wrap: anywhere; }
.review-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 22px; }
.review-item { display: flex; flex-direction: column; gap: 8px; }
.review-q { display: flex; gap: 8px; align-items: baseline; margin: 0; font-size: 17px; line-height: 1.3; }
/* A long word in the question wraps instead of widening the summary. */
.review-q-text { min-width: 0; overflow-wrap: anywhere; }
.review-num {
	flex: 0 0 auto; min-width: 1.6em; height: 1.6em; display: inline-flex; align-items: center;
	justify-content: center; border-radius: var(--pulse-r-pill, 999px); background: var(--pulse-fill);
	color: var(--pulse-text-2); font-size: 0.8em; font-weight: 800;
}
.review-mine { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 0; font-size: 15px; }
.review-mine-lbl { color: var(--pulse-text-2); }
/* A flex item keeps its longest word whole unless told otherwise: a 40-character
   word or a free-text answer without spaces would run past the screen edge. */
.review-mine b { overflow-wrap: anywhere; }
.review-mine.is-ok b { color: var(--pulse-success); }
.review-mine.is-no b { color: var(--pulse-error); }
.review-mine.is-none { font-style: italic; }
.review-hidden { margin: 0; font-size: 14px; }
.review-myrank { margin: 0; }
.review-cta { align-self: center; margin-top: 18px; }
.review-back { align-self: center; margin-top: 4px; }

/* ── Self-paced: cards without a question and the name screen (§2.2–§2.7) ── */
.pace-card { padding-top: 8vh; }
.pace-card .submit { width: 100%; }
.pace-practice { margin: 0; }
.pace-you { font-size: var(--t-lead); line-height: 1.3; margin: 0 0 16px; }
.pace-h { font-size: var(--t-h2); line-height: 1.25; margin: 0 0 12px; }
.pace-line { margin: 0 0 12px; }
.pace-flag { color: var(--pulse-primary); margin: 0 0 8px; }
/* Below the room name the state icon needs room (card "closed, without a name"). */
.pace-card .room-title + .state-ico { margin-top: 12px; }
.pace-facts { margin: 0 0 12px; font-size: var(--t-sm); font-weight: 600; color: var(--pulse-text-2); }
/* Deadline: icon, absolute time, in the last quarter of an hour the chip. */
.pace-when {
	display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 6px 8px;
	margin: 0 0 16px; font-size: var(--t-sm); font-weight: 600;
}
.pace-hints {
	list-style: none; margin: 0 0 20px; padding: 0;
	display: flex; flex-direction: column; gap: 6px; font-size: var(--t-sm);
}
.pace-score { margin: 0 0 12px; font-size: var(--t-h2); font-weight: 800; color: var(--pulse-primary); font-variant-numeric: tabular-nums; }
/* Notice box left-aligned — centred body text in a box reads badly. */
.pace-notice { text-align: left; margin: 0 0 16px; }
.pace-device { margin: 16px 0 0; font-size: var(--t-sm); }
.pace-momentum { margin: 0; font-size: var(--t-sm); }
.pace-momentum b { color: var(--pulse-text); font-variant-numeric: tabular-nums; }

/* ── Self-paced: answer zone (§2.5, §2.6, §2.11) ── */
/* "Question k of n · Open until …", in the last quarter of an hour with a chip. */
.pace-pos {
	display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px;
	margin: 0; font-size: var(--t-sm); font-weight: 600; color: var(--pulse-text-2);
	font-variant-numeric: tabular-nums;
}
.center .pace-pos { justify-content: center; margin: 0 0 12px; }
/* Skip: small and quiet (is-sm, is-tertiary), but the hit area is
   44 px like every phone button (§8.8). */
.pace-skip { align-self: center; min-height: 44px; margin-top: 4px; }
/* Verdict "wrong": its own surface, not just red text (never colour alone). */
.band.is-no { background: var(--pulse-error-soft); color: var(--pulse-error); }
.pace-points { margin-left: auto; }
.pace-timeup .submit { width: 100%; }
/* Programmatic focus on the heading (§2.11): no ring around a title. */
h1[tabindex="-1"]:focus { outline: none; }
/* Screen readers only. */
.pace-sr { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
@media (orientation: landscape) and (max-height: 500px) {
	.h-scroll > .pace-skip { grid-column: 2; }
}
</style>
