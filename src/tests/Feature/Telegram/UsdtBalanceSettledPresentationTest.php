<?php

namespace Tests\Feature\Telegram;

use App\Services\Telegram\TelegramUsdtBalanceIntents;
use App\Services\UsdtBalance\CustomerUsdtActivityService;
use App\Services\UsdtTopUps\UsdtTopUpStatusReader;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsdtTopUpTestCase;

/**
 * U9 presentation against a really settled U7 target. This base class is used
 * instead of the webhook harness because settlement requires preparing a rail
 * outside a wrapping test transaction.
 */
final class UsdtBalanceSettledPresentationTest extends UsdtTopUpTestCase
{
    public function test_settled_target_renders_credited_status_and_history_without_new_outbound_effects(): void
    {
        $this->customer->update(['locale' => 'id']);
        $this->settle();
        $target = $this->target->fresh();
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());

        $intents = app(TelegramUsdtBalanceIntents::class);
        $statuses = app(UsdtTopUpStatusReader::class);
        $status = $statuses->for($target);

        $this->assertSame('settled', $status->state);
        $this->assertTrue($status->isTerminal());
        $this->assertFalse($status->acceptsCustomerLookup());

        $statusSurface = $intents->statusSurface($this->customer, $target, $status);
        $this->assertStringContainsString('Status: Saldo bertambah', $statusSurface->caption);
        $this->assertStringContainsString('Saldo USDT sudah dikreditkan sesuai ledger', $statusSurface->caption);
        $this->assertStringContainsString('Kredit: 1 USDT', $statusSurface->caption);
        $markup = json_encode($statusSurface->keyboard, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('usdt:check:', $markup);
        $this->assertStringNotContainsString('usdt:hash:', $markup);
        $this->assertStringContainsString('usdt:history', $markup);

        $page = app(CustomerUsdtActivityService::class)->firstPage($this->customer);
        $historySurface = $intents->historySurface($this->customer, $page);
        $this->assertStringContainsString('Kredit top-up · Dikreditkan', $historySurface->caption);
        $this->assertStringContainsString('+1 USDT', $historySurface->caption);
        $this->assertStringNotContainsString('Top-up USDT ·', $historySurface->caption);
        $this->assertStringNotContainsString('Rp', $historySurface->caption);

        // The settled receipt stays owned by the U7 outbound seam: rendering
        // adds no effect, and the only effect row is the USDT receipt.
        $this->assertSame(1, DB::table('telegram_outbound_effects')->count());
        $this->assertSame('usdt.top_up_receipt', DB::table('telegram_outbound_effects')->value('effect_type'));
    }
}
