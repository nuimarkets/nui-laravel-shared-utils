<?php

namespace NuiMarkets\LaravelSharedUtils\Http\Controllers\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use NuiMarkets\LaravelSharedUtils\Logging\LogFields;

/**
 * Trait for consistent controller action logging across services.
 *
 * This trait provides a simple way to add comprehensive logging to controller actions
 * with minimal code. It automatically captures request context, user information,
 * and action-specific data.
 *
 * Usage:
 * ```php
 * class OrderController extends Controller
 * {
 *     use LogsControllerActions;
 *
 *     public function store(CreateOrderRequest $request)
 *     {
 *         $this->logActionStart('store', $request);
 *         // ... controller logic ...
 *     }
 * }
 * ```
 */
trait LogsControllerActions
{
    /**
     * Log the start of a controller action with standard context.
     *
     * @param  string  $action  The action name (e.g., 'store', 'update', 'destroy')
     * @param  Request|null  $request  The request object (uses current request if not provided)
     * @param  array  $additionalContext  Additional context to include in the log
     */
    protected function logActionStart(string $action, ?Request $request = null, array $additionalContext = []): void
    {
        $request = $request ?: request();

        $context = $this->buildActionContext($action, $request, $additionalContext);

        $message = $this->getActionStartMessage($action);
        Log::info($message, $context);
    }

    /**
     * Log the successful completion of a controller action.
     *
     * @param  string  $action  The action name
     * @param  mixed  $result  The result of the action (e.g., created model, affected rows)
     * @param  Request|null  $request  The request object
     * @param  array  $additionalContext  Additional context to include in the log
     */
    protected function logActionSuccess(string $action, $result = null, ?Request $request = null, array $additionalContext = []): void
    {
        $request = $request ?: request();

        $context = $this->buildActionContext($action, $request, $additionalContext);
        $context[LogFields::RESULT] = 'success';

        if ($result !== null) {
            $context['result_data'] = $this->sanitizeResultData($result);
        }

        $message = $this->getActionSuccessMessage($action);
        Log::info($message, $context);
    }

    /**
     * Log the failure of a controller action.
     *
     * @param  string  $action  The action name
     * @param  \Throwable|string  $error  The error that occurred
     * @param  Request|null  $request  The request object
     * @param  array  $additionalContext  Additional context to include in the log
     */
    protected function logActionFailure(string $action, $error, ?Request $request = null, array $additionalContext = []): void
    {
        $request = $request ?: request();

        $context = $this->buildActionContext($action, $request, $additionalContext);
        $context[LogFields::RESULT] = 'failure';

        if ($error instanceof \Throwable) {
            $context[LogFields::EXCEPTION] = get_class($error);
            $context[LogFields::ERROR_MESSAGE] = $error->getMessage();
            $context[LogFields::ERROR_CODE] = $error->getCode();
        } else {
            $context[LogFields::ERROR_MESSAGE] = (string) $error;
        }

        $message = $this->getActionFailureMessage($action);
        Log::error($message, $context);
    }

    /**
     * Build the context array for action logging.
     */
    protected function buildActionContext(string $action, Request $request, array $additionalContext): array
    {
        $context = [
            LogFields::FEATURE => $this->getFeatureName(),
            LogFields::ACTION => $action,
        ];

        // Add request context if available
        if ($user = $request->user()) {
            $context[LogFields::REQUEST_USER_ID] = $user->id ?? null;
            $context[LogFields::REQUEST_ORG_ID] = $user->org_id ?? $user->organization_id ?? null;
        }

        $context[LogFields::REQUEST_METHOD] = $request->method();
        $context[LogFields::REQUEST_PATH] = $request->path();

        // Add validated data if present and not sensitive
        if (! $this->containsSensitiveData($action)) {
            $validatedData = $this->getValidatedData($request);
            if (! empty($validatedData)) {
                $context[$this->getDataFieldName($action)] = $validatedData;
            }
        }

        // Add route parameters if useful
        $routeParams = $this->getUsefulRouteParameters($request);
        if (! empty($routeParams)) {
            $context['route_params'] = $routeParams;
        }

        // Merge additional context
        return array_merge($context, $additionalContext);
    }

