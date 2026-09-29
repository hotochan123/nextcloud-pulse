<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

/**
 * Baut das Office-Manifest des PowerPoint-Add-ins aus den Adressen DIESER Instanz.
 *
 * Warum erzeugt statt als Datei im Repo: ein Office-Manifest kennt keine
 * Variablen — <AppDomain> und <SourceLocation> müssen absolute URLs sein. Eine
 * mitgelieferte Vorlage müsste also jede Person an zwei Stellen von Hand
 * ändern; vergisst sie es, lädt das Add-in stumm eine fremde Adresse und der
 * Kasten auf der Folie bleibt leer. Hier gibt jede Instanz ihr eigenes,
 * fertiges Manifest heraus.
 */
class AddinManifest {
    /**
     * Kennung des Add-ins, NICHT der Installation: daran erkennt Office, dass
     * ein Manifest die neue Fassung eines schon eingefügten Add-ins ist. Pro
     * Instanz eine eigene GUID würde Aktualisierungen an Ort und Stelle
     * zerstören — der Wert bleibt deshalb fest.
     */
    public const ADDIN_ID = 'c941db89-f0ae-45c6-b685-e94eecc47c91';

    public const SUPPORT_URL = 'https://github.com/hotochan123/hotochan123-nextcloud-pulse';

    /**
     * @param string $origin    Schema+Host der Instanz, z. B. https://cloud.example.com
     * @param string $embedUrl  absolute URL der Einbett-Shell (/apps/pulse/embed)
     * @param string $version   Version der App aus info.xml, z. B. 0.18.0
     */
    public function build(string $origin, string $embedUrl, string $version): string {
        $id = self::ADDIN_ID;
        $ver = $this->officeVersion($version);
        $domain = $this->xml($origin);
        $source = $this->xml($embedUrl);
        $support = $this->xml(self::SUPPORT_URL);

        // Die Reihenfolge der Elemente ist im Office-Schema festgelegt; wird sie
        // getauscht, lehnt PowerPoint das Manifest ohne brauchbare Meldung ab.
        // Quellsprache ist Englisch (wie in der App), Deutsch kommt als Override
        // daneben — Office wählt nach der Sprache des vortragenden Rechners aus,
        // nicht nach der Sprache, in der jemand hier das Manifest herunterlädt.
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
     * Nur Schema, Host und Port: <AppDomain> darf keinen Pfad tragen.
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
     * Office verlangt in <Version> genau vier Zahlen. Aus „0.18.0" wird
     * „0.18.0.0", aus „0.19.0-beta.1" wird „0.19.0.0" — eine Vorabversion hat
     * in dem Feld keinen Platz, und ein Buchstabe darin lässt PowerPoint das
     * Manifest ablehnen.
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
