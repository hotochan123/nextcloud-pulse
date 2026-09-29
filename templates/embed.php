<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Embed shell for the PowerPoint/Office add-in.
 * Loads office.js (via pulse-embed.js), asks for the room code once
 * (persisted in the document settings of the .pptx) and then frames the
 * projector view /apps/pulse/screen/{code} same-origin.
 * Deliberately renders "blank" (no NC chrome) — the slide should show only the poll.
 */
// The app's translations (OC.L10N.register) — the shell is in English
// and is translated the same way as the bundle.
\OCP\Util::addTranslations(\OCA\Pulse\AppInfo\Application::APP_ID);
// Token layer + design system (own style entry point, no Vue bundle):
// the shell uses the same roles and the same .pulse-btn as the app.
\OCP\Util::addScript('pulse', 'pulse-styles');
\OCP\Util::addStyle('pulse', 'embed');
\OCP\Util::addScript('pulse', 'pulse-embed');
?>
<div id="pulse-embed-root" class="pulse-embed-loading">Pulse …</div>
