<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Test bootstrap — deliberately WITHOUT `lib/base.php`.
 *
 * The unit tests only touch pure computation (scoring, tallying,
 * text normalisation, code generation). For that the server's Composer
 * autoloader (provides OCP\…, including the Entity base class) plus a PSR-4 loader
 * for the app itself is enough. Benefits: fast, no session/config setup — and the
 * tests cannot touch the live instance or its database at all.
 *
 * Runs in the Nextcloud container (the PHP extensions tokenizer/xmlwriter are
 * missing on the host):
 *   docker exec -u www-data nextcloud-nextcloud-1 \
 *     php /var/www/html/apps/pulse/tools/phpunit.phar \
 *     -c /var/www/html/apps/pulse/phpunit.xml
 *
 * NEXTCLOUD_ROOT overrides the server path if the app lives somewhere else.
 */

$ncRoot = getenv('NEXTCLOUD_ROOT') ?: dirname(__DIR__, 3);
$autoload = $ncRoot . '/lib/composer/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Nextcloud-Autoloader nicht gefunden: $autoload\n"
        . "Die App muss unter <nextcloud>/apps/ liegen oder NEXTCLOUD_ROOT gesetzt sein.\n");
    exit(1);
}

require $autoload;

// The server autoloader does not know the PSR packages under 3rdparty/; but
// ITimeFactory extends Psr\Clock\ClockInterface, and without that file the
// time source cannot be mocked.
$psrClock = $ncRoot . '/3rdparty/psr/clock/src/ClockInterface.php';
if (!interface_exists(\Psr\Clock\ClockInterface::class, false) && is_file($psrClock)) {
    require $psrClock;
}

// Likewise Doctrine\DBAL: IDBConnection::quote() has IQueryBuilder::PARAM_STR as
// its default value, PHPUnit evaluates it when mocking, and IQueryBuilder pulls in
// DBAL constants. And Symfony String + HttpFoundation: DataDownloadResponse
// (CSV export, ControllerInputTest) uses them to build the file name in the header.
// Only these namespaces, not the whole 3rdparty autoloader.
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

// Shared test building blocks (e.g. PaceStateTestCase): OCA\Pulse\Tests\Unit\X
// -> tests/unit/X.php. PHPUnit itself only loads files ending in "Test.php".
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
