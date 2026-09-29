# Pulse in PowerPoint einbetten (Content-Add-in)

Bettet die Pulse-Beamer-/Umfrageansicht **live in eine PowerPoint-Folie** ein –
wie eine Mentimeter-Folie. Kein Office-Store nötig, das Add-in wird per **Sideload**
geladen.

## Wie es funktioniert

Das Office-Manifest wird **von der eigenen Nextcloud erzeugt**, nicht als Datei
mitgeliefert: ein Office-Manifest kennt keine Variablen, `<AppDomain>` und
`<SourceLocation>` müssen absolute URLs sein. Jede Instanz gibt deshalb unter
`/apps/pulse/addin/manifest.xml` ihr eigenes, fertiges Manifest heraus.

Das Manifest verweist auf die gehostete Shell `/apps/pulse/embed`. Die Shell lädt
`office.js`, fragt **einmal** den 6-stelligen Raumcode ab (gespeichert in den
Dokument-Settings der `.pptx`) und rahmt dann same-origin `/apps/pulse/screen/{code}`
ein. Beim Weiterschalten der Fragen im Moderator-Deck aktualisiert sich die Folie
von selbst (Pulse-eigenes State-Polling).

## Voraussetzungen

- **Windows-PowerPoint** 2016+ oder Microsoft 365 (Desktop). LibreOffice Impress
  und PowerPoint im Browser können keine Content-Add-ins.
- Der Präsentier-Laptop erreicht die Nextcloud-Adresse aus dem Manifest → **LAN,
  VPN oder öffentlich**.
- **Internet** für `office.js` (Microsoft-CDN) — die Add-in-Hülle braucht es,
  der Umfrage-Inhalt kommt aus der Nextcloud-Instanz.
- Am Webserver/Proxy muss `X-Frame-Options` für zwei Pfade fallen (siehe unten).
  Pulse prüft das selbst und meldet es unter **Verwaltung → Übersicht**.

## Einrichtung (einmalig)

### 0. Manifest aus der eigenen Instanz laden

In Pulse einen Raum öffnen → Überlaufmenü des Decks → **„PowerPoint-Add-in"**.
Alternativ direkt: `https://DEINE-INSTANZ/apps/pulse/addin/manifest.xml`.

Die Datei ist fertig ausgefüllt — sie trägt genau die Adresse, unter der sie
geladen wurde. Wer intern und öffentlich unterschiedliche Namen benutzt, lädt sie
unter der Adresse herunter, die der **Vortrags-Laptop** erreicht.

Nicht von Hand bearbeiten. Wechselt die Adresse der Instanz, neu herunterladen;
die Add-in-Kennung (`<Id>`) bleibt dabei gleich, PowerPoint erkennt es als
dieselbe Erweiterung wieder.

### 1a. Für eine einzelne Person: freigegebener Ordner

1. Ordner anlegen, z. B. `C:\PulseAddin`.
2. Die heruntergeladene `pulse-addin.xml` hineinkopieren.
3. Ordner freigeben: Rechtsklick → **Eigenschaften → Freigabe → Freigeben…**
   (oder **Erweiterte Freigabe**), Freigabename z. B. `PulseAddin`.
   Ergebnis ist ein UNC-Pfad wie `\\DEIN-PC\PulseAddin`.
4. PowerPoint → **Datei → Optionen → Trust Center →
   Einstellungen für das Trust Center… → Vertrauenswürdige Add-In-Kataloge**.
5. Bei **Katalog-URL** den UNC-Pfad `\\DEIN-PC\PulseAddin` eintragen →
   **Katalog hinzufügen**.
6. In der Liste das Häkchen **„Im Menü anzeigen"** setzen → **OK**.
7. **PowerPoint neu starten.**

Das ist der Weg ohne Microsoft-Konto — dafür pro Rechner einmal.

### 1b. Für eine Organisation: zentrale Bereitstellung

Mit Microsoft 365 geht es einmal für alle: **Microsoft 365 Admin Center →
Einstellungen → Integrierte Apps → App hochladen → Benutzerdefinierte App**,
dort dieselbe `pulse-addin.xml` hochladen und Personen oder Gruppen zuweisen.
Das Add-in erscheint dann von selbst in PowerPoint, ohne Katalog und ohne
Trust-Center-Eintrag auf jedem Gerät.

### 2. Add-in einfügen
1. **Einfügen → Meine Add-Ins** → beim Ordnerweg zusätzlich
   **▾ → Freigegebener Ordner**.
2. **Pulse Live-Umfrage** wählen → **Hinzufügen/Einfügen**.
3. Auf der Folie erscheint das Add-in: **Raumcode eintippen → Anzeigen**.
   (Raum vorher in Pulse anlegen, um einen Code zu bekommen.)
4. **Bildschirmpräsentation (F5):** Die Umfrage läuft live in der Folie.
   Das Publikum stimmt wie gewohnt per QR/`/s/CODE` am Handy ab.

