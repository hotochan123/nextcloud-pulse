<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Controller\AddinController;
use OCA\Pulse\Service\AddinManifest;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Das Office-Manifest erzeugt die Instanz, weil ein Manifest keine Variablen
 * kennt. Geht dabei etwas schief, merkt es niemand beim Durchklicken: PowerPoint
 * meldet nichts Brauchbares, der Kasten auf der Folie bleibt einfach leer.
 */
class AddinManifestTest extends TestCase {
    private AddinManifest $manifest;

    protected function setUp(): void {
        $this->manifest = new AddinManifest();
    }

    public static function versions(): array {
        return [
            'gewöhnlich'        => ['0.18.0', '0.18.0.0'],
            'Vorabversion'      => ['0.19.0-beta.1', '0.19.0.0'],
            'Build-Metadaten'   => ['0.18.0+build.7', '0.18.0.0'],
            'nur Hauptversion'  => ['1', '1.0.0.0'],
            'zu viele Stellen'  => ['2.3.4.5.6', '2.3.4.5'],
            'leer'              => ['', '0.0.0.0'],
        ];
    }

    /**
     * Office verlangt genau vier Zahlen. Ein Buchstabe im Feld — etwa aus
     * „0.19.0-beta.1" — und PowerPoint lehnt das ganze Manifest ab.
     */
    #[DataProvider('versions')]
    public function testVersionAlwaysHasFourNumbers(string $appVersion, string $expected): void {
        $this->assertSame($expected, $this->manifest->officeVersion($appVersion));
    }

    public static function urls(): array {
        return [
            'Wurzel'         => ['https://cloud.example.com/', 'https://cloud.example.com'],
            'Unterordner'    => ['https://cloud.example.com/nextcloud/', 'https://cloud.example.com'],
            'eigener Port'   => ['https://cloud.example.com:8443/nextcloud/', 'https://cloud.example.com:8443'],
            'ohne TLS'       => ['http://localhost/nc/', 'http://localhost'],
        ];
    }

    /**
     * <AppDomain> darf nur Schema, Host und Port tragen. Mit Pfad daran hält
     * Office die Navigationsziele des Add-ins für fremde Domains.
     */
    #[DataProvider('urls')]
    public function testAppDomainCarriesNoPath(string $absoluteUrl, string $expected): void {
        $this->assertSame($expected, $this->manifest->origin($absoluteUrl));
    }

    public function testManifestIsWellFormedXml(): void {
        $xml = $this->manifest->build(
            'https://cloud.example.com',
            'https://cloud.example.com/apps/pulse/embed',
            '0.18.0',
        );

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        $this->assertNotFalse($doc, 'Das erzeugte Manifest ist kein wohlgeformtes XML.');
    }

    /**
     * Der Kern der Sache: die Adressen im Manifest sind die der aufrufenden
     * Instanz. Steht hier ein Platzhalter, zeigt das Add-in nach dem Sideload
     * auf eine fremde Nextcloud.
     */
    public function testManifestCarriesTheGivenAddresses(): void {
        $xml = $this->manifest->build(
            'https://cloud.example.org',
            'https://cloud.example.org/nextcloud/apps/pulse/embed',
            '0.18.0',
        );

        $this->assertStringContainsString(
            '<AppDomain>https://cloud.example.org</AppDomain>',
            $xml,
        );
        $this->assertStringContainsString(
            '<SourceLocation DefaultValue="https://cloud.example.org/nextcloud/apps/pulse/embed"/>',
            $xml,
        );
        $this->assertStringContainsString('<Version>0.18.0.0</Version>', $xml);
        // Die Kennung identifiziert das Add-in, nicht die Installation: wechselt
        // sie, hält PowerPoint eine Aktualisierung für ein zweites Add-in.
        $this->assertStringContainsString(
            '<Id>' . AddinManifest::ADDIN_ID . '</Id>',
            $xml,
        );
    }

    /**
     * Quellsprache der App ist Englisch; Deutsch steht als Override daneben.
     * Office wählt danach die Sprache des vortragenden Rechners aus — nicht die
     * Sprache, in der jemand das Manifest heruntergeladen hat.
     */
    public function testDefaultLocaleIsTheSourceLanguage(): void {
        $xml = $this->manifest->build('https://x.example', 'https://x.example/apps/pulse/embed', '0.18.0');

        $this->assertStringContainsString('<DefaultLocale>en-US</DefaultLocale>', $xml);
        $this->assertStringContainsString('<Override Locale="de-de" Value="Pulse Live-Umfrage"/>', $xml);
    }

    /**
     * Adressen landen unmaskiert im XML, wenn man es nicht tut — ein `&` in der
     * URL macht das Manifest dann unlesbar.
     */
    public function testAddressesAreXmlEscaped(): void {
        $xml = $this->manifest->build(
            'https://cloud.example.com',
            'https://cloud.example.com/apps/pulse/embed?a=1&b=2',
            '0.18.0',
        );

        $this->assertStringContainsString('embed?a=1&amp;b=2', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    /**
     * Die Datei hängt an einem gewöhnlichen Link. Fehlt NoCSRFRequired,
     * antwortet Nextcloud mit 412 und der Download bleibt leer — dieselbe Falle
     * wie bei den Bild-Routen (siehe ImageRouteTest).
     */
    public function testManifestRouteAllowsAPlainLink(): void {
        $attributes = (new \ReflectionMethod(AddinController::class, 'manifest'))
            ->getAttributes(NoCSRFRequired::class);

        $this->assertNotEmpty(
            $attributes,
            'AddinController::manifest wird über einen Link geladen und braucht #[NoCSRFRequired].',
        );
    }
}
