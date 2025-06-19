<?php

namespace Nuimarkets\LaravelSharedUtils\RemoteRepositories;

use Nuimarkets\LaravelSharedUtils\Support\SimpleDocument;
use Nuimarkets\LaravelSharedUtils\Support\ProfilingTrait;
use Nuimarkets\LaravelSharedUtils\Exceptions\RemoteServiceException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Swis\JsonApi\Client\Interfaces\DocumentClientInterface;
use Swis\JsonApi\Client\Interfaces\DocumentInterface;
use Swis\JsonApi\Client\Interfaces\ItemDocumentInterface;
use Swis\JsonApi\Client\Item;
use Swis\JsonApi\Client\Exceptions\ValidationException;

abstract class RemoteRepository
{
    use ProfilingTrait;
    /**
     * @var Collection
     */
    protected Collection $data;

    /**
     * @var DocumentClientInterface|null
     */
    private ?DocumentClientInterface $client = null;

    /**
     * @var array - Headers to be send with each request
     */
    private array $headers;

    /**
     * @var int - Number of retry attempts
     */
    protected int $retry = 1;

    /**
     * RemoteRepository constructor.
     */
    public function __construct(DocumentClientInterface $client, $machineTokenService)
    {
        // Bypass for testing to prevent file descriptor issues
        if (!app()->runningUnitTests()) {
            $this->client = $client;
            $this->client->setBaseUri($this->getConfiguredBaseUri());
        }
        
        $this->headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $machineTokenService->getToken()
        ];
        $this->data = new Collection();
    }

    /**
     * Get base URI from various config sources
     */
    protected function getConfiguredBaseUri(): string
    {
        return config('jsonapi.base_uri') 
            ?? config('pxc.base_api_uri')
            ?? config('remote.base_uri')
            ?? throw new \RuntimeException('No remote service base URI configured');
    }

    /**
     * Get base URL length for validation
     */
    protected function getBaseUrlLength(): int
    {
        return $this->client ? strlen($this->client->getBaseUri()) : 0;
    }

    /**
     * Check if GET request URL length is allowed
     */
    public function allowedGetRequest(string $urlPath): bool
    {
        $maxLength = config('pxc.max_url_length', 2048);
        return strlen($urlPath) < ($maxLength - $this->getBaseUrlLength());
    }

    final public function get($url): DocumentInterface
    {
        if (!$this->client) {
            throw new \RuntimeException('Client not initialized - tests should mock this repository');
        }

        $startTime = $this->profileStart(__METHOD__);
        $retry = $this->retry;
        
        while (true) {
            try {
                if (config('pxc.api_log_requests')) {
                    Log::debug("API GET", ['url' => $url]);
                }
                
                $res = $this->client->get($url, $this->headers);
                
                if ($res->hasErrors()) {
                    // Check for specialized error patterns before general handling
                    foreach ($res->getErrors() as $error) {
                        $msg = $error->getDetail();
                        if ($msg && str_contains($msg, 'Duplicate active delivery address codes found')) {
                            \Sentry\captureMessage($msg);
                            $this->profileEnd(__METHOD__, $startTime);
                            return (object) ['error' => $msg];
                        }
                    }
                    $this->handleApiErrors($res, $url);
                }
                
                $this->profileEnd(__METHOD__, $startTime);
                return $res;
                
            } catch (ValidationException $e) {
                $this->handleValidationException($e, $url);
                
            } catch (\Exception $exception) {
                --$retry;
                if ($retry > 0) {
                    sleep(1);
                } else {
                    \Sentry\captureException($exception);
                    $this->profileEnd(__METHOD__, $startTime);
                    throw new RemoteServiceException('Error getting response from remote server: ' . $exception->getMessage(), 500, $exception);
                }
            }
        }
    }

    final public function getUserUrl($url): DocumentInterface
    {
        if (!$this->client) {
            throw new \RuntimeException('Client not initialized - tests should mock this repository');
        }

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
                --$retry;
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
        if (!$this->client) {
            throw new \RuntimeException('Client not initialized - tests should mock this repository');
        }

        $startTime = $this->profileStart(__METHOD__);
        $retry = $this->retry;
        
        while (true) {
            try {
                if (config('pxc.api_log_requests')) {
                    Log::info('Request Debug', [
                        'url' => $url,
                        'body' => $this->client->encode($data),
                        'headers' => $this->headers
                    ]);
                    Log::debug('API POST', ['url' => $url]);
                }
                
                $res = $this->client->post($url, $data, $this->headers);
                
                if ($res->hasErrors()) {
                    Log::error("Error calling service", [
                        "url" => $url,
                        'errors' => array_map(
                            static fn ($e) => $e->getDetail() ?? '', $res->getErrors()->toArray()
                        ),
                    ]);
                    foreach ($res->getErrors() as $error) {
                        \Sentry\captureMessage($error->getDetail() ?? "");
                    }
                    throw new RemoteServiceException('Error calling service. Returned: ');
                }
                
                $this->profileEnd(__METHOD__, $startTime);
                return $res;
                
            } catch (ValidationException $e) {
                $this->handleValidationException($e, $url);
                
            } catch (\Exception $exception) {
                --$retry;
                if ($retry > 0) {
                    sleep(1);
                } else {
                    \Sentry\captureException($exception);
                    $this->profileEnd(__METHOD__, $startTime);
                    throw new RemoteServiceException('Error getting response from remote server', 500);
                }
            }
        }
    }

    /**
     * Handle ValidationException from malformed API responses
     */
    private function handleValidationException(ValidationException $e, string $url): void
    {
        // Try to extract response body for analysis
        $response = $e->getResponse() ?? null;
        $responseBody = $response ? $response->getBody()->getContents() : 'No response body available';
        
        Log::error('Remote API returned non-JSON API compliant response', [
            'url' => $url,
            'response_body' => $responseBody,
            'exception' => $e->getMessage()
        ]);
        
        // Try to extract meaningful error message
        $decoded = json_decode($responseBody, true);
        $errorMessage = $this->extractErrorMessage($decoded);
        
        \Sentry\captureException($e);
        throw new RemoteServiceException($errorMessage, 0, $e);
    }

    /**
     * Extract meaningful error message from various response formats
     */
    private function extractErrorMessage($decoded): string
    {
        if (!is_array($decoded)) {
            return 'Remote service returned invalid response format';
        }
        
        return $decoded['errorMessage'] 
            ?? $decoded['message'] 
            ?? $decoded['error'] 
            ?? 'Remote service error - see logs for details';
    }

    /**
     * Handle API errors from valid JSON API responses
     */
    private function handleApiErrors(DocumentInterface $response, string $url): void
    {
        Log::error('Error calling service.' . PHP_EOL . 'Body: ' . $response->getResponse()->getBody());
        foreach ($response->getErrors() as $error) {
            Log::error($error->getDetail());
        }
        throw new RemoteServiceException('Error calling service. Returned: ' . $response->getResponse()->getBody());
    }

    public function makeRequestBody($data): SimpleDocument
    {
        Item::unguard();
        $item = new Item($data);
        Item::reguard();
        $item->setType('array');
        $body = new SimpleDocument();
        $body->setData($item);
        return $body;
    }

    public function hasId($id): bool
    {
        return isset($this->data[$id]);
    }

    /**
     * @throws \Exception
     */
    public function handleResponse(DocumentInterface $response)
    {
        if ($response->hasErrors()) {
            Log::error('Error calling service.' . PHP_EOL . 'Body: ' . $response->getResponse()->getBody());
            foreach ($response->getErrors() as $error) {
                Log::error($error->getDetail());
            }
            throw new \Exception('Error calling service. Returned: ' . $response->getResponse()->getBody());
        }
        return $response->getData();
    }

    /**
     * Cache data
     */
    public function cache($response)
    {
        $data = $response->getData();
        if (!$data) {
            return;
        }

        foreach ($data as $item) {
            $this->data->put($item->getId(), $item);
        }
    }

    /**
     * Put the single object into the data, the response only has one object.
     *
     * @param mixed $response
     */
    public function cacheOne($response)
    {
        $data = $response->getData();
        if (!$data) {
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
                if (!$id) {
                    unset($ids[$i]);
                    continue;
                }
                if (!$query->has($id)) {
                    $result[] = $id;
                }
            }

            if (sizeof($result) > 0) {
                $this->filter($result);
            }

            return $query->only($ids);
        } catch (\Exception $e) {
            return;
        }
    }

    /**
     * @return Collection
     */
    public function query(): Collection
    {
        return $this->data;
    }

    /**
     * Abstract method that child classes must implement
     * This is where the ValidationException typically occurs
     */
    abstract protected function filter(array $data);
}