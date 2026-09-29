#!/bin/sh
# SPDX-FileCopyrightText: 2026 hotochan123
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Screenshots aller Pulse-Ansichten — Prüfstand für Design-Durchgänge.
#
#   dev/design-shots/run.sh            # aufnehmen (Räume danach weg)
#   dev/design-shots/run.sh keep       # Probe-Räume stehen lassen
#   dev/design-shots/run.sh clean      # Probe-Nutzer + Passwortdatei entfernen
#   dev/design-shots/run.sh store      # die fünf Bilder für die Store-Seite
#
# Legt einen Wegwerf-Nutzer an (die Probe-Räume brauchen einen Besitzer, und der
# Moderator-Screenshot einen Login), baut zwei Räume mit je einer Frage pro Typ,
# füllt sie über den Demo-Weg mit Stimmen und fotografiert jede Ansicht.
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

# Gegen welche Instanz? PULSE_HOST schlägt alles, sonst eine Zeile in .host
# (Store-Strecke: .store-host, dort muss der öffentliche Name stehen). Beide
# Dateien sind nicht im Repo. Bewusst ohne Voreinstellung: eine fremde Adresse
# als Rückfall schickte den Login des Wegwerf-Nutzers an eine Instanz, auf der
# er gar nicht existiert.
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

# Nutzer nur anlegen, wenn er fehlt — sonst bleibt das Passwort gültig.
if ! occ user:info "$UID_SHOTS" >/dev/null 2>&1; then
	echo "==> Wegwerf-Nutzer $UID_SHOTS anlegen"
	openssl rand -base64 18 > "$HERE/.shots-pass"
	chmod 600 "$HERE/.shots-pass"
	OC_PASS="$(cat "$HERE/.shots-pass")" docker exec -u www-data -e OC_PASS \
		"$CONTAINER" php /var/www/html/occ user:add --password-from-env \
		--display-name="Pulse Shots" "$UID_SHOTS"
fi

# Ohne das legt sich der Willkommens-Dialog von Nextcloud über den ersten
# Moderator-Screenshot.
occ user:setting "$UID_SHOTS" firstrunwizard show 99.0.0 >/dev/null

# Die Oberfläche der angemeldeten Ansichten folgt der Spracheinstellung des
# Nutzers, nicht dem Accept-Language des Browsers. Für die Store-Bilder muss sie
# englisch sein; dem übrigen Prüfstand schadet es nicht.
occ user:setting "$UID_SHOTS" core lang en >/dev/null

if [ "$MODE" = "store" ]; then
	# Die Store-Bilder brauchen eigene Räume (aufgeräumte Inhalte, eine
	# Rangliste ohne doppelte Namen) und den öffentlichen Hostnamen: er steht
	# im Beitritts-Link und im QR-Code, und der interne gehört nicht auf eine
	# öffentliche Store-Seite.
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
	# Räume im eigenen Tempo (pace.json) nur für die pace-Strecken, die
	# Einbett-Shell (ein Rennen im Rahmen) oder den vollen Lauf: allein
	# `race 300` dauert eine Weile, und die übrigen Strecken brauchen sie
	# nicht. Eine alte pace.json nennt sonst gelöschte Räume.
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
