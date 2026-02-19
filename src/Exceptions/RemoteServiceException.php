<?php

namespace NuiMarkets\LaravelSharedUtils\Exceptions;

use NuiMarkets\LaravelSharedUtils\Logging\LogFields;

/**
 * Exception thrown when remote service calls fail
 *
 * This exception is used to represent failures when calling external services.
 * It properly returns HTTP 502 (Bad Gateway) or 503 (Service Unavailable) status codes
 * instead of the generic 500 error.
 *
 * @example
 * // Bad Gateway - remote service returned an error
 * throw new RemoteServiceException('Remote API returned error', 502);
 *
 * // Service Unavailable - remote service is down/timeout
 * throw new RemoteServiceException('Service timeout', 503);
 *
 * // Using factory method for structured context
 * throw RemoteServiceException::fromRemoteResponse('AddressRepository', '/v4/addresses/123', 400, ['No address found']);
 */
class RemoteServiceException extends BaseHttpRequestException
{
    private ?string $remoteService = null;

    private ?string $remoteEndpoint = null;

    private ?int $remoteStatusCode = null;

    /** @var array<string> */
    private array $remoteErrors = [];

    /**
     * Create a new RemoteServiceException instance
     *
     * @param  string  $message  The error message
     * @param  int  $statusCode  HTTP status code (default: 502 Bad Gateway)
     * @param  \Throwable|null  $previous  The previous exception for chaining
     * @param  array  $tags  Tags for Sentry categorization
     * @param  array  $extra  Extra context data for logging/Sentry
     */
    public function __construct(
        string $message,
        int $statusCode = 502,
        ?\Throwable $previous = null,
        array $tags = [],
        array $extra = []
    ) {
        parent::__construct($message, $statusCode, $previous, $tags, $extra);
    }

    /**
     * Create a RemoteServiceException with structured context from a remote API response.
     *
     * Produces a clean, human-readable message and populates tags/extra so that
     * ErrorLogger::logException() or BaseErrorHandler::report() can emit a single
     * rich log entry without callers needing to manually build context arrays.
     *
     * @param  string  $service  Short service/repository name (e.g. 'AddressRepository')
     * @param  string  $endpoint  The API endpoint that was called
     * @param  int  $statusCode  HTTP status code from the remote service
     * @param  array<string>  $errorDetails  Error detail strings from the response
     */
    public static function fromRemoteResponse(
        string $service,
        string $endpoint,
        int $statusCode,
        array $errorDetails = []
    ): self {
        $detail = implode('; ', array_filter($errorDetails));
        $message = $detail !== ''
            ? "Remote service error ({$statusCode}): {$detail}"
            : "Remote service error ({$statusCode})";

        $instance = new self($message, $statusCode, null,
            tags: ['remote_service' => $service],
            extra: [
                LogFields::API_SERVICE => $service,
                LogFields::API_ENDPOINT => $endpoint,
                LogFields::API_STATUS => $statusCode,
                'api.errors' => $errorDetails,
            ]
        );
        $instance->remoteService = $service;
        $instance->remoteEndpoint = $endpoint;
        $instance->remoteStatusCode = $statusCode;
        $instance->remoteErrors = $errorDetails;

        return $instance;
    }

    public function getRemoteService(): ?string
    {
        return $this->remoteService;
    }

    public function getRemoteEndpoint(): ?string
    {
        return $this->remoteEndpoint;
    }

    public function getRemoteStatusCode(): ?int
    {
        return $this->remoteStatusCode;
    }

    /** @return array<string> */
    public function getRemoteErrors(): array
    {
        return $this->remoteErrors;
    }
}
