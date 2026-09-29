# Redesign-Brief Pulse — Übergabe an den Design-Lauf

**Stand:** 26.07.2026 · App-Version 0.16.0 · Cachebuster 100 · Commit siehe `git log`
**Bildmaterial:** 30 Screenshots (nicht im öffentlichen Repository) aus dem automatischen
Prüfstand (`dev/design-shots/run.sh`), aufgenommen im echten Betrieb mit 24
Stimmen je Frage, englische Oberfläche, Beamer 1920×1080, Handy 390×844,
Moderator 1440×900.

**Auftrag:** Redesign der Anzeige-Ebenen (Beamer, Handy, Moderator-Präsentation).
Zurück kommt eine **Umsetzungs-Übergabe**, die ein Entwickler ohne Rückfragen
bauen kann. Was dafür drinstehen muss, steht unten unter *Rückgabe-Format*.

---

## 1. Was Pulse ist

Native Nextcloud-App für Live-Umfragen und Quizze in Vorträgen. Die vortragende
Person projiziert einen 6-stelligen Code, das Publikum tritt **ohne Konto** über
einen Link bei und stimmt vom eigenen Handy ab. Drei Oberflächen laufen
gleichzeitig:

| Oberfläche | Wer schaut | Entfernung | Wie lange im Blick |
|---|---|---|---|
| **Beamer** `/screen/{code}` | ganzer Saal | 3–15 m | dauerhaft |
| **Handy** `/s/{code}` | einzelne Person | 30 cm | 10–60 s je Frage |
| **Moderator** `/apps/pulse/` | vortragende Person am Laptop | 60 cm | dauerhaft |

Zwei Raum-Modi: **Umfrage** (anonym, kein Richtig/Falsch) und **Quiz** (Nickname,
Countdown, Punkte, Rangliste).

Neun Fragetypen: Multiple Choice · Wortwolke · Skala (Einzel-Slider, Spektrum-
Radar, Kompass-2D-Feld) · Reihenfolge · Zuordnung · Wahr/Falsch · Mehrfachauswahl
· Schätzfrage · Freitext. Jede Frage kann ein **Bild** tragen.

Je Frage gibt es zwei Bühnen: **offen** (Stimmen laufen ein) und **aufgelöst**
(Ergebnis/Lösung). Am Quiz-Ende kommt eine dritte: **Endstand**.

---

## 2. Bildmaterial — Inventar

Die Bilder selbst sind nicht im öffentlichen Repository; das Inventar bleibt als
Schlüssel zu den Belegen der Befunde stehen.

### Beamer

| Datei | Bühne |
|---|---|
| `beamer-01-lobby.png` | Lobby: Titel, QR, Code |
| `beamer-02-choice-offen.png` | Multiple Choice offen (**mit Bild**) |
| `beamer-03-choice-aufgeloest.png` | Multiple Choice aufgelöst |
| `beamer-04-wortwolke.png` | Wortwolke |
| `beamer-05-skala-einzel.png` | Skala Einzel-Slider, Verteilung |
| `beamer-06-spektrum-DEFEKT.png` | Spektrum-Radar — **Radar fehlte** (Firefox-Bug, gefixt) |
| `beamer-07-spektrum-nach-fix.png` | dasselbe nach dem Fix — so sieht es aus |
| `beamer-08-kompass-DEFEKT.png` | Kompass — **Feld fehlte**, gleicher Bug, gefixt |
| `beamer-09-reihenfolge.png` | Reihenfolge, Konsens |
| `beamer-10-zuordnung-umfrage.png` | Zuordnung Umfrage |
| `beamer-11-zuordnung-quiz-offen.png` | Zuordnung Quiz offen (Chip-Wolke rechts) |
| `beamer-12-zuordnung-aufgeloest.png` | Zuordnung aufgelöst |
| `beamer-13-schaetzfrage-wartet.png` | Schätzfrage offen — **leere Bühne** |
| `beamer-14-freitext-wartet.png` | Freitext offen — **leere Bühne** |
| `beamer-15-endstand.png` | Endstand mit Podium |
| `beamer-16-dunkel.png` | Multiple Choice im Dunkelmodus |

### Handy

| Datei | Bühne |
|---|---|
| `handy-01-beitritt.png` | Code eingeben |
| `handy-02-choice-abstimmen.png` | Choice mit Bild — Optionen unter der Falz |
| `handy-03-nach-aufloesung-pause.png` | nach dem Auflösen einer **Umfrage**: „Short break" |
| `handy-04-nickname.png` | Quiz: Namen eingeben |
| `handy-05-quiz-choice.png` | Quiz-Frage mit Countdown |
| `handy-06-quiz-aufloesung.png` | Quiz-Auflösung mit eigenem Platz |
| `handy-07-kompass.png` | Kompass-Eingabe, Absenden unter der Falz |
| `handy-08-zuordnung-selects.png` | Zuordnung: **nackte native Selects** |
| `handy-09-reihenfolge.png` | Reihenfolge sortieren |
| `handy-10-quiz-ende.png` | nach Quiz-Ende: zeigt die letzte Frage, nicht den Endstand |

