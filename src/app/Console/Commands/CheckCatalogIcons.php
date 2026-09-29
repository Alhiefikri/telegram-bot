<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\TelegramUiIcon;
use App\Services\Telegram\CustomEmojiCapability;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Console\Command;
use Throwable;

final class CheckCatalogIcons extends Command
{
    protected $signature = 'catalog:icons-check {--reset-capability : Forget a remembered custom emoji rejection}';

    protected $description = 'Audit captured product, menu, and message-copy custom emoji identifiers and the custom emoji capability flag.';

    public function handle(TelegramBotClient $bot, CustomEmojiCapability $customEmoji): int
    {
        if ($this->option('reset-capability')) {
            $customEmoji->markAvailable();
            $this->info('Custom emoji capability flag cleared.');
        }

        $reason = $customEmoji->unavailableReason();
        if ($reason !== null) {
            $this->warn('Custom emoji is currently disabled after a rejection: '.$reason);
        }

        $products = Product::query()
            ->whereNotNull('custom_emoji_id')
            ->where('custom_emoji_id', '!=', '')
            ->orderBy('name')
            ->get(['id', 'name', 'custom_emoji_id']);
        $menuIcons = TelegramUiIcon::query()
            ->where('key', 'like', 'menu.%')
            ->whereNotNull('custom_emoji_id')
            ->where('custom_emoji_id', '!=', '')
            ->catalogOrder()
            ->get(['key', 'custom_emoji_id']);
        $messageIcons = TelegramUiIcon::query()
            ->where('key', 'like', 'message.%')
            ->whereNotNull('custom_emoji_id')
            ->where('custom_emoji_id', '!=', '')
            ->catalogOrder()
            ->get(['key', 'custom_emoji_id']);

        // Identifiers are numeric strings, so they must stay in a list: as array
        // keys PHP casts them to integers and Telegram rejects the request.
        $ids = [];
        $labels = [];
        $collect = static function (string $id, string $label) use (&$ids, &$labels): void {
            $id = trim($id);
            if ($id === '') {
                return;
            }
            if (! isset($labels[$id])) {
                $ids[] = $id;
            }
            $labels[$id] = $label;
        };

        foreach ($products as $product) {
            $collect((string) $product->custom_emoji_id, 'product #'.$product->getKey().' ('.$product->name.')');
        }
        foreach ($menuIcons as $icon) {
            $collect((string) $icon->custom_emoji_id, 'menu '.$icon->key);
        }
        foreach ($messageIcons as $icon) {
            $collect((string) $icon->custom_emoji_id, 'message-copy '.$icon->key);
        }

        $this->line(sprintf(
            'Captured identifiers: %d (products: %d, menu slots: %d, message-copy slots: %d)',
            count($ids),
            $products->count(),
            $menuIcons->count(),
            $messageIcons->count(),
        ));

        if ($ids === []) {
            $this->info('No captured custom emoji identifiers to verify.');

            return self::SUCCESS;
        }

        try {
            $resolved = $this->resolveStickers($bot, $ids);
        } catch (Throwable $exception) {
            $this->error('Could not verify the identifiers: '.$exception->getMessage());

            return self::FAILURE;
        }

        $missing = [];
        foreach ($ids as $id) {
            $label = $labels[$id];
            if (isset($resolved[$id])) {
                $this->info(sprintf('OK   %s → %s %s', $label, $resolved[$id], $id));

                continue;
            }

            $missing[] = $label;
            $this->error(sprintf('MISS %s → %s', $label, $id));
        }

        if ($missing !== []) {
            $this->warn('Identifiers that no longer resolve: '.implode(', ', $missing));
        }

        return $missing === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function resolveStickers(TelegramBotClient $bot, array $ids): array
    {
        $resolved = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $response = $bot->getCustomEmojiStickers($chunk);

            foreach ((array) ($response['result'] ?? []) as $sticker) {
                $id = is_array($sticker) ? (string) ($sticker['custom_emoji_id'] ?? '') : '';
                if ($id !== '') {
                    $resolved[$id] = is_array($sticker) ? (string) ($sticker['emoji'] ?? '') : '';
                }
            }
        }

        return $resolved;
    }
}
