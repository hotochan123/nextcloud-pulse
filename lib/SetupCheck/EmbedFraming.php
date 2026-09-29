<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\SetupCheck;

use OCA\Pulse\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\CheckServerResponseTrait;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;
use Psr\Log\LoggerInterface;

/**
 * Checks whether the embed shell (/apps/pulse/embed) can be framed in PowerPoint.
 *
 * Nextcloud sends "X-Frame-Options: SAMEORIGIN" — under Apache from the
 * .htaccess with "always set", which PHP cannot override. So the app cannot
 * get rid of the header itself; it has to be dropped at the web server or
 * proxy. Without this check the failure is silent: the projector page keeps
 * loading in the browser, the slide shows a white box and nothing in the log
 * says why.
 *
 * Every address under which the server reaches itself is checked
 * (overwrite.cli.url, base URL, trusted_domains). That is intentional: the rule
 * hangs on the vhost or the proxy's router; it can apply to the internal name
 * and be missing for the public one. That is exactly when the add-in works
 * in-house but not on the road.
 *
 * Two limits that the message has to state: if the server cannot reach an
 * address (split-horizon DNS, no hairpin NAT), it does not show up here at
 * all. And an address that goes past the proxy straight to the web server
 * — typically localhost — always carries the header, even if the rule for
 * the real name is in place. That is why the result names the checked
 * addresses instead of claiming a blanket "works/doesn't work".
 */
class EmbedFraming implements ISetupCheck {
    use CheckServerResponseTrait;

    private const DOC = 'https://github.com/hotochan123/hotochan123-nextcloud-pulse/blob/main/office-addin/README.md';

    public function __construct(
        protected IL10N $l10n,
        protected IConfig $config,
        protected IURLGenerator $urlGenerator,
        protected IClientService $clientService,
        protected LoggerInterface $logger,
    ) {
    }

    public function getCategory(): string {
        return 'network';
    }

    public function getName(): string {
        return $this->l10n->t('Pulse: embedding in PowerPoint');
    }

    public function run(): SetupResult {
        $path = $this->embedPath();

        $blocked = [];
        $open = [];
        foreach ($this->getTestUrls($path) as $url) {
            $header = $this->frameOptionsOf($url);
            if ($header === null) {
                continue;
            }
            // Group by host: getTestUrls tries http and https for every
            // domain, and the answer is almost always the same.
            $host = parse_url($url, PHP_URL_HOST) ?: $url;
            if ($header === '') {
                $open[$host] = true;
            } else {
                $blocked[$host] = true;
            }
        }

        // If a host answers one way one time and the other way another time, the
        // blocked answer counts: a path that does not work stays a path that does not work.
        $blockedHosts = array_keys($blocked);
        $openHosts = array_values(array_diff(array_keys($open), $blockedHosts));

        if (!$blockedHosts && !$openHosts) {
            return SetupResult::warning(
                $this->l10n->t('Could not reach the Pulse embed page, so whether PowerPoint can frame it is unknown. Check by hand: the response to %s must not carry an X-Frame-Options header.', [$path])
                . "\n" . $this->serverConfigHelp(),
                self::DOC,
            );
        }

        if (!$blockedHosts) {
            return SetupResult::success(
                $this->l10n->t('The Pulse embed page can be framed, so the PowerPoint add-in works. Checked: %s.', [implode(', ', $openHosts)]),
                self::DOC,
            );
        }

        // From here on always just a notice, never a warning. The app cannot
        // switch the header off by itself, most instances don't want the add-in
        // at all — and an address that goes past the reverse proxy
        // (localhost) inevitably carries it, even though everything is set up
        // correctly. A warning would be wrong in exactly the setups this check is for.
        if (!$openHosts) {
            return SetupResult::info(
                $this->l10n->t('Nextcloud sends "X-Frame-Options: SAMEORIGIN" for the Pulse embed page, so a PowerPoint slide shows an empty box instead of the poll. Checked: %s. This only affects the PowerPoint add-in — remove that header in the web server or proxy, and only for the paths /apps/pulse/embed and /apps/pulse/screen/. The projector view in a second window works either way.', [implode(', ', $blockedHosts)]),
                self::DOC,
            );
        }

        return SetupResult::info(
            $this->l10n->t('The Pulse embed page can be framed under %1$s, but not under %2$s. Only the address the presenting laptop uses has to work — an address that goes past the reverse proxy, such as localhost, keeps the X-Frame-Options header no matter how the proxy is configured.', [
                implode(', ', $openHosts),
                implode(', ', $blockedHosts),
            ]),
            self::DOC,
        );
    }

    /**
     * Path of the embed shell, including the web root. Deliberately hard-wired
     * instead of going through linkToRoute — two reasons, both measured:
     *
     * 1. Without loaded app routes, linkToRoute returns an **empty string**,
     *    without an exception. getTestUrls builds addresses on `/` from it, and
     *    the check then measures the start page: that one carries SAMEORIGIN quite
     *    rightly, so the check reports "blocked" for every instance, always.
     * 2. If the routes are loaded, the result depends on the context: without
     *    `htaccess.IgnoreFrontController` and without the environment variable
     *    `front_controller_active` (the .htaccess only sets it for web
     *    requests) the result is `/index.php/apps/pulse/embed`. The same
     *    check would have measured different paths in the admin UI and in
     *    `occ setupchecks`.
     *
     * So the check uses the form that is also in the generated Office
     * manifest. The web server rules in office-addin/README.md cover both
     * forms, so that instances without rewriting work too.
     */
    private function embedPath(): string {
        return rtrim($this->urlGenerator->getWebroot(), '/') . '/apps/' . Application::APP_ID . '/embed';
    }

    /**
     * Value of the X-Frame-Options header: empty string = no header (good),
     * null = address not reachable (no statement possible).
     */
    private function frameOptionsOf(string $url): ?string {
        try {
            $response = $this->clientService->newClient()->get($url, [
                'connect_timeout' => 10,
                // Also an OVERALL limit, not just for connecting: the
                // check runs synchronously while someone opens "Administration
                // settings → Overview", and once per address from
                // trusted_domains. Without this line a reachable but
                // hanging address could hold up the page for any length of time (Guzzle
                // has no default here).
                'timeout' => 10,
                'http_errors' => false,
                // Self-signed certificates don't matter here: the check is about a
                // header, not the chain of trust.
                'verify' => false,
                'nextcloud' => ['allow_local_address' => true],
            ]);
        } catch (\Throwable $e) {
            $this->logger->debug('Pulse: embed page not reachable for the framing check', ['exception' => $e, 'url' => $url]);
            return null;
        }
        return $response->getHeader('X-Frame-Options');
    }
}
