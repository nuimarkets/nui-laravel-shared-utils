# JSON API Validation Guide

This guide explains how to implement consistent JSON:API validation error handling across your Laravel services.

## Table of Contents

- [Overview](#overview)
- [Quick Start](#quick-start)
- [Advanced Usage](#advanced-usage)
- [Testing](#testing)
- [Migration Guide](#migration-guide)
- [Benefits](#benefits)
- [Troubleshooting](#troubleshooting)

## Overview

The JSON API validation utilities provide:
- **Consistent error formatting** - Standardized JSON:API compliant validation responses
- **Zero configuration** - Works out of the box with BaseErrorHandler
- **Service compatibility** - Uniform error handling across all microservices
- **Testing support** - Built-in assertions for validation error testing
- **Framework integration** - Seamless Laravel FormRequest integration

## Quick Start

### 1. Install the Package

```bash
composer require nuimarkets/laravel-shared-utils
```

### 2. Use the JsonApiValidation Trait

Replace your existing FormRequest validation handling:

```php
use NuiMarkets\LaravelSharedUtils\Http\Requests\JsonApiValidation;
use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    use JsonApiValidation;

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'email' => 'required|email',
            'name' => 'required|min:2',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|uuid',
            'items.*.quantity' => 'required|integer|min:1'
        ];
    }
}
```

### 3. Configure BaseErrorHandler (if not already done)

In your service's `app/Exceptions/Handler.php`:

```php
use NuiMarkets\LaravelSharedUtils\Exceptions\BaseErrorHandler;

class Handler extends BaseErrorHandler
{
    // Your existing exception handling logic
}
```

### 4. Validation Errors are Now Consistent

All validation failures will return this standardized format:

```json
{
  "meta": {
    "message": "Validation Failed",
    "status": 422
  },
  "errors": [
    {
      "status": "422",
      "title": "Validation Error",
      "detail": "The email field is required.",
      "source": {
        "pointer": "/data/attributes/email"
      }
    },
    {
      "status": "422",
      "title": "Validation Error",
      "detail": "The name field must be at least 2 characters.",
      "source": {
        "pointer": "/data/attributes/name"
      }
    }
  ]
}
```

## Advanced Usage

### Nested Field Validation

The trait handles complex nested validation automatically:

```php
public function rules()
{
    return [
        'user.profile.age' => 'required|integer|min:18',
        'data.items.0.productId' => 'required|uuid',
        'buyerOrgIds.1' => 'required|uuid'
    ];
}
```

**Results in:**
```json
{
  "errors": [
    {
      "status": "422",
      "title": "Validation Error",
      "detail": "The user.profile.age field is required.",
      "source": {
        "pointer": "/data/attributes/user.profile.age"
      }
    }
  ]
}
```

> **Tip**: For human-readable field names in nested fields, use Laravel's custom attributes in your FormRequest:
> ```php
> public function attributes(): array
> {
>     return ['user.profile.age' => 'user profile age'];
> }
> ```

> **Note on Pointer Format**: The library uses Laravel's dot-notation in source pointers (e.g., `/data/attributes/user.profile.age`) instead of strict JSON Pointer format (`/data/attributes/user/profile/age`). This design choice maintains consistency with Laravel's validation field naming and makes the errors more intuitive for Laravel developers.

### Multiple Rules Per Field

Each failed validation rule creates a separate error object:

```php
public function rules()
{
    return [
        'password' => 'required|min:8|confirmed'
    ];
}
```

**When password is "abc" (fails min:8 and confirmed):**
```json
{
  "errors": [
    {
      "status": "422",
      "title": "Validation Error",
      "detail": "The password field must be at least 8 characters.",
      "source": {
        "pointer": "/data/attributes/password"
      }
    },
    {
      "status": "422",
      "title": "Validation Error",
      "detail": "The password field confirmation does not match.",
      "source": {
        "pointer": "/data/attributes/password"
      }
    }
  ]
}
```

## Testing

### Using JsonApiAssertions

The shared library provides testing utilities for validation assertions:

```php
use NuiMarkets\LaravelSharedUtils\Testing\JsonApiAssertions;

class OrderTest extends TestCase
{
    use JsonApiAssertions;

    public function test_create_order_validation()
    {
        $response = $this->postJson('/api/orders', [
            'email' => 'invalid-email',
            'items' => []
        ]);

        $response->assertStatus(422);

        // Assert specific fields have validation errors
        $this->assertHasValidationErrors($response, [
            'email',
            'items',
            'name'
        ]);
    }

    public function test_nested_field_validation()
    {
        $response = $this->postJson('/api/orders', [
            'data' => [
                'items' => [
                    ['productId' => 'invalid-uuid']
                ]
            ]
        ]);

        // Works with nested field names
        $this->assertHasValidationErrors($response, [
            'data.items.0.productId'
        ]);
    }
}
```

### Manual Testing

You can test validation responses manually:

```bash
curl -X POST http://your-service.local/api/orders \
  -H "Content-Type: application/json" \
  -d '{
    "email": "invalid-email",
    "name": ""
  }' | jq
```

## Migration Guide

### From connect-order (BaseApiRequest)

**Before:**
```php
abstract class BaseApiRequest extends FormRequest
{
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'meta' => [
                'message' => 'Validation Failed',
                'status' => 422,
            ],
            'errors' => $this->formatErrors($validator->errors()),
        ], 422));
    }

    private function formatErrors($errors) { /* custom logic */ }
}
```

**After:**
```php
use NuiMarkets\LaravelSharedUtils\Http\Requests\JsonApiValidation;

abstract class BaseApiRequest extends FormRequest
{
    use JsonApiValidation;

    // Remove failedValidation() method
    // Remove formatErrors() method
}
```

### From connect-auth/connect-product (JsonApiFormat trait)

**Before:**
```php
use App\Http\Requests\Traits\JsonApiFormat;

class SomeRequest extends FormRequest
{
    use JsonApiFormat;
}
```

**After:**
```php
use NuiMarkets\LaravelSharedUtils\Http\Requests\JsonApiValidation;

class SomeRequest extends FormRequest
{
    use JsonApiValidation;
}
```

**Then delete the local trait file:**
```bash
rm app/Http/Requests/Traits/JsonApiFormat.php
```

### Configuration Cleanup

Remove any validation error format configuration:

```php
// Remove from config files:
'api' => [
    'error_format' => 'jsonapi', // ← Remove this
],
```

## Benefits

### 🎯 Consistency
- **Uniform error format** across all services
- **No configuration drift** between services
- **Predictable client integration** for frontend/mobile teams

### 🚀 Developer Experience
- **Zero configuration** - works immediately
- **Drop-in replacement** for existing validation logic
- **Comprehensive testing** utilities included

### 🔧 Maintenance
- **Single source of truth** for error formatting
- **Automatic updates** via package upgrades
- **Reduced code duplication** across services

### 📊 Compliance
- **JSON:API specification** compliant
- **Consistent HTTP status codes** (422 for validation)
- **Proper error object structure** with source pointers

## Troubleshooting

### Common Issues

#### 1. ValidationException Not Being Caught

**Problem:** Validation errors not formatted correctly

**Solution:** Ensure BaseErrorHandler is configured:

```php
// In app/Exceptions/Handler.php
use NuiMarkets\LaravelSharedUtils\Exceptions\BaseErrorHandler;

class Handler extends BaseErrorHandler
{
    // Your existing logic
}
```

#### 2. Custom Response Still Being Returned

**Problem:** Old custom failedValidation() method still exists

**Solution:** Remove any custom failedValidation() methods:

```php
class SomeRequest extends FormRequest
{
    use JsonApiValidation;

    // ❌ Remove this method
    // protected function failedValidation(Validator $validator) { ... }
}
```

#### 3. JsonApiAssertions Not Working

**Problem:** Test assertions fail unexpectedly

**Solution:** Ensure you're using the trait:

```php
use NuiMarkets\LaravelSharedUtils\Testing\JsonApiAssertions;

class MyTest extends TestCase
{
    use JsonApiAssertions; // ← Add this
}
```

#### 4. Content-Type Header Behavior

**Behavior:** The library sets the appropriate Content-Type header based on the request's Accept header:

- **Default**: Returns `application/json` for general compatibility
- **JSON:API requests**: Returns `application/vnd.api+json` when client sends `Accept: application/vnd.api+json`

**Testing both scenarios:**

```php
// Test default JSON response
$response = $this->postJson('/api/endpoint', $data);
$response->assertHeader('Content-Type', 'application/json');

// Test JSON:API response
$response = $this->postJson('/api/endpoint', $data, [
    'Accept' => 'application/vnd.api+json'
]);
$response->assertHeader('Content-Type', 'application/vnd.api+json');
```

### Debugging

Enable debug mode to see full error details:

```php
// In .env
APP_DEBUG=true

// Or temporarily in tests
config(['app.debug' => true]);
```

### Getting Help

- **Documentation:** [README](../README.md)
- **Source code:** `src/Http/Requests/JsonApiValidation.php`
- **Tests:** `tests/Unit/Http/Requests/JsonApiValidationTest.php`
- **Integration examples:** `tests/Feature/JsonApiValidationIntegrationTest.php`

---

**Next Steps:**
- [Distributed Tracing Guide](distributed-tracing.md)
- [Logging Integration](logging-integration.md)
- [Testing Utilities](../README.md#testing-utilities)