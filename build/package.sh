#!/bin/sh
# SPDX-FileCopyrightText: 2026 hotochan123
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Builds the release archive for the Nextcloud App Store.
#
#   build/package.sh                  # build + pack (unsigned)
#   PULSE_KEY=~/pulse.key PULSE_CRT=~/pulse.crt build/package.sh   # + sign
#   SKIP_BUILD=1 build/package.sh     # leave js/ as it is
#   ALLOW_UNRELEASED=1 build/package.sh   # test archive of an unreleased state
#
# Result: build/pulse-<version>.tar.gz with exactly one folder `pulse/` inside —
# that is what the store expects. Sources (src/, node_modules/, docs/, tests/) stay
# out; what ships is the finished bundle in js/. Directories and executables are
# packed as 755, all other files as 644, owned by 0:0, whatever the umask of
# the machine that packs.
#
# Release state: appinfo/info.xml and package.json must carry the same version,
# and CHANGELOG.md needs the section the store reads the release notes from:
# "## [<version>]" for a release, "## [Unreleased]" for a pre-release (a
# version with a "-" suffix such as 0.19.0-beta.1 — the store takes the notes
# of those from [Unreleased] only). ALLOW_UNRELEASED=1 turns both checks into
# warnings; CI uses it to pack whatever state it builds. Never upload such an
# archive.
#
# Signing runs `occ integrity:sign-app` from Nextcloud's source code in a
# disposable container: `docker create --network none` from SIGN_IMAGE
# (default: the image of the container SIGN_FROM, default
# nextcloud-nextcloud-1, if it exists — only its image name is read —,
# otherwise nextcloud:34-apache). The command works without an installed
# instance (it is registered before the "installed" check). Staged app, key
# and certificate are copied into the stopped container, occ runs once, only
# appinfo/signature.json comes back, and the container is removed with the key
# in it — right after signing and, on any error or interrupt, by the exit trap.
# No server that serves traffic ever holds the key, and nothing on the host
# has to be shared with Docker.
#
# CONTAINER=<name> is the explicit opt-in to sign inside that running
# container instead (OCC, default /var/www/html/occ there): a copy of the
# staged app in a private temporary directory under the container's /tmp,
# never the installed app. While occ runs, the key is readable for the web
# server user of that container — do not point it at a production server.
set -eu
umask 022

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# Empty = sign in a disposable container (see above); a name = in that one.
CONTAINER="${CONTAINER:-}"
if [ -n "$CONTAINER" ]; then
	OCC="${OCC:-/var/www/html/occ}"
else
	OCC="${OCC:-/usr/src/nextcloud/occ}"
fi
SCHEMA_URL=https://apps.nextcloud.com/schema/apps/info.xsd

TMP=""
CT_TMP=""
SIGN_CT=""

die() {
	echo "Error: $*" >&2
	exit 1
}

warn() {
	echo "    Warning: $*" >&2
}

# Removes the signing directory in the container, including key and
# certificate. Safe to call more than once.
cleanup_container() {
	if [ -n "$CT_TMP" ]; then
		if docker exec "$CONTAINER" rm -rf "$CT_TMP" >/dev/null 2>&1; then
			CT_TMP=""
		else
			echo "Error: could not remove $CONTAINER:$CT_TMP — it holds the private key, delete it by hand" >&2
		fi
	fi
}

# Removes the disposable signing container — and the key inside it — with its
# anonymous volume (the image declares one). Safe to call more than once.
cleanup_signer() {
	if [ -n "$SIGN_CT" ]; then
		if docker rm -f -v "$SIGN_CT" >/dev/null 2>&1; then
			SIGN_CT=""
		else
			echo "Error: could not remove the signing container $SIGN_CT — it holds the private key, run: docker rm -f -v $SIGN_CT" >&2
		fi
	fi
}

