# Pulse — Übergabe: Skala-Fragetyp als Schieberegler

**Ergänzt** `IMPLEMENTATION-HANDOFF.md` §4.7. Ersetzt das frühere Button-Raster für den Skala-/Rating-Fragetyp
durch einen **Schieberegler** (`input[type=range]`). Referenz-Mockup: `pulse-design-konzept-stufe2.html`
(§4.7, Handy-Screen „Skala"). Umsetzung in **`Participant.vue`** (Eingabe) + Ergebnis in
**`ResultsView`** (Histogramm, siehe §6).

---

## 1. Datenvertrag

Die Skala läuft **von 1 bis X**. `X` ist **pro Frage konfigurierbar** (Moderator, im Composer).

```ts
interface ScaleQuestion {
  type: 'scale';
  min: 1;                 // fix
  max: number;            // X — vom Moderator gesetzt (Default-Vorschlag: 5)
  step: 1;                // ganze Schritte
  minLabel?: string;      // z.B. "gar nicht"  (optional, Moderator)
  maxLabel?: string;      // z.B. "sehr sicher"
}
interface ScaleAnswer { value: number; }   // 1..max, ganzzahlig
```

- **Kein 0-Wert.** Minimum ist immer 1.
- Es gibt **keinen Default-Wert** als Antwort: „nicht geantwortet" ist ein eigener Zustand (§4).
  Der native `value` des Inputs startet auf `min`, wird aber erst als Antwort gewertet, wenn die Person
  den Regler bewegt (erstes `input`-Event) → dann ist „Absenden" aktiv.

---

## 2. Markup (NC-Tokens)

```html
<div class="scale-field">
  <div class="scale-value" aria-hidden="true">
    <b id="scaleVal">–</b><span> / <span id="scaleMax">5</span></span>
  </div>
  <input
    class="scale-range"
    type="range"
    :min="q.min" :max="q.max" step="1"
    :value="q.min"
    :aria-label="`${q.text} – Skala von ${q.min} (${q.minLabel}) bis ${q.max} (${q.maxLabel})`"
    :aria-valuetext="answered ? String(model) : 'noch nicht gewählt'"
    style="--pct:0%">
  <div class="scale-ends">
    <span>{{ q.min }} · {{ q.minLabel }}</span>
    <span>{{ q.max }} · {{ q.maxLabel }}</span>
  </div>
</div>
<button class="pbtn primary" :disabled="!answered" @click="submit">Absenden</button>
```

- **Nur Min/Max-Labels** unter dem Regler — **keine** Zwischenwert-Ticks.
- Der große Zahlwert oben ist der einzige Wert-Indikator (Farbe/Position sind nicht alleiniger Träger → WCAG).

---

## 3. CSS (cross-browser, NC-Tokens)

`--pct` = Füllstand in Prozent; wird per JS gesetzt (§4). Thumb sitzt in einer **44-px-Trefferfläche**.

```css
.scale-field { display:flex; flex-direction:column; gap:12px; }
.scale-value { display:flex; justify-content:center; align-items:baseline; gap:.25em; }
.scale-value b { font-size:46px; font-weight:800; color:var(--color-primary-element);
                 line-height:1; font-variant-numeric:tabular-nums; }
.scale-value span { font-size:14px; color:var(--color-text-maxcontrast); }

.scale-range { -webkit-appearance:none; appearance:none; width:100%; height:44px;
               background:transparent; cursor:pointer; margin:0; }
.scale-range:focus-visible { outline:2px solid var(--color-primary-element); outline-offset:4px; border-radius:8px; }

/* WebKit */
.scale-range::-webkit-slider-runnable-track {
  height:10px; border-radius:999px;
  background:linear-gradient(90deg,
    var(--color-primary-element) var(--pct,0%),
    var(--color-background-dark) var(--pct,0%));
}
.scale-range::-webkit-slider-thumb {
  -webkit-appearance:none; width:30px; height:30px; margin-top:-10px; border-radius:50%;
  background:var(--color-primary-element); border:3px solid var(--color-main-background);
  box-shadow:0 1px 5px rgba(0,0,0,.35);
}
/* Firefox */
.scale-range::-moz-range-track    { height:10px; border-radius:999px; background:var(--color-background-dark); }
.scale-range::-moz-range-progress { height:10px; border-radius:999px; background:var(--color-primary-element); }
.scale-range::-moz-range-thumb {
  width:30px; height:30px; border-radius:50%;
  background:var(--color-primary-element); border:3px solid var(--color-main-background);
  box-shadow:0 1px 5px rgba(0,0,0,.35);
}

.scale-ends { display:flex; justify-content:space-between; font-size:12px;
              color:var(--color-text-maxcontrast); }
```

- **Light + Dark** ergeben sich automatisch aus den `--color-*`-Variablen — keine festen Hex.
- Firefox nutzt `::-moz-range-progress` für die Füllung (braucht kein `--pct`); WebKit den Gradient mit `--pct`.

---

## 4. Verhalten (JS / Vue)

```js
// State
model = null;              // gewählter Wert (null = noch nicht geantwortet)
get answered() { return model !== null; }

onInput(e) {
  const v = +e.target.value;
  model = v;                                   // erste Bewegung = geantwortet
  const { min, max } = this.q;
  e.target.style.setProperty('--pct', ((v - min) / (max - min) * 100) + '%');
  // Wertanzeige + aria-valuetext aktualisieren (im Template gebunden)
}
```

- `--pct` **immer** aus `(v − min)/(max − min)` berechnen (nicht `v/max`), sonst stimmt die Füllung bei `min=1` nicht.
- Vor der ersten Eingabe: `--pct:0%`, Anzeige „–", `aria-valuetext="noch nicht gewählt"`, „Absenden" **disabled**.
- **Eine** Absende-Logik: erst nach Klick auf „Absenden" senden — kein Auto-Send.

---

## 5. Zustände

| Zustand | Regler | Wertanzeige | Absenden |
|---|---|---|---|
| Noch nicht geantwortet | Füllung 0 %, Thumb auf `min` | „–" | disabled |
| In Auswahl | Füllung nach Wert, Thumb bewegt | aktuelle Zahl „7 / 10" | aktiv |
| Abgegeben | Regler **disabled** (schreibgeschützt), Wert bleibt sichtbar | Zahl | ersetzt durch „Antwort ändern" (44 px) |
| Zeit abgelaufen / Pause | disabled | letzte Zahl bzw. „–" | ausgeblendet |

„Antwort ändern" reaktiviert Regler + Absenden (Muster wie §4.8 in Stufe 1).

---

## 6. Ergebnis (ResultsView)

- **Histogramm** mit einer Spalte je Wert **von 1 bis X** (keine 0-Spalte), Höhe = Anzahl Stimmen je Wert.
- Zusätzlich **Ø (Durchschnitt)** und **Median** als Kennzahlen.
- Wie alle Ergebnis-Komponenten **relativ in `em`** (skaliert Handy → Beamer, siehe Haupt-Handoff §5).
- Referenz-Optik: `pulse-design-konzept-stufe3.html`, Abschnitt „Skala / Zahl — Verteilung als Histogramm".
- Achse startet bei 1; bei großem X (z. B. > 10) Spalten schmaler rendern oder gruppieren — Achsenbeschriftung darf nicht überlappen.

---

## 7. Barrierefreiheit

- Aktueller Wert steht als **Zahl** (Farbe/Position nicht allein).
- `input[type=range]` ist nativ **tastaturbedienbar** (Pfeile ±1, Home/End = min/max).
- `aria-label` nennt Frage + Skalengrenzen inkl. Labeltexte; `aria-valuetext` spiegelt Zahl bzw. „noch nicht gewählt".
- Trefferfläche des Reglers **≥ 44 px** (Höhe des `input`), „Absenden"/„Antwort ändern" **≥ 44 px**.
- `prefers-reduced-motion`: der Regler hat ohnehin keine Eigenanimation — nichts zu tun.

---

## 8. Composer (Moderator) — Konfiguration

- Feld **Maximum X** (Zahl, min 2). Empfehlung Default: **5**. Kein 0/1-Sonderfall — min ist fix 1.
- Optionale Felder **Label bei 1** und **Label bei X** (Freitext, Deutsch/Du).
- Validierung: X ganzzahlig ≥ 2; Labels optional; leer → nur die Zahlen als End-Labels.

---

## 9. Abnahme (Definition of Done)

- [ ] Regler läuft **1 … X**, X aus der Fragekonfiguration (nicht hart 10).
- [ ] Füllung korrekt bei `min=1` (Formel `(v−min)/(max−min)`), in **WebKit und Firefox**.
- [ ] „Noch nicht geantwortet" ist unterscheidbar (Anzeige „–", Absenden disabled), **kein** voreingestellter Antwortwert.
- [ ] Wert als Zahl sichtbar; nur **Min/Max**-Labels, keine Zwischenticks.
- [ ] Trefferfläche ≥ 44 px; Tastatur + `aria-label`/`aria-valuetext` korrekt.
- [ ] Light + Dark über `--color-*`; keine festen Hex.
- [ ] Ergebnis-Histogramm 1..X + Ø/Median, relativ (em), auf dem Beamer groß lesbar.
- [ ] „Antwort ändern" reaktiviert die Eingabe (eine Absende-Logik).
```
