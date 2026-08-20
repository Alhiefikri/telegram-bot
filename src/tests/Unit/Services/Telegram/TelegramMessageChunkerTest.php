<?php

namespace Tests\Unit\Services\Telegram;

use App\Services\Telegram\TelegramMessageChunker;
use App\Services\Telegram\TelegramMessageUnit;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TelegramMessageChunkerTest extends TestCase
{
    public function test_it_keeps_message_chunks_under_the_configured_limit(): void
    {
        $chunks = (new TelegramMessageChunker)->fromLines(['first', str_repeat('x', 12), 'last'], 10);

        $this->assertSame(['first', str_repeat('x', 10), str_repeat('x', 2)."\nlast"], $chunks);
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(10, mb_strlen($chunk, 'UTF-8'));
        }
    }

    public function test_it_preserves_atomic_credential_units_and_their_markup(): void
    {
        $unit = new TelegramMessageUnit(
            "Data akun #1\nAuth Token : token-value",
            [['type' => 'code', 'offset' => 24, 'length' => 11]],
            ['inline_keyboard' => [[['text' => 'Buka OTP Mail #1', 'url' => 'https://mail.example.test/otp']]]],
        );

        $this->assertSame([$unit], (new TelegramMessageChunker)->fromAtomicUnits([$unit], 100));
        $this->assertSame($unit->text(), "Data akun #1\nAuth Token : token-value");
        $this->assertSame($unit->replyMarkup()['inline_keyboard'][0][0]['text'], 'Buka OTP Mail #1');
    }

    public function test_it_refuses_to_split_an_oversized_atomic_unit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TelegramMessageChunker)->fromAtomicUnits([
            new TelegramMessageUnit(str_repeat('x', 11)),
        ], 10);
    }
}
