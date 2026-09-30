<?php

namespace Zielu92\FilamentImageLabeler\Support;

class FetchesImage
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    /**
     * Best-effort local file for the given image URL: plain paths pass
     * through, remote http(s) downloads to a request-temp file, same-host
     * URLs are never fetched (own-server request) unless they map onto the
     * public /storage dir. Null = no file; geometry works from URLs alone.
     * Remote fetches apply the SSRF guardrails (see isHostAllowed, no redirects).
     */
    public static function localize(string $url): ?string
    {
        if (str_starts_with($url, 'file://')) {
            $url = substr($url, 7);
        }

        // Never let is_file()/stat hit a stream wrapper.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) !== 1) {
            return is_file($url) ? $url : null;
        }

        if (preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        return static::isSameHost($url)
            ? static::fromSameHostUrl($url)
            : static::fetchRemote($url);
    }

    /**
     * Serve-the-same-app URL: only accept plain public files
     * (/storage/... via the artisan storage:link symlink).
     */
    protected static function fromSameHostUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || ! str_starts_with($path, '/storage/')) {
            return null;
        }

        $public = realpath(public_path($path));
        $root = realpath(public_path('storage'));

        return $public !== false && $root !== false && str_starts_with($public, $root) && is_file($public)
            ? $public
            : null;
    }

    protected static function fetchRemote(string $url): ?string
    {
        if (! filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || ! static::isHostAllowed($host)) {
            return null;
        }

        $data = @file_get_contents(
            $url,
            false,
            stream_context_create([
                'http' => ['timeout' => 15, 'follow_location' => 0, 'max_redirects' => 1],
                'https' => ['timeout' => 15, 'follow_location' => 0, 'max_redirects' => 1],
            ]),
            0,
            self::MAX_BYTES + 1,
        );

        if ($data === false || strlen($data) > self::MAX_BYTES) {
            return null;
        }

        $temp = tempnam(sys_get_temp_dir(), 'fil-ill-');

        if ($temp === false || file_put_contents($temp, $data) === false) {
            return null;
        }

        app()->terminating(fn () => @unlink($temp));

        return $temp;
    }

    /**
     * SSRF guard: true when fetching this host is allowed. Private, loopback,
     * link-local (cloud metadata!) and reserved ranges are refused - and hosts
     * that resolve to any such address - unless the package config opts in.
     * Note: the fetch re-resolves DNS, so this is a strong default, not a
     * rebinding-proof pin.
     */
    public static function isHostAllowed(string $host): bool
    {
        if (config('filament-image-labeler.allow_private_image_hosts') === true) {
            return true;
        }

        $publicFlags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, $publicFlags) !== false;
        }

        $ips = [];

        foreach (gethostbynamel($host) ?: [] as $ip) {
            $ips[] = $ip;
        }

        foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var((string) $ip, FILTER_VALIDATE_IP, $publicFlags) === false) {
                return false;
            }
        }

        return true;
    }

    protected static function isSameHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host)) {
            return false;
        }

        $hosts = array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            request()->getHost(),
        ], 'is_string');

        foreach ($hosts as $expected) {
            if (strcasecmp($host, $expected) === 0) {
                return true;
            }
        }

        return false;
    }
}
