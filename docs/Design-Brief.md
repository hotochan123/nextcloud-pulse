# Pulse — Design-Brief für open-design

**Für:** open-design (Design-Generator) · **Produkt:** Pulse, eine Nextcloud-App für Mentimeter/Kahoot-artige Live-Umfragen & -Quizze · **Stand:** 2026-07-17 · **Version:** 0.10.0

> **Wie du das hier nutzt:** Du siehst den Code der App **nicht** — du arbeitest visuell. Grundlage ist dieser Brief **plus die Screenshots** aus der Checkliste (§8). Liefere Design-Konzepte (Layout, Hierarchie, Komponenten, Iconografie, Microcopy, Skalierung), **kein Code**. Die technische Umsetzung übernimmt danach eine Entwickler-Sitzung.

> **Das Besondere an Pulse: drei sehr unterschiedliche Oberflächen.** Anders als eine normale App bespielt Pulse gleichzeitig (1) das **Handy** des Publikums (mobil, ohne Konto, in Sekunden), (2) den **Beamer** im Raum (Projektion, aus Distanz lesbar) und (3) das **Steuerpult** der vortragenden Person (Desktop, aber live vor Publikum). Jede Fläche hat eigene Gesetze — das prägt den ganzen Brief.

---

## 1. Was Pulse ist — und was es *fühlen* soll

Jemand hält einen Vortrag oder Workshop und will das Publikum einbeziehen: eine Frage projizieren, alle stimmen vom Handy ab, das Ergebnis erscheint live auf dem Beamer. Pulse macht das **ohne Konto** für die Teilnehmenden — ein 6-stelliger Raumcode ist der ganze Zugang.

Es gibt **zwei Modi**:
- **Umfrage** (`poll`) — anonym, Mentimeter-artig. Fragetypen: Multiple Choice, Wortwolke, Skala/Rating. Ergebnisse live sichtbar.
- **Quiz** (`quiz`) — Kahoot-artig, mit Wertung. Teilnehmende treten mit **Nickname** bei (Pseudonym, kein Konto), Fragen haben eine richtige Antwort + Zeitlimit, es gibt Punkte, Rangliste und Podium. Fragetypen: Multiple Choice, Wahr/Falsch, Mehrfachauswahl, Schätzfrage, Freitext.

**Die drei Gefühle, die das Design tragen soll — eins je Fläche:**
- **Lebendig & einladend (Handy/Publikum).** Mitmachen soll sich leicht und lebendig anfühlen — Kahoot-Energie, aber ohne Kitsch, und sauber in Nextcloud eingebettet. Der Weg „Code rein → mitmachen" darf keine Sekunde Reibung haben.
- **Bühnentauglich & lesbar (Beamer).** Die Projektion muss aus der **letzten Reihe** lesbar sein, eine klare Farb-Identität je Antwortoption tragen, und der Auflösungs-/Feier-Moment (Ergebnis, Podium) muss **groß** sein.
- **Souverän & fehlerfest (Steuerpult).** Die vortragende Person steuert live vor Menschen. Das Pult muss ruhig, selbsterklärend und **spoiler-/fehlklick-sicher** sein — kein versehentliches „Beenden", keine geleakte Lösung.

---

## 2. Gestaltungs-Rahmen (bitte einhalten)

