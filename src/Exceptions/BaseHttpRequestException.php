<?php

namespace NuiMarkets\LaravelSharedUtils\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Main Exception handler for something gone wrong in the request
 *
 * Note this is a replacement for the built in BadRequestHttpException which is hardcoded for 400 status
 * Also shown in response if APP_DEBUG=true and or log/sentry
 *  - custom "tag" data used by sentry
 *  - custom "extra" data used by debug responses, sentry and logging
 *  - custom "logExtra" data used only by sentry and logging
 *  - preserving previous exception if its re throwing
 *
 * Usage:
 *      new BaseHttpRequestException('Failed to create order', 500, $exception,
 *              tags: ['test' => 'tag'], extra: ['request_id' => 123],
 *              logExtra: ['upstream_response' => $body]);
 *
 *  Note it's recommended to NOT use $e->getMessage() for message to avoid exposing the internal exception info in response
 */
class BaseHttpRequestException extends HttpException
{
    protected ?\Throwable $previous = null;

    protected array $tags = [];

    protected array $extra = [];

    protected array $logExtra = [];

    public function __construct(
        string $message,
        int $statusCode = 400,
        ?\Throwable $previous = null,
        array $tags = [],
        array $extra = [],
        array $logExtra = [],
    ) {
        $this->previous = $previous;
        // Tags for Sentry
        $this->tags = $tags;
        // Extra for debug responses, logging and Sentry
        $this->extra = $extra;
        // Sensitive diagnostic context for logging and Sentry only
        $this->logExtra = $logExtra;

        if ($previous instanceof HttpException && $statusCode === 400) {
            $statusCode = $previous->getStatusCode();
        }

        parent::__construct($statusCode, $message, $previous);
    }

    public function getExtra(): array
    {
        return $this->extra;
    }

    public function withExtra(array $extra): self
    {
        $this->extra = $extra;

        return $this;
    }

    public function getLogExtra(): array
    {
        return $this->logExtra;
    }

    public function withLogExtra(array $logExtra): self
    {
        $this->logExtra = $logExtra;

        return $this;
    }

    /**
     * Context for logs and Sentry. Log-only values win on a duplicate key.
     */
    public function getLogContext(): array
    {
        return array_merge($this->extra, $this->logExtra);
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function withTags(array $tags): self
    {
        $this->tags = $tags;

        return $this;
    }
}
