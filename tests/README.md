# Tests

## Ausführen

```sh
tests/run.sh                 # alle Unit-Tests
tests/run.sh --filter Tally  # Teilmenge
```

Das Skript holt sich beim ersten Lauf `tools/phpunit.phar` (nicht versioniert)
und ruft PHPUnit **im Nextcloud-Container** auf — dem PHP-CLI auf dem Host
fehlen die Erweiterungen `tokenizer` und `xmlwriter`.

## Was hier läuft

Reine Unit-Tests ohne Nextcloud-Bootstrap: `tests/bootstrap.php` lädt nur den
Composer-Autoloader des Servers (für `OCP\…`, u. a. die Entity-Basisklasse) und
einen PSR-4-Loader für `OCA\Pulse\…`. **Kein `lib/base.php`, keine Datenbank,
keine Session** — die Tests können die Live-Instanz nicht anfassen und laufen in
Millisekunden.

| Datei | Deckt ab |
| --- | --- |
| `unit/QuizServiceTest.php` | Tempo-Punkte, Rangliste inkl. Gleichstand (1-2-2-4) und Zeit-Tie-Break |
| `unit/TallyServiceTest.php` | Auszählung aller Fragetypen: choice, words, scale (single/spectrum/compass), multi, number, text, rank, match |
| `unit/MatchGradingTest.php` | Zuordnung im Quiz: alles-oder-nichts, Zeilenreihenfolge egal, Tempo-Punkte |
| `unit/CodeGeneratorTest.php` | Raumcode-Alphabet ohne I/O/0/1, Kollisions-Neuwurf, Token-Längen |
| `unit/RoomServiceTextTest.php` | Raumtitel säubern/kürzen und Kopie-Suffix (private Methoden per Reflection) |
| `unit/PollParamsTest.php` | Der Controller reicht jedes Frage-Feld durch, das der DeckService liest (vergessenes Feld = „fehlt", obwohl gefüllt) |
| `unit/ImageRouteTest.php` | Bild-Routen tragen `#[NoCSRFRequired]` — ohne das antwortet ein `<img src>` mit 412 |
| `unit/InputTest.php` | Rohe Client-Werte: Listen, 1e999, NAN, kaputtes UTF-8 und NUL werden zu „nicht gesendet“ statt PHP-Warnung; `rawStr` behält NUL (Freitext bewerten) |
| `unit/ControllerInputTest.php` | Jeder Parameter jeder Controller-Aktion als Liste, kaputte `pulse_vt`-Cookies: kein 500, keine Warnung; Quelltext-Wächter gegen rohe Casts auf Request-Werte; `close {release}`: fehlt = alte Regel, Wahrheitswerte gehen durch, alles andere 400 ohne Schließen; die Freitextantwort beim Bewerten behält NUL wie die gespeicherte Stimme |
| `unit/HostileInputTest.php` | DeckService, VoteService und Bild-Upload mit verschachtelten Listen und Unzahlen: 400 statt Warnung oder TypeError |
| `unit/PollVoteRaceTest.php` | Zwei erste Umfrage-Stimmen desselben Tokens zugleich: die zweite Einfügung scheitert am UNIQUE-Index und wird zur Änderung (vorher 500); andere Datenbankfehler bleiben Fehler |
| `unit/QuizCsvTest.php` | CSV des moderierten Raums mit Quizfragen: Freitext eine Zeile je Antwortgruppe (vorher 500), Schätzung mit ihrer Zahl, Freitext und Wörter aus dem Publikum werden keine Formel, Anführungszeichen nach RFC 4180 |

## Was hier NICHT läuft

Alles, was wirklich in die Datenbank greift — `createRoom`, `duplicateRoom`,
`recordVote`, Migrationen. Dafür gibt es Wegwerf-Harnesse im App-Verzeichnis
(`_lbtest.php`, `_titletest.php`, `_duptest.php`, `_reviewtest.php`, `_ranktest.php`,
`_imagetest.php`, `_matchtest.php`; `/_*.php` ist ignoriert), die
gegen die laufende Instanz booten:

```sh
docker exec -u www-data nextcloud-nextcloud-1 php /var/www/html/apps/pulse/_duptest.php
```

Sie legen eigene Räume unter einem Test-UID an und räumen am Ende wieder auf.
Wer sie zu echten Integrationstests ausbauen will, braucht eine Testdatenbank —
gegen die Produktivinstanz sollten sie bewusst Wegwerfskripte bleiben.
