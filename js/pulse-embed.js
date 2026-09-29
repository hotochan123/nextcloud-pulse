/**
 * Pulse — Einbett-Shell fürs Office-/PowerPoint-Add-in.
 *
 * Handgeschriebenes Asset (KEIN webpack-Bundle) — wird von NC per
 * \OCP\Util::addScript mit CSP-Nonce eingebunden. Weil die NC-CSP
 * 'strict-dynamic' nutzt, dürfen von diesem (genonceten) Script dynamisch
 * nachgeladene Scripts laufen — so holen wir office.js aus Microsofts CDN.
 *
 * Zwei Betriebsarten:
 *   - Im Office-Webview: Raumcode wird in Office.context.document.settings
 *     gespeichert (bleibt in der .pptx erhalten).
 *   - Im normalen Browser (zum Testen): Fallback auf ?code=… bzw. localStorage,
 *     nach kurzem Timeout, falls kein Office-Host antwortet.
 */
(function () {
    'use strict';

    /**
     * Übersetzung über denselben Weg wie das Bundle: die Quelltexte stehen
     * englisch im Code, OC.L10N liefert die Sprache der Oberfläche. Fehlt der
     * Kern-Helfer, bleibt der englische Quelltext stehen — nie eine leere
     * Zeichenkette.
     */
    function tr(text, vars) {
        try {
            if (typeof window.t === 'function') { return window.t('pulse', text, vars); }
            if (window.OC && window.OC.L10N && window.OC.L10N.translate) { return window.OC.L10N.translate('pulse', text, vars); }
        } catch (e) { /* Quelltext als Rückfall */ }
        return text;
    }

    var ORIGIN = window.location.origin; // https://nextcloud.example.com
    var root = document.getElementById('pulse-embed-root');
    var settingsApi = null; // Office.context.document.settings, sobald verfügbar

    function screenUrl(code) {
        return ORIGIN + '/apps/pulse/screen/' + encodeURIComponent(code);
    }

    function persist(code) {
        try { window.localStorage.setItem('pulseCode', code); } catch (e) { /* egal */ }
        if (settingsApi) {
            try {
                settingsApi.set('pulseCode', code);
                settingsApi.saveAsync(function () {});
            } catch (e) { /* egal */ }
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

        // Dünne, per Hover eingeblendete Leiste zum Code-Wechsel — stört die
        // Projektion nicht, ist aber beim Einrichten erreichbar.
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
     * Raum prüfen, bevor die Leinwand kommt: der öffentliche Zustand antwortet
     * mit 404, wenn es den Code nicht gibt. Ohne die Prüfung stünde in der
     * Folie ein iframe mit „Diesen Raum gibt es nicht" — die Meldung gehört
     * ans Feld, wo der Fehler entstanden ist (§9.7).
     */
    function roomExists(code) {
        return fetch(ORIGIN + '/apps/pulse/s/' + encodeURIComponent(code) + '/state?spectate=1', {
            headers: { Accept: 'application/json' },
        }).then(function (r) { return r.ok }).catch(function () {
            return null; // kein Netz: kein Urteil, wir lassen es durch
        })
    }

    function renderForm(prefill) {
        root.className = '';
        root.innerHTML = '';

        var wrap = document.createElement('div');
        wrap.className = 'pulse-embed-setup';

        // Wortmarke: die Shell steckt in fremden Folien und muss sagen, was sie
        // ist — vorher stand dort ein nacktes Eingabefeld.
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

        // Sechs Stellen statt eines Feldes: der Code IST sechsstellig, und
        // Einfügen aus der Zwischenablage verteilt sich auf die Stellen.
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
        // Zeichen ab Position i verteilen — dieselbe Routine für Tippen und
        // Einfügen, sonst driften die beiden Wege auseinander.
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

    function start() {
        var code = loadSaved();
        if (code) { renderIframe(code); } else { renderForm(''); }
    }

    // start() genau einmal aufrufen — egal ob office.js zuerst antwortet oder
    // der Timeout-Fallback greift.
    var started = false;
    function bootOnce() {
        if (started) { return; }
        started = true;
        start();
    }

    // office.js dynamisch nachladen (strict-dynamic: von diesem genonceten
    // Script injiziert => erlaubt).
    var s = document.createElement('script');
    s.src = 'https://appsjs.microsoft.com/lib/1/hosted/office.js';
    s.onload = function () {
        if (window.Office && typeof window.Office.onReady === 'function') {
            window.Office.onReady(function () {
                try {
                    var doc = window.Office.context && window.Office.context.document;
                    if (doc && doc.settings) { settingsApi = doc.settings; }
                } catch (e) { /* kein Dokument-Kontext */ }
                bootOnce();
            });
        } else {
            bootOnce();
        }
    };
    s.onerror = function () { bootOnce(); }; // offline / CDN geblockt => Browser-Fallback
    document.head.appendChild(s);

    // Sicherheitsnetz: im normalen Browser feuert Office.onReady evtl. nie.
    window.setTimeout(bootOnce, 2500);
})();
