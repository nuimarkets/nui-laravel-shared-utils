<?php

namespace NuiMarkets\LaravelSharedUtils\Auth;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * A response sink that refuses bytes past a limit.
 *
 * Guzzle's cURL handler writes the decoded body into the sink as it arrives
 * and aborts the transfer when a write comes back short, so the limit holds on
 * the bytes received after decompression, before they are buffered, whatever
 * the response declares about its length.
 *
 * @internal
 */
final class BoundedBodyStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $written = 0;

    private bool $overflowed = false;

    public function __construct(StreamInterface $stream, private readonly int $limit)
    {
        $this->stream = $stream;
    }

    public function write($string): int
    {
        $length = strlen($string);

        if ($this->written + $length > $this->limit) {
            $this->overflowed = true;

            return 0;
        }

        $this->written += $length;

        return $this->stream->write($string);
    }

    /** True once a write was refused because it would pass the limit. */
    public function overflowed(): bool
    {
        return $this->overflowed;
    }
}
