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
     */
    public static function localize(string $url): ?string
    {
        if (str_starts_with($url, 'file://')) {
            $url = substr($url, 7);
        }

        // Never let is_file()/stat hit a stream wrapper.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1) {
            if (preg_match('#^https?://#i', $url) !== 1) {
                return null;
            }

            $path = parse_url($url, PHP_URL_PATH);

            if (static::isSameHost($url)) {
                // Serve-the-same-app URL: only accept plain public files
                // (/storage/... via the artisan storage:link symlink).
                if (! is_string($path) || ! str_starts_with($path, '/storage/')) {
                    return null;
                }

                $public = realpath(public_path($path));
                $root = realpath(public_path('storage'));

                return $public !== false && $root !== false && str_starts_with($public, $root) && is_file($public)
                    ? $public
                    : null;
            }

            if (! filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
                return null;
            }

            $data = @file_get_contents(
                $url,
                false,
                stream_context_create(['http' => ['timeout' => 15], 'https' => ['timeout' => 15]]),
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

        return is_file($url) ? $url : null;
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
