<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Support\Str;
use Throwable;

class TelegramBroadcastIntents
{
    private const PRODUCTS_PER_PAGE = 8;

    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramBroadcastService $broadcast,
        private readonly TelegramBroadcastDrafts $drafts,
        private readonly TelegramAdminAccess $adminAccess,
    ) {}

    public function handleCommand(Customer $customer, string $chatId, string $text): void
    {
        if (! $this->adminAccess->isAdmin($customer, $chatId)) {
            $this->bot->sendMessage($chatId, 'Akses admin diperlukan.');

            return;
        }

        $message = trim(substr($text, strlen('/broadcast')));
        if ($message === '') {
            $this->sendHelp($chatId);

            return;
        }

        if (mb_strlen($message) > 3500) {
            $this->bot->sendMessage($chatId, 'Pesan broadcast maksimal 3500 karakter.');

            return;
        }

        try {
            $this->drafts->put($customer, ['message' => $message, 'product_id' => null]);
        } catch (Throwable) {
            $this->bot->sendMessage($chatId, 'Draft broadcast sedang diproses. Coba lagi sebentar.');

            return;
        }

        $this->sendPreview($customer, $chatId);
    }

    public function handleMenu(Customer $customer, string $chatId): void
    {
        if (! $this->adminAccess->isAdmin($customer, $chatId)) {
            $this->bot->sendMessage($chatId, 'Akses admin diperlukan.');

            return;
        }

        $this->sendHelp($chatId);
    }

    public function handleCallback(Customer $customer, string $chatId, string $callbackId, string $data): bool
    {
        if (! str_starts_with($data, 'broadcast:')) {
            return false;
        }

        if (! $this->adminAccess->isAdmin($customer, $chatId)) {
            $this->bot->answerCallbackQuery($callbackId, 'Akses ditolak.');

            return true;
        }

        $callback = $this->parseCallback($data);
        if ($callback === null) {
            $this->bot->answerCallbackQuery($callbackId, 'Draft sudah kedaluwarsa.');

            return true;
        }

        if (! $this->isCurrentDraft($customer, $callback['draft_id'])) {
            $this->bot->answerCallbackQuery($callbackId, $this->drafts->get($customer) === null
                ? 'Draft sudah kedaluwarsa.'
                : 'Draft tidak berlaku lagi.');

            return true;
        }

        try {
            return match ($callback['action']) {
                'preview' => $this->previewCallback($customer, $chatId, $callbackId, $callback['draft_id']),
                'cancel' => $this->cancel($customer, $chatId, $callbackId, $callback['draft_id']),
                'pick' => $this->pickProducts($customer, $chatId, $callbackId, $callback['draft_id'], $callback['page']),
                'select' => $this->selectProduct($customer, $chatId, $callbackId, $callback['draft_id'], $callback['product_id']),
                'send' => $this->send($customer, $chatId, $callbackId, $callback['draft_id']),
            };
        } catch (Throwable) {
            $this->bot->answerCallbackQuery($callbackId, 'Draft sedang diproses. Coba lagi sebentar.');

            return true;
        }
    }

    private function sendHelp(string $chatId): void
    {
        $this->bot->sendMessage($chatId, "📣 Broadcast Info\n━━━━━━━━━━━━━━\nKetik pesan dengan format:\n\n/broadcast isi pesan\n\nSetelah itu bot menampilkan preview. Kamu bisa menambahkan tombol produk, lalu konfirmasi sebelum pesan dikirim.");
    }

    private function previewCallback(Customer $customer, string $chatId, string $callbackId, string $draftId): bool
    {
        $this->bot->answerCallbackQuery($callbackId);
        $this->sendPreview($customer, $chatId, $draftId);

        return true;
    }

    private function cancel(Customer $customer, string $chatId, string $callbackId, string $draftId): bool
    {
        $result = $this->drafts->mutate($customer, $draftId, fn (array $draft): array => [
            'action' => 'remove',
            'value' => true,
        ]);

        if (! $result['locked']) {
            $this->bot->answerCallbackQuery($callbackId, 'Draft sedang diproses. Coba lagi sebentar.');

            return true;
        }

        if (! $result['matched']) {
            $this->bot->answerCallbackQuery($callbackId, 'Draft sudah kedaluwarsa.');

            return true;
        }

        $this->bot->answerCallbackQuery($callbackId, 'Draft dibatalkan.');
        $this->bot->sendMessage($chatId, '❌ Draft broadcast dibatalkan.');

        return true;
    }

    private function pickProducts(Customer $customer, string $chatId, string $callbackId, string $draftId, int $page): bool
    {
        $this->bot->answerCallbackQuery($callbackId);
        $this->sendProductPicker($customer, $chatId, $draftId, $page);

        return true;
    }

    private function selectProduct(Customer $customer, string $chatId, string $callbackId, string $draftId, int $productId): bool
    {
        $selectedProduct = null;
        $result = $this->drafts->mutate($customer, $draftId, function (array $draft) use (&$selectedProduct, $productId): array {
            $selectedProduct = $this->eligibleProduct($productId);
            if (! $selectedProduct) {
                return ['action' => 'keep', 'value' => 'unavailable'];
            }

            $draft['product_id'] = $selectedProduct->id;

            return ['action' => 'replace', 'draft' => $draft, 'value' => 'selected'];
        });

        if (! $result['locked']) {
            $this->bot->answerCallbackQuery($callbackId, 'Draft sedang diproses. Coba lagi sebentar.');

            return true;
        }

        if (! $result['matched']) {
            $this->bot->answerCallbackQuery($callbackId, 'Draft sudah kedaluwarsa.');

            return true;
        }

        if ($result['value'] === 'unavailable' || ! $selectedProduct) {
            $this->bot->answerCallbackQuery($callbackId, 'Produk tidak tersedia.');

            return true;
        }

        $this->bot->answerCallbackQuery($callbackId, 'Produk ditambahkan.');
        $this->sendPreview($customer, $chatId, $draftId);

        return true;
    }

    private function sendPreview(Customer $customer, string $chatId, ?string $expectedDraftId = null): void
    {
        $draft = $this->drafts->get($customer);
        if ($draft === null || ($expectedDraftId !== null && ! hash_equals($draft['draft_id'], $expectedDraftId))) {
            $this->bot->sendMessage($chatId, 'Draft broadcast tidak ditemukan atau sudah kedaluwarsa. Mulai lagi dengan /broadcast isi pesan.');

            return;
        }

        $product = $draft['product_id'] ? $this->eligibleProduct($draft['product_id']) : null;
        if ($draft['product_id'] && ! $product) {
            $result = $this->drafts->mutate($customer, $draft['draft_id'], function (array $current): array {
                $current['product_id'] = null;

                return ['action' => 'replace', 'draft' => $current, 'value' => null];
            });
            if ($result['matched']) {
                $draft = $this->drafts->get($customer) ?? $draft;
            }
        }

        $lines = [
            '📣 Preview broadcast',
            '━━━━━━━━━━━━━━',
            'Pesan:',
            $draft['message'],
            '',
            $product ? 'Tombol produk: '.$product->name : 'Tombol produk: tidak ada',
            '',
            'Pesan belum dikirim. Pilih tindakan di bawah.',
        ];
        $productAction = $product ? '🔁 Ganti produk' : '➕ Tambahkan produk';

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'inline_keyboard' => [
                [['text' => $productAction, 'callback_data' => $this->callbackData('pick', $draft['draft_id'], 1)]],
                [
                    ['text' => '✅ Kirim', 'callback_data' => $this->callbackData('send', $draft['draft_id'])],
                    ['text' => '❌ Batal', 'callback_data' => $this->callbackData('cancel', $draft['draft_id'])],
                ],
            ],
        ]);
    }

    private function sendProductPicker(Customer $customer, string $chatId, string $draftId, int $page): void
    {
        if (! $this->isCurrentDraft($customer, $draftId)) {
            $this->bot->sendMessage($chatId, 'Draft broadcast sudah kedaluwarsa. Mulai lagi dengan /broadcast isi pesan.');

            return;
        }

        $page = max(1, $page);
        $products = Product::query()
            ->publicVisible()
            ->availableForPurchase()
            ->orderBy('name')
            ->orderBy('id')
            ->offset(($page - 1) * self::PRODUCTS_PER_PAGE)
            ->limit(self::PRODUCTS_PER_PAGE + 1)
            ->get(['id', 'name', 'price_amount']);

        if ($products->isEmpty() && $page > 1) {
            $this->sendProductPicker($customer, $chatId, $draftId, $page - 1);

            return;
        }

        $hasNextPage = $products->count() > self::PRODUCTS_PER_PAGE;
        $products = $products->take(self::PRODUCTS_PER_PAGE);
        $keyboard = $products
            ->map(fn (Product $product): array => [[
                'text' => '🛍️ '.Str::limit($product->name, 38, '…').' · Rp'.number_format($product->price_amount, 0, ',', '.'),
                'callback_data' => $this->callbackData('select', $draftId, $product->id),
            ]])
            ->all();
        $navigation = [];
        if ($page > 1) {
            $navigation[] = ['text' => '⬅️ Sebelumnya', 'callback_data' => $this->callbackData('pick', $draftId, $page - 1)];
        }
        if ($hasNextPage) {
            $navigation[] = ['text' => 'Berikutnya ➡️', 'callback_data' => $this->callbackData('pick', $draftId, $page + 1)];
        }
        if ($navigation !== []) {
            $keyboard[] = $navigation;
        }
        $keyboard[] = [['text' => '⬅️ Kembali ke preview', 'callback_data' => $this->callbackData('preview', $draftId)]];

        $this->bot->sendMessage($chatId, $products->isEmpty()
            ? 'Belum ada produk aktif yang siap dibeli untuk ditampilkan di broadcast.'
            : 'Pilih produk untuk tombol pembelian broadcast.', ['inline_keyboard' => $keyboard]);
    }

    private function send(Customer $customer, string $chatId, string $callbackId, string $draftId): bool
    {
        $result = $this->drafts->mutate($customer, $draftId, function (array $draft): array {
            $product = $draft['product_id'] ? $this->eligibleProduct($draft['product_id']) : null;
            if ($draft['product_id'] && ! $product) {
                return ['action' => 'keep', 'value' => 'invalid_product'];
            }

            $replyMarkup = $product ? [
                'id' => [
                    'inline_keyboard' => [[
                        ['text' => '🛍️ Beli: '.Str::limit($product->name, 38, '…'), 'callback_data' => 'product:'.$product->id],
                    ]],
                ],
                'en' => [
                    'inline_keyboard' => [[
                        ['text' => '🛍️ Buy: '.Str::limit($product->name, 38, '…'), 'callback_data' => 'product:'.$product->id],
                    ]],
                ],
            ] : [];

            try {
                $queued = $this->broadcast->queueAdminBroadcast($draft['message'], $replyMarkup, $draft['draft_id']);
            } catch (Throwable $exception) {
                report($exception);

                return ['action' => 'keep', 'value' => 'persistence_failure'];
            }

            if ($queued['skipped']) {
                return ['action' => 'keep', 'value' => 'skipped'];
            }

            return ['action' => 'remove', 'value' => ['status' => 'queued', 'broadcast_id' => $queued['broadcast_id']]];
        });

        if (! $result['locked']) {
            $this->bot->answerCallbackQuery($callbackId, 'Draft sedang diproses. Coba lagi sebentar.');

            return true;
        }

        if (! $result['matched']) {
            $this->bot->answerCallbackQuery($callbackId, 'Draft sudah kedaluwarsa.');

            return true;
        }

        if ($result['value'] === 'invalid_product') {
            $this->bot->answerCallbackQuery($callbackId, 'Produk sudah tidak tersedia. Pilih produk lain.');

            return true;
        }

        if ($result['value'] === 'persistence_failure') {
            $this->bot->answerCallbackQuery($callbackId, 'Broadcast gagal disimpan. Coba lagi.');

            return true;
        }

        if ($result['value'] === 'skipped') {
            $this->bot->answerCallbackQuery($callbackId, 'Broadcast belum bisa diproses.');
            $this->bot->sendMessage($chatId, 'Broadcast dilewati karena token Telegram belum dikonfigurasi.');

            return true;
        }

        $broadcastId = $result['value']['broadcast_id'] ?? null;
        $this->bot->answerCallbackQuery($callbackId, 'Broadcast sedang dikirim.');
        $this->bot->sendMessage($chatId, $broadcastId === null
            ? 'Broadcast masuk antrean.'
            : "✅ Broadcast masuk antrean\n━━━━━━━━━━━━━━\nID: {$broadcastId}\nPengiriman berjalan di background. Progres bisa dipantau dari dashboard.");

        return true;
    }

    private function eligibleProduct(int $productId): ?Product
    {
        return Product::query()->publicVisible()->availableForPurchase()->whereKey($productId)->first(['id', 'name', 'price_amount']);
    }

    /**
     * @return array{action: 'preview'|'cancel'|'pick'|'select'|'send', draft_id: string, page?: int, product_id?: int}|null
     */
    private function parseCallback(string $data): ?array
    {
        if (preg_match('/^broadcast:(preview|cancel|send):([A-Za-z0-9]{16})$/', $data, $matches) === 1) {
            return ['action' => $matches[1], 'draft_id' => $matches[2]];
        }

        if (preg_match('/^broadcast:pick:([A-Za-z0-9]{16}):([1-9][0-9]*)$/', $data, $matches) === 1) {
            return ['action' => 'pick', 'draft_id' => $matches[1], 'page' => max(1, (int) $matches[2])];
        }

        if (preg_match('/^broadcast:select:([A-Za-z0-9]{16}):([1-9][0-9]*)$/', $data, $matches) === 1) {
            return ['action' => 'select', 'draft_id' => $matches[1], 'product_id' => (int) $matches[2]];
        }

        return null;
    }

    private function isCurrentDraft(Customer $customer, string $draftId): bool
    {
        $draft = $this->drafts->get($customer);

        return $draft !== null && hash_equals($draft['draft_id'], $draftId);
    }

    private function callbackData(string $action, string $draftId, ?int $value = null): string
    {
        $callback = 'broadcast:'.$action.':'.$draftId.($value === null ? '' : ':'.$value);
        if (strlen($callback) > 64) {
            throw new \LogicException('Broadcast callback data exceeded Telegram limit.');
        }

        return $callback;
    }
}
