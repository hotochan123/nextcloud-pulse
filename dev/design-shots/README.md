# Design-Screenshots

Nimmt jede Pulse-Ansicht automatisch auf: Beamer, Handy, Beitritt, Einbett-Shell
und die Moderator-Oberfläche — je Fragetyp, offen und aufgelöst, hell und dunkel.
Gedacht für Design-Durchgänge: statt von Hand durchzuklicken und abzufotografieren
liegen nach einem Lauf ~55 PNG nebeneinander.

```sh
dev/design-shots/run.sh          # aufnehmen, Probe-Räume danach löschen
dev/design-shots/run.sh keep     # Räume stehen lassen (Codes in probe.json)
dev/design-shots/run.sh clean    # Probe-Nutzer + Passwortdatei entfernen
dev/design-shots/run.sh store    # die sechs Bilder der Store-Seite
```

`store` ist die Ausnahme: nicht Prüffälle, sondern Werbebilder. Eigene Räume
(`probe.php store`, aufgeräumte Inhalte und eine Rangliste ohne doppelte Namen),
öffentlicher Hostname im Beitritts-Link, doppelte Pixeldichte, Ausgabe nach
`out-store/`. Warum jeder dieser Punkte nötig ist, steht in `docs/APPSTORE.md`
§3.

Ergebnis: `dev/design-shots/out/NN-name.png` plus `index.md` mit einer Zeile je
Aufnahme (Größe, Seite, Zustand).

## Wie es funktioniert

| Teil | Rolle |
|------|-------|
| `probe.php` | läuft im Nextcloud-Container: legt zwei Räume an (Umfrage + Quiz) mit je einer Frage pro Typ, hängt ein Bild an, füllt über den **echten** Demo-Weg Stimmen ein und schaltet Zustände (`open`/`locked`/`ended`) |
| `shoot.mjs` | steuert Firefox über geckodriver (WebDriver per HTTP, keine npm-Abhängigkeit), setzt Fenstergröße und Theme, wartet auf ein Anker-Element und löst aus |
| `run.sh` | klammert beides: Wegwerf-Nutzer, Räume bauen, aufnehmen, aufräumen |

Der Kniff: **den Zustand setzt der Server, nicht der Browser.** Deshalb braucht
es kein Klick-Skript für „Frage 5 aufgelöst mit 24 Stimmen" — `probe.php` stellt
es ein, der Browser lädt nur noch die Seite.

## Voraussetzungen

- `firefox` auf dem Host (headless) und `tools/geckodriver`:

  ```sh
  curl -sSL https://github.com/mozilla/geckodriver/releases/download/v0.35.0/geckodriver-v0.35.0-linux64.tar.gz | tar -xz -C tools
  ```
- Docker-Zugriff auf den Nextcloud-Container (`occ`, `probe.php`).
- Node ≥ 18 (nutzt `fetch`).

Stellschrauben über Umgebungsvariablen: `PULSE_HOST`, `CONTAINER`,
`APP_IN_CONTAINER`, `PULSE_SHOTS_UID`, `PULSE_SHOTS_LANG` (Standard `en-US, en`),
`PULSE_SHOTS_PORT`, `OUT` und `PULSE_SHOTS_ONLY=<Strecke>` zum Nachbessern
einer einzelnen Strecke: `public`, `edge`, `phone`, `types`, `dark`, `embed`,
`overview`, `match`, `moderator`, `store` sowie `pace` (alle vier Strecken des
eigenen Tempos) oder einzeln `pace-mod`, `pace-run`, `pace-phone`,
`pace-screen`.

## Quiz im eigenen Tempo

`run.sh` legt die Räume dafür mit `probe.php pace-create` an und schreibt sie
nach `pace.json` neben `probe.json` — nur beim vollen Lauf und für `pace*` und
`embed`, weil allein `race 300` eine Weile dauert. Die Räume: `draft` (nicht
geöffnet, zwei Beitritte), `race` (Rennen, 14 Personen), `homework` (Frist in
drei Tagen, Auflösung am Ende), `e2e`, `timed`, `match8`, `late` (Handy-
Durchläufe), `big` (24 Fragen, 300 Personen — dort kann niemand mehr
beitreten), `mid` und `wide` (16 und 20 Fragen), `practice` (Probelauf,
geöffnet) und `click` (nur für Klickstrecken).

`PULSE_SHOTS_ONLY=pace` fährt `pace-mod` → `pace-run` → `pace-phone` →
`pace-screen` in dieser Reihenfolge, weil einige Strecken Räume verändern;
`pace-screen` gibt am Ende `race` frei. Gemessen wird unter anderem: genau ein
gefüllter Knopf je Ansicht, die Fußleiste der Laufansicht über der Falz, vor der
Freigabe keine Lösung im Handy-DOM (`.pulse-results`, `.tg-accepted`,
`.srow-mark`), am Beamer kein Fragetext und 0 px waagerechter Überlauf, im
offenen Rennen ergeben die Zeilen zusammen die Beigetretenen (Befund `SUMME`),
beide CSV im Browser 200 + `text/csv` (Gegenprobe ohne Requesttoken: 412) und
nie zwei `/progress`-Abrufe zugleich. „Stop without releasing“ und „Close quiz“
mit Frist sind je genau ein `/pace`-Aufruf (Frist bleibt 0 bzw. unverändert,
nicht freigegeben); „Reopen …“ belegt danach — und für die Altlast, gestoppt mit
Frist 120 s nach dem Schluss — „When I close it“ vor, ohne localStorage-Merker.
Seit der Freischaltung (Schritt 4.6) laufen die Strecken ohne `?pace=1` — der
Schalter „Self-paced" steht in jedem Quiz-Deck.

