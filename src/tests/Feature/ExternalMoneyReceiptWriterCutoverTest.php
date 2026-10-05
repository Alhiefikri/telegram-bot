<?php

namespace Tests\Feature;

use App\Enums\ExternalMoneyReceiptClaimOutcome;
use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\Customer;
use App\Models\ExternalMoneyReceiptReservation;
use App\Models\Payment;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Services\MoneyReceipts\ExternalMoneyReceiptWriter;
use App\Services\MoneyReceipts\MoneyReceiptIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExternalMoneyReceiptWriterCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_payment_claims_conflict_across_targets_and_reserve_the_loser(): void
    {
        $writer = app(ExternalMoneyReceiptWriter::class);
        $first = $this->klikQrisPayment('INV-A');
        $second = $this->klikQrisPayment('INV-B');

        $this->assertTrue($writer->claimProviderPayment($first)->allowsMoneyEffect());

        $outcome = $writer->claimProviderPayment($second, 'INV-A');

        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Conflict, $outcome);
        $this->assertDatabaseCount('external_money_receipts', 1);
        $this->assertDatabaseHas('external_money_receipt_reservations', [
            'source' => 'runtime',
            'legacy_type' => 'payment',
            'legacy_id' => $second->id,
            'reason' => ExternalMoneyReceiptReservation::REASON_REGISTRY_CONFLICT,
            'resolved_at' => null,
        ]);
    }

    public function test_store_balance_top_up_claims_are_scoped_by_provider_and_account_namespace(): void
    {
        $writer = app(ExternalMoneyReceiptWriter::class);
        $first = $this->topUp('klikqris', 'TRX-1');
        $second = $this->topUp('klikqris', 'TRX-1');
        $otherProvider = $this->topUp('sakurupiah', 'TRX-1');

        $this->assertTrue($writer->claimStoreBalanceTopUp($first)->allowsMoneyEffect());
        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Conflict, $writer->claimStoreBalanceTopUp($second));
        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Claimed, $writer->claimStoreBalanceTopUp($otherProvider));

        $this->assertDatabaseCount('external_money_receipts', 2);
        $this->assertDatabaseHas('external_money_receipts', [
            'provider' => 'sakurupiah',
            'reference' => 'TRX-1',
            'target_id' => $otherProvider->id,
        ]);
    }

    public function test_owner_attestation_uses_a_dedicated_namespace_and_replays(): void
    {
        $writer = app(ExternalMoneyReceiptWriter::class);
        $payment = $this->klikQrisPayment('INV/OWNER 100%');
        $payment->update(['provider' => 'manual', 'method' => 'QR_MANUAL']);

        $this->assertTrue($writer->claimOwnerAttestation($payment)->allowsMoneyEffect());
        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Replayed, $writer->claimOwnerAttestation($payment));

        $this->assertDatabaseHas('external_money_receipts', [
            'provider' => 'manual',
            'account_namespace' => 'owner_attestation',
            'reference' => hash('sha256', 'INV/OWNER 100%'),
            'target_id' => $payment->id,
        ]);
    }

    public function test_compensation_reservations_are_idempotent_and_resolvable(): void
    {
        $writer = app(ExternalMoneyReceiptWriter::class);
        $topUp = $this->topUp('klikqris', 'TRX-COMP-1');

        $writer->reserveCompensatedUnresolvedMoney($topUp, 'TRX-COMP-1');
        $writer->reserveCompensatedUnresolvedMoney($topUp, 'TRX-COMP-1');

        $this->assertSame(1, ExternalMoneyReceiptReservation::query()->count());
        $this->assertNull(ExternalMoneyReceiptReservation::query()->sole()->resolved_at);

        $this->assertSame(1, $writer->resolveCompensationReservation($topUp));
        $this->assertNotNull(ExternalMoneyReceiptReservation::query()->sole()->resolved_at);
    }

    public function test_runtime_and_backfill_derive_identical_identities_including_encoded_references(): void
    {
        config(['services.binance.pay_id' => '123456789', 'services.binance.pay_uid' => null]);
        $payment = $this->klikQrisPayment('INV/ORDER 100% A+B');
        $writer = app(ExternalMoneyReceiptWriter::class);

        $this->assertTrue($writer->claimProviderPayment($payment)->allowsMoneyEffect());

        [$exit, $output] = $this->backfill('qris_payments');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('claimed=0 replayed=1', $output);
        $this->assertDatabaseCount('external_money_receipts', 1);
        $this->assertDatabaseHas('external_money_receipts', [
            'canonical_key' => MoneyReceiptIdentity::providerReference(
                'klikqris',
                'default',
                hash('sha256', 'INV/ORDER 100% A+B'),
            )->canonicalKey(),
            'target_id' => $payment->id,
        ]);
    }

    /** @return array{0: int, 1: string} */
    private function backfill(string $source): array
    {
        $exit = Artisan::call('balance:backfill-money-receipts', [
            '--source' => $source,
            '--chunk' => 100,
            '--limit' => 0,
        ]);

        return [$exit, Artisan::output()];
    }

    private function klikQrisPayment(string $merchantRef): Payment
    {
        return Payment::factory()->create([
            'provider' => 'klikqris',
            'provider_payment_method' => 'qris',
            'method' => 'qris',
            'merchant_ref' => $merchantRef,
            'provider_reference' => null,
            'amount' => 25000,
            'currency' => 'IDR',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    private function topUp(string $provider, string $reference): StoreBalanceTopUp
    {
        $customer = Customer::factory()->create(['locale' => 'en']);
        $intent = StoreBalanceFundingIntent::create([
            'customer_id' => $customer->id,
            'telegram_navigation_root_id' => null,
            'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => 1,
            'policy_version' => 1,
            'amount' => 5000,
            'nonce' => Str::random(32),
            'status' => StoreBalanceFundingIntentStatus::Confirmed,
            'expires_at' => now()->addMinutes(10),
            'claimed_at' => now(),
        ]);

        return StoreBalanceTopUp::create([
            'customer_id' => $customer->id,
            'store_balance_funding_intent_id' => $intent->id,
            'telegram_navigation_root_id' => null,
            'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => 1,
            'locale' => 'en',
            'provider' => $provider,
            'merchant_ref' => 'BAL-'.Str::upper(Str::random(12)),
            'idempotency_key' => 'top-up-'.Str::random(16),
            'currency' => 'IDR',
            'amount' => 5000,
            'status' => StoreBalanceTopUpStatus::Paid,
            'provider_reference' => $reference,
            'provider_fee' => 0,
            'provider_total_payment' => 5000,
            'provider_payment_method' => 'qris',
            'provider_created_at' => now()->subMinutes(2),
            'provider_expires_at' => now()->addMinutes(10),
            'expires_at' => now()->addMinutes(10),
            'paid_at' => now(),
            'settled_at' => now(),
            'provider_create_state' => 'provider_SUCCESS',
        ]);
    }
}
