<?php

namespace NuiMarkets\LaravelSharedUtils\RemoteRepositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use NuiMarkets\LaravelSharedUtils\Contracts\HeaderResolverInterface;
use NuiMarkets\LaravelSharedUtils\Contracts\MachineTokenServiceInterface;
use NuiMarkets\LaravelSharedUtils\Exceptions\RemoteServiceException;
use NuiMarkets\LaravelSharedUtils\Support\ProfilingTrait;
use NuiMarkets\LaravelSharedUtils\Support\SimpleDocument;
use Swis\JsonApi\Client\Document;
use Swis\JsonApi\Client\Error as JsonApiError;
use Swis\JsonApi\Client\ErrorCollection;
use Swis\JsonApi\Client\Exceptions\ValidationException;
use Swis\JsonApi\Client\Interfaces\DocumentClientInterface;
use Swis\JsonApi\Client\Interfaces\DocumentInterface;
use Swis\JsonApi\Client\Interfaces\ItemDocumentInterface;
use Swis\JsonApi\Client\Item;

abstract class RemoteRepository
{
    use ProfilingTrait;

    protected Collection $data;

    private ?DocumentClientInterface $client = null;

    /**
     * @var MachineTokenServiceInterface - Token service for lazy loading
     */
    private MachineTokenServiceInterface $machineTokenService;

    /**
     * @var string|null - Cached token (lazy-loaded on first request)
     */
    private ?string $token = null;

    /**
     * @var array - Headers to be sent with each request
     */
    private array $headers;

    /**
     * @var int - Number of retry attempts
     */
    protected int $retry = 1;

    /**
     * @var array - Patterns for recoverable errors
     */
    protected array $recoverableErrorPatterns = [];

    /**
     * RemoteRepository constructor.
     */
    public function __construct(DocumentClientInterface $client, MachineTokenServiceInterface $machineTokenService)
    {
        $this->client = $client;
        $this->client->setBaseUri($this->getConfiguredBaseUri());

        // Store token service for lazy loading (token not retrieved until first request)
        $this->machineTokenService = $machineTokenService;

        // Initialize base headers (Authorization header will be added lazily)
        $this->headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $this->data = new Collection;
    }

    /**
     * Lazy-load the authentication token on first request.
     * This ensures tokens are only retrieved when actually needed.
     *
     * @throws \RuntimeException if token retrieval fails
     */
    protected function ensureTokenLoaded(): void
    {
        if ($this->token !== null) {
            return; // Token already loaded
        }

        $this->token = $this->retrieveAndValidateToken($this->machineTokenService);

        // Update Authorization header with the loaded token
        $this->headers['Authorization'] = 'Bearer '.$this->token;

        // Add request ID if available
        $requestId = $this->getCurrentRequestId();
        if ($requestId) {
            $this->headers['X-Request-ID'] = $requestId;
        }

        // Add X-Ray trace header if available (preserves full trace context)
        $traceHeader = $this->getCurrentTraceHeader();
        if ($traceHeader) {
            $this->headers['X-Amzn-Trace-Id'] = $traceHeader;
        }

        // Add correlation ID as fallback for non-X-Ray scenarios
        $traceId = $this->getCurrentTraceId();
        if ($traceId) {
            $this->headers['X-Correlation-ID'] = $traceId;
        }

        // Passthrough headers - forward if present in incoming request
        $passthroughHeaders = config('app.remote_repository.passthrough_headers') ?? [];
        foreach ($passthroughHeaders as $header) {
            $value = request()?->headers->get($header);
            if ($value !== null) {
                $this->headers[$header] = $value;
            }
        }

        // Contextual headers - passthrough OR resolve via resolver class
        $contextualHeaders = config('app.remote_repository.contextual_headers') ?? [];
        foreach ($contextualHeaders as $header => $resolverClass) {
            // First try passthrough
            $value = request()?->headers->get($header);

            // If not present, use resolver
            if ($value === null && $resolverClass && class_exists($resolverClass)) {
                $resolver = app($resolverClass);
                if ($resolver instanceof HeaderResolverInterface) {
                    $value = $resolver->resolve();
                }
            }

            if ($value !== null) {
                $this->headers[$header] = $value;
            }
        }
    }

