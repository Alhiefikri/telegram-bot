<?php

namespace App\Http\Requests\Admin\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

trait RedirectsWithValidationErrors
{
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator, $this->validationErrorRedirect($validator));
    }

    abstract protected function validationErrorRedirect(Validator $validator): RedirectResponse;
}
