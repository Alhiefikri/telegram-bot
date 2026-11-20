<?php

namespace App\Storefront;

use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Product;

class StorefrontSupport
{
    private bool $whatsappBaseUrlResolved = false;

    private ?string $whatsappBaseUrl = null;

    /**
     * @return array{title:string, body:string, subject:array{product_id:int|string}, categories:list<array{key:string, label:string, options:list<array{key:string, label:string, description:string, message:string, href:?string, available:bool}>}>}
     */
    public function prePurchase(Product $product): array
    {
        return $this->context(
            title: StorefrontLocale::text('need_help'),
            body: StorefrontLocale::text('pre_purchase_help'),
            subject: ['product_id' => $product->getKey()],
            categories: [
                [
                    'key' => 'pre_purchase',
                    'label' => StorefrontLocale::text('pre_purchase_issue'),
                    'message' => implode("\n", [
                        StorefrontLocale::text('pre_purchase_message'),
                        StorefrontLocale::text('support_product', ['name' => $this->safeLine($product->name)]),
                        StorefrontLocale::text('support_product_url', ['url' => route('storefront.products.show', $product)]),
                    ]),
                ],
            ],
        );
    }

    /**
     * @return array{title:string, body:string, subject:array{}, categories:list<array{key:string, label:string, options:list<array{key:string, label:string, description:string, message:string, href:?string, available:bool}>}>}
     */
    public function general(): array
    {
        return $this->context(
            title: StorefrontLocale::text('need_help'),
            body: StorefrontLocale::text('general_help'),
            subject: [],
            categories: [
                [
                    'key' => 'general',
                    'label' => StorefrontLocale::text('general_issue'),
                    'message' => StorefrontLocale::text('general_message'),
                ],
            ],
        );
    }

    /**
     * @return array{title:string, body:string, subject:array{order_id:int|string}, categories:list<array{key:string, label:string, options:list<array{key:string, label:string, description:string, message:string, href:?string, available:bool}>}>}
     */
    public function order(Order $order): array
    {
        return $this->context(
            title: StorefrontLocale::text('need_help'),
            body: StorefrontLocale::text('order_help'),
            subject: ['order_id' => $order->getKey()],
            categories: [
                [
                    'key' => 'payment_pending',
                    'label' => StorefrontLocale::text('payment_pending_issue'),
                    'message' => $this->orderMessage($order, StorefrontLocale::text('payment_pending_issue')),
                ],
                [
                    'key' => 'payment_final',
                    'label' => StorefrontLocale::text('payment_final_issue'),
                    'message' => $this->orderMessage($order, StorefrontLocale::text('payment_final_issue')),
                ],
                [
                    'key' => 'credentials_missing',
                    'label' => StorefrontLocale::text('credentials_missing_issue'),
                    'message' => $this->orderMessage($order, StorefrontLocale::text('credentials_missing_issue')),
                ],
                [
                    'key' => 'credentials_invalid',
                    'label' => StorefrontLocale::text('credentials_invalid_issue'),
                    'message' => $this->orderMessage($order, StorefrontLocale::text('credentials_invalid_issue')),
                ],
                [
                    'key' => 'mfa_problem',
                    'label' => StorefrontLocale::text('mfa_issue'),
                    'message' => $this->orderMessage($order, StorefrontLocale::text('mfa_issue')),
                ],
                [
                    'key' => 'login_failed',
                    'label' => StorefrontLocale::text('login_issue'),
                    'message' => $this->orderMessage($order, StorefrontLocale::text('login_issue')),
                ],
            ],
        );
    }

    /**
     * @return array{key:string, label:string, description:string, message:string, href:?string, available:bool}
     */
    public function whatsappOption(string $message): array
    {
        $baseUrl = $this->whatsappBaseUrl();

        return [
            'key' => 'whatsapp',
            'label' => StorefrontLocale::text('whatsapp_support'),
            'description' => $baseUrl ? StorefrontLocale::text('whatsapp_description') : StorefrontLocale::text('whatsapp_unconfigured'),
            'message' => $message,
            'href' => $baseUrl ? $this->appendText($baseUrl, 'text', $message) : null,
            'available' => $baseUrl !== null,
        ];
    }

    /**
     * @return array{key:string, label:string, description:string, message:string, href:?string, available:bool}
     */
    public function telegramAdminOption(string $message): array
    {
        $baseUrl = $this->telegramAdminBaseUrl();

        return [
            'key' => 'telegram_admin',
            'label' => StorefrontLocale::text('telegram_admin'),
            'description' => StorefrontLocale::text('telegram_description'),
            'message' => $message,
            'href' => $baseUrl ? $this->appendText($baseUrl, 'text', $message) : null,
            'available' => $baseUrl !== null,
        ];
    }

    private function orderMessage(Order $order, string $category): string
    {
        return implode("\n", [
            StorefrontLocale::text('order_message'),
            StorefrontLocale::text('support_invoice', ['invoice' => $this->safeLine($order->invoice_number)]),
            StorefrontLocale::text('support_issue', ['issue' => $category]),
        ]);
    }

    /**
     * @param  list<array{key:string, label:string, message:string}>  $categories
     * @param  array<string, int|string>  $subject
     * @return array{title:string, body:string, subject:array<string, int|string>, categories:list<array{key:string, label:string, options:list<array{key:string, label:string, description:string, message:string, href:?string, available:bool}>}>}
     */
    private function context(string $title, string $body, array $subject, array $categories): array
    {
        return [
            'title' => $title,
            'body' => $body,
            'subject' => $subject,
            'categories' => array_map(fn (array $category): array => [
                'key' => $category['key'],
                'label' => $category['label'],
                'options' => [
                    $this->whatsappOption($category['message']),
                    $this->telegramAdminOption($category['message']),
                ],
            ], $categories),
        ];
    }

    private function whatsappBaseUrl(): ?string
    {
        if ($this->whatsappBaseUrlResolved) {
            return $this->whatsappBaseUrl;
        }

        $this->whatsappBaseUrlResolved = true;

        $settingUrl = ManualPaymentSetting::current()?->whatsappSupportUrl();
        if ($settingUrl) {
            return $this->whatsappBaseUrl = $settingUrl;
        }

        $configuredUrl = trim((string) config('services.support.whatsapp_url'));
        if ($configuredUrl !== '') {
            return $this->whatsappBaseUrl = $configuredUrl;
        }

        $number = ManualPaymentSetting::normalizeWhatsappNumber((string) config('services.support.whatsapp_number'));

        return $this->whatsappBaseUrl = ($number ? 'https://wa.me/'.$number : null);
    }

    private function telegramAdminBaseUrl(): ?string
    {
        $configuredUrl = trim((string) (config('services.support.telegram_admin_url') ?: config('services.telegram.admin_contact_url')));
        if ($configuredUrl !== '') {
            return $configuredUrl;
        }

        $username = ltrim(trim((string) (config('services.support.telegram_admin_username') ?: config('services.telegram.admin_username'))), '@');

        return $username !== '' ? 'https://t.me/'.$username : null;
    }

    private function appendText(string $url, string $parameter, string $message): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').$parameter.'='.rawurlencode($message);
    }

    private function safeLine(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', trim($value));
    }
}
