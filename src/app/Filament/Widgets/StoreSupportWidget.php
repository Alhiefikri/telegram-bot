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
        'lg' => 1,
    ];

    protected function getViewData(): array
    {
        $setting = ManualPaymentSetting::current();

        return [
            'setting' => $setting,
            'support' => [
                'contact_url' => $setting?->admin_contact_url,
                'username' => $setting?->admin_username,
                'chat_id' => $setting?->admin_telegram_chat_id,
                'payment_mode' => $setting?->payment_mode ?? 'belum diatur',
                'instructions' => $setting?->instructions,
            ],
            'actions' => [
                [
                    'label' => 'Lihat Pesanan',
                    'url' => OrderResource::getUrl('index'),
                ],
                [
                    'label' => 'Tambah Produk',
                    'url' => ProductResource::getUrl('create'),
                ],
                [
                    'label' => 'Cek Pembayaran',
                    'url' => PaymentResource::getUrl('index'),
                ],
                [
                    'label' => $setting ? 'Atur Pembayaran' : 'Buat Pengaturan Pembayaran',
                    'url' => $setting ? ManualPaymentSettingResource::getUrl('index') : ManualPaymentSettingResource::getUrl('create'),
                ],
            ],
        ];
    }
}
