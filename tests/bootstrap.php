<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Test-Bootstrap — bewusst OHNE `lib/base.php`.
 *
 * Die Unit-Tests fassen nur reine Rechenlogik an (Wertung, Auszählung,
 * Textnormalisierung, Code-Erzeugung). Dafür genügt der Composer-Autoloader des
 * Servers (liefert OCP\…, u. a. die Entity-Basisklasse) plus ein PSR-4-Loader
 * für die App selbst. Vorteile: schnell, kein Session-/Config-Setup — und die
 * Tests können die Live-Instanz und ihre Datenbank gar nicht anfassen.
 *
 * Läuft im Nextcloud-Container (PHP-Erweiterungen tokenizer/xmlwriter fehlen
 * auf dem Host):
 *   docker exec -u www-data nextcloud-nextcloud-1 \
 *     php /var/www/html/apps/pulse/tools/phpunit.phar \
 *     -c /var/www/html/apps/pulse/phpunit.xml
 *
 * NEXTCLOUD_ROOT überschreibt den Server-Pfad, falls die App woanders liegt.
 */

$ncRoot = getenv('NEXTCLOUD_ROOT') ?: dirname(__DIR__, 3);
$autoload = $ncRoot . '/lib/composer/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Nextcloud-Autoloader nicht gefunden: $autoload\n"
        . "Die App muss unter <nextcloud>/apps/ liegen oder NEXTCLOUD_ROOT gesetzt sein.\n");
    exit(1);
}

require $autoload;

// Der Server-Autoloader kennt die PSR-Pakete unter 3rdparty/ nicht; ITimeFactory
// erbt aber von Psr\Clock\ClockInterface, und ohne die Datei lässt sich die
// Zeitquelle nicht mocken.
$psrClock = $ncRoot . '/3rdparty/psr/clock/src/ClockInterface.php';
if (!interface_exists(\Psr\Clock\ClockInterface::class, false) && is_file($psrClock)) {
    require $psrClock;
}

// Ebenso Doctrine\DBAL: IDBConnection::quote() hat IQueryBuilder::PARAM_STR als
// Vorgabewert, PHPUnit wertet ihn beim Mocken aus, und IQueryBuilder zieht
// DBAL-Konstanten nach. Und Symfony String + HttpFoundation: DataDownloadResponse
// (CSV-Export, ControllerInputTest) baut damit den Dateinamen im Header. Nur
// diese Namensräume, nicht der ganze 3rdparty-Autoloader.
spl_autoload_register(static function (string $class) use ($ncRoot): void {
    foreach ([
        'Doctrine\\DBAL\\' => '/3rdparty/doctrine/dbal/src/',
        'Symfony\\Component\\String\\' => '/3rdparty/symfony/string/',
        'Symfony\\Component\\HttpFoundation\\' => '/3rdparty/symfony/http-foundation/',
    ] as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $file = $ncRoot . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }
});

spl_autoload_register(static function (string $class): void {
    $prefix = 'OCA\\Pulse\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/../lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Gemeinsame Test-Bausteine (z. B. PaceStateTestCase): OCA\Pulse\Tests\Unit\X
// -> tests/unit/X.php. PHPUnit selbst lädt nur Dateien auf „Test.php".
spl_autoload_register(static function (string $class): void {
    $prefix = 'OCA\\Pulse\\Tests\\Unit\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/unit/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
