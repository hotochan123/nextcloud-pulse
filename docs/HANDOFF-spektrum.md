# Pulse — Übergabe: Skala-Modus „Spektrum" (Radar)

**Ergänzt** `HANDOFF-skala-slider.md`. Der Skala-Fragetyp bekommt einen **Modus „Spektrum"**: mehrere Aspekte,
je auf **0 … X**, Startwert = Mitte; Ergebnis ist ein **Radar-Spektrumgraph**. Referenz-Mockup: `pulse-spektrum.html`.
Plan/Entscheidungen: `plan-spektrum.md`. Umsetzung: Eingabe in `Participant.vue`, Ergebnis in `ResultsView`,
Konfiguration im Composer (`Moderator.vue`).

---

## 1. Datenmodell

```ts
interface SpectrumQuestion {
  type: 'scale';
  mode: 'spectrum';
  min: 0;                 // fix — Spektrum beginnt bei 0
  max: number;            // X, gemeinsam für alle Aspekte
  aspects: {              // 3 … 8 Aspekte
    id: string;
    label: string;        // "Tempo"
    poleLow?: string;     // Label bei 0  ("zu langsam")   — nur Handy-Eingabe
    poleHigh?: string;    // Label bei X  ("zu schnell")   — nur Handy-Eingabe
  }[];
}
interface SpectrumAnswer { values: Record<string /*aspectId*/, number>; }  // je Aspekt 0..X

// Default-Wert je Aspekt = Mitte:
const mid = Math.floor(max / 2);   // gerades X → exakt; ungerades X → abgerundet
```

- Aggregation je Aspekt (Server oder Client): **Ø** (Durchschnitt), **min**, **max** (für die Streuung), n.
- „Nicht bewegt" zählt: der Default (mid) ist eine **gültige neutrale Antwort** (keine „nicht geantwortet"-Sonderlogik wie bei der Einzel-Skala).

---

## 2. Composer (Moderator)

- Skala-Frage → Umschalter **Modus: Einzel | Spektrum** (Segmented-Control).
- **Maximum X** (gemeinsam, ganzzahlig ≥ 2; Default-Vorschlag 5–6).
- **Aspekte-Liste** (hinzufügen/entfernen/sortieren), **min 3, max 8**. Je Aspekt: Name (Pflicht),
  Pol-Label links/rechts (optional).
- Validierung: ≥ 3 Aspekte; leere Namen verhindern.

---

## 3. Handy-Eingabe (Participant.vue)

- Frage oben, darunter **N Aspekt-Blöcke** untereinander, je:
  - Aspekt-Name + kleine **Wertanzeige** (Zahl).
  - **Schieberegler** `min=0 max=X step=1`, **Startwert = mid** (`--pct = value/X·100%`).
  - **Pol-Labels** links (0 · poleLow) / rechts (X · poleHigh).
- **Eine** gemeinsame Absende-Aktion „Absenden".
- Hinweis „Regler stehen neutral in der Mitte — verschiebe nur, was abweicht."
- A11y: je Regler eigenes `aria-label` (Aspekt + Pole + Bereich), ≥ 44 px Trefferfläche, Tastatur; Wert als Zahl.
- CSS wie Einzel-Slider (`.srange` + WebKit/Firefox-Thumb, Füllung über `--pct`); nur kompakter je Aspekt.
- Bei 8 Aspekten scrollt der Screen — „Absenden" am Ende bleibt erreichbar.

---

## 4. Ergebnis: Radar (ResultsView)

Als **SVG** rendern (skaliert Handy → Beamer über das `viewBox`, keine festen px-Texte außerhalb). Aufbau:

1. **Geometrie:** N Speichen, gleichmäßig verteilt. Winkel Aspekt i: `-90° + i·360/N`.
   Punkt für Wert v: `x = cx + (v/X)·R·cos(a)`, `y = cy + (v/X)·R·sin(a)`. **Zentrum = 0, Rand = X.**
2. **Gitter:** dünne Ringe je ganzem Schritt `g = 1…X` (bei großem X ausdünnen). Speichen vom Zentrum nach außen.
3. **Neutral-Ring** bei `X/2` betont (gestrichelt, `--nc-maxcontrast`); **Außenring** bei `X` (`--nc-border-dark`).
4. **Streuungs-Band (Min–Max):** ein `<path>` aus Max-Polygon + Min-Polygon mit `fill-rule="evenodd"`,
   `fill=var(--nc-primary-el)` @ ~15 % Deckung → Ring zwischen min und max. Schmal = einig, breit = gespalten.
5. **Durchschnitts-Vieleck:** `<polygon>` der Ø-Werte, `stroke=var(--nc-primary-el)` 2–3 px + Füllung ~20 %;
   Eckpunkte als Kreise (mit `--nc-main-bg`-Rand).
6. **Beschriftung je Speiche (außen):** Aspekt-**Name** + darunter **„Ø x,x"** (Farbe nicht allein → Text).
   Textanker nach Winkel (links/rechts/mittig). **Keine** bipolaren links/rechts-Labels am Radar (nicht darstellbar).
7. Relative Einheiten (SVG `viewBox`), damit dieselbe Komponente klein (Handy) und groß (Beamer) rendert.

**Kennzahlen:** Ø je Aspekt an der Speiche; Gesamt-n als Meta oben. Keine %-Notation hier (Skalenwerte).

---

## 5. Abnahme (Definition of Done)

- [ ] Composer: Modus Einzel|Spektrum; 3–8 Aspekte mit optionalen Pol-Labels; gemeinsames X; Validierung.
- [ ] Handy: N Regler, Start = `floor(X/2)`, Pol-Labels, eine Absende-Aktion, ≥ 44 px, `aria-label` je Aspekt, Light+Dark.
- [ ] Werte 0 … X; neutraler Default zählt als gültige Antwort.
- [ ] Radar: Zentrum=0 / Rand=X, Neutral-Ring bei X/2, Ø-Vieleck, Streuungs-Band (Min–Max), Aspekt-Name + Ø-Wert je Speiche.
- [ ] SVG-`viewBox` → skaliert Handy/Beamer; Aspekt-Namen/Ø als Text (Farbe nicht alleiniger Träger).
- [ ] Radar erst ab **3 Aspekten** (darunter keine Fläche) — Composer erzwingt das.
- [ ] Konsistent mit NC-Tokens und den bestehenden Handoffs; keine Roh-Hex außer bewusst gesetzten.

---

## 6. Randfälle

- **N = 3** → Dreieck (kleinste Fläche); **N = 8** → Achteck; dazwischen sauber generierbar aus der Winkelformel.
- **Ungerades X:** Default `floor(X/2)`; der Neutral-Ring liegt bei `X/2` (kann zwischen zwei Gitterringen liegen — ok).
- **Alle Antworten gleich** → Streuungs-Band verschwindet (min = max); das ist die korrekte „völlig einig"-Darstellung.
- **Sehr großes X:** Gitterringe ausdünnen (z. B. nur jeden 2. Schritt), damit der Radar nicht zuläuft.
