<?php

namespace NuiMarkets\LaravelSharedUtils\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

trait JsonApiValidation
{
    /**
     * Handle a failed validation attempt.
     *
     * Throws a standard ValidationException without a custom response,
     * allowing BaseErrorHandler to format it as JSON:API.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function failedValidation(Validator $validator)
    {
        // Simply throw the exception without a custom response
        // BaseErrorHandler will catch and format it properly
        throw new ValidationException($validator);
    }
}
