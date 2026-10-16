<?php

namespace Tests\Feature\Telegram;

use App\Models\CustomerUsdtFundingPolicy;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramUsdtBalanceIntents;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\UsdtBalance\CustomerUsdtActivityService;
use App\Services\UsdtTopUps\UsdtTopUpPreparation;
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
        $this->assertStringContainsString('Nominal diajukan: 1 USDT', $statusSurface->caption);
        $this->assertStringContainsString('USDT diterima terverifikasi: 1 USDT', $statusSurface->caption);
        $this->assertStringContainsString('Saldo sebelum kredit: 0 USDT', $statusSurface->caption);
        $this->assertStringContainsString('Saldo setelah kredit: 1 USDT', $statusSurface->caption);
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

    public function test_bsc_fee_and_verified_receipt_remain_historical_snapshots_after_policy_change(): void
    {
        $this->customer->update(['locale' => 'en']);
        $this->settle();
        $this->travel(20)->minutes();
        CustomerUsdtFundingPolicy::current()->update(['application_fee_micro' => 100_000, 'version' => 2]);
        $actions = app(TelegramUsdtFundingActions::class);
        $intent = $actions->preview('951001', '951001', 'private', 100, '1')->intent;
        $this->target = $actions->callback('951001', '951001', 'private', 100, 'usdt:confirm:'.$intent->nonce)->topUp;
        app(UsdtTopUpPreparation::class)->prepare($this->target->id);
        $this->rpc->timestamp = now()->timestamp + 5;
        $hash = '0x'.str_repeat('c', 64);
        $this->rpc->receipt['transactionHash'] = $hash;
        $this->rpc->receipt['logs'] = [$this->rpc->log(amount: '1100000000000000000')];
        $this->rpc->receipt['logs'][0]['transactionHash'] = $hash;
        $this->settle($hash);
        CustomerUsdtFundingPolicy::current()->update(['application_fee_micro' => 0, 'version' => 3]);

        $status = app(UsdtTopUpStatusReader::class)->for($this->target);
        $this->assertSame('settled', $status->state);
        $this->assertSame(1_100_000, $status->receivedMicro);
        $this->assertSame(1_000_000, $status->creditMicro);
        $this->assertSame(1_000_000, $status->balanceBeforeMicro);
        $this->assertSame(2_000_000, $status->balanceAfterMicro);
        $surface = app(TelegramUsdtBalanceIntents::class)->statusSurface($this->customer, $this->target, $status);
        $receipt = TelegramOutboundEffect::query()->where('source_id', $this->target->id)->sole()->outcome_metadata['message'];
        foreach ([$surface->caption, $receipt] as $copy) {
            $this->assertStringContainsString('Submitted amount: 1 USDT', $copy);
            $this->assertStringContainsString('Verified received: 1.1 USDT', $copy);
            $this->assertStringContainsString('Application fee: 0.1 USDT', $copy);
            $this->assertStringContainsString('Balance before credit: 1 USDT', $copy);
            $this->assertStringContainsString('Balance after credit: 2 USDT', $copy);
            $this->assertStringContainsString($this->target->reference, $copy);
            $this->assertDoesNotMatchRegularExpression('/\bUSD\b/', $copy);
        }
        $this->assertSame(2, DB::table('customer_usdt_balance_entries')->count());
    }
}
