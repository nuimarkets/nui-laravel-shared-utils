<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use NuiMarkets\LaravelSharedUtils\Http\Requests\JsonApiValidation;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class JsonApiValidationTest extends TestCase
{
    /**
     * Helper method to invoke the protected failedValidation method using reflection.
     */
    private function invokeFailedValidation($request, $validator)
    {
        $reflection = new \ReflectionClass($request);
        $method = $reflection->getMethod('failedValidation');
        $method->setAccessible(true);

        return $method->invoke($request, $validator);
    }

    public function test_failed_validation_throws_validation_exception_without_custom_response()
    {
        $request = new class extends FormRequest
        {
            use JsonApiValidation;

            public function rules()
            {
                return ['name' => 'required'];
            }

            public function authorize()
            {
                return true;
            }
        };

        $validator = Validator::make([], ['name' => 'required']);

        $this->expectException(ValidationException::class);

        try {
            $this->invokeFailedValidation($request, $validator);
        } catch (ValidationException $e) {
            // Ensure no custom response is set
            $this->assertNull($e->response);
            // Message format varies between Laravel versions (8/9 vs 10+)
            $errors = $e->errors();
            $this->assertArrayHasKey('name', $errors);
            $this->assertStringContainsString('name', $errors['name'][0]);
            $this->assertStringContainsString('required', $errors['name'][0]);
            throw $e; // Re-throw for expectException
        }
    }

    public function test_failed_validation_preserves_validator_errors()
    {
        $request = new class extends FormRequest
        {
            use JsonApiValidation;

            public function rules()
            {
                return ['email' => 'required|email'];
            }

            public function authorize()
            {
                return true;
            }
        };

        $validator = Validator::make(['email' => 'invalid'], ['email' => 'required|email']);

        try {
            $this->invokeFailedValidation($request, $validator);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            // Verify that all validation errors are preserved
            $errors = $e->errors();
            $this->assertArrayHasKey('email', $errors);
            // Be flexible with validation message wording (varies between Laravel versions)
            $this->assertNotEmpty($errors['email']);
            $emailMessage = $errors['email'][0];
            $this->assertStringContainsString('email', strtolower($emailMessage));
            $this->assertStringContainsString('valid', strtolower($emailMessage));
        }
    }

    public function test_multiple_field_validation_errors_are_preserved()
    {
        $request = new class extends FormRequest
        {
            use JsonApiValidation;

            public function rules()
            {
                return [
                    'name' => 'required|min:2',
                    'email' => 'required|email',
                    'age' => 'required|integer|min:18',
                ];
            }

            public function authorize()
            {
                return true;
            }
        };

        $validator = Validator::make(
            ['name' => 'a', 'email' => 'invalid', 'age' => '10'],
            ['name' => 'required|min:2', 'email' => 'required|email', 'age' => 'required|integer|min:18']
        );

        try {
            $this->invokeFailedValidation($request, $validator);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $errors = $e->errors();

            // Verify all three fields have validation errors
            $this->assertArrayHasKey('name', $errors);
            $this->assertArrayHasKey('email', $errors);
            $this->assertArrayHasKey('age', $errors);

            // Verify specific error messages (flexible with wording across Laravel versions)
            $this->assertNotEmpty($errors['name']);
            $this->assertNotEmpty($errors['email']);
            $this->assertNotEmpty($errors['age']);

            // Check key content rather than exact wording
            $nameMessage = strtolower($errors['name'][0]);
            $emailMessage = strtolower($errors['email'][0]);
            $ageMessage = strtolower($errors['age'][0]);

            $this->assertStringContainsString('name', $nameMessage);
            $this->assertStringContainsString('2', $nameMessage); // minimum 2 characters

            $this->assertStringContainsString('email', $emailMessage);
            $this->assertStringContainsString('valid', $emailMessage);

            $this->assertStringContainsString('age', $ageMessage);
            $this->assertStringContainsString('18', $ageMessage); // minimum 18
        }
    }
}
