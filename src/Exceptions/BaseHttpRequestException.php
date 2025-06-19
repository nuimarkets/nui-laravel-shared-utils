<?php

namespace Nuimarkets\LaravelSharedUtils\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Main Exception handler for something gone wrong in the request
 *
 * Note this is a replacement for the built in BadRequestHttpException which is hardcoded for 400 status
 * Also shown in response if APP_DEBUG=true and or log/sentry
 *  - custom "tag" data used by sentry
 *  - custom "extra" data used by sentry/logging
 *  - preserving previous exception if its re throwing
 *
 * Usage:
 *      new BaseHttpRequestException('Failed to create order', 500, $exception,
 *              tags: ['test' => 'tag'], extra: ['misc' => 123]);
 *
 *  Note it's recommended to NOT use $e->getMessage() for message to avoid exposing the internal exception info in response
 */
class BaseHttpRequestException extends HttpException
{
    protected ?\Throwable $previous = null;

    protected array $tags = [];

    protected array $extra = [];

    public function __construct(string $message, int $statusCode = 400, ?\Throwable $previous = null, array $tags = [], array $extra = [])
    {
        $this->previous = $previous;
        // Tags for Sentry
        $this->tags = $tags;
        // Extra for logging/Sentry
        $this->extra = $extra;

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
