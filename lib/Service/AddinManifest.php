<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

/**
 * Builds the Office manifest of the PowerPoint add-in from the addresses of THIS instance.
 *
 * Why generated instead of shipped as a file in the repo: an Office manifest has no
 * variables — <AppDomain> and <SourceLocation> must be absolute URLs. A
 * bundled template would have to be edited by hand in two places by
 * everyone; forget it and the add-in silently loads someone else's address and the
 * box on the slide stays empty. Here every instance hands out its own,
 * ready-to-use manifest.
 */
class AddinManifest {
    /**
     * Identifier of the add-in, NOT of the installation: it is how Office
     * recognizes a manifest as the new version of an add-in that is already
     * inserted. A separate GUID per instance would break in-place
     * updates — so the value stays fixed.
     */
    public const ADDIN_ID = 'c941db89-f0ae-45c6-b685-e94eecc47c91';

    public const SUPPORT_URL = 'https://github.com/hotochan123/hotochan123-nextcloud-pulse';

    /**
     * @param string $origin    scheme+host of the instance, e.g. https://cloud.example.com
     * @param string $embedUrl  absolute URL of the embed shell (/apps/pulse/embed)
     * @param string $version   app version from info.xml, e.g. 0.18.0
     */
    public function build(string $origin, string $embedUrl, string $version): string {
        $id = self::ADDIN_ID;
        $ver = $this->officeVersion($version);
        $domain = $this->xml($origin);
        $source = $this->xml($embedUrl);
        $support = $this->xml(self::SUPPORT_URL);

        // The order of the elements is fixed by the Office schema; swap it and
        // PowerPoint rejects the manifest without a useful message.
        // The source language is English (as in the app), German sits next to it as an
        // override — Office picks by the language of the presenting computer,
        // not by the language in which someone downloads the manifest here.
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <!--
          Pulse — Content-Add-in für PowerPoint (Live-Umfrage auf der Folie).

          VON DIESER NEXTCLOUD ERZEUGT, nicht von Hand bearbeiten: die Adressen
          unten stammen aus der Instanz, von der die Datei geladen wurde. Neu
          herunterladen unter Pulse -> Deck-Menü -> „PowerPoint add-in".
          Einbauanleitung: {$support}/blob/main/office-addin/README.md
        -->
        <OfficeApp
            xmlns="http://schemas.microsoft.com/office/appforoffice/1.1"
            xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
            xmlns:bt="http://schemas.microsoft.com/office/officeappbasictypes/1.0"
            xsi:type="ContentApp">
          <Id>{$id}</Id>
          <Version>{$ver}</Version>
          <ProviderName>hotochan123</ProviderName>
          <DefaultLocale>en-US</DefaultLocale>
          <DisplayName DefaultValue="Pulse live poll">
            <Override Locale="de-de" Value="Pulse Live-Umfrage"/>
          </DisplayName>
          <Description DefaultValue="Shows a live Pulse poll or quiz on the slide itself. Runs against your own Nextcloud.">
            <Override Locale="de-de" Value="Zeigt eine Pulse-Umfrage oder ein Quiz live auf der Folie. Läuft gegen die eigene Nextcloud."/>
          </Description>
          <SupportUrl DefaultValue="{$support}"/>
          <!-- Alle Navigationsziele des Add-ins liegen auf dieser Domain. -->
          <AppDomains>
            <AppDomain>{$domain}</AppDomain>
          </AppDomains>
          <!-- Presentation = PowerPoint -->
          <Hosts>
            <Host Name="Presentation"/>
          </Hosts>
          <DefaultSettings>
            <SourceLocation DefaultValue="{$source}"/>
          </DefaultSettings>
          <!-- ReadWriteDocument: Raumcode in den Dokument-Settings der .pptx ablegen. -->
          <Permissions>ReadWriteDocument</Permissions>
        </OfficeApp>

        XML;
    }

    /**
     * Only scheme, host and port: <AppDomain> must not carry a path.
     */
    public function origin(string $absoluteUrl): string {
        $parts = parse_url($absoluteUrl);
        if (!isset($parts['scheme'], $parts['host'])) {
            return rtrim($absoluteUrl, '/');
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $parts['scheme'] . '://' . $parts['host'] . $port;
    }

    /**
     * Office requires exactly four numbers in <Version>. "0.18.0" becomes
     * "0.18.0.0", "0.19.0-beta.1" becomes "0.19.0.0" — a pre-release has
     * no room in that field, and a letter in it makes PowerPoint reject the
     * manifest.
     */
    public function officeVersion(string $version): string {
        $numeric = preg_split('/[^0-9.]/', $version, 2)[0];
        $parts = array_map('intval', array_filter(explode('.', $numeric), 'strlen'));
        return implode('.', array_pad(array_slice($parts, 0, 4), 4, 0));
    }

    private function xml(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
