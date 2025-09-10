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
    public function __construct(ErrorParser $errorParser)
    {
        parent::__construct($errorParser);
    }

    /**
     * Parse various error data formats into standardized JSON API ErrorCollection.
     * 
     * Handles:
     * - String errors: Wraps in proper JSON API error format
     * - Throwable instances: Extracts message, code, file, line with context
     * - Objects with malformed 'errors' key: Normalizes non-array errors
     * - Generic objects: Converts to proper JSON API structure
     *
     * @param  mixed  $data
     */
    public function parse($data): ErrorCollection
    {
        // Handle null input: create default error
        if ($data === null) {
            $data = [
                'errors' => [(object) ['detail' => 'No error data provided']]
            ];
        }
        
        // Handle string input: wrap in JSON API error format
        if (is_string($data)) {
            $data = [
                'errors' => [
                    (object) [
                        'detail' => $data,
                    ],
                ],
            ];
        }
        
        // Handle other scalar types (int, float, bool): convert to string and wrap
        if (is_scalar($data) && !is_string($data)) {
            $detail = (string) $data;
            
            // Handle boolean false specifically (converts to empty string)
            if (is_bool($data) && !$data) {
                $detail = 'false';
            }
            
            $data = [
                'errors' => [
                    (object) [
                        'detail' => $detail,
                    ],
                ],
            ];
        }
        
        // Handle Throwable instances: extract meaningful context before generic object processing
        if ($data instanceof Throwable) {
            $data = [
                'errors' => [
                    (object) [
                        'title' => get_class($data),
                        'detail' => $data->getMessage(),
                        'code' => (string) $data->getCode(),
                        'meta' => (object) [
                            'file' => $data->getFile(),
                            'line' => $data->getLine(),
                            'trace' => $data->getTraceAsString(),
                        ],
                    ],
                ],
            ];
        }

        // Handle object input: convert and normalize
        if (is_object($data)) {
            // Convert object to proper JSON API error format
            $errorData = json_decode(json_encode($data), true);

            // Guard against encode/decode failure
            if (!is_array($errorData) || json_last_error() !== JSON_ERROR_NONE) {
                $errorData = [
                    'errors' => [
                        (object) [
                            'detail' => 'Failed to parse error data: ' . json_last_error_msg(),
                        ],
                    ],
                ];
            } else {
                // Case 1: 'errors' is missing or null
                if (!isset($errorData['errors']) || $errorData['errors'] === null) {
                    $errorData['errors'] = [(object) ['detail' => 'Error data was null']];
                }
                // Case 2: 'errors' is a non-array (scalar or object)
                elseif (!is_array($errorData['errors'])) {
                    if (is_scalar($errorData['errors'])) {
                        // Convert scalar to proper error object
                        $errorData['errors'] = [(object) ['detail' => (string) $errorData['errors']]];
                    } else {
                        // Wrap object in array
                        $errorData['errors'] = [$errorData['errors']];
                    }
                }
                // Case 3: 'errors' is an array but needs normalization
                elseif (is_array($errorData['errors'])) {
                    // Check if it's an associative array (no numeric 0 key)
                    if (!array_key_exists(0, $errorData['errors'])) {
                        // Handle Laravel validation format: {"field": ["error1", "error2"]}
                        $normalizedErrors = [];
                        foreach ($errorData['errors'] as $field => $fieldErrors) {
                            if (is_array($fieldErrors)) {
                                // Each field error becomes a separate error object
                                foreach ($fieldErrors as $fieldError) {
                                    $normalizedErrors[] = (object) [
                                        'detail' => (string) $fieldError,
                                        'source' => (object) ['pointer' => "/data/attributes/{$field}"]
                                    ];
                                }
                            } elseif (is_scalar($fieldErrors)) {
                                $normalizedErrors[] = (object) [
                                    'detail' => (string) $fieldErrors,
                                    'source' => (object) ['pointer' => "/data/attributes/{$field}"]
                                ];
                            } else {
                                // Fallback: convert object/other to error
                                $normalizedErrors[] = (object) ['detail' => 'Field validation error'];
                            }
                        }
                        
                        // If no errors were extracted, use the whole object as fallback
                        if (empty($normalizedErrors)) {
                            $normalizedErrors[] = (object) $errorData['errors'];
                        }
                        
                        $errorData['errors'] = $normalizedErrors;
                    } else {
                        // Handle array-of-scalars: map each scalar to error object
                        $normalizedErrors = [];
                        foreach ($errorData['errors'] as $error) {
                            if ($error === null) {
                                // Handle null entries
                                $normalizedErrors[] = (object) ['detail' => 'Null error entry'];
                            } elseif (is_scalar($error)) {
                                $normalizedErrors[] = (object) ['detail' => (string) $error];
                            } else {
                                $normalizedErrors[] = $error;
                            }
                        }
                        $errorData['errors'] = $normalizedErrors;
                    }
                }

                // Final validation: ensure errors is a numerically indexed array of objects
                if (!isset($errorData['errors']) || !is_array($errorData['errors']) || empty($errorData['errors'])) {
                    // Wrap whole $errorData as fallback
                    $errorData = [
                        'errors' => [(object) $errorData],
                    ];
                }
                
                // Convert all error entries to objects for Swis compatibility
                $errorData['errors'] = array_map(function ($error) {
                    return is_array($error) ? (object) $error : $error;
                }, $errorData['errors']);
            }

            $data = $errorData;
        }

        // Handle array input with 'errors' key (but not processed as object above)
        if (is_array($data) && isset($data['errors'])) {
            // Apply the same normalization logic for arrays
            // Case 1: 'errors' is missing or null
            if (!isset($data['errors']) || $data['errors'] === null) {
                $data['errors'] = [(object) ['detail' => 'Error data was null']];
            }
            // Case 2: 'errors' is a non-array (scalar or object)
            elseif (!is_array($data['errors'])) {
                if (is_scalar($data['errors'])) {
                    $data['errors'] = [(object) ['detail' => (string) $data['errors']]];
                } else {
                    $data['errors'] = [$data['errors']];
                }
            }
            // Case 3: 'errors' is an array but needs normalization
            elseif (is_array($data['errors'])) {
                // Check if it's an associative array (no numeric 0 key)
                if (!array_key_exists(0, $data['errors'])) {
                    // Handle Laravel validation format: {"field": ["error1", "error2"]}
                    $normalizedErrors = [];
                    foreach ($data['errors'] as $field => $fieldErrors) {
                        if (is_array($fieldErrors)) {
                            // Each field error becomes a separate error object
                            foreach ($fieldErrors as $fieldError) {
                                $normalizedErrors[] = (object) [
                                    'detail' => (string) $fieldError,
                                    'source' => (object) ['pointer' => "/data/attributes/{$field}"]
                                ];
                            }
                        } elseif (is_scalar($fieldErrors)) {
                            $normalizedErrors[] = (object) [
                                'detail' => (string) $fieldErrors,
                                'source' => (object) ['pointer' => "/data/attributes/{$field}"]
                            ];
                        } else {
                            // Fallback: convert object/other to error
                            $normalizedErrors[] = (object) ['detail' => 'Field validation error'];
                        }
                    }
                    
                    // If no errors were extracted, use the whole object as fallback
                    if (empty($normalizedErrors)) {
                        $normalizedErrors[] = (object) $data['errors'];
                    }
                    
                    $data['errors'] = $normalizedErrors;
                } else {
                    // Handle array-of-scalars: map each scalar to error object
                    $normalizedErrors = [];
                    foreach ($data['errors'] as $error) {
                        if ($error === null) {
                            // Skip null entries or convert to placeholder
                            $normalizedErrors[] = (object) ['detail' => 'Null error entry'];
                        } elseif (is_scalar($error)) {
                            $normalizedErrors[] = (object) ['detail' => (string) $error];
                        } else {
                            $normalizedErrors[] = $error;
                        }
                    }
                    $data['errors'] = $normalizedErrors;
                }
            }
        }
        
        // Handle array input WITHOUT 'errors' key - wrap entire object as an error
        if (is_array($data) && !isset($data['errors'])) {
            // Check for common error message fields
            if (isset($data['message'])) {
                $data = [
                    'errors' => [(object) ['detail' => $data['message']]]
                ];
            } elseif (isset($data['error'])) {
                if (is_string($data['error'])) {
                    $data = [
                        'errors' => [(object) ['detail' => $data['error']]]
                    ];
                } else {
                    // Wrap error object, ensuring proper type conversions
                    $errorObj = $this->convertArrayToObject($data['error']);
                    $data = [
                        'errors' => [$errorObj]
                    ];
                }
            } else {
                // Convert entire object to a single error with detail field
                $errorMessage = 'Error data was null';
                if (!empty($data)) {
                    // Try to extract meaningful error message from various fields
                    $possibleFields = ['detail', 'title', 'description', 'text', 'msg'];
                    foreach ($possibleFields as $field) {
                        if (isset($data[$field]) && is_string($data[$field])) {
                            $errorMessage = $data[$field];
                            break;
                        }
                    }
                    
                    // If no meaningful field found, stringify first scalar value
                    if ($errorMessage === 'Error data was null') {
                        foreach ($data as $key => $value) {
                            if (is_scalar($value)) {
                                $errorMessage = (string) $value;
                                break;
                            }
                        }
                    }
                }
                
                $data = [
                    'errors' => [(object) ['detail' => $errorMessage]]
                ];
            }
        }

        // Ensure all error entries are objects for Swis compatibility (final cleanup)
        if (is_array($data) && isset($data['errors']) && is_array($data['errors'])) {
            $data['errors'] = array_map(function ($error) {
                if (is_array($error)) {
                    // Recursively convert nested arrays to objects
                    return $this->convertArrayToObject($error);
                }
                return $error;
            }, $data['errors']);
        }

        // Extract the errors array for the parent parser
        if (is_array($data) && isset($data['errors'])) {
            return parent::parse($data['errors']);
        }

        return parent::parse($data);
    }

    /**
     * Recursively convert arrays to objects for Swis compatibility
     */
    private function convertArrayToObject($data)
    {
        if (is_array($data)) {
            $object = new \stdClass();
            foreach ($data as $key => $value) {
                // Map common non-JSON:API fields to JSON:API equivalents
                if ($key === 'message') {
                    $object->detail = $this->convertArrayToObject($value);
                } elseif (in_array($key, ['code', 'status']) && is_numeric($value)) {
                    // Convert certain fields to strings as required by JSON:API spec
                    $object->$key = (string) $value;
                } else {
                    $object->$key = $this->convertArrayToObject($value);
                }
            }
            return $object;
        }
        return $data;
    }
}