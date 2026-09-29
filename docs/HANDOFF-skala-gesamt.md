# Pulse — Gesamtübergabe: Fragetyp „Skala" (drei Modi)

Dachdokument für den **Skala-Fragetyp** mit **drei Modi** — **Einzel · Spektrum · Kompass**. Bündelt die
gemeinsame Basis und verweist auf die Detail-Specs. Ergänzt `IMPLEMENTATION-HANDOFF.md` §4.7.
Umsetzung: Eingabe `Participant.vue`, Ergebnis `ResultsView`, Konfiguration `Moderator.vue`.

---

## 1. Überblick — ein Fragetyp, drei Modi

| Modus | Eingabe (Handy) | Wertebereich | Default | Ergebnis | Detail-Spec |
|---|---|---|---|---|---|
| **Einzel** | 1 Schieberegler | **1 … X** | **keiner** (nicht geantwortet, Absenden disabled) | Histogramm + Ø/Median | `HANDOFF-skala-slider.md` |
| **Spektrum** | N Regler (3–8 Aspekte) | **0 … X** je Aspekt | **Mitte** `floor(X/2)` (zählt) | Radar (Ø-Vieleck + Streuung) | `HANDOFF-spektrum.md` |
| **Kompass** | 2D-Pad + X/Y-Regler | **−R … +R** je Achse | **Mitte (0,0)** (zählt) | Quadranten-Scatter/Heatmap + Centroid | `HANDOFF-kompass.md` |

> **Wichtiger Unterschied bei „Default":** Einzel hat **bewusst keinen** Vorwert (Bias-Vermeidung, „–", Absenden
> disabled). Spektrum und Kompass starten **neutral in der Mitte**, und dieser Neutralwert **zählt** als Antwort
> (bipolare Aspekte/Achsen — Mitte = neutral ist inhaltlich sinnvoll). Nicht angleichen.

---

## 2. Gemeinsame Basis (für alle drei Modi)

- **NC-Tokens, Light + Dark** (Mapping in `IMPLEMENTATION-HANDOFF.md` §3); keine festen Hex außer bewusst gesetzten.
- **Regler-Komponente** (`input[type=range]`) ist der geteilte Baustein: WebKit/Firefox-Thumb, Füllung über `--pct`,
  ≥ 44 px Trefferfläche, `:focus-visible`, `aria-label`. Einzel/Spektrum/Kompass nutzen dieselbe CSS-Basis.
- **Eine Absende-Logik** je Frage (kein Auto-Send). Wert(e) immer als **Zahl** sichtbar (Farbe/Position nie allein → WCAG).
- **Ergebnis-Komponenten relativ** (`em`/SVG-`viewBox`) → dieselbe Darstellung klein am Handy, groß am Beamer.
- **SVG-Farben als Inline-`style` mit `var()`** (nicht als Präsentationsattribut — sonst greift `var()` nicht zuverlässig).
- Microcopy Deutsch, Du, geschlechtergerecht.

## 3. Gemeinsames Datenmodell

```ts
type ScaleMode = 'single' | 'spectrum' | 'compass';

interface ScaleQuestion {
  type: 'scale';
  mode: ScaleMode;

  // single:
  min?: 1; max?: number;                 // 1 … X
  // spectrum:
  spectrumMin?: 0; spectrumMax?: number; // 0 … X
  aspects?: { id: string; label: string; poleLow?: string; poleHigh?: string }[]; // 3–8
  // compass:
  range?: number;                        // R → −R … +R (Default 5)
  axisX?: { title: string; poleLow: string; poleHigh: string };
  axisY?: { title: string; poleLow: string; poleHigh: string };
  cornerLabels?: [string,string,string,string];
  heatmapThreshold?: number;             // Default 40
}

type ScaleAnswer =
  | { value: number }                              // single (1..X)
  | { values: Record<string, number> }             // spectrum (aspectId → 0..X)
  | { x: number; y: number };                      // compass (−R..R)
```

## 4. Composer (Moderator) — ein Umschalter

- Fragetyp „Skala" → **Modus-Segmented-Control: Einzel | Spektrum | Kompass**.
- Je Modus die eigenen Felder einblenden:
  - **Einzel:** Maximum X (Default-Vorschlag 5) + optionale Min/Max-Labels.
  - **Spektrum:** Maximum X + **Aspekte-Liste (3–8)** mit Pol-Labels.
  - **Kompass:** **Bereich R** (Default 5) + **zwei Achsen** (Titel + Pol-Labels) + optionale Ecklabels + Heatmap-Schwelle.
- Validierung je Modus (siehe Detail-Specs).

## 5. Bau-Reihenfolge (empfohlen)

