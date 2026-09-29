#!/bin/sh
# SPDX-FileCopyrightText: 2026 hotochan123
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Screenshots of all Pulse views — the test bench for design passes.
#
#   dev/design-shots/run.sh            # capture (rooms removed afterwards)
#   dev/design-shots/run.sh keep       # leave the probe rooms in place
#   dev/design-shots/run.sh clean      # remove the probe user + password file
#   dev/design-shots/run.sh store      # the six images for the store page
#
# Creates a throwaway user (the probe rooms need an owner, and the
# moderator screenshot a login), builds two rooms with one question per type each,
# fills them with votes via the demo path and photographs every view.
set -eu

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
CONTAINER="${CONTAINER:-nextcloud-nextcloud-1}"
APP_IN_CONTAINER="${APP_IN_CONTAINER:-/var/www/html/apps/pulse}"
UID_SHOTS="${PULSE_SHOTS_UID:-pulse-shots}"
OUT_ENV="${OUT:-}"
OUT="${OUT:-$HERE/out}"
MODE="${1:-}"

occ() { docker exec -u www-data "$CONTAINER" php /var/www/html/occ "$@"; }
probe() { docker exec -u www-data "$CONTAINER" php "$APP_IN_CONTAINER/dev/design-shots/probe.php" "$@"; }

if [ "$MODE" = "clean" ]; then
	probe destroy "$UID_SHOTS" || true
	occ user:delete "$UID_SHOTS" || true
	rm -f "$HERE/.shots-pass"
	echo "Probe-Nutzer und Passwortdatei entfernt."
	exit 0
fi

# Against which instance? PULSE_HOST beats everything, otherwise one line in .host
# (store run: .store-host, which must contain the public name). Neither
# file is in the repository. Deliberately without a default: a foreign address
# as a fallback would send the throwaway user's login to an instance on which
# it does not even exist.
if [ "$MODE" = "store" ]; then HOST_FILE="$HERE/.store-host"; else HOST_FILE="$HERE/.host"; fi
if [ -z "${PULSE_HOST:-}" ] && [ -f "$HOST_FILE" ]; then
	PULSE_HOST="$(tr -d '[:space:]' < "$HOST_FILE")"
fi
[ -n "${PULSE_HOST:-}" ] || {
	echo "PULSE_HOST setzen oder $HOST_FILE anlegen (eine Zeile, z. B. https://nextcloud.example.com)" >&2
	exit 1
}
export PULSE_HOST

[ -x "$ROOT/tools/geckodriver" ] || {
	echo "tools/geckodriver fehlt. Holen:" >&2
	echo "  curl -sSL https://github.com/mozilla/geckodriver/releases/download/v0.35.0/geckodriver-v0.35.0-linux64.tar.gz | tar -xz -C $ROOT/tools" >&2
	exit 1
}
command -v firefox >/dev/null || { echo "firefox fehlt auf dem Host" >&2; exit 1; }

# Only create the user if it is missing — otherwise the password stays valid.
if ! occ user:info "$UID_SHOTS" >/dev/null 2>&1; then
	echo "==> Wegwerf-Nutzer $UID_SHOTS anlegen"
	openssl rand -base64 18 > "$HERE/.shots-pass"
	chmod 600 "$HERE/.shots-pass"
	OC_PASS="$(cat "$HERE/.shots-pass")" docker exec -u www-data -e OC_PASS \
		"$CONTAINER" php /var/www/html/occ user:add --password-from-env \
		--display-name="Pulse Shots" "$UID_SHOTS"
fi

# Without this, Nextcloud's welcome dialog covers the first
# moderator screenshot.
occ user:setting "$UID_SHOTS" firstrunwizard show 99.0.0 >/dev/null

# The UI of the logged-in views follows the user's language setting,
# not the browser's Accept-Language. For the store images it has to be
# English; it does no harm to the rest of the test bench.
occ user:setting "$UID_SHOTS" core lang en >/dev/null

if [ "$MODE" = "store" ]; then
	# The store images need their own rooms (tidy content, a
	# leaderboard without duplicate names) and the public host name: it appears
	# in the join link and in the QR code, and the internal one does not belong on a
	# public store page.
	[ -n "$OUT_ENV" ] || OUT="$HERE/out-store"
	echo "==> Store-Räume bauen"
	probe destroy "$UID_SHOTS" >/dev/null 2>&1 || true
	probe store "$UID_SHOTS" > "$HERE/store.json"

	echo "==> Aufnehmen ($PULSE_HOST)"
	rm -rf "$OUT"
	PULSE_SHOTS_ONLY=store node "$HERE/shoot.mjs" "$HERE/store.json" "$OUT" || SHOT_FAILED=1
else
	echo "==> Probe-Räume bauen"
	probe destroy "$UID_SHOTS" >/dev/null 2>&1 || true
	probe create "$UID_SHOTS" > "$HERE/probe.json"
	# Self-paced rooms (pace.json) only for the pace runs, the
	# embed shell (a race inside the frame) or the full run: `race 300`
	# alone takes a while, and the other runs do not need them.
	# Otherwise an old pace.json would name deleted rooms.
	case "${PULSE_SHOTS_ONLY:-}" in
		''|pace*|embed) probe pace-create "$UID_SHOTS" > "$HERE/pace.json" ;;
		*) rm -f "$HERE/pace.json" ;;
	esac

	echo "==> Aufnehmen ($PULSE_HOST)"
	rm -rf "$OUT"
	node "$HERE/shoot.mjs" "$HERE/probe.json" "$OUT" || SHOT_FAILED=1
fi

if [ "$MODE" != "keep" ]; then
	echo "==> Probe-Räume wegräumen"
	probe destroy "$UID_SHOTS"
else
	echo "Probe-Räume bleiben stehen (Codes in $HERE/probe.json)."
fi

echo
echo "Bilder: $OUT"
[ -z "${SHOT_FAILED:-}" ] || { echo "Ein Teil der Aufnahmen schlug fehl." >&2; exit 1; }