- **Nextcloud-Designsystem.** Die App lebt *innerhalb* von Nextcloud. Nutze NC-Look-and-feel und NC-Design-Tokens (§3), funktionierend in **Light- und Dark-Theme** — beide immer mitdenken, Farben aus Theme-Variablen, nicht aus festen Hex-Werten. **Ausnahme:** die Antwort-Farbpalette und der QR-Code sind bewusst theme-**unabhängig** (Scanbarkeit, wiedererkennbare Optionsfarben) — dafür eigene, kontrastgeprüfte Festwerte.
- **Drei Viewports statt einem.** (a) **Handy ~320–390 px** — mobile first, das ist die Publikumsfläche. (b) **Beamer** — großes Display, Betrachtung aus mehreren Metern, oft in abgedunkeltem Raum; hier zählt Fernlesbarkeit und Kontrast, nicht Informationsdichte. (c) **Moderator-Desktop** — aber unter Live-Druck bedient. Heute existiert **fast kein** Breakpoint (ein einziger `@media` im Moderator bei 880 px, **keiner** auf Handy/Beamer) — jeder Screen braucht bewusste Varianten.
- **Barrierefreiheit (WCAG AA):** Textkontrast ≥ 4,5:1; **Farbe nie als einziger Informationsträger** — betrifft bei Pulse besonders **Gewinner/richtige Antwort, Timer-Dringlichkeit und Status** (heute oft rein farblich); sichtbare Fokuszustände; Touch-Ziele ≥ 44 px; jedes Bedienelement mit sichtbarer Beschriftung.
- **Tonalität:** Deutsch, durchgängig **Du** (ist bereits konsistent — bitte halten), **geschlechtergerecht** („Teilnehmende" statt „Teilnehmer" — heute noch gemischt).
- **Bewegung sparsam** und `prefers-reduced-motion` respektieren (heute überwiegend abgedeckt — Niveau halten). Der Quiz-Modus darf punktuell feiern (Podium), aber die Arbeits-Screens bleiben ruhig.

---

## 3. Aktuelles Marken-/Token-Vokabular (Ist-Zustand)

Die App erbt das Nextcloud-Theme. Das ist die Palette, in der du dich bewegst — und die Liste ihrer **Inkonsistenzen**, die du auflösen sollst.

**Genutzte Tokens (NC-Theme):** `--color-main-background/-text`, `--color-text-maxcontrast`, `--color-background-hover/-dark`, `--color-border/-dark`, `--color-primary-element(-text/-hover/-light)`, `--color-success`, `--color-warning(-text)`, `--color-error(-text)`, Radius `--border-radius(-element/-container/-large/-pill)`, `--font-face`. Grundsätzlich ist **fast alles tokenbasiert** (theme-/dark-fähig) — das ist gut und soll bleiben.

**Visuelle Schulden, die du konsolidieren sollst:**

1. **Ein Chip/Badge-System.** Pill-Badges sind vielfach von Hand gebaut und driften: Status-Tags (`.tag--live/--locked/--revealed`), Nav-Pills (`.nav-present/-practice/-reveal`), Punkt-Indikatoren (`.myroom-live` „● läuft" vs. `.conn` „● live/offline"), Typ-Labels (`.deck-type` vs. `.summary-type` — gleiche Rolle, zwei Selektoren), Code-Badge, Probelauf-Tag. Zwei Bau-Stile mischen sich: **solid** (Primärfläche, weißer Text) vs. **soft** (`color-mix(… 16–20 %)`-Tönung). → Entwirf **eine** Chip-Familie mit klaren Varianten (neutral / Status / Erfolg / Fehler / hervorgehoben) und **einem** Radius-Token. Achtung: die soft-Variante nutzt `color-mix()` ohne Fallback → auf alten Mobil-Browsern verschwindet die Fläche.

2. **Ein Icon-System.** Heute **drei** parallele Sprachen und **kein** SVG-/NC-Icon-Set:
   - **Emoji** (OS-abhängig gerendert, nicht NC-flach): 📊 🎯 🧪 🏁 🖥️ 🏆 👁 🙈 ⬇ 🥇🥈🥉 🎉 😕 👋 🤔 ⏱️ ⏸️ ✍️
   - **Text-Glyphen:** ◀ ▶ ✓ ✕ ✎ ⠿ (Ziehen) ● + ⛶ (Vollbild — seltenes Unicode, **Tofu-Risiko**)
   - **SVG:** nur das animierte Bestätigungs-Häkchen (Handy) und der QR.
   - Folge: „**richtig/Erfolg**" erscheint in **vier** Darstellungen (`✓`-Glyphe, `🎉`-Emoji, SVG-Häkchen, `🥇`-Medaille), „**Sieger**" in zwei (`★` und `🥇`). → **Ein** durchgängiges Icon-Set (Material-Stil passend zu NC); Emoji höchstens als bewusster Deko-Akzent, nicht als Bedeutungsträger. `🙈` ist zudem die falsche Metapher fürs Verbergen der Lösung.

