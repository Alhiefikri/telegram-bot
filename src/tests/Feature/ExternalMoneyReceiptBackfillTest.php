<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\UserRole;
use App\Models\BscPaymentTransferEvidence;
use App\Models\Customer;
use App\Models\ExternalMoneyReceipt;
use App\Models\ExternalMoneyReceiptBackfillState;
use App\Models\ExternalMoneyReceiptReservation;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\User;
use App\Services\MoneyReceipts\ExternalMoneyReceiptClaims;
use App\Services\MoneyReceipts\ExternalMoneyReceiptCutoverReport;
use App\Services\MoneyReceipts\MoneyReceiptIdentity;
use App\Services\MoneyReceipts\MoneyReceiptTarget;
use App\Services\MoneyReceipts\VerifiedMoneyReceipt;
use App\Services\StoreBalance\StoreBalanceOwnerCompensationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExternalMoneyReceiptBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.binance.pay_id' => '123456789',
            'services.binance.pay_uid' => null,
            'services.bsc.chain_id' => 56,
        ]);
    }

    public function test_a_dry_run_reports_legacy_rows_without_writing_receipts_reservations_or_checkpoints(): void
    {
        $payment = $this->binancePayment();

        [$exit, $output] = $this->backfill('binance_payments', ['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('mode=dry_run', $output);
        $this->assertStringContainsString('scanned=1 claimed=1', $output);
        $this->assertDatabaseCount('external_money_receipts', 0);
        $this->assertDatabaseCount('external_money_receipt_reservations', 0);
        $this->assertDatabaseCount('external_money_receipt_backfill_states', 0);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame('TXN-DRY-RUN', $payment->fresh()->provider_reference);
    }

    public function test_backfill_claims_settled_payment_and_top_up_receipts_for_their_historical_targets(): void
    {
        $binance = $this->binancePayment();
        $bsc = $this->bscPayment();
        $qris = $this->qrisPayment(['merchant_ref' => 'INV-QRIS-1']);
        $manual = $this->qrisPayment([
            'provider' => 'manual',
            'method' => 'QR_MANUAL',
            'merchant_ref' => 'INV-MANUAL-1',
        ]);
        $topUp = $this->paidTopUp(['provider_reference' => 'TRX-TOPUP-1']);

        [$exit, $output] = $this->backfill();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('source=binance_payments', $output);
        $this->assertStringContainsString('ready=true', $output);

        $this->assertDatabaseHas('external_money_receipts', [
            'provider' => 'binance',
            'identity_kind' => 'provider_reference',
            'account_namespace' => 'pay_id:123456789',
            'reference' => 'TXN-DRY-RUN',
            'status' => 'claimed',
            'target_type' => 'order_payment',
            'target_id' => $binance->id,
        ]);
        $this->assertDatabaseHas('external_money_receipts', [
            'provider' => 'bsc',
            'identity_kind' => 'bsc_log',
            'chain_id' => 56,
            'log_index' => 0,
            'status' => 'claimed',
            'target_type' => 'order_payment',
            'target_id' => $bsc['payment']->id,
        ]);
        $this->assertDatabaseHas('external_money_receipt_parent_claims', [
            'chain_id' => 56,
            'tx_hash' => $bsc['evidence']->tx_hash,
            'status' => 'claimed',
            'target_id' => $bsc['payment']->id,
        ]);
        $this->assertDatabaseHas('external_money_receipts', [
            'provider' => 'klikqris',
            'reference' => 'INV-QRIS-1',
            'status' => 'claimed',
            'target_id' => $qris->id,
        ]);
        $this->assertDatabaseHas('external_money_receipts', [
            'provider' => 'manual',
            'account_namespace' => 'owner_attestation',
            'reference' => 'INV-MANUAL-1',
            'status' => 'claimed',
            'target_id' => $manual->id,
        ]);
        $this->assertDatabaseHas('external_money_receipts', [
            'provider' => 'klikqris',
            'reference' => 'TRX-TOPUP-1',
            'status' => 'claimed',
            'target_type' => 'store_balance_top_up',
            'target_id' => $topUp->id,
        ]);

        $this->assertSame(PaymentStatus::Paid, $binance->fresh()->status);
        $this->assertSame('TXN-DRY-RUN', $binance->fresh()->provider_reference);
        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->fresh()->status);
        $this->assertSame('TRX-TOPUP-1', $topUp->fresh()->provider_reference);
        $this->assertTrue(
            ExternalMoneyReceiptBackfillState::query()->where('source', 'binance_payments')->sole()->isReady(),
        );
    }

    public function test_a_second_backfill_run_replays_existing_receipts_without_duplicates(): void
    {
        $this->binancePayment();
        $this->paidTopUp();

        $this->assertSame(0, $this->backfill()[0]);
        $receipts = ExternalMoneyReceipt::query()->count();

        [, $binanceOutput] = $this->backfill('binance_payments');
        $this->assertStringContainsString('claimed=0 replayed=1', $binanceOutput);

        [, $topUpOutput] = $this->backfill('qris_top_ups');
        $this->assertStringContainsString('claimed=0 replayed=1', $topUpOutput);

        $this->assertSame($receipts, ExternalMoneyReceipt::query()->count());
    }

    public function test_an_interrupted_backfill_resumes_from_its_recorded_cursor(): void
    {
        $this->binancePayment(['provider_reference' => 'TXN-1']);
        $this->binancePayment(['provider_reference' => 'TXN-2']);
        $this->binancePayment(['provider_reference' => 'TXN-3']);

        [$exit, $output] = $this->backfill('binance_payments', ['--limit' => 1]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('scanned=1', $output);
        $this->assertStringContainsString('complete=false', $output);
        $this->assertSame(1, ExternalMoneyReceipt::query()->where('provider', 'binance')->count());
        $state = ExternalMoneyReceiptBackfillState::query()->where('source', 'binance_payments')->sole();
        $this->assertNotNull($state->cursor);
        $this->assertSame('pending', $state->status);

        [$exit, $output] = $this->backfill('binance_payments');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('scanned=3', $output);
        $this->assertStringContainsString('complete=true', $output);
        $this->assertStringContainsString('ready=true', $output);
        $this->assertSame(3, ExternalMoneyReceipt::query()->where('provider', 'binance')->count());
        $this->assertTrue($state->fresh()->isReady());
    }

    public function test_a_missing_receiver_snapshot_is_reserved_and_blocks_readiness_instead_of_guessing_from_config(): void
    {
        $payment = $this->binancePayment([
            'binance_receiver_type' => null,
            'binance_receiver_value' => null,
        ]);

        [$exit, $output] = $this->backfill('binance_payments');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('reserved=1', $output);
        $this->assertDatabaseCount('external_money_receipts', 0);
        $this->assertDatabaseHas('external_money_receipt_reservations', [
            'source' => 'binance_payments',
            'legacy_type' => 'payment',
            'legacy_id' => $payment->id,
            'reason' => ExternalMoneyReceiptReservation::REASON_MISSING_RECEIVER_NAMESPACE,
            'resolved_at' => null,
        ]);
    }

    public function test_a_conflicting_registry_identity_is_reserved_instead_of_being_reclaimed(): void
    {
        $payment = $this->binancePayment(['provider_reference' => 'TXN-CONFLICT']);
        $topUp = $this->paidTopUp();

        // Another adapter target already claimed the same physical transfer.
        $conflicting = new VerifiedMoneyReceipt(
            identity: MoneyReceiptIdentity::binancePay('pay_id', '123456789', 'TXN-CONFLICT'),
            beneficiary: 'pay_id:123456789',
            currency: 'USDT',
            amountUnits: '10000',
            decimals: 6,
            target: MoneyReceiptTarget::storeBalanceTopUp((string) $topUp->id),
        );
        $this->assertTrue(
            app(ExternalMoneyReceiptClaims::class)->claim($conflicting)->allowsMoneyEffect(),
        );

        [$exit] = $this->backfill('binance_payments');

        $this->assertSame(1, $exit);
        $this->assertDatabaseHas('external_money_receipt_reservations', [
            'legacy_type' => 'payment',
            'legacy_id' => $payment->id,
            'reason' => 'registry_conflict',
            'resolved_at' => null,
        ]);
        $this->assertDatabaseHas('external_money_receipts', [
            'canonical_key' => $conflicting->canonicalKey(),
            'target_type' => 'store_balance_top_up',
            'target_id' => $topUp->id,
        ]);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_compensated_funding_sources_are_reserved_as_unresolved_money(): void
    {
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200));
        $customer = Customer::factory()->create(['locale' => 'en']);
        $topUp = $this->topUpForCustomer($customer, [
            'status' => StoreBalanceTopUpStatus::Failed,
            'provider_created_at' => now()->subHour(),
            'provider_reference' => 'TRX-LOST-1',
        ]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        app(StoreBalanceOwnerCompensationService::class)->release(
            $topUp->id,
            $owner,
            'TRX-LOST-1',
            'Provider money never settled.',
        );

        [$exit, $output] = $this->backfill('compensations');

        // The completed scan is not ready because the compensated funding
        // source stays reserved for operator review.
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('source=compensations', $output);
        $this->assertStringContainsString('scanned=1', $output);
        $this->assertStringContainsString('reserved=1', $output);
        $this->assertSame(
            1,
            ExternalMoneyReceiptReservation::query()
                ->where('legacy_type', 'store_balance_top_up')
                ->where('legacy_id', $topUp->id)
                ->where('reason', ExternalMoneyReceiptReservation::REASON_COMPENSATED_UNRESOLVED_MONEY)
                ->count(),
        );

        // A second scan replays the same reservation without duplicating it.
        $this->backfill('compensations');
        $this->assertSame(1, ExternalMoneyReceiptReservation::query()->count());
    }

    public function test_the_cutover_report_blocks_on_open_reservations_and_resolution_clears_them(): void
    {
        $payment = $this->binancePayment([
            'binance_receiver_type' => null,
            'binance_receiver_value' => null,
        ]);

        [$exit] = $this->backfill('binance_payments');
        $this->assertSame(1, $exit);
        $reservation = ExternalMoneyReceiptReservation::query()->sole();

        $this->artisan('balance:money-receipt-cutover-report --require-ready --json')
            ->expectsOutputToContain('"ready":false')
            ->assertFailed();

        [$exit, $output] = $this->runResolveCommand($reservation->id);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Resolved reservation', $output);
        $this->assertNotNull($reservation->fresh()->resolved_at);
        $this->assertSame(0, app(ExternalMoneyReceiptCutoverReport::class)->summary()['reservations']['open']);
        $this->assertContains('owner_compensation', array_keys(app(ExternalMoneyReceiptCutoverReport::class)->summary()['writers']));
    }

    /** @return array{0: int, 1: string} */
    private function backfill(string $source = 'all', array $options = []): array
    {
        $exit = Artisan::call('balance:backfill-money-receipts', array_merge([
            '--source' => $source,
            '--chunk' => 100,
            '--limit' => 0,
        ], $options));

        return [$exit, Artisan::output()];
    }

    /** @return array{0: int, 1: string} */
    private function runResolveCommand(int $id): array
    {
        $exit = Artisan::call('balance:resolve-money-receipt-reservation', [
            'id' => $id,
            '--note' => 'Reviewed provider evidence.',
        ]);

        return [$exit, Artisan::output()];
    }

    private function binancePayment(array $overrides = []): Payment
    {
        return Payment::factory()->create(array_replace([
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'merchant_ref' => 'MR-'.Str::upper(Str::random(10)),
            'provider_reference' => 'TXN-DRY-RUN',
            'binance_receiver_type' => 'pay_id',
            'binance_receiver_value' => '123456789',
            'amount' => 10000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ], $overrides));
    }

    private function qrisPayment(array $overrides = []): Payment
    {
        return Payment::factory()->create(array_replace([
            'provider' => 'klikqris',
            'provider_payment_method' => 'qris',
            'method' => 'qris',
            'merchant_ref' => 'INV-'.Str::upper(Str::random(10)),
            'provider_reference' => null,
            'amount' => 25000,
            'currency' => 'IDR',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ], $overrides));
    }

    /** @return array{payment: Payment, evidence: BscPaymentTransferEvidence} */
    private function bscPayment(): array
    {
        $payment = Payment::factory()->create([
            'provider' => 'manual',
            'method' => 'USDT_BEP20',
            'merchant_ref' => 'MR-BSC-'.Str::upper(Str::random(8)),
            'provider_reference' => null,
            'amount' => 1_000_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
        PaymentBscDetail::factory()->forPayment($payment)->create();
        $payment->update(['provider_reference' => '0x'.str_repeat('ab', 32)]);

        $evidence = BscPaymentTransferEvidence::factory()->create([
            'payment_id' => $payment->id,
            'tx_hash' => '0x'.str_repeat('ab', 32),
            'log_index' => 0,
            'amount_units' => '1000000000000000000',
            'receiver_address' => '0x'.str_repeat('cd', 20),
            'verification_status' => PaymentBscDetail::STATUS_CONFIRMED,
        ]);

        return ['payment' => $payment, 'evidence' => $evidence];
    }

    private function paidTopUp(array $overrides = []): StoreBalanceTopUp
    {
        return $this->topUpForCustomer(Customer::factory()->create(['locale' => 'en']), array_replace([
            'provider' => 'klikqris',
            'provider_reference' => 'TRX-'.Str::upper(Str::random(8)),
            'status' => StoreBalanceTopUpStatus::Paid,
            'paid_at' => now(),
            'settled_at' => now(),
        ], $overrides));
    }

    private function topUpForCustomer(Customer $customer, array $overrides = []): StoreBalanceTopUp
    {
        $expiresAt = now()->addMinutes(10)->startOfSecond();
        $intent = StoreBalanceFundingIntent::create([
            'customer_id' => $customer->id,
            'telegram_navigation_root_id' => null,
            'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => 1,
            'policy_version' => 1,
            'amount' => 5000,
            'nonce' => Str::random(32),
            'status' => StoreBalanceFundingIntentStatus::Confirmed,
            'expires_at' => $expiresAt,
            'claimed_at' => now(),
        ]);

        return StoreBalanceTopUp::create(array_replace([
            'customer_id' => $customer->id,
            'store_balance_funding_intent_id' => $intent->id,
            'telegram_navigation_root_id' => null,
            'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => 1,
            'locale' => 'en',
            'provider' => 'manual',
            'merchant_ref' => 'BAL-'.Str::upper(Str::random(12)),
            'idempotency_key' => 'top-up-'.Str::random(16),
            'currency' => 'IDR',
            'amount' => 5000,
            'status' => StoreBalanceTopUpStatus::Payable,
            'provider_reference' => null,
            'provider_fee' => 0,
            'provider_total_payment' => 5000,
            'provider_payment_method' => 'QR_MANUAL',
            'provider_created_at' => now()->subMinutes(2),
            'provider_expires_at' => $expiresAt,
            'expires_at' => $expiresAt,
            'provider_create_state' => 'created',
            'provider_create_attempted_at' => now()->subMinutes(2),
        ], $overrides));
    }
}
