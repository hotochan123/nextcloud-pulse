# Durchgangs-Simulation

Spielt eine Umfrage, vier Quiz-Varianten und das Quiz im eigenen Tempo gegen
die laufende Instanz durch — ohne Browser, direkt über die HTTP-Endpunkte, so
wie Moderator-Oberfläche, Handys und Beamer sie benutzen:

```sh
dev/sim/run.sh          # durchspielen, Räume danach löschen
KEEP=1 dev/sim/run.sh   # Räume stehen lassen (zum Nachsehen im Browser)
# Strg-C räumt die bis dahin angelegten Räume ebenfalls weg.
```

Endet mit `N ok / M fehlgeschlagen` und Exit-Code 1, sobald etwas rot ist.
Ein Lauf dauert knapp fünf Minuten (Antwort-Verzögerungen, Korrekturfenster,
abgelaufene 5-s-Timer; die Hausaufgabe im eigenen Tempo wartet ihre 90-s-Frist
ab, die parallel zum Rennen läuft; dazu knapp 30 s Wettläufe).

## Was durchgespielt wird

| Durchgang | Inhalt |
|-----------|--------|
| Umfrage | alle fünf Typen (Auswahl, Wortwolke, Skala einzeln/Spektrum/Kompass, Reihenfolge, Zuordnung), sechs Handys, Stimme ändern, Auflösen, Wieder öffnen, Zurückblättern, Gesamtauswertung, CSV |
| Quiz „je Frage" | alle sieben Typen, vier Spielende mit festem Antwortplan, Korrekturfenster, Freitext-Bewertung, Auflösen je Frage, Endstand, CSV |
| Quiz „am Ende" | wie oben, aber verdeckt bis `/end` |
| Probelauf | je Frage aufgelöst, keine Rangliste, Ende = Lobby |
| Probelauf + am Ende | `/end` löst einmal auf (ohne Rangliste), dann Lobby |
| (jedes Quiz) | danach ein zweiter Lauf ohne Zurücksetzen: das alte Ende gilt nicht mehr |
| Timer & Deck | Zeitablauf, Wieder öffnen, Bearbeiten leert Stimmen, Duplizieren, Zurücksetzen |
| „am Ende" nachträglich | nach dem Auflösen eingeschaltet: Frage wieder verdeckt, keine neuen Antworten; offene Handys/Beamer erfahren es (Version ändert sich), zurückgeschaltet wieder aufgelöst |
| Frage übersprungen | weitergeschaltet ohne Auflösen: ihre Punkte fehlen in jeder öffentlichen Rangliste, bis sie aufgelöst ist; am Ende zählt alles, auf Handy, Beamer, `/summary` und beim Moderator gleich |
| Wortschlüssel | ❤️/❤ (Variantenwähler) und „guter  Kaffee" (doppelter Leerraum) sind je ein Wort, angezeigt in der ersten Schreibweise |
| Laufende Frage bearbeitet | Umfrage ohne Stimmen: offene Handys holen die neue Fassung, Stimme mit neuen IDs geht durch |
| Probelauf-Wechsel | Umschalten leert Test-Teilnehmende und -Stimmen; Handy und Beamer in der Lobby erfahren es |
| Eigenes Tempo: Rennen | ohne Frist, mit Timer, Urteil je Frage; Cursor-Steuerung und (ab dem Öffnen) Deck gesperrt (409), persönliche Uhr ab `/next`, Vorschau-Sperre, Korrektur nur im Fenster der ersten Antwort, Zeitablauf, Freitext „wird geprüft" und Bewertung, Name nach dem Start fest, Beitritt sperren, Person entfernen, Schließen = Freigabe, beide CSV-Sichten, Zurücksetzen und zurück auf moderiert |
| Eigenes Tempo: Hausaufgabe | Frist (1 min–30 Tage, aus der Serveruhr), ohne Timer, Urteil erst mit der Freigabe; die Frist läuft ohne Moderator ab, Verlängern, Schließen ohne Freigabe, Freigeben; flach 1000 Punkte, CSV ohne Zeiten |
| Eigenes Tempo: Probelauf | „Urteil am Ende" wird „je Frage", nie eine Rangliste (Handy, Beamer, `/summary`; Moderator `[]`), Beitritts-Limit je IP und Raum |
| Eigenes Tempo: Stoppen ohne Freigabe | `close {release:false}` in einem Aufruf: geschlossen, Frist 0, nicht freigegeben, Stimme sofort endgültig, Handy/Beamer ohne Endstand; ungültiges `release` 400 ohne Wirkung; `close` auf geschlossenem Fenster No-op (auch mit `release`); der alte Zwei-Schritt-Weg (Frist in 120 s, dann `close`) wirkt wie bisher; `release: "false"` als Text; `release: true` gibt mit Frist frei |
| Eigenes Tempo: Wettläufe | echt parallel, die Verzögerung des Handys um den gemessenen Laufzeitunterschied Moderator − Handy durchgestimmt: Entfernen gegen `/vote` und `/next`, Bewerten gegen eine Antwort und gegen ihre Korrektur |
| Feindliche Eingaben | Listen/Objekte statt Text oder Zahl in Moderator- und Handy-Parametern (JSON und Formular), 1e100/1e999, NUL, kaputtes UTF-8, fremde `pulse_vt`-Cookies (Liste, überlang, kaputt): nie 500, sondern 400 mit der üblichen Meldung, die Vorgabe oder ein neues Cookie; eine Freitextantwort mit NUL bleibt bewertbar |

