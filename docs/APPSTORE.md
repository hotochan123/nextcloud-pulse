# Pulse im Nextcloud App Store veröffentlichen

Was der Store verlangt, was im Repo schon dafür vorbereitet ist und was von Hand
passieren muss. Reihenfolge einhalten — ohne Zertifikat nimmt der Store nichts an.

Öffentliches Repository ist `hotochan123/hotochan123-nextcloud-pulse`; die vollständige
Entwicklungsgeschichte liegt im privaten Archiv `hotochan123/pulse`.

## Stand

| Anforderung | Status |
|---|---|
| `appinfo/info.xml` gültig gegen das Store-Schema | erledigt (`xmllint`, auch in CI) |
| Lizenz im Repo + SPDX-Kopf in jeder Quelldatei | erledigt (`LICENSE`, AGPL-3.0-or-later) |
| `CHANGELOG.md` (Release-Notizen des Stores) | erledigt |
| Release-Archiv ohne Quellen/Wegwerf-Dateien | erledigt (`build/package.sh`) |
| CI: Bundle, Tests, Übersetzungen, Schema | erledigt (`.github/workflows/ci.yml`) |
| App-ID `pulse` frei | ja (Stand 26.07.2026 kein Eintrag im Store) |
| Signatur-Zertifikat von Nextcloud | Schlüssel erzeugt, `.csr` im Fork (14.08.2026) — **Pull Request noch nicht geöffnet** (Stand 29.09.2026) |
| Screenshots für die Store-Seite | erledigt — sechs Bilder in `screenshots/`, in `info.xml` verlinkt (**wirksam erst nach `git push`**) |
| Getestet auf den beworbenen Serverversionen | erledigt — `info.xml` bewirbt nur noch 34, und nur 34 ist geprüft |

## Einmalig

### 1. Konto und App-ID

Konto auf <https://apps.nextcloud.com> anlegen. Die App-ID (`pulse`) wird beim
ersten Upload an dieses Konto gebunden und ist danach reserviert.

### 2. Zertifikat beantragen

```sh
build/certificate.sh          # erzeugt ~/.nextcloud/certificates/pulse.{key,csr}
```

**Bereits geschehen** (14.08.2026, RSA 4096, `CN=pulse`). Der Schlüssel liegt
**nicht** am Standardort unter `~/.nextcloud/`, sondern auf dauerhaftem
Speicher, weil `/root` auf diesem Host im RAM liegt (siehe unten). Der genaue
Pfad steht bewusst nicht im Repo; im Folgenden `$PULSE_SECRETS`:

```sh
PULSE_SECRETS=/pfad/zum/secrets-ordner/pulse    # einmal pro Sitzung setzen
ls "$PULSE_SECRETS"                             # pulse.key (600), pulse.csr
```

**Vorsicht:** `build/certificate.sh` ohne `OUT=` legt einen **zweiten**
Schlüssel am Standardort an, statt den vorhandenen zu finden — es prüft nur den
Ordner, den es selbst benutzt. Überschreiben tut es nichts, aber signiert wird
dann mit dem falschen Schlüssel. Immer `OUT="$PULSE_SECRETS"` mitgeben.

