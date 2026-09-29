#!/bin/sh
# SPDX-FileCopyrightText: 2026 hotochan123
# SPDX-License-Identifier: AGPL-3.0-or-later
# Run the unit tests inside the Nextcloud container.
#
# Why inside the container: PHPUnit needs the tokenizer and xmlwriter extensions,
# which the PHP CLI on the host lacks. The container has them (PHP 8.5).
# The tests themselves load NO lib/base.php and do not touch the database.
#
#   tests/run.sh                 # all tests
#   tests/run.sh --filter Quiz   # arguments passed on to PHPUnit
#
# CONTAINER overrides the container name, APP_DIR the path inside the container.
set -eu

CONTAINER="${CONTAINER:-nextcloud-nextcloud-1}"
APP_DIR="${APP_DIR:-/var/www/html/apps/pulse}"
PHAR_URL="https://phar.phpunit.de/phpunit-11.phar"

# The PHAR is deliberately kept out of version control (tools/ is ignored).
if [ ! -f "$(dirname "$0")/../tools/phpunit.phar" ]; then
	echo "PHPUnit fehlt — lade $PHAR_URL nach tools/phpunit.phar"
	mkdir -p "$(dirname "$0")/../tools"
	curl -sSL -o "$(dirname "$0")/../tools/phpunit.phar" "$PHAR_URL"
fi

exec docker exec -u www-data "$CONTAINER" \
	php "$APP_DIR/tools/phpunit.phar" -c "$APP_DIR/phpunit.xml" "$@"
