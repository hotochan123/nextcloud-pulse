# Pulse — Implementierungs-Übergabe (für eine Claude-Coding-Instanz)

**Rolle des Empfängers:** Du implementierst das Frontend der Nextcloud-App **Pulse**
(Mentimeter/Kahoot-artige Live-Umfragen & -Quizze) anhand eines fertigen Design-Konzepts.
Das Konzept ist verbindlich; deine Aufgabe ist die technische Umsetzung im echten Code.

**Sprache aller sichtbaren Texte:** Deutsch, durchgängig **Du**, **geschlechtergerecht**
(„Teilnehmende", nicht „Teilnehmer"). Nicht neu erfinden — Vokabular unten in §11 übernehmen.

---

## 0. Zuerst lesen (Source of Truth)

In dieser Reihenfolge, bevor du Code anfasst:

1. **`Design-Brief.md`** — der ursprüngliche Auftrag mit Problem→Ziel je Screen (§4) und den
   sechs visuellen Schulden (§3). Autoritativ für das *Warum*.
2. **`index.html`** — Konzept-Hub: Übersicht, Abdeckungsmatrix §4.1–§4.11, Annahmen (§7), Code-Punkte (§6).
3. Die vier Konzept-Dateien (öffne sie im Browser, Light **und** Dark umschalten):
   - `pulse-design-konzept.html` — **Stufe 1**: Mini-Designsystem + Antwort-Palette + §4.7/§4.9/§4.4
   - `pulse-design-konzept-stufe2.html` — **Stufe 2**: §4.1/§4.2/§4.3/§4.6/§4.5/§4.11 + restliche Fragetypen
   - `pulse-design-konzept-stufe3.html` — **Stufe 3**: §4.10 Ergebnis-Grammatik + Mehrfachauswahl + TextGrading
   - `pulse-wortwolke.html` — **lauffähige** Wortwolken-Engine (framework-frei, Referenz-Implementierung)
4. Die neun Screenshots vom 17.07.2026 — der **Ist-Zustand** der App (das, was du ablöst). Sie sind
   nicht im öffentlichen Repository.

Die Konzept-HTML sind **annotierte Referenz-Mockups**, kein Produktionscode — nimm daraus Layout,
Hierarchie, Token-Zuordnung, States und Microcopy; die Mechanik baust du in der echten Codebasis.

## 1. Codebasis-Kontext

Nextcloud-App, Vue-Frontend. Laut Brief liegen die relevanten Komponenten in:
- `Moderator.vue` — Steuerpult (Meine Räume, Deck-Editor, Composer, Präsentation, Zusammenfassung, Endstand)
- `Participant.vue` — Handy (Beitritt, Nickname, Abstimmen, Zustände)
- `Screen.vue` — Beamer (Lobby, laufende Frage, Auflösung)
- geteilte Komponenten: `ResultsView`, `Leaderboard`, `TextGrading`

**Öffne diese Dateien zuerst und verstehe den Ist-Zustand**, bevor du umbaust. Erhalte, was gut ist
(fast alles ist tokenbasiert/theme-fähig — das bleibt). Ersetze die visuellen Schulden aus §3.

## 2. Zwei Modi

- **`poll` (Umfrage)** — anonym, Mentimeter-artig. Typen: Multiple Choice, Wortwolke, Skala/Rating.
- **`quiz`** — Kahoot-artig, mit Nickname (Pseudonym, kein Konto), richtiger Antwort + Zeitlimit,
  Punkten, Rangliste, Podium. Typen: Multiple Choice, Wahr/Falsch, Mehrfachauswahl, Schätzfrage, Freitext.

---

## 3. Design-Tokens

**Regel:** Farben kommen aus dem **Nextcloud-Theme** (`--color-*`), damit Light/Dark automatisch stimmen —
**nie feste Hex-Werte** für theme-abhängige Flächen. Die Hex-Werte unten sind die *Design-Absicht* /
Fallback; binde wo möglich an die echten NC-Variablen.

