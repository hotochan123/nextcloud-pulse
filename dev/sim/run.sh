#!/bin/sh
# SPDX-FileCopyrightText: 2026 hotochan123
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Durchgangs-Simulation (Umfrage + vier Quiz-Varianten) gegen die laufende
# Instanz. Nutzt denselben Wegwerf-Nutzer wie dev/design-shots und legt ihn an,
# wenn er fehlt.
#
#   dev/sim/run.sh           # durchspielen, Räume danach löschen
#   KEEP=1 dev/sim/run.sh    # Räume stehen lassen
#   PULSE_SIM_HOST=localhost:34071 dev/sim/run.sh
#                            # frischer Routencache-Schlüssel nach neuen Routen (README)
set -eu

HERE="$(cd "$(dirname "$0")" && pwd)"
SHOTS="$HERE/../design-shots"
CONTAINER="${CONTAINER:-nextcloud-nextcloud-1}"
UID_SIM="${PULSE_SIM_USER:-pulse-shots}"
PASS_FILE="$SHOTS/.shots-pass"

occ() { docker exec -u www-data "$CONTAINER" php /var/www/html/occ "$@"; }

# Erst prüfen, ob Nextcloud bereit ist (`status -e`: Exit ≠ 0 auch bei
# Wartungsmodus oder offenem Upgrade). Sonst liefe die Simulation in lauter 503.
occ status -e >/dev/null 2>&1 || { echo "Nextcloud nicht bereit ($CONTAINER): gestoppt, Wartungsmodus oder Upgrade offen" >&2; exit 1; }
if [ "$UID_SIM" != "pulse-shots" ]; then
	# .shots-pass gehört pulse-shots (design-shots nutzt sie mit).
	[ -n "${PULSE_SIM_PASS:-}" ] || { echo "PULSE_SIM_USER=$UID_SIM: Passwort per PULSE_SIM_PASS mitgeben" >&2; exit 1; }
elif ! occ user:info "$UID_SIM" >/dev/null 2>&1; then
	echo "==> Wegwerf-Nutzer $UID_SIM anlegen"
	TMP_PASS="$(mktemp)"
	openssl rand -base64 18 > "$TMP_PASS"
	OC_PASS="$(cat "$TMP_PASS")" docker exec -u www-data -e OC_PASS \
		"$CONTAINER" php /var/www/html/occ user:add --password-from-env \
		--display-name="Pulse Shots" "$UID_SIM" || { rm -f "$TMP_PASS"; exit 1; }
	# Erst jetzt, wo es den Nutzer mit diesem Passwort gibt, ablegen — und
	# genau dieses nehmen, auch wenn PULSE_SIM_PASS etwas anderes vorgab.
	mv "$TMP_PASS" "$PASS_FILE"
	chmod 600 "$PASS_FILE"
	PULSE_SIM_PASS="$(cat "$PASS_FILE")"
fi
if [ -z "${PULSE_SIM_PASS:-}" ]; then
	[ -r "$PASS_FILE" ] || { echo "Passwort fehlt: $PASS_FILE (dev/design-shots/run.sh clean, dann neu)" >&2; exit 1; }
	PULSE_SIM_PASS="$(cat "$PASS_FILE")"
fi

# Direkt an den Container, nicht über den Proxy: kein Rate-Limit, kein TLS.
# Die IP ist keine trusted_domain — sim.mjs schickt deshalb Host: localhost.
if [ -z "${PULSE_SIM_URL:-}" ]; then
	IP="$(docker inspect "$CONTAINER" --format '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}' | awk '{print $1}')"
	PULSE_SIM_URL="http://$IP"
fi

PULSE_SIM_URL="$PULSE_SIM_URL" PULSE_SIM_USER="$UID_SIM" PULSE_SIM_PASS="$PULSE_SIM_PASS" \
	exec node "$HERE/sim.mjs"
