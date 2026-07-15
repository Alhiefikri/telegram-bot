<?php

namespace Tests\Unit\Services\Telegram;

use App\Services\Telegram\TelegramMessageChunker;
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
}
