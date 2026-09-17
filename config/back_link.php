<?php
/**
 * config/back_link.php
 *
 * One helper for every "Back" button that should return the user to the page
 * they actually came from, rather than to a hardcoded destination.
 *
 * The problem it solves: a list page holds its filters and page number in the
 * URL (?search=…&status=…&page=3). Opening a row and pressing a Back button
 * wired to a bare "purchase_orders.php" throws all of that away and drops the
 * user at an unfiltered page 1 -- which, on a ten-page list, means finding
 * their place again after every single row they look at.
 *
 * How it decides, in order:
 *   1. An explicit ?return=… on the current URL. Pages that already thread a
 *      return target through their own links keep working, and it survives a
 *      POST-redirect where the referer is lost.
 *   2. The HTTP referer, if it is same-origin and isn't the current page.
 *   3. The caller's fallback -- the old hardcoded destination.
 *
 * SECURITY -- why this can't just echo the referer back:
 * an unvalidated referer is an open redirect. A link from an attacker's site
 * to your purchase order page would render a "Back" button pointing at their
 * domain, on your styling, to a logged-in user. Everything here is reduced to
 * a path + query on THIS host; scheme, host, port and userinfo are discarded,
 * and anything that doesn't parse to a same-host absolute path falls back.
 */

/**
 * Resolve where a Back button should point.
 *
 * @param string $fallback Relative URL to use when there is nothing better --
 *                         the destination the button used to hardcode.
 */
function back_link(string $fallback): string
{
    foreach ([$_GET['return'] ?? null, $_SERVER['HTTP_REFERER'] ?? null] as $candidate) {
        $safe = back_link_sanitize(is_string($candidate) ? $candidate : '');
        if ($safe !== '' && !back_link_is_current($safe)) {
            return $safe;
        }
    }

    return $fallback;
}

/**
 * Reduce a candidate to a same-host path+query, or '' if it can't be trusted.
 */
function back_link_sanitize(string $candidate): string
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return '';
    }

    // "//evil.test/x" is protocol-relative: it looks like a path but browsers
    // treat it as another host. Rejected before parse_url can be generous.
    if (str_starts_with($candidate, '//') || str_contains($candidate, "\n") || str_contains($candidate, "\r")) {
        return '';
    }

    $parts = parse_url($candidate);
    if ($parts === false) {
        return '';
    }

    // Absolute URL: only keep it if the host is this one.
    if (isset($parts['host'])) {
        $host = strtolower($parts['host']);
        $self = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        // HTTP_HOST carries the port; compare hostnames without it.
        $selfHost = strtolower((string)parse_url('http://' . $self, PHP_URL_HOST));
        if ($host !== $selfHost || $selfHost === '') {
            return '';
        }
    }

    $path = (string)($parts['path'] ?? '');
    if ($path === '' || $path[0] !== '/') {
        // Relative referers are unusual and ambiguous to resolve; the fallback
        // is a better answer than a guess.
        return '';
    }

    $out = $path;
    if (isset($parts['query']) && $parts['query'] !== '') {
        $out .= '?' . $parts['query'];
    }

    return $out;
}

/** True when the candidate is the page we're already on -- Back must move. */
function back_link_is_current(string $candidate): bool
{
    $current = (string)($_SERVER['REQUEST_URI'] ?? '');

    return $candidate === $current;
}
