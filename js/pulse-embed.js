/**
 * Pulse — embed shell for the Office/PowerPoint add-in.
 *
 * Hand-written asset (NOT a webpack bundle) — NC loads it via
 * \OCP\Util::addScript with a CSP nonce. Because the NC CSP uses
 * 'strict-dynamic', scripts loaded dynamically by this (nonced) script
 * may run — that is how we fetch office.js from Microsoft's CDN.
 *
 * Two modes:
 *   - In the Office webview: the room code is stored in Office.context.document.settings
 *     (it stays in the .pptx).
 *   - In a normal browser (for testing): falls back to ?code=… or localStorage
 *     after a short timeout if no Office host answers.
 */
(function () {
    'use strict';

    /**
     * Translation takes the same path as the bundle: the source strings are
     * English in the code, OC.L10N supplies the interface language. If the
     * core helper is missing, the English source string stays — never an empty
     * string.
     */
    function tr(text, vars) {
        try {
            if (typeof window.t === 'function') { return window.t('pulse', text, vars); }
            if (window.OC && window.OC.L10N && window.OC.L10N.translate) { return window.OC.L10N.translate('pulse', text, vars); }
        } catch (e) { /* source string as fallback */ }
        return text;
    }

    var ORIGIN = window.location.origin; // https://nextcloud.example.com
    var root = document.getElementById('pulse-embed-root');
    var settingsApi = null; // Office.context.document.settings, once available

    function screenUrl(code) {
        return ORIGIN + '/apps/pulse/screen/' + encodeURIComponent(code);
    }

    function persist(code) {
        try { window.localStorage.setItem('pulseCode', code); } catch (e) { /* ignore */ }
        if (settingsApi) {
            try {
                settingsApi.set('pulseCode', code);
                settingsApi.saveAsync(function () {});
            } catch (e) { /* ignore */ }
        }
    }

    function loadSaved() {
        if (settingsApi) {
            var c = settingsApi.get('pulseCode');
            if (c) { return c; }
        }
        var q = null;
        try { q = new URLSearchParams(window.location.search).get('code'); } catch (e) { q = null; }
        if (q) { return q; }
        try { return window.localStorage.getItem('pulseCode') || ''; } catch (e) { return ''; }
    }

    function renderIframe(code) {
        root.className = '';
        root.innerHTML = '';

        var frame = document.createElement('iframe');
        frame.className = 'pulse-embed-frame';
        frame.src = screenUrl(code);
        frame.setAttribute('allowfullscreen', '');
        frame.setAttribute('title', tr('Pulse live view'));

        // Thin bar, shown on hover, for changing the code — it does not get in
        // the way of the projection but is reachable while setting up.
        var bar = document.createElement('div');
        bar.className = 'pulse-embed-bar';
        var label = document.createElement('span');
        label.className = 'pulse-embed-code';
        label.textContent = tr('Room {code}', { code: code });
        var change = document.createElement('button');
        change.type = 'button';
        change.className = 'pulse-btn is-secondary is-sm pulse-embed-change';
        change.textContent = tr('Change code');
        change.addEventListener('click', function () { renderForm(code); });
        bar.appendChild(label);
        bar.appendChild(change);

        root.appendChild(frame);
        root.appendChild(bar);
    }

    /**
     * Check the room before the canvas appears: the public state answers
     * with 404 if the code does not exist. Without this check the slide
     * would show an iframe saying "This room does not exist." — the message
     * belongs at the field where the mistake was made (design notes §9.7, not
     * in the public repository).
     */
    function roomExists(code) {
        return fetch(ORIGIN + '/apps/pulse/s/' + encodeURIComponent(code) + '/state?spectate=1', {
            headers: { Accept: 'application/json' },
        }).then(function (r) { return r.ok }).catch(function () {
            return null; // no network: no verdict, we let it through
        })
    }

    function renderForm(prefill) {
        root.className = '';
        root.innerHTML = '';

        var wrap = document.createElement('div');
        wrap.className = 'pulse-embed-setup';

        // Wordmark: the shell sits in other people's slides and has to say what it
        // is — before, there was just a bare input field.
        var mark = document.createElement('p');
        mark.className = 'pulse-embed-mark';
        mark.innerHTML = '<span class="pulse-embed-dot"></span>Pulse';

        var p = document.createElement('p');
        p.className = 'pulse-embed-lede';
        p.textContent = tr('Enter the room code to embed the live view into this slide.');

        var label = document.createElement('label');
        label.className = 'pulse-embed-label';
        label.id = 'pulse-embed-label';
        label.textContent = tr('Room code');

        // Six cells instead of one field: the code IS six characters long, and
        // pasting from the clipboard spreads across the cells.
        var cells = document.createElement('div');
        cells.className = 'pulse-embed-cells';
        cells.setAttribute('role', 'group');
        cells.setAttribute('aria-labelledby', 'pulse-embed-label');

        var err = document.createElement('p');
        err.className = 'pulse-embed-err';
        err.id = 'pulse-embed-err';
        err.setAttribute('role', 'alert');
        err.hidden = true;

        var boxes = [];
        function value() {
            return boxes.map(function (b) { return b.value }).join('').toUpperCase();
        }
        function clearError() {
            err.hidden = true;
            err.textContent = '';
            boxes.forEach(function (b) { b.classList.remove('pulse-embed-cell--err') });
        }
        function fail(message) {
            err.textContent = message;
            err.hidden = false;
            boxes.forEach(function (b) { b.classList.add('pulse-embed-cell--err') });
            boxes[0].focus();
            boxes[0].select();
        }
        // Spread characters starting at position i — the same routine for typing and
        // pasting, otherwise the two paths drift apart.
        function spread(from, text) {
            var chars = String(text).toUpperCase().replace(/[^A-Z0-9]/g, '').split('');
            var at = from;
            while (chars.length && at < boxes.length) {
                boxes[at].value = chars.shift();
                at++;
            }
            boxes[Math.min(at, boxes.length - 1)].focus();
        }
        for (var i = 0; i < 6; i++) {
            var cell = document.createElement('input');
            cell.type = 'text';
            cell.className = 'pulse-embed-cell';
            cell.maxLength = 1;
            cell.inputMode = 'latin';
            cell.autocapitalize = 'characters';
            cell.setAttribute('aria-label', tr('Character {position} of 6', { position: i + 1 }));
            cell.setAttribute('aria-describedby', 'pulse-embed-err');
            cell.dataset.at = String(i);
            cell.addEventListener('input', function (e) {
                clearError();
                spread(Number(e.target.dataset.at), e.target.value);
            });
            cell.addEventListener('paste', function (e) {
                e.preventDefault();
                clearError();
                spread(Number(e.target.dataset.at), (e.clipboardData || window.clipboardData).getData('text'));
            });
            cell.addEventListener('keydown', function (e) {
                var at = Number(e.target.dataset.at);
                if (e.key === 'Backspace' && !e.target.value && at > 0) { boxes[at - 1].focus(); }
                if (e.key === 'ArrowLeft' && at > 0) { boxes[at - 1].focus(); }
                if (e.key === 'ArrowRight' && at < 5) { boxes[at + 1].focus(); }
                if (e.key === 'Enter') { go(); }
            });
            boxes.push(cell);
            cells.appendChild(cell);
        }
        (prefill || '').toUpperCase().replace(/[^A-Z0-9]/g, '').split('').forEach(function (c, at) {
            if (at < 6) { boxes[at].value = c }
        });

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pulse-btn is-primary is-lg pulse-embed-go';
        btn.textContent = tr('Show');

        function go() {
            var code = value();
            if (!/^[A-Z0-9]{6}$/.test(code)) {
                fail(tr('A room code has six letters or digits.'));
                return;
            }
            btn.disabled = true;
            roomExists(code).then(function (ok) {
                btn.disabled = false;
                if (ok === false) {
                    fail(tr('No room with this code. Check it on the presenter screen.'));
                    return;
                }
                persist(code);
                renderIframe(code);
            });
        }
        btn.addEventListener('click', go);

        wrap.appendChild(mark);
        wrap.appendChild(p);
        wrap.appendChild(label);
        wrap.appendChild(cells);
        wrap.appendChild(err);
        wrap.appendChild(btn);
        root.appendChild(wrap);
        boxes[0].focus();
    }

    /**
     * Only a real room code goes into the frame. The saved value comes from
     * the document settings (they travel with the .pptx), ?code= or
     * localStorage, and nothing has checked it yet. screenUrl() does not
     * make it safe: encodeURIComponent keeps '..', and /apps/pulse/screen/..
     * resolves to /apps/pulse/, the moderator page (or the login page) inside
     * the slide. Anything else opens the form with the value filled in, where
     * go() checks it like a typed code.
     */
    function start() {
        var saved = loadSaved();
        var code = String(saved || '').toUpperCase();
        if (/^[A-Z0-9]{6}$/.test(code)) { renderIframe(code); } else { renderForm(typeof saved === 'string' ? saved : ''); }
    }

    // Call start() exactly once — whether office.js answers first or
    // the timeout fallback kicks in.
    var started = false;
    function bootOnce() {
        if (started) { return; }
        started = true;
        start();
    }

    // Load office.js dynamically (strict-dynamic: injected by this nonced
    // script => allowed).
    var s = document.createElement('script');
    s.src = 'https://appsjs.microsoft.com/lib/1/hosted/office.js';
    s.onload = function () {
        if (window.Office && typeof window.Office.onReady === 'function') {
            window.Office.onReady(function () {
                try {
                    var doc = window.Office.context && window.Office.context.document;
                    if (doc && doc.settings) { settingsApi = doc.settings; }
                } catch (e) { /* no document context */ }
                bootOnce();
            });
        } else {
            bootOnce();
        }
    };
    s.onerror = function () { bootOnce(); }; // offline / CDN blocked => browser fallback
    document.head.appendChild(s);

    // Safety net: in a normal browser Office.onReady may never fire.
    window.setTimeout(bootOnce, 2500);
})();
