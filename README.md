# Pulse — Live-Umfragen für Nextcloud

Mentimeter-artige Live-Umfragen als native Nextcloud-App. Die vortragende Person
öffnet einen Raum und projiziert einen 6-stelligen Code; das Publikum tritt ohne
Nextcloud-Konto über einen öffentlichen Link bei und stimmt vom Handy ab.
Ergebnisse aktualisieren sich per Polling live.

## Funktionsumfang

- Zwei Rollen: **Moderator** (angemeldet) und **Teilnehmer** (öffentlich, ohne Konto).
- Zwei Raum-Modi: **Umfrage** (anonym) und **Quiz** (Nickname, Countdown, Rangliste).
- Fragetypen — Umfrage: Multiple Choice · Wortwolke · Skala (Einzel/Spektrum/Kompass) ·
  Reihenfolge · **Zuordnung**. Quiz: Multiple Choice · Wahr/Falsch · Mehrfachauswahl ·
  Schätzfrage · Freitext (mit Bewertung) · Reihenfolge · **Zuordnung**.
- Deck aus mehreren Fragen, Bild je Frage, Beamer-Ansicht, QR-Code, Probelauf,
  Zusammenfassung mit CSV-Export.
- Quiz **im eigenen Tempo** (Schalter „Eigenes Tempo" im Deck-Menü eines Quiz):
  statt einer Frage für alle arbeitet jede Person das Deck auf dem eigenen Handy
  durch — als Rennen im Raum oder als Hausaufgabe mit Frist. Die Moderation
  sieht den Fortschritt je Person, bewertet Freitexte und gibt die Ergebnisse
  frei; der Beamer zeigt das Rennen, nie die Fragen. Siehe unten.
- Doppelabstimmungs-Schutz per anonymem Voter-Cookie (keine Personenzuordnung).

## Quiz im eigenen Tempo

1. **Umschalten:** im Quiz-Raum Deck-Menü → Häkchen „Eigenes Tempo". Das leert
   Teilnehmende, Antworten und Rangliste (mit Rückfrage, sobald jemand
   beigetreten ist); die Fragen bleiben. Der Kopf zeigt „Quiz · eigenes Tempo".
2. **Öffnen:** „Quiz öffnen …" statt „Präsentation starten". Im Dialog das Ende
   wählen:
   - „Wenn ich es schließe" = **Rennen**: Vorgabe mit Timer und Rückmeldung nach
     jeder Frage; Schließen gibt die Ergebnisse sofort frei (oder „Stoppen ohne
     Freigabe", um vorher Freitexte zu prüfen).
   - „Zu einer festen Zeit" = **Hausaufgabe**, Frist 1 Minute bis 30 Tage:
     Vorgabe ohne Timer, Ergebnis erst mit der Freigabe; die Frist schließt das
     Quiz von selbst, auch ohne offenen Tab.

   Timer und Rückmeldung lassen sich im Dialog umstellen; ein Probelauf gibt
   immer sofort Rückmeldung und zeigt nie eine Rangliste. Ab dem Öffnen sind die
   Fragen gesperrt, bis der Raum zurückgesetzt wird.
3. **Laufansicht** („Fortschritt zeigen"): je Person Frage, Antworten, Punkte und
   letzte Aktivität, daneben Beitritts-Code mit QR, Beamer-Vorschau und
   Freitext-Bewertung (ohne Lösungsliste, Fehltipps unter „Gerade geprüft"
   umkehrbar). Unten genau eine Hauptaktion: „Quiz schließen" → offene
   Freitexte prüfen → „Ergebnisse freigeben". Im Menü: Beitritt sperren, Ende
   ändern bzw. wieder öffnen, Punkte zeigen (bei Rückmeldung am Ende),
   Teilnehmende und Antworten als CSV, Quiz zurücksetzen; Personen lassen sich
   einzeln entfernen. Die Seite ist privat — projiziert wird das Beamer-Fenster.
4. **Handy:** Name → Startkarte → Fragen. „Nächste Frage" erscheint, sobald die
   Antwort endgültig ist (wenige Sekunden, kostet keine Punkte); Fragen ohne
   Timer lassen sich überspringen. Neu laden setzt an derselben Stelle fort —
   aber nur im selben Browser: ein Gerätewechsel beginnt mit einem neuen Namen
   bei Frage 1.
5. **Beamer:** offen das Rennen (wie viele bei welcher Frage, wie viele fertig),
   in den ersten zwei Minuten mit großem Beitrittsblock, danach bei Rückmeldung
   je Frage die besten acht; geschlossen nur der Zähler; freigegeben der
   Endstand.

Grenzen: höchstens 300 Personen je Raum, 120 Beitritte je Adresse und Raum in
zehn Minuten. Übergabe mit Prüfwegen und offenen Punkten:
[`docs/pace-2026-09/UI.md`](docs/pace-2026-09/UI.md).

## Architektur

- **Öffentlicher Zugang** ohne Login über `#[PublicPage]` + `#[NoCSRFRequired]` —
  derselbe Mechanismus wie Freigabe-Links. Der Raumcode ist das Freigabe-Token:
  `/apps/pulse/s/{code}`.
- **Kernentscheidung Stimmenzählung:** eine DB-Zeile pro `(poll_id, voter_token)`
  (`UNIQUE`-Index), ausgezählt per `COUNT`/Aggregat — kein gemeinsamer Zähler.
  Re-Vote = Upsert (last-write-wins pro Person). Aus dem Ursprungs-Artefakt so
  übernommen, damit gleichzeitige Stimmen nicht verloren gehen.
- **Realtime:** Polling (~2,5 s) per JSON. Kein High-Performance-Backend nötig.

## Struktur

```
lib/Controller/   PageController (Moderator-SPA), RoomApiController (Moderator-API),
                  PublicController (Teilnehmer-Seite), PublicVoteController (öffentl. API)
lib/Db/           Room, Poll, Vote, Player, Presence, Progress + QBMapper
lib/Service/      RoomService (Räume), DeckService (Fragen), VoteService (Stimmen),
                  StateService (Lesesichten + CSV), TallyService (Auszählung),
                  QuizService (Punkte), PollImageService (Bilder), DemoService, CodeGenerator,
                  PaceService + PaceStateService (Quiz im eigenen Tempo: Fenster, Lesesichten)
lib/Migration/    Schema (pulse_rooms, pulse_polls, pulse_votes, … pulse_progress)
src/              Moderator.vue (Steuerpult), Participant.vue (Handy), Screen.vue (Beamer),
                  components/StageRow.vue (Bühnen-Zeilenmodell), ResultsView.vue,
                  eigenes Tempo: components/Pace*.vue (Moderator), StageRace.vue (Beamer),
                  mixins/pace-phone.js (Handy), util/pace.js (reine Regeln, ohne Browser),
                  styles.js (Token-Schicht ohne Vue — für die Einbett-Shell)
l10n/             Übersetzungen (de.json wird gepflegt, de.js daraus erzeugt)
build/            l10n-build.js (json -> js), l10n-check.js (Abdeckung),
                  package.sh (Release-Archiv), certificate.sh (Signatur-Schlüssel)
tests/            PHPUnit (datenbankfrei) + run.sh; DB-Wege decken _*.php ab
office-addin/     PowerPoint-Add-in (Anleitung, Traefik-Beispiel; das Manifest
                  erzeugt die Instanz selbst)
dev/design-shots/ Screenshot-Prüfstand für Design-Durchgänge (headless Firefox)
.github/          CI: Bundle, PHPUnit, Übersetzungen, info.xml-Schema
```

## Bauen

```
npm install
npm run build      # erzeugt js/pulse-main.js und js/pulse-public.js
```

Danach in Nextcloud aktivieren:

```
occ app:enable pulse
```

## Sprachen

Quellsprache ist **Englisch** — im Code steht der englische Text, die Übersetzung
kommt aus `l10n/`. So zeigt die App außerhalb des deutschsprachigen Raums nicht
plötzlich deutsche Knöpfe, und weitere Sprachen brauchen keine Code-Änderung.

```
PHP:  $this->l10n->t('Room not found.')          // IL10N in den Konstruktor
      $this->l10n->n('%n vote', '%n votes', $x)
Vue:  {{ t('pulse', 'Save') }}                    // Vue.prototype.t/n aus main.js
      {{ n('pulse', 'answer', 'answers', total) }}
      t('pulse', 'Hello {name}', { name })        // Platzhalter in geschweiften Klammern
```

Gepflegt wird nur `l10n/de.json`; `l10n/de.js` (die Datei, die Nextcloud vor das
Bundle hängt) entsteht daraus:

```
npm run l10n         # de.json -> de.js
npm run l10n:check   # meldet fehlende und verwaiste Übersetzungen, Exit 1 bei Lücken
```

Mehrzahl steht unter dem zusammengesetzten Schlüssel `_Singular_::_Plural_` mit
einem Array als Wert — dieselbe Regel in PHP und JS. Zahlen werden in der Sprache
der Oberfläche formatiert (`fmtNum()` im Frontend, `NumberFormatter` im CSV-Export);
ein fest verdrahtetes Komma wäre in einer englischen Oberfläche eine andere Zahl.

## Datenmodell

```
Poll  = { id, type, question, options, answerKey, maxWords, timeLimit, image, status }

# options trägt je nach Typ etwas anderes (JSON in einer Spalte):
choice/multi/rank/truefalse -> [{id,label}, …]
scale                       -> {mode, min, max, aspects|axisX/axisY, …}   -> getScaleConfig()
match                       -> {items:[{id,label}], targets:[{id,label}]} -> getMatchConfig()

# answerKey ist die Quiz-Lösung (nie vor dem Auflösen an Teilnehmer):
multi {correct:[id]} · rank {order:[id]} · match {map:{itemId:targetId}}
number {target,tolerance} · text {accepted:[],rejected:[]}

Vote  = { value: "<optionId>" }                  # choice/truefalse
Vote  = { value: ["wort", "wort"] }              # words (kleingeschrieben beim Auszählen)
Vote  = { value: ["id","id"] }                   # rank (vollständige Permutation)
Vote  = { value: {"<itemId>": "<targetId>"} }    # match (jede Zeile zugeordnet)
```

## Tests

```
tests/run.sh          # PHPUnit im Nextcloud-Container (siehe tests/README.md)
npm run l10n:check    # Übersetzungs-Abdeckung, Exit 1 bei Lücken
TZ=Europe/Berlin node dev/unit/pace.test.mjs   # Regeln des eigenen Tempos (Fristen, Handy-Karten, Takt)
dev/sim/run.sh        # Durchgangs-Simulation gegen die laufende Instanz (dev/sim/README.md)
```

Screenshots aller Ansichten (Beamer, Handy, Moderator — je Fragetyp, offen und
aufgelöst, hell und dunkel) nimmt `dev/design-shots/run.sh` automatisch auf;
siehe `dev/design-shots/README.md`. Der Lauf misst dabei jede Beamer-Bühne und
meldet, wenn Inhalt über den geclippten Kiosk hinausläuft — das sieht man auf
einem Standbild sonst nur, wenn man es sucht.

Übergaben, Umsetzungsstand und Bilder der Design-Durchgänge vom Juli 2026 sind
nicht Teil des öffentlichen Repositorys.

Die Unit-Suite bleibt datenbankfrei (Wertung, Auszählung, Textnormalisierung,
Parameter-Durchreichung). Alles, was wirklich in die DB greift, prüfen die
Wegwerf-Harnesse `_*.php` im App-Verzeichnis — beschrieben in `tests/README.md`.

## In PowerPoint einbetten

Eigenes Content-Add-in (Sideload, kein Office-Store). Das Office-Manifest liefert
die Instanz selbst aus — `/apps/pulse/addin/manifest.xml`, im Deck-Menü als
„PowerPoint-Add-in". Es trägt die Adresse, unter der es geladen wurde; ein
Office-Manifest kennt keine Variablen, eine mitgelieferte Datei müsste also jede
Installation von Hand anpassen. Anleitung: `office-addin/README.md`.

**Achtung:** Nextcloud sendet `X-Frame-Options: SAMEORIGIN` aus `.htaccess` und
`lib/base.php` — eine App kann das nicht überschreiben. Für `/apps/pulse/screen/`
und `/apps/pulse/embed` muss der Header am Webserver oder Reverse-Proxy fallen;
Schnipsel für Apache, nginx und Traefik stehen in `office-addin/README.md`. Ob er
noch steht, meldet die Einrichtungsprüfung „Pulse: Einbetten in PowerPoint" unter
Verwaltung → Übersicht, getrennt nach Adresse.

## Release

```
build/certificate.sh    # einmalig: Schlüssel + Zertifikatsanfrage für die Signatur
build/package.sh        # baut, prüft, signiert und packt build/pulse-<version>.tar.gz
```

Der ganze Weg in den Nextcloud App Store — Zertifikat, Version, Signatur,
Upload — steht in [`docs/APPSTORE.md`](docs/APPSTORE.md).

## Lizenz

AGPL-3.0-or-later, siehe [`LICENSE`](LICENSE). Jede Quelldatei trägt den
passenden SPDX-Kopf.

## Noch offen / mögliche Erweiterungen

- App Store: Zertifikat beantragen und Screenshots aufnehmen — die beiden
  letzten Handgriffe, Checkliste in `docs/APPSTORE.md`. Weitere Sprachen liefen
  über Transifex und brauchen Nextclouds Zutun.
- Getestet ist die App auf Nextcloud 34; `info.xml` bewirbt ab 29.
- Fragetyp Bild-Hotspot (auf ein Bild tippen statt Antwort wählen).
- Quiz im eigenen Tempo: Wiedereinstiegscode für den Gerätewechsel, Start aus
  dem Wartezustand ohne Tipp, Demo-Rennen und Store-Bilder; dazu kleine
  Engine-Folgepunkte (Liste in `docs/pace-2026-09/UI.md`).
- Echtes Push statt Polling — bräuchte einen eigenen WebSocket-Dienst;
  `notify_push` scheidet aus, weil Teilnehmende anonym und nicht angemeldet sind.
- Ballot-Stuffing: ohne Konto bleibt das Voter-Cookie die einzige Schranke —
  dokumentierte Grenze, nicht behebbar ohne Anmeldung.
