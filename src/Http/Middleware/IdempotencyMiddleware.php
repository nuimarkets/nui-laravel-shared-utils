<?php

namespace NuiMarkets\LaravelSharedUtils\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class IdempotencyMiddleware
{
    private const SEPARATOR = "\x1f";

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('idempotency.enabled', false)) {
            return $next($request);
        }

        $user = $request->user();
        if ($user === null || ! $this->isWriteMethod($request->method())) {
            return $next($request);
        }

        $userId = $this->getUserId($user);
        if ($userId === null || $userId === '') {
            return $next($request);
        }

        $headerName = (string) config('idempotency.header_name', 'Idempotency-Key');
        $hasHeader = $request->headers->has($headerName);

        if (! $hasHeader && $this->requestContentTypeMatches($request, config('idempotency.body_hash_skip_content_types', []))) {
            return $next($request);
        }

        $actorScope = $this->actorScope($userId, $this->getOrgId($user));
        $method = strtoupper($request->method());
        $routeIdentity = $this->routeIdentity($request);
        $requestBody = $request->getContent();
        $bodyHash = hash('sha256', $requestBody === false ? '' : $requestBody);

        $resolved = $this->resolveKey($request, $hasHeader, $headerName, $actorScope, $method, $routeIdentity, $bodyHash);
        if ($resolved['error'] !== null) {
            return $this->errorResponse($request, 400, 'idempotency_key_invalid', $resolved['error']);
        }

        $source = $resolved['source'];
        $fingerprint = $this->fingerprint($actorScope, $method, $routeIdentity, $bodyHash);
        $cacheKey = $this->cacheKey($source, $actorScope, $method, $routeIdentity, $resolved['key']);
        $lockTtl = (int) config('idempotency.lock_ttl', 60);
        $lockedAt = $this->now();

        try {
            $connection = Redis::connection((string) config('idempotency.redis_connection', 'default'));
            $lockAcquired = $connection->set($cacheKey, json_encode([
                'fingerprint' => $fingerprint,
                'state' => 'inflight',
                'locked_at' => $lockedAt,
            ]), 'EX', $lockTtl, 'NX');
        } catch (Throwable $e) {
            $this->log('warning', 'idempotency.fail_open', $source, $cacheKey, $fingerprint, [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return $next($request);
        }

        if ($lockAcquired) {
            $this->log('info', 'idempotency.lock_acquired', $source, $cacheKey, $fingerprint);

            $response = $next($request);

            App::terminating(function () use ($connection, $cacheKey, $fingerprint, $source, $response, $lockedAt, $lockTtl, $resolved): void {
                $this->complete($connection, $cacheKey, $fingerprint, $source, $response, $lockedAt, $lockTtl, $resolved['ttl']);
            });

            return $response;
        }

        try {
            $existing = $this->decodePayload($connection->get($cacheKey));
        } catch (Throwable $e) {
            $this->log('warning', 'idempotency.fail_open', $source, $cacheKey, $fingerprint, [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return $next($request);
        }

        if (! $this->isReadablePayload($existing)) {
            $this->log('warning', 'idempotency.fail_open', $source, $cacheKey, $fingerprint);

            return $next($request);
        }

        if (($existing['fingerprint'] ?? null) !== $fingerprint) {
            $this->log('info', 'idempotency.conflict', $source, $cacheKey, $fingerprint);

            return $this->errorResponse(
                $request,
                422,
                'idempotency_key_conflict',
                'The idempotency key was already used for a different request.'
            );
        }

        if (($existing['state'] ?? null) === 'inflight') {
            if ($this->inflightExpired($existing, $lockTtl)) {
                try {
                    $connection->del($cacheKey);
                } catch (Throwable $e) {
                    $this->log('warning', 'idempotency.fail_open', $source, $cacheKey, $fingerprint, [
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ]);
                }

                return $next($request);
            }

            $retryAfter = $this->retryAfter($existing, $lockTtl);
            $this->log('info', 'idempotency.inflight_reject', $source, $cacheKey, $fingerprint);

            return $this->errorResponse(
                $request,
                409,
                'idempotency_request_inflight',
                'A request with this idempotency key is still being processed.',
                ['Retry-After' => (string) $retryAfter]
            );
        }

        if (($existing['state'] ?? null) === 'complete' && $this->isCompletePayload($existing)) {
            $this->log('info', 'idempotency.replay', $source, $cacheKey, $fingerprint);

            return $this->replayResponse($existing);
        }

        $this->log('warning', 'idempotency.fail_open', $source, $cacheKey, $fingerprint);

        return $next($request);
    }

    protected function now(): int
    {
        return now()->getTimestamp();
    }

    private function complete($connection, string $cacheKey, string $fingerprint, string $source, Response $response, int $lockedAt, int $lockTtl, int $ttl): void
    {
        try {
            $existing = $this->decodePayload($connection->get($cacheKey));

            if (
                ! is_array($existing)
                || ($existing['state'] ?? null) !== 'inflight'
                || ($existing['fingerprint'] ?? null) !== $fingerprint
                || (int) ($existing['locked_at'] ?? 0) !== $lockedAt
                || $this->now() > ($lockedAt + $lockTtl)
            ) {
                $this->log('warning', 'idempotency.lock_expired_before_complete', $source, $cacheKey, $fingerprint);

                return;
            }

            $skipReason = $this->skipReason($response);
            if ($skipReason !== null) {
                $connection->del($cacheKey);
                $this->log('info', 'idempotency.skip_cache', $source, $cacheKey, $fingerprint, [
                    'skip_reason' => $skipReason,
                ]);

                return;
            }

            $responseBody = $response->getContent();

            $connection->set($cacheKey, json_encode([
                'status' => $response->getStatusCode(),
                'headers' => $this->headersForStorage($response),
                'body_b64' => base64_encode($responseBody === false ? '' : $responseBody),
                'fingerprint' => $fingerprint,
                'state' => 'complete',
                'completed_at' => $this->now(),
                'locked_at' => $lockedAt,
            ]), 'EX', $ttl);
        } catch (Throwable $e) {
            $this->log('warning', 'idempotency.fail_open', $source, $cacheKey, $fingerprint, [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function resolveKey(Request $request, bool $hasHeader, string $headerName, string $actorScope, string $method, string $routeIdentity, string $bodyHash): array
    {
        if ($hasHeader) {
            $key = trim((string) $request->headers->get($headerName, ''));
            $maxLength = (int) config('idempotency.header_max_length', 255);

            if ($key === '') {
                return ['error' => 'The idempotency key must not be empty.'];
            }

            if (strlen($key) > $maxLength) {
                return ['error' => "The idempotency key must not exceed {$maxLength} characters."];
            }

            if (! preg_match('/^[\x21-\x7e]+$/', $key)) {
                return ['error' => 'The idempotency key must contain only printable ASCII characters with no whitespace.'];
            }

            return [
                'error' => null,
                'key' => $key,
                'source' => 'header',
                'ttl' => (int) config('idempotency.ttl_header', 600),
            ];
        }

        return [
            'error' => null,
            'key' => $this->join([$actorScope, $method, $routeIdentity, $bodyHash]),
            'source' => 'body_hash',
            'ttl' => (int) config('idempotency.ttl_body_hash', 30),
        ];
    }

    private function cacheKey(string $source, string $actorScope, string $method, string $routeIdentity, string $resolvedKey): string
    {
        $digest = hash('sha256', $this->join([$actorScope, $method, $routeIdentity, $resolvedKey]));

        return rtrim((string) config('idempotency.key_prefix', 'idem:v1'), ':').':'.$source.':'.$digest;
    }

    private function fingerprint(string $actorScope, string $method, string $routeIdentity, string $bodyHash): string
    {
        return hash('sha256', $this->join([$actorScope, $method, $routeIdentity, $bodyHash]));
    }

    private function join(array $components): string
    {
        return implode(self::SEPARATOR, array_map(static fn ($value): string => (string) $value, $components));
    }

    private function actorScope(string $userId, ?string $orgId): string
    {
        return $this->join(['user', $userId, 'org', $orgId ?? '']);
    }

    private function routeIdentity(Request $request): string
    {
        $route = $request->route();

        if ($route && $route->getName()) {
            $parameters = $route->parameters();
            ksort($parameters);

            return $this->join(['route', $route->getName(), $this->stableJson($parameters)]);
        }

        return $this->join(['path', '/'.ltrim($request->path(), '/'), $this->normalizedQuery($request->query())]);
    }

    private function normalizedQuery(array $query): string
    {
        $this->recursiveKsort($query);

        return $this->stableJson($query);
    }

    private function recursiveKsort(array &$value): void
    {
        ksort($value);

        foreach ($value as &$child) {
            if (is_array($child)) {
                $this->recursiveKsort($child);
            }
        }
    }

    private function stableJson($value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function getUserId($user): ?string
    {
        $id = $user->id ?? null;

        if ($id === null && method_exists($user, 'getAuthIdentifier')) {
            $id = $user->getAuthIdentifier();
        }

        if ($id === null && method_exists($user, 'getKey')) {
            $id = $user->getKey();
        }

        return $id === null ? null : (string) $id;
    }

    private function getOrgId($user): ?string
    {
        $id = $user->org_id ?? $user->organization_id ?? $user->organisation_id ?? null;

        return $id === null ? null : (string) $id;
    }

    private function isWriteMethod(string $method): bool
    {
        return in_array(strtoupper($method), ['POST', 'PATCH', 'PUT', 'DELETE'], true);
    }

    private function requestContentTypeMatches(Request $request, array $contentTypes): bool
    {
        return $this->contentTypeMatches((string) $request->headers->get('Content-Type', ''), $contentTypes);
    }

    private function contentTypeMatches(string $actual, array $allowed): bool
    {
        $actual = strtolower(trim(explode(';', $actual)[0]));

        if ($actual === '') {
            return false;
        }

        foreach ($allowed as $contentType) {
            if ($actual === strtolower((string) $contentType)) {
                return true;
            }
        }

        return false;
    }

    private function skipReason(Response $response): ?string
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return 'streamed';
        }

        if (! in_array($response->getStatusCode(), config('idempotency.replayable_status_codes', []), true)) {
            return 'status_code';
        }

        $content = $response->getContent();
        if ($content === false) {
            return 'streamed';
        }

        if (strlen($content) > (int) config('idempotency.max_response_bytes', 262144)) {
            return 'oversize';
        }

        if (! $this->contentTypeMatches((string) $response->headers->get('Content-Type', ''), config('idempotency.replayable_content_types', []))) {
            return 'content_type';
        }

        return null;
    }

    private function headersForStorage(Response $response): array
    {
        $headers = [];

        foreach (config('idempotency.replay_headers_allowlist', []) as $header) {
            $value = $response->headers->get((string) $header);
            if ($value !== null) {
                $headers[strtolower((string) $header)] = $value;
            }
        }

        return $headers;
    }

    private function decodePayload($payload): ?array
    {
        if (! is_string($payload) || $payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function isReadablePayload(?array $payload): bool
    {
        return is_array($payload)
            && isset($payload['fingerprint'])
            && isset($payload['state']);
    }

    private function isCompletePayload(array $payload): bool
    {
        return isset($payload['status'], $payload['body_b64'])
            && is_numeric($payload['status'])
            && base64_decode((string) $payload['body_b64'], true) !== false;
    }

    private function inflightExpired(array $payload, int $lockTtl): bool
    {
        if (! isset($payload['locked_at']) || ! is_numeric($payload['locked_at'])) {
            return false;
        }

        return $this->now() >= ((int) $payload['locked_at'] + $lockTtl);
    }

    private function retryAfter(array $payload, int $lockTtl): int
    {
        if (isset($payload['locked_at']) && is_numeric($payload['locked_at'])) {
            return max(1, ((int) $payload['locked_at'] + $lockTtl) - $this->now());
        }

        return (int) config('idempotency.retry_after_seconds', 5);
    }

    private function replayResponse(array $payload): Response
    {
        $headers = $payload['headers'] ?? [];
        $headers['X-Idempotency-Replay'] = '1';
        $headers['X-Idempotency-Original-Status'] = (string) $payload['status'];

        $response = new IlluminateResponse(
            base64_decode((string) $payload['body_b64']),
            (int) $payload['status'],
            $headers
        );

        $this->addNoStore($response);

        return $response;
    }

    private function errorResponse(Request $request, int $status, string $code, string $message, array $headers = []): Response
    {
        if ($this->wantsJsonApi($request)) {
            $body = [
                'errors' => [[
                    'status' => (string) $status,
                    'code' => $code,
                    'title' => $this->titleFromCode($code),
                    'detail' => $message,
                ]],
            ];
        } else {
            $body = [
                'error' => $code,
                'message' => $message,
            ];
        }

        $response = response()->json($body, $status, $headers);
        $this->addNoStore($response);

        return $response;
    }

    private function wantsJsonApi(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept', ''), 'application/vnd.api+json');
    }

    private function titleFromCode(string $code): string
    {
        return ucwords(str_replace('_', ' ', $code));
    }

    private function addNoStore(Response $response): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control', '');

        if (! str_contains(strtolower($cacheControl), 'no-store')) {
            $response->headers->set('Cache-Control', 'no-store');
        }
    }

    private function log(string $level, string $event, string $source, string $cacheKey, string $fingerprint, array $context = []): void
    {
        Log::{$level}($event, array_merge([
            'event' => $event,
            'idempotency_key_source' => $source,
            'cache_key' => $cacheKey,
            'fingerprint' => $fingerprint,
            'request_id' => request()?->headers->get('X-Request-ID'),
        ], $context));
    }
}
