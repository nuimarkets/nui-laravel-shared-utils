<?php

namespace Nuimarkets\LaravelSharedUtils\Exceptions;


use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Base Error Handler
 */
class BaseErrorHandler extends ExceptionHandler
{
    /*
     * * A list of the exception types that should not be reported.
     * Note: The base Handler defines the common ones in $internalDontReport
     * However we override to ensure anything over 500 is reported
     */
    protected $dontReport = [];

    public function shouldReport(Throwable $e): bool
    {

        // For HTTP exceptions, only report >= 500 status codes
        if ($e instanceof HttpException) {
            return $e->getStatusCode() >= 500;
        }

        return parent::shouldReport($e);

    }

    protected function getFormattedError(Throwable $e): array
    {
        $title = null;
        $status = Response::HTTP_INTERNAL_SERVER_ERROR;
        $errors = [];

        // Handle different types of exceptions
        if ($e instanceof ValidationException) {
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            $title = "Validation Failed";
            $errors = $e->errors();
        } elseif ($e instanceof ModelNotFoundException) {
            $status = Response::HTTP_NOT_FOUND;
        } elseif ($e instanceof AuthorizationException) {
            $status = Response::HTTP_FORBIDDEN;
        } elseif ($e instanceof BaseHttpRequestException) {
            $status = $e->getStatusCode();
            $error = [
                'code' => (string) $e->getStatusCode(),
                'detail' => $e->getMessage(),
            ];


            if ($this->shouldShowDebugInfo()) {
                if ($e->getPrevious()) {
                    $error['previous'] = $this->getExceptionTrace($e->getPrevious());
                }

                if (!empty($e->getTags())) {
                    $error['tags'] = $e->getTags();
                }

                if (!empty($e->getExtra())) {
                    $error['extra'] = $e->getExtra();
                }

            }

            $errors = [$error];

        } elseif ($e instanceof HttpException) {
            $status = $e->getStatusCode();
        }

        $title ??= Response::$statusTexts[$status];

        if (empty($errors)) {
            $errors[] = [
                'status' => $status,
                'title' => $title,
            ];
        }

        if ($this->shouldShowDebugInfo() && !($e instanceof ValidationException)) {
            $errors[] = $this->getExceptionTrace($e);
        }

        return [
            'status' => $status,
            'title' => $title,
            'errors' => $errors,
        ];
    }

    protected function shouldShowDebugInfo(): bool
    {
        return config('app.debug') && !app()->runningUnitTests();
    }

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

    public function report(Throwable $e): void
    {
        if (!$this->shouldReport($e)) {
            return;
        }

        $errorData = $this->getFormattedError($e);

        // For reportable exceptions
        Log::error(
            class_basename($e),
            [
                ...$errorData['errors'],
                'exception' => $e,
            ]
        );

        parent::report($e);
    }

    public function render($request, Throwable $e): JsonResponse
    {
        $errorData = $this->getFormattedError($e);

        // Always log non-reported exceptions as INFO
        if (!$this->shouldReport($e)) {
            Log::info(class_basename($e), $errorData['errors']);
        }

        return new JsonResponse([
            'meta' => [
                'message' => $errorData['title'],
                'status' => $errorData['status'],
            ],
            'errors' => $errorData['errors'],
        ], $errorData['status']);
    }
}
