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

    private const CACHE_MANUAL_LAST_SENT_AT = 'fake-sales-proof:manual:last-sent-at';

    private const CACHE_AUTO_LAST_SENT_AT = 'fake-sales-proof:last-sent-at';

    private const CACHE_AUTO_NEXT_AVAILABLE_AT = 'fake-sales-proof:next-available-at';

    private const CACHE_AUTO_INTERVAL_HOURS = 'fake-sales-proof:interval-hours';

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
     * @return array{can_send: bool, disabled_reason: string|null, last_sent_label: string, manual_last_sent_label: string, auto_last_sent_label: string, next_available_label: string, interval_label: string, interval_options_label: string, product_options: list<array{id: int, name: string, label: string}>, send_url: string}
     */
    public function dashboardState(StoreChannelSetting $setting, bool $ready, bool $canManage): array
    {
        $automatic = $this->automaticIntervalState();
        $manualLastSentLabel = $this->manualLastSentLabel();
        $productOptions = $this->productOptions();
        $hasProduct = $productOptions !== [];
        $disabledReason = match (true) {
            ! $canManage => 'Hanya owner/admin yang bisa mengirim info pembelian fake.',
            ! $ready => 'Setup channel belum ready. Jalankan Test Channel sampai berhasil dulu.',
            ! $setting->sales_proof_enabled => 'Aktifkan toggle Sales Proof Post dulu.',
            ! $hasProduct => 'Tidak ada produk aktif dengan stok tersedia untuk dipakai.',
            default => null,
        };

        return [
            'can_send' => $disabledReason === null,
            'disabled_reason' => $disabledReason,
            'last_sent_label' => $manualLastSentLabel,
            'manual_last_sent_label' => $manualLastSentLabel,
            'auto_last_sent_label' => $automatic['last_sent_label'],
            'next_available_label' => $automatic['next_available_label'],
            'interval_label' => $automatic['interval_label'],
            'interval_options_label' => $this->intervalOptionsLabel(),
            'product_options' => $productOptions,
            'send_url' => route('admin.store-channel.fake-sales-proof'),
        ];
    }

    /**
     * Manual button publish. It is intentionally not throttled by the automatic
     * random interval; admins asked for this button to be fire-on-demand.
     *
     * @return array{success: bool, message: string, skipped?: bool}
     */
    public function publish(mixed $productId): array
    {
        return $this->publishManual($productId);
    }

    /**
     * @return array{success: bool, message: string, skipped?: bool}
     */
    public function publishManual(mixed $productId): array
    {
        return $this->withPublishLock(fn (): array => $this->publishManualWhileLocked($productId));
    }

    /**
     * @return array{success: bool, message: string, skipped?: bool}
     */
    public function publishAutomatically(): array
    {
        return $this->withPublishLock(fn (): array => $this->publishAutomaticallyWhileLocked());
    }

    /**
     * @param  callable(): array{success: bool, message: string, skipped?: bool}  $callback
     * @return array{success: bool, message: string, skipped?: bool}
     */
    private function withPublishLock(callable $callback): array
    {
        $lock = Cache::lock('sales-proof-posts:publish', 60);
        if (! $lock->get()) {
            return ['success' => false, 'skipped' => true, 'message' => 'Publikasi Sales Proof lain sedang berjalan. Coba lagi sebentar.'];
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{success: bool, message: string, skipped?: bool}
     */
    private function publishManualWhileLocked(mixed $rawProductId): array
    {
        $setting = StoreChannelSetting::current();
        $chatId = $setting->telegram_chat_id ?: $setting->channel_username;
        if (! $setting->sales_proof_enabled) {
            return ['success' => false, 'message' => 'Aktifkan Sales Proof Post sebelum mengirim info pembelian fake.'];
        }

        if (! $chatId) {
            return ['success' => false, 'message' => 'Store Channel belum punya chat ID/username. Jalankan Test Channel dulu.'];
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
            $this->sendFakeSalesProof((string) $chatId, $product);
            $this->recordSuccessfulManualPublish();

            return [
                'success' => true,
                'message' => 'Info pembelian fake dikirim ke Telegram Channel memakai produk '.$product->name.'. Tombol manual bisa dipakai lagi kapan saja.',
            ];
        } catch (Throwable $e) {
            return $this->failedSendResult($e);
        }
    }

    /**
     * @return array{success: bool, message: string, skipped?: bool}
     */
    private function publishAutomaticallyWhileLocked(): array
    {
        $setting = StoreChannelSetting::current();
        $chatId = $setting->telegram_chat_id ?: $setting->channel_username;
        if (! $setting->sales_proof_enabled) {
            return ['success' => false, 'skipped' => true, 'message' => 'Sales Proof Post nonaktif; auto fake notif dilewati.'];
        }

        if (! $chatId) {
            return ['success' => false, 'skipped' => true, 'message' => 'Store Channel belum punya chat ID/username; auto fake notif dilewati.'];
        }

        $automatic = $this->automaticIntervalState();
        if (! $automatic['ready']) {
            return ['success' => false, 'skipped' => true, 'message' => 'Auto fake notif belum waktunya. Berikutnya '.$automatic['next_available_label'].'.'];
        }

        $product = $this->randomAvailableProduct();
        if (! $product) {
            return ['success' => false, 'skipped' => true, 'message' => 'Tidak ada produk aktif dengan stok tersedia untuk auto fake notif.'];
        }

        try {
            $this->sendFakeSalesProof((string) $chatId, $product);
            $intervalHours = $this->recordSuccessfulAutomaticInterval();

            return [
                'success' => true,
                'message' => 'Auto fake notif dikirim ke Telegram Channel memakai produk '.$product->name.'. Auto berikutnya sekitar '.$intervalHours.' jam lagi.',
            ];
        } catch (Throwable $e) {
            return $this->failedSendResult($e);
        }
    }

    private function sendFakeSalesProof(int|string $chatId, Product $product): void
    {
        $response = $this->bot->sendMessage(
            $chatId,
            $this->formatter->formatFake($this->randomBuyerMask(), $product->name, 1, $this->fakeOrderNumber()),
            $this->shoppingButtonMarkup(),
        );
        $messageId = data_get($response, 'result.message_id');
        if (data_get($response, 'ok') !== true || ! $messageId) {
            throw new RuntimeException('Telegram sendMessage did not return a successful message id.');
        }
    }

    /**
     * @return array{success: false, message: string}
     */
    private function failedSendResult(Throwable $e): array
    {
        $safeMessage = TelegramBotApiRedactor::redactText($e->getMessage());

        return [
            'success' => false,
            'message' => 'Gagal mengirim info pembelian fake: '.$safeMessage,
        ];
    }

    /**
     * @return array{ready: bool, last_sent_label: string, next_available_label: string, interval_label: string}
     */
    private function automaticIntervalState(): array
    {
        $lastSentAt = $this->cachedTime(self::CACHE_AUTO_LAST_SENT_AT);
        $nextAvailableAt = $this->cachedTime(self::CACHE_AUTO_NEXT_AVAILABLE_AT);
        $intervalHours = Cache::get(self::CACHE_AUTO_INTERVAL_HOURS);
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

    private function recordSuccessfulManualPublish(): void
    {
        Cache::put(self::CACHE_MANUAL_LAST_SENT_AT, now()->toISOString(), now()->addDays(30));
    }

    private function recordSuccessfulAutomaticInterval(): int
    {
        $intervalHours = self::COOLDOWN_HOUR_OPTIONS[array_rand(self::COOLDOWN_HOUR_OPTIONS)];
        $lastSentAt = now();
        $nextAvailableAt = $lastSentAt->copy()->addHours($intervalHours);
        $expiresAt = $nextAvailableAt->copy()->addDay();

        Cache::put(self::CACHE_AUTO_LAST_SENT_AT, $lastSentAt->toISOString(), $expiresAt);
        Cache::put(self::CACHE_AUTO_NEXT_AVAILABLE_AT, $nextAvailableAt->toISOString(), $expiresAt);
        Cache::put(self::CACHE_AUTO_INTERVAL_HOURS, $intervalHours, $expiresAt);

        return $intervalHours;
    }

    /**
     * @return Builder<Product>
     */
    private function availableProducts(): Builder
    {
        return Product::query()->publicVisible()->availableForPurchase();
    }

    private function randomAvailableProduct(): ?Product
    {
        return $this->availableProducts()->inRandomOrder()->first();
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
        return $this->numbering->reserveNextNumber();
    }

    private function intervalOptionsLabel(): string
    {
        return implode(' / ', array_map(fn (int $hours): string => $hours.' jam', self::COOLDOWN_HOUR_OPTIONS));
    }

    private function manualLastSentLabel(): string
    {
        $lastSentAt = $this->cachedTime(self::CACHE_MANUAL_LAST_SENT_AT);

        return $lastSentAt ? $this->formatDashboardTime($lastSentAt) : 'Belum pernah';
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
