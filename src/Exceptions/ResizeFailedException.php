<?php

namespace NuiMarkets\LaravelSharedUtils\Exceptions;

use Throwable;

/**
 * Thrown when AttachmentService::resizeImage cannot produce a resized output
 * (pixel cap exceeded, undecodable source, encoder failure). Maps to HTTP 422
 * via the BaseHttpRequestException parent so corrupt uploads surface as
 * client-fixable validation errors rather than 500s.
 */
class ResizeFailedException extends BaseHttpRequestException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 422, $previous);
    }
}
