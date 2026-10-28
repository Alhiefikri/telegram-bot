<?php

namespace App\Services\Telegram;

use App\Models\Product;
use App\Models\StoreChannelSetting;
use App\Services\PaidInvoice\PaidInvoiceRenderer;
use App\Services\PaidInvoice\PaidInvoiceSyntheticPresentationAdapter;
use App\Storefront\StorefrontUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class FakeSalesProofPostPublisher
{
    private const CACHE_MANUAL_LAST_SENT_AT = 'fake-sales-proof:manual:last-sent-at';

    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly SalesProofPostFormatter $formatter,
        private readonly SalesProofPostNumbering $numbering,
        private readonly SalesProofStockSignal $stockSignal,
        private readonly StorefrontUrl $urls,
        private readonly PaidInvoiceRenderer $invoiceRenderer,
        private readonly PaidInvoiceSyntheticPresentationAdapter $invoicePresentation,
        private readonly TelegramOutboundResponseValidator $responseValidator,
    ) {}

    /**
     * @return array{can_send: bool, disabled_reason: string|null, manual_last_sent_label: string, product_options: list<array{id: int, name: string, label: string}>, send_url: string}
     */
    public function dashboardState(StoreChannelSetting $setting, bool $ready, bool $canManage): array
    {
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
            'manual_last_sent_label' => $this->manualLastSentLabel(),
            'product_options' => $productOptions,
            'send_url' => route('admin.store-channel.fake-sales-proof'),
        ];
    }

    /**
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
     * @param  callable(): array{success: bool, message: string, skipped?: bool, retryable?: bool}  $callback
     * @return array{success: bool, message: string, skipped?: bool, retryable?: bool}
     */
    private function withPublishLock(callable $callback, bool $retryWhenBusy = false): array
    {
        $lock = Cache::lock('sales-proof-posts:publish', 60);
        if (! $lock->get()) {
            return $retryWhenBusy
                ? ['success' => false, 'retryable' => true, 'message' => 'Publikasi Sales Proof lain sedang berjalan; coba lagi.']
                : ['success' => false, 'skipped' => true, 'message' => 'Publikasi Sales Proof lain sedang berjalan. Coba lagi sebentar.'];
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
     * Publish the proof for a committed manual stock deletion. The product may
     * already be unavailable; unlike manual selection, this path
     * deliberately renders the deleted quantity and does not mutate inventory.
     *
     * @return array{success: bool, message: string, skipped?: bool, retryable?: bool}
     */
    public function publishDeletedStock(int $productId, int $quantity): array
    {
        return $this->withPublishLock(
            callback: fn (): array => $this->publishDeletedStockWhileLocked($productId, $quantity),
            retryWhenBusy: true,
        );
    }

    /**
     * @return array{success: bool, message: string, skipped?: bool, retryable?: bool}
     */
    private function publishDeletedStockWhileLocked(int $productId, int $quantity): array
    {
        if ($quantity < 1) {
            return ['success' => false, 'skipped' => true, 'message' => 'Penghapusan stok kosong; fake notif dilewati.'];
        }

        $setting = StoreChannelSetting::current();
        $chatId = $setting->telegram_chat_id ?: $setting->channel_username;
        if (! $setting->sales_proof_enabled || ! $chatId) {
            return ['success' => false, 'skipped' => true, 'message' => 'Fake Sales Proof tidak siap; notif penghapusan stok dilewati.'];
        }

        $product = Product::query()->find($productId);
        if (! $product) {
            return ['success' => false, 'skipped' => true, 'message' => 'Produk penghapusan stok sudah tidak tersedia.'];
        }

        try {
            $this->sendFakeSalesProof((string) $chatId, $product, $quantity);

            return ['success' => true, 'message' => 'Fake Sales Proof penghapusan stok dikirim.'];
        } catch (Throwable $e) {
            return [...$this->failedSendResult($e), 'retryable' => true];
        }
    }

    private function sendFakeSalesProof(int|string $chatId, Product $product, int $quantity = 1): void
    {
        $presentation = $this->invoicePresentation->present($product, quantity: $quantity);
        $invoice = $this->invoiceRenderer->render($presentation);

        try {
            $response = $this->bot->sendPhoto(
                $chatId,
                $invoice->bytes,
                $invoice->filename,
                $this->formatter->format($presentation, $this->fakeOrderNumber(), $this->stockSignal->forProduct($product)),
                $this->shoppingButtonMarkup($product),
                [TelegramBotClient::CUSTOM_EMOJI_SCOPE_OPTION => CustomEmojiCapability::SCOPE_CHANNEL],
            );
            $this->responseValidator->messageIdFromResponse($response, 'Telegram fake Sales Proof');
        } finally {
            unset($invoice, $presentation);
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

    private function recordSuccessfulManualPublish(): void
    {
        Cache::put(self::CACHE_MANUAL_LAST_SENT_AT, now()->toISOString(), now()->addDays(30));
    }

    /**
     * @return Builder<Product>
     */
    private function availableProducts(): Builder
    {
        return Product::query()->withAvailability()->publicVisible()->availableForPurchase();
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

    private function fakeOrderNumber(): int
    {
        return $this->numbering->reserveNextNumber();
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

    private function shoppingButtonMarkup(Product $product): array
    {
        return [
            'inline_keyboard' => [[['text' => $this->formatter->shoppingButtonLabel(), 'url' => $this->urls->telegramProductStartUrl($product)]]],
        ];
    }
}
