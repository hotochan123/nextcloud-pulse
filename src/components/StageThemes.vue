<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<ol class="stage-themes" :data-count="themes.length">
		<li v-for="(th, i) in themes" :key="i" class="st-card">
			<span class="st-count">{{ th.count }} <span class="st-count-unit">{{ n('pulse', 'answer', 'answers', th.count) }}</span></span>
			<span class="st-title">{{ th.title }}</span>
			<span class="st-example">“{{ th.example }}”</span>
		</li>
	</ol>
</template>

<script>
// Projector: the themes the moderator released after "Group with AI" (up to
// six cards: title, how many answers, one real answer as example). Plain
// text only; long titles and examples are clamped so nothing runs past the stage.
export default {
	name: 'StageThemes',
	props: {
		// poll.themes of the public state: [{ title, count, example }]
		themes: { type: Array, required: true },
	},
}
</script>

<style scoped>
.stage-themes {
	list-style: none; margin: 0; padding: 0;
	flex: 1 1 auto; min-height: 0; width: 100%;
	display: grid; gap: 0.6em;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	/* rows as tall as their cards: a card never clips a line in half; when six
	   do not fit, the projector shrinks its whole stage (Screen.vue fitStage) */
	grid-auto-rows: auto; align-content: start;
}
.stage-themes[data-count="1"] { grid-template-columns: minmax(0, 1fr); }
.stage-themes[data-count="2"], .stage-themes[data-count="4"] { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.st-card {
	display: flex; flex-direction: column; gap: 0.25em;
	padding: 0.6em 0.8em; border-radius: var(--pulse-r-card);
	background: var(--pulse-bar-track);
	border: 2px solid var(--pulse-border);
}
.st-count, .st-title, .st-example { flex: none; }
.st-count { font-size: 1.6em; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; color: var(--pulse-viz); }
.st-count-unit { font-size: 0.5em; font-weight: 600; color: var(--pulse-text-2); }
/* The line height of the font's own glyph height (≈ 1.36 em): the box clips
   for its line clamp, and at 1.2 the descenders of the last line lost a pixel
   on a large screen (3840 wide). */
.st-title {
	font-size: 1.15em; font-weight: 700; line-height: 1.36; overflow-wrap: anywhere;
	display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.st-example {
	font-size: 0.85em; line-height: 1.3; color: var(--pulse-text-2); overflow-wrap: anywhere;
	display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;
}
</style>
