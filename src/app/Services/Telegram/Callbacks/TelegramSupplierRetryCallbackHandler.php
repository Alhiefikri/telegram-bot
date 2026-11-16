<?php

namespace App\Services\Telegram\Callbacks;

use App\Services\Suppliers\SupplierInvoiceRetry;
use App\Services\Telegram\TelegramAdminAccess;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use Throwable;

class TelegramSupplierRetryCallbackHandler implements TelegramCallbackHandler
{
    private const PATTERN = '/^admin:supplier-retry:([1-9][0-9]{0,18})$/';

    public function __construct(
        private readonly TelegramAdminAccess $adminAccess,
        private readonly TelegramBotClient $bot,
        private readonly SupplierInvoiceRetry $retry,
    ) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::BeforePrivacy;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return false;
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        return preg_match(self::PATTERN, $context->data) === 1;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (preg_match(self::PATTERN, $context->data, $matches) !== 1) {
            return false;
        }

        if (! $context->isPrivate()
            || (string) $context->customer->telegram_id !== $context->chatId
            || ! $this->adminAccess->isAdmin($context->customer, $context->chatId)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, 'Hanya owner di chat pribadi yang dapat mencoba ulang.');

            return true;
        }
        if (strlen($matches[1]) > strlen((string) PHP_INT_MAX)
            || (strlen($matches[1]) === strlen((string) PHP_INT_MAX) && strcmp($matches[1], (string) PHP_INT_MAX) > 0)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, 'Fulfillment tidak ditemukan.');

            return true;
        }

        // Acknowledge before the supplier refresh; price exceptions require the
        // password-confirmed dashboard flow and never authorize spending here.
        $this->bot->answerCallbackQuery($context->callbackQueryId, 'Memeriksa stok dan saldo. Hasil akan dikirim di sini.');

        try {
            $result = $this->retry->attempt((int) $matches[1]);
            $message = match ($result['status'] ?? '') {
                'queued' => 'Pembelian dijadwalkan. Periksa hasil pembelian dan pengiriman; jangan menekan ulang.',
                'already_processing' => 'Pembelian sedang diproses. Tidak ada pembelian baru yang dijadwalkan.',
                'stock_unavailable' => 'Stok tersedia setelah reservasi tidak cukup. Tidak ada pembelian baru; coba lagi setelah restock.',
                'stock_unknown' => 'Stok supplier belum dapat dipastikan. Tidak ada pembelian baru.',
                'insufficient_balance' => 'Saldo supplier yang tersedia setelah hold tidak cukup. Tidak ada pembelian baru.',
                'balance_unknown' => 'Saldo supplier belum dapat dipastikan. Tidak ada pembelian baru.',
                'price_approval_required' => 'Harga supplier melewati batas invoice. Buka Tangani invoice di dashboard untuk meninjau harga sebelum menyetujui.',
                'unavailable' => 'Stok atau saldo belum tersedia dalam batas pembelian. Tidak ada pembelian baru. Anda dapat memeriksa atau memaksa sinkron di dashboard.',
                'sync_failed' => 'Sinkronisasi belum berhasil. Data supplier belum dapat digunakan untuk membeli; periksa dashboard.',
                'manual_takeover' => 'Invoice sudah ditangani manual. Pembelian otomatis tidak dijalankan.',
                'unsafe', 'invalid_order' => 'Pembelian ulang belum aman atau invoice tidak lagi memenuhi syarat. Periksa transaksi di dashboard.',
                default => 'Status invoice berubah. Periksa dashboard sebelum bertindak.',
            };
        } catch (Throwable $exception) {
            report($exception);
            $message = 'Pemeriksaan tidak dapat diselesaikan. Periksa status invoice di dashboard sebelum mencoba lagi.';
        }

        try {
            $this->bot->sendMessage($context->chatId, $message);
        } catch (Throwable $exception) {
            report($exception);
        }

        return true;
    }
}
