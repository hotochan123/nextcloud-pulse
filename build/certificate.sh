#!/bin/sh
# SPDX-FileCopyrightText: 2026 hotochan123
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Erzeugt Schlüssel und Zertifikatsanfrage (CSR) für die App-Signatur.
#
#   build/certificate.sh              # -> ~/.nextcloud/certificates/pulse.{key,csr}
#   OUT=/pfad build/certificate.sh
#
# Der Common Name MUSS die App-ID sein, sonst weist Nextcloud das Zertifikat ab.
# Der private Schlüssel gehört NICHT ins Repo und wird auch nie ausgeliefert.
#
# Achtung beim Ablageort: liegt das Home-Verzeichnis auf einem flüchtigen
# Dateisystem (Unraid und andere Appliances halten / im RAM), ist der Schlüssel
# nach dem nächsten Neustart weg — und damit jedes signierte Release. Dann OUT
# auf dauerhaften Speicher setzen: OUT=/pfad/auf/platte build/certificate.sh
set -eu

APP_ID=pulse
OUT="${OUT:-$HOME/.nextcloud/certificates}"

mkdir -p "$OUT"
chmod 700 "$OUT"

if [ -f "$OUT/$APP_ID.key" ]; then
	echo "Es gibt schon einen Schlüssel: $OUT/$APP_ID.key"
	echo "Nicht überschreiben — mit einem neuen Schlüssel wird jedes bisher"
	echo "signierte Release ungültig. Zum Neuanfang die Datei bewusst wegräumen."
	exit 1
fi

openssl req -nodes -newkey rsa:4096 -keyout "$OUT/$APP_ID.key" \
	-out "$OUT/$APP_ID.csr" -subj "/CN=$APP_ID"
chmod 600 "$OUT/$APP_ID.key"

cat <<EOF

Schlüssel:  $OUT/$APP_ID.key   (geheim, nur lokal, kein Backup in ein Repo)
Anfrage:    $OUT/$APP_ID.csr   (die kommt in den Pull Request)

Weiter:
  1. Konto auf https://apps.nextcloud.com anlegen (die App-ID wird daran gebunden).
  2. https://github.com/nextcloud/app-certificate-requests forken,
     Datei nach $APP_ID/$APP_ID.csr legen, Pull Request aufmachen.
  3. Nach dem Merge liegt dort $APP_ID/$APP_ID.crt — herunterladen nach
     $OUT/$APP_ID.crt.
  4. Signieren und packen:
     PULSE_KEY=$OUT/$APP_ID.key PULSE_CRT=$OUT/$APP_ID.crt build/package.sh

Prüfen, dass CSR und App-ID zusammenpassen:
  openssl req -in $OUT/$APP_ID.csr -noout -subject
EOF