    /**
     * Get the feature name for this controller.
     * Services can override this method to provide custom feature names.
     */
    protected function getFeatureName(): string
    {
        // Try to get from property first
        if (property_exists($this, 'loggingFeatureName')) {
            return $this->loggingFeatureName;
        }

        // Otherwise derive from class name
        $className = class_basename($this);

        return strtolower(str_replace('Controller', '', $className));
    }

    /**
     * Get validated data from the request safely.
     */
    protected function getValidatedData(Request $request): array
    {
        try {
            if (method_exists($request, 'validated')) {
                return $request->validated();
            }
        } catch (\Exception $e) {
            // Skip if validated() is not available (e.g., in tests or non-form requests)
        }

        return [];
    }

    /**
     * Get useful route parameters for logging.
     */
    protected function getUsefulRouteParameters(Request $request): array
    {
        $params = $request->route() ? $request->route()->parameters() : [];

        $result = [];
        foreach ($params as $key => $value) {
            // Skip objects without getKey method
            if (is_object($value) && ! method_exists($value, 'getKey')) {
                continue;
            }

            // Extract key from objects with getKey method
            if (is_object($value) && method_exists($value, 'getKey')) {
                $result[$key] = $value->getKey();
            } elseif (is_scalar($value)) {
                // Keep scalar values as-is
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Sanitize result data for logging.
     *
     * @param  mixed  $result
     * @param  array  $visitedObjects  Reference to array tracking visited objects
     * @return mixed
     */
    protected function sanitizeResultData($result, array &$visitedObjects = [])
    {
        // Handle non-objects and non-arrays directly
        if (! is_object($result) && ! is_array($result)) {
            return $result;
        }

        // Check for circular reference in objects
        if (is_object($result)) {
            $objectHash = spl_object_hash($result);

            // If we've already processed this object, return a placeholder
            if (isset($visitedObjects[$objectHash])) {
                return '[Circular Reference: '.get_class($result).']';
            }

            // Mark object as visited
            $visitedObjects[$objectHash] = true;

            // Convert to array if possible
            if (method_exists($result, 'toArray')) {
                $result = $result->toArray();
            } else {
                // For objects without toArray, return class name to avoid issues
                return '[Object: '.get_class($result).']';
            }
        }

        // Process arrays recursively
        if (is_array($result)) {
            $sanitized = [];
            foreach ($result as $key => $value) {
                $sanitized[$key] = $this->sanitizeResultData($value, $visitedObjects);
            }

            return $sanitized;
        }

        return $result;
    }

    /**
     * Get the appropriate field name for request data based on action.
     */
    protected function getDataFieldName(string $action): string
    {
        return match ($action) {
            'store', 'create' => 'request_data',
            'update', 'patch' => 'update_data',
            'index', 'search' => 'filters',
            default => 'data'
        };
    }

    /**
     * Check if action contains sensitive data that shouldn't be logged.
     * Services can override this to customize sensitive data detection.
     */
    protected function containsSensitiveData(string $action): bool
    {
        // Common sensitive actions
        $sensitiveActions = ['login', 'register', 'password', 'token', 'secret'];

        foreach ($sensitiveActions as $sensitive) {
            if (stripos($action, $sensitive) !== false) {
                return true;
            }
        }

        // Check property for additional sensitive actions
        if (property_exists($this, 'sensitiveActions')) {
            return in_array($action, $this->sensitiveActions, true);
        }

        return false;
    }

    /**
     * Get the log message for action start.
     */
    protected function getActionStartMessage(string $action): string
    {
        $controllerName = class_basename($this);

        return "{$controllerName}.{$action} started";
    }

    /**
     * Get the log message for action success.
     */
    protected function getActionSuccessMessage(string $action): string
    {
        $controllerName = class_basename($this);

        return "{$controllerName}.{$action} completed successfully";
    }

    /**
     * Get the log message for action failure.
     */
    protected function getActionFailureMessage(string $action): string
    {
        $controllerName = class_basename($this);

        return "{$controllerName}.{$action} failed";
    }
}
