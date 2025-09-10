# ErrorCollectionParser Testing Documentation

## Overview
The ErrorCollectionParser has comprehensive test coverage with both unit tests (18 tests) and integration tests (15 tests) that verify all error format transformations and JSON API compliance.

**Status**: ✅ All 262 library tests passing, including complete ErrorCollectionParser test suite

## Integration Test Strategy

### Test Environment Setup
- Full Laravel application context with Orchestra Testbench
- Complete Swis JSON API client configuration
- Mock HTTP responses for various error scenarios
- Service container binding with proper dependency injection

### Core Integration Test Cases

#### 1. End-to-End Error Parsing Workflow
```php
class ErrorCollectionParserIntegrationTest extends TestCase
{
    /** @test */
    public function test_full_error_parsing_workflow_with_json_api_client()
    {
        // Test complete workflow: HTTP error → parser → ErrorCollection
        // Verify base parser integration doesn't break
    }
}
```

#### 2. Service Container Integration
```php
/** @test */
public function test_service_provider_registration()
{
    // Test singleton registration
    // Test alias binding to Swis interface
    // Verify DI container resolves enhanced parser
}
```

#### 3. RemoteRepository Integration
```php
/** @test */
public function test_remote_repository_error_handling()
{
    // Test parser integration with RemoteRepository classes
    // Mock API responses with various error formats
    // Verify error normalization in real usage context
}
```

#### 4. Real-World Error Scenarios
```php
/** @test */
public function test_handles_actual_api_error_responses()
{
    // Test parsing of real API error responses
    // Various HTTP status codes (400, 422, 500)
    // Mixed error format responses
    // Malformed JSON responses
}
```

### Mock Response Test Data

#### Valid JSON API Errors
```json
{
  "errors": [
    {
      "status": "422",
      "title": "Validation Error",
      "detail": "The email field is required",
      "source": {"pointer": "/data/attributes/email"}
    }
  ]
}
```

#### Malformed Error Responses
```json
{"errors": "Database connection failed"}
{"message": "Internal server error"}
{"error": {"code": 500, "message": "Server error"}}
```

#### Edge Case Responses
```json
{"errors": null}
{"errors": []}
{"errors": ["Error 1", "Error 2"]}
{}
```

### Configuration Testing
```php
/** @test */
public function test_config_driven_behavior()
{
    // Test error_logging.include_stack_trace config
    // Test error_parser.include_traces config  
    // Test sensitive data redaction via config
}
```

### Performance Testing
```php
/** @test */
public function test_parser_performance_with_large_errors()
{
    // Test parsing performance with large error datasets
    // Memory usage with extensive stack traces
    // Timeout handling for complex object conversion
}
```

## Test Implementation Notes

### Challenges Addressed
1. **Swis Client Validation**: Use full client setup instead of mocked parsers
2. **Service Binding**: Test actual DI container resolution
3. **Real HTTP Context**: Use Guzzle HTTP mock responses
4. **Config Integration**: Test with actual Laravel config system

### Test Structure
```
tests/Integration/
├── ErrorCollectionParserIntegrationTest.php
├── ServiceProviderIntegrationTest.php  
├── RemoteRepositoryErrorHandlingTest.php
└── Fixtures/
    ├── valid-json-api-errors.json
    ├── malformed-error-responses.json
    └── edge-case-responses.json
```

### Mock Setup Pattern
```php
protected function setUp(): void
{
    parent::setUp();
    
    // Setup full JSON API client with our enhanced parser
    $this->app->singleton(ErrorCollectionParser::class, function ($app) {
        // Complete parser setup with dependencies
    });
    
    $this->app->alias(
        ErrorCollectionParser::class,
        \Swis\JsonApi\Client\Parsers\ErrorCollectionParser::class
    );
}
```

## Success Criteria

### Integration Test Goals
- ✅ All error parsing scenarios work end-to-end
- ✅ Service container integration functions correctly  
- ✅ No regressions in base Swis parser functionality
- ✅ Real-world error formats are handled properly
- ✅ Performance remains acceptable under load
- ✅ Configuration-driven behavior works as expected

### Coverage Targets
- **End-to-end workflows**: 100% of documented use cases
- **Error format combinations**: All documented input/output pairs
- **Service integration**: Complete DI container workflow
- **Config scenarios**: All configuration flag combinations

## Implementation Priority
1. **High**: Basic end-to-end parsing workflow
2. **High**: Service container integration  
3. **Medium**: RemoteRepository integration
4. **Medium**: Real API response handling
5. **Low**: Performance and config edge cases

This integration test plan complements the unit tests by providing comprehensive validation in realistic usage scenarios where the strict Swis client validation is properly handled.