cleanup() {
	cleanup_signer
	cleanup_container
	if [ -n "$TMP" ]; then
		rm -rf "$TMP"
	fi
}
trap cleanup EXIT
# On a signal, exit with the usual 128+n status (Linux numbers); exit runs the
# EXIT trap. Every signal that ends the script by default needs its own line:
# dash (/bin/sh on Debian and Ubuntu) skips the EXIT trap for a signal without
# a trap and would leave the key in a container. QUIT is Ctrl-\ in a
# terminal; PIPE is `build/package.sh | head`.
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 131' QUIT
trap 'exit 138' USR1
trap 'exit 140' USR2
trap 'exit 141' PIPE
trap 'exit 142' ALRM
trap 'exit 143' TERM

VERSION="$(sed -n 's/.*<version>\(.*\)<\/version>.*/\1/p' "$ROOT/appinfo/info.xml" | head -n1)"
[ -n "$VERSION" ] || die "cannot read the version from appinfo/info.xml"
ARCHIVE="$ROOT/build/pulse-$VERSION.tar.gz"

SIGN=""
if [ -n "${PULSE_KEY:-}" ] || [ -n "${PULSE_CRT:-}" ]; then
	[ -n "${PULSE_KEY:-}" ] && [ -n "${PULSE_CRT:-}" ] \
		|| die "set both PULSE_KEY and PULSE_CRT to sign, or neither"
	[ -r "$PULSE_KEY" ] || die "private key not readable: $PULSE_KEY"
	[ -r "$PULSE_CRT" ] || die "certificate not readable: $PULSE_CRT"
	SIGN=1
fi

echo "==> Checking the release state of $VERSION"
PKG_VERSION="$(node -p 'require(process.argv[1]).version' "$ROOT/package.json")"
RELEASED=1
if [ "$PKG_VERSION" != "$VERSION" ]; then
	echo "    appinfo/info.xml says $VERSION, package.json says $PKG_VERSION" >&2
	RELEASED=""
fi
# The store takes a pre-release's notes from [Unreleased] only; a
# "## [0.19.0-beta.1]" heading it would even file under 0.19.0 and show with
# the final release's notes.
case "$VERSION" in
	*-*) NOTES="Unreleased" ;;
	*) NOTES="$VERSION" ;;
esac
if ! awk -v h="## [$NOTES]" '
		index($0, h) == 1 && (length($0) == length(h) || substr($0, length(h) + 1, 1) == " ") { found = 1 }
		END { exit !found }' "$ROOT/CHANGELOG.md"; then
	echo "    CHANGELOG.md has no \"## [$NOTES]\" section" >&2
	RELEASED=""
fi
if [ -z "$RELEASED" ]; then
	if [ "${ALLOW_UNRELEASED:-}" = 1 ]; then
		warn "packing an unreleased state (ALLOW_UNRELEASED=1) — do not upload this archive"
	else
		die "$VERSION is not a release state. Give info.xml and package.json the same version and add the CHANGELOG section, or set ALLOW_UNRELEASED=1 for a test archive."
	fi
fi

TMP="$(mktemp -d "${TMPDIR:-/tmp}/pulse-package.XXXXXX")"
STAGE="$TMP/pulse"

if [ -z "${SKIP_BUILD:-}" ]; then
	echo "==> npm run build"
	(cd "$ROOT" && npm run --silent build >/dev/null)
fi
[ -f "$ROOT/js/pulse-main.js" ] || die "js/pulse-main.js is missing — build first"

echo "==> Checking translations"
(cd "$ROOT" && npm run --silent l10n:check)

echo "==> Validating appinfo/info.xml"
if command -v xmllint >/dev/null 2>&1; then
	# Always against today's schema: the store changes it now and then, and an
	# old copy passes what the store rejects. build/info.xsd (not in git) keeps
	# the last good download for offline runs.
	SCHEMA="$TMP/info.xsd"
	if command -v curl >/dev/null 2>&1 \
			&& curl -fsSL --max-time 30 -o "$SCHEMA" "$SCHEMA_URL" && [ -s "$SCHEMA" ]; then
		cp "$SCHEMA" "$ROOT/build/info.xsd" 2>/dev/null || true
	elif [ -s "$ROOT/build/info.xsd" ]; then
		warn "could not download $SCHEMA_URL — using the cached build/info.xsd, which may be outdated"
		SCHEMA="$ROOT/build/info.xsd"
	else
		warn "could not download $SCHEMA_URL and there is no cached build/info.xsd — schema check skipped"
		SCHEMA=""
	fi
	if [ -n "$SCHEMA" ]; then
		xmllint --noout --schema "$SCHEMA" "$ROOT/appinfo/info.xml"
	fi
