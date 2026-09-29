#!/bin/sh
# SPDX-FileCopyrightText: 2026 hotochan123
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Run-through simulation (poll + four quiz variants) against the running
# instance. Uses the same throwaway user as dev/design-shots and creates it
# if it is missing.
#
#   dev/sim/run.sh           # play through, delete the rooms afterwards
#   KEEP=1 dev/sim/run.sh    # keep the rooms
#   PULSE_SIM_HOST=localhost:34071 dev/sim/run.sh
#                            # fresh route-cache key after new routes (README)
set -eu

HERE="$(cd "$(dirname "$0")" && pwd)"
SHOTS="$HERE/../design-shots"
CONTAINER="${CONTAINER:-nextcloud-nextcloud-1}"
UID_SIM="${PULSE_SIM_USER:-pulse-shots}"
PASS_FILE="$SHOTS/.shots-pass"

occ() { docker exec -u www-data "$CONTAINER" php /var/www/html/occ "$@"; }

# First check that Nextcloud is ready (`status -e`: exit ≠ 0 also in maintenance
# mode or with a pending upgrade). Otherwise the simulation would hit only 503s.
occ status -e >/dev/null 2>&1 || { echo "Nextcloud nicht bereit ($CONTAINER): gestoppt, Wartungsmodus oder Upgrade offen" >&2; exit 1; }
if [ "$UID_SIM" != "pulse-shots" ]; then
	# .shots-pass belongs to pulse-shots (design-shots shares it).
	[ -n "${PULSE_SIM_PASS:-}" ] || { echo "PULSE_SIM_USER=$UID_SIM: Passwort per PULSE_SIM_PASS mitgeben" >&2; exit 1; }
elif ! occ user:info "$UID_SIM" >/dev/null 2>&1; then
	echo "==> Wegwerf-Nutzer $UID_SIM anlegen"
	TMP_PASS="$(mktemp)"
	openssl rand -base64 18 > "$TMP_PASS"
	OC_PASS="$(cat "$TMP_PASS")" docker exec -u www-data -e OC_PASS \
		"$CONTAINER" php /var/www/html/occ user:add --password-from-env \
		--display-name="Pulse Shots" "$UID_SIM" || { rm -f "$TMP_PASS"; exit 1; }
	# Store it only now that the user exists with this password — and
	# use exactly this one, even if PULSE_SIM_PASS said otherwise.
	mv "$TMP_PASS" "$PASS_FILE"
	chmod 600 "$PASS_FILE"
	PULSE_SIM_PASS="$(cat "$PASS_FILE")"
fi
if [ -z "${PULSE_SIM_PASS:-}" ]; then
	[ -r "$PASS_FILE" ] || { echo "Passwort fehlt: $PASS_FILE (dev/design-shots/run.sh clean, dann neu)" >&2; exit 1; }
	PULSE_SIM_PASS="$(cat "$PASS_FILE")"
fi

# Straight to the container, not through the proxy: no rate limit, no TLS.
# The IP is not a trusted_domain — which is why sim.mjs sends Host: localhost.
if [ -z "${PULSE_SIM_URL:-}" ]; then
	IP="$(docker inspect "$CONTAINER" --format '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}' | awk '{print $1}')"
	PULSE_SIM_URL="http://$IP"
fi

PULSE_SIM_URL="$PULSE_SIM_URL" PULSE_SIM_USER="$UID_SIM" PULSE_SIM_PASS="$PULSE_SIM_PASS" \
	exec node "$HERE/sim.mjs"
