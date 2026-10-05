<?php

namespace Tests\Feature;

use App\Enums\ExternalMoneyReceiptClaimOutcome;
use App\Models\BscPaymentTransferEvidence;
use App\Models\ExternalMoneyReceipt;
use App\Models\ExternalMoneyReceiptAlias;
use App\Models\ExternalMoneyReceiptParentClaim;
use App\Services\Bsc\BscTransactionHash;
use App\Services\MoneyReceipts\ExternalMoneyReceiptClaims;
use App\Services\MoneyReceipts\MoneyReceiptAlias;
use App\Services\MoneyReceipts\MoneyReceiptIdentity;
use App\Services\MoneyReceipts\MoneyReceiptParentClaim;
use App\Services\MoneyReceipts\MoneyReceiptTarget;
use App\Services\MoneyReceipts\VerifiedMoneyReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class ExternalMoneyReceiptClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_receipt_is_inserted_once_and_claimed_for_its_target(): void
    {
        $target = MoneyReceiptTarget::orderPayment('order-1');
        $alias = new MoneyReceiptAlias('custody_deposit', MoneyReceiptIdentity::providerReference('custody', 'account-1', 'deposit-1'));
        $receipt = $this->bscReceipt('alpha', 0, $target, aliases: [$alias]);

        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Claimed, $this->claims()->claim($receipt));

        $this->assertDatabaseCount('external_money_receipts', 1);
        $this->assertDatabaseHas('external_money_receipts', [
            'canonical_key' => $receipt->canonicalKey(),
            'provider' => 'bsc',
            'identity_kind' => 'bsc_log',
            'chain_id' => 56,
            'tx_hash' => $receipt->identity->txHash(),
            'log_index' => 0,
            'currency' => 'USDT',
            'amount_units' => '2500000',
            'status' => 'claimed',
            'target_type' => 'order_payment',
            'target_id' => 'order-1',
            'fingerprint' => $receipt->fingerprint(),
        ]);
        $this->assertDatabaseHas('external_money_receipt_aliases', [
            'alias_key' => $alias->identity->canonicalKey(),
            'alias_type' => 'custody_deposit',
        ]);
        $this->assertDatabaseHas('external_money_receipt_parent_claims', [
            'chain_id' => 56,
            'tx_hash' => $receipt->identity->txHash(),
            'status' => 'claimed',
            'target_type' => 'order_payment',
            'target_id' => 'order-1',
        ]);

        $record = ExternalMoneyReceipt::query()->sole();
        $this->assertNotNull($record->claimed_at);
        $this->assertSame(ExternalMoneyReceipt::query()->sole()->id, ExternalMoneyReceiptAlias::query()->sole()->external_money_receipt_id);
    }

    public function test_replaying_the_same_receipt_returns_replayed_without_a_second_effect(): void
    {
        $alias = new MoneyReceiptAlias('custody_deposit', MoneyReceiptIdentity::providerReference('custody', 'account-1', 'deposit-1'));
        $receipt = $this->bscReceipt('alpha', 0, MoneyReceiptTarget::orderPayment('order-1'), aliases: [$alias]);

        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Claimed, $this->claims()->claim($receipt));
        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Replayed, $this->claims()->claim($receipt));

        $this->assertDatabaseCount('external_money_receipts', 1);
        $this->assertDatabaseCount('external_money_receipt_aliases', 1);
        $this->assertDatabaseCount('external_money_receipt_parent_claims', 1);
    }

    public function test_a_changed_amount_or_beneficiary_for_the_same_receipt_conflicts(): void
    {
        $receipt = $this->bscReceipt('alpha', 0, MoneyReceiptTarget::orderPayment('order-1'));

        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Claimed, $this->claims()->claim($receipt));
        $this->assertSame(
            ExternalMoneyReceiptClaimOutcome::Conflict,
            $this->claims()->claim($this->bscReceipt('alpha', 0, MoneyReceiptTarget::orderPayment('order-1'), amountUnits: '2500001')),
        );
        $this->assertSame(
            ExternalMoneyReceiptClaimOutcome::Conflict,
            $this->claims()->claim($this->bscReceipt('alpha', 0, MoneyReceiptTarget::orderPayment('order-1'), beneficiary: '0x'.str_repeat('b', 40))),
        );

        $record = ExternalMoneyReceipt::query()->sole();
        $this->assertSame('2500000', $record->amount_units);
        $this->assertSame('0x'.str_repeat('a', 40), $record->beneficiary);
    }

    public function test_a_second_target_claiming_the_same_receipt_conflicts_and_keeps_the_first_owner(): void
    {
        $receipt = $this->bscReceipt('alpha', 0, MoneyReceiptTarget::orderPayment('order-1'));

        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Claimed, $this->claims()->claim($receipt));
        $this->assertSame(
            ExternalMoneyReceiptClaimOutcome::Conflict,
            $this->claims()->claim($this->bscReceipt('alpha', 0, MoneyReceiptTarget::orderPayment('order-2'))),
        );

        $this->assertDatabaseCount('external_money_receipts', 1);
        $this->assertSame('order-1', ExternalMoneyReceipt::query()->sole()->target_id);
    }

    public function test_a_verified_alias_collision_conflicts_and_rolls_back_the_new_receipt(): void
    {
        $alias = new MoneyReceiptAlias('custody_deposit', MoneyReceiptIdentity::providerReference('custody', 'account-1', 'deposit-1'));
        $first = $this->bscReceipt('alpha', 0, MoneyReceiptTarget::orderPayment('order-1'), aliases: [$alias]);
        $second = $this->bscReceipt('beta', 0, MoneyReceiptTarget::orderPayment('order-2'), aliases: [$alias]);

        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Claimed, $this->claims()->claim($first));
        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Conflict, $this->claims()->claim($second));

        $this->assertDatabaseCount('external_money_receipts', 1);
        $this->assertDatabaseCount('external_money_receipt_aliases', 1);
        $this->assertDatabaseMissing('external_money_receipts', ['canonical_key' => $second->canonicalKey()]);
        $this->assertSame(ExternalMoneyReceipt::query()->sole()->id, ExternalMoneyReceiptAlias::query()->sole()->external_money_receipt_id);
    }

    public function test_a_caller_transaction_survives_a_conflicting_claim_and_commits_its_own_work(): void
    {
        $receipt = $this->bscReceipt('alpha', 0, MoneyReceiptTarget::orderPayment('order-1'));
        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Claimed, $this->claims()->claim($receipt));

        $outcome = null;
        DB::transaction(function () use (&$outcome): void {
            $outcome = $this->claims()->claim($this->bscReceipt('alpha', 0, MoneyReceiptTarget::orderPayment('order-2')));
            DB::table('audit_logs')->insert([
                'action' => 'external-money-receipt-caller-transaction-marker',
                'subject_type' => 'test',
                'subject_id' => 'order-2',
                'created_at' => now(),
            ]);
        });

        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Conflict, $outcome);
        $this->assertDatabaseHas('audit_logs', ['action' => 'external-money-receipt-caller-transaction-marker']);
        $this->assertDatabaseCount('external_money_receipts', 1);
    }

    public function test_bsc_parent_transaction_accepts_multiple_logs_for_the_same_target(): void
    {
        $target = MoneyReceiptTarget::storeBalanceTopUp('top-up-1');

        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Claimed, $this->claims()->claim($this->bscReceipt('alpha', 0, $target)));
        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Claimed, $this->claims()->claim($this->bscReceipt('alpha', 1, $target)));

        $this->assertDatabaseCount('external_money_receipts', 2);
        $this->assertDatabaseCount('external_money_receipt_parent_claims', 1);
        $this->assertDatabaseHas('external_money_receipt_parent_claims', [
            'status' => 'claimed',
            'target_type' => 'store_balance_top_up',
            'target_id' => 'top-up-1',
        ]);
    }

    public function test_bsc_multi_log_transaction_for_a_second_target_enters_review_with_the_original_owner(): void
    {
        $first = $this->bscReceipt('alpha', 0, MoneyReceiptTarget::orderPayment('order-1'));
        $second = $this->bscReceipt('alpha', 1, MoneyReceiptTarget::orderPayment('order-2'));

        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Claimed, $this->claims()->claim($first));
        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Review, $this->claims()->claim($second));
        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Review, $this->claims()->claim($second));
        $this->assertSame(
            ExternalMoneyReceiptClaimOutcome::Conflict,
            $this->claims()->claim($this->bscReceipt('alpha', 1, MoneyReceiptTarget::orderPayment('order-3'))),
        );

        $this->assertDatabaseCount('external_money_receipts', 2);
        $this->assertDatabaseCount('external_money_receipt_parent_claims', 1);
        $this->assertDatabaseHas('external_money_receipt_parent_claims', [
            'status' => 'review',
            'target_type' => 'order_payment',
            'target_id' => 'order-1',
            'review_reason' => ExternalMoneyReceipt::REVIEW_REASON_BSC_PARENT_CROSS_TARGET,
        ]);

        $reviewed = ExternalMoneyReceipt::query()->where('canonical_key', $second->canonicalKey())->sole();
        $this->assertSame('review', $reviewed->status->value);
        $this->assertSame(ExternalMoneyReceipt::REVIEW_REASON_BSC_PARENT_CROSS_TARGET, $reviewed->review_reason);
        $this->assertNull($reviewed->claimed_at);
    }

    public function test_a_reviewed_bsc_parent_transaction_holds_every_receipt_and_later_log_for_review(): void
    {
        $first = $this->bscReceipt('alpha', 0, MoneyReceiptTarget::orderPayment('order-1'));
        $second = $this->bscReceipt('alpha', 1, MoneyReceiptTarget::orderPayment('order-2'));

        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Claimed, $this->claims()->claim($first));
        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Review, $this->claims()->claim($second));

        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Review, $this->claims()->claim($first));

        $later = $this->bscReceipt('alpha', 2, MoneyReceiptTarget::orderPayment('order-1'));
        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Review, $this->claims()->claim($later));

        $this->assertDatabaseCount('external_money_receipts', 3);
        $this->assertDatabaseCount('external_money_receipt_parent_claims', 1);

        foreach (ExternalMoneyReceipt::query()->get() as $record) {
            $this->assertSame('review', $record->status->value);
            $this->assertSame(ExternalMoneyReceipt::REVIEW_REASON_BSC_PARENT_CROSS_TARGET, $record->review_reason);
            $this->assertNull($record->claimed_at);
        }
    }

    public function test_observed_receipts_aliases_and_parent_claims_are_immutable(): void
    {
        $alias = new MoneyReceiptAlias('custody_deposit', MoneyReceiptIdentity::providerReference('custody', 'account-1', 'deposit-1'));
        $this->claims()->claim($this->bscReceipt('alpha', 0, MoneyReceiptTarget::orderPayment('order-1'), aliases: [$alias]));

        $this->assertImmutable(fn () => ExternalMoneyReceipt::query()->sole()->forceFill(['amount_units' => '1'])->save());
        $this->assertImmutable(fn () => ExternalMoneyReceipt::query()->sole()->forceFill(['target_id' => 'order-2'])->save());
        $this->assertImmutable(fn () => ExternalMoneyReceipt::query()->sole()->delete());
        $this->assertImmutable(fn () => ExternalMoneyReceiptAlias::query()->sole()->forceFill(['alias_type' => 'other'])->save());
        $this->assertImmutable(fn () => ExternalMoneyReceiptAlias::query()->sole()->delete());
        $this->assertImmutable(fn () => ExternalMoneyReceiptParentClaim::query()->sole()->forceFill(['target_id' => 'order-2'])->save());
        $this->assertImmutable(fn () => ExternalMoneyReceiptParentClaim::query()->sole()->delete());
    }

    public function test_the_registry_serves_checkout_top_up_and_adjustment_targets_across_adapters(): void
    {
        $this->assertSame(
            ExternalMoneyReceiptClaimOutcome::Claimed,
            $this->claims()->claim($this->binanceReceipt('TXN-1', MoneyReceiptTarget::orderPayment('order-1'))),
        );
        $this->assertSame(
            ExternalMoneyReceiptClaimOutcome::Claimed,
            $this->claims()->claim($this->binanceReceipt('TXN-2', MoneyReceiptTarget::storeBalanceTopUp('top-up-1'))),
        );
        $this->assertSame(
            ExternalMoneyReceiptClaimOutcome::Claimed,
            $this->claims()->claim($this->binanceReceipt('TXN-3', MoneyReceiptTarget::balanceAdjustment('adjustment-1'))),
        );

        $this->assertDatabaseCount('external_money_receipts', 3);
        $this->assertDatabaseCount('external_money_receipt_parent_claims', 0);
        $this->assertDatabaseHas('external_money_receipts', [
            'provider' => 'binance',
            'identity_kind' => 'provider_reference',
            'account_namespace' => 'pay_id:123456789',
            'reference' => 'TXN-1',
            'currency' => 'USDT',
            'amount_units' => '2500000',
            'status' => 'claimed',
            'target_type' => 'order_payment',
            'target_id' => 'order-1',
        ]);
    }

    public function test_legacy_bsc_evidence_hashes_normalize_to_the_same_receipt_identity(): void
    {
        $evidence = BscPaymentTransferEvidence::factory()->create([
            'tx_hash' => '0x'.str_repeat('AB', 32),
            'log_index' => 4,
        ]);

        $identity = MoneyReceiptIdentity::bscLog(56, (string) $evidence->tx_hash, (int) $evidence->log_index);

        $this->assertSame(BscTransactionHash::normalize((string) $evidence->tx_hash), $identity->txHash());
        $this->assertSame($identity->canonicalKey(), MoneyReceiptIdentity::bscLog(56, $identity->txHash(), 4)->canonicalKey());
    }

    private function claims(): ExternalMoneyReceiptClaims
    {
        return app(ExternalMoneyReceiptClaims::class);
    }

    private function assertImmutable(callable $mutation): void
    {
        try {
            $mutation();
            $this->fail('Expected the external money receipt registry to reject the mutation.');
        } catch (LogicException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    /** @param list<MoneyReceiptAlias> $aliases */
    private function bscReceipt(
        string $txSeed,
        int $logIndex,
        MoneyReceiptTarget $target,
        string $amountUnits = '2500000',
        string $beneficiary = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        array $aliases = [],
    ): VerifiedMoneyReceipt {
        $hash = $this->txHash($txSeed);

        return new VerifiedMoneyReceipt(
            identity: MoneyReceiptIdentity::bscLog(56, $hash, $logIndex),
            beneficiary: $beneficiary,
            currency: 'USDT',
            amountUnits: $amountUnits,
            decimals: 6,
            target: $target,
            evidence: ['tx_hash' => $hash, 'log_index' => $logIndex, 'confirmations' => 12],
            aliases: $aliases,
            parentClaim: MoneyReceiptParentClaim::for(56, $hash),
        );
    }

    private function binanceReceipt(string $transactionId, MoneyReceiptTarget $target): VerifiedMoneyReceipt
    {
        return new VerifiedMoneyReceipt(
            identity: MoneyReceiptIdentity::binancePay('pay_id', '123456789', $transactionId),
            beneficiary: 'pay_id:123456789',
            currency: 'USDT',
            amountUnits: '2500000',
            decimals: 6,
            target: $target,
            evidence: ['transaction_id_suffix' => substr($transactionId, -2), 'confirmations' => 1],
        );
    }

    private function txHash(string $seed): string
    {
        return '0x'.substr(hash('sha256', $seed), 0, 64);
    }
}
