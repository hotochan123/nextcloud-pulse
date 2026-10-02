<!--
SPDX-FileCopyrightText: 2026 hotochan123
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Security policy

## Status of the code

Pulse was written by AI coding agents (Anthropic's Claude Code), not by hand.
It has had no independent human code review and no security audit. In
September 2026 AI agents reviewed the code for security problems; the fixes are
listed under "Security" in [`CHANGELOG.md`](CHANGELOG.md), and what remains is
described under "Security notes" and "Known limits" in the
[README](README.md#security-notes). Please evaluate Pulse yourself before you
rely on it. Details: [How this app was built](README.md#how-this-app-was-built-ai-disclosure).

## Reporting a vulnerability

Please do not describe a security problem in a public issue.

- **Preferred:** GitHub's private vulnerability reporting — "Report a
  vulnerability" on the repository's
  [Security tab](https://github.com/hotochan123/nextcloud-pulse/security).
- **If that button is not there** (for example because private reporting has
  been switched off):
  open an [issue](https://github.com/hotochan123/nextcloud-pulse/issues)
  that only says you have found a security problem and asks for a private way
  to send the details — no description, no proof of concept. The maintainer
  will answer with a contact.

Helpful in a report: the Pulse version or commit, the Nextcloud version and
database, what an attacker needs (a room code, a Nextcloud account, a site on
the same domain, …), the steps to reproduce and the effect.

Pulse is maintained by one person. There is no bug bounty
and no guaranteed response time; fixes go into the next release, and only the
newest release is supported.

## Scope

In scope:

- the app in this repository: the PHP code, the templates, the JavaScript
  bundles and their sources, the PowerPoint add-in shell and the manifest the
  app generates;
- the participant pages and API (`/apps/pulse/s/…`, `/screen/…`, `/embed`),
  the moderator API and the background job;
- the release tooling (`build/package.sh`) and the CI configuration, as far as
  they affect what gets shipped;
- a way around a protection or limit that the README describes, for example
  the throttling of wrong room codes, the voter cookie, the caps per account
  or the `.htaccess` that hides the development tree.

Out of scope:

- Nextcloud server itself and other apps — please report those to Nextcloud
  (<https://nextcloud.com/security/>);
- Microsoft's `office.js` and PowerPoint;
- the configuration of your web server or proxy;
- the limits the README documents as known, as long as they stay within the
  stated bounds: voting again under new identities, scouting a self-paced quiz
  with a second identity, anyone with a room code seeing its projector view,
  and the tools under `dev/` and `tests/`, which are not part of a release.
