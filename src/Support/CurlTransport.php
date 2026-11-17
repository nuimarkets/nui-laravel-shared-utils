<?php

namespace NuiMarkets\LaravelSharedUtils\Support;

/**
 * Whether Guzzle can send a request through cURL, which applies the request
 * `timeout` to the whole transfer. Without it Guzzle falls back to its PHP
 * stream handler, which applies the timeout to each read, so a call that must
 * end inside a time budget cannot rely on it.
 *
 * Follows Guzzle's own choice: a cURL handler needs curl_exec or
 * curl_multi_exec, and ext-curl can be loaded with both disabled through
 * disable_functions, which composer's platform check does not see. Recent
 * Guzzle also needs libcurl 7.21.2 or later.
 *
 * @internal
 */
final class CurlTransport
{
    private const MIN_LIBCURL = '7.21.2';

    public static function available(): bool
    {
        if (! function_exists('curl_exec') && ! function_exists('curl_multi_exec')) {
            return false;
        }

        $version = function_exists('curl_version') ? (curl_version()['version'] ?? null) : null;

        return is_string($version) && version_compare($version, self::MIN_LIBCURL, '>=');
    }
}