    /**
     * Retrieve and validate token from the service
     *
     * @throws \RuntimeException
     */
    protected function retrieveAndValidateToken(MachineTokenServiceInterface $machineTokenService): string
    {
        try {
            $token = $machineTokenService->getToken();
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'Failed to retrieve token from machine token service: '.$e->getMessage(),
                0,
                $e
            );
        }

        if (! is_string($token) || empty(trim($token))) {
            throw new \RuntimeException(
                'Machine token service returned invalid token. Expected non-empty string, got: '.
                (is_string($token) ? 'empty string' : gettype($token))
            );
        }

        return trim($token);
    }

    /**
     * Set recoverable error patterns
     */
    public function setRecoverableErrorPatterns(array $patterns): void
    {
        $this->recoverableErrorPatterns = $patterns;
    }

    /**
     * Get recoverable error patterns from config or default
     */
    protected function getRecoverableErrorPatterns(): array
    {
        return $this->recoverableErrorPatterns ?: config('app.remote_repository.recoverable_error_patterns', [
            'Duplicate active delivery address codes found',
        ]);
    }

    /**
     * Get base URI from various config sources
     */
    protected function getConfiguredBaseUri(): string
    {
        // Primary configuration location (standardized)
        $baseUri = config('app.remote_repository.base_uri');
        if ($baseUri !== null) {
            return $baseUri;
        }

        // Legacy fallback configuration keys for backward compatibility
        // TODO: Remove these fallbacks after Connect projects have been migrated
        $legacyConfigKeys = [
            'jsonapi.base_uri',
            'pxc.base_api_uri',
            'remote.base_uri',
        ];

        $missingKeys = ['app.remote_repository.base_uri'];

        foreach ($legacyConfigKeys as $key) {
            $value = config($key);
            if ($value !== null) {
                Log::warning("Using deprecated config key '{$key}'. Please migrate to 'app.remote_repository.base_uri'");

                return $value;
            }
            $missingKeys[] = $key;
        }

        throw new \RuntimeException(
            'No remote service base URI configured. Checked the following config keys: '.
            implode(', ', $missingKeys).'. Please set one of these configuration values.'
        );
    }

    /**
     * Get base URL length for validation
     */
    protected function getBaseUrlLength(): int
    {
        return $this->client ? strlen($this->client->getBaseUri()) : 0;
    }

    /**
     * Check if GET request URL is valid and length is allowed
     */
    public function allowedGetRequest(string $urlPath): bool
    {
        // First, validate the URL path format and check for malicious patterns
        if (! $this->isValidUrlPath($urlPath)) {
            return false;
        }

        // Then check the length constraint
        $maxLength = config('app.remote_repository.max_url_length', config('pxc.max_url_length', 2048));

        return strlen($urlPath) < ($maxLength - $this->getBaseUrlLength());
    }

    /**
     * Validate URL path for security and format
     */
    protected function isValidUrlPath(string $urlPath): bool
    {
        // Check for empty or whitespace-only paths
        if (empty(trim($urlPath))) {
            return false;
        }

        // Check for null bytes which can cause security issues
        if (strpos($urlPath, "\0") !== false) {
            return false;
        }

        // Check for directory traversal attempts
        $dangerousPatterns = [
            '../',
            '..\\',
            '%2e%2e%2f',
            '%2e%2e%5c',
            '..%2f',
            '..%5c',
            '%252e%252e%252f',
            '..%252f',
            '..%255c',
        ];

        $lowerPath = strtolower($urlPath);
        foreach ($dangerousPatterns as $pattern) {
            if (strpos($lowerPath, $pattern) !== false) {
                return false;
            }
        }

        // Check for common injection patterns
        if (preg_match('/[<>\"\'`]|javascript:|data:|vbscript:|file:|about:|chrome:|ms-|moz-|opera-|webkit-/i', $urlPath)) {
            return false;
        }

        // Validate URL encoding - ensure it's properly encoded
        $decoded = urldecode($urlPath);
        if ($decoded !== $urlPath && urldecode($decoded) !== $decoded) {
            // Double encoding detected
            return false;
        }

        // Check for valid URL path characters (RFC 3986)
        // Allow: alphanumeric, dash, underscore, dot, tilde, forward slash, colon, at,
        // question mark, ampersand, equals, brackets, and percent (for encoding)
        if (! preg_match('/^[a-zA-Z0-9\-_.~\/:\?@&=\[\]%!$()*+,;\']+$/', $urlPath)) {
            return false;
        }

        // Additional check for consecutive slashes which might indicate issues
        if (strpos($urlPath, '//') !== false && ! preg_match('/^https?:\/\//', $urlPath)) {
            return false;
        }

        return true;
    }

    /**
     * Check if an error message matches any recoverable error pattern
     */
    protected function isRecoverableError(string $errorMessage): bool
    {
        $patterns = $this->getRecoverableErrorPatterns();

        foreach ($patterns as $pattern) {
            if (str_contains($errorMessage, $pattern)) {
                return true;
            }
        }

        return false;
    }

    final public function get($url): DocumentInterface
    {
        if (! $this->client) {
            throw new \RuntimeException('Client not initialized - tests should mock this repository');
        }

        // Lazy-load token on first request
        $this->ensureTokenLoaded();

        $startTime = $this->profileStart(__METHOD__);
        $retry = $this->retry;

        while (true) {
            try {
                if (config('app.remote_repository.log_requests', config('pxc.api_log_requests', false))) {
                    Log::debug('API GET', ['url' => $url]);
                }

                $res = $this->client->get($url, $this->headers);

                if ($res->hasErrors()) {
                    // Check for specialized error patterns before general handling
                    foreach ($res->getErrors() as $error) {
                        $msg = $error->getDetail();
                        if ($msg && $this->isRecoverableError($msg)) {
                            \Sentry\captureMessage($msg);
                            $this->profileEnd(__METHOD__, $startTime);

                            // Create a proper Document with the error to match return type
                            $errorDocument = new Document;
                            $errorCollection = new ErrorCollection;
                            $jsonApiError = new JsonApiError(
                                null, // id
                                null, // links
                                '400', // status
                                'recoverable_error', // code
                                null, // title
                                $msg, // detail
                                null, // source
                                null  // meta
                            );
                            $errorCollection->push($jsonApiError);
                            $errorDocument->setErrors($errorCollection);

                            return $errorDocument;
                        }
                    }
                    $this->handleApiErrors($res, $url);
                }

                $this->profileEnd(__METHOD__, $startTime);

                return $res;

            } catch (ValidationException $e) {
                $this->handleValidationException($e, $url);

            } catch (RemoteServiceException $e) {
                // 4xx = definitive response from remote (validation, not found, etc.) — don't retry
                if ($e->getStatusCode() >= 400 && $e->getStatusCode() < 500) {
                    $this->profileEnd(__METHOD__, $startTime);
                    throw $e;
                }

                // 5xx = remote server error — may be transient, retry
                $retry--;
                if ($retry > 0) {
                    sleep(1);
                } else {
                    \Sentry\captureException($e);
                    $this->profileEnd(__METHOD__, $startTime);
                    throw $e;
                }

            } catch (\Exception $exception) {
                // Network errors, timeouts, etc. — retry then wrap as 503
                $retry--;
                if ($retry > 0) {
                    sleep(1);
                } else {
                    \Sentry\captureException($exception);
                    $this->profileEnd(__METHOD__, $startTime);
                    throw new RemoteServiceException('Error getting response from remote server: '.$exception->getMessage(), 503, $exception);
                }
            }
        }
    }

    final public function getUserUrl($url): DocumentInterface
    {
        if (! $this->client) {
            throw new \RuntimeException('Client not initialized - tests should mock this repository');
        }

        // Lazy-load token on first request
        $this->ensureTokenLoaded();

        $startTime = $this->profileStart(__METHOD__);
        $retry = $this->retry;

        while (true) {
            try {
                Log::debug('getUserUrl', ['url' => $url]);

                $res = $this->client->get($url, $this->headers);

                if ($res->hasErrors()) {
                    $errorMessages = [];
                    foreach ($res->getErrors() as $error) {
                        $msg = $error->getDetail();
                        if ($msg) {
                            \Sentry\captureMessage($msg);
                        }
                        $errorMessages[] = $msg;
                    }
                    Log::error('Remote repository returned errors', $errorMessages);
                }

                $this->profileEnd(__METHOD__, $startTime);

                return $res;

            } catch (ValidationException $e) {
                $this->handleValidationException($e, $url);

            } catch (\Exception $exception) {
                $retry--;
                if ($retry > 0) {
                    sleep(1);
                } else {
                    \Sentry\captureException($exception);
                    $this->profileEnd(__METHOD__, $startTime);
                    throw $exception; // getUserUrl throws original exception
                }
            }
        }
    }

    final public function post($url, ItemDocumentInterface $data): DocumentInterface
    {
        if (! $this->client) {
            throw new \RuntimeException('Client not initialized - tests should mock this repository');
        }

        // Lazy-load token on first request
        $this->ensureTokenLoaded();

        $startTime = $this->profileStart(__METHOD__);
        $retry = $this->retry;

        while (true) {
            try {
                if (config('app.remote_repository.log_requests', config('pxc.api_log_requests', false))) {
                    Log::info('Request Debug', [
                        'url' => $url,
                        'body' => $this->client->encode($data),
                        'headers' => $this->headers,
                    ]);
                    Log::debug('API POST', ['url' => $url]);
                }

                $res = $this->client->post($url, $data, $this->headers);

                if ($res->hasErrors()) {
                    $httpResponse = $res->getResponse();
                    $statusCode = $httpResponse
                        ? self::extractValidHttpStatusCode($httpResponse->getStatusCode())
                        : 502;

                    $errorDetails = array_map(
                        static fn ($e) => $e->getDetail() ?? '',
                        $res->getErrors()->toArray()
                    );

                    // No logging here — exception carries structured context,
                    // caller or error handler logs once with ErrorLogger::logException()
                    $serviceName = class_basename(static::class);
                    throw RemoteServiceException::fromRemoteResponse($serviceName, $url, $statusCode, $errorDetails);
                }

                $this->profileEnd(__METHOD__, $startTime);

                return $res;

            } catch (ValidationException $e) {
                $this->handleValidationException($e, $url);

            } catch (RemoteServiceException $e) {
                // 4xx = definitive response from remote — don't retry
                if ($e->getStatusCode() >= 400 && $e->getStatusCode() < 500) {
                    $this->profileEnd(__METHOD__, $startTime);
                    throw $e;
                }

                // 5xx = remote server error — may be transient, retry
                $retry--;
                if ($retry > 0) {
                    sleep(1);
                } else {
                    \Sentry\captureException($e);
                    $this->profileEnd(__METHOD__, $startTime);
                    throw $e;
                }

            } catch (\Exception $exception) {
                // Network errors, timeouts, etc. — retry then wrap as 503
                $retry--;
                if ($retry > 0) {
                    sleep(1);
                } else {
                    \Sentry\captureException($exception);
                    $this->profileEnd(__METHOD__, $startTime);
                    throw new RemoteServiceException(
                        'Error getting response from remote server: '.$exception->getMessage(),
                        503,
                        $exception
                    );
                }
            }
        }
    }

    /**
     * Handle ValidationException from malformed API responses
     */
    private function handleValidationException(ValidationException $e, string $url): void
    {
        // ValidationException doesn't have getResponse() method - log available information
        Log::error('Remote API returned validation error', [
            'url' => $url,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        // Extract meaningful error message from the exception
        $errorMessage = $e->getMessage() ?: 'Remote API validation error';

        \Sentry\captureException($e);
        throw new RemoteServiceException($errorMessage, 502, $e);
    }

    /**
     * Extract meaningful error message from various response formats
     */
    private function extractErrorMessage($decoded): string
    {
        if (! is_array($decoded)) {
            return 'Remote service returned invalid response format';
        }

        return $decoded['errorMessage']
            ?? $decoded['message']
            ?? $decoded['error']
            ?? 'Remote service error - see logs for details';
    }

    /**
     * Handle API errors from valid JSON API responses.
     *
     * Preserves the original HTTP status code from the remote service instead of
     * wrapping all errors as 502. This enables smarter retry logic and caching
     * based on the actual error type.
     */
    private function handleApiErrors(DocumentInterface $response, string $url): void
    {
        $this->throwRemoteServiceError($response, $url);
    }

    /**
     * Shared error handling for remote service errors.
     *
     * Extracts error details from the response, logs them with structured context,
     * and throws a RemoteServiceException with the original HTTP status code.
     *
     * @param  DocumentInterface  $response  The response containing errors
     * @param  string|null  $endpoint  Optional endpoint URL for logging context
     *
     * @throws RemoteServiceException Always throws after logging
     */
    private function throwRemoteServiceError(DocumentInterface $response, ?string $endpoint = null): void
    {
        $httpResponse = $response->getResponse();
        $serviceName = class_basename(static::class);

        // Handle edge case where response is null
        if ($httpResponse === null) {
            throw RemoteServiceException::fromRemoteResponse(
                $serviceName,
                $endpoint ?? 'unknown',
                502,
                ['No response available']
            );
        }

        $statusCode = self::extractValidHttpStatusCode($httpResponse->getStatusCode());

        // Collect error details for structured context
        $errorDetails = [];
        foreach ($response->getErrors() as $error) {
            $errorDetails[] = $error->getDetail();
        }

        // No logging here — caller or error handler logs once with full context
        throw RemoteServiceException::fromRemoteResponse(
            $serviceName,
            $endpoint ?? 'unknown',
            $statusCode,
            $errorDetails
        );
    }

    /**
     * Extract and validate HTTP status code, with fallback to 502 for invalid codes.
     */
    private static function extractValidHttpStatusCode(?int $statusCode): int
    {
        // Null or invalid status code - use 502
        if ($statusCode === null || $statusCode < 100 || $statusCode >= 600) {
            return 502;
        }

        // If status is 2xx but we're in error handling, something is wrong - use 502
        if ($statusCode >= 200 && $statusCode < 300) {
            Log::warning('Received 2xx status with errors array - using 502', [
                'original_status' => $statusCode,
            ]);

            return 502;
        }

        return $statusCode;
    }

    /**
     * Create a request body document from data
     *
     * @param  mixed  $data  The data to create the request body from
     *
     * @throws RemoteServiceException if unable to create request body
     */
    public function makeRequestBody($data): SimpleDocument
    {
        Item::unguard();

        try {
            // Validate input data
            if (! is_array($data) && ! is_object($data)) {
                throw new \InvalidArgumentException(
                    'Data must be an array or object, '.gettype($data).' given'
                );
            }

            // Convert object to array if necessary (Item constructor requires array)
            $itemData = is_object($data) ? (array) $data : $data;

            // Create and configure the Item
            $item = new Item($itemData);
            $item->setType('array');

            // Create the document and set the data
            $body = new SimpleDocument;
            $body->setData($item);

            return $body;

        } catch (\InvalidArgumentException $e) {
            Log::error('Invalid data provided to makeRequestBody', [
                'error' => $e->getMessage(),
                'data_type' => gettype($data),
            ]);
            throw new RemoteServiceException(
                'Failed to create request body: '.$e->getMessage(),
                400,
                $e
            );
        } catch (\Exception $e) {
            Log::error('Error creating request body', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new RemoteServiceException(
                'Failed to create request body: '.$e->getMessage(),
                500,
                $e
            );
        } finally {
            // Always reguard to prevent memory leaks
            Item::reguard();
        }
    }

    public function hasId($id): bool
    {
        return isset($this->data[$id]);
    }

    /**
     * Handle response and extract data, or throw exception on error.
     *
     * Preserves the original HTTP status code from the remote service.
     *
     * @throws RemoteServiceException
     */
    public function handleResponse(DocumentInterface $response)
    {
        if ($response->hasErrors()) {
            $this->throwRemoteServiceError($response);
        }

        return $response->getData();
    }

    /**
     * Cache data
     */
    public function cache($response)
    {
        $data = $response->getData();
        if (! $data) {
            return;
        }

        foreach ($data as $item) {
            $this->data->put($item->getId(), $item);
        }
    }

    /**
     * Put the single object into the data, the response only has one object.
     *
     * @param  mixed  $response
     */
    public function cacheOne($response)
    {
        $data = $response->getData();
        if (! $data) {
            return;
        }
        $this->data->put($data->getId(), $data);
    }

    /**
     * Find by collection key
     */
    public function findById($id)
    {
        $ret = $this->findByIds([$id]);
        if (empty($ret)) {
            return null;
        }

        return $ret->first();
    }

    /**
     * Find by collection key, but do not trigger API
     * call if it is not already retrieved
     */
    public function findByIdWithoutRetrieve($id)
    {
        return $this->query()->get($id);
    }

    /**
     * Find by collection keys
     */
    public function findByIds(array $ids = [])
    {
        try {
            $query = $this->query();
            $result = [];
            foreach ($ids as $i => $id) {
                if (! $id) {
                    unset($ids[$i]);

                    continue;
                }
                if (! $query->has($id)) {
                    $result[] = $id;
                }
            }

            if (count($result) > 0) {
                $this->filter($result);
            }

            return $query->only($ids);
        } catch (\Exception $e) {
            Log::error('Error in findByIds method', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ids' => $ids,
            ]);

            // Send to Sentry for monitoring
            \Sentry\captureException($e);

            // Throw exception instead of returning null to prevent silent failures
            throw new RemoteServiceException('Error in findByIds: '.$e->getMessage(), 502, $e);
        }
    }

    public function query(): Collection
    {
        return $this->data;
    }

    /**
     * Get the current request ID from the log context.
     */
    protected function getCurrentRequestId(): ?string
    {
        // Fallback to request headers if available
        if (request() && request()->headers) {
            return request()->headers->get('X-Request-ID');
        }

        return null;
    }

    /**
     * Get the current X-Ray trace ID from the log context.
     */
    protected function getCurrentTraceId(): ?string
    {
        // Fallback to request headers if available
        if (request() && request()->headers) {
            $traceHeader = request()->headers->get('X-Amzn-Trace-Id');
            if ($traceHeader && preg_match('/Root=([^;]+)/', $traceHeader, $matches)) {
                return $matches[1];
            }

            return $traceHeader;
        }

        return null;
    }

    /**
     * Get the full X-Ray trace header for propagation to downstream services.
     * This preserves the complete trace context including Parent and Sampled flags.
     */
    protected function getCurrentTraceHeader(): ?string
    {
        // Get full trace header to preserve X-Ray trace context
        if (request() && request()->headers) {
            return request()->headers->get('X-Amzn-Trace-Id');
        }

        return null;
    }

    /**
     * Abstract method that child classes must implement
     * This is where the ValidationException typically occurs
     */
    abstract protected function filter(array $data);
}
