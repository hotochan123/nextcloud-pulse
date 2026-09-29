<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

/** Moderator interface (signed-in users). */
\OCP\Util::addScript('pulse', 'pulse-main');
// CSS is injected by the JS bundle (vue-style-loader), so there is no separate css/.
?>
<div id="pulse-app"></div>
