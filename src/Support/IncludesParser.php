<?php

namespace Nuimarkets\LaravelSharedUtils\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * IncludesParser - Connect Platform Include Parser
 * 
 * Provides advanced API response transformation capabilities beyond standard Laravel/Fractal includes.
 * Enables fine-grained control over what data is returned in API responses across all Connect microservices.
 * 
 * Features:
 * - Custom include parameters (e.g., 'shortdata' for lightweight responses)
 * - Include/exclude query parameter support
 * - Default includes management
 * - Disabled includes for security
 * - Debug logging capabilities
 */
class IncludesParser
{
    private array $includeByDefault = [];
    private array $disabledIncludes = [];
    private ?array $included = null;
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Parse include/exclude query parameters and apply defaults and restrictions
     */
    public function parse(): void
    {
        $this->included = $this->includeByDefault;

        // Process includes with validation and sanitization
        if ($this->request->has('include')) {
            $includeParam = $this->request->input('include');
            if (is_string($includeParam) && $includeParam !== '') {
                $includes = $this->sanitizeParameterList($includeParam);
                $this->included = array_merge(
                    $this->included,
                    array_fill_keys($includes, true)
                );
            }
        }

        // Process excludes with validation and sanitization
        if ($this->request->has('exclude')) {
            $excludeParam = $this->request->input('exclude');
            if (is_string($excludeParam) && $excludeParam !== '') {
                $excludes = $this->sanitizeParameterList($excludeParam);
                foreach ($excludes as $exclude) {
                    unset($this->included[$exclude]);
                }
            }
        }

        // Remove any disabled includes (using array_diff_key for performance)
        $this->included = array_diff_key($this->included, $this->disabledIncludes);
    }

    /**
     * Sanitize and filter parameter list from comma-separated string
     */
    private function sanitizeParameterList(string $parameterString): array
    {
        return array_filter(
            array_map('trim', explode(',', $parameterString)),
            fn($item) => $item !== '' && is_string($item) && strlen($item) <= 255
        );
    }

    /**
     * Add an include that will be automatically included by default
     */
    public function addDefaultInclude(string $include): void
    {
        $this->includeByDefault[$include] = true;
        $this->included = null; // Reset parsed state
    }

    /**
     * Remove a default include
     */
    public function removeDefaultInclude(string $include): void
    {
        unset($this->includeByDefault[$include]);
        $this->included = null; // Reset parsed state
    }

    /**
     * Add an include that should be disabled/blocked from being included
     */
    public function addDisabledInclude(string $include): void
    {
        $this->disabledIncludes[$include] = true;
        $this->included = null; // Reset parsed state
    }

    /**
     * Remove a disabled include restriction
     */
    public function removeDisabledInclude(string $include): void
    {
        unset($this->disabledIncludes[$include]);
        $this->included = null; // Reset parsed state
    }

    /**
     * Check if a specific include parameter is included
     */
    public function isIncluded(string $include): bool
    {
        if (is_null($this->included)) {
            $this->parse();
        }
        
        return isset($this->included[$include]);
    }

    /**
     * Check if a specific include parameter is NOT included
     */
    public function isNotIncluded(string $include): bool
    {
        return !$this->isIncluded($include);
    }

    /**
     * Get all currently included parameters
     */
    public function getIncludes(): array
    {
        if (is_null($this->included)) {
            $this->parse();
        }
        
        return array_keys($this->included);
    }

    /**
     * Get all default includes
     */
    public function getDefaultIncludes(): array
    {
        return array_keys($this->includeByDefault);
    }

    /**
     * Get all disabled includes
     */
    public function getDisabledIncludes(): array
    {
        return array_keys($this->disabledIncludes);
    }

    /**
     * Debug log current include state for troubleshooting
     */
    public function debug(): void
    {
        if (is_null($this->included)) {
            $this->parse();
        }

        Log::debug('IncludesParser Debug State', [
            'included' => $this->included,
            'defaults' => $this->includeByDefault,
            'disabled' => $this->disabledIncludes,
            'query_include' => $this->request->input('include'),
            'query_exclude' => $this->request->input('exclude'),
        ]);
    }

    /**
     * Reset parser state (useful for testing)
     */
    public function reset(): void
    {
        $this->includeByDefault = [];
        $this->disabledIncludes = [];
        $this->included = null;
    }
}