
# Pulse — Übergabe: Design-Verfeinerung (Übergabe 2 → Implementierung)

**Für:** die Coding-Instanz · **Ergänzt:** `IMPLEMENTATION-HANDOFF.md`, `HANDOFF-skala-slider.md`, `HANDOFF-spektrum.md`, `HANDOFF-kompass.md`
**Quelle:** Übergabe 2 „Design-Verfeinerung" (Design-Unterlagen, nicht im öffentlichen Repository) · **Visueller Zielzustand:** fünf Referenzdokumente (unten je Abschnitt genannt).
**Stand:** 2026-07-19 · Politur-Runde, kein Neuentwurf — das Designsystem (NC-Tokens, Antwort-Palette A–H, em-Skalierung, „Du"-Pille, Segmented, Timer-Ring) **bleibt**.

> **Reihenfolge:** Zuerst **§0 (neue Token-Rollen)** — alles andere bindet daran. Dann §1–§6 in Liefer­reihenfolge. Jeder Abschnitt hat eine **Definition of Done (DoD)**.

---

## §0 — Neue Token-Rollen (zuerst einführen)

Vier neue Rollen, jeweils Light **und** Dark. In die zentrale Token-Datei (bei `--pulse-*` / `--nc-*`), nicht inline. Danach die betroffenen Flächen von `--nc-primary`/`--nc-maxcontrast`/`--nc-quiz` auf die neuen Rollen umstellen.

```css
:root, [data-theme="light"]{
  /* „Du" / Self-Identität — getrennt von Datenblau UND Quiz-Lila (§6) */
  --pulse-self:#c2255c; --pulse-self-soft:rgba(194,37,92,.12); --pulse-self-ink:#fff;
  /* Datenvisualisierung — Histogramm/Radar/Kompass, getrennt vom UI-Blau (§3.2) */
  --pulse-viz:#0e7c8b; --pulse-viz-2:#7ac6d2; --pulse-viz-soft:rgba(14,124,139,.14);
  /* datentragendes „Grau" (Counts, %, N Stimmen, Mini-PIN, Code) — kräftiger als maxcontrast (§4) */
  --nc-readout:#33465e;
  /* Podium (§5) */
  --pulse-gold:#c8961e;   --pulse-gold-2:#efc75a;
  --pulse-silver:#8b93a0; --pulse-silver-2:#c3cad4;
  --pulse-bronze:#a56a3c; --pulse-bronze-2:#cf9463;
}
[data-theme="dark"]{
  --pulse-self:#f2699b; --pulse-self-soft:rgba(242,105,155,.20); --pulse-self-ink:#2a0a17;
  --pulse-viz:#43c3d8; --pulse-viz-2:#9fe0ea; --pulse-viz-soft:rgba(67,195,216,.18);
  --nc-readout:#c3d2e4;
  --pulse-gold:#e6bd4d;   --pulse-gold-2:#f6d97e;
  --pulse-silver:#b7bfcc; --pulse-silver-2:#dbe1ea;
  --pulse-bronze:#c98a56; --pulse-bronze-2:#e0aa7e;
}
```

**Regeln:**
- „Du" ist überall dieselbe Farbe (`--pulse-self`): Rangliste/Balken/Podium **und** Kompass-Punkt/Regler/Readout. **Quiz-Lila (`--nc-quiz`) raus aus dem Umfrage-Kompass.**
- Grafik-Datenfarbe ist `--pulse-viz` (nicht mehr `--nc-primary`). Ø = kräftige Kontur in `--pulse-viz`; Streuung = hellere Fläche `--pulse-viz-2`/`--pulse-viz-soft`. Unterschied über **Form + Helligkeit**, nicht Deckkraft.
- `--nc-readout` nur für **datentragendes** Grau; weglassbare Beschriftung bleibt `--nc-maxcontrast`.
- Farbe nie einziger Träger (AA): „Du"/„Schwerpunkt"/Rang immer zusätzlich als Text/Form.

**DoD §0:** Tokens in Light+Dark vorhanden; keine neuen Roh-Hex außer diesen Rollen + Antwort-Palette A–H.

---

## §1 — Skala-Familie: Eingabe *und* Ergebnis · Ref: `pulse-skala-familie.html`
**Dateien:** `Participant.vue` (Eingabe), `ResultsView` (Ergebnis), gemeinsame Slider-/Pad-Komponente.

**Eingabe (Handy):**
- **Gemeinsame Wert-Hierarchie:** eine tabellenziffrige Wert-Anzeige (Monospace, `tabular-nums`) über der Eingabe, in allen drei Modi gleich. Kompass-Readout **gleich prominent** wie die Einzel-Zahl (nicht mehr graue Fließtext-Zeile) — „X +2 · Y −1" als große tabellarische Anzeige.
- **Einzel:** unipolarer Regler (Füllung 0 → Thumb). Zieh-Hinweis + echter **„noch nichts gewählt"-Zustand** (grauer „–", Absenden `disabled`), erst bei Berührung aktiv.
- **Spektrum:** **bipolare** Regler — Füllung **von der Mitte** zum Thumb (nicht 0→Thumb), **Mittel-Tick** auf der Spur, Default **exakt mittig**. Neutraler Wert bleibt grau. (Mittel-Tick hinter dem Thumb: `mid-tick{z-index:0}`, `slider{position:relative;z-index:1}` — der deckende Thumb überlagert den Tick, transparente Reglerfläche lässt die Stummel durch.)
- **Kompass:** Pad-Punkt, Readout, beide Regler-Thumbs in **`--pulse-self`** (nicht Quiz-Lila). Sticky-Footer mit `padding-bottom:max(0px,env(safe-area-inset-bottom))` (§9: „Absenden" nicht unter Android-Leiste).
- **Ein geteilter Baustein** für Regler/Pad: Track 9 px, Thumb 28 px, sichtbarer Fokus, Trefferfläche ≥ 44 px.

**Ergebnis (Beamer + Handy), SVG in `viewBox` (skaliert Handy→Beamer):**
- **Beamer füllt die Bühne** (nicht winziger Block mit leeren Flanken) — wie die Choice-Balken. **Layout-Regel für quadratische Charts (Radar/Kompass):** schlanker Kopf (Titel + 1 Meta-Zeile), Inhalt in einer `flex:1`-Zeile, Chart an der **Höhe** ausgerichtet (`height:100%; aspect-ratio:1`), rechts daneben eine große **Ø-/Kennzahl-Spalte** (nummeriert 1..N passend zu den Chart-Ecken) → nutzt Höhe *und* Breite, beseitigt das Achsen-Label-Clipping (Werte in der Spalte statt am Rand), Legende als schmale Fußzeile. Balken-Charts bleiben breit. Ref: `pulse-beamer-flaeche.html` → „Ergebnis-Höhe". (Bug im Screenshot `22-25-18`: radar-wrap 1808×627, Radar klein mittig.)
- **Datenfarbe `--pulse-viz`.** Histogramm-Säulen, Radar-Ø, Kompass-Menge/Heatmap/Schwerpunkt.
- **Histogramm:** Grundlinie; **Ø & Median als Marker in der Verteilung** (Linien + Fähnchen), nicht als lose Zahlen darüber; Ø durchgezogen (`--pulse-viz`), Median gestrichelt (`--nc-main-text`) → per Form unterscheidbar. Alle Skalenwerte als Achse (auch 0-Stimmen als ruhiger Sockel) → kein einsamer Balken.
- **Radar:** Ø als kräftige **Kontur** über hellerem Streuungs-Band (`--pulse-viz-2`); optionales **„Du"-Polygon** (`--pulse-self`, dünn) + Norm-Note in der Kopfzeile; Aspekt-Labels mit Rand-Puffer, **Umbruch statt Clipping** (§9 „ieherfähigkeit").
- **Kompass:** Menge/Heatmap/Schwerpunkt `--pulse-viz` (Schwerpunkt = Raute + Text „Schwerpunkt"), eigener Punkt `--pulse-self` (Ring + „Du"). Nulllinien/Rahmen über die Heatmap zeichnen.
- **Leerzustand (alle drei):** stiller Rahmen + eine Zeile „Noch keine Antworten" statt kollabiertem Diagramm.

