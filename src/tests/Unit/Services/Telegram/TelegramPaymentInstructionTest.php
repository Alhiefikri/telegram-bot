<?php

namespace Tests\Unit\Services\Telegram;

use App\Services\Telegram\CustomEmojiCapability;
use App\Services\Telegram\TelegramPaymentInstruction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TelegramPaymentInstructionTest extends TestCase
{
    #[DataProvider('amountLabels')]
    public function test_only_the_final_payable_number_is_copyable_even_when_invoice_and_subtotal_have_digits(string $label): void
    {
        $instruction = new TelegramPaymentInstruction(
            photoContents: 'qr',
            filename: 'qr.png',
            captionLines: ['🧾 INV-125000', 'Produk 125.000', $label.'Rp 125.875', 'Batas: 10 Nov 2026'],
            copyableAmountLine: 2,
        );
        $entity = $instruction->options()['caption_entities'][0];
        $utf16 = mb_convert_encoding($instruction->caption(), 'UTF-16LE', 'UTF-8');
        $selected = mb_convert_encoding(substr($utf16, $entity['offset'] * 2, $entity['length'] * 2), 'UTF-8', 'UTF-16LE');

        $this->assertSame('code', $entity['type']);
        $this->assertSame('125.875', $selected);
    }

    public function test_custom_emoji_before_the_total_does_not_shift_the_copy_target(): void
    {
        $instruction = new TelegramPaymentInstruction(
            photoContents: 'qr',
            filename: 'qr.png',
            captionLines: ['<tg-emoji emoji-id="12345" data-omp-icon="1">🧾</tg-emoji> Invoice 987', 'Total: Rp 10.125'],
            copyableAmountLine: 1,
        );
        $rendered = (new CustomEmojiCapability)->textAndEntities(
            $instruction->caption(),
            $instruction->options()['caption_entities'],
        );
        $code = collect($rendered['entities'])->firstWhere('type', 'code');
        $utf16 = mb_convert_encoding($rendered['text'], 'UTF-16LE', 'UTF-8');

        $this->assertSame('10.125', mb_convert_encoding(
            substr($utf16, $code['offset'] * 2, $code['length'] * 2),
            'UTF-8',
            'UTF-16LE',
        ));
    }

    public static function amountLabels(): array
    {
        return [
            'Indonesia' => ['💳 Total bayar: '],
            'Melayu' => ['💳 Jumlah bayaran: '],
            'English' => ['💳 Total to pay: '],
        ];
    }
}
