<?php

namespace App\Http\Requests\Admin\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

trait RedirectsWithValidationErrors
{
    protected function failedValidation(Validator $validator): void
    {
        $exception = new ValidationException($validator);
        $exception->response = $this->validationErrorRedirect($validator);

        throw $exception;
    }

    abstract protected function validationErrorRedirect(Validator $validator): RedirectResponse;
}