Der Common Name der Anfrage **muss** die App-ID sein — das Skript setzt
`/CN=pulse`. Danach <https://github.com/nextcloud/app-certificate-requests>
forken, die `pulse.csr` als `pulse/pulse.csr` hinzufügen und einen Pull Request
aufmachen. Das geht komplett über die GitHub-Oberfläche, aber **der Fork muss
zuerst von Hand angelegt werden** — der Auto-Fork beim „Create new file" greift
bei diesem Repo nicht („you need to fork it and propose your changes from
there"). Nach dem Merge liegt dort `pulse/pulse.crt`; die Datei neben den
Schlüssel legen.

Vorbereitet am 14.08.2026 im Fork `hotochan123/app-certificate-requests`,
Branch `pulse-cert` (nur `pulse/pulse.csr`). **Der Pull Request selbst wurde
nie geöffnet** — am 29.09.2026 über die GitHub-API geprüft: kein PR aus diesem
Branch, keiner von diesem Konto. Nachholen über
<https://github.com/nextcloud/app-certificate-requests/compare/master...hotochan123:app-certificate-requests:pulse-cert>
(„Create pull request“); dort wird zurzeit binnen ein bis vier Tagen gemergt.
Danach nachsehen, ob der PR wirklich da ist. Gegengeprüft: die hochgeladene
`.csr` ist Byte für Byte die lokale, `CN=pulse`, und ihr öffentlicher
Schlüssel gehört zu `pulse.key` (SHA-256 des Pubkeys auf beiden Seiten gleich).

Der private Schlüssel bleibt außerhalb des Repos (`.gitignore` sperrt
`*.key`/`*.csr`/`*.crt` zusätzlich ab). Geht er verloren, ist jedes bisher
signierte Release ungültig und es braucht ein neues Zertifikat.

Ablageort prüfen: auf Appliances wie Unraid liegt `/root` im RAM und ist nach
einem Neustart leer. Der Schlüssel gehört dann auf dauerhaften Speicher
(`OUT=/pfad/auf/platte build/certificate.sh`), Verzeichnis `700`, Datei `600`.

### 3. Screenshots

Sechs Bilder liegen in `screenshots/` und stehen in `info.xml` hinter
`<repository>`:

```xml
<screenshot>https://raw.githubusercontent.com/hotochan123/hotochan123-nextcloud-pulse/main/screenshots/01-projector-live.png</screenshot>
```

**Der Store lädt diese URLs selbst.** Solange die Dateien nicht auf `main`
liegen, laufen sie ins Leere und die Store-Seite bleibt bilderlos — Bilder also
mit pushen, bevor ein Release angemeldet wird. Ins Release-Archiv gehören sie
nicht, `build/package.sh` stellt nur die Laufzeit-Dateien zusammen.

Aufgenommen werden sie vom Prüfstand:

```sh
dev/design-shots/run.sh store        # -> dev/design-shots/out-store/
```

Die Strecke unterscheidet sich in vier Punkten vom übrigen Prüfstand, und jeder
davon war vorher eine Falle:

- **Eigene Räume** (`probe.php store`). Der Demo-Weg vergibt je Stimme ein neues
  Token, registriert also je Frage neue Spielende: auf dem Podest stand zweimal
  derselbe Name mit „shared" daneben, und ab mehr Stimmen als Namen hing eine
  „2" hinten dran. Im Store-Fixture beantworten zwölf Personen mit festem Token
  jede Frage, nach einem festen Muster — jede Frage hat eine Verteilung, und die
  Rangliste hat eindeutige Plätze.
- **Öffentlicher Hostname** (`PULSE_HOST` oder eine Zeile in
  `dev/design-shots/.store-host`, nicht im Repo; die Store-Bilder zeigen
  `https://nextcloud.mitung.de`). Der Beitritts-Link kommt aus
  `window.location.host` und steht sowohl als Text als auch im QR-Code auf dem
  Bild. Ein interner Name gehört nicht auf eine öffentliche Store-Seite.
- **Doppelte Pixeldichte** (`layout.css.devPixelsPerPx`), 1200 × 675 CSS-Pixel
  ergeben also ein 2400 × 1350-Bild. Auf die Fensterhöhe wird der Browser-Rand
  aufgeschlagen, sonst wäre das Bild 2:1 statt 16:9.
- **Mehr Anwesende als Antworten** (24 von 30). Sonst steht auf jedem Bild
  „vollständig", und eine laufende Frage sieht aus wie eine abgeschlossene.

Englische Oberfläche kommt aus der Spracheinstellung des Wegwerf-Nutzers
(`occ user:setting pulse-shots core lang en`) und aus
`intl.accept_languages` für die öffentlichen Seiten.

Der Store nimmt bis zu zehn Bilder. Die Reihenfolge in `info.xml` ist die
Reihenfolge auf der Seite; das erste ist das Vorschaubild.

## Je Release

1. **Version hochziehen** in `appinfo/info.xml` **und** `package.json`
   (gleiche Zahl). Achtung: der Versionsbump löst auf der lokalen Instanz einen
   `occ upgrade` aus, sonst antworten die App-Routen mit 503.
2. **`CHANGELOG.md`** — Abschnitt `[Unreleased]` in die neue Version umbenennen,
   Datum setzen. Der Store zeigt diesen Text als Release-Notiz.
3. **`max-version`** in `info.xml` prüfen: bewirbt die App eine Serverversion,
   auf der sie nie lief, kommen die Fehlerberichte von dort.
4. **Bauen, prüfen, signieren, packen:**

   ```sh
   PULSE_KEY="$PULSE_SECRETS/pulse.key" \
   PULSE_CRT="$PULSE_SECRETS/pulse.crt" \
   build/package.sh
   ```

   Das Skript baut das Bundle, prüft Übersetzungen und `info.xml`, stellt nur
   die Auslieferungsdateien zusammen, ruft `occ integrity:sign-app` im
   Nextcloud-Container auf (schreibt `appinfo/signature.json`) und packt
   `build/pulse-<version>.tar.gz`. Am Ende gibt es die Base64-Signatur des
   Archivs aus — die braucht der Store gleich.

5. **Gegenprobe:** `tar -tzf build/pulse-<version>.tar.gz` — oberster Ordner
   heißt `pulse/`, `appinfo/signature.json` ist drin, `src/`, `node_modules/`,
   `docs/`, `tests/` sind es nicht.

6. **Tag und GitHub-Release:**

   ```sh
   git tag -a v0.16.0 -m "Pulse 0.16.0" && git push origin v0.16.0
   ```

   Release auf GitHub anlegen und das Archiv als Asset anhängen. Der Store lädt
   die Datei selbst herunter, die URL muss also öffentlich und HTTPS sein.

7. **Beim Store anmelden:**

   ```sh
   curl -X POST https://apps.nextcloud.com/api/v1/apps/releases \
     -u "STORE-KONTO:PASSWORT" \
     -H "Content-Type: application/json" \
     -d '{
           "download": "https://github.com/hotochan123/hotochan123-nextcloud-pulse/releases/download/v0.16.0/pulse-0.16.0.tar.gz",
           "signature": "<Base64 aus Schritt 4>",
           "nightly": false
         }'
   ```

   Statt `-u` geht auch ein API-Token des Kontos:
   `-H "Authorization: Token <token>"`. Der Store lädt das Archiv, prüft die
   Signatur gegen das Zertifikat, liest `info.xml` und `CHANGELOG.md` und
   veröffentlicht. Antwortet er mit 4xx, steht der Grund im Rumpf — meist
   Signatur, Schema oder eine App-ID, die nicht zum Zertifikat passt.

   Vorabversionen: eine Version nach Semver-Regel mit Suffix (`0.17.0-beta.1`)
   landet im Vorab-Kanal, `"nightly": true` im Nightly-Kanal.

8. **Nachsehen:** `https://apps.nextcloud.com/apps/pulse`, und einmal auf einer
   frischen Instanz über die Oberfläche installieren. Die Integritätsprüfung
   des Servers vergleicht dabei `appinfo/signature.json` mit dem Zertifikat —
   passt sie nicht, meldet die Instanz „Some files have not passed the
   integrity check".

## Was noch fehlt, um ehrlich zu bleiben

- **Serverversionen:** `info.xml` bewirbt **nur 34** (`min-version="34"
  max-version="34"`), und genau darauf läuft die App auch. Bewusst so gewählt:
  ein breiterer Bereich wäre eine Behauptung über Server, die nie jemand
  gestartet hat, und die Fehlerberichte kämen von dort. Preis: ältere Instanzen
  bekommen die App nicht angeboten, und mit NC 35 verschwindet sie aus dem
  Store, bis `max-version` steigt.

  Den Bereich später ehrlich aufmachen: `nextcloud/ocp` in der jeweiligen
  Version als Dev-Abhängigkeit plus Psalm belegt die PHP-Seite statisch (die
  Stubs tragen genau die OCP-Symbole dieser Serverversion), dazu ein echter
  Durchlauf auf der untersten beworbenen Version — die deckt PHP-Untergrenze,
  Migrationen und Theme-Variablen ab, die eine reine Symbolprüfung nicht sieht.
  `occ app:check-code` hilft nicht mehr, das Kommando gibt es in 34 nicht mehr.
- **Übersetzungen:** Englisch und Deutsch kommen aus dem Repo. Weitere Sprachen
  liefe über Transifex; dafür muss die App in Nextclouds Transifex-Projekt
  aufgenommen werden (Anfrage bei Nextcloud), Alleingang lohnt nicht.
- **Signatur in CI:** bewusst nicht. `occ integrity:sign-app` braucht eine
  laufende Nextcloud-Instanz, und der private Schlüssel hat in einem
  CI-Geheimnis nichts verloren, solange lokal signieren genügt.