Probe-Befehle für Prüffälle (über die echten Services; `window` und `leave`
schreiben bewusst roh):

| Befehl | Wirkung |
|---|---|
| `pace <code> live\|self` | Ablauf umstellen |
| `open <code> [closesIn] [timed 0\|1] [feedback each\|end]` | öffnen; `closesIn` in Sekunden ab jetzt, 0 = ohne Frist |
| `close <code>` / `release <code>` | schließen / freigeben |
| `lock <code> 0\|1` | Beitritt sperren |
| `window <code> closesAt=… closedAt=… releasedAt=… openedAt=…` | Zeitstempel roh setzen, `now±s` erlaubt (`closesAt=now-1` = Frist sofort abgelaufen) |
| `race <code> <N> [seed]` | N Personen treten bei und spielen deterministisch unterschiedlich weit, mit echten Punkten |
| `remove <code> <name>` | eine Person entfernen |
| `leave <code> <name>` | offene Frage einer Person schließen, ohne die nächste zu starten |
| `online <code> finished\|started\|all` | Präsenz der Personen setzen (15 s): sie stehen in `/progress` als „online" |
| `present <code> <N>` | N Anwesende ohne Namen (15 s) — „{count} here" vor dem Öffnen |
| `reset <code>` | wie „Reset quiz", auch im offenen Zustand |
| `pace-create <uid>` | die Räume oben anlegen, JSON auf stdout |

Von Hand, z. B.:

```sh
docker exec -u www-data nextcloud-nextcloud-1 php /var/www/html/apps/pulse/dev/design-shots/probe.php open ABC123 259200 0 end
```

## Brute-Force-Falle

Wird ein Raum gelöscht oder zurückgesetzt, während eine `/s/…`- oder
`/screen/…`-Seite — oder die Beamer-Vorschau in der Laufansicht — noch abfragt,
laufen 404 auf `pulseRoomCode` auf, und irgendwann antwortet Nextcloud der
Server-Adresse auf allen Pulse-Routen mit 429. Deshalb:

- In jeder Strecke vor `reset` oder `destroy` erst `about:blank` laden;
  `close`, `release`, `window` und `remove` brauchen das nicht. `run.sh`
  räumt erst auf, wenn `node` beendet ist.
- Nach jedem Lauf `occ security:bruteforce:attempts <Server-IP>` ansehen; steigt
  der Zähler, die Ursache suchen und dann `occ security:bruteforce:reset
  <Server-IP>`.
- **Ausnahme mit Absicht:** die Strecke `embed` hebt den Zähler um genau 1 —
  die Aufnahme „unbekannter Code" tippt `ZZZZZZ`, und genau diese Abfrage zählt.
  Nach `embed` also +1 prüfen und zurücksetzen; jeder weitere Anstieg ist ein
  echter Fund.
- Der Beamer hält seinen Zustand 2 s: nach einem Probe-Befehl mindestens
  `settle: 2500`.
- Nie parallel zu `dev/sim/run.sh` (oder einem zweiten Prüfstand-Lauf): beide
  nutzen `pulse-shots`, und `run.sh` räumt vorher und nachher mit `probe
  destroy pulse-shots` **jeden** Raum dieses Nutzers weg — auch die des Sims,
  dessen nächste Handy-Anfrage dann ins Leere geht und zählt.

## Grundlinien

`out-pre-pace-ui/` (vor Stufe 4) und `out-post-pace-ui/` (nach der
Freischaltung) halten die Strecken `moderator`, `phone` und `public` als
Vergleich fest; wie alle `out-*` stehen sie nicht im Repo. Verglichen werden die
Messzeilen der `index.md` mit neutralisierten Raumcodes — Pixel-Vergleiche
zeigen bei jedem Lauf Unterschiede, weil Code, QR-Code und gesäte Stimmen
wechseln.

**Welche Instanz?** `PULSE_HOST` schlägt alles. Sonst wird `.host` in diesem
Ordner gelesen — eine Zeile, z. B. `https://nextcloud.intern.example`; die
Store-Strecke liest stattdessen `.store-host` mit dem öffentlichen Namen. Beide
Dateien sind nicht im Repo: ein interner Hostname hat in einem öffentlichen
Repository nichts zu suchen. Fehlt beides, bricht `run.sh` (und `shoot.mjs`)
vor dem ersten Schritt ab — eine fest eingetragene Rückfall-Adresse hätte den
Login des Wegwerf-Nutzers an eine fremde Instanz geschickt.

## Zwei Eigenheiten der Umgebung

- **Nextcloud 34 verlangt Firefox ≥ 145**, auf dem Server läuft 128 ESR. Ohne
  Gegenmaßnahme landet der Login auf `/unsupported`. `shoot.mjs` setzt deshalb
  denselben Merker, den der Knopf „Continue with this unsupported browser"
  setzt. Betrifft nur den Prüfstand.
- **Willkommens-Dialog:** `firstrunwizard` legt sich sonst über den ersten
  Moderator-Screenshot. `run.sh` setzt die Nutzereinstellung `show` auf eine
  hohe Versionsnummer — der Wert ist seit Kurzem eine Version, kein Schalter.

## Grenzen

Standbilder ohne Hover-, Fokus- und Animationszustände, kein Gefühl fürs
Bedienen am echten Gerät. Was auffällt (Umbrüche, Kontraste, Platzverteilung,
abgeschnittene Inhalte), fällt hier auf; ob sich etwas *gut anfühlt*, entscheidet
weiterhin ein Mensch mit echtem Beamer.