## Was geprüft wird

- Handy-Gesamtauswertung und Moderator zählen dasselbe (je Frage); der Beamer
  zählt dieselben Antworten. Die CSV der Umfrage wird nur erzeugt (200, BOM);
  die des moderierten Quiz nennt jede Frage, beim Freitext eine Zeile je
  Antwortgruppe wie beim Moderator, bei der Schätzung die Zahl.
- Vor dem Auflösen stehen weder Lösung (`correctOption`, `answerKey`) noch
  Auszählung noch richtig/falsch im öffentlichen Zustand; Reihenfolge und
  Zuordnung kommen gemischt (Schlüssel-Hash, für alle gleich, unabhängig von
  der Lösung); ob eine Frage aufgelöst ist, sagt `poll.revealed`. Die
  öffentliche `version` ist ein undurchsichtiger Hash, kein CRC der Lösung.
- Die Gesamtauswertung fürs Handy enthält nur Fragen, die schon gezeigt wurden;
  ihre Rangliste zählt keine verdeckte Frage mit (laufend oder übersprungen),
  erst der Endstand zählt alles. Eine bearbeitete Frage gilt wieder als nie
  gezeigt.
- Punkte: Summe der Einzelrückmeldungen je Person = Ranglistenpunkte; bei
  gleicher Trefferzahl liegt die schnellere Person vorn.
- Eine Antwort mit veralteter `pollId` (Moderator hat weitergeschaltet) wird
  abgelehnt, nicht der neuen Frage gutgeschrieben.
- Nicknames sind im Raum eindeutig (ohne Groß/Klein, ohne unsichtbare
  Zeichen, Variantenwähler und doppelten Leerraum), der eigene darf umgeschrieben werden. Wortwolke: ein Wort je
  Person, auch mit Nullbreiten-Leerzeichen oder NBSP.

Im eigenen Tempo zusätzlich:

- Vor der Freigabe steht auf dem Handy weder Lösung noch Verteilung noch
  Rangliste; `/summary` zeigt im offenen Fenster nur verlassene, nach Schluss
  alle erreichten Fragen, beides ohne Lösung, und einem fremden Cookie nie eine
  Frage. Der Beamer zeigt nie Fragetexte oder Optionen, nur das Rennen in Zahlen
  (`race`) und bei „Urteil je Frage" die Spitze. Das Rennen zählt jede gestartete
  Person genau einmal (auf ihrer Frage oder fertig) und stimmt mit `/progress`
  überein — offen, freigegeben und nach Fristablauf.
- Es zählen nur endgültige Stimmen (Korrekturfenster vorbei, korrigiert oder
  nicht mehr korrigierbar): Handy (`myScore`), Beamer, `/progress`, Endstand
  und CSV nennen dieselben Punkte. Eine Stimme direkt vor dem Schließen zählt
  sofort.
- Die Version springt ohne Job, sobald etwas Zeitgetriebenes kippt (Stimme
  endgültig, Zeitablauf, Frist), und nach Bewertung und Freigabe; der
  Heartbeat pollender Handys lässt die `/progress`-Version stehen (204).
- „Urteil am Ende": bis zur Freigabe nur „gespeichert", `/progress` verdeckt
  die Punkte (außer `?scores=1`) — auch nach abgelaufener Frist.
- `close` ohne `release` behält die alte Regel (ohne Frist = Freigabe) — alte
  Tabs und API-Clients.
- Beitritt: gesperrt kommen nur bekannte Handys rein, nach dem Start gibt es
  keinen anderen Namen, eine entfernte Person verliert Namen und Fortschritt
  und ihr Name wird frei; der 121. Beitrittsversuch je IP und Raum bekommt 429.
  Den Deckel von 300 Spielenden erreicht eine IP nie (Limit 120) — er steckt nur
  im Unit-Test.
- Wettläufe (ob ein Lauf das Millisekunden-Fenster trifft, ist Zufall; geprüft
  wird, was ein Treffer verletzte): wer mitten in `/vote` oder `/next` entfernt
  wird, hinterlässt weder Stimme noch Zeile; eine Antwort, die mitten in der
  Bewertung ihrer Normalform ankommt, bleibt nicht auf „wird geprüft"; eine
  Korrektur geht gegen eine gleichzeitige Bewertung nicht verloren; keine 500.
  Das Löschen eines Raums mitten in `/join`/`/next` prüft das Sim nicht: jede
  404 darauf zählte als Brute-Force-Versuch der Sim-IP (Unit-Tests `RoomGoneTest`,
  `RoomPaceLifecycleTest`).

