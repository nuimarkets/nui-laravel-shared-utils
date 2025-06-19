<?php

namespace NuiMarkets\LaravelSharedUtils\Http\Controllers;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use NuiMarkets\LaravelSharedUtils\Exceptions\BaseHttpRequestException;
use NuiMarkets\LaravelSharedUtils\Http\Requests\BaseFormRequest;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Test exception handling by using /test-error?exception=
 */
class ErrorTestController extends Controller
{
    /**
     * Test (Error handling)
     *
     * @throws ValidationException
     * @throws BindingResolutionException
     */
    public function testError(Request $request): JsonResponse
    {
        // Test exception handling by using /test-error?exception=400

        $exceptionTest = $request->get('exception');

        switch ($exceptionTest) {
            case '500':
                throw new \Exception;
            case '400':
            case 'BadRequestHttpException':
                throw new BadRequestHttpException('Test BadRequestHttpException'); // Symfony
            case 'BaseHttpRequestException':
                throw new BaseHttpRequestException('Test BaseHttpRequestException'); // app one (default=400)
            case 'BadHttpRequestException500':
                throw new BaseHttpRequestException('Test BaseHttpRequestException (500)', 500);
            case 'BadHttpRequestExceptionPrevious':
                $exception = new \PDOException('testing 123');
                throw new BaseHttpRequestException(
                    'Test BaseHttpRequestException (Previous)',
                    500,
                    $exception,
                    tags: [
                        'test' => 'tag',
                    ],
                    extra: [
                        'misc' => 123,
                    ],
                );
            case '404':
                throw new NotFoundHttpException; // Symfony
            case '422':
            case 'ValidationException':
                throw ValidationException::withMessages([
                    'field' => ['msg blah blah required'],
                ]);
            case 'baseValidationException':

                $requestClass = new class extends BaseFormRequest
                {
                    public function rules(): array
                    {
                        return [
                            'testField' => 'required|string',
                            'optionalField' => 'string',
                        ];
                    }

                    public function authorize(): bool
                    {
                        return true;
                    }
                };

                $container = app();
                $formRequest = $container->make(get_class($requestClass));

                $formRequest->setContainer($container);

                $request->merge([
                    'optionalField' => 'some value',
                    // Deliberately missing required 'testField' to trigger validation
                ]);

                $formRequest->setValidator($container->make('validator'));

                $formRequest->setRouteResolver(function () use ($request) {
                    return $request->route();
                });

                $formRequest->validateResolved();
        }

        return new JsonResponse([]);
    }
}
