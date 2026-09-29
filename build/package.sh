#!/bin/sh
# SPDX-FileCopyrightText: 2026 hotochan123
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Builds the release archive for the Nextcloud App Store.
#
#   build/package.sh                  # build + pack (unsigned)
#   PULSE_KEY=~/pulse.key PULSE_CRT=~/pulse.crt build/package.sh   # + sign
#   SKIP_BUILD=1 build/package.sh     # leave js/ as it is
#
# Result: build/pulse-<version>.tar.gz with exactly one folder `pulse/` inside —
# that is what the store expects. Sources (src/, node_modules/, docs/, tests/) stay
# out; what ships is the finished bundle in js/.
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/build/dist"
STAGE="$DIST/pulse"
CONTAINER="${CONTAINER:-nextcloud-nextcloud-1}"
APP_IN_CONTAINER="${APP_IN_CONTAINER:-/var/www/html/apps/pulse}"

VERSION="$(sed -n 's/.*<version>\(.*\)<\/version>.*/\1/p' "$ROOT/appinfo/info.xml" | head -n1)"
[ -n "$VERSION" ] || { echo "Version nicht aus appinfo/info.xml lesbar" >&2; exit 1; }
ARCHIVE="$ROOT/build/pulse-$VERSION.tar.gz"

if [ -z "${SKIP_BUILD:-}" ]; then
	echo "==> npm run build"
	(cd "$ROOT" && npm run --silent build >/dev/null)
fi
[ -f "$ROOT/js/pulse-main.js" ] || { echo "js/pulse-main.js fehlt — erst bauen" >&2; exit 1; }

echo "==> Übersetzungen prüfen"
(cd "$ROOT" && npm run --silent l10n:check)

echo "==> info.xml prüfen"
if command -v xmllint >/dev/null 2>&1; then
	SCHEMA="$ROOT/build/info.xsd"
	[ -f "$SCHEMA" ] || curl -sSL -o "$SCHEMA" https://apps.nextcloud.com/schema/apps/info.xsd
	xmllint --noout --schema "$SCHEMA" "$ROOT/appinfo/info.xml"
else
	echo "    xmllint fehlt — Schema-Prüfung übersprungen"
fi

echo "==> Dateien zusammenstellen"
rm -rf "$DIST"
mkdir -p "$STAGE"
# Ship only what the app needs at runtime, plus the licence and the
# changelog (the store reads the release notes from CHANGELOG.md).
for item in appinfo css img js l10n lib templates office-addin \
		README.md LICENSE CHANGELOG.md; do
	[ -e "$ROOT/$item" ] || { echo "fehlt: $item" >&2; exit 1; }
	cp -a "$ROOT/$item" "$STAGE/"
done
rm -f "$STAGE"/js/*.map

# Nothing unexpected in the archive: no sources, no throwaway scripts, no
# key material. Better to abort here than to upload it.
if find "$STAGE" \( -name 'node_modules' -o -name '*.key' -o -name '*.pem' \
		-o -name '_*.php' -o -name '*.map' \) -print | grep -q .; then
	echo "Archiv enthält Dateien, die nicht ausgeliefert werden dürfen:" >&2
	find "$STAGE" \( -name 'node_modules' -o -name '*.key' -o -name '*.pem' \
		-o -name '_*.php' -o -name '*.map' \) -print >&2
	exit 1
fi

if [ -n "${PULSE_KEY:-}" ] && [ -n "${PULSE_CRT:-}" ]; then
	echo "==> Signieren (occ integrity:sign-app im Container $CONTAINER)"
	# The key stays outside the repository; it only goes into the container
	# for this run and is deleted afterwards.
	docker cp "$PULSE_KEY" "$CONTAINER:/tmp/pulse-sign.key"
	docker cp "$PULSE_CRT" "$CONTAINER:/tmp/pulse-sign.crt"
	docker exec "$CONTAINER" chown www-data /tmp/pulse-sign.key /tmp/pulse-sign.crt
	docker exec -u www-data "$CONTAINER" php /var/www/html/occ integrity:sign-app \
		--path="$APP_IN_CONTAINER/build/dist/pulse" \
		--privateKey=/tmp/pulse-sign.key \
		--certificate=/tmp/pulse-sign.crt
	docker exec "$CONTAINER" rm -f /tmp/pulse-sign.key /tmp/pulse-sign.crt
	[ -f "$STAGE/appinfo/signature.json" ] || {
		echo "signature.json wurde nicht erzeugt" >&2; exit 1; }
else
	echo "==> Ohne Signatur (PULSE_KEY/PULSE_CRT nicht gesetzt)"
	echo "    Der App Store nimmt nur signierte Releases an — siehe docs/APPSTORE.md"
fi

echo "==> Archiv packen"
MTIME="$(cd "$ROOT" && git log -1 --format=%cI 2>/dev/null || true)"
rm -f "$ARCHIVE"
tar -czf "$ARCHIVE" -C "$DIST" \
	--owner=0 --group=0 --numeric-owner --sort=name \
	${MTIME:+--mtime="$MTIME"} \
	pulse

echo
echo "Archiv:  $ARCHIVE"
echo "Größe:   $(ls -lh "$ARCHIVE" | awk '{print $5}')"
echo "SHA256:  $(sha256sum "$ARCHIVE" | cut -d' ' -f1)"
if [ -n "${PULSE_KEY:-}" ]; then
	echo "Signatur fürs Store-API (base64, sha512 über das Archiv):"
	openssl dgst -sha512 -sign "$PULSE_KEY" "$ARCHIVE" | openssl base64 -A
	echo
fi
