# Shared Types Reference

This document catalogs all shared data structures, value objects, and constants available in `nuimarkets/laravel-shared-utils`.

## Value Objects

### JWTUser

**Location**: `src/Auth/JWTUser.php`

Immutable user object for standardized JWT authentication across services. Replaces ad-hoc `stdClass` usage with type-safe properties.

```php
class JWTUser
{
    public function __construct(
        public readonly string $id,
        public readonly string $org_id,
        public readonly string $role,       // buyer, seller, admin, machine, etc.
        public readonly ?string $email = null,
        public readonly ?string $org_name = null,
        public readonly ?string $org_type = null,
    ) {}
}
```

**Usage**: Extract from JWT token in authentication middleware, pass to controllers/services.

### SimpleDocument

**Location**: `src/Support/SimpleDocument.php`

JSON API document wrapper that serializes to simple arrays instead of full JSON:API format.

```php
class SimpleDocument extends Document implements ItemDocumentInterface
{
    public function jsonSerialize()
    // Returns the attributes array, not full JSON:API structure
}
```

**Usage**: Request bodies for internal APIs that don't require JSON:API envelope.

## Constants / Enums

### FailureCategory

**Location**: `src/RemoteRepositories/FailureCategory.php`

Constants for classifying remote repository failures. Used by `CachesFailedLookups` trait to determine appropriate cache TTL.

| Constant | Value | Description |
|----------|-------|-------------|
| `NOT_FOUND` | `'not_found'` | HTTP 404 - Resource doesn't exist |
| `AUTH_ERROR` | `'auth_error'` | HTTP 401/403 - Auth/permission failure |
| `RATE_LIMITED` | `'rate_limited'` | HTTP 429 - Rate limit exceeded |
| `SERVER_ERROR` | `'server_error'` | HTTP 5xx - Server-side issue |
| `TIMEOUT` | `'timeout'` | cURL error 28 - Operation timed out |
| `CONNECTION_ERROR` | `'connection_error'` | DNS/connection failures |
| `CLIENT_ERROR` | `'client_error'` | Other HTTP 4xx errors |
| `UNKNOWN` | `'unknown'` | Unclassified failure |

**Transient categories** (may self-resolve): `TIMEOUT`, `CONNECTION_ERROR`, `SERVER_ERROR`, `RATE_LIMITED`

```php
// Check if a failure is transient
FailureCategory::isTransient($category); // true for transient categories
```

## Event Structures

### IntercomEvent

**Location**: `src/Events/IntercomEvent.php`

Laravel event for Intercom analytics tracking with multi-tenant support.

```php
class IntercomEvent
{
    public string $userId;
    public string $event;
    public array $properties;
    public ?string $tenantId;
}
```

**Usage**: Dispatch for async Intercom event tracking via `IntercomListener`.

## Request DTOs

### AttachmentUploadRequest

**Location**: `src/Http/Requests/AttachmentUploadRequest.php`

Base form request for file attachments. Services can extend and customize.

**Default validation**:
- `attachments`: Required array, 1-10 files
- Each file: Max 10MB, allowed types: jpg, jpeg, png, gif, pdf, doc, docx, xls, xlsx, txt, csv
- `type`: Optional, one of: image, document, video, text, other

**Extension points**:
- `getFileValidationRules()` - Customize file constraints
- `getAllowedMimeTypes()` - Customize file types
- `getAllowedTypes()` - Customize type categories

## Exception Types

### RemoteServiceException

**Location**: `src/Exceptions/RemoteServiceException.php`

Standard exception for remote service communication failures. Preserves original HTTP status codes.

### CachedLookupFailureException

**Location**: `src/Exceptions/CachedLookupFailureException.php`

Thrown when a cached lookup failure is hit. Provides structured error context.

### BaseHttpRequestException

**Location**: `src/Exceptions/BaseHttpRequestException.php`

Base class for HTTP request exceptions with standardized error handling.

## Abstract Base Classes

### RemoteRepository

**Location**: `src/RemoteRepositories/RemoteRepository.php`

Abstract base for service-to-service API communication.

**Key features**:
- Lazy token loading (tokens retrieved on first request)
- X-Ray trace propagation
- Configurable retry logic
- URL validation and security checks

See [RemoteRepository.md](../RemoteRepository.md) for full documentation.

## Contracts

### MachineTokenServiceInterface

**Location**: `src/Contracts/MachineTokenServiceInterface.php`

Interface for machine-to-machine authentication token services.

```php
interface MachineTokenServiceInterface
{
    public function getToken(): string;
}
```

## Related Documentation

- [RemoteRepository.md](../RemoteRepository.md) - Service-to-service communication
- [failure-caching.md](../failure-caching.md) - CachesFailedLookups trait
- [distributed-tracing.md](../distributed-tracing.md) - X-Ray integration
- [logging-integration.md](../logging-integration.md) - Logging infrastructure
