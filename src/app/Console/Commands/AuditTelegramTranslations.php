<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramTranslationCatalog;
use Illuminate\Console\Command;

class AuditTelegramTranslations extends Command
{
    protected $signature = 'telegram:translation-audit';

    protected $description = 'Validate the customer-facing Telegram translation catalogs';

    public function handle(TelegramTranslationCatalog $catalog): int
    {
        $errors = $catalog->audit();
        if ($errors === []) {
            $this->info('Telegram translation audit passed for id, en, and ms.');

            return self::SUCCESS;
        }

        $this->error('Telegram translation audit failed:');
        foreach ($errors as $error) {
            $this->line('- '.$error);
        }

        return self::FAILURE;
    }
}