**DoD §1:** eine Wert-Hierarchie über alle Modi; bipolare Spektrum-Regler mit Mitte-Füllung + Tick; Kompass in `--pulse-self`; drei Grafiken groß am Beamer in `--pulse-viz` mit Ø-vs-Streuung, Median-Marker, Label-Umbruch, Leerzustände; Light+Dark.

---

## §2 — Feier-Moment + Podium am Beamer · Ref: `pulse-feier-podium.html`
**Bestätigt am Screenshot `14-48-58`:** Podium läuft heute nur auf dem Moderator-Schirm, blaue Balken per Deckkraft, bewegungslos, viel Leerraum.

- **Podium gehört auf den Beamer** (nicht nur Moderator). Echte Podest-Höhen 1 > 2 > 3 mit Metall-Akzent (Gold/Silber/Bronze).
- **Gestaffelter Einlauf:** Bronze zuerst, dann Silber, **Sieger zuletzt**; **Medaillen-Pop** beim Ankommen; dezenter Gold-Glow hinter Platz 1 (kein Konfetti). Delays c3→c2→c1.
- **Quiz-Verdikt:** knapper Scale-in; **Grün/Rot erst bei der Auflösung** (siehe §5); ✓/✗ als Symbol **+ Text**.
- **„Du"** auf Podest/Endstand-Liste in `--pulse-self` (Ring + „Du"-Pille); Rang zusätzlich über Medaillenzahl + Höhe + Name.
- **Alle Animationen `reduced-motion`-fest:** `@media (prefers-reduced-motion:reduce)` schaltet Rise/Pop/Glow/Verdikt ab, Endzustand bleibt.
- **Punktegleichstand (ex aequo):** Tie-Break zuerst über **kürzere Gesamt-Antwortzeit** (echte Gleichstände selten). Bleibt es exakt gleich → **geteilter Rang**, nächster Rang **springt** (1-2-2-**4**, kein 3). Gleichplatzierte: gleiche Medaillenzahl **+ Höhe + „geteilt"-Chip**; Sieger immer mittig/höchster. Gleichstand auf 2 → zwei Silber flankieren, **Bronze entfällt** (kein leerer Faux-Balken). Viele Gleiche → nur eindeutige Ränge aufs Podest, Rest in die Liste mit geteiltem Rang. (Bug im Screenshot `21-49-53`: zwei „2" als Staircase, Sieger am Rand, kein Marker.)

