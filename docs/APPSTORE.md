# Publishing Pulse in the Nextcloud App Store

What the store requires, what the repository already has in place for it, and
what has to be done by hand. Keep to the order — without a certificate the
store accepts nothing.

The public repository is `hotochan123/hotochan123-nextcloud-pulse`; the complete
development history is in the private archive `hotochan123/pulse`.

## Status

| Requirement | Status |
|---|---|
| `appinfo/info.xml` valid against the store schema | done (`xmllint`, also in CI) |
| Licence in the repository + SPDX header in every source file | done (`LICENSE`, AGPL-3.0-or-later) |
| `CHANGELOG.md` (the store's release notes) | done |
| Release archive without sources/throwaway files | done (`build/package.sh`) |
| CI: bundle, tests, translations, schema | done (`.github/workflows/ci.yml`) |
| App ID `pulse` available | yes (no entry in the store as of 26 Jul 2026) |
| Signing certificate from Nextcloud | key generated, `.csr` in the fork (14 Aug 2026) — **pull request not opened yet** (as of 29 Sep 2026) |
| Screenshots for the store page | done — six images in `screenshots/`, linked in `info.xml` (**they only show once they are on `main` of the public repository and it is publicly visible**) |
| Tested on the advertised server versions | done — `info.xml` now advertises only 34, and only 34 has been tested |

## Once

### 1. Account and app ID

Create an account on <https://apps.nextcloud.com>. The app ID (`pulse`) is bound
to that account with the first upload and is reserved from then on.

### 2. Request the certificate

```sh
build/certificate.sh          # creates ~/.nextcloud/certificates/pulse.{key,csr}
```

**Already done** (14 Aug 2026, RSA 4096, `CN=pulse`). The key is **not** in the
default location under `~/.nextcloud/` but on persistent storage, because on
this host `/root` lives in RAM (see below). The exact path is deliberately not
in the repository; below it is called `$PULSE_SECRETS`:

```sh
PULSE_SECRETS=/path/to/secrets-folder/pulse     # set once per session
ls "$PULSE_SECRETS"                             # pulse.key (600), pulse.csr
```

**Careful:** `build/certificate.sh` without `OUT=` creates a **second** key in
the default location instead of finding the existing one — it only checks the
folder it uses itself. It overwrites nothing, but you would then sign with the
wrong key. Always pass `OUT="$PULSE_SECRETS"`.

The common name of the request **must** be the app ID — the script sets
`/CN=pulse`. Then fork <https://github.com/nextcloud/app-certificate-requests>,
add `pulse.csr` as `pulse/pulse.csr` and open a pull request. This works
entirely through the GitHub web interface, but **the fork has to be created by
hand first** — the automatic fork on "Create new file" does not kick in for
this repository ("you need to fork it and propose your changes from there").
After the merge, `pulse/pulse.crt` is there; put that file next to the key.

Prepared on 14 Aug 2026 in the fork `hotochan123/app-certificate-requests`,
branch `pulse-cert` (only `pulse/pulse.csr`). **The pull request itself was
never opened** — checked through the GitHub API on 29 Sep 2026: no PR from this
branch, none from this account. To catch up, use
<https://github.com/nextcloud/app-certificate-requests/compare/master...hotochan123:app-certificate-requests:pulse-cert>
("Create pull request"); requests there are currently merged within one to four
days. Afterwards, check that the PR really exists. Cross-checked: the uploaded
`.csr` is byte for byte the local one, `CN=pulse`, and its public key belongs to
`pulse.key` (SHA-256 of the public key is the same on both sides).

The private key stays outside the repository (`.gitignore` additionally blocks
`*.key`/`*.csr`/`*.crt`). If it is lost, every release signed so far becomes
invalid and a new certificate is needed.

Check where it is stored: on appliances such as Unraid, `/root` lives in RAM
and is empty after a reboot. The key then belongs on persistent storage
(`OUT=/path/on/disk build/certificate.sh`), directory `700`, file `600`.

### 3. Screenshots

Six images are in `screenshots/` and are listed in `info.xml` after
`<repository>`:

```xml
<screenshot>https://raw.githubusercontent.com/hotochan123/hotochan123-nextcloud-pulse/main/screenshots/01-projector-live.png</screenshot>
```

**The store fetches these URLs itself.** As long as the files are not on `main`
of the public repository, or the repository is not publicly visible, the URLs
lead nowhere and the store page stays without images — so push the images
before registering a release. They do not belong in the release archive;
`build/package.sh` only collects the runtime files.

They are taken by the screenshot harness:

```sh
dev/design-shots/run.sh store        # -> dev/design-shots/out-store/
```

This run differs from the rest of the harness in four ways, and each of them
used to be a trap:

- **Rooms of its own** (`probe.php store`). The demo route hands out a new token
  for every vote, so it registers new players for every question: the podium
  showed the same name twice with "shared" next to it, and once there were more
  votes than names a "2" was appended. In the store fixture, twelve people with
  fixed tokens answer every question in a fixed pattern — every question has a
  distribution, and the leaderboard has distinct places.
- **Public host name** (`PULSE_HOST` or one line in
  `dev/design-shots/.store-host`, not in the repository; the store images show
  `https://nextcloud.mitung.de`). The join link comes from
  `window.location.host` and appears on the image both as text and in the QR
  code. An internal name does not belong on a public store page.
- **Double pixel density** (`layout.css.devPixelsPerPx`), so 1200 × 675 CSS
  pixels give a 2400 × 1350 image. The browser chrome is added to the window
  height, otherwise the image would be 2:1 instead of 16:9.
- **More people present than answers** (24 of 30). Otherwise every image says
  "complete", and a running question looks like a finished one.

The English interface comes from the language setting of the throwaway user
(`occ user:setting pulse-shots core lang en`) and from
`intl.accept_languages` for the public pages.

The store takes up to ten images. The order in `info.xml` is the order on the
page; the first one is the preview image.

## For every release

1. **Bump the version** in `appinfo/info.xml` **and** `package.json`
   (same number). Note: on the local instance the version bump requires an
   `occ upgrade`; until then the app routes answer with 503.
2. **`CHANGELOG.md`** — rename the `[Unreleased]` section to the new version
   and set the date. The store shows this text as the release notes.
3. Check **`max-version`** in `info.xml`: if the app advertises a server version
   it never ran on, the bug reports will come from there.
4. **Build, check, sign, pack:**

   ```sh
   PULSE_KEY="$PULSE_SECRETS/pulse.key" \
   PULSE_CRT="$PULSE_SECRETS/pulse.crt" \
   build/package.sh
   ```

   The script builds the bundle, checks translations and `info.xml`, collects
   only the files that ship, runs `occ integrity:sign-app` in the Nextcloud
   container (which writes `appinfo/signature.json`) and packs
   `build/pulse-<version>.tar.gz`. At the end it prints the Base64 signature of
   the archive — the store needs it in a moment.

5. **Cross-check:** `tar -tzf build/pulse-<version>.tar.gz` — the top folder is
   called `pulse/`, `appinfo/signature.json` is included, `src/`,
   `node_modules/`, `docs/` and `tests/` are not.

6. **Tag and GitHub release:**

   ```sh
   git tag -a v<version> -m "Pulse <version>" && git push origin v<version>
   ```

   Create a release on GitHub and attach the archive as an asset. The store
   downloads the file itself, so the URL has to be public and HTTPS.

7. **Register with the store:**

   ```sh
   curl -X POST https://apps.nextcloud.com/api/v1/apps/releases \
     -u "STORE-ACCOUNT:PASSWORD" \
     -H "Content-Type: application/json" \
     -d '{
           "download": "https://github.com/hotochan123/hotochan123-nextcloud-pulse/releases/download/v<version>/pulse-<version>.tar.gz",
           "signature": "<Base64 from step 4>",
           "nightly": false
         }'
   ```

   Instead of `-u`, an API token of the account works too:
   `-H "Authorization: Token <token>"`. The store downloads the archive, checks
   the signature against the certificate, reads `info.xml` and `CHANGELOG.md`
   and publishes. If it answers with 4xx, the reason is in the body — usually
   the signature, the schema, or an app ID that does not match the certificate.

   Pre-releases: a version with a suffix under semver rules (`0.17.0-beta.1`)
   ends up in the pre-release channel, `"nightly": true` in the nightly channel.

8. **Check:** `https://apps.nextcloud.com/apps/pulse`, and install it once
   through the web interface on a fresh instance. The server's integrity check
   compares `appinfo/signature.json` with the certificate — if it does not
   match, the instance reports "Some files have not passed the integrity
   check".

## What is still missing, to stay honest

- **Server versions:** `info.xml` advertises **only 34** (`min-version="34"
  max-version="34"`), and that is exactly what the app runs on. Chosen on
  purpose: a wider range would be a claim about servers nobody has ever
  started, and the bug reports would come from there. The price: older
  instances are not offered the app, and with NC 35 it disappears from the
  store until `max-version` goes up.

  To open the range honestly later: `nextcloud/ocp` in the respective version
  as a dev dependency plus Psalm covers the PHP side statically (the stubs
  carry exactly the OCP symbols of that server version), plus a real run on the
  lowest advertised version — that covers the PHP minimum, migrations and theme
  variables, which a pure symbol check does not see. `occ app:check-code` no
  longer helps; the command no longer exists in 34.
- **Translations:** English and German come from the repository. Further
  languages would go through Transifex; for that the app has to be added to
  Nextcloud's Transifex project (a request to Nextcloud), going it alone is not
  worth it.
- **Signing in CI:** deliberately not. `occ integrity:sign-app` needs a running
  Nextcloud instance, and the private key has no business in a CI secret as
  long as signing locally is enough.
