# Pulse — Übergabe-Paket: Skala-Modus „Spektrum" (Radar)

Einstiegsdokument für die Umsetzung des **Spektrum-Fragetyps**. Alles, was eine Coding-Instanz braucht,
liegt in diesem Projekt — dieses Dokument bündelt es und liefert den Kick-off-Prompt.

---

## 1. Was gebaut wird

Der Skala-Fragetyp bekommt einen **Modus „Spektrum"**: mehrere Aspekte (3–8), je auf **0 … X** bewertet,
Startwert = Mitte (`floor(X/2)`). Ergebnis ist ein **Radar-Spektrumgraph** (Speiche je Aspekt, Zentrum = 0,
Rand = X, Ø-Vieleck + Streuungs-Band + Neutral-Ring bei X/2).

Umsetzung: Eingabe in `Participant.vue`, Ergebnis (Radar) in `ResultsView`, Konfiguration im Composer
(`Moderator.vue`). Baut technisch auf dem Einzel-Slider auf.

## 2. Paket-Inhalt — diese Dateien mitgeben

**Spektrum-spezifisch (Kern des Pakets):**
- `HANDOFF-spektrum.md` — **die verbindliche Spec** (Datenmodell, Composer, Handy-Eingabe, Radar-Berechnung, Abnahme, Randfälle).
- `pulse-spektrum.html` — lauffähiges **Referenz-Mockup** (Handy-Eingabe + Beamer-Radar, Light/Dark). Im Browser öffnen.
- `plan-spektrum.md` — die getroffenen Entscheidungen (Q1–Q6) mit Begründung.

**Geteilte Grundlage (schon im Repo/Handoff, hier als Kontext):**
- `HANDOFF-skala-slider.md` — der Einzel-Slider, auf dem Spektrum aufsetzt (Regler-CSS/JS, Zustände).
- `IMPLEMENTATION-HANDOFF.md` — NC-Token-Mapping, Komponenten-Familien, Ergebnis-Grammatik (§3–§5).
- `Design-Brief.md` + die 9 Screenshots (nicht im öffentlichen Repository) — Ist-Zustand & Rahmen.

**Wichtig:** Zugriff auf die echte Codebasis (`Participant.vue`, `Moderator.vue`, `ResultsView`) ist Voraussetzung.

## 3. Entscheidungen (gelten, im Plan markiert)

- Ergebnisgraph = **Radar** · Skala **0 … X**, Default **floor(X/2)** · neutraler Mittelwert **zählt**.
- Handy-Regler mit **Pol-Labels** (links/rechts); der Radar zeigt je Aspekt nur den **Namen**.
- **3–8 Aspekte** (Radar braucht ≥ 3). · Streuung als **zweites, blasses Polygon** (Min–Max).

## 4. Kick-off-Prompt (copy-paste an die Coding-Instanz)

```text
Ergänzung zum Pulse-Frontend: Der Skala-Fragetyp bekommt einen Modus „Spektrum" — mehrere Aspekte,
je 0…X bewertet, Ergebnis als Radar-Graph.

Lies zuerst HANDOFF-spektrum.md — verbindliche Spec (Datenmodell, Composer, Handy-Eingabe, Radar-Berechnung,
Abnahme). Öffne pulse-spektrum.html im Browser als Referenz-Mockup (Handy-Eingabe + Beamer-Radar, Light/Dark),
und plan-spektrum.md für die Begründung der Entscheidungen. Kontext: HANDOFF-skala-slider.md (Einzel-Slider,
auf dem das aufsetzt) und IMPLEMENTATION-HANDOFF.md (NC-Tokens, Komponenten).

Kernpunkte, die stimmen müssen:
- Spektrum-Skala läuft 0…X (min fix 0); X pro Frage konfigurierbar. Default je Aspekt = floor(X/2) (Mitte).
- Neutraler (unbewegter) Mittelwert zählt als gültige Antwort — keine „nicht geantwortet"-Sonderlogik.
- Handy: N Regler untereinander (3–8 Aspekte), je mit Pol-Labels links/rechts, eine Absende-Aktion,
  ≥44px Trefferfläche, aria-label je Aspekt; Regler-CSS wie der Einzel-Slider.
- Radar (SVG viewBox → skaliert Handy/Beamer): Speiche je Aspekt, Zentrum=0/Rand=X, Ø-Vieleck,
  Streuungs-Band Min–Max (fill-rule evenodd), betonter Neutral-Ring bei X/2; je Speiche Name + „Ø x,x"
  als Text (Farbe nicht alleiniger Träger). Erst ab 3 Aspekten rendern.
- Light+Dark über --color-*/NC-Tokens; SVG-Farben als Inline-style mit var() (nicht als Präsentationsattribut).

Umsetzung: Eingabe in Participant.vue, Ergebnis in ResultsView, Composer-Modus Einzel|Spektrum + Aspekte-Liste
in Moderator.vue.

Zeig mir zuerst kurz, wo du das im Code verortest, dann setz es um und prüf gegen die Abnahme-Checkliste (§5 der Spec).
```

## 5. Kurz-Abnahme (Details in HANDOFF-spektrum.md §5)

- [ ] Composer: Modus Einzel|Spektrum; 3–8 Aspekte mit Pol-Labels; gemeinsames X; Validierung.
- [ ] Handy: N Regler, Start floor(X/2), Pol-Labels, eine Absende-Aktion, ≥44px, aria-label, Light+Dark.
- [ ] Radar: Zentrum=0/Rand=X, Neutral-Ring X/2, Ø-Vieleck, Streuungs-Band, Name+Ø je Speiche, ab 3 Aspekten.
- [ ] Werte 0…X, neutraler Default zählt; SVG skaliert Handy→Beamer; keine Roh-Hex außer bewusst gesetzten.
