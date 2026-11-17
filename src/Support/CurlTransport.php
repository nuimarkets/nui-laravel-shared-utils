<?php

namespace NuiMarkets\LaravelSharedUtils\Support;

/**
 * Whether Guzzle can send a request through cURL, which applies the request
 * `timeout` to the whole transfer. Without it Guzzle falls back to its PHP
 * stream handler, which applies the timeout to each read, so a call that must
 * end inside a time budget cannot rely on it.
 *
 * Follows Guzzle's own choice: a cURL handler needs the extension, curl_exec
 * or curl_multi_exec, and a recent enough libcurl. ext-curl can be loaded with
 * both functions disabled through disable_functions, which composer's platform
 * check does not see.
 *
 * @internal
 */
final class CurlTransport
{
    /**
     * Guzzle 7.9.0 and 7.9.1 use cURL only from libcurl 7.34; other 7.x
     * releases accept older versions. The strictest floor never reports cURL
     * where an installable Guzzle would use streams.
     */
    private const MIN_LIBCURL = '7.34';

    public static function available(): bool
    {
        return self::usable(
            \defined('CURLOPT_CUSTOMREQUEST'),
            \function_exists('curl_exec'),
            \function_exists('curl_multi_exec'),
            \function_exists('curl_version') ? (curl_version()['version'] ?? null) : null,
        );
    }

    /** The decision, apart from the probes, so its boundaries can be tested. */
    public static function usable(bool $extension, bool $exec, bool $multiExec, ?string $libcurl): bool
    {
        return $extension
            && ($exec || $multiExec)
            && $libcurl !== null
            && version_compare($libcurl, self::MIN_LIBCURL, '>=');
    }
}