## Wie es funktioniert

- `run.sh` legt bei Bedarf den Wegwerf-Nutzer `pulse-shots` an (derselbe wie in
  `dev/design-shots`, Passwort in `dev/design-shots/.shots-pass`), ermittelt die
  Container-IP und startet `sim.mjs`.
- Der Moderator spricht per Basic-Auth + `OCS-APIRequest: true` mit der API
  (kein CSRF-Token nötig), jedes Handy hält sein eigenes `pulse_vt`-Cookie.
- Die Anfragen gehen direkt an den Container, nicht über den Proxy. Die IP ist
  keine `trusted_domain`, deshalb schickt `sim.mjs` `Host: localhost` — und
  nutzt dafür `node:http`, weil `fetch` den Host-Header überschreibt.
- Eigenes Tempo mit echten Wartezeiten: eine Stimme ist erst endgültig, wenn
  `(now − created) > fw` in ganzen Sekunden gilt, real also bis zu fw+1 s nach
  der Antwort — `FINAL(fw)` wartet fw + 1,5 s. Beamer-Zustand und öffentliche
  Rangliste liegen 2 s im Server-Cache, Beamer-Prüfungen nach einer Änderung
  warten deshalb vorher 2,1 s. Fristen kommen aus `serverNow` der letzten
  Antwort, nie aus der Uhr dieses Rechners.
- Meldungen und CSV-Zellen („yes"/„no") werden englisch verglichen:
  `pulse-shots` hat die Sprache `en`, die Handys schicken kein
  `Accept-Language`.
- Jede Anfrage trägt den User-Agent `pulse-sim`. Nach dem Lauf zeigt ein
  Log-Scan, ob eine davon eine Warnung oder einen Fehler ins Nextcloud-Log
  schrieb — erwartet: keine Zeile:

  ```sh
  LOG=/var/log/nextcloud/nextcloud.log
  OFF=$(docker exec nextcloud-nextcloud-1 stat -c %s $LOG)
  dev/sim/run.sh
  docker exec nextcloud-nextcloud-1 tail -c +$((OFF + 1)) $LOG | grep -a '"userAgent":"pulse-sim"' | grep -a -E '"level":[234]'
  ```

**Nie parallel** zu `dev/design-shots/run.sh` oder einem zweiten Sim-Lauf:
alle nutzen `pulse-shots`, und der Prüfstand räumt mit `probe destroy
pulse-shots` jeden Raum dieses Nutzers weg — auch die, die das Sim gerade
benutzt. Die nächste Handy-Anfrage auf einen gelöschten Code zählt als
Brute-Force-Versuch der Sim-IP.

Stellschrauben: `CONTAINER`, `PULSE_SIM_URL`, `PULSE_SIM_HOST`,
`PULSE_SIM_USER` (dann auch `PULSE_SIM_PASS` — `.shots-pass` gehört
`pulse-shots`). Antwortet `occ` nicht, bricht `run.sh` ab, statt das
gemeinsame Passwort zu überschreiben.

`PULSE_SIM_HOST` (Vorgabe `localhost`) ist auch der Ausweg aus dem
Routencache: Nextcloud legt die Routen je Host-Header eine Stunde in APCu ab
(`lib/private/Route/CachingRouter.php`, Schlüssel Host + Basis-URL). Eine neu
eingetragene Route antwortet für einen schon gecachten Host bis dahin mit 404 —
der HTML-Fehlerseite, nicht dem JSON des Controllers (401/403/400/405 heißt:
Route geladen). Ein Port ergibt einen frischen Schlüssel, die
`trusted_domains`-Prüfung ignoriert ihn:

```sh
PULSE_SIM_HOST=localhost:34071 dev/sim/run.sh
```

Die Durchgänge im eigenen Tempo prüfen das vorab: antwortet `/pace` mit 404,
meldet das Sim genau einen Fehlschlag mit diesem Hinweis und lässt nur sie aus.

Erst nach dem Ändern von `appinfo/routes.php` und einer Minute Wartezeit
(opcache prüft Dateien nur alle `opcache.revalidate_freq` Sekunden, hier 60),
sonst legt der frische Schlüssel die alten Routen für eine Stunde ab. Kein
Container-Neustart nötig.

## Grenze

Die Simulation bildet die Moderator-Knöpfe nach (`moderatorFinish` spiegelt
den Hauptknopf an der letzten Frage aus `Moderator.vue`), sie klickt sie nicht. Ändert sich dort der
Ablauf, muss `sim.mjs` mitziehen — und ob die Oberfläche den richtigen Knopf
anbietet, zeigt nur der Browser.
