<?php

namespace NuiMarkets\LaravelSharedUtils\Support;

use Throwable;

/**
 * Normalizes various error data formats into JSON:API compliant error structures.
 *
 * This class handles the complex logic of converting different input types
 * (strings, exceptions, malformed objects, etc.) into standardized JSON:API
 * error arrays that can be processed by the Swis ErrorCollectionParser.
 */
class ErrorDataNormalizer
{
    /**
     * Normalize various error data formats into JSON:API compliant structure.
     *
     * @param mixed $data Input error data of any type
     * @return array Normalized data with 'errors' key containing array of error objects
     */
    public function normalize($data): array
    {
        // Handle null input: create default error
        if ($data === null) {
            return [
                'errors' => [['detail' => 'No error data provided']]
            ];
        }

        // Handle string input: wrap in JSON API error format
        if (is_string($data)) {
            return [
                'errors' => [
                    [
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

            return [
                'errors' => [
                    [
                        'detail' => $detail,
                    ],
                ],
            ];
        }

        // Handle Throwable instances: extract meaningful context before generic object processing
        if ($data instanceof Throwable) {
            return [
                'errors' => [
                    [
                        'title' => get_class($data),
                        'detail' => $data->getMessage(),
                        'code' => (string) $data->getCode(),
                        'meta' => [
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
            return $this->normalizeObject($data);
        }

        // Handle array input
        if (is_array($data)) {
            return $this->normalizeArray($data);
        }

        // Fallback for unknown types
        return [
            'errors' => [['detail' => 'Unknown error data type']]
        ];
    }

    /**
     * Normalize object input to JSON:API format
     */
    private function normalizeObject($data): array
    {
        // Convert object to proper JSON API error format
        $errorData = json_decode(json_encode($data), true);

        // Guard against encode/decode failure
        if (!is_array($errorData) || json_last_error() !== JSON_ERROR_NONE) {
            return [
                'errors' => [
                    [
                        'detail' => 'Failed to parse error data: ' . json_last_error_msg(),
                    ],
                ],
            ];
        }

        return $this->normalizeErrorsArray($errorData);
    }

    /**
     * Normalize array input to JSON:API format
     */
    private function normalizeArray(array $data): array
    {
        // Handle array input with 'errors' key
        if (array_key_exists('errors', $data)) {
            return $this->normalizeErrorsArray($data);
        }

        // Handle array input WITHOUT 'errors' key - wrap entire object as an error
        return $this->normalizeArrayWithoutErrorsKey($data);
    }

    /**
     * Normalize data that has an 'errors' key
     */
    private function normalizeErrorsArray(array $data): array
    {
        // Case 1: 'errors' is missing or null
        if (!array_key_exists('errors', $data) || $data['errors'] === null) {
            $data['errors'] = [['detail' => 'Error data was null']];
            return $data;
        }

        // Case 2: 'errors' is a non-array (scalar or object)
        if (!is_array($data['errors'])) {
            if (is_scalar($data['errors'])) {
                // Convert scalar to proper error object
                $data['errors'] = [['detail' => (string) $data['errors']]];
            } else {
                // Wrap object in array
                $data['errors'] = [$data['errors']];
            }
            return $data;
        }

        // Case 3: 'errors' is an array but needs normalization
        if (is_array($data['errors'])) {
            $data['errors'] = $this->normalizeErrorsArrayContent($data['errors']);
        }

        // Final validation: ensure errors is a numerically indexed array
        if (!isset($data['errors']) || !is_array($data['errors']) || empty($data['errors'])) {
            // Wrap whole data as fallback
            return [
                'errors' => [(array) $data],
            ];
        }

        return $data;
    }

    /**
     * Normalize the contents of an errors array
     */
    private function normalizeErrorsArrayContent(array $errors): array
    {
        // Check if it's an associative array (no numeric 0 key)
        if (!array_key_exists(0, $errors)) {
            // Handle Laravel validation format: {"field": ["error1", "error2"]}
            $normalizedErrors = [];
            foreach ($errors as $field => $fieldErrors) {
                if (is_array($fieldErrors)) {
                    // Each field error becomes a separate error object
                    foreach ($fieldErrors as $fieldError) {
                        $normalizedErrors[] = [
                            'detail' => (string) $fieldError,
                            'source' => ['pointer' => "/data/attributes/{$field}"]
                        ];
                    }
                } elseif (is_scalar($fieldErrors)) {
                    $normalizedErrors[] = [
                        'detail' => (string) $fieldErrors,
                        'source' => ['pointer' => "/data/attributes/{$field}"]
                    ];
                } else {
                    // Fallback: convert object/other to error
                    $normalizedErrors[] = ['detail' => 'Field validation error'];
                }
            }

            // If no errors were extracted, use the whole object as fallback
            if (empty($normalizedErrors)) {
                $normalizedErrors[] = (array) $errors;
            }

            return $normalizedErrors;
        } else {
            // Handle array-of-scalars or mixed: map each item to error object
            $normalizedErrors = [];
            foreach ($errors as $error) {
                if ($error === null) {
                    // Handle null entries
                    $normalizedErrors[] = ['detail' => 'Null error entry'];
                } elseif (is_scalar($error)) {
                    $normalizedErrors[] = ['detail' => (string) $error];
                } else {
                    $normalizedErrors[] = $error;
                }
            }
            return $normalizedErrors;
        }
    }

    /**
     * Handle array input without 'errors' key
     */
    private function normalizeArrayWithoutErrorsKey(array $data): array
    {
        // Check for common error message fields
        if (isset($data['message'])) {
            return [
                'errors' => [['detail' => $data['message']]]
            ];
        }

        if (isset($data['error'])) {
            if (is_string($data['error'])) {
                return [
                    'errors' => [['detail' => $data['error']]]
                ];
            } else {
                // Wrap error object, ensuring proper type conversions
                $errorObj = $this->convertArrayToObject($data['error']);
                return [
                    'errors' => [$errorObj]
                ];
            }
        }

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

        return [
            'errors' => [['detail' => $errorMessage]]
        ];
    }

    /**
     * Recursively convert arrays to objects for backward compatibility
     * (Note: This method maintains compatibility with existing behavior
     * but the normalized output should use arrays for JSON:API compliance)
     */
    private function convertArrayToObject($data)
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                // Map common non-JSON:API fields to JSON:API equivalents
                if ($key === 'message') {
                    $result['detail'] = $this->convertArrayToObject($value);
                } elseif (in_array($key, ['code', 'status']) && is_numeric($value)) {
                    // Convert certain fields to strings as required by JSON:API spec
                    $result[$key] = (string) $value;
                } else {
                    $result[$key] = $this->convertArrayToObject($value);
                }
            }
            return $result;
        }
        return $data;
    }
}