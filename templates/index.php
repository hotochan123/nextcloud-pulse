<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

/** Moderator-Oberfläche (angemeldete Nutzer). */
\OCP\Util::addScript('pulse', 'pulse-main');
// CSS wird vom JS-Bundle injiziert (vue-style-loader) — kein separates css/.
?>
<div id="pulse-app"></div>
