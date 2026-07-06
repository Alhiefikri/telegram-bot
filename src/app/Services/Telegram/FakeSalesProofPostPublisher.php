<?php

namespace App\Services\Telegram;

use App\Models\Product;
use App\Models\StoreChannelSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class FakeSalesProofPostPublisher
{
    public const COOLDOWN_HOUR_OPTIONS = [2, 3, 4, 5];

    private const SHOPPING_BUTTON_URL = 'https://t.me/lhiestore_bot?start=from_channel';

    private const CACHE_LAST_SENT_AT = 'fake-sales-proof:last-sent-at';

    private const CACHE_NEXT_AVAILABLE_AT = 'fake-sales-proof:next-available-at';

    private const CACHE_INTERVAL_HOURS = 'fake-sales-proof:interval-hours';

    /** @var list<string> */
    private const BUYER_NAMES = [
        'Aditya',
        'Aisyah',
        'Bima',
        'Citra',
        'Daffa',
        'Dewi',
        'Dymas',
        'Fajar',
        'Farhan',
        'Gita',
        'Hafiz',
        'Intan',
        'Kevin',
        'Laras',
        'Nadia',
        'Raka',
        'Rizky',
        'Salsa',
        'Tasya',
        'Yudha',
    ];

    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly SalesProofPostFormatter $formatter,
        private readonly SalesProofPostNumbering $numbering,
    ) {}

    /**
     * @return array{can_send: bool, disabled_reason: string|null, last_sent_label: string, next_available_label: string, interval_label: string, interval_options_label: string, product_options: list<array{id: int, name: string, label: string}>, send_url: string}
     */
    public function dashboardState(StoreChannelSetting $setting, bool $ready, bool $canManage): array
    {
        $cooldown = $this->cooldownState();
        $productOptions = $this->productOptions();
        $hasProduct = $productOptions !== [];
        $disabledReason = match (true) {
            ! $canManage => 'Hanya owner/admin yang bisa mengirim info pembelian fake.',
            ! $ready => 'Setup channel belum ready. Jalankan Test Channel sampai berhasil dulu.',
            ! $setting->sales_proof_enabled => 'Aktifkan toggle Sales Proof Post dulu.',
            ! $hasProduct => 'Tidak ada produk aktif dengan stok tersedia untuk dipakai.',
            ! $cooldown['ready'] => 'Info pembelian fake masih cooldown acak. Bisa kirim lagi setelah '.$cooldown['next_available_label'].'.',
            default => null,
        };

        return [
            'can_send' => $disabledReason === null,
            'disabled_reason' => $disabledReason,
            'last_sent_label' => $cooldown['last_sent_label'],
            'next_available_label' => $cooldown['next_available_label'],
            'interval_label' => $cooldown['interval_label'],
            'interval_options_label' => $this->intervalOptionsLabel(),
            'product_options' => $productOptions,
            'send_url' => route('admin.store-channel.fake-sales-proof'),
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function publish(mixed $productId): array
    {
        $lock = Cache::lock('sales-proof-posts:publish', 60);
        if (! $lock->get()) {
            return ['success' => false, 'message' => 'Publikasi Sales Proof lain sedang berjalan. Coba lagi sebentar.'];
        }

        try {
            return $this->publishWhileLocked($productId);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function publishWhileLocked(mixed $rawProductId): array
    {
        $setting = StoreChannelSetting::current();
        $chatId = $setting->telegram_chat_id ?: $setting->channel_username;
        if (! $setting->sales_proof_enabled) {
            return ['success' => false, 'message' => 'Aktifkan Sales Proof Post sebelum mengirim info pembelian fake.'];
        }

        if (! $chatId) {
            return ['success' => false, 'message' => 'Store Channel belum punya chat ID/username. Jalankan Test Channel dulu.'];
        }

        $cooldown = $this->cooldownState();
        if (! $cooldown['ready']) {
            return ['success' => false, 'message' => 'Info pembelian fake terakhir masih dalam cooldown acak. Bisa kirim lagi setelah '.$cooldown['next_available_label'].'.'];
        }

        $productId = filter_var($rawProductId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($productId === false) {
            return ['success' => false, 'message' => 'Pilih produk aktif untuk info pembelian fake.'];
        }

        $product = $this->availableProducts()->whereKey($productId)->first();
        if (! $product) {
            return ['success' => false, 'message' => 'Produk pilihan tidak aktif atau stoknya tidak tersedia.'];
        }

        try {
            $response = $this->bot->sendMessage(
                $chatId,
                $this->formatter->formatFake($this->randomBuyerMask(), $product->name, 1, $this->fakeOrderNumber()),
                $this->shoppingButtonMarkup(),
            );
            $messageId = data_get($response, 'result.message_id');
            if (data_get($response, 'ok') !== true || ! $messageId) {
                throw new RuntimeException('Telegram sendMessage did not return a successful message id.');
            }

            $intervalHours = $this->recordSuccessfulCooldown();

            return [
                'success' => true,
                'message' => 'Info pembelian fake dikirim ke Telegram Channel memakai produk '.$product->name.'. Cooldown berikutnya '.$intervalHours.' jam.',
            ];
        } catch (Throwable $e) {
            $safeMessage = TelegramBotApiRedactor::redactText($e->getMessage());

            return [
                'success' => false,
                'message' => 'Gagal mengirim info pembelian fake: '.$safeMessage,
            ];
        }
    }

    /**
     * @return array{ready: bool, last_sent_label: string, next_available_label: string, interval_label: string}
     */
    private function cooldownState(): array
    {
        $lastSentAt = $this->cachedTime(self::CACHE_LAST_SENT_AT);
        $nextAvailableAt = $this->cachedTime(self::CACHE_NEXT_AVAILABLE_AT);
        $intervalHours = Cache::get(self::CACHE_INTERVAL_HOURS);
        $intervalHours = is_numeric($intervalHours) ? (int) $intervalHours : null;
        $ready = $nextAvailableAt === null || now()->greaterThanOrEqualTo($nextAvailableAt);

        return [
            'ready' => $ready,
            'last_sent_label' => $lastSentAt ? $this->formatDashboardTime($lastSentAt) : 'Belum pernah',
            'next_available_label' => $nextAvailableAt ? $this->formatDashboardTime($nextAvailableAt) : 'Sekarang',
            'interval_label' => in_array($intervalHours, self::COOLDOWN_HOUR_OPTIONS, true)
                ? $intervalHours.' jam'
                : 'Random '.$this->intervalOptionsLabel(),
        ];
    }

    private function recordSuccessfulCooldown(): int
    {
        $intervalHours = self::COOLDOWN_HOUR_OPTIONS[array_rand(self::COOLDOWN_HOUR_OPTIONS)];
        $lastSentAt = now();
        $nextAvailableAt = $lastSentAt->copy()->addHours($intervalHours);
        $expiresAt = $nextAvailableAt->copy()->addDay();

        Cache::put(self::CACHE_LAST_SENT_AT, $lastSentAt->toISOString(), $expiresAt);
        Cache::put(self::CACHE_NEXT_AVAILABLE_AT, $nextAvailableAt->toISOString(), $expiresAt);
        Cache::put(self::CACHE_INTERVAL_HOURS, $intervalHours, $expiresAt);

        return $intervalHours;
    }

    /**
     * @return Builder<Product>
     */
    private function availableProducts(): Builder
    {
        return Product::query()->publicVisible()->availableForPurchase();
    }

    /**
     * @return list<array{id: int, name: string, label: string}>
     */
    private function productOptions(): array
    {
        return $this->availableProducts()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'stock', 'reserved_stock'])
            ->map(fn (Product $product): array => [
                'id' => (int) $product->id,
                'name' => $product->name,
                'label' => $product->name.' · stok '.$product->availableStock(),
            ])
            ->values()
            ->all();
    }

    private function randomBuyerMask(): string
    {
        $name = self::BUYER_NAMES[array_rand(self::BUYER_NAMES)];

        return mb_substr($name, 0, 2).'***';
    }

    private function fakeOrderNumber(): int
    {
        return $this->numbering->nextNumber();
    }

    private function intervalOptionsLabel(): string
    {
        return implode(' / ', array_map(fn (int $hours): string => $hours.' jam', self::COOLDOWN_HOUR_OPTIONS));
    }

    private function formatDashboardTime(Carbon $time): string
    {
        return $time->copy()->timezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i');
    }

    private function cachedTime(string $key): ?Carbon
    {
        $value = Cache::get($key);
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function shoppingButtonMarkup(): array
    {
        return [
            'inline_keyboard' => [[['text' => '🛒 Belanja Sekarang', 'url' => self::SHOPPING_BUTTON_URL]]],
        ];
    }
}
