# Publishing Pulse in the Nextcloud App Store

What the store requires, what the repository already has in place for it, and
what has to be done by hand. Keep to the order — every step needs the one
before it, and the store accepts no release for an app ID that has not been
registered with a certificate.

The public repository is `hotochan123/nextcloud-pulse`; the complete
development history is in the private archive `hotochan123/pulse`.

## Status

As of 2 October 2026:

| Requirement | Status |
|---|---|
| Public repository | done — `hotochan123/nextcloud-pulse` is public since 1 Oct 2026 (renamed from `hotochan123-nextcloud-pulse`, GitHub redirects the old name), Issues and private vulnerability reporting are on, description and topics set. `info.xml` points there for the website, the issue tracker and the screenshots |
| App ID `pulse` | taken for this app — `pulse/` in the certificate repository, registered in the store |
| Signing certificate from Nextcloud | done — issued on 2 Oct 2026 through pull request [#1292](https://github.com/nextcloud/app-certificate-requests/pull/1292), valid until 7 Jan 2037; saved next to the key and checked: `CN=pulse`, matches the key, chains to Nextcloud's code-signing root, not revoked |
| Store account, app ID registered | done — registered by the owner on 2 Oct 2026 through the web form; the store page <https://apps.nextcloud.com/apps/pulse> exists and has no release yet. The account's API token ([step 4](#4-store-account)) is not saved yet; the release can also be uploaded through the web form without it |
| `appinfo/info.xml` valid against the store schema | done — validates against the store's current schema (`xmllint`, also in CI and in `build/package.sh`) |
| Licence | done — `LICENSE` (AGPL-3.0-or-later), SPDX header in every source file, `<licence>AGPL-3.0-or-later</licence>` in `info.xml`; four icons in `PulseIcon.vue` come from Feather Icons (MIT), credited with the licence text under "Licence" in the README |
| `CHANGELOG.md` (the store's release notes) | format done; `[Unreleased]` still has to become the first release's section |
| Release archive without sources/throwaway files | done (`build/package.sh`); signing tested with a throwaway certificate |
| First release | not yet — no tag, no GitHub release, no signed archive; version 0.19.0 set on 2 Oct 2026 (`info.xml`, `package.json`), its CHANGELOG section follows with the release ([step 6](#6-first-release)) |
| CI: bundle, tests, translations, schema | workflow in `.github/workflows/ci.yml`, green on the public repository — check it again before tagging |
| Screenshots for the store page | done — six images in `screenshots/`, linked in `info.xml`; all six URLs answer 200 `image/png` (1 Oct 2026), and the join code on them no longer opens a room |
| Databases | fresh installations of Nextcloud 34.0.1 and 35.0.1 tested with SQLite, MariaDB 11.8, PostgreSQL 17 and MySQL 8.4 (MySQL after the fix for new questions, see `CHANGELOG.md`) |
| Server versions | done — 34 and 35 (`max-version="35"` since 2 Oct 2026), tested on 34.0.1, 34.0.4 and 35.0.1 including the server update from 34.0.4 to 35.0.1, see [the test](#nextcloud-35-test-2-oct-2026) |
| Public e-mail address | decided: **none**. Commits carry the GitHub no-reply address, the SPDX headers name only `hotochan123`, and `info.xml` gives the GitHub profile as `<author homepage>`; contact goes through the issue tracker. The store's developer guide asks for an address on the GitHub profile with the certificate request, see [step 2](#2-request-the-certificate); the request for `pulse` was merged without one and without a question about it (2 Oct 2026) |

## Once

In this order: make the repository public → request the certificate and save
it → store account → register the app ID → first release. The screenshots
(step 3) only need a check once the repository is public.

### 1. Make the repository public

**No personal e-mail address in the repository.** Commits are made with the
GitHub no-reply address (`git config user.email` in this clone), the
`SPDX-FileCopyrightText` headers name only `hotochan123`, and `info.xml` has
`<author homepage="https://github.com/hotochan123">` instead of `mail`. New
files copy their header from a neighbour, so they stay that way. Check before
switching to public, and before every push, that `git grep -n "@"` over the
source files and `git log --format="%ae %ce"` show no personal address.

Switch `hotochan123/nextcloud-pulse` to public — only this one; the
archive `hotochan123/pulse` stays private (early screenshots in its history
show an internal host name). Everything else depends on this step: the
certificate request has to link a public repository, the store fetches the
screenshots from it, and every instance downloads the release archive from it.
Keep it public for as long as any release is in the store.

Then:

- enable Issues — the store requires a way to contact the author, and `<bugs>`
  in `info.xml` points at the issue tracker;
- check that the six screenshot URLs in `info.xml` answer with 200 and
  `image/png`;
- check that CI is green for the current `main` (Actions tab).

### 2. Request the certificate

#### Key and request (done)

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
`/CN=pulse`.

#### Open the pull request — personally

The request goes into <https://github.com/nextcloud/app-certificate-requests>
as `pulse/pulse.csr`. It is prepared in the fork
`hotochan123/app-certificate-requests`, branch `pulse-cert` (only
`pulse/pulse.csr`, committed through the GitHub web interface on 14 Aug 2026).
Cross-checked: the uploaded `.csr` is byte for byte the local one, `CN=pulse`,
and its public key belongs to `pulse.key` (SHA-256 of the public key is the same
on both sides). The owner opened the pull request on 1 Oct 2026:
<https://github.com/nextcloud/app-certificate-requests/pull/1292> (one file);
a maintainer merged it the next day, 2 Oct 2026, and added the certificate as
`pulse/pulse.crt`. It was opened from
<https://github.com/nextcloud/app-certificate-requests/compare/master...hotochan123:app-certificate-requests:pulse-cert>
("Create pull request"). A later request, for example after losing the key,
needs a new branch in the fork that holds only the new `pulse/pulse.csr`, and
is opened the same way; check afterwards that it really exists. Should the
fork ever have to be made again: create it by hand first — the automatic fork
on "Create new file" does not kick in for this repository.

**Open it yourself, and write it yourself.** Nextcloud's
[AI contribution policy](https://github.com/nextcloud/.github/blob/master/AI_POLICY.md)
applies to every repository of the Nextcloud organisation, this one included:
AI agents must not open pull requests on their own, descriptions have to be in
the contributor's own words, and a pull request with AI-assisted work has to
say so. This one only adds the request file, but it links an app written by AI
agents. So no agent opens this PR, and its description is not pasted from one.
It should say, in your own words:

- what the certificate is for — the app `pulse`, live polls and quizzes — and
  the link to the public repository (Nextcloud's code-signing guide asks for
  it);
- that Pulse was written by AI coding agents and has had no independent code
  review or security audit, with a link to the README's AI disclosure section.

Reviewer questions are answered personally as well; one to expect is why
`/screen` and `/embed` may be framed by any origin (the PowerPoint add-in).

Before opening it: the store's developer guide asks to show an e-mail address
on the GitHub profile along with the request, and Nextcloud may ask for more to
confirm who owns the app. The profile shows none, and the personal address is
not meant to be public (step 1). Either show a dedicated address there, or open
the request without one and expect a question about ownership in the PR. In
practice the address is not enforced: at least six requests merged in late
September 2026 came from profiles without one. The ownership evidence is the
same account owning the repository, being `<author>` in `info.xml` and having
written every commit.

What the certificate repository checks (as of 1 Oct 2026):

- **DCO** (GitHub app, runs at once): every commit signed off with the
  account's address — the no-reply address counts. The commit on `pulse-cert`
  carries `Signed-off-by` with the no-reply address of `hotochan123`. The AI
  policy allows only humans to add that line, so the person who signs off must
  be the one who created the commit.
- **Validate CSR Common Name** and **Validate Folder Names** (Actions): path
  `<id>/<id>.csr`, ID matching `^[a-z]+[a-z0-9_]*[a-z0-9]$` and at most 32
  characters, CN equal to the folder name. `pulse/pulse.csr` with `CN=pulse`
  passes the same shell logic run locally. For first-time contributors both
  show "Action required" until a maintainer approves the run — not a failure.
- The reviewer opens the linked repository and compares `<id>` in `info.xml`
  and the commit authors with the request.

While a request is open, do **not** press "Update branch" and do not rebase:
the branch may be far behind `master`, but the checks run on the merge ref. An
update adds a commit without a sign-off, which fails DCO, and the reviewer
asks for a branch with only the request commit. No title format is
prescribed, and the README says not to mention anyone. A short, generic ID has
been questioned before (one request was asked for a "less generic" ID).

Recent requests were merged after 18 hours to five days (median about two
days), in batches. The many old open requests are stalled ones waiting for
their authors, not a queue.

How it went for `pulse`: merged on 2 Oct 2026, about 27 hours after it was
opened, without a question about the e-mail address, the ownership, the ID or
the framing; the maintainer only linked the commit with the certificate. The
notes above stay for a later request, for example after losing the key.

#### After the merge: save and check the certificate

The merge adds `pulse/pulse.crt` to the certificate repository. Save it as
`$PULSE_SECRETS/pulse.crt`, next to the key, without blank lines before or
after the certificate, and check that it is issued for the app ID and belongs
to the key:

```sh
openssl x509 -in "$PULSE_SECRETS/pulse.crt" -noout -subject     # subject=CN=pulse
[ "$(openssl x509 -in "$PULSE_SECRETS/pulse.crt" -noout -pubkey)" = \
  "$(openssl pkey -in "$PULSE_SECRETS/pulse.key" -pubout)" ] && echo "certificate matches the key"
```

To also see that Nextcloud issued it and has not revoked it, check it against
the root certificate and revocation list every Nextcloud server checks app
signatures with (`resources/codesigning/` in the server's source):

```sh
NC=/path/to/nextcloud    # the server's web root
openssl verify -crl_check -CAfile "$NC/resources/codesigning/root.crt" \
  -CRLfile "$NC/resources/codesigning/root.crl" "$PULSE_SECRETS/pulse.crt"   # …: OK
```

Done on 2 Oct 2026: the saved file is byte for byte the one in the
certificate repository, and all three checks passed.

`build/package.sh` repeats the first two checks (`CN=pulse`, matches the key)
before every signing, but not the check against Nextcloud's root: `occ` signs
with any certificate, and a wrong one would only show up when an instance
refuses the release.

The private key stays outside the repository (`.gitignore` additionally blocks
`*.key`/`*.csr`/`*.crt`). If it is lost, every release signed so far becomes
invalid: a new request goes into the same repository (overwriting
`pulse/pulse.csr`, mentioning the lost key), and registering the new
certificate with the store deletes every release there.

Check where it is stored: on appliances such as Unraid, `/root` lives in RAM
and is empty after a reboot. The key then belongs on persistent storage
(`OUT=/path/on/disk build/certificate.sh`), directory `700`, file `600`.

### 3. Screenshots

Six images are in `screenshots/` and are listed in `info.xml` after
`<repository>`:

```xml
<screenshot>https://raw.githubusercontent.com/hotochan123/nextcloud-pulse/main/screenshots/01-projector-live.png</screenshot>
```

**The store fetches these URLs itself.** As long as the files are not on `main`
of the public repository, or the repository is not publicly visible, the URLs
lead nowhere and the store page stays without images — so check them (step 1)
before publishing a release. They do not belong in the release archive;
`build/package.sh` only collects the runtime files. The join code on the images
belongs to a store fixture room from 14 Aug 2026; make sure it no longer opens
a room, or take the images again.

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

### 4. Store account

Create an account on <https://apps.nextcloud.com>, with e-mail and password or
through the GitHub login. Then fetch the account's API token from
<https://apps.nextcloud.com/account/token>: an account created through GitHub
has no password the API could check and works with the token only, so the
examples below use the token throughout. Keep it like the key, outside the
repository:

```sh
STORE_TOKEN="$(cat "$PULSE_SECRETS/store.token")"    # file 600, never in the repository
```

The account exists (the registration in step 5 needed it); the token is not
saved yet. Uploading a release through the web form
([For every release](#for-every-release), step 7) works without it.

### 5. Register the app ID

The registration — not the first upload — binds the ID `pulse` to the account
and makes it the app's owner; the store refuses every release before it. It
needs the certificate and a signature over the app ID made with the private
key:

```sh
SIG="$(echo -n "pulse" | openssl dgst -sha512 -sign "$PULSE_SECRETS/pulse.key" | openssl base64 -A)"
```

Either in the web form <https://apps.nextcloud.com/developer/apps/new> (paste
the content of `pulse.crt` and the value of `$SIG`), or through the API:

```sh
node -e 'const fs = require("fs"); process.stdout.write(JSON.stringify({
    certificate: fs.readFileSync(process.argv[1], "utf8").trim(),
    signature: process.argv[2],
    is_enterprise_only: false,
}))' "$PULSE_SECRETS/pulse.crt" "$SIG" \
| curl -X POST https://apps.nextcloud.com/api/v1/apps \
    -H "Authorization: Token $STORE_TOKEN" \
    -H "Content-Type: application/json" \
    --data-binary @-
```

**Leave "enterprise only" off** (`is_enterprise_only: false`) — it cannot be
changed after the registration. 201 means registered; 400 means the signature
does not match the certificate, or the certificate is not signed by Nextcloud
or has been revoked.

Done on 2 Oct 2026 through the web form, whose "Mark app as enterprise-only"
box is unticked by default. The public app list (`/api/v1/apps.json`) leaves
out apps without a release and enterprise-only apps, so `pulse` showing up
there after the first release confirms that the box stayed off.

The store page names the owner by the account's first and last name
(`hotochan123` since 2 Oct 2026); an account without a name shows as
"Anonymous". Until the first release the page shows the app ID `pulse` as
name, summary and description: the registration only knows the ID. The first
release replaces them with the texts from `info.xml` (name "Pulse").

### 6. First release

- **Version:** 0.19.0, set on 2 Oct 2026 in `info.xml` and `package.json`;
  the local instance was upgraded with `occ upgrade` right away (step 1 of
  [For every release](#for-every-release)). Not 0.18.0: `[Unreleased]` holds
  everything since 0.18.0 (14 Aug 2026) — the self-paced quiz, a database
  migration and the fixes of September — and a tag `v0.18.0` with different
  code exists in the private archive.
- **Nextcloud 35:** decided — the release declares 34 and 35
  ([test](#nextcloud-35-test-2-oct-2026)).
- **CI** green on the public repository.
- Then follow [For every release](#for-every-release).
- Afterwards switch the README's installation section to the store and bring
  the status table above up to date.

## For every release

1. **Bump the version** in `appinfo/info.xml` **and** `package.json`
   (same number). Note: on a development instance where this working copy is
   itself the installed app (fine for development, not for production — see
   the README's installation section and the shipped `.htaccess`), the version
   bump requires an `occ upgrade`; until then the app routes answer with 503.
2. **`CHANGELOG.md`** — the store shows the section of this version as the
   release notes:
   - **Release:** rename the `[Unreleased]` section to
     `## [<version>] - <YYYY-MM-DD>` (a new, empty `[Unreleased]` can go above
     it). The store takes the section whose heading carries exactly the version
     from `info.xml`; without one the release has no notes.
   - **Pre-release** (a version with a semver pre-release suffix, such as
     `0.19.0-beta.1`, which the store puts into the beta channel) and nightly:
     the store takes the notes from `## [Unreleased]`, so leave that heading as
     it is and add no `## [0.19.0-beta.1]` heading — the store would not read
     it for the beta, but would file it under `0.19.0` and show it again with
     the final release's notes. `build/package.sh` knows this rule: for a
     version with a pre-release suffix it asks for `## [Unreleased]`.
3. Check **`max-version`** in `info.xml`: if the app advertises a server version
   it never ran on, the bug reports will come from there.
4. **Build, check, sign, pack:**

   ```sh
   PULSE_KEY="$PULSE_SECRETS/pulse.key" \
   PULSE_CRT="$PULSE_SECRETS/pulse.crt" \
   build/package.sh
   ```

   What the script does, in this order:

   - **Release state:** it stops unless `info.xml` and `package.json` carry the
     same version and `CHANGELOG.md` has a `## [<version>]` section — for a
     pre-release a `## [Unreleased]` section (step 2).
     `ALLOW_UNRELEASED=1` turns this into a warning for a test archive — CI
     uses it; never upload such an archive.
   - **Checks:** it builds the bundle (`SKIP_BUILD=1` leaves `js/` as it is),
     checks the translations and validates `info.xml` against the schema it
     downloads from the store on every run; if the download fails, it falls
     back to the last good copy in `build/info.xsd` with a warning.
   - **Staging:** in a temporary directory outside the repository, with only
     the runtime files; it aborts on symlinks, keys, certificates, source maps,
     `_*.php` and `node_modules`. Directories and executables get mode 755,
     everything else 644, owner 0:0 — whatever the umask of the machine.
   - **Signing:** on the host it first checks that certificate and key belong
     together and that the certificate is issued for `CN=pulse`. Then it
     creates a disposable container without network from a Nextcloud image
     (`SIGN_IMAGE`; by default the image of the container `SIGN_FROM`,
     default `nextcloud-nextcloud-1`, if there is one — only its image name is
     read —, otherwise `nextcloud:34-apache`), copies the staged app, key and
     certificate into it, runs `occ integrity:sign-app` from the image's
     Nextcloud source once and takes only `appinfo/signature.json` back.
     `occ` does not need an installed instance for this command. The
     container, with the key in it and its anonymous volume, is removed right
     after signing and on every error or interrupt — the script traps HUP,
     INT, QUIT (Ctrl-\), USR1, USR2, PIPE, ALRM and TERM, in bash as in dash.
     If that removal fails, the script says so and names the container. Only
     SIGKILL (`kill -9`), a crash of the shell or a rarely sent signal outside
     that list gets past it silently; then `docker ps -a --filter
     name=pulse-sign-` shows it, and `docker rm -f -v <name>` removes it. So no
     server that serves traffic ever holds the key.
     `CONTAINER=<name>` is the explicit opt-in to sign inside that running
     container instead: a copy of the stage in a private directory under its
     `/tmp`, never the installed app, removed the same way. While `occ` runs
     — a few seconds — the key is readable there for the web server user, so
     never point it at a production server.
   - **Packing:** `build/pulse-<version>.tar.gz`. At the end it prints the
     archive's SHA-256 and the Base64 signature over the archive — the store
     needs it in step 7.

5. **Cross-check:** `tar -tvzf build/pulse-<version>.tar.gz` — the top folder
   is called `pulse/`, `appinfo/signature.json` is included, the entries read
   `-rw-r--r-- 0/0` or `drwxr-xr-x 0/0`, and `src/`, `node_modules/`, `docs/`
   and `tests/` are not there.

6. **Tag and GitHub release:**

   ```sh
   git tag -a v<version> -m "Pulse <version>" && git push origin v<version>
   ```

   Create a release on GitHub and attach the archive as an asset. The store
   downloads the file itself, so the URL has to be public and HTTPS (at most
   20 MB).

   **Keep the asset online for good.** The store deletes its own copy after
   the check and only keeps the link: every instance downloads the archive
   from this URL when it installs or updates Pulse. Deleting the release,
   replacing the asset with another file or making the repository private
   breaks the installation of that version.

7. **Register with the store:**

   ```sh
   curl -X POST https://apps.nextcloud.com/api/v1/apps/releases \
     -H "Authorization: Token $STORE_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
           "download": "https://github.com/hotochan123/nextcloud-pulse/releases/download/v<version>/pulse-<version>.tar.gz",
           "signature": "<Base64 from step 4>",
           "nightly": false
         }'
   ```

   The same works without a token in the web form
   <https://apps.nextcloud.com/developer/apps/releases/new> (download link,
   signature, nightly box).

   An account with a password can use `-u "STORE-ACCOUNT:PASSWORD"` instead of
   the token; one created through GitHub cannot. The store downloads the
   archive, checks the signature against the registered certificate, reads
   `info.xml` and `CHANGELOG.md` and publishes (201; 200 when the version
   already existed). If it answers with 4xx, the reason is in the body —
   usually the signature, the schema, an app ID that is not registered, or a
   download that failed.

   Pre-releases: a version with a pre-release suffix (`0.19.0-beta.1`) ends up
   in the beta channel, `"nightly": true` in the nightly channel (a new nightly
   replaces all earlier ones).

8. **Check:** `https://apps.nextcloud.com/apps/pulse`, and install it once
   through the web interface on a fresh instance. The server's integrity check
   compares `appinfo/signature.json` with the certificate — if it does not
   match, the instance reports "Some files have not passed the integrity
   check".

## Nextcloud 35 test (2 Oct 2026)

Throwaway instances from the official images `nextcloud:35.0.1-apache` and
`nextcloud:34.0.4-apache` (both PHP 8.5.11), with the app from `main` and
`max-version` raised to 35:

- **Installation:** fresh 35.0.1 on SQLite, MariaDB 11.8, PostgreSQL 17 and
  MySQL 8.4. The app installs and all 12 migrations are recorded (a fresh
  installation applies the final schema in one step, so the data steps that
  only run when updating from an older Pulse did not run on 35); the
  PostgreSQL schema of the Pulse tables is identical to the one on 34.0.4, and
  `db:add-missing-*` and the schema check report nothing for them. The
  background job is registered and runs, and so does the setup check. Word
  cloud, number guess and free text questions can be created on every
  database.
- **Unit tests** against the instance's own server code: 1609 tests, OK on
  35.0.1 and on 34.0.4.
- **HTTP simulation** (`dev/sim`): 1504 ok / 0 failed on all four databases
  and on 34.0.4. On 35.0.1 with PostgreSQL, Redis as the distributed cache
  and for locking, and `overwriteprotocol=https`, as in production: 1506 / 0
  and nothing in the Nextcloud log at warning level or above — the two extra
  checks need https.
- **Screenshot harness,** every track except `store`, on 34.0.4 and 35.0.1
  (PostgreSQL): 333 images each, no findings. Apart from run-to-run noise
  (random seeded votes, timings), the differences come from the Nextcloud
  header, which is 44 px high in 35 instead of 50 px. Pulse reads the height
  from `--header-height`, so its area gains 6 px — scroll offsets shrink by
  4–6 px, frames and previews grow slightly — and nothing is cut off.
- **Server update** 34.0.4 → 35.0.1 through the official image, with data
  (48 rooms, about 5000 votes, PostgreSQL): Pulse stays enabled, every Pulse
  table is byte for byte the same afterwards, and the simulation passes
  again. Nextcloud 35's own schema check then lists a missing core table
  `oc_federated_invites` (a repair step of the update drops it) — core, not
  Pulse.
- **App update** 0.18.0 → 0.19.0 on 35.0.1 (version raised in the copy):
  `occ upgrade` updates the app; until then, and for a moment right after,
  its routes answer 503, as on 34.
- **Logs and headers:** no entry from Pulse at warning level or above during
  web requests, no PHP deprecation from Pulse files, no HTTP 5xx in the
  simulation and harness runs; the framing headers of `/s`, `/screen` and
  `/embed` are the same on 34.0.4 and 35.0.1.

Nextcloud 35's updater disables every app that does not ship with the
server and whose `max-version` is below 35. Copies of Pulse from before
2 October 2026 carry `max-version="34"` (0.18.0 declared 34 only, earlier
versions 29 to 34), so the updater turns them off when an instance moves
to 35.

## What is still missing, to stay honest

- **Nextcloud 35, not covered by the test above:** PHP 8.4 entirely and PHP
  8.3 beyond the syntax check and the unit tests (CI runs both on 35 with PHP
  8.3 and 8.5), the data steps of the migrations (see "Installation" above),
  the server update on MariaDB, MySQL and SQLite, the integrity check of a
  signed archive (install the first signed release once on 34 and once on 35
  before uploading it) and the PowerPoint add-in in real Office.
- **Servers older than 34:** to open the range downwards honestly later,
  `nextcloud/ocp` in the
  respective version as a dev dependency plus Psalm covers the PHP side
  statically (the stubs carry exactly the OCP symbols of that server version),
  plus a real run on the lowest advertised version — that covers the PHP
  minimum, migrations and theme variables, which a pure symbol check does not
  see. `occ app:check-code` no longer helps; the command no longer exists in
  34.
- **Security contact:** `SECURITY.md` asks for reports through GitHub's
  private vulnerability reporting, which has been on since 1 Oct 2026; should
  the button ever be missing, `SECURITY.md` asks for an issue without details
  instead. The store expects authors to respond to security concerns in time.
- **Translations:** English and German come from the repository. Further
  languages would go through Transifex; for that the app has to be added to
  Nextcloud's Transifex project (a request to Nextcloud), going it alone is not
  worth it.
- **Signing in CI:** deliberately not. `occ integrity:sign-app` only needs
  Nextcloud's source code (the disposable container above), but the private
  key has no business in a CI secret as long as signing locally is enough.
