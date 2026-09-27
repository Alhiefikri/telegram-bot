<?php

namespace App\Console\Commands;

use App\Models\CatalogBrandIcon;
use App\Services\Telegram\CustomEmojiCapability;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Console\Command;
use Throwable;

final class CheckCatalogBrandIcons extends Command
{
    protected $signature = 'catalog:brand-icons-check {--reset-capability : Forget a remembered custom emoji rejection}';

    protected $description = 'Audit captured brand custom emoji identifiers and the custom emoji capability flag.';

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

        $brands = CatalogBrandIcon::query()->catalogOrder()->get();
        $ids = [];
        foreach ($brands as $brand) {
            $id = trim((string) $brand->custom_emoji_id);
            if ($id !== '') {
                $ids[$id] = (string) $brand->slug;
            }
        }

        $this->line(sprintf('Brands: %d, with a captured custom emoji: %d', $brands->count(), count($ids)));

        if ($ids === []) {
            $this->info('No captured custom emoji identifiers to verify.');

            return self::SUCCESS;
        }

        try {
            $resolved = $this->resolveStickers($bot, array_keys($ids));
        } catch (Throwable $exception) {
            $this->error('Could not verify the identifiers: '.$exception->getMessage());

            return self::FAILURE;
        }

        $missing = [];
        foreach ($ids as $id => $slug) {
            if (isset($resolved[$id])) {
                $this->info(sprintf('OK   %s → %s %s', $slug, $resolved[$id], $id));

                continue;
            }

            $missing[] = $slug;
            $this->error(sprintf('MISS %s → %s', $slug, $id));
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
