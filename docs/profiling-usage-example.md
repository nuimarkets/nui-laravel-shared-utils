# ProfilingTrait Usage Guide

## Overview

The `ProfilingTrait` provides comprehensive performance monitoring for RemoteRepository operations, helping identify network bottlenecks and optimize API call patterns.

## Automatic Integration

The ProfilingTrait is automatically available in any class extending `RemoteRepository`:

```php
use NuiMarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository;

class ProductRepository extends RemoteRepository 
{
    // ProfilingTrait is automatically available via RemoteRepository
}
```

## Initialization

Initialize profiling at the start of your request cycle (typically in middleware or service provider):

```php
use NuiMarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository;

// Initialize profiling at request start
RemoteRepository::initProfiling();
```

## Logging Results

Output timing results at the end of your request cycle:

```php
// Log all accumulated timing data
RemoteRepository::logTimings();
```

## Sample Output

The profiling logs provide detailed breakdown of repository performance:

```json
{
  "class": "App\\RemoteRepositories\\ProductV2Repository",
  "total_seconds": 0.847,
  "request_percentage": "23%",
  "calls": 5,
  "calls_breakdown": [
    {"method": "get", "seconds": 0.234},
    {"method": "get", "seconds": 0.189},
    {"method": "post", "seconds": 0.312},
    {"method": "get", "seconds": 0.089},
    {"method": "getUserUrl", "seconds": 0.023}
  ]
}
```

## Integration Example

Complete middleware example for automatic profiling:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use NuiMarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository;

class RemoteRepositoryProfilingMiddleware
{
    public function handle($request, Closure $next)
    {
        // Initialize profiling at request start
        RemoteRepository::initProfiling();
        
        $response = $next($request);
        
        // Log timing results at request end
        RemoteRepository::logTimings();
        
        return $response;
    }
}
```

## Performance Analysis

Use the profiling data to identify:

1. **Slow Repository Operations**: Methods taking > 100ms
2. **High Call Volume**: Classes with > 10 API calls per request  
3. **Total Network Time**: Sum of all repository percentages
4. **Request Bottlenecks**: Classes consuming > 50% of request time

## Configuration

Control profiling behavior via environment variables:

```env
# Enable profiling (controls whether timing data is collected and logged)
REMOTE_REPOSITORY_ENABLE_PROFILING=true

# Enable detailed API request logging
REMOTE_REPOSITORY_LOG_REQUESTS=true

# Set maximum URL length for GET requests
REMOTE_REPOSITORY_MAX_URL_LENGTH=2048
```

**Configuration Options:**
- `REMOTE_REPOSITORY_ENABLE_PROFILING`: Controls whether profiling is active (default: false)
- `REMOTE_REPOSITORY_LOG_REQUESTS`: Enable detailed request/response logging (default: false)
- `REMOTE_REPOSITORY_MAX_URL_LENGTH`: Maximum URL length for logs (default: 255)

## Troubleshooting Performance Issues

When investigating slow requests:

1. **Check total_seconds**: Individual repository timing
2. **Review calls_breakdown**: Identify which methods are slowest
3. **Analyze request_percentage**: Understand network vs application time
4. **Compare across repositories**: Find the biggest contributors

The profiling trait automatically handles edge cases like missing initialization and provides safe fallbacks for production environments.