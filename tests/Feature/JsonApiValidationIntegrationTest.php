<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use NuiMarkets\LaravelSharedUtils\Http\Requests\JsonApiValidation;
use NuiMarkets\LaravelSharedUtils\Testing\JsonApiAssertions;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class JsonApiValidationIntegrationTest extends TestCase
{
    use JsonApiAssertions;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure BaseErrorHandler
        $this->app->singleton(\Illuminate\Contracts\Debug\ExceptionHandler::class, function ($app) {
            return new \NuiMarkets\LaravelSharedUtils\Exceptions\BaseErrorHandler($app);
        });

        // Create test route with FormRequest using JsonApiValidation
        Route::post('/test-validation', function (TestValidationRequest $request) {
            $request->validated(); // ensure usage and triggers validation pipeline
            return response()->json(['success' => true]);
        });
    }

    public function test_validation_errors_are_formatted_as_jsonapi_by_base_error_handler()
    {
        $response = $this->postJson('/test-validation', [
            'email' => 'invalid-email',
            'name' => '',
        ]);

        $response->assertStatus(422);

        $data = $response->json();

        // Verify JSON:API structure
        $this->assertEquals('Validation Failed', $data['meta']['message']);
        $this->assertEquals(422, $data['meta']['status']);
        $this->assertArrayHasKey('errors', $data);

        // Verify error format
        foreach ($data['errors'] as $error) {
            $this->assertEquals('422', $error['status']);
            $this->assertEquals('Validation Error', $error['title']);
            $this->assertArrayHasKey('detail', $error);
            $this->assertArrayHasKey('source', $error);
            $this->assertArrayHasKey('pointer', $error['source']);
        }
    }

    public function test_jsonapi_assertions_work_with_new_format()
    {
        $response = $this->postJson('/test-validation', []);

        $this->assertHasValidationErrors($response, ['email', 'name']);
    }

    public function test_successful_request_returns_normal_response()
    {
        $response = $this->postJson('/test-validation', [
            'email' => 'test@example.com',
            'name' => 'John Doe',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    public function test_validation_error_detail_format()
    {
        $response = $this->postJson('/test-validation', [
            'email' => 'invalid-email',
        ]);

        $response->assertStatus(422);
        $data = $response->json();

        // Find specific errors and verify format
        $emailErrors = array_filter($data['errors'], fn ($e) => $e['source']['pointer'] === '/data/attributes/email');
        $nameErrors = array_filter($data['errors'], fn ($e) => $e['source']['pointer'] === '/data/attributes/name');

        $this->assertNotEmpty($emailErrors);
        $this->assertNotEmpty($nameErrors);

        // Check email validation error
        $emailError = array_values($emailErrors)[0];
        $this->assertStringContainsString('email:', $emailError['detail']);
        $this->assertStringContainsString('valid email', $emailError['detail']);

        // Check name validation error
        $nameError = array_values($nameErrors)[0];
        $this->assertStringContainsString('name:', $nameError['detail']);
        $this->assertStringContainsString('required', $nameError['detail']);
    }

    public function test_multiple_validation_rules_per_field()
    {
        $response = $this->postJson('/test-validation', [
            'email' => 'test@example.com',
            'name' => 'A', // Too short
        ]);

        $response->assertStatus(422);
        $data = $response->json();

        // Should have error for name minimum length
        $nameErrors = array_filter($data['errors'], fn ($e) => $e['source']['pointer'] === '/data/attributes/name');
        $this->assertNotEmpty($nameErrors);

        $nameError = array_values($nameErrors)[0];
        $this->assertStringContainsString('at least 2 characters', $nameError['detail']);
    }

    public function test_no_custom_response_in_validation_exception()
    {
        // This test verifies that JsonApiValidation trait doesn't set a custom response,
        // allowing BaseErrorHandler to format the response
        $request = new TestValidationRequest;

        // Use reflection to verify failedValidation behavior
        $reflection = new \ReflectionClass($request);
        $method = $reflection->getMethod('failedValidation');
        $method->setAccessible(true);

        $validator = \Illuminate\Support\Facades\Validator::make([], ['email' => 'required']);

        try {
            $method->invoke($request, $validator);
            $this->fail('Expected ValidationException was not thrown');
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Verify no custom response is set (BaseErrorHandler will handle formatting)
            $this->assertNull($e->response);
        }
    }
}

// Test FormRequest class
class TestValidationRequest extends FormRequest
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
        ];
    }
}
