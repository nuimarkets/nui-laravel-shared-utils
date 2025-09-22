<?php

namespace NuiMarkets\LaravelSharedUtils\Support;

use Swis\JsonApi\Client\ErrorCollection;
use Swis\JsonApi\Client\Parsers\ErrorParser;
use Throwable;

/**
 * Enhanced ErrorCollectionParser for consistent outbound API error handling.
 *
 * This class provides standardized error normalization for JSON API responses across all
 * Connect platform services. It extends the base Swis JSON API client ErrorCollectionParser
 * to handle various error formats that may be encountered in real-world applications.
 *
 * Key Features:
 * - String errors: Automatically wraps string errors in JSON API format
 * - Throwable handling: Extracts meaningful context from exceptions
 * - Malformed errors: Normalizes non-array 'errors' keys
 * - Object conversion: Handles generic error objects
 * - Backward compatible: Maintains all base functionality
 *
 * Usage:
 * This parser should be registered with the Swis JSON API client in service providers
 * to ensure consistent error handling across all outbound API calls.
 *
 * @see https://linear.app/nuimarkets/issue/CON-1307
 */
class ErrorCollectionParser extends \Swis\JsonApi\Client\Parsers\ErrorCollectionParser
{
    private ErrorDataNormalizer $normalizer;

    public function __construct(ErrorParser $errorParser, ?ErrorDataNormalizer $normalizer = null)
    {
        parent::__construct($errorParser);
        $this->normalizer = $normalizer ?? new ErrorDataNormalizer();
    }

    /**
     * Parse various error data formats into standardized JSON API ErrorCollection.
     *
     * Uses the ErrorDataNormalizer to handle complex normalization logic.
     *
     * @param  mixed  $data
     */
    public function parse($data): ErrorCollection
    {
        // Use the normalizer to handle all complex logic
        $normalizedData = $this->normalizer->normalize($data);

        // Convert arrays to objects for Swis compatibility while maintaining JSON:API structure
        if (is_array($normalizedData) && isset($normalizedData['errors'])) {
            $errors = array_map([$this, 'convertArrayToObject'], $normalizedData['errors']);
            return parent::parse($errors);
        }

        return parent::parse($normalizedData);
    }

    /**
     * Convert arrays to objects for Swis compatibility
     * (The normalizer uses arrays for JSON:API compliance, but Swis expects objects)
     */
    private function convertArrayToObject($data)
    {
        if (is_array($data)) {
            $object = new \stdClass();
            foreach ($data as $key => $value) {
                $object->$key = $this->convertArrayToObject($value);
            }
            return $object;
        }
        return $data;
    }
}