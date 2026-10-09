<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\Product;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TelegramContentIntents
{
    private const FIELDS = ['description' => 'Product Details', 'warranty_policy' => 'Replacement / Refund Policy'];

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramAdminAccess $access,
        private TelegramProductContent $content,
        private AuditLogger $audit,
    ) {}

    public function forget(Customer $customer): void
    {
        Cache::forget($this->key($customer));
    }

    public function menu(Customer $customer, string $chatId, int $page = 0): void
    {
        $this->forget($customer);
        $products = Product::query()->orderBy('name')->orderBy('id')->skip($page * 10)->take(11)->get(['id', 'name']);
        $buttons = [];
        foreach ($products->take(10) as $product) {
            $buttons[] = [['text' => $product->name, 'callback_data' => 'content:product:'.$product->id]];
        }
        $navigation = [];
        if ($page > 0) {
            $navigation[] = ['text' => '‹ Sebelumnya', 'callback_data' => 'content:page:'.($page - 1)];
        }
        if ($products->count() > 10) {
            $navigation[] = ['text' => 'Berikutnya ›', 'callback_data' => 'content:page:'.($page + 1)];
        }
        if ($navigation !== []) {
            $buttons[] = $navigation;
        }
        $this->bot->sendMessage($chatId, 'Admin — pilih produk untuk mengedit Product Details atau Replacement / Refund Policy.', ['inline_keyboard' => $buttons]);
    }

    public function callback(TelegramCallbackContext $context): void
    {
        $this->bot->answerCallbackQuery($context->callbackQueryId);
        $parts = explode(':', $context->data);
        if (($parts[1] ?? '') === 'page' && ctype_digit($parts[2] ?? '')) {
            $this->menu($context->customer, $context->chatId, min(100000, (int) $parts[2]));

            return;
        }
        $product = Product::query()->find((int) ($parts[2] ?? 0));
        if (! $product) {
            $this->bot->sendMessage($context->chatId, 'Produk tidak tersedia. Buka /admin lagi.');

            return;
        }
        if (($parts[1] ?? '') === 'product') {
            $this->forget($context->customer);
            $buttons = [];
            foreach (self::FIELDS as $field => $label) {
                $buttons[] = [['text' => $label, 'callback_data' => 'content:edit:'.$product->id.':'.$field]];
            }
            $buttons[] = [['text' => 'Kembali', 'callback_data' => 'content:page:0']];
            $this->bot->sendMessage($context->chatId, $product->name.' — pilih bagian:', ['inline_keyboard' => $buttons]);

            return;
        }
        $field = $parts[3] ?? '';
        if (($parts[1] ?? '') !== 'edit' || ! isset(self::FIELDS[$field])) {
            return;
        }
        $this->forget($context->customer);
        $text = (string) $product->{$field};
        $this->bot->sendMessage($context->chatId, self::FIELDS[$field].' — '.$product->name."\nSalin teks asli berikut ke kolom pesan, edit, lalu BALAS pesan instruksi terakhir. Emoji premium harus dipilih dari keyboard emoji Telegram. /cancel membatalkan; /clear mengosongkan bagian. Berlaku 10 menit.");
        // No title is mixed into the editable original; offsets remain exact.
        if ($text !== '') {
            foreach (mb_str_split($text, 4096, 'UTF-8') as $index => $chunk) {
                $options = $index === 0 && mb_strlen($text, 'UTF-8') <= 4096
                    ? ['entities' => $this->content->entities($product, $field)] : [];
                $this->bot->sendMessage($context->chatId, $chunk, [], $options);
            }
        }
        $prompt = $this->bot->sendMessage($context->chatId, 'Balas pesan ini dengan teks pengganti lengkap untuk '.self::FIELDS[$field].'.', ['force_reply' => true, 'selective' => true]);
        Cache::put($this->key($context->customer), [
            'product_id' => $product->id, 'field' => $field, 'text' => $text,
            'metadata' => $product->telegram_content[$field] ?? null,
            'chat_id' => $context->chatId, 'prompt_id' => $prompt['result']['message_id'],
        ], now()->addMinutes(10));
    }

    public function receive(Customer $customer, string $chatId, array $message, string $chatType): bool
    {
        $state = Cache::get($this->key($customer));
        if (! is_array($state) || $chatType !== 'private' || $state['chat_id'] !== $chatId) {
            return false;
        }
        if (! $this->access->isAdmin($customer, $chatId)) {
            $this->forget($customer);

            return true;
        }
        $text = $message['text'] ?? null;
        if (in_array($text, ['/cancel', '/batal'], true)) {
            $this->forget($customer);
            $this->bot->sendMessage($chatId, 'Edit dibatalkan.');

            return true;
        }
        if (is_string($text) && str_starts_with($text, '/') && $text !== '/clear') {
            $this->forget($customer);

            return false;
        }
        if ((int) ($message['reply_to_message']['message_id'] ?? 0) !== (int) $state['prompt_id']) {
            $this->bot->sendMessage($chatId, 'Belum disimpan. Balas pesan instruksi edit, atau /cancel.');

            return true;
        }
        try {
            if (! is_string($text) || ($text !== '/clear' && trim($text) === '')) {
                throw new InvalidArgumentException('Kirim pesan teks, bukan foto/stiker.');
            }
            $text = $text === '/clear' ? '' : $text;
            $entities = $this->content->capture($text, $message['entities'] ?? []);
            $ids = array_values(array_unique(array_column($entities, 'custom_emoji_id')));
            foreach (array_chunk($ids, 100) as $batch) {
                $stickers = $this->bot->getCustomEmojiStickers($batch)['result'] ?? [];
                $resolved = array_column($stickers, 'custom_emoji_id');
                if (array_diff($batch, $resolved) !== []) {
                    throw new InvalidArgumentException('Emoji premium tidak dapat diverifikasi. Kirim ulang.');
                }
            }
            DB::transaction(function () use ($customer, $state, $text, $entities): void {
                $product = Product::query()->lockForUpdate()->find($state['product_id']);
                $field = $state['field'];
                if (! $product || (string) $product->{$field} !== $state['text'] || ($product->telegram_content[$field] ?? null) !== $state['metadata']) {
                    throw new InvalidArgumentException('Konten sudah berubah. Buka /admin lagi agar tidak menimpa perubahan terbaru.');
                }
                $other = $field === 'description' ? 'warranty_policy' : 'description';
                if (strlen(mb_convert_encoding($text.(string) $product->{$other}, 'UTF-16LE', 'UTF-8')) / 2 > 3000) {
                    throw new InvalidArgumentException('Total Product Details + Refund Policy maksimal 3000 unit UTF-16 (emoji dapat dihitung dua unit) agar muat di Telegram.');
                }
                $metadata = $product->telegram_content ?? [];
                $metadata[$field] = ['text' => $text, 'entities' => $entities];
                $product->forceFill([$field => $text, 'telegram_content' => $metadata])->save();
                $this->audit->log('product.telegram_content_updated', $product, [$field => $state['text']], [$field => $text, 'telegram_actor_id' => (string) $customer->telegram_id]);
            });
        } catch (InvalidArgumentException $exception) {
            $this->bot->sendMessage($chatId, $exception->getMessage());

            return true;
        }
        $this->forget($customer);
        $this->bot->sendMessage($chatId, 'Tersimpan. Konten langsung digunakan pada Product Detail berikutnya. Emoji bergerak memerlukan izin custom emoji bot dari Telegram dan animasi aktif pada aplikasi penerima.');
        $product = Product::query()->find($state['product_id']);
        if ($text !== '' && $product) {
            $expected = $this->content->entities($product, $state['field']);
            $preview = $this->bot->sendMessage($chatId, $text, [], ['entities' => $expected]);
            $returnedIds = array_column($preview['result']['entities'] ?? [], 'custom_emoji_id');
            if (array_diff(array_column($expected, 'custom_emoji_id'), $returnedIds) !== []) {
                $this->bot->sendMessage($chatId, 'Teks dan ID emoji tersimpan, tetapi Telegram tidak mengembalikan emoji premium pada preview. Pastikan PEMILIK bot memiliki Telegram Premium atau bot memiliki username tambahan dari Fragment. Emoji biasa tetap tampil; buka /admin dan kirim ulang setelah izin aktif.');
            }
        }

        return true;
    }

    private function key(Customer $customer): string
    {
        return 'telegram:content-edit:'.$customer->telegram_id;
    }
}
