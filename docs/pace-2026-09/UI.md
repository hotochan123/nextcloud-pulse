# Quiz im eigenen Tempo — Oberfläche (Stufe 4)

Übergabe zur Oberfläche des Quiz im eigenen Tempo: was gebaut ist, wo es
steht, wie man es prüft und was offen bleibt. Die Engine dazu (Stufe 2+3:
Fenster, `/pace`, `/progress`, `/next`, Endgültigkeit, Lecks) steht im Commit
`feat(quiz): self-paced quiz engine (API only)` und im CHANGELOG.

**Stand:** 28.09.2026 · Schritte 4.0–4.6 umgesetzt, **freigeschaltet**
(`PACE_UI = true`, Protokoll 3). Die Produktfragen F1–F5 sind so gebaut, wie
unten in „Entscheidungen" steht.

**Folgerunde Engine (28.09.):** drei Punkte nach der Freischaltung — s.
Abschnitt „Folgerunde Engine“ vor „Bekannt und offen“.

| Schritt | Inhalt | Stand |
|---|---|---|
| 4.0 | Ausgangslage: Grundlinie `out-pre-pace-ui`, Sim, Brute-Force-Zähler | fertig |
| 4.1 | Grundlagen: `util/pace.js`, `util/csv.js`, Icons, Tokens, additive Props der Bausteine, zwei deutsche Korrekturen, Probe-Befehle | fertig |
| 4.2 | Deck im eigenen Tempo, Umschalter, Öffnen-Dialog, Status im Deck, Raumliste | fertig |
| 4.3a | Laufansicht: Kopf, Tabelle, Beitritt, Hauptaktion, Stoppen ohne Freigabe | fertig |
| 4.3b | Laufansicht: Freitext-Bewertung, Entfernen, Menü, Beamer-Vorschau, Zusammenfassung | fertig |
| 4.4a | Handy: Karten, Beitritt, Start, Weiter, Endkarten | fertig |
| 4.4b | Handy: Antwortzone, Urteil, Zeitablauf, Auswertung, Fokus; Lastlauf | fertig |
| 4.5 | Beamer: Lobby, Rennen, geschlossen, Endstand | fertig |
| Abschluss | elf Review-Befunde behoben und im Browser belegt | fertig |
| 4.6 | Freischalten, Protokoll 2 → 3, Doku | fertig |

## Was es ist

Ein Quiz-Raum läuft wahlweise **moderiert** (eine Frage für alle, die
Moderation schaltet weiter) oder **im eigenen Tempo**: jede Person arbeitet das
Deck auf dem eigenen Handy durch, ihre Uhr je Frage startet erst, wenn sie die
Frage holt. Die Moderation steuert statt eines Cursors ein **Fenster**:
Entwurf → offen → geschlossen → freigegeben.

- **Rennen** (ohne Frist): Vorgabe mit Timer und Urteil nach jeder Frage;
  Schließen gibt sofort frei.
- **Hausaufgabe** (Frist 1 Minute bis 30 Tage): Vorgabe ohne Timer, Ergebnis
  erst mit der Freigabe; die Frist schließt von selbst.
- **Probelauf**: immer Urteil sofort, nie eine Rangliste.

## Wo es steht

Neue Logik steht in neuen Dateien; `Moderator.vue`, `Participant.vue` und
`Screen.vue` tragen nur Weichen, Datenfelder und kleine Template-Blöcke. Jeder
neue Zweig hängt an `isPaced` (Moderator: `room.mode === 'quiz' && room.pace ===
'self'`; Handy/Beamer: `data.room.pace === 'self'`).

