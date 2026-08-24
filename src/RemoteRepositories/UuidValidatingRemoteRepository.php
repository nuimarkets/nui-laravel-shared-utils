<?php

namespace NuiMarkets\LaravelSharedUtils\RemoteRepositories;

use Illuminate\Support\Facades\Log;

/**
 * Remote repository that validates UUIDs before making API calls.
 * Use this for repositories that expect UUID-format IDs (users, orders, organizations, etc.)
 * Use base RemoteRepository for repositories with non-UUID IDs (ports, containers, etc.)
 */
abstract class UuidValidatingRemoteRepository extends RemoteRepository
{
    /**
     * Override findByIds to validate UUIDs before making API calls
     *
     * @return \Illuminate\Support\Collection
     *
     * @throws \NuiMarkets\LaravelSharedUtils\Exceptions\RemoteServiceException
     */
    public function findByIds(array $ids = [])
    {
        // Filter invalid UUIDs to prevent RemoteServiceException
        $validIds = $this->filterValidUuids($ids);

        return parent::findByIds($validIds);
    }

    /**
     * Filter and validate UUIDs to prevent RemoteServiceException.
     * Logs any invalid UUIDs found for debugging purposes.
     *
     * @param  array  $uuids  Array of UUIDs to validate
     * @return array Array of valid UUIDs only
     */
    protected function filterValidUuids(array $uuids): array
    {
        $invalidUuids = [];
        $validUuids = array_filter($uuids, function ($uuid) use (&$invalidUuids) {
            $isValid = $uuid && is_string($uuid) &&
                       preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid);

            if (! $isValid) {
                $invalidUuids[] = $uuid;
            }

            return $isValid;
        });

        // Log invalid UUIDs for debugging
        if (! empty($invalidUuids)) {
            $logData = [
                'repository' => get_class($this),
                'invalid_count' => count($invalidUuids),
                'total_count' => count($uuids),
                'valid_count' => count($validUuids),
                'invalid_uuids' => $invalidUuids,
            ];

            Log::warning('Invalid UUIDs filtered from RemoteRepository query', $logData);
        }

        return $validUuids;
    }
}
