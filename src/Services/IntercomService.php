<?php

declare(strict_types=1);

namespace NuiMarkets\LaravelSharedUtils\Services;

use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IntercomService
{
    private string $baseUrl;

    private string $token;

    private string $apiVersion;

    private bool $enabled;

    private string $serviceName;

    private array $config;

    public function __construct()
    {
        $this->config = config('intercom', []);
        $this->baseUrl = $this->config['base_url'] ?? 'https://api.intercom.io';
        $this->token = $this->config['token'] ?? '';
        $this->apiVersion = $this->config['api_version'] ?? '2.13';
        $this->enabled = $this->config['enabled'] ?? false;
        $this->serviceName = $this->config['service_name'] ?? config('app.name', 'connect-service');

        if (empty($this->token) && $this->enabled) {
            Log::warning('Intercom token not configured but service is enabled', [
                'feature' => 'intercom',
                'service' => $this->serviceName,
            ]);
        }
    }

    /**
     * Track an event for a user
     */
    public function trackEvent(string $userId, string $event, array $properties = []): bool
    {
        // Guard clause: Check for empty userId or event
        if (empty(trim($userId)) || empty(trim($event))) {
            Log::warning('Intercom trackEvent called with empty userId or event', [
                'feature' => 'intercom',
                'user_id' => $userId,
                'event' => $event,
                'service' => $this->serviceName,
            ]);

            return false;
        }

        Log::info('Intercom trackEvent called', [
            'feature' => 'intercom',
            'user_id' => $userId,
            'event' => $event,
            'enabled' => $this->isEnabled(),
            'service' => $this->serviceName,
            'token_set' => ! empty($this->token),
        ]);

        if (! $this->isEnabled()) {
            Log::warning('Intercom track event not enabled', [
                'feature' => 'intercom',
                'enabled_config' => $this->enabled,
                'token_empty' => empty($this->token),
                'config' => $this->config,
            ]);

            return false;
        }

        try {
            $eventData = [
                'event_name' => $this->formatEventName($event),
                'created_at' => time(),
                'metadata' => array_merge($properties, [
                    'service' => $this->serviceName,
                    'version' => config('app.version', '1.0.0'),
                    'environment' => config('app.env', 'production'),
                ]),
            ];

            // Use email field if userId looks like an email, otherwise use external_id
            if (filter_var($userId, FILTER_VALIDATE_EMAIL)) {
                $eventData['email'] = $userId;
            } else {
                $eventData['external_id'] = $userId;
            }

            // Wrap event data in 'data' field as required by Intercom API
            $response = $this->makeApiRequest('POST', '/events', ['data' => $eventData]);

            if ($response->successful()) {
                $this->logSuccess('Intercom event tracked', [
                    'user_id' => $userId,
                    'event' => $event,
                    'response_status' => $response->status(),
                ]);

                return true;
            } else {
                $this->logError('Failed to track Intercom event', [
                    'user_id' => $userId,
                    'event' => $event,
                    'status' => $response->status(),
                    'error' => $response->body(),
                ]);

                return false;
            }
        } catch (Exception $e) {
            $this->logError('Intercom event exception', [
                'user_id' => $userId,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Create or update a user in Intercom
     */
    public function createOrUpdateUser(string $userId, array $attributes = []): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        try {
            $userData = [
                'external_id' => $userId,
                'custom_attributes' => array_merge($attributes, [
                    'last_request_at' => time(),
                    'service_last_active' => $this->serviceName,
                ]),
            ];

            // Add basic attributes if provided
            foreach (['email', 'name', 'phone'] as $field) {
                if (isset($attributes[$field])) {
                    $userData[$field] = $attributes[$field];
                    unset($userData['custom_attributes'][$field]);
                }
            }

            $response = $this->makeApiRequest('POST', '/contacts', $userData);

            if ($response->successful()) {
                $contact = $response->json() ?? [];
                $this->logSuccess('Intercom user created/updated', [
                    'user_id' => $userId,
                    'intercom_id' => $contact['id'] ?? null,
                ]);

                return $contact;
            } else {
                $this->logError('Failed to create/update Intercom user', [
                    'user_id' => $userId,
                    'status' => $response->status(),
                    'error' => $response->body(),
                ]);

                return [];
            }
        } catch (Exception $e) {
            $this->logError('Intercom user exception', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Create or update a company in Intercom
     */
    public function createOrUpdateCompany(string $companyId, array $attributes = []): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        try {
            $companyData = [
                'company_id' => $companyId,
                'custom_attributes' => array_merge($attributes, [
                    'last_active_service' => $this->serviceName,
                    'updated_at' => time(),
                ]),
            ];

            // Add basic attributes if provided
            foreach (['name', 'plan', 'size'] as $field) {
                if (isset($attributes[$field])) {
                    $companyData[$field] = $attributes[$field];
                    unset($companyData['custom_attributes'][$field]);
                }
            }

            $response = $this->makeApiRequest('POST', '/companies', $companyData);

            if ($response->successful()) {
                $company = $response->json() ?? [];
                $this->logSuccess('Intercom company created/updated', [
                    'company_id' => $companyId,
                    'intercom_id' => $company['id'] ?? null,
                ]);

                return $company;
            } else {
                $this->logError('Failed to create/update Intercom company', [
                    'company_id' => $companyId,
                    'status' => $response->status(),
                    'error' => $response->body(),
                ]);

                return [];
            }
        } catch (Exception $e) {
            $this->logError('Intercom company exception', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Batch track multiple events using Intercom's bulk endpoint
     */
    public function batchTrackEvents(array $events): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $results = [];
        // Intercom supports up to 1000 events per bulk request
        $batchSize = min($this->config['batch_size'] ?? 50, 1000);

        foreach (array_chunk($events, $batchSize) as $batch) {
            $batchResult = $this->sendBatchToIntercom($batch);
            $results = array_merge($results, $batchResult);
        }

        return $results;
    }

    /**
     * Send a batch of events to Intercom's bulk endpoint
     */
    private function sendBatchToIntercom(array $events): array
    {
        try {
            $bulkData = [
                'events' => [],
            ];

            foreach ($events as $event) {
                $eventData = [
                    'event_name' => $this->formatEventName($event['event'] ?? ''),
                    'created_at' => time(),
                    'metadata' => array_merge($event['properties'] ?? [], [
                        'service' => $this->serviceName,
                        'version' => config('app.version', '1.0.0'),
                        'environment' => config('app.env', 'production'),
                    ]),
                ];

                $userId = $event['user_id'] ?? '';

                // Use email field if userId looks like an email, otherwise use external_id
                if (filter_var($userId, FILTER_VALIDATE_EMAIL)) {
                    $eventData['email'] = $userId;
                } else {
                    $eventData['external_id'] = $userId;
                }

                $bulkData['events'][] = $eventData;
            }

            // Wrap bulk data in 'data' field as required by Intercom API
            $response = $this->makeApiRequest('POST', '/events/bulk', ['data' => $bulkData]);

            if ($response->successful()) {
                $this->logSuccess('Intercom bulk events tracked', [
                    'event_count' => count($events),
                    'response_status' => $response->status(),
                ]);

                // Return success for all events in this batch
                return array_map(function ($event) {
                    return [
                        'event' => $event,
                        'success' => true,
                    ];
                }, $events);
            } else {
                $this->logError('Intercom bulk events failed', [
                    'event_count' => count($events),
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                ]);

                // Return failure for all events in this batch
                return array_map(function ($event) {
                    return [
                        'event' => $event,
                        'success' => false,
                    ];
                }, $events);
            }
        } catch (\Throwable $e) {
            $this->logError('Intercom bulk events exception', [
                'event_count' => count($events),
                'error' => $e->getMessage(),
            ]);

            // Return failure for all events in this batch
            return array_map(function ($event) {
                return [
                    'event' => $event,
                    'success' => false,
                ];
            }, $events);
        }
    }

    /**
     * Check if Intercom integration is enabled and configured
     */
    public function isEnabled(): bool
    {
        return $this->enabled && ! empty($this->token);
    }

    /**
     * Get service configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Get service name
     */
    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    /**
     * Make an API request to Intercom
     */
    private function makeApiRequest(string $method, string $endpoint, array $data = []): Response
    {
        $timeout = $this->config['timeout'] ?? 10;
        $url = $this->baseUrl.$endpoint;

        return Http::withHeaders($this->getHeaders())
            ->timeout($timeout)
            ->{strtolower($method)}($url, $data);
    }

    /**
     * Format event names with consistent naming
     */
    private function formatEventName(string $event): string
    {
        $prefix = $this->config['event_prefix'] ?? '';

        // Ensure consistent naming
        $event = strtolower($event);
        $event = str_replace(' ', '_', $event);

        // Add underscore separator if prefix doesn't end with one
        if ($prefix && ! str_ends_with($prefix, '_')) {
            $prefix .= '_';
        }

        return $prefix.$event;
    }

    /**
     * Get HTTP headers for Intercom API
     */
    private function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Intercom-Version' => $this->apiVersion,
        ];
    }

    /**
     * Log success messages
     */
    private function logSuccess(string $message, array $context = []): void
    {
        Log::info($message, array_merge($context, [
            'feature' => 'intercom',
            'service' => $this->serviceName,
        ]));
    }

    /**
     * Log error messages
     */
    private function logError(string $message, array $context = []): void
    {
        $failSilently = $this->config['fail_silently'] ?? true;

        $logContext = array_merge($context, [
            'feature' => 'intercom',
            'service' => $this->serviceName,
        ]);

        if ($failSilently) {
            Log::warning($message, $logContext);
        } else {
            Log::error($message, $logContext);
        }
    }
}
