<?php

namespace NuiMarkets\LaravelSharedUtils\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NuiMarkets\LaravelSharedUtils\Logging\LogFields;

/**
 * Base middleware for adding request context to all logs.
 * Services can extend this class to add service-specific context.
 * 
 * This middleware automatically adds:
 * - Request ID (from header or generated)
 * - Request method, path, and IP
 * - User context (if authenticated)
 * - Custom context via extending classes
 */
abstract class RequestLoggingMiddleware
{
    /**
     * The header name to check for existing request ID.
     * 
     * @var string
     */
    protected string $requestIdHeader = 'X-Request-ID';
    
    /**
     * Whether to add the request ID to response headers.
     * 
     * @var bool
     */
    protected bool $addRequestIdToResponse = true;
    
    /**
     * Handle an incoming request and set logging context.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Generate or retrieve request ID
        $requestId = $this->getRequestId($request);
        
        // Build base context for this request
        $context = $this->buildBaseContext($request, $requestId);
        
        // Add user context if authenticated
        $context = $this->addUserContext($request, $context);
        
        // Add service-specific context
        $context = $this->addServiceContext($request, $context);
        
        // Set the context for all logs in this request
        Log::withContext($context);
        
        // Log the request start if enabled
        $this->logRequestStart($request, $context);
        
        // Continue processing the request
        $response = $next($request);
        
        // Add request ID to response headers if enabled
        if ($this->addRequestIdToResponse && $response) {
            $response->headers->set($this->requestIdHeader, $requestId);
        }
        
        // Log the request completion if enabled
        $this->logRequestComplete($request, $response, $context);
        
        return $response;
    }
    
    /**
     * Get or generate a request ID.
     * 
     * @param Request $request
     * @return string
     */
    protected function getRequestId(Request $request): string
    {
        return $request->headers->get($this->requestIdHeader, Str::uuid()->toString());
    }
    
    /**
     * Build the base logging context.
     * 
     * @param Request $request
     * @param string $requestId
     * @return array
     */
    protected function buildBaseContext(Request $request, string $requestId): array
    {
        return [
            LogFields::REQUEST_ID => $requestId,
            'request' => [
                'method' => $request->method(),
                'path' => $request->path(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]
        ];
    }
    
    /**
     * Add user context to the logging context.
     * 
     * @param Request $request
     * @param array $context
     * @return array
     */
    protected function addUserContext(Request $request, array $context): array
    {
        if ($user = $request->user()) {
            $context[LogFields::USER_ID] = $this->getUserId($user);
            $context[LogFields::ORG_ID] = $this->getUserOrgId($user);
            
            // Add additional user fields if available
            if ($email = $this->getUserEmail($user)) {
                $context[LogFields::USER_EMAIL] = $email;
            }
            
            if ($userType = $this->getUserType($user)) {
                $context[LogFields::USER_TYPE] = $userType;
            }
        }
        
        return $context;
    }
    
    /**
     * Add service-specific context to the logging context.
     * Services should override this method to add custom context.
     * 
     * @param Request $request
     * @param array $context
     * @return array
     */
    abstract protected function addServiceContext(Request $request, array $context): array;
    
    /**
     * Log the start of a request.
     * Override this method to enable request start logging.
     * 
     * @param Request $request
     * @param array $context
     * @return void
     */
    protected function logRequestStart(Request $request, array $context): void
    {
        // Override in child classes if request start logging is desired
    }
    
    /**
     * Log the completion of a request.
     * Override this method to enable request completion logging.
     * 
     * @param Request $request
     * @param mixed $response
     * @param array $context
     * @return void
     */
    protected function logRequestComplete(Request $request, $response, array $context): void
    {
        // Override in child classes if request completion logging is desired
    }
    
    /**
     * Get the user ID from the authenticated user.
     * Services can override this to match their user model.
     * 
     * @param mixed $user
     * @return mixed
     */
    protected function getUserId($user)
    {
        return $user->id ?? null;
    }
    
    /**
     * Get the organization ID from the authenticated user.
     * Services can override this to match their user model.
     * 
     * @param mixed $user
     * @return mixed
     */
    protected function getUserOrgId($user)
    {
        return $user->org_id ?? $user->organization_id ?? null;
    }
    
    /**
     * Get the email from the authenticated user.
     * Services can override this to match their user model.
     * 
     * @param mixed $user
     * @return string|null
     */
    protected function getUserEmail($user): ?string
    {
        return $user->email ?? null;
    }
    
    /**
     * Get the user type from the authenticated user.
     * Services can override this to match their user model.
     * 
     * @param mixed $user
     * @return string|null
     */
    protected function getUserType($user): ?string
    {
        return $user->type ?? $user->user_type ?? null;
    }
    
    /**
     * Configure the middleware from an array of options.
     * 
     * @param array $options
     * @return self
     */
    public function configure(array $options): self
    {
        if (isset($options['request_id_header'])) {
            $this->requestIdHeader = $options['request_id_header'];
        }
        
        if (isset($options['add_request_id_to_response'])) {
            $this->addRequestIdToResponse = (bool) $options['add_request_id_to_response'];
        }
        
        return $this;
    }
}