| Bereich | Datei |
|---|---|
| Reine Regeln ohne Browser: Fensterzustand, Fristen, Vorwahlen, Handy-Karten, `canNext`, Abruftakte, Rennzeilen, Feature-Schalter `PACE_UI` | `src/util/pace.js` (Test: `dev/unit/pace.test.mjs`) |
| CSV mit Requesttoken in der Query (sonst 412) | `src/util/csv.js` |
| Fristen, Dauer, „vor x min", Zustands-Chip | `src/util/format.js` (`fmtDeadline`, `fmtDuration`, `fmtAgo`, `paceStateChip`) |
| Umschalter, Sperren, Deck-Kopf, Zählstände für Bestätigungen, Fehler/409 | `src/Moderator.vue` (`deckMenu`, `paceLock`, `deckPrimary`, `fetchCounts`, `lossText`, `failWrite`, `refreshRoom`) |
| Öffnen-Dialog und „Ende ändern / Wieder öffnen" | `src/components/PaceOpenDialog.vue` (`mode="open"` / `"extend"`) |
| „N da · N beigetreten" im Deck, folgt Frist und zweitem Tab | `src/components/PaceDeckStatus.vue` |
| Laufansicht (Phase `pace`): Tabelle, Bewertung, Menü, Hauptaktion, Statuszeile | `src/components/PaceRun.vue` |
| `/progress`-Schleife, einflugig, mit Sequenznummer und Timeout | `src/mixins/progress-poll.js` |
| Beamer-Vorschau in der Laufansicht | `src/components/ScreenPreview.vue` |
| Handy: Zustand, Karten, `nextStep`, Korrekturmodus, Fokus, Ansagen | `src/mixins/pace-phone.js` + Zweige in `src/Participant.vue` |
| Einflugiges Polling für Handy und Beamer im eigenen Tempo | `src/mixins/polling.js` (`pollSingleFlight`, moderiert aus) |
| Beamer: Rennen, Beitrittsblock, Spitze, Zähler, Endstand | `src/components/StageRace.vue` + Zweige in `src/Screen.vue`, Farben `.pace-race-row` in `src/styles/pulse-ds.css` |
| Additive Props (ohne sie byte-gleich) | `PulseSegmented` `disabled`, `PulseMenu` `hint`, `PulseConfirm`/`pulseConfirm` `altLabel`, `TextGrading` `keyless`/`busy` |
| Icons `hourglass`, `calendar`, `flag`, `users`, `download` | `src/components/ui/PulseIcon.vue` |
| Kontrast-Tokens `--pulse-viz-ink`, `--pulse-state-done-ink` | `src/styles/pulse-tokens.css` (alle drei Themenblöcke) |
| Texte (Quelle Englisch) | `l10n/de.json` |
| Protokollnummer (einmaliger Reload alter Tabs) | `src/util/protocol.js` + `Application::PROTOCOL` |
| Prüfstand, Lastlauf | `dev/design-shots/{probe.php,shoot.mjs,run.sh}`, `dev/sim/load.mjs` |

## Leitplanken, die weiter gelten

