<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Services\Sakurupiah\SakurupiahChannelPolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;

final class UpdateSakurupiahChannelRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    public function authorize(): bool
    {
        return $this->attributes->get('adminUser')?->role === UserRole::Owner;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'sakurupiah_channel' => ['required', 'string', Rule::in(SakurupiahChannelPolicy::CHANNELS)],
            'payment_provider_version' => ['required', 'integer', 'min:0'],
        ];
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return redirect()->route('admin.dashboard', ['tab' => 'settings', 'section' => 'pembayaran'])
            ->withErrors($validator, 'sakurupiahChannel')->withInput();
    }
}
