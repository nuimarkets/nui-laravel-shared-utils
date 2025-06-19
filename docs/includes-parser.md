# IncludesParser - API Response Transformation Utility

## Overview

The `IncludesParser` is a Laravel utility that provides advanced API response transformation capabilities beyond standard Laravel/Fractal includes. It enables fine-grained control over what data is returned in API responses across microservices architectures.

## Purpose

- **Performance optimization** - Reduces API payload sizes for faster responses
- **Microservices efficiency** - Enables lighter cross-service communication  
- **Consistent pattern** - Standardized approach across application transformers
- **Flexible data inclusion** - Query parameter-driven response customization

## Installation

The IncludesParser is included in the `nuimarkets/laravel-shared-utils` package. Install via Composer:

```bash
composer require nuimarkets/laravel-shared-utils
```

### Service Provider Registration

Add the service provider to your Laravel application:

```php
// config/app.php
'providers' => [
    // Other providers...
    NuiMarkets\LaravelSharedUtils\Providers\IncludesParserServiceProvider::class,
],
```

### Configuration

Publish the configuration file (optional):

```bash
php artisan vendor:publish --provider="NuiMarkets\LaravelSharedUtils\Providers\IncludesParserServiceProvider"
```

Configure default and disabled includes in `config/includes_parser.php`:

```php
[
    'default_includes' => [
        'tenant',      // Always include tenant data
        'shortdata',   // Use lightweight transforms by default
    ],
    'disabled_includes' => [
        'sensitive_data',  // Block sensitive information
        'internal_only',   // Prevent internal data exposure
    ],
]
```

## Key Features

### 1. Custom Include Parameters

Unlike standard Laravel resource patterns, IncludesParser supports custom include parameters:

```php
// Standard Laravel approach
UserResource::withoutRelationships()
UserResource::minimal()

// IncludesParser approach
?include=shortdata          // Triggers light_transform()
?include=tenant,shortdata   // Multiple custom includes
```

### 2. Shortdata Convention

**"shortdata"** is a custom convention (not Laravel standard) that triggers lightweight data transformations:

```php
public function transform(Tenant $model)
{
    if ($this->includesParser->isIncluded('shortdata')) {
        return $this->light_transform($model);  // Minimal data only
    }
    return $this->full_transform($model);       // Complete data with relationships
}
```

### 3. Include/Exclude Query Parameters

Support for both inclusion and exclusion of data:

```php
// Include specific data
?include=users,permissions,tenant

// Exclude unwanted data  
?exclude=addresses,media

// Combine both
?include=users,permissions,tenant&exclude=addresses
```

### 4. Default Includes

Automatically include certain parameters without explicit query parameters:

```php
// In controller or service provider
$includesParser->addDefaultInclude('tenant');
$includesParser->addDefaultInclude('shortdata');
```

### 5. Disabled Includes

Block certain includes for security or performance:

```php  
// Prevent sensitive data inclusion
$includesParser->addDisabledInclude('sensitive_data');
$includesParser->addDisabledInclude('admin_only');
```

## Usage Examples

### Basic Controller Usage

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use NuiMarkets\LaravelSharedUtils\Support\IncludesParser;

class UsersController extends Controller
{
    public function __construct(private IncludesParser $includesParser)
    {
        // Add default includes for all actions
        $this->includesParser->addDefaultInclude('tenant');
    }

    public function index()
    {
        // Parser automatically processes ?include and ?exclude query parameters
        $users = User::query()->paginate();
        
        return UserResource::collection($users);
    }
}
```

### Transformer Integration

```php
<?php

namespace App\Transformers;

use League\Fractal\TransformerAbstract;
use NuiMarkets\LaravelSharedUtils\Support\IncludesParser;

class UserTransformer extends TransformerAbstract
{
    public function __construct(private IncludesParser $includesParser)
    {
    }

    public function transform(User $user)
    {
        // Check for shortdata convention
        if ($this->includesParser->isIncluded('shortdata')) {
            return $this->light_transform($user);
        }
        
        return $this->full_transform($user);
    }
    
    private function light_transform(User $user): array
    {
        // Return only essential fields - no expensive joins
        return [
            'id' => $user->uuid,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
        ];
    }
    
    private function full_transform(User $user): array
    {
        // Return complete data with relationships
        return [
            'id' => $user->uuid,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'profile' => $user->profile,
            'permissions' => $user->permissions,
            'addresses' => $user->addresses,
            'createdAt' => $user->created_at,
            'updatedAt' => $user->updated_at,
        ];
    }
}
```

### Advanced Usage with Conditional Logic

```php
public function transform(Product $product)
{
    $data = [
        'id' => $product->uuid,
        'name' => $product->name,
        'status' => $product->status,
    ];
    
    // Conditionally include expensive data
    if ($this->includesParser->isIncluded('pricing')) {
        $data['pricing'] = $this->getPricingData($product);
    }
    
    if ($this->includesParser->isIncluded('inventory')) {
        $data['inventory'] = $this->getInventoryData($product);
    }
    
    // Always exclude sensitive data if disabled
    if ($this->includesParser->isNotIncluded('internal_notes')) {
        unset($data['internal_notes']);
    }
    
    return $data;
}
```

## API Usage Examples

### Frontend API Calls

```javascript
// Get minimal user data for dropdowns/lists
GET /api/users/linked?include=tenant,shortdata

