<?php

namespace App\Http\Requests;

use App\Models\ManualPaymentSetting;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Storefront\StorefrontLocale;
use App\Storefront\StorefrontUrl;
use App\Support\PaymentProvider;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StorefrontShopCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $requiresSakurupiahPhone = $this->requiresSakurupiahPhone();
        $supportChannelRules = ['nullable', 'string', 'required_with:support_contact_value', Rule::in(
            $requiresSakurupiahPhone ? ['whatsapp'] : ['telegram', 'whatsapp'],
        )];
        $supportValueRules = ['nullable', 'string', 'max:120', 'required_with:support_contact_channel'];
        if ($requiresSakurupiahPhone) {
            array_unshift($supportChannelRules, 'required');
            array_unshift($supportValueRules, 'required');
            $supportValueRules[] = 'regex:/^\+?[0-9][0-9 .-]{7,19}$/';
        }

        return [
            'checkout_attempt' => ['required', 'uuid'],
            'product_id' => [
                'nullable',
                'integer',
                'min:1',
                'required_without:cart_snapshot',
                'prohibits:cart_snapshot',
            ],
            'quantity' => [
                'nullable',
                'integer',
                'min:1',
                'max:20',
                'required_with:product_id',
            ],
            'buyer_name' => ['required', 'string', 'min:2', 'max:40', 'regex:/^[A-Za-z0-9_ .-]+$/'],
            'terms_accepted' => ['required', 'accepted'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:254'],
            'support_contact_channel' => $supportChannelRules,
            'support_contact_value' => $supportValueRules,
            'payment_method' => ['nullable', Rule::in(CheckoutPaymentIntents::acceptedPaymentRails())],
            'cart_snapshot' => [
                'nullable',
                'string',
                'max:64',
                'required_without:product_id',
                'prohibits:product_id',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('product_id') && $this->filled('quantity')) {
                $validator->errors()->add(
                    'quantity',
                    StorefrontLocale::text('quantity_direct_only'),
                );
            }
        });
    }

    /**
     * Flash only checkout fields that are safe to restore on the canonical review page.
     *
     * @return array<string, mixed>
     */
    public function checkoutRecoveryInput(): array
    {
        $input = $this->only([
            'checkout_attempt',
            'product_id',
            'quantity',
            'buyer_name',
            'email',
            'support_contact_channel',
            'support_contact_value',
            'payment_method',
            'cart_snapshot',
            'terms_accepted',
        ]);
        if (! is_string($input['checkout_attempt'] ?? null) || ! Str::isUuid($input['checkout_attempt'])) {
            unset($input['checkout_attempt']);
        }

        return $input;
    }

    protected function failedValidation(Validator $validator): void
    {
        $response = redirect()
            ->to($this->getRedirectUrl())
            ->withInput($this->checkoutRecoveryInput())
            ->withErrors($validator, $this->errorBag);

        throw new ValidationException($validator, $response, $this->errorBag);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'checkout_attempt.*' => StorefrontLocale::text('reopen_checkout'),
            'product_id.required' => StorefrontLocale::text('choose_product_purchase'),
            'product_id.required_without' => StorefrontLocale::text('choose_product_or_cart'),
            'product_id.prohibits' => StorefrontLocale::text('choose_either'),
            'product_id.*' => StorefrontLocale::text('choose_product_purchase'),
            'quantity.*' => StorefrontLocale::text('invalid_quantity'),
            'buyer_name.required' => StorefrontLocale::text('buyer_required'),
            'buyer_name.regex' => StorefrontLocale::text('buyer_invalid'),
            'buyer_name.string' => StorefrontLocale::text('buyer_invalid'),
            'buyer_name.min' => StorefrontLocale::text('buyer_length'),
            'buyer_name.max' => StorefrontLocale::text('buyer_length'),
            'terms_accepted.required' => StorefrontLocale::text('agree_required'),
            'terms_accepted.accepted' => StorefrontLocale::text('agree_required'),
            'email.email' => StorefrontLocale::text('email_invalid'),
            'email.max' => StorefrontLocale::text('email_max'),
            'email.string' => StorefrontLocale::text('email_invalid'),
            'support_contact_channel.*' => StorefrontLocale::text('choose_support_channel'),
            'support_contact_channel.required' => StorefrontLocale::text('choose_sakurupiah_whatsapp'),
            'support_contact_channel.required_with' => StorefrontLocale::text('choose_or_clear_channel'),
            'support_contact_value.required_with' => StorefrontLocale::text('enter_or_clear_contact'),
            'support_contact_value.required' => StorefrontLocale::text('enter_sakurupiah_whatsapp'),
            'support_contact_value.regex' => StorefrontLocale::text('valid_sakurupiah_whatsapp'),
            'support_contact_value.*' => StorefrontLocale::text('contact_invalid'),
            'payment_method.*' => StorefrontLocale::text('available_payment_method'),
            'cart_snapshot.required_without' => StorefrontLocale::text('cart_or_one_product'),
            'cart_snapshot.prohibits' => StorefrontLocale::text('cart_or_one_only'),
            'cart_snapshot.*' => StorefrontLocale::text('reopen_checkout'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $channel = $this->input('support_contact_channel');
        $value = $this->input('support_contact_value');
        $buyerName = $this->input('buyer_name', $this->input('buyer_handle'));
        $email = $this->input('email');

        $this->merge([
            'buyer_name' => is_string($buyerName) ? trim($buyerName) : $buyerName,
            'support_contact_channel' => is_string($channel) && trim($channel) !== '' ? trim($channel) : null,
            'support_contact_value' => is_string($value) && trim($value) !== '' ? trim($value) : null,
            'email' => is_string($email) && trim($email) !== '' ? mb_strtolower(trim($email)) : null,
        ]);
    }

    private function requiresSakurupiahPhone(): bool
    {
        return ManualPaymentSetting::current()?->paymentProvider() === PaymentProvider::SAKURUPIAH
            && CheckoutPaymentIntents::normalizePaymentRail($this->input('payment_method')) === CheckoutPaymentIntents::RAIL_QRIS;
    }

    protected function getRedirectUrl(): string
    {
        return $this->checkoutRecoveryUrl();
    }

    public function checkoutRecoveryUrl(): string
    {
        if (! $this->filled('product_id')) {
            return route('storefront.shop.checkout.start');
        }

        $from = $this->query('from');

        return app(StorefrontUrl::class)->checkoutRecoveryUrl(
            $this->input('product_id'),
            is_string($from) ? $from : null,
        );
    }
}