**Ausnahme (bewusst theme-UNABHÄNGIG, feste Werte):** die Antwort-Palette (§4) und der QR-Code.

### 3.1 Mapping auf NC-Theme-Variablen

| Pulse-Rolle | NC-Variable (bevorzugt) | Design-Absicht Light | Dark |
|---|---|---|---|
| Seiten-Hintergrund | `--color-main-background` | `#ffffff` | `#171717` |
| Text | `--color-main-text` | `#1a1a1a` | `#ededed` |
| Sekundärtext | `--color-text-maxcontrast` | `#6b6b6b` | `#a0a0a0` |
| Hover-Fläche | `--color-background-hover` | `#f2f2f2` | `#262626` |
| Füll-/Panel-Fläche | `--color-background-dark` | `#ededed` | `#242424` |
| Rahmen | `--color-border` | `#dcdcdc` | `#333333` |
| Rahmen kräftig | `--color-border-dark` | `#c4c4c4` | `#454545` |
| Primär (Marke/CTA) | `--color-primary-element` | `#006aa3`¹ | `#3ba1f0` |
| Primär Hover | `--color-primary-element-hover` | `#00538a` | `#64b6f5` |
| Auf Primär | `--color-primary-element-text` | `#ffffff` | `#06243a` |
| Erfolg | `--color-success` | `#2d7d46` | `#57c06f` |
| Warnung | `--color-warning` | `#9a6b00`² | `#e0a800` |
| Fehler | `--color-error` | `#c13030` | `#e56b6b` |

¹ Für Text-auf-Fläche AA-korrigiert; die reine Marke darf `#0069c2` sein.
² Warn-**Text** dunkel für AA auf Weiß; Warn-**Fläche** bleibt `#e5a100`.

**Fallback-Drift auflösen (§3.4):** je Variable genau **einen** Fallback-Wert verwenden — nicht mehr
`#767676`/`#999`/`#555` gemischt, nicht mehr `--border-radius-element` in 8/10/12/14 px.

### 3.2 Neue, theme-unabhängige Pulse-Tokens

```css
:root {
  /* Quiz-Marke: eigener Akzent statt Warngelb (§7-Entscheidung) */
  --pulse-quiz:        #7a3ea8;   /* dark: #b57fe0 */
  --pulse-quiz-light:  rgba(122,62,168,.13);
  --pulse-on-quiz:     #ffffff;

  /* Kanonische Antwort-Palette A–H — AA-geprüft, THEME-UNABHÄNGIG (§4) */
  --opt-a:#c23b2e; --opt-a-ink:#ffffff;   /* Rot     */
  --opt-b:#1d6fb0; --opt-b-ink:#ffffff;   /* Blau    */
  --opt-c:#2e7d46; --opt-c-ink:#ffffff;   /* Grün    */
  --opt-d:#e8a200; --opt-d-ink:#241a00;   /* Amber → DUNKLER Text (AA-Fix!) */
  --opt-e:#7b3fa0; --opt-e-ink:#ffffff;   /* Lila    */
  --opt-f:#0f7c8c; --opt-f-ink:#ffffff;   /* Türkis (abgedunkelt, AA-Fix) */
  --opt-g:#c2255c; --opt-g-ink:#ffffff;   /* Magenta */
  --opt-h:#495057; --opt-h-ink:#ffffff;   /* Schiefer */

  /* EIN Radius-Set (§3.4) */
  --pulse-r-el:8px; --pulse-r-card:14px; --pulse-r-pill:9999px;
}
```

### 3.3 Antwort-Palette — die Kern-Regel (§3.6, §4.10)

Jede Antwortoption bekommt einen **stabilen Index A–H** → eine Farbe aus der Palette.
Diese Farbe wird **von der Live-Kachel (Beamer) über den Handy-Button bis in den Ergebnis-Balken
durchgezogen** — das ist die Kahoot-Brücke. Die Textfarbe je Kachel ist `--opt-*-ink` (nie pauschal
Weiß — Amber trägt dunklen Text). Buchstabe **und** Farbe erscheinen auf beiden Seiten (Handy + Beamer).