- **Moderiert bleibt verhaltensgleich.** Bewusste Ausnahmen, alle
  Fehlerbehebungen (im CHANGELOG unter „Fixed"): „Export CSV" der
  Zusammenfassung (vorher 412), deutsch „Ändern" statt „Wandel" und „Probelauf"
  statt „Übungslauf", fünf Fehler-Toasts zeigen die Servermeldung, `join()`
  verwirft überholte `/state`-Antworten, einmaliger Reload durch den
  Protokollsprung.
- **Keine Lösung vor der Freigabe.** Handy nur `verdict`, nie
  `correctOption`/`answerKey`; Beamer nie Fragetext, Optionen oder
  Verteilungen, geschlossen auch keine Rangliste; Laufansicht ohne
  „Akzeptiert:"-Block, Punkte bei Rückmeldung am Ende verborgen; die
  Zusammenfassung warnt, solange das Quiz offen ist.
- **Fehler:** verzweigt wird nur nach HTTP-Status, angezeigt wird
  `e.response.data.message` mit Pauschaltext als Rückfall; Meldungstexte werden
  nie verglichen. Jede 409 auf einen schreibenden Aufruf im eigenen Tempo lädt
  den Raum neu.
- **Polling:** jede neue Schleife ist einflugig, verwirft überholte Antworten
  per Sequenznummer und hat ein axios-`timeout`.
- **Knöpfe:** genau ein gefüllter Knopf je Ansicht; `PulseMenu` für Überlauf,
  Schalter mit Häkchen, Rotes zuletzt.
- **Vue 2:** neue reaktive Felder in `data()` (auch im Mixin), Maps nur per
  `$set`. `.vue`/`.js` sind mit Tabs eingerückt — strukturelle Änderungen per
  Node-Skript, nicht mit einem Editor, der führende Tabs verliert.
- CSS-Klassen heißen `pace-*`, nie „self": `--pulse-self*` ist die
  Magenta-„Du"-Farbe.

## Entscheidungen (F1–F5, wie gebaut)

| # | Frage | Gebaut |
|---|---|---|
| F1 | Name der Einstellung | „Eigenes Tempo" / „Self-paced" |
| F2 | Wo umschalten | Häkchen im Deck-Menü jedes Quiz + Kopf-Chip „Quiz · eigenes Tempo" |
| F3 | Wann erscheint „Weiter" | erst mit dem Urteil (wenige Sekunden, kostet keine Punkte) |
| F4 | Spitze am Beamer im offenen Rennen | ja, nach den ersten 2 Minuten und nur mit Punkten > 0; bei Rückmeldung am Ende nie |
| F5 | „Du" in neuen deutschen Texten | groß |

## Prüfen

Laufregel nach jeder Änderung (vom App-Verzeichnis aus; `npx`/`npm run`
laufen unter diesem Pfad nicht, deshalb direkt über `node`):

```sh
node build/l10n-build.js && node build/l10n-check.js
TZ=Europe/Berlin node dev/unit/pace.test.mjs
npm_package_name=pulse npm_package_version=0.18.0 node node_modules/webpack/bin/webpack.js --node-env production --config webpack.config.js
occ config:app:set theming cachebuster --value=<n+1>   # sonst sehen Browser das alte Bundle
tests/run.sh
dev/sim/run.sh
dev/design-shots/run.sh           # oder PULSE_SHOTS_ONLY=<Strecke>
occ security:bruteforce:attempts <Server-IP>
```

- **Sim:** 1443 ok / 0 (Log-Scan leer). **PHPUnit:** 804 Tests. **Unit:** 31 Fälle.
- **Prüfstand:** Strecken `moderator`, `phone`, `public`, `types`, `match`,
  `embed`, `overview` und `pace` (= `pace-mod`, `pace-run`, `pace-phone`,
  `pace-screen`). Was die pace-Strecken messen, die Probe-Befehle und die
  Brute-Force-Falle stehen in `dev/design-shots/README.md`.
- **Grundlinie:** `dev/design-shots/out-post-pace-ui/{moderator,phone,public}`
  (nicht im Repo). Gegen die Grundlinie vor Stufe 4 (`out-pre-pace-ui`) sind die
  Messzeilen dieser drei Strecken gleich; die einzige gewollte Abweichung — der
  Eintrag „Self-paced" im Deck-Menü eines Quiz — zeigt die Strecke `overview`
  (Menü offen: drei statt zwei Schalter).
- **Brute-Force-Zähler:** nach jedem Lauf unverändert, bis auf `embed` (+1 mit
  Absicht, unbekannter Code `ZZZZZZ`; danach zurücksetzen).

Von Hand, in drei Fenstern (Moderator, Handy, Beamer unter
`/apps/pulse/screen/<code>`):

1. Quiz anlegen, Deck-Menü → „Self-paced". Ohne Beigetretene schaltet es ohne
   Rückfrage um; sonst fragt es mit Zahl nach.
2. „Open quiz …" → „Open quiz". Beamer: „Join and start at your own pace."
3. Handy: Name → „Start quiz" → antworten → Urteil → „Next question" → … →
   „I’m done" → „You’re through!". Beamer: Rennen „1 of 1 finished", nie ein
   Fragetext.
4. Laufansicht: „Close quiz" → „Close and release". Beamer: Podium; Handy:
   „Quiz finished" mit Platz; Hauptaktion jetzt „Participants as CSV".
5. Gegenprobe moderiert: anderes Quiz, „Start presenting", auflösen, „Show final
   standings" — Handy und Beamer folgen wie bisher.

## Freischaltung (4.6)

- `PACE_UI = true` in `src/util/pace.js`: der Schalter steht in jedem Quiz-Deck,
  `?pace=1` ist bedeutungslos (auch der Prüfstand braucht es nicht mehr).
- `Application::PROTOCOL` und `src/util/protocol.js` von 2 auf 3. Ein Handy-
  oder Beamer-Tab mit einem Bundle von davor hätte einen Raum im eigenen Tempo
  für immer als „Waiting for the next question …" bzw. „Starting shortly"
  gezeigt; mit dem Sprung lädt jeder offene Tab einmal neu. Vermerk im
  `sessionStorage` (`pulse-protocol-reload` = `3`), deshalb keine Schleife.
- **Reihenfolge bei jedem Protokollsprung:** erst Bundle bauen und Cachebuster
  erhöhen, **dann** die PHP-Konstante. Umgekehrt lüde ein Tab, der den neuen
  Server sieht, das *alte* Bundle neu, setzte den Vermerk — und bliebe danach
  auf dem alten Bundle stehen, weil der Vermerk jeden weiteren Reload
  verbietet. In der Zwischenzeit (neues Bundle, alter Server) lädt ein frisch
  geöffneter Tab höchstens einmal mit Vermerk `2` und passt danach.

**Gemessen bei der Freischaltung (28.09.):**

- Laufregel komplett: Übersetzungen 749 Quelltexte, 0 fehlen; Unit 30 / 0;
  Build; Cachebuster 170 → 171; PHPUnit 597 Tests (ProtocolConstantTest 3 = 3);
  Sim 1384 / 0, vor und nach dem Prüfstand.
- Protokollsprung: ein Handy-Tab (mitten in Frage 1) und ein Beamer-Tab
  (Rennen), beide vor dem Schritt mit dem Bundle `…-170` geöffnet. Nach Build
  und Cachebuster (Server noch bei 2): kein Reload. Nach der PHP-Konstante
  meldete `/state` nach 12 s Protokoll 3; beide Tabs luden 13–14 s nach der
  Änderung **genau einmal** neu, jetzt mit `…-171`, Vermerk `3`, das Handy
  wieder auf seiner Frage. Danach 2,5 Minuten ohne weiteren Reload.
- Prüfstand: `moderator`, `phone`, `public`, `types`, `match`, `overview`,
  `embed`, `pace` — alle ohne Befund. Messzeilen von `moderator`/`phone`/
  `public` gleich `out-pre-pace-ui`; `overview` zeigt im Quiz-Deck-Menü drei
  statt zwei Schalter (den neuen Eintrag), sonst gleich; `pace` gegenüber dem
  Lauf vor der Freischaltung nur mit anderen Uhrzeiten und Laufzeiten, jetzt
  ohne `?pace=1`. Neue Grundlinie `out-post-pace-ui`.
- Brute-Force-Zähler 0 vor und nach jedem Lauf (`embed` +1 wie vorgesehen,
  zurückgesetzt).
- Gemeinsamer Durchgang in drei Sitzungen, 20 / 0: moderiertes Quiz (Start,
  Auflösen, Endstand auf Handy und Beamer); Schalter „Self-paced" ohne
  `?pace=1` im Quiz-Deck, nicht im Umfrage-Deck; Umschalten ohne Rückfrage
  (niemand beigetreten), Öffnen mit den Vorgaben, Handy durch zwei Fragen,
  Beamer-Rennen ohne Fragetext, „Close and release", Podium und Platz 1.

## Folgerunde Engine (28.09.)

Nach der Freischaltung, ohne Protokoll- und ohne Versionssprung (0.18.0), in
dieser Reihenfolge:

- **Eingaben gehärtet.** Jeder Request-Parameter läuft über
  `lib/Service/Input.php` (`str`/`number`/`int`/`flag`), das Voter-Cookie über
  `PublicVoteController::voterToken()`: Listen, Objekte, 1e999/INF, Zahlen
  jenseits ±1e18 und kaputtes UTF-8 gelten als nicht gesendet — die
  übliche 400 oder die Vorgabe, nie eine PHP-Warnung, nie 500. NUL-Bytes
  fallen weg; nur die Freitextantwort beim Bewerten behält sie (`rawStr`),
  weil die gespeicherte Stimme sie behält und die Normalform sonst ihre Gruppe
  nie träfe. Wo „fehlt“ etwas bedeutet, ist Unbrauchbares 400: Frist
  (`closesAt`), Cursor (`pollId`), optionale Schalter in `/pace` (`timed`).
  Als Voter-Cookie gilt nur die Form, die der Server vergibt (32 Zeichen
  A–Z, a–z, 0–9); alles andere zählt als kein Cookie (`/state`, `/join`,
  `/vote` vergeben ein neues). Geprüft:
  `InputTest`, `ControllerInputTest` (jede Aktion mit Listen in jedem
  Parameter, Quelltext-Wächter), `HostileInputTest`; im Sim der Durchgang
  „Feindliche Eingaben“, danach der Log-Scan (User-Agent `pulse-sim`, kein
  Eintrag ab Stufe 2). Gemessen: PHPUnit 597 → 773 Tests (1748 → 3837
  Zusicherungen), Sim 1384 → 1417 ok / 0 mit leerem Log-Scan, Unit 30 / 0,
  Übersetzungen 749 / 0 fehlen, Prüfstand `moderator`/`phone`/`public` gleich
  `out-post-pace-ui` bis auf die Schriftgröße der aufgelösten Schätzfrage am
  Beamer (42 statt 46 px — hängt an den zufälligen Demo-Stimmen und schwankte
  schon vorher zwischen beiden Werten), Brute-Force-Zähler 0 vor und nach
  jedem Lauf.
- **Stoppen ohne Freigabe ist ein Aufruf:** `POST /pace {action: close,
  release: false}` — geschlossen, nicht freigegeben, die Frist bleibt 0,
  „Reopen …“ belegt „When I close it“ vor. `release` wirkt nur beim Schließen
  eines offenen Fensters (geschlossen = No-op, auch mit `true`); fehlt er, gilt
  die alte Regel (ohne Frist = Freigabe) — alte Tabs und API-Clients laufen
  unverändert, ohne Protokollsprung. Unbrauchbar (Liste, „maybe“, 2) = 400
  „Invalid request.“, `''` = false. Die Laufansicht schickt `release` jetzt
  immer ausdrücklich: „Close and release“ `true`, „Close quiz“ mit Frist und
  „Stop without releasing“ `false`; der frische Raum-Abruf vor „Close quiz“
  und der localStorage-Merker entfallen. Reihenfolge beim Ausrollen: erst PHP
  (≥ 65 s Opcache, Sim „Stoppen ohne Freigabe“ grün), dann Bundle — ein neues
  Bundle gegen den alten Server gäbe beim Stoppen frei. Gemessen (PHP vor dem
  Bundle; das Bundle folgt unten, „Bundle“): PHPUnit 773 → 801 Tests (3837 → 3942 Zusicherungen), Sim 1417 → 1440
  ok / 0 mit leerem Log-Scan (23 Prüfungen „Stoppen ohne Freigabe“), Unit
  30 / 0, Übersetzungen 749 / 0 fehlen, Brute-Force-Zähler 0 vor und nach dem
  Lauf.
- **Rennbalken ohne Überlappung.** `beamerState` zählt jede gestartete Person
  genau einmal: fertig (`isFinished`) oder auf Frage k (offene, sonst zuletzt
  erreichte Zeile — dieselbe Zahl wie Handy und Laufansicht); nicht gestartet +
  je Frage + fertig = beigetreten. Legende jetzt „100% = everyone who joined“
  („100 % = alle Beigetretenen“). Das Sim prüft Beamer = `/progress` (Rennen
  offen und freigegeben, Hausaufgabe nach der Frist, Probelauf), der Prüfstand
  `pace-screen` die Summe (Befund `SUMME`).

Gemessen (28.09., PHP aller drei Punkte): PHPUnit 804 Tests / 3952
Assertions, Unit 31 / 0, Sim 1443 ok / 0 (Log-Scan ohne Eintrag),
Übersetzungen 749 / 0 fehlen / 0 verwaist, Brute-Force-Zähler 0 vor und nach
dem Lauf.

**Bundle (28.09., ein Schritt für alle drei Punkte, nach dem PHP):** Build,
Cachebuster 171 → 172. Prüfstand `pace` 222 Aufnahmen ohne Befund — „Stop
without releasing“ genau ein `/pace`-Aufruf, Frist 0; „Close quiz“ mit Frist
ein Aufruf, Frist unverändert; Merker keiner; `SUMME` in allen 24 offenen
Rennzeilen gleich; Legende nicht abgeschnitten. `moderator` gleich
`out-post-pace-ui`. Sim 1443 / 0 mit leerem Log-Scan; Fuzz über jede Route
und jeden Parameter (Listen/Objekte in JSON, Formular, Query und Cookie;
Wegwerf-Skript, nicht im Repo) 14 271 Anfragen, 0× 5xx.

**Nachprüfung (29.09.), nur PHP, Sim und Doku — kein neues Bundle:**

- Die Freitextantwort beim Bewerten las `Input::str` und verlor so ihr NUL;
  die Stimme behält es (`normalizeValue` prüft nur `is_string`). Eine
  Antwort „Pa\0ris“ blieb dann für immer „wird geprüft“, und „falsch“ darauf
  lehnte stattdessen jedes echte „Paris“ ab. Jetzt `Input::rawStr` (wie
  `str`, nur mit NUL; die Antwort geht ausschließlich durch `json_encode`).
  Geprüft: `ControllerInputTest`, `InputTest`, Sim „Freitext mit NUL bleibt
  bewertbar“.
- CSV des moderierten Raums: jedes Quiz mit Freitextfrage gab 500 (seit
  0.17.0, mit dem Freitext gekommen) — die Auszählung heißt dort `answers`, nicht
  `results`. Jetzt eine Zeile je Antwortgruppe; die Schätzung nennt ihre Zahl
  (die Spalte war leer); Freitext und Wörter aus dem Publikum laufen wie im
  eigenen Tempo durch `CsvFormat::cell` (vorher `PaceStateService::cell`);
  Anführungszeichen nach RFC 4180 (`fputcsv` mit leerem Escape, das PHP 8.4
  ohnehin ausdrücklich verlangt). Geprüft: `QuizCsvTest`, Sim „CSV …“ in
  jedem moderierten Quiz.
- Sim: Beamer = `/progress` jetzt auch nach Fristablauf (Hausaufgabe) und im
  Probelauf — vorher prüften nur Rennen offen und freigegeben gegen
  `/progress`.
- Umfrage-Stimme: zwei erste Stimmen desselben Tokens zugleich (Doppeltipp,
  Wiederholung nach Netzfehler) fanden beide keine Stimme, die zweite
  Einfügung scheiterte am UNIQUE-Index — 500. Alt (schon in 0.18.0), gefunden
  vom Fuzz (`/vote` mit vier gleichzeitigen Anfragen und einem Cookie). Jetzt
  wie in Quiz und eigenem Tempo abgefangen: die zweite wird zur Änderung.
  Geprüft: `PollVoteRaceTest`; Wettlauf 60 × 8 gleichzeitige erste Stimmen
  (Wegwerf-Skript): 480× 200, 224 abgefangene Verstöße im PostgreSQL-Log,
  60 Stimmen für 60 Tokens.
- Sim, Fuzz und Prüfstand teilen `pulse-shots`; `probe destroy` löscht jeden
  Raum des Nutzers. Nie parallel (READMEs).

Gemessen: PHPUnit 804 → 827 Tests (3952 → 3989 Assertions), Unit 31 / 0,
Übersetzungen 749 / 0 fehlen / 0 verwaist, Sim 1443 → 1458 ok / 0 mit leerem
Log-Scan (vor und nach dem Stimmen-Fix). Fuzz vor dem Stimmen-Fix 14 271
Anfragen, 2× 500 (der Wettlauf, sonst ohne Log-Eintrag); danach 14 271,
0× 5xx, Log-Scan leer.
Prüfstand `phone` und `public` gegen das Bundle von 172 gleich
`out-post-pace-ui` (nur andere Raumcodes; `public` wieder 42 statt 46 px bei
der aufgelösten Schätzfrage, s. oben). Brute-Force-Zähler 0 vor und nach jedem
Lauf, danach kein Raum von `pulse-shots` übrig.

## Bekannt und offen

**Grenzen, die man kennen muss**

- **Gerätewechsel** ist eine neue Identität: Wer auf einem anderen Gerät
  weitermacht, beginnt mit neuem Namen bei Frage 1, der alte Name gilt als
  vergeben. Die Moderation kann die alte Person entfernen, dann ist der Name
  frei. Ein Wiedereinstiegscode ist Stufe 6.
- **„Finished" vor den letzten Punkten:** Fertig ist, wer die letzte Frage
  beantwortet hat — auch während diese Antwort noch in ihrem Korrekturfenster
  steht. Die Laufansicht zeigt dann „Finished" und die Statuszeile „Everyone
  still here is through …", die Punkte der letzten Antwort kommen erst mit ihrem
  Urteil (gemessen: fertig nach 0,3 s, Punkte nach 4,2 s — im selben Moment wie
  das Urteil am Handy). Verloren geht nichts: Schließen macht die Antwort sofort
  endgültig. Die Laufansicht hängt dem Handy höchstens einen Abruf (2–3 s)
  hinterher.
- **Offene Freitexte** zählen 0 Punkte, bis sie bewertet sind; eine Bewertung
  nach der Freigabe ändert einen schon gesehenen Endstand (die Laufansicht
  warnt dort).
- **Last:** Lastlauf mit 100 Handys, 90 s (27.09.): `/state` p95 40 ms,
  `/next` und `/vote` p95 ≈ 42 ms, `/progress` p95 304 ms (baut bei jedem Abruf
  alles neu; ein Moderator alle 2–3 s). Im Rahmen; ein Aggregat-Cache für
  `/progress` erst, wenn es gemessen eng wird.
- Höchstens 300 Personen je Raum, 120 Beitritte je Adresse und Raum in zehn
  Minuten — eine Schulklasse hinter einem NAT liegt darunter, ein Hörsaal hinter
  einer Adresse nicht unbedingt.

**Nächste Stufen**

- Stufe 5: Start aus dem Wartezustand ohne Tipp, Demo-Rennen (`?demo=1`),
  Store-Bilder mit einem Rennen.
- Stufe 6: Wiedereinstiegscode (Gerätewechsel), Mischen pro Person,
  gemeinsamer Durchgang nach Schluss.

**Engine-Folgepunkte** (PHP + Test, eigene kleine Runde)

- `beamerState` schickt im geschlossenen, nicht freigegebenen Fenster bei
  „Rückmeldung je Frage“ weiter die Spitze (Top 8) mit. Der Beamer zeigt sie
  nicht (Screen.vue), abrufbar ist sie mit dem Raumcode aber; seit
  `close {release: false}` ist das der Normalfall des Stoppens. Ggf. nur noch
  im offenen Fenster senden (PHP, SelfStateGateTest, Prüfstand-Befund
  „RANGLISTE IM GESCHLOSSENEN“).
- Billiger Fingerabdruck für `/state` im eigenen Tempo und Präsenz nicht bei
  jedem Abruf schreiben — nur, wenn Last es verlangt.
- `/progress` mit Aggregat-Cache pro Version — erst bei messbarer Last.
- Ohne API, also nirgends geplant: „Nicht Du?" auf geteilten Tablets,
  Beitrittssperre im öffentlichen Zustand, Länge des Korrekturfensters im
  Payload.

**Aufräumen**

- Präsentations-Vorschau des Moderators auf `ScreenPreview` umstellen;
  `Moderator.ago()` auf `fmtAgo`.
- `prefers-reduced-motion` global für `.srow-fill` (heute nur die Rennzeilen).
- Der moderierte Korrekturmodus bleibt nach einer abgelehnten Korrektur (400)
  offen stehen. Im eigenen Tempo ist das behoben; moderiert löst ihn die
  nächste Frage der Moderation — eigene Runde.
- Deutscher Wortlaut von „This question is closed." (heute „Diese Abstimmung
  ist beendet.", geteilter Server-Schlüssel) und die kleingeschriebenen
  „du/dich" im Bestand (u. a. „Nur für dich").