else
	warn "xmllint not installed — schema check skipped"
fi

echo "==> Staging files"
mkdir "$STAGE"
# Ship only what the app needs at runtime, plus the licence and the
# changelog (the store reads the release notes from CHANGELOG.md).
for item in appinfo css img js l10n lib templates office-addin \
		README.md LICENSE CHANGELOG.md; do
	[ -e "$ROOT/$item" ] || die "missing: $item"
	cp -a "$ROOT/$item" "$STAGE/"
done
rm -f "$STAGE"/js/*.map

# Nothing unexpected in the archive: no sources, no throwaway scripts, no
# key material, no symlinks. Better to abort here than to upload it.
if find "$STAGE" \( -name 'node_modules' -o -name '*.key' -o -name '*.pem' \
		-o -name '*.crt' -o -name '_*.php' -o -name '*.map' -o -type l \) -print | grep -q .; then
	echo "Error: the archive would contain files that must not ship:" >&2
	find "$STAGE" \( -name 'node_modules' -o -name '*.key' -o -name '*.pem' \
		-o -name '*.crt' -o -name '_*.php' -o -name '*.map' -o -type l \) -print >&2
	exit 1
fi

# Normalise modes, whatever the umask or file system of this machine.
find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f \( -perm -100 -o -perm -010 -o -perm -001 \) -exec chmod 755 {} +
find "$STAGE" -type f ! \( -perm -100 -o -perm -010 -o -perm -001 \) -exec chmod 644 {} +

if [ -n "$SIGN" ]; then
	command -v docker >/dev/null 2>&1 || die "docker not found — signing needs a Nextcloud image"
	# occ signs with any certificate; a wrong one only shows up when an
	# instance refuses to install the release. So check it here.
	CRT_PUB="$(openssl x509 -in "$PULSE_CRT" -noout -pubkey)" \
		|| die "cannot read the certificate $PULSE_CRT"
	KEY_PUB="$(openssl pkey -in "$PULSE_KEY" -pubout)" \
		|| die "cannot read the private key $PULSE_KEY"
	[ "$CRT_PUB" = "$KEY_PUB" ] || die "$PULSE_CRT does not belong to $PULSE_KEY"
	openssl x509 -in "$PULSE_CRT" -noout -subject -nameopt RFC2253 \
		| sed -n 's/^subject= *//p' | tr ',' '\n' | grep -qx 'CN=pulse' \
		|| die "the certificate is not issued for CN=pulse"
fi

