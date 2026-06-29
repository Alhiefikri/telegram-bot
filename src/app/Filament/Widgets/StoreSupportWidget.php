<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ManualPaymentSettings\ManualPaymentSettingResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\ManualPaymentSetting;
use Filament\Widgets\Widget;

class StoreSupportWidget extends Widget
{
    protected string $view = 'filament.widgets.store-support-widget';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 4,
    ];

    protected function getViewData(): array
    {
        $setting = ManualPaymentSetting::current();

        return [
            'setting' => $setting,
            'support' => [
                'status_label' => $setting ? 'Aktif' : 'Belum diatur',
                'status_tone' => $setting ? 'emerald' : 'amber',
                'contact_url' => $setting?->admin_contact_url,
                'username' => $setting?->admin_username,
                'chat_id' => $setting?->admin_telegram_chat_id,
                'payment_mode' => $setting?->payment_mode ?? 'belum diatur',
                'instructions' => $setting?->instructions,
                'instructions_label' => $setting?->instructions ? 'Catatan pembayaran' : 'Belum ada catatan',
            ],
            'actions' => [
                [
                    'label' => 'Lihat pesanan',
                    'description' => 'Buka daftar pesanan masuk',
                    'url' => OrderResource::getUrl('index'),
                    'icon' => 'orders',
                ],
                [
                    'label' => 'Kelola produk',
                    'description' => 'Lihat daftar produk aktif',
                    'url' => ProductResource::getUrl('index'),
                    'icon' => 'products',
                ],
                [
                    'label' => 'Cek pembayaran',
                    'description' => 'Pantau transaksi masuk',
                    'url' => PaymentResource::getUrl('index'),
                    'icon' => 'payment',
                ],
                [
                    'label' => $setting ? 'Atur pembayaran' : 'Buat pengaturan',
                    'description' => 'Sesuaikan kontak dan instruksi',
                    'url' => $setting ? ManualPaymentSettingResource::getUrl('index') : ManualPaymentSettingResource::getUrl('create'),
                    'icon' => 'settings',
                ],
            ],
        ];
    }
}
