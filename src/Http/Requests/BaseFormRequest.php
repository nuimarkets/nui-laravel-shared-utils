<?php

namespace Nuimarkets\LaravelSharedUtils\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Base Form Request - logging & error handling bits
 *
 */
abstract class BaseFormRequest extends FormRequest
{
    protected $redirect = false;

    /**
     * Validates and logs the request data.
     *
     * IMPORTANT: This method logs raw request data and MUST be used in conjunction with
     * Nuimarkets\LaravelSharedUtils\Logging\SensitiveDataProcessor
     * to ensure sensitive data is properly redacted in logs.
     * The processor must be configured in the logging stack
     *
     * @see Nuimarkets\LaravelSharedUtils\Logging\SensitiveDataProcessor
     */
    public function validateResolved(): void
    {
        Log::debug(class_basename(get_class($this)) . ".rules()", [
            'data' => $this->all(),
            'headers' => $this->headers->all(),
            'route' => $this->method() . ' ' . $this->url(),
        ]);

        parent::validateResolved();
    }

    protected function failedValidation(Validator $validator)
    {
        Log::debug(class_basename(get_class($this)) . ".failedValidation");

        throw new HttpResponseException(new JsonResponse([
            'errors' => $validator->errors(),
        ], 422));
    }


    abstract public function authorize(): bool;

    abstract public function rules(): array;


}