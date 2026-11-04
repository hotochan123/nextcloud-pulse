#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 hotochan123
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Browser tests of Pulse (dev/e2e/e2e.mjs): Playwright (Chromium) drives the moderator desk, the phone page and
# the projector page on a laptop, a tablet and a phone, light and dark. The factory check "e2e".
#
#   dev/e2e/run.sh                         # own throwaway Nextcloud (≈ 1–2 min install), then the cases
#   PULSE_E2E_ONLY=compose,poll dev/e2e/run.sh
#   PULSE_E2E_URL=http://127.0.0.1:18641 PULSE_E2E_USER=alice PULSE_E2E_PASS=… dev/e2e/run.sh
#                                          # against a running factory dev stack instead (no stack of its own)
#
# Default mode starts exactly two containers of its own, pulse-e2e-<pid> (Nextcloud IMAGE, SQLite) and
# pulse-e2e-<pid>-redis, on the default bridge without a published port, mounts this checkout read-only as
# custom_apps/pulse and removes them on exit (also on Ctrl-C; KEEP=1 keeps them). The browser runs in a third
# container, pulse-e2e-<pid>-browser (mcr.microsoft.com/playwright:v1.49.1-jammy), inside the network namespace
# of the Nextcloud container: it opens http://localhost, a trusted domain and a secure context. In URL mode the
# browser container uses the host network and opens only PULSE_E2E_URL. There is no default URL and no default
# container: production is never addressed.
#
# playwright-core (dev/e2e/package-lock.json) lives in the Docker volume pulse-e2e-node-1.49.1, filled by
# `npm ci` on the first run (needs the registry once), offline afterwards.
#
# Env: IMAGE (nextcloud:34-apache), KEEP=1, PULSE_E2E_URL / _USER / _PASS, and the knobs of e2e.mjs
# (PULSE_E2E_ONLY, PULSE_E2E_REPEAT, PULSE_E2E_TIMEOUT, PULSE_E2E_SELFTEST), passed through.
# Output: one line per case (ok <case> / FAIL <case>: <message>), last line E2E <ok>/<failed>; the report and a
# screenshot per red case go to a fresh directory named on stderr.
# Exit: 0 all green, 1 a case failed, 2 the stack or the browser did not come up.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
APP="$PWD"
IMAGE="${IMAGE:-nextcloud:34-apache}"
PW_IMAGE="mcr.microsoft.com/playwright:v1.49.1-jammy"
PW_VOLUME="pulse-e2e-node-1.49.1"
C="pulse-e2e-$$"
OUT="$(mktemp -d "${TMPDIR:-/tmp}/pulse-e2e.XXXXXX")"
log() { echo "[e2e] $*" >&2; }
fail() { echo "e2e: $*" >&2; exit 2; }

