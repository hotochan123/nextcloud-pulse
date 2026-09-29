#!/bin/sh
# SPDX-FileCopyrightText: 2026 hotochan123
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Creates the key and the certificate signing request (CSR) for signing the app.
#
#   build/certificate.sh              # -> ~/.nextcloud/certificates/pulse.{key,csr}
#   OUT=/path build/certificate.sh
#
# The Common Name MUST be the app ID, otherwise Nextcloud rejects the certificate.
# The private key does NOT belong in the repository and is never shipped.
#
# Mind where you store it: if the home directory lives on a volatile
# file system (Unraid and other appliances keep / in RAM), the key is
# gone after the next reboot — and with it every signed release. In that case
# point OUT at persistent storage: OUT=/path/on/disk build/certificate.sh
set -eu
umask 077

APP_ID=pulse
OUT="${OUT:-$HOME/.nextcloud/certificates}"

mkdir -p "$OUT"
chmod 700 "$OUT"

if [ -f "$OUT/$APP_ID.key" ]; then
	echo "A key already exists: $OUT/$APP_ID.key"
	echo "Not overwriting it — a new key invalidates every release signed so far."
	echo "To start over, move the file away deliberately."
	exit 1
fi

openssl req -nodes -newkey rsa:4096 -keyout "$OUT/$APP_ID.key" \
	-out "$OUT/$APP_ID.csr" -subj "/CN=$APP_ID"
chmod 600 "$OUT/$APP_ID.key"

cat <<EOF

Key:      $OUT/$APP_ID.key   (secret, local only, never backed up into a repository)
Request:  $OUT/$APP_ID.csr   (this goes into the pull request)

Next:
  1. Make the source repository public — the certificate request links to it.
  2. Fork https://github.com/nextcloud/app-certificate-requests, add the
     request as $APP_ID/$APP_ID.csr and open a pull request.
  3. Once it is merged, the repository has $APP_ID/$APP_ID.crt — download it
     to $OUT/$APP_ID.crt.
  4. Register the app ID: create an account on https://apps.nextcloud.com and
     register the app with the certificate and a signature over the app ID:
       printf '%s' $APP_ID | openssl dgst -sha512 -sign $OUT/$APP_ID.key | openssl base64
     The registration reserves the ID; the store accepts no release before it.
  5. Sign and pack:
     PULSE_KEY=$OUT/$APP_ID.key PULSE_CRT=$OUT/$APP_ID.crt build/package.sh

Check that the request carries the app ID:
  openssl req -in $OUT/$APP_ID.csr -noout -subject
EOF