### Moderator und Einbettung

| Datei | Bühne |
|---|---|
| `moderator-01-start.png` | Startbildschirm mit „Meine Räume" |
| `moderator-02-praesentation.png` | Präsentationsansicht (Frage läuft) |
| `moderator-03-deck.png` | Deck-Liste |
| `embed-01-shell-deutsch.png` | PowerPoint-Einbett-Shell — **hart auf Deutsch** |

---

## 3. Befunde

Priorisiert. **B** = Bug (kaputt), **D** = Designschwäche.

### Bugs, die im Redesign mitgelöst gehören

| # | Befund | Beleg |
|---|---|---|
| B1 | **Endstand schneidet die Plätze 4–8 ab.** Inhalt ist 1324 px hoch, der Kasten 636 px, `overflow: hidden`. Sichtbar sind nur Titel und Podium; die Liste darunter fällt weg. Die Schrumpf-Schleife (`fitResults`) greift nicht, weil das Podium in px statt em rechnet. | `beamer-15-endstand.png` |
| B2 | **Endstand trägt weiter die Überschrift der letzten Frage** („Which port does HTTPS use by default?" über „Final standings"). | `beamer-15-endstand.png` |
| B3 | **Einbett-Shell ist hart auf Deutsch** (`js/pulse-embed.js`: „Raumcode eingeben…", „Anzeigen", „Code ändern") — die App ist sonst englischsprachig mit `l10n/`. | `embed-01-shell-deutsch.png` |
| B4 | *(erledigt 26.07.)* Radar und Kompassfeld waren am Beamer **unsichtbar**: `width: auto` an einem Flex-SVG löst Firefox zu 0 auf. Gefixt mit `aspect-ratio`. Die Defekt-Bilder liegen als Beleg bei. | `beamer-06/08-*-DEFEKT.png` |

### Durchgehende Muster am Beamer

| # | Befund |
|---|---|
| D1 | **Leerraum unten.** Bei fast jeder Bühne bleiben 15–45 % der Fläche ungenutzt; die Inhalte kleben oben. Am schlimmsten Skala-Einzel (`beamer-05`), Spektrum (`beamer-07`), Reihenfolge (`beamer-09`). Auf einer Leinwand ist das verschenkte Lesbarkeit. |
| D2 | **Das Bild ist eine Briefmarke.** `.scr-img` hängt mit `max-height: 22vh; max-width: 28vw` links neben der Frage in der Kopfzeile und drückt die Frage aus der Mitte. Bei einer Bildfrage ist das Bild der Inhalt, nicht Beiwerk. |
| D3 | **Nextcloud-Rahmen auf der Leinwand.** Blaue NC-Kopfleiste, blauer Rand, weiße Karte darin. Kein Vollbild-Kiosk; die App-Chrome lenkt ab und kostet oben 50 px. |
| D4 | **Weiter Augenweg.** Label steht links, Zahl 1800 px weiter rechts (`beamer-03`, `beamer-09`, `beamer-12`). Auf Entfernung verliert man die Zeile. |
| D5 | **Meta-Angaben in zwei Ecken.** „100 % = all votes" links unten, „24 votes" rechts unten, beide klein und grau. |
| D6 | **Leere Wartebühnen.** Schätzfrage und Freitext zeigen während der ganzen Antwortzeit nur ein Stift-Icon und „Enter your answer on your phone" (`beamer-13`, `beamer-14`) — obwohl Antworten längst eintrudeln. |
| D7 | **Der Countdown ist das lauteste Element** der Quiz-Bühnen: fetter blauer Vollbreiten-Balken über allem. |
| D8 | **Richtig/falsch ist zu leise.** In der Quiz-Auflösung markiert nur ein kleiner grüner Haken die Lösung; alle Balken bleiben gleich blau (`beamer-12`). Auf 10 m sieht man nicht, was stimmte. |
| D9 | **Wortwolke: die Hälfte der Wörter steht senkrecht** (`beamer-04`). Sieht gut aus, liest sich aus dem Saal schlecht. Zähler „·3" sind winzig und grau. |
| D10 | **Dunkelmodus:** hochgeladene Bilder behalten ihren weißen Hintergrund und leuchten als weißer Block (`beamer-16`); Prozentzahlen verlieren Kontrast. |
| D11 | **Zuordnung offen:** linke Spalte oben ausgerichtet, rechte Chip-Wolke vertikal zentriert — die Bühne wirkt schief, rechts bleibt viel Luft (`beamer-11`). |

### Handy

| # | Befund |
|---|---|
| D12 | **Bild frisst die Falz.** Bei einer Bildfrage nimmt das Bild ~35 % der Höhe, die Antwortknöpfe rutschen unter die Falz (`handy-02`). Genau umgekehrt zum Beamer — dort zu klein, hier zu groß. |
| D13 | **Zuordnung am Handy sind vier nackte `<select>`** (`handy-08`) — Systemoptik, kein Design-System, kein Zusammenhang zwischen Zeile und Ziel sichtbar. Schwächste Ansicht der App. |
| D14 | **Kompass:** „Absenden" liegt unter der Falz, Achsentitel stehen 90° gedreht in Kleinstschrift, die Zahlenanzeige „PACE 0 · SCOPE 0" ist ohne Kontext kryptisch (`handy-07`). |
| D15 | **Umfrage-Auflösung ist eine Sackgasse:** nach dem Auflösen zeigt das Handy „Short break — back in a moment" (`handy-03`), obwohl der Beamer gerade das Ergebnis zeigt. Wer nicht zur Leinwand schaut, sieht nichts. |
| D16 | **Quiz-Ende zeigt die letzte Frage** statt „Dein Platz / Endstand" (`handy-10`). |
| D17 | **Antwortkarten wirken leer:** kleine Schrift links in einer breiten weißen Karte (`handy-05`); dem Quiz fehlt die Kahoot-Griffigkeit (große farbige Flächen, Fingerziel). |
| D18 | **Das eigene Ergebnis steht unter den Balken.** „Place 6 · 0 points" ist die Information, die die Person zuerst sucht, und liegt unter der Verteilung (`handy-06`). |

### Moderator

| # | Befund |
|---|---|
| D19 | Startbildschirm: viel Leerraum oben, der Umschalter Umfrage/Quiz ist blass (inaktive Seite kaum lesbar), „Meine Räume" fängt unter der Falz an (`moderator-01`). |
| D20 | Präsentationsansicht: die Stimmenzahl steht als riesige Zahl mitten im Leeren, rechts nimmt die QR-Spalte ein Drittel der Breite dauerhaft (`moderator-02`). Der Moderator braucht Bild, Frage, Stand und die nächsten Knöpfe — nicht dreimal denselben Code. |

### Was gut ist und bleiben soll

- Balken-Auflösung: Buchstaben-Badge, Farbfolge, „leading"-Kennzeichnung.
- Wortwolken-Engine (Fit-Schleife, „+N weitere").
- Podium und Rangliste am Quiz-Ende, inklusive Gleichstands-Behandlung.
- Nickname-Einstieg und Code-Eingabe am Handy: ruhig, ein Ziel je Bildschirm.
- Reihenfolge am Handy (nummerierte Karten, Hoch/Runter zusätzlich zum Ziehen).
- Farb- und Kontrastarbeit im hellen Modus.

---

## 4. Ziele des Redesigns

1. **Fernlesbarkeit vor Dichte.** Alles auf der Leinwand muss aus 10 m lesbar
   sein. Im Zweifel weniger zeigen, dafür größer.
2. **Die Bühne füllen.** Kein Layout, das unten ein Viertel leer lässt. Inhalt
   skaliert in die verfügbare Höhe (die Schrumpf-Schleife gibt es schon).
3. **Bildfragen bekommen eine eigene Bühnenaufteilung** — auf dem Beamer groß,
   am Handy so, dass die Antwortknöpfe über der Falz bleiben.
4. **Keine leere Bühne, während Antworten laufen.** Auch Schätzfrage und
   Freitext brauchen etwas Lebendiges (Eingangs-Ticker, anonyme Zähler,
   Live-Verteilung ohne Lösung).
5. **Ein Ort für Meta-Angaben** statt zweier Ecken.
6. **Richtig/falsch muss aus dem Saal erkennbar sein**, ohne die Lösung vor dem
   Auflösen zu verraten.
7. **Am Handy ist die Aktion das Wichtigste.** Antworten ohne Scrollen; nach dem
   Absenden eine klare Rückmeldung; nach dem Auflösen das eigene Ergebnis zuerst.
8. **Dunkelmodus gleichwertig**, inklusive Umgang mit hellen Bildern.

## 5. Leitplanken — was nicht verhandelbar ist

**Technik**

- **Vue 2.7 + `@nextcloud/vue` 8**, Webpack, keine neuen Laufzeit-Abhängigkeiten.
  Kein Vue 3, kein Chart-Framework, kein CSS-Framework. Visualisierungen sind
  handgeschriebenes SVG bzw. eine eigene Engine.
- **Farben kommen aus der Token-Schicht** (`src/styles/pulse-tokens.css`), die an
  Nextcloud-Variablen hängt (`--color-primary` usw.) — die App folgt dem Theme
  des Servers. Neue feste Farben nur als begründete Ausnahme (bisher: Antwort-
  Palette A–H, Quiz-Akzent, QR bleibt fix schwarzweiß).
- **Ein Button-System:** `.pulse-btn` mit Varianten (`src/styles/pulse-ds.css`).
  Kein zweites einführen. Auf der angemeldeten Moderator-Seite brauchen die
  formgebenden Regeln `!important`, weil Nextcloud native `<button>` global
  stylt.
- **WCAG AA** für Text und Bedienelemente, hell und dunkel. Icon-Knöpfe haben
  Namen, Statuswechsel sind `role="status"`, alles ist mit der Tastatur
  bedienbar — das gilt weiter.
- **Quelltexte sind Englisch**, Übersetzungen liegen in `l10n/`. Neue Texte
  englisch schreiben, Platzhalter in geschweiften Klammern.

**Fallen, die schon einmal Zeit gekostet haben**

- `container-type: size` an einer Kachel ⇒ die Kachel trägt **nichts** zur Höhe
  ihres Containers bei. Ein Raster mit `grid-template-rows: auto` bleibt dann
  unsichtbar. Höhen müssen von außen kommen (`1fr`, `min-height: 0`).
- SVG in einem Flex-Item braucht eine **feste Breitenquelle** (`aspect-ratio`);
  `width: auto` + `height: 100%` liefert in Firefox Breite 0.
- DOM, das per JavaScript erzeugt wird (Wortwolke), trägt **kein** `data-v-…` →
  scoped CSS greift dort nicht.
- Der Beamer ist ein **geclippter Kiosk**: kein Scrollen, `overflow: hidden`.
  Was nicht passt, wird nicht gescrollt, sondern skaliert (`fitResults`).

**Nicht anfassen**

- Datenmodell, Routen, Auszählung, Punktevergabe.
- Das serverseitige Reveal-Gate: Lösungen und Rangliste gehen erst bei
  `status ∈ {locked, ended}` an Teilnehmer. Kein Layout darf etwas anzeigen, was
  der Server noch nicht ausgeliefert hat.
- Der Beamer bleibt **rein lesend** und spiegelt nicht die Moderator-Ansicht.

## 6. Rückgabe-Format — was die Umsetzungs-Übergabe enthalten muss

Die Übergabe geht an einen Entwickler (Claude Code), der den Code kennt, aber
nicht im Design-Lauf dabei war. Sie sollte enthalten:

1. **Je Bühne eine Spezifikation** (Beamer offen/aufgelöst je Fragetyp, Handy je
   Fragetyp und Zustand, Moderator, Lobby, Endstand): Aufteilung, Größen-
   verhältnisse, was bei 2 und was bei 8 Optionen passiert, was bei sehr langem
   Text passiert.
2. **Verbindliche Werte** statt Adjektive: Raster, Abstände, Schriftgrößen als
   `clamp()`, Mindest- und Höchstgrößen, Zeilenlängen.
3. **Token-Änderungen** benannt (`--pulse-*`), wenn neue Rollen nötig sind —
   nicht rohe Hex-Werte im Layout.
4. **Zustände und Grenzfälle:** null Stimmen, eine Stimme, Gleichstand, sehr
   lange Labels, fehlendes Bild, sehr hohes/breites Bild, Probelauf-Kennzeichen,
   „Auflösung am Ende"-Modus.
5. **Reihenfolge der Umsetzung** in Schritten, die einzeln lauffähig und
   überprüfbar sind (der Prüfstand schießt nach jedem Schritt neue Bilder).
6. **Abnahmekriterien je Schritt:** woran man im Screenshot sieht, dass es sitzt.
7. **Ausdrücklich, was unverändert bleibt** — damit nicht versehentlich
   funktionierende Bühnen umgebaut werden.

Was **nicht** gebraucht wird: fertige Mockups als Pixelvorlage, neue
Markenidentität, Farbwechsel weg von den Nextcloud-Variablen.

## 7. So sieht man das Ergebnis

```sh
dev/design-shots/run.sh      # ~57 Screenshots aller Ansichten, ~4 Minuten
```

Nimmt Beamer, Handy, Moderator, hell und dunkel, je Fragetyp offen und aufgelöst
auf und legt sie in `dev/design-shots/out/` ab. Der Lauf baut seine Testdaten
selbst und räumt sie hinterher weg. Details in `dev/design-shots/README.md`.
