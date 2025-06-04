<?php

/**
 * Dumps an array to output with formatted display
 *
 * This function takes an array and displays it in a formatted way similar to dump().
 * It uses array_export internally to create a readable representation of the array.
 *
 * @param array $array The array to dump to output
 * @param int $indent The indentation level for nested arrays (default: 0)
 * @return void
 */
function dump_output($array, $indent = 0): void
{
    $output = array_export($array, $indent);

    // Output it like dump() does - with styling and immediate display
    echo "\n" . $output . "\n\n";

}

/**
 * Dumps a response object's JSON content to output with formatted display
 *
 * This function extracts the JSON content from a response object and displays it
 * in a formatted way. Useful for debugging API responses during testing.
 *
 * @param mixed $response The response object (typically from HTTP requests)
 * @param int $indent The indentation level for nested arrays (default: 0)
 * @return void
 */
function dump_response($response, $indent = 0): void
{
    $array = $response->json();
    $output = array_export($array, $indent);

    // Output it like dump() does - with styling and immediate display
    echo "\n" . $output . "\n\n";

}

/**
 * Exports an array to a formatted string representation
 *
 * This function converts an array into a readable string format with proper
 * indentation and type-specific formatting. It handles nested arrays recursively
 * and distinguishes between sequential and associative arrays for cleaner output.
 *
 * Features:
 * - Handles nested arrays with proper indentation
 * - Detects sequential numeric arrays and omits keys for cleaner output
 * - Properly formats strings, booleans, null values, and numbers
 * - Escapes special characters in strings
 *
 * @param array $array The array to export
 * @param int $indent The current indentation level (default: 0)
 * @return string The formatted string representation of the array
 */
function array_export($array, $indent = 0): string
{
    // Create indentation string based on current level
    $spaces = str_repeat('    ', $indent);
    
    // Handle empty arrays
    if (empty($array)) return '[]';

    // Check if this is a sequential numeric array (0, 1, 2, 3...)
    // This allows us to omit keys for cleaner output on simple arrays
    $isSequential = array_keys($array) === range(0, count($array) - 1);

    $output = "[\n";
    
    // Process each key-value pair in the array
    foreach ($array as $key => $value) {
        // Add proper indentation for this line
        $output .= $spaces . '    ';

        // Only show key if it's not a sequential numeric array
        // This keeps output clean for simple indexed arrays
        if (!$isSequential || !is_int($key)) {
            // Quote string keys, leave numeric keys unquoted
            $output .= is_string($key) ? "'$key'" : $key;
            $output .= ' => ';
        }

        // Handle different value types appropriately
        if (is_array($value)) {
            // Recursively process nested arrays with increased indentation
            $output .= array_export($value, $indent + 1);
        } elseif (is_string($value)) {
            // Quote strings and escape special characters
            $output .= "'" . addslashes($value) . "'";
        } elseif (is_bool($value)) {
            // Convert boolean to string representation
            $output .= $value ? 'true' : 'false';
        } elseif (is_null($value)) {
            // Handle null values
            $output .= 'null';
        } else {
            // Handle numeric and other scalar values
            $output .= $value;
        }
        
        // Add comma and newline for next element
        $output .= ",\n";
    }
    
    // Close the array with proper indentation
    $output .= $spaces . ']';
    return $output;
}


/**
 * Recursively nulls out specified fields in an array
 *
 * This function traverses an array (including nested arrays) and sets specified
 * field names to null. Useful for sanitizing test data or removing sensitive
 * information from arrays before comparison or output.
 *
 * The function uses JSON encode/decode to ensure we're working with a clean
 * array structure and handles nested arrays recursively.
 *
 * @param array $data The array to modify
 * @param array $fieldsToNull Array of field names to set to null
 * @return array The modified array with specified fields nulled
 */
function null_values(array $data, array $fieldsToNull) {

    // Convert to ensure we're working with a clean array structure
    // This also handles objects that might be passed in
    $data = json_decode(json_encode($data), true);

    // Process each key-value pair in the array
    foreach ($data as $key => &$value) {
        // If this key matches one of the fields to null, set it to null
        if (in_array($key, $fieldsToNull)) {
            $value = null;
        }
        // If value is an array, recursively process it to handle nested structures
        elseif (is_array($value)) {
            $value = null_values($value, $fieldsToNull);
        }
    }

    return $data;
}