3. **Ein Dialog-/Notice-Muster.** Zerstörerische Bestätigungen laufen heute über das **native `window.confirm()`** (Raum löschen, zurücksetzen, Probelauf umschalten, Frage löschen) — bricht die Theme-Optik komplett. Fehler kommen per Toast, Inline-Hinweise in **drei** leicht verschiedenen Stilen (Banner / Warnzeile / grauer Hint), Erfolgs-Feedback fehlt als Muster. → **Ein** konsistentes Set: themebarer Bestätigungsdialog (mit ernster Variante für Destruktives), Info-Notice, Warn-Notice, transientes Erfolgs-Feedback.

4. **Semantische Farben aus Tokens, Fallback-Drift auflösen.** Dieselbe Variable hat je nach Fundstelle unterschiedliche Hardcode-Fallbacks — das musst du zu **je einem** Wert vereinheitlichen:
   - `--color-text-maxcontrast`: `#767676` / `#999` / `#555`
   - `--color-error`: `#c33` / `#c73e3e` (Toast)
   - `--color-warning`: `#e0a800` / `#e9a800`
   - `--border-radius-element`: 8 / 10 / 12 / 14 px (vier Werte!)
   - Fixe Sonderwerte: `#201800` (Quiz-Button-Text auf Gelb), diverse `#fff` auf Farbflächen (Toast-Text `#fff` hartkodiert → Kontrastrisiko, wenn `--color-error` hell rendert).

5. **Eine Typo-Skala.** Es gibt **keine** — ~14 diskrete Größen (12–30 px) mit vielen Beinah-Dubletten (13/14/15, 18/19/20, 22/24/26) plus einige `clamp()` auf dem Beamer. Gewichte 400–900 gemischt (das einzige 900er ist der Korrekt-Marker). → Definiere eine **kleine, klare Stufenskala** (z. B. 5–6 Stufen), separat für Handy/Moderator (kompakt) und Beamer (fern-lesbar).

6. **Die Antwort-Farbpalette (Pulse-spezifisch, wichtig).** Live-Optionen erscheinen auf dem Beamer als vollfarbige Kacheln A/B/C… aus dieser 8er-Palette mit **weißer** Schrift:
   `#E15A4C · #2D7DD2 · #2E9E5B · #E5A100 · #8E44AD · #16A2B8 · #D6336C · #495057`
   Zwei Probleme: **Weiß auf `#E5A100` (Amber) und `#16A2B8` (Türkis) liegt unter AA** — genau diese Kacheln brechen aus der Distanz weg (der Buchstabe hat zusätzlich `opacity 0.85`). Und: **diese Farb-Identität geht bei der Auflösung verloren** — die Ergebnis-Balken sind alle Primärblau, das Publikum kann „die rote Option A" nicht mehr ihrem Balken zuordnen. → Entwirf **eine** kanonische, AA-sichere Optionspalette (Textfarbe pro Farbe geprüft, ggf. dunkler Text auf hellen Kacheln), die **von der Live-Kachel bis in den Ergebnis-Balken durchgezogen** wird.

---

## 4. Die Screens (Ist → Problem → Ziel)

Gegliedert nach den drei Flächen. Screenshots dazu siehe §8.

### Fläche A — Steuerpult (Moderator, Desktop unter Live-Druck)

#### 4.1 Start / „Meine Räume"
- **Ist:** Zentrierte Landing (Eyebrow „Pulse", große Headline, zwei Modus-Buttons 📊 Umfrage / 🎯 Quiz), darunter die Raumliste als Karten (Modus-Emoji, gespaceter Code, „● läuft", Meta, Lösch-✕).
- **Probleme:** Zwei konkurrierende Primär-CTAs ohne Hierarchie; der Quiz-Button nutzt die **Warnfarbe (Gelb) als Marke** — semantisch falsch (Gelb = Achtung) — mit hartem `#201800`. Modus steckt nur im **Emoji** (kein Textlabel, OS-Rendering). Lösch-✕ ~34 px (unter 44 px), Live-Status farb-getragen.
- **Ziel:** Ruhiger, souveräner Einstieg mit **einem** dominanten „Los geht's" und dezenter Modus-Wahl (eigene Akzentfarbe fürs Quiz, **nicht** Warngelb); Raumliste als klar tippbare Karten mit textgestütztem Status.

