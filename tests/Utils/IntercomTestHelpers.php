<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Utils;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use NuiMarkets\LaravelSharedUtils\Events\IntercomEvent;

/**
 * Shared test helpers for Intercom integration tests.
 *
 * Provides factory methods for setting up Intercom configuration and creating
 * test events to reduce duplication across Intercom-related test files.
 */
trait IntercomTestHelpers
{
    /**
     * Set up Intercom configuration for testing.
     *
     * @param  array  $overrides  Configuration values to override defaults
     */
    protected function setUpIntercomConfig(array $overrides = []): void
    {
        $defaults = [
            'token' => 'test-token',
            'api_version' => '2.11',
            'base_url' => 'https://api.intercom.io',
            'enabled' => true,
            'service_name' => 'connect-service-test',
            'timeout' => 10,
            'fail_silently' => true,
            'batch_size' => 50,
            'event_prefix' => 'connect',
        ];

        Config::set('intercom', array_merge($defaults, $overrides));
    }

    /**
     * Set up Intercom configuration with detailed logging enabled.
     *
     * @param  array  $overrides  Configuration values to override defaults
     */
    protected function setUpIntercomConfigWithDetailedLogging(array $overrides = []): void
    {
        $this->setUpIntercomConfig(array_merge([
            'features' => ['detailed_logging' => true],
        ], $overrides));
    }

    /**
     * Set up Intercom configuration in disabled state.
     */
    protected function setUpIntercomConfigDisabled(): void
    {
        $this->setUpIntercomConfig(['enabled' => false]);
    }

    /**
     * Set up Intercom configuration without a token (disabled).
     */
    protected function setUpIntercomConfigWithoutToken(): void
    {
        $this->setUpIntercomConfig(['token' => '']);
    }

    /**
     * Create an IntercomEvent for testing.
     *
     * @param  string  $userId  User ID
     * @param  string  $event  Event name
     * @param  array  $properties  Event properties
     * @param  string|null  $tenantId  Tenant ID
     */
    protected function createIntercomEvent(
        string $userId = 'user-123',
        string $event = 'product_viewed',
        array $properties = [],
        ?string $tenantId = null
    ): IntercomEvent {
        return new IntercomEvent($userId, $event, $properties, $tenantId);
    }

    /**
     * Create an IntercomEvent with common product view properties.
     *
     * @param  string  $userId  User ID
     * @param  string  $productId  Product ID
     * @param  array  $additionalProperties  Additional properties to merge
     * @param  string|null  $tenantId  Tenant ID
     */
    protected function createProductViewEvent(
        string $userId = 'user-123',
        string $productId = 'prod-456',
        array $additionalProperties = [],
        ?string $tenantId = null
    ): IntercomEvent {
        $properties = array_merge([
            'product_id' => $productId,
        ], $additionalProperties);

        return $this->createIntercomEvent($userId, 'product_viewed', $properties, $tenantId);
    }

    /**
     * Fake Intercom API with a successful response.
     *
     * @param  array  $responseData  Response data to return
     * @param  int  $statusCode  HTTP status code
     */
    protected function fakeIntercomApiSuccess(array $responseData = [], int $statusCode = 200): void
    {
        $defaultResponse = [
            'type' => 'event',
            'id' => 'event-123',
        ];

        Http::fake([
            'https://api.intercom.io/*' => Http::response(
                array_merge($defaultResponse, $responseData),
                $statusCode
            ),
        ]);
    }

    /**
     * Fake Intercom API with an error response.
     *
     * @param  int  $statusCode  HTTP status code
     * @param  array  $errors  Error data to return
     */
    protected function fakeIntercomApiError(int $statusCode = 400, array $errors = []): void
    {
        $defaultErrors = [
            'errors' => [['message' => 'Invalid request']],
        ];

        Http::fake([
            'https://api.intercom.io/*' => Http::response(
                empty($errors) ? $defaultErrors : ['errors' => $errors],
                $statusCode
            ),
        ]);
    }

    /**
     * Fake Intercom API with different responses for events, contacts, and companies.
     *
     * @param  array|null  $eventResponse  Response for /events endpoint
     * @param  array|null  $contactResponse  Response for /contacts endpoint
     * @param  array|null  $companyResponse  Response for /companies endpoint
     */
    protected function fakeIntercomApiMultipleEndpoints(
        ?array $eventResponse = null,
        ?array $contactResponse = null,
        ?array $companyResponse = null
    ): void {
        $fakes = [];

        if ($eventResponse !== null) {
            $fakes['https://api.intercom.io/events'] = Http::response($eventResponse, 200);
        }

        if ($contactResponse !== null) {
            $fakes['https://api.intercom.io/contacts'] = Http::response($contactResponse, 200);
        }

        if ($companyResponse !== null) {
            $fakes['https://api.intercom.io/companies'] = Http::response($companyResponse, 200);
        }

        // Default success for any other endpoints
        $fakes['https://api.intercom.io/*'] = Http::response(['status' => 'ok'], 200);

        Http::fake($fakes);
    }

    /**
     * Fake Intercom API to throw an exception.
     *
     * @param  \Exception  $exception  The exception to throw
     */
    protected function fakeIntercomApiException(\Exception $exception): void
    {
        Http::fake(function () use ($exception) {
            throw $exception;
        });
    }

    /**
     * Create batch events data for testing.
     *
     * @param  int  $count  Number of events to create
     * @return array Array of event data
     */
    protected function createBatchEventsData(int $count = 2): array
    {
        $events = [];
        for ($i = 1; $i <= $count; $i++) {
            $events[] = [
                'user_id' => "user-{$i}",
                'event' => 'product_viewed',
                'properties' => ['product_id' => "prod-{$i}"],
            ];
        }

        return $events;
    }
}
