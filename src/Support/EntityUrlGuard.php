<?php

namespace Zielu92\FilamentImageLabeler\Support;

final class EntityUrlGuard
{
    private function __construct() {}

    public static function isSafe(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        if (preg_match('#[\x00-\x1F\x7F]#', $url)) {
            return false;
        }

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