1. **Gemeinsame Basis** — Regler-Komponente, Composer-Modus-Umschalter, ResultsView-Grundgerüst (relativ).
2. **Einzel** (`HANDOFF-skala-slider.md`) — kleinster Modus, etabliert Regler + Histogramm.
3. **Spektrum** (`HANDOFF-spektrum.md`) — N Regler + Radar.
4. **Kompass** (`HANDOFF-kompass.md`) — 2D-Pad + Scatter/Heatmap.

Nach jedem Modus gegen die jeweilige Abnahme-Checkliste (§5 der Detail-Spec) prüfen.

## 6. Datei-Karte (Paket)

| Zweck | Einzel | Spektrum | Kompass |
|---|---|---|---|
| Spec | `HANDOFF-skala-slider.md` | `HANDOFF-spektrum.md` | `HANDOFF-kompass.md` |
| Mockup | (in `pulse-design-konzept-stufe2.html`, §4.7) | `pulse-spektrum.html` | `pulse-kompass.html` |
| Entscheidungen | — | `plan-spektrum.md` | `plan-kompass.md` |
| Paket + Prompt | — | `spektrum-paket.md` | `kompass-paket.md` |

Gemeinsamer Kontext für alle: `IMPLEMENTATION-HANDOFF.md` (NC-Tokens/Komponenten), `Design-Brief.md`, die 9 Screenshots (nicht im öffentlichen Repository).
**Zugriff auf die echte Codebasis** (`Participant.vue`, `Moderator.vue`, `ResultsView`) vorausgesetzt.

## 7. Kombinierter Kick-off-Prompt (alle drei Modi)

```text
Du baust im Pulse-Frontend den Fragetyp „Skala" mit DREI Modi: Einzel, Spektrum, Kompass.
Dachdokument: HANDOFF-skala-gesamt.md — lies es zuerst (gemeinsame Basis, Datenmodell, Bau-Reihenfolge,
Datei-Karte). Die verbindlichen Detail-Specs sind HANDOFF-skala-slider.md (Einzel), HANDOFF-spektrum.md
(Spektrum), HANDOFF-kompass.md (Kompass). Referenz-Mockups: pulse-spektrum.html, pulse-kompass.html und
§4.7 in pulse-design-konzept-stufe2.html. Kontext: IMPLEMENTATION-HANDOFF.md (NC-Tokens, Komponenten).

Rahmen für alle Modi:
- NC-Tokens, Light+Dark; keine festen Hex außer bewusst gesetzten; SVG-Farben als Inline-style mit var().
- Geteilte Regler-Komponente (input[type=range], ≥44px, WebKit+Firefox, Füllung über --pct); eine
  Absende-Logik je Frage; Wert(e) immer als Zahl sichtbar (Farbe/Position nie allein); Ergebnisse relativ
  (em / SVG-viewBox) → Handy klein, Beamer groß. Microcopy Deutsch, Du, geschlechtergerecht.
- Default UNTERSCHEIDLICH: Einzel = KEIN Vorwert (Absenden disabled bis Eingabe). Spektrum = Mitte floor(X/2),
  zählt. Kompass = Mitte (0,0), zählt. Nicht angleichen.

Composer: ein Modus-Umschalter Einzel|Spektrum|Kompass in Moderator.vue mit je eigenen Feldern.

Vorgehen: Bau in dieser Reihenfolge — (1) gemeinsame Basis (Regler + Modus-Umschalter + ResultsView-Gerüst),
(2) Einzel, (3) Spektrum, (4) Kompass. Zeig mir zuerst kurz, wo du die Basis im Code verortest, dann setz
Modus für Modus um und prüf jeweils gegen die Abnahme-Checkliste (§5 der Detail-Spec).
```

## 8. Gesamt-Abnahme (Dach — Details in den Detail-Specs)

- [ ] Composer: Modus-Umschalter Einzel|Spektrum|Kompass mit je eigenen, validierten Feldern.
- [ ] Geteilte Regler-Basis (≥44px, WebKit+Firefox, Light+Dark) in allen Modi.
- [ ] Default-Verhalten je Modus korrekt (Einzel kein Vorwert; Spektrum/Kompass Mitte, zählt).
- [ ] Ergebnisse relativ (Handy→Beamer): Histogramm / Radar / Quadrant-Scatter+Heatmap.
- [ ] WCAG AA je Modus: Wert als Zahl, Farbe nicht allein, Fokus sichtbar, aria; Microcopy Du/geschlechtergerecht.
- [ ] Jede Detail-Abnahme (§5 in Slider/Spektrum/Kompass) einzeln erfüllt.
