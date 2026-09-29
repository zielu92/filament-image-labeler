<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic annotation
    |--------------------------------------------------------------------------
    |
    | When auto-annotating, the package may download the displayed image from
    | a remote http(s) URL so your model's autoAnnotate() gets a local file.
    | By default that fetch refuses destinations that resolve to private,
    | loopback, link-local (e.g. cloud metadata 169.254.169.254) or reserved
    | IP ranges, and it never follows redirects - classic SSRF guardrails.
    |
    | Set this to true only if your image URLs genuinely live on an internal
    | network you control; it re-enables fetching private destinations.
    |
    */

    'allow_private_image_hosts' => env('IMAGE_LABELER_ALLOW_PRIVATE_IMAGE_HOSTS', false),

];
