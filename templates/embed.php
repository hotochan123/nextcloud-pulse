<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Einbett-Shell fürs PowerPoint-/Office-Add-in.
 * Lädt (per pulse-embed.js) office.js nach, fragt einmalig den Raumcode ab
 * (persistiert in den Dokument-Settings der .pptx) und rahmt dann die
 * Beamer-Ansicht /apps/pulse/screen/{code} same-origin ein.
 * Rendert bewusst „blank" (keine NC-Chrome) — die Folie soll nur die Umfrage zeigen.
 */
// Übersetzungen der App (OC.L10N.register) — die Shell ist englischsprachig
// und übersetzt über denselben Weg wie das Bundle.
\OCP\Util::addTranslations(\OCA\Pulse\AppInfo\Application::APP_ID);
// Token-Schicht + Design-System (eigener Stil-Einstieg, kein Vue-Bundle):
// die Shell nutzt dieselben Rollen und denselben .pulse-btn wie die App.
\OCP\Util::addScript('pulse', 'pulse-styles');
\OCP\Util::addStyle('pulse', 'embed');
\OCP\Util::addScript('pulse', 'pulse-embed');
?>
<div id="pulse-embed-root" class="pulse-embed-loading">Pulse …</div>
