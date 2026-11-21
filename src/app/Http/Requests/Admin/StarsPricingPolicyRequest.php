<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Models\User;
use App\Services\TelegramStars\StarsPricingPolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;

final class StarsPricingPolicyRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    public function authorize(): bool
    {
        $actor = $this->attributes->get('adminUser');

        return $actor instanceof User && $actor->role?->canManageStarsPricing() === true;
    }

    public function rules(): array
    {
        $rules = [
            'idr_per_usd' => ['required', 'integer:strict', 'min:1'],
            'reward_micro_usd' => ['required', 'integer:strict', 'min:1'],
            'reserve_bps' => ['required', 'integer:strict', 'between:0,'.StarsPricingPolicy::MAX_RESERVE_BPS],
            'issuance_enabled' => ['required', 'boolean'],
            'version' => ['required', 'integer:strict', 'min:0'],
        ];

        if ($this->routeIs('admin.stars-pricing.preview')) {
            $rules['total_idr'] = ['required', 'integer:strict', 'min:1'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'idr_per_usd' => 'kurs IDR per USD',
            'reward_micro_usd' => 'acuan reward micro-USD',
            'reserve_bps' => 'cadangan',
            'issuance_enabled' => 'izin penerbitan',
            'version' => 'versi kebijakan',
            'total_idr' => 'total retail IDR',
        ];
    }

    public function proposal(): StarsPricingPolicy
    {
        $data = $this->validated();

        return new StarsPricingPolicy(
            $data['idr_per_usd'],
            $data['reward_micro_usd'],
            $data['reserve_bps'],
            (bool) $data['issuance_enabled'],
            $data['version'],
        );
    }

    protected function prepareForValidation(): void
    {
        foreach (['idr_per_usd', 'reward_micro_usd', 'reserve_bps', 'version', 'total_idr'] as $field) {
            $value = $this->input($field);
            // HTML forms send strings. Reject fractions, JSON booleans,
            // scientific notation and integers outside the native range.
            if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', $value) === 1) {
                $integer = filter_var($value, FILTER_VALIDATE_INT);
                if ($integer !== false) {
                    $this->merge([$field => $integer]);
                }
            }
        }
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return to_route('admin.stars-pricing.show')
            ->withErrors($validator)
            ->withInput($this->only(['idr_per_usd', 'reward_micro_usd', 'reserve_bps', 'version', 'issuance_enabled', 'total_idr']));
    }
}