Code später wechseln: mit der Maus über das Add-in fahren → obere Leiste →
**„Code ändern"**.

## Fehlersuche

| Symptom | Ursache / Fix |
|---|---|
| Add-in taucht nicht unter „Meine Add-Ins" auf | Katalog-URL muss ein **UNC-Pfad** (`\\…`) sein, nicht `C:\…`; „Im Menü anzeigen" gesetzt? PowerPoint neu gestartet? |
| Kasten bleibt weiß/leer | `X-Frame-Options` noch da (Prüfung unter **Verwaltung → Übersicht** ansehen). Sonst: Laptop im **LAN/VPN**? `office.js` = **Internet** da? Gegentest: die `<SourceLocation>`-Adresse aus dem Manifest mit `?code=DEINCODE` im Browser des Laptops öffnen. |
| Add-in zeigt eine fremde/alte Adresse | Manifest stammt aus einer anderen Instanz oder von vor einem Adresswechsel — neu herunterladen (Schritt 0). |
| „Add-in-Fehler" / lädt nicht | Zertifikat der Instanz muss dem Laptop vertrauenswürdig sein (Let's Encrypt reicht). Prüfen, ob die Shell im Browser des Laptops lädt. |
| Falscher/alter Code hängt fest | Über die Hover-Leiste **„Code ändern"**. Der Code steckt in der `.pptx`. |

## Voraussetzung am Webserver: `X-Frame-Options` muss weg

Das betrifft **jede** Nextcloud-Instanz, nicht nur ein bestimmtes Setup. Nextcloud
sendet den Header aus dem eigenen Code, an zwei Stellen:

```
.htaccess:35     Header always set X-Frame-Options "SAMEORIGIN"
lib/base.php     header('X-Frame-Options: SAMEORIGIN')   # Fallback ohne mod_headers
```

`always set` lässt sich per PHP **nicht** überschreiben — eine App kann den Header
nicht loswerden. Er muss auf Webserver- oder Proxy-Ebene entfernt werden, und zwar
nur für die beiden Anzeige-Pfade:

- `/apps/pulse/screen/…` — Beamer-/Publikumsansicht (rein lesend)
- `/apps/pulse/embed` — die Add-in-Shell

**Beide Male auch mit `/index.php` davor.** Ohne `htaccess.IgnoreFrontController`
erzeugt Nextcloud seine Adressen als `/index.php/apps/pulse/embed`, und eine
Regel, die nur die kurze Form kennt, greift dann nie. Die Schnipsel unten decken
beide ab. (Genau daran ist es hier einmal gescheitert: dieselbe Seite kam unter
der kurzen Adresse ohne Header zurück und unter der langen mit.)

Die Abstimm-Seite `/s/{code}` bleibt bewusst gesperrt. Die zweite Framing-Sperre,
CSP `frame-ancestors`, löst die App selbst (`PublicController::screen`/`embed`).

**Traefik** — fertige Datei liegt hier unter [`traefik/`](traefik/), auf dem Server
in das Verzeichnis des File-Providers. Host, `service` und `certResolver` anpassen
(in der Datei markiert).

**Apache** (in der vHost-Konfiguration, nicht in der Nextcloud-`.htaccess` — die
wird bei Updates überschrieben):

```apache
<LocationMatch "^(/index\.php)?/apps/pulse/(screen/|embed)">
    Header always unset X-Frame-Options
</LocationMatch>
```

**nginx** (im `location`-Block, der zu PHP weiterreicht):

```nginx
location ~ ^(/index\.php)?/apps/pulse/(screen/|embed) {
    proxy_hide_header X-Frame-Options;   # Reverse-Proxy davor
    # oder bei direktem php-fpm: fastcgi_hide_header X-Frame-Options;
    ...
}
```

Prüfen — der Header darf **nicht** auftauchen:

```sh
curl -sI https://DEINE-INSTANZ/apps/pulse/embed            | grep -i x-frame-options
curl -sI https://DEINE-INSTANZ/index.php/apps/pulse/embed  | grep -i x-frame-options
```

Dasselbe macht Pulse von sich aus: die Einrichtungsprüfung **„Pulse: Einbetten in
PowerPoint"** (Verwaltung → Übersicht) ruft die Einbettseite unter jeder Adresse
ab, unter der der Server sich selbst erreicht — und meldet es getrennt, wenn die
Regel für den internen Namen greift und für den öffentlichen fehlt. Ohne das
bricht die Einbettung **still**: die Beamer-Seite lädt weiter im Browser, in
PowerPoint bleibt der Kasten leer.

**Gehostete/verwaltete Nextcloud** ohne Zugriff auf Webserver oder Proxy: der
Embed funktioniert dort nicht. Beamer-Ansicht im zweiten Fenster statt in der
Folie ist dann der Weg — die braucht weder Add-in noch Header-Regel noch
Microsoft.
