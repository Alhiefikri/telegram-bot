<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Support\Str;

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

        $this->drafts->put($customer, ['message' => $message, 'product_id' => null]);
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

        if ($data === 'broadcast:preview') {
            $this->bot->answerCallbackQuery($callbackId);
            $this->sendPreview($customer, $chatId);

            return true;
        }

        if ($data === 'broadcast:cancel') {
            $this->drafts->forget($customer);
            $this->bot->answerCallbackQuery($callbackId, 'Draft dibatalkan.');
            $this->bot->sendMessage($chatId, '❌ Draft broadcast dibatalkan.');

            return true;
        }

        if (preg_match('/^broadcast:pick:([1-9][0-9]*)$/', $data, $matches)) {
            $this->bot->answerCallbackQuery($callbackId);
            $this->sendProductPicker($customer, $chatId, (int) $matches[1]);

            return true;
        }

        if (preg_match('/^broadcast:select:([1-9][0-9]*):([1-9][0-9]*)$/', $data, $matches)) {
            $draft = $this->drafts->get($customer);
            if ($draft === null) {
                $this->bot->answerCallbackQuery($callbackId, 'Draft sudah kedaluwarsa.');

                return true;
            }

            $product = $this->eligibleProduct((int) $matches[1]);
            if (! $product) {
                $this->bot->answerCallbackQuery($callbackId, 'Produk tidak tersedia.');

                return true;
            }

            $draft['product_id'] = (int) $product->id;
            $this->drafts->put($customer, $draft);
            $this->bot->answerCallbackQuery($callbackId, 'Produk ditambahkan.');
            $this->sendPreview($customer, $chatId);

            return true;
        }

        if ($data === 'broadcast:send') {
            $this->send($customer, $chatId, $callbackId);

            return true;
        }

        return false;
    }

    private function sendHelp(string $chatId): void
    {
        $this->bot->sendMessage($chatId, "📣 Broadcast Info\n━━━━━━━━━━━━━━\nKetik pesan dengan format:\n\n/broadcast isi pesan\n\nSetelah itu bot menampilkan preview. Kamu bisa menambahkan tombol produk, lalu konfirmasi sebelum pesan dikirim.");
    }

    private function sendPreview(Customer $customer, string $chatId): void
    {
        $draft = $this->drafts->get($customer);
        if ($draft === null) {
            $this->bot->sendMessage($chatId, 'Draft broadcast tidak ditemukan atau sudah kedaluwarsa. Mulai lagi dengan /broadcast isi pesan.');

            return;
        }

        $product = $draft['product_id'] ? $this->eligibleProduct($draft['product_id']) : null;
        if ($draft['product_id'] && ! $product) {
            $draft['product_id'] = null;
            $this->drafts->put($customer, $draft);
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
                [['text' => $productAction, 'callback_data' => 'broadcast:pick:1']],
                [['text' => '✅ Kirim', 'callback_data' => 'broadcast:send'], ['text' => '❌ Batal', 'callback_data' => 'broadcast:cancel']],
            ],
        ]);
    }

    private function sendProductPicker(Customer $customer, string $chatId, int $page): void
    {
        if ($this->drafts->get($customer) === null) {
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
            $this->sendProductPicker($customer, $chatId, $page - 1);

            return;
        }

        $hasNextPage = $products->count() > self::PRODUCTS_PER_PAGE;
        $products = $products->take(self::PRODUCTS_PER_PAGE);
        $keyboard = $products
            ->map(fn (Product $product): array => [[
                'text' => '🛍️ '.Str::limit($product->name, 38, '…').' · Rp'.number_format($product->price_amount, 0, ',', '.'),
                'callback_data' => 'broadcast:select:'.$product->id.':'.$page,
            ]])
            ->all();
        $navigation = [];
        if ($page > 1) {
            $navigation[] = ['text' => '⬅️ Sebelumnya', 'callback_data' => 'broadcast:pick:'.($page - 1)];
        }
        if ($hasNextPage) {
            $navigation[] = ['text' => 'Berikutnya ➡️', 'callback_data' => 'broadcast:pick:'.($page + 1)];
        }
        if ($navigation !== []) {
            $keyboard[] = $navigation;
        }
        $keyboard[] = [['text' => '⬅️ Kembali ke preview', 'callback_data' => 'broadcast:preview']];

        $this->bot->sendMessage($chatId, $products->isEmpty()
            ? 'Belum ada produk aktif yang siap dibeli untuk ditampilkan di broadcast.'
            : 'Pilih produk untuk tombol pembelian broadcast.', ['inline_keyboard' => $keyboard]);
    }

    private function send(Customer $customer, string $chatId, string $callbackId): void
    {
        $draft = $this->drafts->get($customer);
        if ($draft === null) {
            $this->bot->answerCallbackQuery($callbackId, 'Draft sudah kedaluwarsa.');

            return;
        }

        $product = $draft['product_id'] ? $this->eligibleProduct($draft['product_id']) : null;
        if ($draft['product_id'] && ! $product) {
            $this->bot->answerCallbackQuery($callbackId, 'Produk sudah tidak tersedia. Pilih produk lain.');

            return;
        }

        $draft = $this->drafts->consume($customer);
        if ($draft === null) {
            $this->bot->answerCallbackQuery($callbackId, 'Broadcast sudah diproses atau draft kedaluwarsa.');

            return;
        }

        $replyMarkup = $product ? [
            'inline_keyboard' => [[
                ['text' => '🛍️ Beli: '.Str::limit($product->name, 38, '…'), 'callback_data' => 'product:'.$product->id],
            ]],
        ] : [];

        $this->bot->answerCallbackQuery($callbackId, 'Broadcast sedang dikirim.');
        $result = $this->broadcast->sendToActiveUsers($draft['message'], replyMarkup: $replyMarkup);
        $this->bot->sendMessage($chatId, $result['skipped']
            ? 'Broadcast dilewati karena token Telegram belum dikonfigurasi.'
            : "✅ Broadcast selesai\n━━━━━━━━━━━━━━\nTerkirim: {$result['sent']}\nGagal: {$result['failed']}");
    }

    private function eligibleProduct(int $productId): ?Product
    {
        return Product::query()->publicVisible()->availableForPurchase()->whereKey($productId)->first(['id', 'name', 'price_amount']);
    }
}
