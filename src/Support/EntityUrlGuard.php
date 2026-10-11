<?php

namespace Zielu92\FilamentImageLabeler\Support;

/**
 * Trust boundary for an entity link action url: the developer's url() (or a
 * url derived from a linked record) becomes `window.location.href` in the
 * editor, so only same-origin relative paths and plain http(s) links are let
 * through - `javascript:`, `data:`, `vbscript:`, `file:` and protocol-relative
 * hosts are rejected before they ever reach the client.
 */
final class EntityUrlGuard
{
    private function __construct() {}

    public static function isSafe(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        // Control characters and newlines smuggle through into navigation.
        if (preg_match('#[\x00-\x1F\x7F]#', $url)) {
            return false;
        }

        // Same-origin relative path - but reject the protocol-relative forms
        // //host and /\host, which browsers resolve against an attacker host.
        if ($url[0] === '/') {
            return ($url[1] ?? '') !== '/' && ($url[1] ?? '') !== '\\';
        }

        if ($url[0] === '?' || $url[0] === '#') {
            return true;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
