<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Models\StoreBalanceFundingPolicy;
use App\Services\StoreBalance\StoreBalanceFundingPolicyDefinition;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Validator as ValidationValidator;

class UpdateStoreBalanceFundingPolicyRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    public function authorize(): bool
    {
        $user = $this->attributes->get('adminUser');

        return (bool) $user?->role?->canManageStoreOperations();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'minimum_amount' => ['required'],
            'preset_amounts' => ['required', 'array', 'min:1', 'max:'.StoreBalanceFundingPolicy::MAX_PRESETS],
            'preset_amounts.*' => ['required'],
            'version' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'minimum_amount.required' => 'Minimum top-up wajib diisi.',
            'preset_amounts.required' => 'Daftar preset top-up wajib diisi.',
            'preset_amounts.array' => 'Daftar preset top-up tidak valid.',
            'preset_amounts.min' => 'Daftar preset top-up harus memiliki sedikitnya satu nominal.',
            'preset_amounts.max' => 'Daftar preset top-up maksimal '.StoreBalanceFundingPolicy::MAX_PRESETS.' nominal.',
            'preset_amounts.*.required' => 'Setiap baris preset top-up wajib diisi.',
            'version.required' => 'Muat ulang dashboard sebelum mengubah kebijakan top-up.',
            'version.integer' => 'Versi kebijakan top-up tidak valid.',
            'version.min' => 'Versi kebijakan top-up tidak valid.',
        ];
    }

    /** @return array<int, callable(ValidationValidator): void> */
    public function after(): array
    {
        return [
            function (ValidationValidator $validator): void {
                foreach (StoreBalanceFundingPolicyDefinition::validationErrors(
                    $this->input('minimum_amount'),
                    $this->input('preset_amounts'),
                ) as $key => $message) {
                    $validator->errors()->add($key, $message);
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $minimum = $this->input('minimum_amount');
        $presets = $this->input('preset_amounts');

        if (is_string($presets)) {
            $presets = trim($presets) === ''
                ? []
                : preg_split('/\R/', trim($presets));
        } elseif (is_array($presets)) {
            $presets = array_map(
                static fn (mixed $amount): mixed => is_string($amount) ? trim($amount) : $amount,
                $presets,
            );
        }

        $this->merge([
            'minimum_amount' => is_string($minimum) ? trim($minimum) : $minimum,
            'preset_amounts' => $presets,
        ]);
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return redirect()
            ->route('admin.dashboard', ['tab' => 'stats'])
            ->withErrors($validator, 'fundingPolicy')
            ->withInput();
    }
}