// Get full user data for detailed views  
GET /api/users/linked?include=tenant,permissions,addresses

// Exclude unwanted data
GET /api/products?include=pricing&exclude=internal_notes,attachments
```

### Cross-Service Communication

```php
// Service-to-service API call
$urlPath = 'users/linked?include=tenant,shortdata&ids=' . $userIds;
$response = $this->apiService->get($urlPath);
```

## Common Include Parameters

### Standard Parameters

- `shortdata` - Lightweight data without expensive joins
- `tenant` - Include tenant/organization data  
- `permissions` - Include user permission data
- `addresses` - Include address relationships
- `attachments` - Include file attachments
- `pricing` - Include pricing information
- `inventory` - Include inventory/stock data

### Application-Specific Parameters

Each service may define additional custom parameters:

- Authentication services: `users.active`, `allAddressVersions`, `disabled_addresses`
- Order management: `items`, `attachments`, `authors`, `simpleList`, `isSuperAdmin`
- Product catalogs: Service-specific product attributes and relationships

## Performance Benefits

1. **Reduced Payload Sizes**: Up to 70% smaller responses with `shortdata`
2. **Faster Database Queries**: Avoid expensive joins when not needed
3. **Better Mobile Performance**: Smaller payloads for mobile apps  
4. **Cross-Service Efficiency**: Lighter API calls between microservices
5. **Bandwidth Savings**: Reduced data transfer costs

## Best Practices

### When to Use Shortdata

- List views (user dropdowns, organization lists)
- Mobile app responses
- Cross-service API calls where minimal data is sufficient
- Performance-critical endpoints
- Paginated results with many records

### When to Use Full Data

- Detail views requiring complete information
- Admin interfaces needing all fields
- Data export functionality
- Complex business logic requiring relationships

### Implementation Guidelines

1. **Always implement both** `light_transform()` and `full_transform()` methods
2. **Use `shortdata`** as the standard lightweight parameter name
3. **Add default includes** in controllers when appropriate
4. **Document custom parameters** in service documentation
5. **Test performance impact** of different include combinations
6. **Use consistent naming** across all application services

## Testing

The IncludesParser includes comprehensive test coverage:

```bash
# Run IncludesParser tests
./vendor/bin/phpunit tests/Unit/Support/IncludesParserTest.php

# Test coverage includes:
# - Basic include/exclude parsing
# - Parameter trimming
# - Default includes management
# - Disabled includes enforcement  
# - Lazy parsing behavior
# - State reset functionality
# - Debug logging
```

## Migration from Service-Specific Implementations

### Updating Existing Services

1. **Install shared utils package**:
   ```bash
   composer require nuimarkets/laravel-shared-utils
   ```

2. **Register service provider**:
   ```php
   // config/app.php
   NuiMarkets\LaravelSharedUtils\Providers\IncludesParserServiceProvider::class,
   ```

3. **Update transformer constructors**:
   ```php
   // Before
   use App\Helpers\IncludesParser;
   
   // After  
   use NuiMarkets\LaravelSharedUtils\Support\IncludesParser;
   ```

4. **Remove service-specific implementations**:
   - Delete `app/Helpers/IncludeParser.php` or `app/Support/IncludesParser.php`
   - Remove any custom service providers
   - Update import statements

5. **Test functionality**:
   ```bash
   composer test
   ```

### Backward Compatibility

The shared implementation maintains backward compatibility with existing usage patterns:

- Same method signatures (`isIncluded()`, `isNotIncluded()`, etc.)
- Same query parameter format (`?include=`, `?exclude=`)
- Same shortdata convention
- Enhanced features (debug logging, additional methods)

## Troubleshooting

### Debug Logging

Enable debug logging to troubleshoot include/exclude behavior:

```php
// Add debug logging in controller
$includesParser->debug();

// Check logs for output like:
// [DEBUG] IncludesParser Debug State {
//   "included": {"users": true, "tenant": true},
//   "defaults": {"tenant": true},
//   "disabled": {"sensitive_data": true},
//   "query_include": "users,permissions",
//   "query_exclude": "permissions"
// }
```

### Common Issues

1. **Includes not working**: Verify service provider is registered
2. **Default includes not applied**: Check configuration and service provider boot
3. **Disabled includes still showing**: Ensure disabled includes are processed after defaults
4. **Performance issues**: Review which includes trigger expensive operations

## Architecture Notes

This is a **custom convention** - not a Laravel/PHP community standard. Other Laravel projects would typically use standard resource patterns or custom resource classes instead of this include parser approach.

The IncludesParser represents a custom solution developed specifically for microservices architectures and API optimization requirements.