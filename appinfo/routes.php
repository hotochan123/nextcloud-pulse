<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

return [
    'routes' => [
        // ── Moderator SPA (login required) ──────────────────────────────────
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        // map history-mode deep links of the moderator SPA to the same entry point
        ['name' => 'page#index', 'url' => '/room/{code}', 'verb' => 'GET',
            'requirements' => ['code' => '[A-Za-z0-9]{6}'], 'postfix' => 'room'],

        // ── Moderator API (login required) ──────────────────────────────────
        ['name' => 'roomApi#index',      'url' => '/api/1.0/rooms',                       'verb' => 'GET'],
        ['name' => 'roomApi#create',     'url' => '/api/1.0/rooms',                       'verb' => 'POST'],
        ['name' => 'roomApi#summary',    'url' => '/api/1.0/rooms/{code}/summary',        'verb' => 'GET', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#leaderboard', 'url' => '/api/1.0/rooms/{code}/leaderboard',   'verb' => 'GET', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#exportCsv',  'url' => '/api/1.0/rooms/{code}/export',         'verb' => 'GET', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#show',       'url' => '/api/1.0/rooms/{code}',                'verb' => 'GET',    'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#destroy',    'url' => '/api/1.0/rooms/{code}',                'verb' => 'DELETE', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#rename',     'url' => '/api/1.0/rooms/{code}/title',          'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#duplicate',  'url' => '/api/1.0/rooms/{code}/duplicate',      'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        // Empty the room (votes+participants+leaderboard), switch the practice run on/off
        ['name' => 'roomApi#resetRoom',  'url' => '/api/1.0/rooms/{code}/reset',          'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#practice',   'url' => '/api/1.0/rooms/{code}/practice',       'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        // Quiz: toggle reveal-at-the-end, end the quiz (reveal the last question)
        ['name' => 'roomApi#reveal',     'url' => '/api/1.0/rooms/{code}/reveal',         'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#endQuiz',    'url' => '/api/1.0/rooms/{code}/end',            'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        // Deck: append a question, set the cursor, delete a question
        ['name' => 'roomApi#addPoll',    'url' => '/api/1.0/rooms/{code}/polls',          'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#reorder',    'url' => '/api/1.0/rooms/{code}/deck/order',     'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#setCurrent', 'url' => '/api/1.0/rooms/{code}/current',        'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#updatePoll', 'url' => '/api/1.0/rooms/{code}/polls/{pollId}',         'verb' => 'PUT',    'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        ['name' => 'roomApi#deletePoll', 'url' => '/api/1.0/rooms/{code}/polls/{pollId}',         'verb' => 'DELETE', 'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        ['name' => 'roomApi#results',    'url' => '/api/1.0/rooms/{code}/polls/{pollId}/results', 'verb' => 'GET',  'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        ['name' => 'roomApi#resetPoll',  'url' => '/api/1.0/rooms/{code}/polls/{pollId}/reset',   'verb' => 'POST', 'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        // Question image: upload, remove, (moderator) preview
        ['name' => 'roomApi#uploadImage','url' => '/api/1.0/rooms/{code}/polls/{pollId}/image', 'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        ['name' => 'roomApi#deleteImage','url' => '/api/1.0/rooms/{code}/polls/{pollId}/image', 'verb' => 'DELETE', 'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        ['name' => 'roomApi#showImage',  'url' => '/api/1.0/rooms/{code}/polls/{pollId}/image', 'verb' => 'GET',    'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        ['name' => 'roomApi#gradeAnswer','url' => '/api/1.0/rooms/{code}/polls/{pollId}/grade',   'verb' => 'POST', 'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        ['name' => 'roomApi#lockPoll',  'url' => '/api/1.0/rooms/{code}/polls/{pollId}/lock',   'verb' => 'POST', 'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        ['name' => 'roomApi#unlockPoll','url' => '/api/1.0/rooms/{code}/polls/{pollId}/unlock', 'verb' => 'POST', 'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        // Demo/test mode: fill the active question with synthetic votes / empty it again
        ['name' => 'roomApi#demoSeed',  'url' => '/api/1.0/rooms/{code}/demo', 'verb' => 'POST',   'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'roomApi#demoClear', 'url' => '/api/1.0/rooms/{code}/demo', 'verb' => 'DELETE', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        // Self-paced quiz: switch the pace, open/close/extend/release the window,
        // lock joining, remove a person
        ['name' => 'roomApi#pace',      'url' => '/api/1.0/rooms/{code}/pace',     'verb' => 'POST', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        // Progress per person/question (race/homework), polled adaptively with ?v=
        ['name' => 'roomApi#progress',  'url' => '/api/1.0/rooms/{code}/progress', 'verb' => 'GET',  'requirements' => ['code' => '[A-Za-z0-9]{6}']],

        // ── Join page (public): type in the code ────────────────────────────
        ['name' => 'public#join', 'url' => '/join', 'verb' => 'GET'],

        // ── Participant page (public, no account) ───────────────────────────
        // The room code IS the share token, like /s/{token} for share links.
        ['name' => 'public#show', 'url' => '/s/{code}', 'verb' => 'GET', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],

        // ── Projector/audience view (public): big screen for the projection ──
        ['name' => 'public#screen', 'url' => '/screen/{code}', 'verb' => 'GET', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],

        // ── Embed shell for the Office/PowerPoint add-in (public, content add-in) ──
        ['name' => 'public#embed', 'url' => '/embed', 'verb' => 'GET'],

        // ── Office manifest of the add-in, filled with the addresses of THIS instance ──
        // An Office manifest knows no variables; that is why the server generates
        // it instead of shipping it as a template to be patched by hand.
        ['name' => 'addin#manifest', 'url' => '/addin/manifest.xml', 'verb' => 'GET'],

        // ── Participant API (public; #[PublicPage]/#[NoCSRFRequired] on the controller) ──
        ['name' => 'publicVote#state', 'url' => '/s/{code}/state', 'verb' => 'GET',  'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        // Overall summary for the phone (all questions), only at the push of a button
        ['name' => 'publicVote#summary', 'url' => '/s/{code}/summary', 'verb' => 'GET', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        // Serve a question's image publicly (only the running/revealed question)
        ['name' => 'publicVote#image', 'url' => '/s/{code}/polls/{pollId}/image', 'verb' => 'GET', 'requirements' => ['code' => '[A-Za-z0-9]{6}', 'pollId' => '\d+']],
        ['name' => 'publicVote#join',  'url' => '/s/{code}/join',  'verb' => 'POST', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        ['name' => 'publicVote#vote',  'url' => '/s/{code}/vote',  'verb' => 'POST', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
        // Self-paced: fetch the next question (the only place where a clock starts)
        ['name' => 'publicVote#next',  'url' => '/s/{code}/next',  'verb' => 'POST', 'requirements' => ['code' => '[A-Za-z0-9]{6}']],
    ],
];
