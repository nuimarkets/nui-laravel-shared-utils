<?php

namespace NuiMarkets\LaravelSharedUtils\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Base Error Handler
 */
class BaseErrorHandler extends ExceptionHandler
{
    /**
     * A list of the exception types that should not be reported.
     * We override to ensure HTTP ≥ 500 still get reported.
     */
    protected $dontReport = [];

    public function shouldReport(Throwable $e): bool
    {
        // Don't report expected client-side errors
        if ($e instanceof ValidationException) {
            return false;
        }
        // For HTTP exceptions, only report >= 500
        if ($e instanceof HttpException) {
            return $e->getStatusCode() >= 500;
        }

        return parent::shouldReport($e);
    }

    /**
     * Build a JSON-API–style error array for every exception.
     * Now includes mapping of certain QueryExceptions to 400.
     */
    protected function getFormattedError(Throwable $e): array
    {
        $title = null;
        $status = Response::HTTP_INTERNAL_SERVER_ERROR;
        $errors = [];

        //
        // 1) HANDLE “invalid UUID” (SQLSTATE 22P02) AS 400 BAD REQUEST
        //
        if ($e instanceof QueryException && $this->isInvalidUuidError($e)) {
            $status = Response::HTTP_BAD_REQUEST;         // 400
            $title = Response::$statusTexts[$status];    // “Bad Request”
            $detail = 'Invalid UUID format.';

            $errors[] = [
                'status' => (string) $status,
                'title' => $title,
                'detail' => $detail,
            ];

            return [
                'status' => $status,
                'title' => $title,
                'errors' => $errors,
            ];
        }

        //
        // 2) VALIDATION, MODEL- NOT- FOUND, AUTHORIZATION, & CUSTOM HTTP EXCEPTIONS
        //
        if ($e instanceof ValidationException) {
            $status = Response::HTTP_UNPROCESSABLE_ENTITY; // 422
            $title = 'Validation Failed';

            // Always use JSON:API format (removed config checks and legacy format support)
            // Build one error object per field+message:
            // [
            //   {
            //     'status': '422',
            //     'title': 'Validation Error',
            //     'detail': 'field1: The field1 is required.',
            //     'source': { 'pointer': '/data/attributes/field1' }
            //   },
            //   …
            // ]
            //
            // In JSON:API's design, each error object is meant to be a fully self-contained description of a single problem.
            // The official JSON:API spec (§ 4.0, "Error Objects") explicitly states:
            //
            // "The status member … MUST be a string … containing the HTTP status code applicable to this problem."
            //
            // "The title member … a short, human-readable summary …."
            //
            // Because the spec says each error object "MUST" have status (string) and may have title, the common approach is to repeat them on every item.

            // $e->errors() returns an array: field → [messages…]
            // We'll wrap that direct array into our "errors" slot.
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $msg) {
                    $errors[] = [
                        'status' => (string) $status,
                        'title' => 'Validation Error',
                        'detail' => "$field: $msg",
                        'source' => ['pointer' => $this->pointerForField($field)],
                    ];
                }
            }
        } elseif ($e instanceof ModelNotFoundException) {
            $status = Response::HTTP_NOT_FOUND;            // 404
        } elseif ($e instanceof AuthorizationException) {
            $status = Response::HTTP_FORBIDDEN;            // 403
        } elseif ($e instanceof BaseHttpRequestException) {
            // Your custom exception that already has a “statusCode()” method
            $status = $e->getStatusCode();
            $error = [
                'status' => (string) $status,
                'title' => Response::$statusTexts[$status] ?? 'Error',
                'detail' => $e->getMessage(),
            ];

            if ($this->shouldShowDebugInfo()) {
                if ($e->getPrevious()) {
                    $error['previous'] = $this->getExceptionTrace($e->getPrevious());
                }
                if (! empty($e->getTags())) {
                    $error['tags'] = $e->getTags();
                }
                if (! empty($e->getExtra())) {
                    $error['extra'] = $e->getExtra();
                }
            }

            $errors[] = $error;
        } elseif ($e instanceof HttpException) {
            $status = $e->getStatusCode();
            if (empty($errors) && ($msg = trim((string) $e->getMessage())) !== '') {
                $errors[] = [
                    'status' => (string) $status,
                    'title' => Response::$statusTexts[$status] ?? 'Error',
                    'detail' => $msg,
                ];
            }
        }

        //
        // 3) DEFAULT TITLE IF NONE SET
        //
        $title ??= Response::$statusTexts[$status];

        //
        // 4) IF NO “errors” YET (i.e. not ValidationException or BaseHttpRequestException),
        //    CREATE A GENERIC ERROR OBJECT
        //
        if (empty($errors)) {
            $errors[] = [
                'status' => (string) $status,
                'title' => $title,
            ];
        }

        //
        // 5) ADD DEBUG INFO (STACK TRACE) IF APP IS IN DEBUG & NOT UNIT TESTING
        //
        if ($this->shouldShowDebugInfo() && ! ($e instanceof ValidationException)) {
            $errors[] = $this->getExceptionTrace($e);
        }

        return [
            'status' => $status,
            'title' => $title,
            'errors' => $errors,
        ];
    }

    /**
     * When app.debug=true (and not in PHPUnit), show stack traces.
     */
    protected function shouldShowDebugInfo(): bool
    {
        return config('app.debug') && ! app()->runningUnitTests();
    }

    /**
     * Return a minimal trace object for JSON output.
     */
    protected function getExceptionTrace(\Throwable $e): array
    {
        return [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'detail' => $e->getMessage(),
            'name' => get_class($e),
            'stack' => array_slice($e->getTrace(), 0, 3),
        ];
    }

    /**
     * Report exceptions if they meet shouldReport() logic.
     */
    public function report(Throwable $e): void
    {
        if (! $this->shouldReport($e)) {
            return;
        }

        $errorData = $this->getFormattedError($e);

        Log::error(
            class_basename($e),
            [
                'errors' => $errorData['errors'],
                'exception' => $e,
            ],
        );

        parent::report($e);
    }

    /**
     * Render into JSON-API response.
     */
    public function render($request, Throwable $e): Response|JsonResponse
    {
        $errorData = $this->getFormattedError($e);

        if (! $this->shouldReport($e)) {
            Log::info(
                class_basename($e),
                [
                    'errors' => $errorData['errors'],
                    'exception' => $e,
                ],
            );
        }

        $headers = [];
        if (str_contains((string) $request->header('Accept', ''), 'application/vnd.api+json')) {
            $headers['Content-Type'] = 'application/vnd.api+json';
        }

        return new JsonResponse([
            'meta' => [
                'message' => $errorData['title'],
                'status' => $errorData['status'],
            ],
            'errors' => $errorData['errors'],
        ], $errorData['status'], $headers);
    }

    /**
     * Generate JSON:API source pointer for a validation field.
     *
     * Current behavior uses Laravel dot-notation for backward compatibility.
     * To switch to JSON Pointer standard later, replace dots with slashes and escape ~ and /.
     */
    protected function pointerForField(string $field): string
    {
        return "/data/attributes/{$field}";
    }

    /**
     * Utility: check if a QueryException's SQLSTATE is "22P02" (invalid UUID).
     */
    protected function isInvalidUuidError(QueryException $e): bool
    {
        // $e->errorInfo[0] is the SQLSTATE code (e.g. "22P02")
        $sqlState = $e->errorInfo[0] ?? null;

        return $sqlState === '22P02';
    }
}
