#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 hotochan123
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The load run (dev/sim/load.mjs) against its own throwaway Nextcloud, with its pass mark (no 5xx/timeout, every
# phone joined, p95 /state < LOAD_P95_MAX, default 300 ms): the factory check "load". It starts exactly three
# containers of its own, pulse-load-<pid> (Nextcloud), pulse-load-<pid>-db and pulse-load-<pid>-redis, on the
# default bridge (--link, no network of its own, no published port), mounts this checkout read-only as
# custom_apps/pulse, and removes them again on exit (also on Ctrl-C). It never touches any other container.
#
#   dev/sim/load-check.sh                # ≈ 4–5 min: install ≈ 1–2 min, load 90 s, final standings 30 s
#   KEEP=1 dev/sim/load-check.sh         # keep the containers (their names go to stderr)
#
# Env: IMAGE (default nextcloud:34-apache), DB=pgsql|sqlite|mariadb|mysql (default pgsql), KEEP=1, and the knobs of
# load.mjs (LOAD_PHONES, LOAD_SECS, LOAD_TAIL_SECS, LOAD_P95_MAX), passed through.
# Exit: that of load.mjs (0 pass, 1 below the mark or aborted), 2 when the stack does not come up.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
APP="$PWD"
IMAGE="${IMAGE:-nextcloud:34-apache}"
DB="${DB:-pgsql}"
log() { echo "[load-check] $*" >&2; }
fail() { echo "load-check: $*" >&2; exit 2; }
case "$DB" in pgsql | sqlite | mariadb | mysql) ;; *) fail "DB must be pgsql, sqlite, mariadb or mysql, not '$DB'" ;; esac

C="pulse-load-$$"
cleanup() {
	local rc=$?
	if [ "${KEEP:-}" = 1 ]; then
		log "KEEP=1: containers ${C:?} ${C:?}-db ${C:?}-redis stay (docker rm -fv to remove)"
	else
		docker rm -fv "${C:?}" "${C:?}-db" "${C:?}-redis" >/dev/null 2>&1 || true
	fi
	exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

# The bundles: built by the check "build" before; run on its own, build them here.
if ! find js -maxdepth 1 -name '*.js' ! -name 'pulse-embed.js' 2>/dev/null | grep -q .; then
	log "no bundles in js/: npm run build"
	npm run build >&2 || fail "npm run build failed"
fi

pw() { head -c 18 /dev/urandom | base64 | tr -d '/+=' | head -c 20; }
DB_PASS="$(pw)"; REDIS_PASS="$(pw)"; ADMIN_PASS="$(pw)"; USER_PASS="$(pw)"

LINKS=(--link "$C-redis:redis")
DB_ENV=()
case "$DB" in
pgsql)
	docker run -d --name "$C-db" -e POSTGRES_DB=nextcloud -e POSTGRES_USER=nextcloud -e POSTGRES_PASSWORD="$DB_PASS" \
		postgres:17-alpine >/dev/null
	DB_ENV=(-e POSTGRES_HOST=db -e POSTGRES_DB=nextcloud -e POSTGRES_USER=nextcloud -e POSTGRES_PASSWORD="$DB_PASS")
	LINKS+=(--link "$C-db:db")
	for _ in $(seq 60); do docker exec "$C-db" pg_isready -U nextcloud >/dev/null 2>&1 && break; sleep 1; done ;;
mariadb | mysql)
	img=mariadb:11.8; pre=MARIADB; admin=mariadb-admin
	[ "$DB" = mysql ] && { img=mysql:8.4; pre=MYSQL; admin=mysqladmin; }
	docker run -d --name "$C-db" -e "${pre}_DATABASE=nextcloud" -e "${pre}_USER=nextcloud" -e "${pre}_PASSWORD=$DB_PASS" \
		-e "${pre}_RANDOM_ROOT_PASSWORD=1" "$img" --transaction-isolation=READ-COMMITTED --binlog-format=ROW >/dev/null
	DB_ENV=(-e MYSQL_HOST=db -e MYSQL_DATABASE=nextcloud -e MYSQL_USER=nextcloud -e MYSQL_PASSWORD="$DB_PASS")
	LINKS+=(--link "$C-db:db")
	for _ in $(seq 120); do docker exec "$C-db" sh -c "$admin ping -unextcloud -p\"$DB_PASS\" --silent" >/dev/null 2>&1 && break; sleep 1; done ;;
sqlite)
	DB_ENV=(-e SQLITE_DATABASE=nextcloud) ;;
esac
docker run -d --name "$C-redis" redis:7-alpine redis-server --requirepass "$REDIS_PASS" >/dev/null
docker run -d --name "$C" "${LINKS[@]}" -v "$APP:/var/www/html/custom_apps/pulse:ro" \
	-e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD="$ADMIN_PASS" -e NEXTCLOUD_TRUSTED_DOMAINS=localhost \
	-e REDIS_HOST=redis -e REDIS_HOST_PASSWORD="$REDIS_PASS" "${DB_ENV[@]}" "$IMAGE" >/dev/null
log "$C: Nextcloud $IMAGE with $DB installs (≈ 1–2 min)"

occ() { docker exec -u 33 "$C" php occ "$@"; }
for _ in $(seq 180); do
	occ status --output=json 2>/dev/null | grep -q '"installed":true' && break
	sleep 2
done
occ status --output=json 2>/dev/null | grep -q '"installed":true' || fail "Nextcloud did not finish installing"
occ config:system:set has_internet_connection --value=false --type=boolean >/dev/null
occ config:system:set appstoreenabled --value=false --type=boolean >/dev/null
occ background:cron >/dev/null
out="$(occ app:enable pulse 2>&1)" || fail "occ app:enable pulse failed: $out"
docker exec -u 33 -e OC_PASS="$USER_PASS" "$C" php occ user:add --password-from-env --display-name=Alice alice >/dev/null
occ user:setting alice core lang en >/dev/null

IP="$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$C")"
[ -n "$IP" ] || fail "no address for $C"
# Right after app:enable the web server may still answer 404 on Pulse's routes. Probe with another Host header
# (localhost:1) so that the route cache of "localhost", which load.mjs uses, is not filled without Pulse.
ready=""
for _ in $(seq 60); do
	code="$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: localhost:1' -H 'OCS-APIRequest: true' \
		"http://$IP/index.php/apps/pulse/api/1.0/rooms" || true)"
	[ "$code" = 401 ] && { ready=1; break; }
	sleep 1
done
[ -n "$ready" ] || fail "Pulse's API does not answer 401 to an anonymous request (last HTTP $code)"

log "load run against $C ($IP)"
set +e
PULSE_SIM_URL="http://$IP" PULSE_SIM_USER=alice PULSE_SIM_PASS="$USER_PASS" node dev/sim/load.mjs
rc=$?
set -e
exit "$rc"