#### 4.2 Deck-Editor
- **Ist:** Kopfzeile „Dein Deck/Quiz" mit bis zu **9 Buttons** in einer umbrechenden Reihe (Navigieren, Modus-Schalter 🧪/🏁, Zurücksetzen, Beamer, „Präsentation starten ▶"), sortierbare Fragenliste (Drag-Handle ⠿), darunter Composer.
- **Probleme:** **Button-Overload ohne Gruppierung** — der Haupt-CTA „Präsentation starten" steht am Ende einer 9er-Reihe und geht unter. Drei Icon-Systeme in einer Zeile. Probelauf wird **dreifach** visualisiert (Banner + Toggle-Pill + Nav-Badge). Umsortieren **nur per Drag** (⠿), kein ↑/↓-Fallback → auf Touch kaum bedienbar. „Aktuelle Frage" nur als blaue Rahmenfarbe (farb-only). Zeilen-Aktionen ▶/✎/✕ unter 44 px.
- **Ziel:** Übersichtliche Werkstatt — Kopfzeile klar in **Navigieren | Modus | Präsentieren** gegliedert, **ein** dominanter Start-CTA, tippbare Sortier-Alternative, **ein** Probelauf-Treatment.

#### 4.3 Frage-Editor (Composer)
- **Ist:** Karte mit Typ-Umschalter (3 Pills Umfrage / 5 Pills Quiz), großem Fragen-Input, typspezifischen Feldern (Optionen mit ✓-Korrektmarker, Wahr/Falsch, Schätzfrage ± Toleranz, Freitext, Skala, Wörter), Zeit-Select, Aktionsleiste.
- **Probleme:** Der 5-Pill-Typ-Umschalter bricht unkontrolliert um (kein Segmented-Control). **Korrekt-Marker** unterscheidet „an/aus" primär über **Farbe** (grau ✓ → grün ✓), nicht über Form — für Farbfehlsichtige heikel; 40 px (unter 44 px). Hinweise in drei Stilen. Kein sichtbarer Titel/Trenner „Neue Frage" (Editier- vs. Neu-Modus nur an einer Notiz erkennbar).
- **Ziel:** Fokussierter, formularklarer Editor; Typwahl als echte **Segmented-Control**, Korrekt-Markierung mit **Form + Farbe + Text**, **ein** Hinweis-Muster, klarer Modus-Titel.

