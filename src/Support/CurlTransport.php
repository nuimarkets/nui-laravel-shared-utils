<?php

namespace NuiMarkets\LaravelSharedUtils\Support;

/**
 * Whether Guzzle can send a request through cURL, which applies the request
 * `timeout` to the whole transfer. Without it Guzzle falls back to its PHP
 * stream handler, which applies the timeout to each read, so a call that must
 * end inside a time budget cannot rely on it.
 *
 * Follows Guzzle's own choice: a cURL handler needs the extension, curl_exec
 * or curl_multi_exec, and a recent enough libcurl, built with SSL. ext-curl
 * can be loaded with both functions disabled through disable_functions, which
 * composer's platform check does not see.
 *
 * @internal
 */
final class CurlTransport
{
    /**
     * Two reasons, so the floor stays needed while either holds:
     *
     * - Guzzle 7.9.0 and 7.9.1 use cURL only from libcurl 7.34; other 7.x
     *   releases accept older versions.
     * - From Guzzle 7.13, a request asking for TLS 1.2 as its `crypto_method`,
     *   which Laravel's HTTP client does by default, goes to the stream handler
     *   unless libcurl is 7.34 or later and built with SSL
     *   (`Proxy::wrapTlsFallback`, `CurlVersion::supportsTls12`), whatever
     *   the URL's scheme.
     *
     * The strictest floor never reports cURL where an installable Guzzle would
     * use streams.
     */
    private const MIN_LIBCURL = '7.34';

    public static function available(): bool
    {
        $info = \function_exists('curl_version') ? curl_version() : false;

        return self::usable(
            \defined('CURLOPT_CUSTOMREQUEST'),
            \function_exists('curl_exec'),
            \function_exists('curl_multi_exec'),
            \is_array($info) ? ($info['version'] ?? null) : null,
            \is_array($info)
                && \defined('CURL_VERSION_SSL')
                && \defined('CURL_SSLVERSION_TLSv1_2')
                && \is_int($info['features'] ?? null)
                && ($info['features'] & \CURL_VERSION_SSL) !== 0,
        );
    }

    /**
     * The decision, apart from the probes, so its boundaries can be tested.
     *
     * @param  bool  $tls12  libcurl is built with SSL and PHP defines its TLS 1.2 option.
     */
    public static function usable(bool $extension, bool $exec, bool $multiExec, ?string $libcurl, bool $tls12): bool
    {
        return $extension
            && ($exec || $multiExec)
            && $libcurl !== null
            && version_compare($libcurl, self::MIN_LIBCURL, '>=')
            && $tls12;
    }
}
