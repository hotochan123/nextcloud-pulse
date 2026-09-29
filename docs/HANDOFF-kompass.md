# Pulse — Übergabe: Skala-Modus „2D-Feld / Kompass"

**Ergänzt** `HANDOFF-skala-slider.md`. Dritte Variante des Skala-Typs: zwei bipolare Achsen, ein Punkt je
Antwort. Ergebnis = Quadranten-**Scatter** (wenige Stimmen) bzw. **Heatmap** (ab Schwelle) mit **Centroid**.
Referenz-Mockup: `pulse-kompass.html` · Entscheidungen: `plan-kompass.md`.
Umsetzung: Eingabe `Participant.vue`, Ergebnis `ResultsView`, Konfiguration `Moderator.vue`.

---

## 1. Datenmodell

```ts
interface CompassQuestion {
  type: 'scale'; mode: 'compass';
  range: number;                 // R (Default 5) → Werte je Achse −R … +R, Mitte 0 = neutral
  axisX: { title: string; poleLow: string; poleHigh: string };  // z.B. praktisch / theoretisch
  axisY: { title: string; poleLow: string; poleHigh: string };  // z.B. einzeln / gemeinsam
  cornerLabels?: [string,string,string,string]; // optional: [links-unten, rechts-unten, links-oben, rechts-oben]
  heatmapThreshold?: number;     // ab n Stimmen → Heatmap (Default 40)
}
interface CompassAnswer { x: number; y: number; }  // je −R … +R (ganzzahlig)
// Default = {x:0, y:0} (Mitte, neutral) — zählt als gültige Antwort.
// Centroid = { x: mean(x), y: mean(y) } über alle Antworten.
```

## 2. Composer (Moderator)

- Skala-Frage → Modus **Einzel | Spektrum | Kompass** (Segmented-Control).
- **Zwei Achsen** X und Y, je Titel + zwei Pol-Labels (Pflicht). **Bereich R** (Default 5).
- Optional **vier Quadranten-Ecklabels**. Optional **Heatmap-Schwelle** (Default 40).
- Validierung: beide Achsentitel + je zwei Pol-Labels gesetzt; R ≥ 2.

## 3. Handy-Eingabe (Participant.vue)

- **2D-Pad**: quadratische Fläche mit Fadenkreuz (Mitte). Ziehpunkt (Thumb) ≥ ~34 px; `pointerdown/-move` setzt X/Y.
  Default = **Mitte (0,0)**. Pol-Labels an den vier Seiten (oben=yHigh, unten=yLow, links=xLow, rechts=xHigh).
- **A11y-Fallback (Pflicht):** zwei **Regler** (X-Achse, Y-Achse), −R…+R, step 1 — synchron mit dem Pad
  (Pad → Regler und Regler → Pad). Tastatur/Screenreader über die Regler; Pad `role="application"` + aria-label.
- **Positionsanzeige** als Text („X +2 · Y −1"). Eine gemeinsame Absende-Aktion.
- Mapping: `x = round((px*2−1)*R)`, `y = round((1−py*2)*R)` (px,py = 0…1 relativ zum Pad; y invertiert).
- Trefferflächen ≥ 44 px (Regler); Pad groß genug für sichere Geste.

## 4. Ergebnis (ResultsView) — SVG, relativ (viewBox)

**Gemeinsames Feld:** Quadrat −R…+R; Gitter je ganze Einheit (`--nc-border`); **Nulllinien** durch die Mitte
betont (`--nc-maxcontrast`); Rahmen (`--nc-border-dark`); **Pol-Labels** an den vier Enden; optionale
**Ecklabels** in den Quadranten (dezent). Mapping `sx(x)=cx+(x/R)·H`, `sy(y)=cy−(y/R)·H`.

- **< Schwelle → Scatter:** je Stimme ein halbtransparenter Punkt (`--nc-primary-el` @ ~50 %).
- **≥ Schwelle → Heatmap:** Raster (Bins, z. B. 1er-Zellen über −R…+R); Zellfarbe = `--nc-primary-el` mit
  Deckung ∝ Dichte. Nulllinien/Rahmen **über** die Heatmap zeichnen, damit sie sichtbar bleiben.
- **Centroid** (immer): Raute-Marker `--nc-primary-el` + weißer Rand + Label „Schwerpunkt".
- **Eigener Punkt** (immer, lokal): `--nc-quiz` (Lila), Ring + Punkt + Label **„Du"** — hebt sich von der
  blauen Menge und vom Schwerpunkt ab (Farbe nicht allein → „Du"-Text; Schwerpunkt via Rautenform).

## 5. Abnahme (Definition of Done)

- [ ] Composer: Modus Einzel|Spektrum|Kompass; zwei Achsen mit Titel + Pol-Labels; R; optionale Eck-Labels + Heatmap-Schwelle.
- [ ] Handy: 2D-Pad **+** synchrone X/Y-Regler (A11y), Default Mitte (0,0), eine Absende-Aktion, ≥ 44 px, aria, Light+Dark.
- [ ] Werte −R…+R (ganzzahlig); neutraler Default (0,0) zählt.
- [ ] Ergebnis: Quadrant mit Nulllinien + Pol-/Ecklabels; Scatter < Schwelle, Heatmap ≥ Schwelle; Centroid immer; eigener Punkt „Du".
- [ ] SVG-viewBox → skaliert Handy→Beamer; „Du"/„Schwerpunkt" als Text (Farbe nicht alleiniger Träger).
- [ ] NC-Tokens; SVG-Farben als Inline-style mit var() (nicht als Präsentationsattribut).

## 6. Randfälle

- **Wenige Stimmen an gleicher Stelle** → Punkte überlagern; optional leichtes Jitter oder Deckung < 100 %.
- **Alle in einem Quadranten** → Centroid liegt dort; völlig ok.
- **Genau an der Schwelle** → definiertes Verhalten (≥ n = Heatmap).
- **Sehr große R** → Gitterlinien ausdünnen; Heatmap-Bins gröber wählen.
- **Anonyme Umfrage ohne Nickname** → „Du"-Punkt nur lokal beim eigenen Gerät markieren (Client kennt die eigene Antwort).
