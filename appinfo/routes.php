<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

return [
    'routes' => [
        // ── Moderator-SPA (Login erforderlich) ──────────────────────────────
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        // history-mode Deep-Links der Moderator-SPA auf denselben Einstieg mappen
        ['name' => 'page#index', 'url' => '/room/{code}', 'verb' => 'GET',
            'requirements' => ['code' => '[A-Za-z0-9]{6}'], 'postfix' => 'room'],

        // ── Moderator-API (Login erforderlich) ──────────────────────────────
        ['name' => 'roomApi#index',      'url' => '/api/1.0/rooms',                       'verb' => 'GET'],
        ['name' => 'roomApi#create',     'url' => '/api/1.0/rooms',                       'verb' => 'POST'],
        ['name' => 'roomApi#summary',    'url' => '/api/1.0/rooms/{code}/summary',        'verb' => 'GET', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#leaderboard', 'url' => '/api/1.0/rooms/{code}/leaderboard',   'verb' => 'GET', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#exportCsv',  'url' => '/api/1.0/rooms/{code}/export',         'verb' => 'GET', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#show',       'url' => '/api/1.0/rooms/{code}',                'verb' => 'GET',    'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#destroy',    'url' => '/api/1.0/rooms/{code}',                'verb' => 'DELETE', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#rename',     'url' => '/api/1.0/rooms/{code}/title',          'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#duplicate',  'url' => '/api/1.0/rooms/{code}/duplicate',      'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        // Raum leeren (Stimmen+Teilnehmer+Rangliste), Probelauf ein-/ausschalten
        ['name' => 'roomApi#resetRoom',  'url' => '/api/1.0/rooms/{code}/reset',          'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#practice',   'url' => '/api/1.0/rooms/{code}/practice',       'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        // Quiz: Auflösung-am-Ende umschalten, Quiz beenden (letzte Frage auflösen)
        ['name' => 'roomApi#reveal',     'url' => '/api/1.0/rooms/{code}/reveal',         'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#endQuiz',    'url' => '/api/1.0/rooms/{code}/end',            'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        // Deck: Frage anhängen, Cursor setzen, Frage löschen
        ['name' => 'roomApi#addPoll',    'url' => '/api/1.0/rooms/{code}/polls',          'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#reorder',    'url' => '/api/1.0/rooms/{code}/deck/order',     'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#setCurrent', 'url' => '/api/1.0/rooms/{code}/current',        'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#updatePoll', 'url' => '/api/1.0/rooms/{code}/polls/{pollId}',         'verb' => 'PUT',    'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        ['name' => 'roomApi#deletePoll', 'url' => '/api/1.0/rooms/{code}/polls/{pollId}',         'verb' => 'DELETE', 'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        ['name' => 'roomApi#results',    'url' => '/api/1.0/rooms/{code}/polls/{pollId}/results', 'verb' => 'GET',  'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        ['name' => 'roomApi#resetPoll',  'url' => '/api/1.0/rooms/{code}/polls/{pollId}/reset',   'verb' => 'POST', 'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        // Bild zur Frage: hochladen, entfernen, (Moderator-)Vorschau
        ['name' => 'roomApi#uploadImage','url' => '/api/1.0/rooms/{code}/polls/{pollId}/image', 'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        ['name' => 'roomApi#deleteImage','url' => '/api/1.0/rooms/{code}/polls/{pollId}/image', 'verb' => 'DELETE', 'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        ['name' => 'roomApi#showImage',  'url' => '/api/1.0/rooms/{code}/polls/{pollId}/image', 'verb' => 'GET',    'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        ['name' => 'roomApi#gradeAnswer','url' => '/api/1.0/rooms/{code}/polls/{pollId}/grade',   'verb' => 'POST', 'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        ['name' => 'roomApi#lockPoll',  'url' => '/api/1.0/rooms/{code}/polls/{pollId}/lock',   'verb' => 'POST', 'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        ['name' => 'roomApi#unlockPoll','url' => '/api/1.0/rooms/{code}/polls/{pollId}/unlock', 'verb' => 'POST', 'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        // Demo-/Testmodus: aktive Frage mit synthetischen Stimmen befüllen / wieder leeren
        ['name' => 'roomApi#demoSeed',  'url' => '/api/1.0/rooms/{code}/demo', 'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#demoClear', 'url' => '/api/1.0/rooms/{code}/demo', 'verb' => 'DELETE', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        // Quiz im eigenen Tempo: Tempo umschalten, Fenster öffnen/schließen/verlängern/freigeben,
        // Beitritt sperren, Person entfernen
        ['name' => 'roomApi#pace',      'url' => '/api/1.0/rooms/{code}/pace',     'verb' => 'POST', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        // Fortschritt je Person/Frage (Rennen/Hausaufgabe), adaptiv gepollt mit ?v=
        ['name' => 'roomApi#progress',  'url' => '/api/1.0/rooms/{code}/progress', 'verb' => 'GET',  'requirements' => ['code' => '[A-Za-z0-9]{6}']],

        // ── Beitritts-Seite (öffentlich): Code eintippen ────────────────────
        ['name' => 'public#join', 'url' => '/join', 'verb' => 'GET'],

        // ── Teilnehmer-Seite (öffentlich, ohne Account) ─────────────────────
        // Der Raumcode IST das Freigabe-Token, wie /s/{token} bei Freigabe-Links.
        ['name' => 'public#show', 'url' => '/s/{code}', 'verb' => 'GET', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],

        // ── Beamer-/Publikumsansicht (öffentlich): Großbild für die Projektion ──
        ['name' => 'public#screen', 'url' => '/screen/{code}', 'verb' => 'GET', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],

        // ── Einbett-Shell fürs Office-/PowerPoint-Add-in (öffentlich, Content-Add-in) ──
        ['name' => 'public#embed', 'url' => '/embed', 'verb' => 'GET'],

        // ── Office-Manifest des Add-ins, mit den Adressen DIESER Instanz gefüllt ──
        // Ein Office-Manifest kennt keine Variablen; deshalb erzeugt es der
        // Server, statt es als Vorlage zum Nachbessern auszuliefern.
        ['name' => 'addin#manifest', 'url' => '/addin/manifest.xml', 'verb' => 'GET'],

        // ── Teilnehmer-API (öffentlich; #[PublicPage]/#[NoCSRFRequired] am Controller) ──
        ['name' => 'publicVote#state', 'url' => '/s/{code}/state', 'verb' => 'GET',  'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        // Gesamtauswertung fürs Handy (alle Fragen), nur auf Knopfdruck
        ['name' => 'publicVote#summary', 'url' => '/s/{code}/summary', 'verb' => 'GET', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        // Bild einer Frage öffentlich ausliefern (nur laufende/aufgelöste Frage)
        ['name' => 'publicVote#image', 'url' => '/s/{code}/polls/{pollId}/image', 'verb' => 'GET', 'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        ['name' => 'publicVote#join',  'url' => '/s/{code}/join',  'verb' => 'POST', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'publicVote#vote',  'url' => '/s/{code}/vote',  'verb' => 'POST', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        // Eigenes Tempo: nächste Frage holen (einzige Stelle, an der eine Uhr startet)
        ['name' => 'publicVote#next',  'url' => '/s/{code}/next',  'verb' => 'POST', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
    ],
];