cleanup() {
	local rc=$?
	if [ "${KEEP:-}" = 1 ]; then
		log "KEEP=1: containers ${C:?}, ${C:?}-redis stay (docker rm -fv to remove)"
		docker rm -f "${C:?}-browser" >/dev/null 2>&1 || true
	else
		docker rm -fv "${C:?}-browser" "${C:?}" "${C:?}-redis" >/dev/null 2>&1 || true
	fi
	log "outputs: $OUT"
	exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

docker image inspect "$PW_IMAGE" >/dev/null 2>&1 || docker pull -q "$PW_IMAGE" >/dev/null 2>&1 \
	|| fail "image $PW_IMAGE missing and cannot be pulled"

# playwright-core into its volume, once
if ! docker run --rm -v "$PW_VOLUME:/deps" "$PW_IMAGE" test -f /deps/node_modules/playwright-core/package.json; then
	log "filling volume $PW_VOLUME (npm ci)"
	docker run --rm -v "$PW_VOLUME:/deps" -v "$APP/dev/e2e:/src:ro" -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 "$PW_IMAGE" \
		sh -c 'cp /src/package.json /src/package-lock.json /deps/ && cd /deps && npm ci --no-audit --no-fund' >&2 \
		|| fail "npm ci of playwright-core failed"
fi

if [ -n "${PULSE_E2E_URL:-}" ]; then
	[ -n "${PULSE_E2E_USER:-}" ] && [ -n "${PULSE_E2E_PASS:-}" ] || fail "PULSE_E2E_URL needs PULSE_E2E_USER and PULSE_E2E_PASS"
	URL="$PULSE_E2E_URL"
	USER_NAME="$PULSE_E2E_USER"
	USER_PASS="$PULSE_E2E_PASS"
	NET=(--network host)
	log "against $URL (no stack of its own)"
else
	if ! find js -maxdepth 1 -name '*.js' ! -name 'pulse-embed.js' 2>/dev/null | grep -q .; then
		log "no bundles in js/: npm run build"
		npm run build >&2 || fail "npm run build failed"
	fi
	pw() { head -c 18 /dev/urandom | base64 | tr -d '/+=' | head -c 20; }
	REDIS_PASS="$(pw)"; ADMIN_PASS="$(pw)"; USER_PASS="$(pw)"
	USER_NAME=alice
	docker run -d --name "$C-redis" redis:7-alpine redis-server --requirepass "$REDIS_PASS" >/dev/null \
		|| fail "redis did not start"
	docker run -d --name "$C" --link "$C-redis:redis" -v "$APP:/var/www/html/custom_apps/pulse:ro" \
		-e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD="$ADMIN_PASS" -e NEXTCLOUD_TRUSTED_DOMAINS=localhost \
		-e SQLITE_DATABASE=nextcloud -e REDIS_HOST=redis -e REDIS_HOST_PASSWORD="$REDIS_PASS" "$IMAGE" >/dev/null \
		|| fail "Nextcloud container did not start"
	log "$C: Nextcloud $IMAGE installs (≈ 1–2 min)"
	occ() { docker exec -u 33 "$C" php occ "$@"; }
	for _ in $(seq 180); do
		occ status --output=json 2>/dev/null | grep -q '"installed":true' && break
		sleep 2
	done
	occ status --output=json 2>/dev/null | grep -q '"installed":true' || fail "Nextcloud did not finish installing"
	occ config:system:set has_internet_connection --value=false --type=boolean >/dev/null
	occ config:system:set appstoreenabled --value=false --type=boolean >/dev/null
	occ config:system:set default_language --value=en >/dev/null
	occ background:cron >/dev/null
	# the welcome dialog of a fresh account would lie over the desk
	occ app:disable firstrunwizard >/dev/null 2>&1 || true
	out="$(occ app:enable pulse 2>&1)" || fail "occ app:enable pulse failed: $out"
	docker exec -u 33 -e OC_PASS="$USER_PASS" "$C" php occ user:add --password-from-env --display-name=Alice alice >/dev/null \
		|| fail "user alice could not be added"
	occ user:setting alice core lang en >/dev/null
	# Right after app:enable the web server may still answer 404 on Pulse's routes. Probe with another Host
	# header (localhost:1) so the route cache of "localhost", which the browser uses, is not filled without Pulse.
	ready=""
	for _ in $(seq 60); do
		code="$(docker exec "$C" curl -s -o /dev/null -w '%{http_code}' -H 'Host: localhost:1' \
			http://127.0.0.1/index.php/apps/pulse/api/1.0/rooms || true)"
		[ "$code" = 401 ] && { ready=1; break; }
		sleep 1
	done
	[ -n "$ready" ] || fail "Pulse's API does not answer 401 to an anonymous request (last HTTP $code)"
	URL="http://localhost"
	NET=(--network "container:$C")
fi

log "browser cases against $URL"
set +e
docker run --rm --init --name "$C-browser" "${NET[@]}" --shm-size=1g \
	-v "$APP/dev/e2e:/work:ro" -v "$PW_VOLUME:/deps:ro" -v "$OUT:/out" \
	-e PULSE_E2E_URL="$URL" -e PULSE_E2E_USER="$USER_NAME" -e PULSE_E2E_PASS="$USER_PASS" \
	-e PULSE_E2E_ONLY -e PULSE_E2E_REPEAT -e PULSE_E2E_TIMEOUT -e PULSE_E2E_SELFTEST -e PULSE_E2E_SHOTS \
	-e PULSE_E2E_PW=/deps/node_modules/playwright-core/index.mjs -e PULSE_E2E_OUT=/out \
	-w /work "$PW_IMAGE" node /work/e2e.mjs
rc=$?
set -e
# 0 and 1 come from e2e.mjs; anything else (no browser, crash) counts as "did not come up"
case "$rc" in 0 | 1) exit "$rc" ;; 130) exit 130 ;; *) fail "browser run ended with exit $rc" ;; esac
