<?php

namespace App\Http\Requests\Admin\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

trait RedirectsWithValidationErrors
{
    protected function failedValidation(Validator $validator): void
    {
        $exception = new ValidationException($validator);
        if ($this->expectsJson()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Periksa kembali isian yang ditandai.',
                'errors' => $validator->errors()->toArray(),
            ], 422)->header('Cache-Control', 'no-store, private'));
        }

        $exception->response = $this->validationErrorRedirect($validator);

        throw $exception;
    }

    abstract protected function validationErrorRedirect(Validator $validator): RedirectResponse;
}
