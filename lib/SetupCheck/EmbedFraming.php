<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\SetupCheck;

use OCA\Pulse\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\CheckServerResponseTrait;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;
use Psr\Log\LoggerInterface;

/**
 * Prüft, ob die Einbett-Shell (/apps/pulse/embed) in PowerPoint framebar ist.
 *
 * Nextcloud sendet „X-Frame-Options: SAMEORIGIN" — unter Apache aus der
 * .htaccess mit „always set", was PHP nicht überschreiben kann. Die App kann
 * den Header also nicht selbst loswerden; er muss am Webserver oder Proxy
 * fallen. Ohne diese Prüfung scheitert das still: die Beamer-Seite lädt im
 * Browser weiter, in der Folie bleibt ein weißer Kasten und nichts im Log
 * verrät warum.
 *
 * Geprüft wird jede Adresse, unter der der Server sich selbst erreicht
 * (overwrite.cli.url, Basis-URL, trusted_domains). Das ist Absicht: die Regel
 * hängt am Vhost bzw. am Router des Proxys, sie kann für den internen Namen
 * greifen und für den öffentlichen fehlen. Genau dann funktioniert das Add-in
 * im Haus und unterwegs nicht.
 *
 * Zwei Grenzen, die in der Meldung stehen müssen: erreicht der Server eine
 * Adresse nicht (Split-Horizon-DNS, kein Hairpin-NAT), taucht sie hier gar
 * nicht auf. Und eine Adresse, die am Proxy vorbei direkt auf den Webserver
 * geht — typisch localhost —, trägt den Header immer, auch wenn die Regel für
 * den echten Namen sitzt. Deshalb nennt das Ergebnis die geprüften Adressen
 * beim Namen, statt ein pauschales „geht/geht nicht" zu behaupten.
 */
class EmbedFraming implements ISetupCheck {
    use CheckServerResponseTrait;

    private const DOC = 'https://github.com/hotochan123/hotochan123-nextcloud-pulse/blob/main/office-addin/README.md';

    public function __construct(
        protected IL10N $l10n,
        protected IConfig $config,
        protected IURLGenerator $urlGenerator,
        protected IClientService $clientService,
        protected LoggerInterface $logger,
    ) {
    }

    public function getCategory(): string {
        return 'network';
    }

    public function getName(): string {
        return $this->l10n->t('Pulse: embedding in PowerPoint');
    }

    public function run(): SetupResult {
        $path = $this->embedPath();

        $blocked = [];
        $open = [];
        foreach ($this->getTestUrls($path) as $url) {
            $header = $this->frameOptionsOf($url);
            if ($header === null) {
                continue;
            }
            // Nach Host zusammenfassen: getTestUrls probiert je Domain http und
            // https, und die Antwort ist fast immer dieselbe.
            $host = parse_url($url, PHP_URL_HOST) ?: $url;
            if ($header === '') {
                $open[$host] = true;
            } else {
                $blocked[$host] = true;
            }
        }

        // Antwortet ein Host mal so und mal so, zählt die blockierte Antwort:
        // ein Weg, der nicht funktioniert, bleibt ein Weg, der nicht funktioniert.
        $blockedHosts = array_keys($blocked);
        $openHosts = array_values(array_diff(array_keys($open), $blockedHosts));

        if (!$blockedHosts && !$openHosts) {
            return SetupResult::warning(
                $this->l10n->t('Could not reach the Pulse embed page, so whether PowerPoint can frame it is unknown. Check by hand: the response to %s must not carry an X-Frame-Options header.', [$path])
                . "\n" . $this->serverConfigHelp(),
                self::DOC,
            );
        }

        if (!$blockedHosts) {
            return SetupResult::success(
                $this->l10n->t('The Pulse embed page can be framed, so the PowerPoint add-in works. Checked: %s.', [implode(', ', $openHosts)]),
                self::DOC,
            );
        }

        // Ab hier immer nur ein Hinweis, nie eine Warnung. Den Header kann die
        // App nicht selbst abstellen, die meisten Instanzen wollen das Add-in
        // gar nicht — und eine Adresse, die am Reverse-Proxy vorbeigeht
        // (localhost), trägt ihn zwangsläufig, obwohl alles richtig steht. Eine
        // Warnung wäre in genau den Setups falsch, für die diese Prüfung da ist.
        if (!$openHosts) {
            return SetupResult::info(
                $this->l10n->t('Nextcloud sends "X-Frame-Options: SAMEORIGIN" for the Pulse embed page, so a PowerPoint slide shows an empty box instead of the poll. Checked: %s. This only affects the PowerPoint add-in — remove that header in the web server or proxy, and only for the paths /apps/pulse/embed and /apps/pulse/screen/. The projector view in a second window works either way.', [implode(', ', $blockedHosts)]),
                self::DOC,
            );
        }

        return SetupResult::info(
            $this->l10n->t('The Pulse embed page can be framed under %1$s, but not under %2$s. Only the address the presenting laptop uses has to work — an address that goes past the reverse proxy, such as localhost, keeps the X-Frame-Options header no matter how the proxy is configured.', [
                implode(', ', $openHosts),
                implode(', ', $blockedHosts),
            ]),
            self::DOC,
        );
    }

