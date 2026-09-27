<?php

namespace Zielu92\FilamentImageLabeler\Support;

class FetchesImage
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    /**
     * Resolve a locally readable path for the image behind $url: paths pass
     * through, http(s) URLs are downloaded to a temp file that is removed at
     * the end of the request. Anything else yields null.
     */
    public static function localize(string $url): ?string
    {
        if (str_starts_with($url, 'file://')) {
            $url = substr($url, 7);
        }

        // Any other wrapper scheme: http(s) only, never let is_file() stat a URL.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1) {
            if (preg_match('#^https?://#i', $url) !== 1 || ! filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
                return null;
            }
        } elseif (is_file($url)) {
            return $url;
        } else {
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

        $path = tempnam(sys_get_temp_dir(), 'fil-ill-');

        if ($path === false || file_put_contents($path, $data) === false) {
            return null;
        }

        app()->terminating(fn () => @unlink($path));

        return $path;
    }
}