if [ -n "$SIGN" ] && [ -z "$CONTAINER" ]; then
	if [ -z "${SIGN_IMAGE:-}" ]; then
		SIGN_IMAGE="$(docker inspect --format '{{.Config.Image}}' "${SIGN_FROM:-nextcloud-nextcloud-1}" 2>/dev/null || true)"
		SIGN_IMAGE="${SIGN_IMAGE:-nextcloud:34-apache}"
	fi
	echo "==> Signing (occ integrity:sign-app in a disposable container from $SIGN_IMAGE, no network)"
	# Nothing of the image's entrypoint runs (it would install Nextcloud): only
	# php with occ, as root — the only user that may write the source's config
	# directory, which occ insists on even for this command.
	SIGN_CT="pulse-sign-$$-$(od -An -N4 -tx4 /dev/urandom | tr -d ' ')"
	docker create --name "$SIGN_CT" --network none --entrypoint php "$SIGN_IMAGE" \
		"$OCC" integrity:sign-app \
		--path=/sign/pulse \
		--privateKey=/sign/sign.key \
		--certificate=/sign/sign.crt >/dev/null \
		|| die "cannot create a signing container from $SIGN_IMAGE"
	mkdir "$TMP/sign"
	docker cp "$TMP/sign" "$SIGN_CT:/sign" >/dev/null
	docker cp "$STAGE" "$SIGN_CT:/sign/pulse" >/dev/null
	docker cp -L "$PULSE_KEY" "$SIGN_CT:/sign/sign.key" >/dev/null
	docker cp -L "$PULSE_CRT" "$SIGN_CT:/sign/sign.crt" >/dev/null
	docker start -a "$SIGN_CT" || die "occ integrity:sign-app failed"
	# occ reports some failures (such as an unwritable config directory) and still
	# exits 0 — only the file tells.
	docker cp "$SIGN_CT:/sign/pulse/appinfo/signature.json" "$STAGE/appinfo/signature.json" >/dev/null 2>&1 \
		|| die "occ did not write appinfo/signature.json"
	cleanup_signer
	[ -z "$SIGN_CT" ] || die "the signing container could not be removed"
	[ -s "$STAGE/appinfo/signature.json" ] || die "occ did not write appinfo/signature.json"
	chmod 644 "$STAGE/appinfo/signature.json"
elif [ -n "$SIGN" ]; then
	echo "==> Signing (occ integrity:sign-app in the running container $CONTAINER — CONTAINER is set)"
	CT_TMP="$(docker exec "$CONTAINER" mktemp -d /tmp/pulse-sign.XXXXXX)" \
		|| die "cannot create a temporary directory in container $CONTAINER"
	docker cp "$STAGE" "$CONTAINER:$CT_TMP/pulse"
	docker exec "$CONTAINER" chown -R www-data:www-data "$CT_TMP"
	# Key and certificate go in through stdin, so they are created with mode 600
	# inside the private directory and never exist with looser permissions.
	docker exec -i -u www-data "$CONTAINER" sh -c 'umask 077 && cat > "$1"' sh "$CT_TMP/sign.key" < "$PULSE_KEY"
	docker exec -i -u www-data "$CONTAINER" sh -c 'umask 077 && cat > "$1"' sh "$CT_TMP/sign.crt" < "$PULSE_CRT"
	docker exec -u www-data "$CONTAINER" php "$OCC" integrity:sign-app \
		--path="$CT_TMP/pulse" \
		--privateKey="$CT_TMP/sign.key" \
		--certificate="$CT_TMP/sign.crt"
	docker cp "$CONTAINER:$CT_TMP/pulse/appinfo/signature.json" "$STAGE/appinfo/signature.json"
	cleanup_container
	[ -z "$CT_TMP" ] || die "the signing directory could not be removed from the container"
	[ -s "$STAGE/appinfo/signature.json" ] || die "occ did not write appinfo/signature.json"
	chmod 644 "$STAGE/appinfo/signature.json"
else
	echo "==> Not signing (PULSE_KEY/PULSE_CRT not set)"
	echo "    The App Store only accepts signed releases — see docs/APPSTORE.md"
fi

echo "==> Packing the archive"
MTIME="$(cd "$ROOT" && git log -1 --format=%cI 2>/dev/null || true)"
rm -f "$ARCHIVE"
tar -czf "$ARCHIVE" -C "$TMP" \
	--owner=0 --group=0 --numeric-owner --sort=name \
	${MTIME:+--mtime="$MTIME"} \
	pulse

echo
echo "Archive: $ARCHIVE"
echo "Size:    $(ls -lh "$ARCHIVE" | awk '{print $5}')"
echo "SHA256:  $(sha256sum "$ARCHIVE" | cut -d' ' -f1)"
if [ -n "$SIGN" ]; then
	echo "Signature for the store API (base64, SHA-512 over the archive):"
	openssl dgst -sha512 -sign "$PULSE_KEY" "$ARCHIVE" | openssl base64 -A
	echo
fi
