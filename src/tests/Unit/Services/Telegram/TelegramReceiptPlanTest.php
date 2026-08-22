<?php

namespace Tests\Unit\Services\Telegram;

use App\Services\Telegram\TelegramMessageUnit;
use App\Services\Telegram\TelegramReceiptPlan;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelegramReceiptPlanTest extends TestCase
{
    #[DataProvider('photoCaptionBoundaries')]
    public function test_photo_caption_boundary_is_enforced(int $length, bool $valid): void
    {
        if (! $valid) {
            $this->expectException(InvalidArgumentException::class);
        }

        $plan = new TelegramReceiptPlan(
            photoUnits: [new TelegramMessageUnit(str_repeat('x', $length))],
            textUnits: [new TelegramMessageUnit('x')],
            productCount: 1,
            accountCount: 1,
        );

        if ($valid) {
            $this->assertSame($length, $plan->photoUnit(1)->characterLength());
        }
    }

    #[DataProvider('messageTextBoundaries')]
    public function test_continuation_boundary_is_enforced(int $length, bool $valid): void
    {
        if (! $valid) {
            $this->expectException(InvalidArgumentException::class);
        }

        $plan = new TelegramReceiptPlan(
            photoUnits: [new TelegramMessageUnit('caption')],
            textUnits: [new TelegramMessageUnit(str_repeat('x', $length))],
            productCount: 1,
            accountCount: 1,
        );

        if ($valid) {
            $this->assertSame($length, $plan->textUnit(1)->characterLength());
        }
    }

    public function test_debug_and_serialization_do_not_expose_plan_content(): void
    {
        $secret = 'plan-secret@example.test';
        $plan = new TelegramReceiptPlan(
            photoUnits: [new TelegramMessageUnit($secret)],
            textUnits: [new TelegramMessageUnit($secret)],
            productCount: 1,
            accountCount: 1,
        );

        $this->assertArrayNotHasKey('text', $plan->__debugInfo());
        $this->assertStringNotContainsString($secret, json_encode($plan->__debugInfo(), JSON_THROW_ON_ERROR));

        $this->expectException(LogicException::class);
        serialize($plan);
    }

    /** @return iterable<string, array{int, bool}> */
    public static function photoCaptionBoundaries(): iterable
    {
        yield '1023' => [1023, true];
        yield '1024' => [1024, true];
        yield '1025' => [1025, false];
    }

    /** @return iterable<string, array{int, bool}> */
    public static function messageTextBoundaries(): iterable
    {
        yield '4095' => [4095, true];
        yield '4096' => [4096, true];
        yield '4097' => [4097, false];
    }
}