**DoD §2:** Podium auf dem Beamer-Screen; gestaffelte CSS-Animationen mit reduced-motion-Fallback; Gold/Silber/Bronze + „Du"-Farbe; Verdikt-Scale-in; **Gleichstand = geteilter Rang (1-2-2-4) mit „geteilt"-Kennzeichnung, Gold mittig, kein Faux-Bronze**.

---

## §3 — Beamer-Fläche & Fernlesbarkeit · Ref: `pulse-beamer-flaeche.html`
**Nicht anfassen (stark):** PIN-Block, Wahr/Falsch-Auflösung, WordCloud-Chips.

- **Choice-Kacheln, Passung + Fernlesbarkeit:** unter fixem Kopf/Timer/QR füllen die Optionen die Resthöhe (`flex:1;min-height:0`). **Nach Optionszahl staffeln:** 2–4 → **eine Spalte** (hohe Balken); **5–8 → Zwei-Spalten-Raster** (`grid-template-columns:1fr 1fr; grid-auto-rows:1fr`) → halb so viele Zeilen, doppelt so hohe Kacheln, **Badge + Label wachsen mit** (`clamp`), lange Labels 2-zeilig statt Clipping. Verhindert die dünnen, unlesbaren Streifen bei 8 Optionen (Screenshot `22-02-16`). Palette-Brücke, Buchstaben-Badge, Reveal-Chip-Platz bleiben; **Palette um `--opt-g/--opt-h` ergänzen** (waren nur bis F).
- **Lobby:** zweispaltige Komposition füllt die Fläche; **kontraststarke** Mitmach-Schritte statt grauer Fließtext; **Live-„N schon dabei"-Zähler** (pulsierender Punkt, reduced-motion-fest) für Momentum. PIN-Block unangetastet.
- **Freitext-Ergebnis:** **Häufigkeits-Kacheln** (12× groß … 1× klein) statt zwei Zeilen im Leerraum; akzeptierte Antwort grün, ähnliche/offene Schreibweisen gestrichelt; **eine** Summenzeile „N Nennungen · M verschiedene".
- **Grau-Readouts:** datentragende Zahlen (Counts, %, N Stimmen, Mini-PIN, Code) auf **`--nc-readout`**.

**DoD §3:** Choice passt bei 2–8 Optionen; Lobby gefüllt + Momentum-Zähler; Freitext als Häufigkeits-Kacheln + eine „Nennungen"-Zeile; datentragendes Grau auf `--nc-readout`; Light+Dark.

---

## §4 — Skala-Composer + Demo-Leiste (Moderator) · Ref: `pulse-composer-demo.html`
**Bestätigt am Screenshot `14-49-18`** (Demo-Leiste).

