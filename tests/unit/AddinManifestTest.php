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
 * The instance generates the Office manifest because a manifest cannot hold
 * variables. If that goes wrong, nobody notices when clicking through: PowerPoint
 * reports nothing useful, the box on the slide simply stays empty.
 */
class AddinManifestTest extends TestCase {
    private AddinManifest $manifest;

    protected function setUp(): void {
        $this->manifest = new AddinManifest();
    }

    public static function versions(): array {
        return [
            'ordinary'            => ['0.18.0', '0.18.0.0'],
            'pre-release'         => ['0.19.0-beta.1', '0.19.0.0'],
            'build metadata'      => ['0.18.0+build.7', '0.18.0.0'],
            'major version only'  => ['1', '1.0.0.0'],
            'too many parts'      => ['2.3.4.5.6', '2.3.4.5'],
            'empty'               => ['', '0.0.0.0'],
        ];
    }

    /**
     * Office requires exactly four numbers. One letter in the field — say from
     * "0.19.0-beta.1" — and PowerPoint rejects the whole manifest.
     */
    #[DataProvider('versions')]
    public function testVersionAlwaysHasFourNumbers(string $appVersion, string $expected): void {
        $this->assertSame($expected, $this->manifest->officeVersion($appVersion));
    }

    public static function urls(): array {
        return [
            'root'           => ['https://cloud.example.com/', 'https://cloud.example.com'],
            'subfolder'      => ['https://cloud.example.com/nextcloud/', 'https://cloud.example.com'],
            'custom port'    => ['https://cloud.example.com:8443/nextcloud/', 'https://cloud.example.com:8443'],
            'without TLS'    => ['http://localhost/nc/', 'http://localhost'],
        ];
    }

    /**
     * <AppDomain> may carry only scheme, host and port. With a path attached,
     * Office treats the add-in's navigation targets as foreign domains.
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

        $this->assertNotFalse($doc, 'The generated manifest is not well-formed XML.');
    }

    /**
     * The heart of the matter: the addresses in the manifest are those of the calling
     * instance. If a placeholder ends up here, the add-in points to someone else's
     * Nextcloud after sideloading.
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
        // The ID identifies the add-in, not the installation: if it changes,
        // PowerPoint takes an update for a second add-in.
        $this->assertStringContainsString(
            '<Id>' . AddinManifest::ADDIN_ID . '</Id>',
            $xml,
        );
    }

    /**
     * The app's source language is English; German sits next to it as an override.
     * Office then picks by the language of the presenting computer — not by the
     * language in which someone downloaded the manifest.
     */
    public function testDefaultLocaleIsTheSourceLanguage(): void {
        $xml = $this->manifest->build('https://x.example', 'https://x.example/apps/pulse/embed', '0.18.0');

        $this->assertStringContainsString('<DefaultLocale>en-US</DefaultLocale>', $xml);
        $this->assertStringContainsString('<Override Locale="de-de" Value="Pulse Live-Umfrage"/>', $xml);
    }

    /**
     * The add-in asks for the lowest permission level (security review, L11).
     * js/pulse-embed.js only uses the Settings API (the room code stored in
     * the .pptx), which Restricted covers; ReadWriteDocument would let script
     * that gets into /embed or /screen read and rewrite the whole deck. The
     * ID stays, so Office still updates the add-in in place.
     */
    public function testManifestAsksForRestrictedOnly(): void {
        $xml = $this->manifest->build('https://x.example', 'https://x.example/apps/pulse/embed', '0.18.0');
        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc);
        $doc->registerXPathNamespace('o', 'http://schemas.microsoft.com/office/appforoffice/1.1');

        $this->assertSame(['Restricted'], array_map('strval', $doc->xpath('/o:OfficeApp/o:Permissions')));
        $this->assertSame('c941db89-f0ae-45c6-b685-e94eecc47c91', AddinManifest::ADDIN_ID);

        // What the embed shell calls must stay within Restricted: the
        // Settings methods get/set/saveAsync — no handlers, no host APIs.
        $embed = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pulse-embed.js');
        $this->assertStringContainsString('settingsApi.saveAsync', $embed);
        foreach (['addHandlerAsync', 'PowerPoint.run', 'getFileAsync', 'getSelectedDataAsync', 'setSelectedDataAsync', 'customXmlParts', 'bindings'] as $api) {
            $this->assertStringNotContainsString($api, $embed, $api . ' needs more than Restricted — raise <Permissions> with it');
        }
    }

    /**
     * Addresses end up unescaped in the XML unless you escape them — an `&` in the
     * URL then makes the manifest unreadable.
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
     * The file hangs off a plain link. Without NoCSRFRequired,
     * Nextcloud answers with 412 and the download stays empty — the same trap
     * as with the image routes (see ImageRouteTest).
     */
    public function testManifestRouteAllowsAPlainLink(): void {
        $attributes = (new \ReflectionMethod(AddinController::class, 'manifest'))
            ->getAttributes(NoCSRFRequired::class);

        $this->assertNotEmpty(
            $attributes,
            'AddinController::manifest is loaded through a link and needs #[NoCSRFRequired].',
        );
    }
}
