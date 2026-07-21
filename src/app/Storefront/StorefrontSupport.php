<?php

namespace App\Storefront;

use App\Models\Order;
use App\Models\Product;

class StorefrontSupport
{
    /**
     * @return array{title:string, body:string, subject:array{product_id:int|string}, categories:list<array{key:string, label:string, options:list<array{key:string, label:string, description:string, message:string, href:?string, available:bool}>}>}
     */
    public function prePurchase(Product $product): array
    {
        return $this->context(
            title: 'Butuh bantuan?',
            body: 'Tanya ketersediaan, kecocokan produk, atau detail checkout sebelum membeli.',
            subject: ['product_id' => $product->getKey()],
            categories: [
                [
                    'key' => 'pre_purchase',
                    'label' => 'Tanya produk sebelum beli',
                    'message' => implode("\n", [
                        'Halo Admin Lhie Store, saya mau tanya sebelum membeli.',
                        'Produk: '.$this->safeLine($product->name),
                        'URL produk: '.route('storefront.products.show', $product),
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
            title: 'Butuh bantuan?',
            body: 'Pilih kanal support sebelum membeli, saat checkout, atau saat butuh arahan pesanan.',
            subject: [],
            categories: [
                [
                    'key' => 'general',
                    'label' => 'Tanya admin Lhie Store',
                    'message' => 'Halo Admin Lhie Store, saya butuh bantuan di storefront.',
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
            title: 'Butuh bantuan?',
            body: 'Pilih kategori masalah. Pesan hanya membawa nomor invoice dan konteks masalah, tanpa Recovery Key atau data kredensial. Support tidak dapat membuka kredensial dari invoice saja.',
            subject: ['order_id' => $order->getKey()],
            categories: [
                [
                    'key' => 'payment_pending',
                    'label' => 'QRIS / pembayaran belum terdeteksi',
                    'message' => $this->orderMessage($order, 'QRIS / pembayaran belum terdeteksi'),
                ],
                [
                    'key' => 'payment_final',
                    'label' => 'Pembayaran gagal, expired, atau dibatalkan',
                    'message' => $this->orderMessage($order, 'Pembayaran gagal, expired, atau dibatalkan'),
                ],
                [
                    'key' => 'credentials_missing',
                    'label' => 'Data akun belum muncul',
                    'message' => $this->orderMessage($order, 'Data akun belum muncul'),
                ],
                [
                    'key' => 'credentials_invalid',
                    'label' => 'Kredensial tidak valid',
                    'message' => $this->orderMessage($order, 'Kredensial tidak valid'),
                ],
                [
                    'key' => 'mfa_problem',
                    'label' => 'MFA / Authenticator bermasalah',
                    'message' => $this->orderMessage($order, 'MFA / Authenticator bermasalah'),
                ],
                [
                    'key' => 'login_failed',
                    'label' => 'Login gagal',
                    'message' => $this->orderMessage($order, 'Login gagal'),
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
            'label' => 'WhatsApp Support',
            'description' => $baseUrl ? 'Buka chat WhatsApp dengan pesan siap kirim.' : 'Nomor WhatsApp belum dikonfigurasi.',
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
            'label' => 'Telegram Admin',
            'description' => 'Buka chat admin manusia, bukan Telegram Bot. Template pesan juga tersedia bila aplikasi Telegram tidak mengisi otomatis.',
            'message' => $message,
            'href' => $baseUrl ? $this->appendText($baseUrl, 'text', $message) : null,
            'available' => $baseUrl !== null,
        ];
    }

    private function orderMessage(Order $order, string $category): string
    {
        return implode("\n", [
            'Halo Admin Lhie Store, saya butuh bantuan untuk pesanan website.',
            'Invoice: '.$this->safeLine($order->invoice_number),
            'Kategori masalah: '.$category,
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
        $configuredUrl = trim((string) config('services.support.whatsapp_url'));
        if ($configuredUrl !== '') {
            return $configuredUrl;
        }

        $number = preg_replace('/\D+/', '', (string) config('services.support.whatsapp_number'));

        return $number ? 'https://wa.me/'.$number : null;
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