**Composer (§3.4):**
- **Hierarchie Typ ▸ Modus:** der Skala-Modus (Einzel/Spektrum/Kompass) ist **Unterwahl von „Skala"** — eingerückt, Konnektor „▸", Mini-Label „Skala-Modus", **kleinere** Chip-Schalter (kein zweites, identisches Segmented-Control).
- **Gerahmte Sektionsgruppen** mit kräftigem Kopf: „Zwei Achsen" / „Bereich & Darstellung" (R + Heatmap-Schwelle jetzt **gerahmt**) / „Quadranten-Ecklabels · optional".
- **2×2-Ecklabel-Raster räumlich korrekt** (oben oben, unten unten — vorher vertauscht). Pol-Felder mit Abstand, nicht klebend.
- **„+"-Buttons als Icon** (Optionen bei MC, Aspekte bei Spektrum).
- **Deck-Liste:** Untertitel nennt den **Submodus** („Skala · Kompass", Modus fett in `--nc-readout`) + kleines Typ-Icon MC/WC/SK (SK in `--pulse-self`). Zeilen-Aktionen als Icons (Zeigen/Bearbeiten/Löschen), ≥ 34 px + Tooltip.

**Demo-Leiste (§3.3, nur `?demo=1`):**
- Komplett auf **`--pulse-*` / `.pulse-chip`** (raus aus rohen NC-Variablen/festen px).
- **„Demo-Modus" als Warn-Chip** (nicht loser Großbuchstaben-Text); gestrichelter, warngetönter Rahmen = „zählt nicht".
- **Anzahl-Feld** (Default 25, gerahmt). **„Stimmen erzeugen" = Ghost-Button**, **„Demo leeren" = Danger-Outline** (`--nc-error` + Papierkorb) — nie mehr verwechselbar. Subtext „nur zum Prüfen — echte Stimmen bleiben".

**DoD §4:** Modus als eigene Ebene unter dem Fragetyp; Composer-Sektionen einheitlich gerahmt; 2×2 korrekt; Submodus im Deck-Untertitel; Demo-Leiste tokenisiert mit Anzahl-Feld + getrennten Aktionen.

---

## §5 — Ergebnis-Grammatik + Kleinteile · Ref: `pulse-kleinteile.html`

**§7 Grammatik:**
- Freitext/Wortwolke: **eine** Summenzeile („N Nennungen · M verschiedene"), nicht „N Antworten" + „N Stimmen".
- Ein Begriff durchgängig: **„Nennungen"** (WordCloud + Freitext).
- Schätzfrage & Skala teilen **denselben Median-Baustein** und dasselbe Kennzahl-Format („Ø 4,6 · Median 5", Komma, tabellenziffrig).

**§8 Kleinteile (Delta):**
- **Wahr/Falsch im Quiz (§11):** während der Abstimmung **neutral** (graue ✓/✗ + Wort), **Grün/Rot erst bei der Auflösung** + Stimmen. Symbol trägt immer mit.
- **Landing „Meine Räume":** klarer **„Öffnen"-Primärbutton**; Papierkorb sekundär. **Typ deutlich:** kräftiges Icon (Quiz-Lila / Umfrage-Blau) + Text-Chip.
- **Composer-Beitrittsadresse:** dreifach → **eine ruhige Einheit** (PIN + kurze Join-URL + „Link kopieren" für den Deep-Link).
- **Ein Button-System:** eine Primär-Höhe (48 px), ein Radius, ein Hover — „Beitreten"/„Los geht's" und Abstimm-Absender gleich.
- **Einheitlicher Fokus-Ring** (2 px `--nc-primary-el`) auf allen Feldern/Buttons.
- **Quiz-Lila ≠ Options-E-Lila:** Option E abrücken, `--nc-quiz` reservieren.
- **Bestätigungsdialog-Buttons ≥ 44 px.**
- **Zeichenzähler** einheitlich: rechtsbündig unter dem Feld, `--nc-maxcontrast`.
- **Leerzustände** einheitlich gebrandet (Rahmen + eine Zeile), auch der Endstand.
- **Microcopy:** informelles „**Du/Deine**" groß, geschlechtergerecht — „deine Stimme zählt." → „Deine".

**DoD §5:** eine „Nennungen"-Zeile + Median für beide Verteilungen; W/F neutral→Auflösung; Landing-Öffnen-Button + Typ; Beitrittsadresse einfach; ein Button-System + Fokus-Ring; restliche Delta-Punkte umgesetzt.

---

## §6 — §9-Punkte (Code-Bugs, separat) — durch das Design entschärft
- **Beamer-Kacheln unten abgeschnitten** → gelöst durch Flex-Stapel (§3).
- **Radar-/Achsenlabel abgeschnitten** → Umbruch + Rand-Puffer (§1).
- **„Absenden" unter Android-Leiste** → `env(safe-area-inset-bottom)` am Sticky-Footer (§1).

---

## Referenzdokumente (visueller Zielzustand)
| § | Datei |
|---|---|
| §1 Skala-Familie | `pulse-skala-familie.html` |
| §2 Feier + Podium | `pulse-feier-podium.html` |
| §3 Beamer-Fläche | `pulse-beamer-flaeche.html` |
| §4 Composer + Demo | `pulse-composer-demo.html` |
| §5 Grammatik + Kleinteile | `pulse-kleinteile.html` |

Jede Datei hat einen Light/Dark-Toggle und „Warum"-Notizen je Vorschlag. Alle bauen auf den §0-Tokens auf.
