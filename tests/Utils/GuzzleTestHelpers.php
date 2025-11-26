<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Utils;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Shared test helpers for creating Guzzle HTTP objects.
 *
 * Provides factory methods for creating Guzzle Request, Response, and Exception
 * objects to reduce duplication in tests involving HTTP operations.
 */
trait GuzzleTestHelpers
{
    /**
     * Create a Guzzle Request object.
     *
     * @param  string  $method  HTTP method (GET, POST, etc.)
     * @param  string  $uri  Request URI
     * @param  array  $headers  Request headers
     * @param  string|null  $body  Request body
     */
    protected function createGuzzleRequest(
        string $method = 'GET',
        string $uri = 'http://example.com',
        array $headers = [],
        ?string $body = null
    ): Request {
        return new Request($method, $uri, $headers, $body);
    }

    /**
     * Create a Guzzle Response object.
     *
     * @param  int  $statusCode  HTTP status code
     * @param  array  $headers  Response headers
     * @param  string  $body  Response body
     * @param  string  $version  HTTP protocol version
     * @param  string|null  $reason  HTTP reason phrase
     */
    protected function createGuzzleResponse(
        int $statusCode = 200,
        array $headers = [],
        string $body = '',
        string $version = '1.1',
        ?string $reason = null
    ): Response {
        return new Response($statusCode, $headers, $body, $version, $reason);
    }

    /**
     * Create a Guzzle RequestException with a response.
     *
     * @param  int  $statusCode  HTTP status code for the response
     * @param  string  $message  Exception message
     * @param  string  $responseBody  Response body
     * @param  string  $method  HTTP method
     * @param  string  $uri  Request URI
     */
    protected function createGuzzleRequestException(
        int $statusCode,
        string $message = 'Request failed',
        string $responseBody = '',
        string $method = 'GET',
        string $uri = 'http://example.com'
    ): RequestException {
        $request = $this->createGuzzleRequest($method, $uri);
        $response = $this->createGuzzleResponse($statusCode, [], $responseBody);

        return new RequestException($message, $request, $response);
    }

    /**
     * Create a Guzzle RequestException without a response (network error).
     *
     * @param  string  $message  Exception message
     * @param  string  $method  HTTP method
     * @param  string  $uri  Request URI
     */
    protected function createGuzzleRequestExceptionWithoutResponse(
        string $message = 'Network error',
        string $method = 'GET',
        string $uri = 'http://example.com'
    ): RequestException {
        $request = $this->createGuzzleRequest($method, $uri);

        return new RequestException($message, $request, null);
    }

    /**
     * Create a Guzzle ConnectException (connection failed).
     *
     * @param  string  $message  Exception message
     * @param  string  $method  HTTP method
     * @param  string  $uri  Request URI
     */
    protected function createGuzzleConnectException(
        string $message = 'Connection refused',
        string $method = 'GET',
        string $uri = 'http://example.com'
    ): ConnectException {
        $request = $this->createGuzzleRequest($method, $uri);

        return new ConnectException($message, $request);
    }

    /**
     * Create a Guzzle ConnectException simulating a timeout.
     *
     * @param  string  $method  HTTP method
     * @param  string  $uri  Request URI
     */
    protected function createGuzzleTimeoutException(
        string $method = 'GET',
        string $uri = 'http://example.com'
    ): ConnectException {
        return $this->createGuzzleConnectException(
            'cURL error 28: Operation timed out',
            $method,
            $uri
        );
    }

    /**
     * Create a 404 Not Found exception.
     *
     * @param  string  $responseBody  Response body
     * @param  string  $uri  Request URI
     */
    protected function createGuzzle404Exception(
        string $responseBody = 'Not found',
        string $uri = 'http://example.com'
    ): RequestException {
        return $this->createGuzzleRequestException(404, 'Not found', $responseBody, 'GET', $uri);
    }

    /**
     * Create a 500 Internal Server Error exception.
     *
     * @param  string  $responseBody  Response body
     * @param  string  $uri  Request URI
     */
    protected function createGuzzle500Exception(
        string $responseBody = 'Internal server error',
        string $uri = 'http://example.com'
    ): RequestException {
        return $this->createGuzzleRequestException(500, 'Server error', $responseBody, 'GET', $uri);
    }

    /**
     * Create a 401 Unauthorized exception.
     *
     * @param  string  $responseBody  Response body
     * @param  string  $uri  Request URI
     */
    protected function createGuzzle401Exception(
        string $responseBody = 'Unauthorized',
        string $uri = 'http://example.com'
    ): RequestException {
        return $this->createGuzzleRequestException(401, 'Unauthorized', $responseBody, 'GET', $uri);
    }

    /**
     * Create a 403 Forbidden exception.
     *
     * @param  string  $responseBody  Response body
     * @param  string  $uri  Request URI
     */
    protected function createGuzzle403Exception(
        string $responseBody = 'Forbidden',
        string $uri = 'http://example.com'
    ): RequestException {
        return $this->createGuzzleRequestException(403, 'Forbidden', $responseBody, 'GET', $uri);
    }

    /**
     * Create a 429 Too Many Requests exception.
     *
     * @param  string  $responseBody  Response body
     * @param  string  $uri  Request URI
     */
    protected function createGuzzle429Exception(
        string $responseBody = 'Too many requests',
        string $uri = 'http://example.com'
    ): RequestException {
        return $this->createGuzzleRequestException(429, 'Rate limited', $responseBody, 'GET', $uri);
    }

    /**
     * Create a 503 Service Unavailable exception.
     *
     * @param  string  $responseBody  Response body
     * @param  string  $uri  Request URI
     */
    protected function createGuzzle503Exception(
        string $responseBody = 'Service unavailable',
        string $uri = 'http://example.com'
    ): RequestException {
        return $this->createGuzzleRequestException(503, 'Service unavailable', $responseBody, 'GET', $uri);
    }
}