    /**
     * Pfad der Einbett-Shell, mit Web-Root. Bewusst festverdrahtet statt über
     * linkToRoute — zwei Gründe, beide gemessen:
     *
     * 1. Ohne geladene App-Routen gibt linkToRoute einen **leeren String**
     *    zurück, ohne Ausnahme. getTestUrls baut daraus Adressen auf `/`, und
     *    die Prüfung misst dann die Startseite: die trägt SAMEORIGIN völlig zu
     *    Recht, also meldet die Prüfung „blockiert" für jede Instanz, immer.
     * 2. Sind die Routen geladen, hängt das Ergebnis am Zusammenhang: ohne
     *    `htaccess.IgnoreFrontController` und ohne die Umgebungsvariable
     *    `front_controller_active` (die setzt die .htaccess nur bei
     *    Web-Anfragen) kommt `/index.php/apps/pulse/embed` heraus. Dieselbe
     *    Prüfung hätte in der Verwaltungsoberfläche und in `occ setupchecks`
     *    verschiedene Pfade gemessen.
     *
     * Geprüft wird deshalb die Form, die auch im erzeugten Office-Manifest
     * steht. Die Webserver-Regeln in office-addin/README.md decken beide Formen
     * ab, damit auch Instanzen ohne Rewrite funktionieren.
     */
    private function embedPath(): string {
        return rtrim($this->urlGenerator->getWebroot(), '/') . '/apps/' . Application::APP_ID . '/embed';
    }

    /**
     * Wert des X-Frame-Options-Headers: leerer String = kein Header (gut),
     * null = Adresse nicht erreichbar (keine Aussage möglich).
     */
    private function frameOptionsOf(string $url): ?string {
        try {
            $response = $this->clientService->newClient()->get($url, [
                'connect_timeout' => 10,
                // Auch eine GESAMT-Grenze, nicht nur fürs Verbinden: die
                // Prüfung läuft synchron, während jemand „Verwaltung →
                // Übersicht" öffnet, und einmal je Adresse aus
                // trusted_domains. Ohne diese Zeile darf eine erreichbare, aber
                // hängende Adresse die Seite beliebig lange aufhalten (Guzzle
                // hat hier keine Vorgabe).
                'timeout' => 10,
                'http_errors' => false,
                // Selbstsignierte Zertifikate sind hier egal: geprüft wird ein
                // Header, nicht die Vertrauenskette.
                'verify' => false,
                'nextcloud' => ['allow_local_address' => true],
            ]);
        } catch (\Throwable $e) {
            $this->logger->debug('Pulse: embed page not reachable for the framing check', ['exception' => $e, 'url' => $url]);
            return null;
        }
        return $response->getHeader('X-Frame-Options');
    }
}