### 3.4 Typo-Skala (§3.5 — eine statt ~14)

| Token | px | Rolle |
|---|---|---|
| `--t-cap` | 12 | Meta, Labels |
| `--t-sm` | 14 | Sekundärtext |
| `--t-body` | 16 | Fließtext, Buttons (kein iOS-Zoom bei Inputs → min 16) |
| `--t-lead` | 20 | Frage Handy, Kartentitel |
| `--t-h2` | 26 | Screen-Titel |
| `--t-h1` | 34 | Landing |

**Beamer** getrennt und **relativ**: `clamp()`/`vw` bzw. `em`. Siehe §7 unten — der Beamer ist ein
eigener Viewport, alle Ergebnis-Komponenten in `em`.

---

## 4. Komponenten-Verträge

Jede dieser Komponenten ersetzt eine der visuellen Schulden. States immer über **Form + Text**, nie
nur Farbe. Alle Touch-/Klickziele **≥ 44 px**.

### 4.1 Chip/Badge — EINE Familie (ersetzt §3.1)
Varianten: `neutral · accent · live · warning · error · success · quiz`. Ein Radius (`--pulse-r-pill`),
eine Bau-Logik (soft-Tönung **mit** Fallback-Farbe, kein nacktes `color-mix()`). Status trägt immer Text
(+ optional Puls-Punkt). Punkte im Quiz sind ein **echtes Badge** („+120"), nicht nur grüner Text.

### 4.2 Button-Hierarchie (ersetzt §4.1/§4.2/§4.4)
`primary` (ein dominanter je Screen) · `secondary` (Outline) · `tertiary` (gefüllt-ruhig) · `quiz`
(Lila) · `danger` (destruktiv, **immer abgesetzt** — nie neben harmlose Navigation). `min-height:44px`,
`:focus-visible` sichtbar.

### 4.3 Notice + Dialog (ersetzt §3.3)
Ein Notice-Baustein (`info · warn · success`). **`window.confirm()` durch themebaren Dialog ersetzen**;
destruktive Variante mit Warn-Icon, ruhigem „Abbrechen" links und roter Aktion rechts.

### 4.4 Icon-Set (ersetzt §3.2)
Ein durchgängiges SVG-Stroke-Set (Material-nah). „richtig" = **ein** Haken (nicht ✓/🎉/SVG/🥇 gemischt).
Auge für „Lösung zeigen" (nicht 👁/🙈). Eigenes Vollbild-Icon (kein Unicode ⛶, Tofu-Risiko). Emoji nur
noch als bewusste Podium-Deko (🥇🥈🥉).

### 4.5 Segmented-Control
Für Modus-Wahl und Composer-Typwahl. **Wichtig:** bei Umbruch auf mehrere Zeilen **kein Voll-Pill-Radius**
am Container (die runden Enden schieben die äußeren Buttons über die Box) — moderaten Radius (~12 px) +
`row-gap` verwenden. Aktives Segment = erhabene Fläche (`--color-main-background` + Schatten), nicht Sonderfarbe.

### 4.6 Korrekt-Marker (Composer, §4.3)
Toggle mit **Form + Farbe + Text**: leere → gefüllte Checkbox, grün, Label „richtig". ≥ 44 px.

### 4.7 Handy-Antwortoption
Zeile mit Buchstaben-Badge (Optionsfarbe + `--opt-*-ink`) + Text; Auswahl über Rahmen + Haken.
**Wahr/Falsch:** zwei Spalten, Ja = Erfolg/✓, Nein = Fehler/✕ (nicht neutral-grau gestapelt).
**Mehrfachauswahl:** Checkbox-Form, Absende-Button zeigt Stand („2 ausgewählt · Absenden"), **eine**
Absende-Logik. **Skala 0–10:** festes 6-Spalten-Raster (0–5 / 6–10), keine unkontrollierten Umbrüche.

### 4.8 Beitritt (§4.6)
6 Code-Boxen mit **garantierter** Mindestbreite (≥ 44 px, auch bei 320 px). **Kein Auto-Senden** nach dem
6. Zeichen — „Beitreten" ist die eine Aktion, `disabled` bis vollständig, sichtbarer Ladezustand.
Nickname-Input mit sichtbarem Limit (24).

### 4.9 Timer (§4.4)
Nicht-Farb-Signal ≤ 5 s: **Ring + Zahl + Balken**, zusätzlich Puls. Folgt dem Theme (der „N dabei"-Puls
war hartkodiert grün → auf Theme-Variable umstellen).

---

## 5. Geteilte Ergebnis-Grammatik (§4.10) — verbindlich, überall gleich

Die Referenz steht in `pulse-design-konzept-stufe3.html`. Vier Regeln:

1. **Eine Balken-Norm:** Balken normieren **immer auf die Gesamtstimmen** (Anteil). Verteilungstypen
   (Skala/Zahl) rendern ein **Histogramm mit Achse + Ø/Median**, *nicht* denselben Balken max-normiert.
   Jeder Block beschriftet, was 100 % heißt.
   - Choice: „100 % = alle Stimmen". Mehrfachauswahl: „100 % = alle Antwortenden; Summe kann > 100 % sein".
2. **Eine Prozent-Notation:** durchgängig **„N · P %"** (absolute Stimmen · Anteil).
3. **Eine Treffer-/Du-Markierung:** richtig **und** Sieger = grüner Haken + Wort. „Du" = eine Akzent-Pille
   „Du" — identisch in Liste, Balken und Podium (Podium behält Medaille zusätzlich, „Du" bleibt die Pille).
4. **Relative Einheiten:** alle Ergebnis-Komponenten in **`em`**. Dieselbe Komponente rendert per
   Container-`font-size` auf dem Handy klein, auf dem Beamer groß — **das ist der Fix für §4.9
   „Auflösung bleibt zu klein"** (heute überschreiben Kind-Elemente die `clamp()`-Skalierung mit px).

**Freitext:** EIN geteilter Baustein — `ResultsView` (read-only) und `TextGrading` (interaktiv) dürfen
nicht mehr getrennt gebaut sein. TextGrading: richtig/falsch je Antwort (Form+Farbe+Text, 44 px), Punkte
fließen sofort, offene Antworten sichtbar markiert (nicht stumm 0).

---

## 6. Screen-für-Screen — Bau-Reihenfolge & Abnahme

Empfohlene Reihenfolge (Fundament → heiße Flächen → Rest):

| # | Screen | Konzept-Referenz | Kernpunkte / Abnahme |
|---|---|---|---|
| 1 | Tokens + Komponenten (§3–§4) | Stufe 1 | Alle Komponenten in Light+Dark; keine Roh-Hex außer Palette/QR |
| 2 | §4.7 Handy Abstimmen | Stufe 1/2 | Buchstabe+Farbe-Brücke; Wahr/Falsch codiert; alle Typen; ≥44px |
| 3 | §4.9 Beamer laufende Frage + Auflösung | Stufe 1 | Kachel-Ink je Farbe; QR-Karte umrandet; **em-Skalierung**; Podium |
| 4 | §4.4 Steuerpult Präsentation | Stufe 1 | Drei Gruppen; „Beenden" abgesetzt; Timer-Ring; Auto-Weiter sichtbar |
| 5 | §4.10 ResultsView/Leaderboard/TextGrading | Stufe 3 | Eine Norm/Notation/Markierung; em; Freitext-Baustein geteilt |
| 6 | §4.1 Meine Räume | Stufe 2 | Ein CTA + Modus-Segmented; Karten mit Text-Status; Löschen 44px |
| 7 | §4.2 Deck-Editor | Stufe 2 | Kopfzeile 3 Gruppen; ↑/↓-Sortierung; „Aktuell" als Chip; ein Probelauf |
| 8 | §4.3 Composer | Stufe 2 | Typ-Segmented (wrap-fest); Korrekt-Marker Form+Farbe+Text |
| 9 | §4.6 Beitritt | Stufe 2 | Eine Absende-Logik; ≥44px-Boxen; Ladezustand |
| 10 | §4.5 Zusammenfassung + Endstand | Stufe 2/1 | Report; „Zurück \| Export" getrennt; CSV echter Button; Podium-Feier |
| 11 | §4.11 Empty-States | Stufe 2 | Gebrandet + ein CTA; „Raum nicht gefunden" kein Dead-End |
| 12 | Wortwolke | pulse-wortwolke.html | siehe §7 |

---

## 7. Wortwolke — Engine anbinden

`pulse-wortwolke.html` ist die **lauffähige, framework-freie Referenz** (Vanilla JS, keine
Abhängigkeiten). Übernimm die Engine 1:1 in eine Vue-Komponente (oder als eingebettetes Modul):

- **Öffentliche Schnittstelle:** `const words = new Map()` (Anzeigewort → Häufigkeit) + `render(pulseWord)`.
  Steuerung von außen nur über `addWord(text, fromReal)` bzw. Snapshot: `words` befüllen + `render()`.
- **Mitnehmen:** `key/norm`, `hashStr`, `colorFor`, `vertOf`, `baseT`, `sizeScale`, `computeLayout`,
  `computeLayoutFitted`, `render`, `addWord`, Modul-Variablen `words/els/measurer`, Konstanten
  `GAP/MARGIN/MAX_VISIBLE`, das CSS (`.word`-Regeln, Wort-Palette `--w1..8` light+dark), Poppins-700-Font.
- **Überlagerungs-Mitigation ist eingebaut** (nicht entfernen): adaptive Fit-Schleife (schrumpft
  Schriftgröße, bis alle passen), Top-90-Kappung mit „+N weitere", Ausblenden nicht-platzierter Elemente.
- **Datenquelle ersetzen:** Demo-Teil (`seed`, `pool`, `scheduleLive`, Live/Reset/Theme-Buttons, QR,
  Zähler) raus. Echte Antworten anbinden:
  ```js
  socket.on('word', text => addWord(text, true));           // Live
  socket.on('snapshot', rows => {                            // Server-Snapshot
    words.clear();
    for (const { text, count } of rows) words.set(text, count);
    render();
  });
  ```
- **Container muss gemessene Größe > 0 haben** vor dem ersten `render()` (Flex-/Grid-Zelle: `flex:1; min-height`).
- **Fonts abwarten:** `(document.fonts?.ready ?? Promise.resolve()).then(render)`.
- Die Wort-Palette (`--w1..8`) ist **getrennt** von der UI-Antwort-Palette (A–H) und vom UI-Akzent.

---

## 8. „Bleibt Code" — die Mechanik hinter dem Konzept (§6 des Briefs)

Diese Punkte sind bewusst nicht im Konzept gelöst — sie sind **deine** Kernarbeit:

- [ ] `ResultsView`/`Leaderboard` von festen **px → relativen Einheiten** (em) — Kind-Elemente dürfen die
      Beamer-Skalierung nicht mehr mit absoluten px überschreiben.
- [ ] `window.confirm()` → **themebarer Bestätigungsdialog** (ernste Variante für Destruktives).
- [ ] **Farbpaletten-Verkabelung Live→Ergebnis:** der Options-Index A–H muss von der Live-Kachel bis in den
      Ergebnis-Balken durchgereicht werden (nicht am Ergebnis alles auf Primärblau kippen).
- [ ] **ARIA-Verdrahtung** (Live-Regionen für Zähler/Timer/Auflösung; `role`/`aria-label`; Fokus-Führung).
- [ ] **Reaktivität/State**, adaptives **Polling**, **Routing/Deep-Links** (Join-URL, Raum-Deep-Link).
- [ ] Wortwolke an echte **WebSocket/SSE/Polling**-Quelle (§7).

---

## 9. Getroffene Annahmen (Brief §7) — gelten, im Konzept sichtbar markiert

- Antwort-Palette: kanonische AA-Palette; dunkler Text auf hellen Kacheln (Amber) erlaubt.
- Options-Brücke Handy↔Beamer: Buchstabe **und** Farbe auf beiden Seiten.
- Icon-Set: vollständig SVG; Emoji nur Podium-Deko.
- Quiz-Markenfarbe: eigener Akzent (Lila), nicht Warngelb.
- Timer-Dringlichkeit ≤ 5 s: Ring + Puls + Zahl.
- Podium auf dem Beamer; Antwort-Skala 0–10.

Falls du davon abweichen willst: erst nachfragen, nicht still ändern.

---

## 10. Definition of Done (global, jeder Screen)

- [ ] Funktioniert in **Light UND Dark** (Farben aus NC-Theme-Variablen, nicht fest).
- [ ] **WCAG AA:** Textkontrast ≥ 4,5:1; **Farbe nie alleiniger Informationsträger** (Gewinner/richtig,
      Timer-Dringlichkeit, Status tragen Form/Text/Icon zusätzlich); sichtbare `:focus-visible`; jedes
      Bedienelement mit sichtbarer Beschriftung.
- [ ] **Touch-/Klickziele ≥ 44 px.** Inputs ≥ 16 px Schrift (kein iOS-Zoom).
- [ ] **Responsive:** Handy ~320–390 px (mobile first), Beamer als eigener großer Viewport, Moderator-Desktop.
      Kein horizontaler Scroll auf dem Handy.
- [ ] **Bewegung sparsam**, `prefers-reduced-motion` respektiert (Puls/Pop/Transition dann aus/reduziert).
- [ ] Microcopy Deutsch, Du, geschlechtergerecht (§11); ein Label je Ziel (keine vier „Zurück"-Varianten).
- [ ] Keine Roh-Hex außer Antwort-Palette (A–H) und QR.

---

## 11. Microcopy-Vokabular (ein Wortschatz)

- Buttons: „Präsentation starten" · „Auflösen" · „Nächste Frage" · „Lösung zeigen" · „Beenden" · „Beamer" ·
  „Zurücksetzen" · „Absenden" · „Beitreten" · „Los geht's".
- Status-Chips: „Läuft" · „Gesperrt" · „Aufgelöst" · „Probelauf · zählt nicht" · „Offline" · „Bereit".
- „Zeit um" (sanft): „Zeit ist um — die Auflösung kommt gleich."
- Raum nicht gefunden: „Diesen Raum gibt es nicht." + „Neuen Code eingeben." Durchgängig **Raum** (nicht Code/Raum gemischt).
- Destruktiver Dialog: „Raum wirklich löschen? … Das lässt sich nicht rückgängig machen." — „Endgültig löschen" / „Abbrechen".
- Personen: „Teilnehmende" · „dabei" · „Du".
- Wortwolke-Frage: „Was verbindest Du mit …?"

---

## 12. Arbeitsweise-Empfehlung

1. Ist-Zustand lesen (`Moderator.vue`/`Participant.vue`/`Screen.vue` + geteilte Komponenten).
2. Erst Tokens + geteilte Komponenten (§3–§5) zentral umsetzen — der Rest hängt daran.
3. Dann Screens in der Reihenfolge aus §6; nach jedem Screen gegen die DoD (§10) prüfen.
4. Bei Unklarheit: das entsprechende Konzept-HTML im Browser öffnen, Light/Dark umschalten, den
   „Warum"-Kasten der Sektion lesen — dort steht, welches §-Problem gelöst wird.
5. Nichts ohne Grund umbenennen/umbauen, was bereits funktioniert und tokenbasiert ist.
```