#### 4.4 Präsentation (das Steuerpult) — *hohe Priorität*
- **Ist:** `.nav-bar` mit bis zu **11 Kontrollen** (◀ Position ▶, „N dabei"-Puls, Modus-Pills, Vollbild, Rangliste, Zusammenfassung, Übersicht, Beamer, Meine Räume, **Beenden**), darunter Frage + Status-Tag, Quiz-Timerbalken + Antwortzähler, Live-Aktionsleiste mit Auto-Weiter-Countdown/„Anhalten", Lösungs-Toggle 👁/🙈, eingebettete Rangliste.
- **Probleme:** **Extreme Kontroll-Dichte** — auf ~375 px ein mehrzeiliger Button-Teppich; das rote **„Beenden" sitzt direkt neben „Meine Räume"** → Fehlklick-Risiko live. Navigations-Pfeile als dünne Text-Glyphen ◀ ▶ inkonsistent zu den Emoji daneben. **Status-Tags und Timer-Dringlichkeit rein farb-getragen** (≤ 5 s nur Rot, kein Puls/Icon); die „N dabei"-Pulsanimation ist **hartkodiert grün** (folgt dem Theme nicht). Auto-Weiter-Countdown visuell schwach (14-px-Text, kein Fortschrittsring). Vier Label-Varianten für „zurück"-Ziele; Vollbild-Label variiert dreifach.
- **Ziel:** Bühnentaugliches, **ruhiges** Pult — wenige, **gruppierte** Primäraktionen (Auflösen / Weiter), das zerstörerische „Beenden" klar **abgesetzt**, Timer & Auto-Weiter mit **redundanten Nicht-Farb-Signalen** (Form/Motion/Ring), ein konsistentes Label-Vokabular. Das ist der wichtigste Screen — hier entscheidet sich „souverän vs. hektisch".

#### 4.5 Zusammenfassung & Endstand
- **Ist:** Zusammenfassung = schmale Spalte mit Aktionen (Meine Räume, ⬇ CSV, Zurück) + pro Frage Ergebnis (`ResultsView`) bzw. Freitext-Bewertung (`TextGrading`). Endstand = „🏆 Endstand" + Podium (`Leaderboard`).
- **Probleme:** CSV-Export ist ein `<a>` in Button-Optik zwischen echten Buttons; Aktionsleiste nicht in „Zurück | Export" getrennt. Typ-Label als handgebautes Pseudo-Badge (driftet gegen den Deck-Editor). Der **Endstand fühlt sich nicht wie ein Höhepunkt an** — generische Aktionsleiste, kein prominenter „Nochmal/Neues Spiel"-CTA. Empty-States sind reiner Grautext.
- **Ziel:** Zusammenfassung mit **Report-Charakter** (klar lesbar, „Zurück | Export" getrennt); Endstand als **Feier-Screen** mit Bühnenwirkung und klarer Weiterführung.

### Fläche B — Handy (Publikum, mobile first) — *hohe Priorität*

#### 4.6 Beitritt & Nickname
- **Ist:** 6 Zeichen-Boxen (Auto-Advance/Paste) + „Beitreten"; im Quiz danach „Wie heißt du?" + Nickname-Input.
- **Probleme:** Nach dem 6. Zeichen sendet die Eingabe **automatisch** → der „Beitreten"-Button ist praktisch tot (zwei konkurrierende Absende-Wege ohne Hinweis). Die 6 Boxen sind auf 320-px-Geräten **nicht breitengeschützt** (können unter die Zielgröße schrumpfen). Nickname ohne sichtbares Zeichenlimit (24).
- **Ziel:** **Eine** klare Absende-Logik, garantierte ≥ 44-px-Boxen auch schmal, eindeutiger Ladezustand.

#### 4.7 Abstimmen (die Fragetypen) — *höchste Priorität*
- **Ist:** Choice = gestapelte große Buttons; Wahr/Falsch = zwei Buttons; Mehrfachauswahl = antippen + „Absenden"; Skala = quadratische Zahl-Buttons + End-Labels; Zahl/Freitext = großes Feld + „Absenden"; Wörter = bis 3 Felder.
- **Probleme:** **Wahr/Falsch ist nicht codiert** — beide Buttons neutral-grau, **vertikal gestapelt**, kein Ja/Nein-Grün/Rot, kein ✓/✗. **Keine Farb-/Symbol-Brücke zum Beamer:** die Handy-Buttons tragen weder Buchstabe noch Optionsfarbe, die Zuordnung „mein Button ↔ Kachel am Beamer" ist rein positionsbasiert. Skala 1–10 bricht unkontrolliert um. Das Wörter-Feld ist das **einzige Input ohne Reset** → Optik-Drift. Positiv: Abstimm-Buttons sind ≥ 44 px, Inputs ≥ 16 px (kein iOS-Zoom) — **halten**.
- **Ziel:** Jede Antwortoption trägt **dieselbe Farbe/denselben Buchstaben wie am Beamer** (die Brücke ist der Kern des Kahoot-Gefühls); Wahr/Falsch semantisch codiert (2 Spalten, Ja/Nein-Farbe); ein einheitlicher Eingabefeld-Baustein; definiertes Skalen-Umbruchverhalten.

#### 4.8 Zustände (gewartet / abgestimmt / aufgelöst / Zeit um / Pause)
- **Ist:** Warteraum (Puls-Punkt), „Antwort abgegeben"-SVG-Häkchen + Live-Ergebnis + „Antwort ändern", Quiz-Auflösung (`verdict` 🎉/😕 + „+Punkte"), „⏱️ Zeit abgelaufen", „⏸️ Kurze Pause", Probelauf-Tag „🧪 zählt nicht", Verbindungsstatus unten.
- **Probleme:** „**Antwort ändern**" ist ein unterstrichener Text-Link, ~29 px hoch (**unter 44 px**), obwohl echte Aktion. **Punkte** („+120") sind nur grüner Text — semantisch ein Badge, visuell keins, verschwinden neben dem 52-px-Emoji. Drei „Erfolg"-Sprachen auf einer Fläche (SVG-Häkchen, ✓-Glyphe, 🎉). „Zeit abgelaufen" ohne Trost/Ausblick. Verbindungsstatus (13 px, ganz unten) leicht zu übersehen.
- **Ziel:** „Antwort ändern" als vollwertiges Sekundär-Tap-Ziel; **Punkte als eigenständiges Badge** mit klarer vertikaler Rhythmik (Verdict → Ergebnis → Rang → Rangliste); **eine** Erfolgs-Ikonografie; sichtbarere, aber unaufdringliche Verbindungsanzeige.

### Fläche C — Beamer (Projektion) — *hohe Priorität, heute am schwächsten*

#### 4.9 Lobby, laufende Frage, Auflösung
- **Ist:** Lobby = große Headline + weiße QR-Karte + PIN-Pille + Join-URL. Laufende Frage = große Frage + Mini-QR/PIN oben, Countdown-Balken, Optionen als vollfarbige Kacheln A/B/C (weiße Schrift) bzw. „✍️ am Handy eingeben" für Zahl/Freitext, Live-Antwortzähler. Auflösung = `ResultsView` + `Leaderboard`.
- **Probleme:**
  - **Fernlesbarkeit gebrochen:** die Join-Anweisung läuft in Grau (`maxcontrast`) → wäscht aus Distanz aus. Die **QR-Karte** ist eine grelle weiße Fläche ohne Rahmen → schwebt im Dark-Theme unmotiviert.
  - **Kachel-Kontrast:** weiße Schrift auf `#E5A100`/`#16A2B8` unter AA → diese Optionen brechen aus 10 m weg.
  - **Auflösung viel zu klein:** `ResultsView` und `Leaderboard` nutzen **feste px** — der Beamer versucht per `clamp()` hochzuskalieren, aber die Kind-Elemente überschreiben mit absoluten px → **Balken/Rangliste bleiben in Handy-/Desktop-Größe stehen**. Der wichtigste Moment ist aus der letzten Reihe unlesbar.
  - **Kein Podium auf dem Beamer:** der 🥇🥈🥉-Feier-Moment existiert **nur** im privaten Moderator-Fenster.
  - Vertikaler Overflow bei 8 Optionen ungefangen (kein Scroll/Reserve).
- **Ziel:** Der Beamer ist ein **eigener Viewport** — alles skaliert mit der Viewport-Größe (relative Einheiten), Join-Anweisung **kontraststark**, QR-Karte theme-fest umrandet, Kachel-Textfarbe pro Farbe geprüft, **Podium gehört aufs Großbild**. Fernlesbarkeit vor Informationsdichte.

### Quer über alle Flächen

#### 4.10 Geteilte Ergebnis-Sprache (`ResultsView` / `Leaderboard` / `TextGrading`)
- **Ist:** Dieselben Komponenten rendern in Handy, Beamer und Moderator.
- **Probleme:**
  - **Zwei Balken-Normierungen** sehen identisch aus, bedeuten Verschiedenes: choice/multi normieren auf die **Gesamtstimmen** (echter Anteil), Zahl/Skala auf das **Maximum** (führender Wert = 100 %). Ein 100-%-Balken heißt je Typ etwas anderes.
  - **Prozent-Notation uneinheitlich:** „%" nur bei choice/multi, sonst nur Rohzahlen.
  - **Sieger vs. richtig uneinheitlich:** Umfrage-Sieger = `★`, Quiz-richtig = grüner `✓`, dazu das Leaderboard mit `🥇` und `correct✓` — mehrere Symbole für verwandte Dinge, teils direkt nebeneinander.
  - **„Das bist du" markiert unterschiedlich:** in der Liste eine Text-`du`-Pille, im Podium nur eingefärbter Name (farb-only).
  - **Freitext doppelt gebaut:** `ResultsView` (read-only) und `TextGrading` (interaktiv) rendern denselben Block mit getrennten Klassen und leicht abweichenden Werten (Drift-Risiko).
- **Ziel:** **Eine** Ergebnis-Grammatik über alle Typen und Flächen — eine Balken-Norm (klar beschriftet, was 100 % heißt), eine Prozent-Notation, **eine** Markierung für „Sieger/richtig/du" (eine Form + Farbe + Text), ein geteilter Freitext-Baustein, durchgängig **relative Einheiten**, damit dieselbe Komponente auf Handy klein und auf dem Beamer groß rendert.

#### 4.11 Rand- & Leerzustände
- **Handy „Raum nicht gefunden":** heute eine **Sackgasse** ohne Zurück-Weg → freundlicher Leerzustand mit „Neuen Code eingeben". Dazu Terminologie vereinheitlichen (durchgängig **Raum** *oder* **Code**, nicht beides gemischt).
- **Leere Deck-/Ranglisten-Zustände:** heute reiner Grautext → einladende, gebrandete Empty-States (Icon + ein klarer CTA).

---

## 5. Was du liefern sollst (Deliverables)

**Ausgabeform:** annotierte Mockups (Layout, Hierarchie, Farb-/Token-Zuordnung, Beschriftungen) — plus, wenn dein Format es kann, gerenderte Vorschauen. Eine Entwickler:in soll daraus *ohne Rückfragen* umsetzen können.

**Reihenfolge & Umfang:** Bitte **nicht** alles auf einmal. Priorisiere die drei „heißen" Flächen: **Handy-Abstimmen (§4.7)**, **Beamer laufende Frage + Auflösung (§4.9)**, **Steuerpult Präsentation (§4.4)**. Von jeder ein vollständiges Referenz-Mockup im jeweils richtigen Viewport (Handy ~375 px · Beamer groß · Moderator Desktop), mit Notiz zu **Dark-Abweichungen**. Die übrigen Screens danach, gern als **Delta** zum Designsystem.

1. **Ein konsolidiertes Mini-Designsystem** für Pulse (auf NC-Tokens): Chip/Badge-Familie, Button-Hierarchie, Dialog- & Notice-Muster, **Icon-Set** (ersetzt den Emoji/Glyphen-Mix), **Typo-Skala** (Handy/Moderator + Beamer getrennt), Statusfarben (AA, Light + Dark). **Das ist die Grundlage — zuerst.**
2. **Die kanonische Antwort-Farbpalette** (§3.6): AA-sicher, theme-unabhängig, Textfarbe pro Farbe geprüft — und die Regel, wie sie **von der Live-Kachel bis in den Ergebnis-Balken** durchgezogen wird.
3. **Überarbeitete Screens** (§4) als Mockups in der Priorisierung, mit den genannten Zielen, Light + Dark.
4. **Die geteilte Ergebnis-Grammatik** (§4.10): eine Balken-/Prozent-/Sieger-/„du"-Sprache, beamer-skalierend.
5. **Microcopy-Vorschläge** (Deutsch, Du, geschlechtergerecht): Modus-/Verfahrens-Kürzel, Fehlermeldungen, Button-Labels (ein Vokabular), Bestätigungsdialoge, sanftere „Zeit um"-Texte.
6. Für jeden Vorschlag kurz das **Warum** (welches Problem aus §4 er löst).

---

## 6. Nicht Teil dieses Briefs (bleibt Entwicklung)

Diese Punkte sind **Code** und werden nach deinen Konzepten in einer Entwickler-Sitzung umgesetzt — bitte **nicht** hier lösen: das Umstellen von `ResultsView`/`Leaderboard` von festen px auf relative Einheiten, das Ersetzen von `window.confirm()` durch einen themebaren Dialog, ARIA-Verdrahtung, Reaktivität/State, das adaptive Polling, Routing/Deep-Links, die Farbpaletten-Verkabelung Live→Ergebnis. Deine Aufgabe ist das **visuelle & inhaltliche Konzept**; die Mechanik folgt danach.

---

## 7. Offene Entscheidungen (idealerweise vorab geklärt)

- **Antwort-Farbpalette:** eine kanonische AA-Palette (Empfehlung), Textfarbe pro Farbe geprüft — dunkler Text auf hellen Kacheln erlaubt?
- **Options-Brücke Handy↔Beamer:** Buchstabe **und** Farbe auf beiden Seiten (Empfehlung: ja — das ist der Kahoot-Effekt)?
- **Icon-Set:** vollständig auf ein SVG-Set (Empfehlung) — oder Emoji als bewussten Spiel-Akzent an wenigen Stellen behalten (z. B. Podium-Medaillen)?
- **Quiz-Markenfarbe:** eigene Akzentfarbe statt Warngelb (Empfehlung: eigene)?
- **Timer-Dringlichkeit:** welches **Nicht-Farb**-Signal bei ≤ 5 s (Puls / Ring / Icon)?
- **Podium auf dem Beamer:** ja (Empfehlung) — als großer Feier-Moment?

**Wenn eine Frage bei Arbeitsbeginn offen ist:** blockiere nicht und rate nicht still — nimm die **empfohlene** Variante an und **markiere die Annahme sichtbar** im Konzept.

---

## 8. Screenshot-Checkliste (bitte open-design mitgeben)

open-design kann die App **nicht** selbst öffnen (Login/Live-Raum nötig). Für den Ist-Zustand brauchst du Screenshots — je Ansicht möglichst **Light + Dark**; auf dem Handy zusätzlich **~375 px**.

> **Nicht jeder Zustand muss fotografiert werden.** Was sich gerade nicht herstellen lässt (leere Liste, „Raum nicht gefunden", „Zeit um"), einfach **überspringen** — das ist aus dem Brief (§4.8, §4.11) gestaltbar. Priorität haben die **gefüllten, komplexen, live** Ansichten.

**Handy (Publikum):**
- [ ] Code-Eingabe (6 Boxen) + Nickname-Screen (Quiz)
- [ ] Abstimmen **je Typ**: Choice, Wahr/Falsch, Mehrfachauswahl (inkl. „ausgewählt"), Skala 1–10, Zahl, Freitext, Wörter
- [ ] Zustände: Warteraum, „Antwort abgegeben" (+ „Antwort ändern"), Quiz-**Auflösung** (Richtig **und** Falsch, mit Punkten + eigenem Rang), „⏸️ Pause", Probelauf-Tag
- [ ] Countdown-Timer im **letzten (roten) Zustand**
- [ ] Fehler-Toast (falls reproduzierbar)

**Beamer:**
- [ ] Lobby (PIN + QR + Join-URL) — Light **und** Dark (QR-Karte im Dark ist der kritische Fall)
- [ ] Laufende Frage mit **vielen** Optionen (zeigt Kachelfarben + den Amber/Türkis-Kontrastfall) und einmal Zahl/Freitext („✍️ am Handy")
- [ ] Auflösung: Ergebnis-Balken **und** Rangliste (zeigt die zu kleine Skalierung) — Quiz **und** Umfrage

**Steuerpult (Moderator):**
- [ ] Start / „Meine Räume" (mit einigen Räumen)
- [ ] Deck-Editor (volle Kopfzeile mit allen Buttons + Composer offen, Quiz-Typ)
- [ ] Präsentation **live** (volle nav-bar, Timer läuft, „N dabei", Lösungs-Toggle) — der wichtigste Screen
- [ ] Zusammenfassung (mehrere Fragen, inkl. Freitext-Bewertung) + Endstand-Podium

*Tipp: zusätzlich ein Screenshot einer beliebigen anderen Nextcloud-App im selben Theme hilft open-design, den NC-Look als „Marke" zu treffen.*

---

*Design-orientierte Fassung, destilliert aus einem 3-Agenten-Code-Review der Frontend-Komponenten (`Moderator.vue`, `Participant.vue`, `Screen.vue` + geteilte Komponenten). Die visuellen Befunde sind code-abgeleitet und sollten an den echten Screenshots verifiziert werden. Die technische Umsetzung (px→relativ, themebare Dialoge, Farbpaletten-Verkabelung) folgt in einer Entwickler-Sitzung.*
