#!/bin/sh
# SPDX-FileCopyrightText: 2026 hotochan123
# SPDX-License-Identifier: AGPL-3.0-or-later
# Unit-Tests im Nextcloud-Container ausführen.
#
# Warum im Container: PHPUnit braucht die Erweiterungen tokenizer und xmlwriter,
# die dem PHP-CLI auf dem Host fehlen. Der Container hat sie (PHP 8.5).
# Die Tests selbst laden KEIN lib/base.php und fassen die Datenbank nicht an.
#
#   tests/run.sh                 # alle Tests
#   tests/run.sh --filter Quiz   # weitergereichte PHPUnit-Argumente
#
# CONTAINER überschreibt den Container-Namen, APP_DIR den Pfad im Container.
set -eu

CONTAINER="${CONTAINER:-nextcloud-nextcloud-1}"
APP_DIR="${APP_DIR:-/var/www/html/apps/pulse}"
PHAR_URL="https://phar.phpunit.de/phpunit-11.phar"

# Die PHAR liegt bewusst außerhalb der Versionsverwaltung (tools/ ist ignoriert).
if [ ! -f "$(dirname "$0")/../tools/phpunit.phar" ]; then
	echo "PHPUnit fehlt — lade $PHAR_URL nach tools/phpunit.phar"
	mkdir -p "$(dirname "$0")/../tools"
	curl -sSL -o "$(dirname "$0")/../tools/phpunit.phar" "$PHAR_URL"
fi

exec docker exec -u www-data "$CONTAINER" \
	php "$APP_DIR/tools/phpunit.phar" -c "$APP_DIR/phpunit.xml" "$@"