- `PACE_UI` ist jetzt eine Konstante `true`. Sie kann samt Abfrage in
  `deckMenu` entfallen, sobald feststeht, dass sie nicht mehr zurückgedreht
  wird.
- Die Strecke `moderator` öffnet kein Quiz-Deck-Menü; den neuen Eintrag belegt
  nur `overview` (und `pace-mod`). Wer die Grundlinie schärfer will, nimmt eine
  Aufnahme des offenen Quiz-Menüs in `moderator` auf.
- `stopDeadline`/`STOP_LEAD` in `src/util/pace.js` erkennen nur noch
  Altlast-Stopps: Räume, die der frühere Zwei-Schritt-Weg (Frist in 120 s,
  dann `close`) gestoppt hat, stehen mit `closesAt = closedAt + 120` und ohne
  Freigabe in der Datenbank. Ein Protokollsprung lädt nur Tabs neu, diese
  Räume bleiben — ohne die Helfer belegte „Reopen …“ dort „At a set time“ mit
  morgen vor, und ein Klick machte aus dem Rennen eine Hausaufgabe. Entfallen
  dürfen sie erst, wenn keiner mehr existiert: `SELECT code FROM
  oc_pulse_rooms WHERE pace = 'self' AND released_at = 0 AND closed_at > 0
  AND closes_at - closed_at BETWEEN 100 AND 125` leer — sonst vorher für diese
  Räume `closes_at = 0` setzen oder die Helfer behalten. Den Zwei-Schritt-Weg
  gab es nur auf `main` zwischen `7d5f030` und `ef29c10`, in keinem Release;
  auf der Live-Instanz stand am 29.09. kein Raum im eigenen Tempo.
- Bewusst hingenommen: einen Altlast-Stopp, dessen zweiter Aufruf scheiterte,
  erkennt nichts mehr. Die 120-s-Frist schloss den Raum dann von selbst
  (`closedAt = closesAt`, Abstand 0); verraten hat ihn nur der
  localStorage-Merker `pulse-pace-stop:<code>` des alten Tabs, und den liest
  das neue Bundle nicht. „Reopen …“ (in den zwei Minuten davor auch „Change
  the end …“) belegt dort „At a set time“ mit morgen und „Was: …“ vor — sichtbar
  im Dialog, bevor jemand klickt. Dafür braucht es ein Bundle von vor
  `close {release}` (nur `main`, nie released) und einen gescheiterten
  zweiten Aufruf; den Merker dafür wieder einzulesen lohnt nicht.
