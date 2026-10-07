<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\BalanceAdjustmentRejected;
use App\Exceptions\CustomerUsdtFundingReservationConflict;
use App\Models\Customer;
use App\Models\User;
use App\Services\MoneyReceipts\ExternalMoneyReceiptClaims;
use App\Services\MoneyReceipts\MoneyReceiptIdentity;
use App\Services\MoneyReceipts\MoneyReceiptParentClaim;
use App\Services\MoneyReceipts\MoneyReceiptTarget;
use App\Services\MoneyReceipts\VerifiedMoneyReceipt;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\UsdtBalance\UsdtReceiptReconciliation;
use App\Services\UsdtFunding\CustomerUsdtFundingRelease;
use App\Services\UsdtFunding\CustomerUsdtFundingReservationService;
use App\Services\UsdtTopUps\UsdtTopUpReview;
use App\Services\UsdtTopUps\UsdtTopUpSettlement;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BscUsdtRailRpcFixture;
use Tests\Support\UsdtTopUpTestCase;

class UsdtTopUpReviewTest extends UsdtTopUpTestCase
{
    protected User $reviewOwner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reviewOwner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'owner-password']);
        $this->actingAs($this->reviewOwner);
        $session = app('session')->driver();
        $session->start();
        request()->setLaravelSession($session);
        config(['services.telegram.admin_telegram_chat_id' => '998877']);
    }

    protected function review(string $decision = 'settled', string $amount = '1')
    {
        return app(UsdtTopUpReview::class)->request($this->reviewOwner, $this->target->id,
            $decision, $amount, BscUsdtRailRpcFixture::TX, 'Verified actual deposit');
    }

    protected function confirmReview($preview): object
    {
        return app(UsdtTopUpReview::class)->confirm($this->reviewOwner, $preview->id, $preview->fingerprint, 1, 'owner-password');
    }

    public function test_revised_actual_amount_is_credited_once_and_original_snapshots_survive(): void
    {
        $original = $this->target->getAttributes();
        $this->rpc->receipt['logs'] = [$this->rpc->log(amount: '1250000000000000000')];
        $this->settle();
        $preview = $this->review('settled', '1.25');
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $resolution = $this->confirmReview($preview);
        $this->assertSame($resolution->top_up_id, $this->confirmReview($preview)->top_up_id);
        $this->assertSame(1_250_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertSame(1_250_000, (int) $resolution->resolved_amount_micro);
        $this->assertSame($original, $this->target->fresh()->getAttributes());
        $this->assertSame([], app(UsdtReceiptReconciliation::class)->inspect(null, null, null, 100)->findings);
        $this->travel(1)->minutes();
        $this->settle();
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
    }

    #[DataProvider('mismatchedDeposits')]
    public function test_review_resolves_recorded_actual_deposit_without_losing_or_double_counting_it(string $raw, string $decimal, string $micro): void
    {
        $original = $this->target->getAttributes();
        $this->rpc->receipt['logs'] = [$this->rpc->log(amount: $raw)];
        $this->settle();
        $report = app(UsdtReceiptReconciliation::class)->inspect(null, null, null, 100);
        $this->assertSame($micro, $report->unresolvedDepositMicro->toString());
        $receiptId = DB::table('external_money_receipts')->value('id');
        $preview = $this->review('settled', $decimal);
        $resolution = $this->confirmReview($preview);
        $this->assertSame((int) $receiptId, (int) $resolution->receipt_id);
        $this->confirmReview($preview);
        DB::table('customer_usdt_top_up_inbox')->update(['state' => 'pending', 'next_check_at' => now()]);
        $this->travel(1)->minutes();
        $this->settle();
        $report = app(UsdtReceiptReconciliation::class)->inspect(null, null, null, 100);
        $this->assertSame([], $report->findings);
        $this->assertSame('0', $report->unresolvedDepositMicro->toString());
        $this->assertSame((int) $micro, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        foreach (['external_money_receipts', 'customer_usdt_balance_entries', 'customer_usdt_balance_accounting_events', 'customer_usdt_top_up_resolutions'] as $table) {
            $this->assertSame(1, DB::table($table)->count(), $table);
        }
        $this->assertSame($original, $this->target->fresh()->getAttributes());
    }

    public static function mismatchedDeposits(): array
    {
        return [
            'underpayment' => ['750000000000000000', '0.75', '750000'],
            'overpayment' => ['1250000000000000000', '1.25', '1250000'],
        ];
    }

    public function test_compensation_is_real_deposit_funding_not_arbitrary_grant(): void
    {
        $preview = $this->review('resolved_by_compensation');
        $resolution = $this->confirmReview($preview);
        $this->assertSame('resolved_by_compensation', $resolution->kind);
        $this->assertSame(1_000_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertNotNull($resolution->receipt_id);
        $this->assertNotNull($resolution->entry_id);
    }

    public function test_missing_rpc_evidence_rejects_compensation_without_money(): void
    {
        $this->rpc->receipt = null;
        $this->expectException(DomainException::class);
        $this->review('resolved_by_compensation');
    }

    public function test_multiple_transfers_are_unresolved_without_sum_or_partial_grant(): void
    {
        $this->rpc->receipt['logs'][] = $this->rpc->log(index: 1);
        $this->expectException(DomainException::class);
        $this->review();
    }

    public function test_submicro_amount_is_not_rounded_into_owner_credit(): void
    {
        $this->rpc->receipt['logs'] = [$this->rpc->log(amount: '1000000000000000001')];
        $this->expectException(DomainException::class);
        $this->review();
    }

    public function test_password_failure_keeps_preview_and_money_unresolved(): void
    {
        $preview = $this->review();
        try {
            app(UsdtTopUpReview::class)->confirm($this->reviewOwner, $preview->id, $preview->fingerprint, 1, 'wrong-password');
            $this->fail('Wrong password accepted');
        } catch (BalanceAdjustmentRejected) {
            $this->assertSame(0, DB::table('customer_usdt_top_up_resolutions')->count());
            $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        }
        $this->confirmReview($preview);
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
    }

    public function test_changed_fingerprint_and_expired_session_fail_closed(): void
    {
        $preview = $this->review();
        try {
            app(UsdtTopUpReview::class)->confirm($this->reviewOwner, $preview->id, str_repeat('0', 64), 1, 'owner-password');
            $this->fail('Changed fingerprint accepted');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('customer_usdt_top_up_resolutions')->count());
        }
        request()->session()->migrate();
        $this->expectException(AuthorizationException::class);
        $this->confirmReview($preview);
    }

    public function test_changed_version_rejects_preview_confirmation(): void
    {
        $preview = $this->review();
        $this->expectException(AuthorizationException::class);
        app(UsdtTopUpReview::class)->confirm($this->reviewOwner, $preview->id, $preview->fingerprint, 2, 'owner-password');
    }

    public function test_changed_password_invalidates_existing_preview(): void
    {
        $preview = $this->review();
        $this->reviewOwner->forceFill(['password' => 'replacement-password'])->save();
        $this->expectException(AuthorizationException::class);
        app(UsdtTopUpReview::class)->confirm($this->reviewOwner, $preview->id, $preview->fingerprint, 1, 'replacement-password');
    }

    public function test_outbound_failure_rolls_back_review_money_and_all_economic_claims(): void
    {
        $preview = $this->review();
        DB::unprepared("CREATE TRIGGER reject_review_outbound BEFORE INSERT ON telegram_outbound_effects BEGIN SELECT RAISE(ABORT, 'outbound failed'); END;");
        try {
            $this->confirmReview($preview);
            $this->fail('Review money committed without receipt');
        } catch (QueryException) {
            foreach (['customer_usdt_top_up_resolutions', 'customer_usdt_balance_entries', 'external_money_receipts', 'customer_usdt_top_up_evidence'] as $table) {
                $this->assertSame(0, DB::table($table)->count(), $table);
            }
            $this->assertSame(1, DB::table('usdt_top_up_review_requests')->count());
        }
        DB::unprepared('DROP TRIGGER reject_review_outbound');
        $this->confirmReview($preview);
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
    }

    public function test_expired_preview_cannot_authorize_money(): void
    {
        $preview = $this->review();
        $this->travel(6)->minutes();
        $this->expectException(AuthorizationException::class);
        $this->confirmReview($preview);
    }

    public function test_permission_removal_during_rpc_rejects_confirmation(): void
    {
        $preview = $this->review();
        $this->rpc->onCall = function (): void {
            $this->rpc->onCall = null;
            $this->reviewOwner->forceFill(['role' => UserRole::Admin])->save();
        };
        $this->expectException(AuthorizationException::class);
        $this->confirmReview($preview);
    }

    public function test_changed_checkpoint_during_rpc_fences_result(): void
    {
        $preview = $this->review();
        $this->rpc->onCall = function (): void {
            $this->rpc->onCall = null;
            DB::table('usdt_bsc_scan_checkpoints')->update(['version' => DB::raw('version + 1')]);
        };
        $this->expectException(DomainException::class);
        $this->confirmReview($preview);
    }

    public function test_legacy_release_cannot_bypass_confirmed_target_review(): void
    {
        $this->expectException(CustomerUsdtFundingReservationConflict::class);
        app(CustomerUsdtFundingReservationService::class)->release($this->customer, $this->target->reservation_id,
            CustomerUsdtFundingRelease::compensated('made-up', 'owner:1'));
    }

    public function test_global_claim_for_another_target_blocks_review_credit(): void
    {
        $preview = $this->review();
        $receipt = new VerifiedMoneyReceipt(
            MoneyReceiptIdentity::bscLog(56, BscUsdtRailRpcFixture::TX, 0),
            'customer:'.$this->customer->id, 'USDT', '1000000000000000000', 18,
            MoneyReceiptTarget::customerUsdtTopUp((string) Str::uuid()),
            parentClaim: MoneyReceiptParentClaim::for(56, BscUsdtRailRpcFixture::TX));
        DB::transaction(fn () => app(ExternalMoneyReceiptClaims::class)->claim($receipt));
        try {
            $this->confirmReview($preview);
            $this->fail('Receipt reused for another target');
        } catch (DomainException) {
            $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
            $this->assertSame(0, DB::table('customer_usdt_top_up_resolutions')->count());
        }
    }

    public function test_foreign_customer_cannot_authorize_external_return(): void
    {
        $this->expectException(AuthorizationException::class);
        app(UsdtTopUpReview::class)->authorizeExternalReturn(Customer::factory()->create(), $this->target->id,
            '1', '0x'.str_repeat('7', 40), str_repeat('n', 32));
    }

    public function test_return_authorization_rejects_group_foreign_actor_unknown_customer_and_stale_root(): void
    {
        $actions = app(TelegramUsdtFundingActions::class);
        foreach ([
            ['951001', '-951001', 'group', 100],
            ['951002', '951001', 'private', 100],
            ['951002', '951002', 'private', 100],
            ['951001', '951001', 'private', 101],
        ] as [$actor, $chat, $type, $message]) {
            try {
                $actions->authorizeExternalReturn($actor, $chat, $type, $message,
                    $this->target->id, '1', '0x'.str_repeat('7', 40), str_repeat('n', 32));
                $this->fail('Untrusted Customer context must not authorize a refund destination.');
            } catch (AuthorizationException) {
                $this->assertSame(0, DB::table('usdt_top_up_external_return_authorizations')->count());
            }
        }
    }

    public function test_pasted_sender_address_does_not_authorize_external_refund(): void
    {
        $this->expectException(DomainException::class);
        app(UsdtTopUpReview::class)->request($this->reviewOwner, $this->target->id, 'externally_refunded', '1',
            BscUsdtRailRpcFixture::TX, 'Return', '0x'.str_repeat('c', 64), '0x'.str_repeat('9', 40));
    }

    protected function refundFixture(string $decimal = '1', string $raw = '1000000000000000000'): object
    {
        $destination = '0x'.str_repeat('7', 40);
        $authorization = app(TelegramUsdtFundingActions::class)->authorizeExternalReturn(
            '951001', '951001', 'private', 100, $this->target->id, $decimal, $destination, str_repeat('n', 32));
        $inbound = $this->rpc->receipt;
        $outbound = $inbound;
        $outbound['transactionHash'] = '0x'.str_repeat('c', 64);
        $outbound['logs'] = [$this->rpc->log(amount: $raw, receiver: $destination)];
        $outbound['logs'][0]['transactionHash'] = $outbound['transactionHash'];
        $outbound['logs'][0]['topics'][1] = '0x'.str_repeat('0', 24).substr(BscUsdtRailRpcFixture::ADDRESS, 2);
        $calls = 0;
        $this->rpc->onCall = function (string $method) use ($inbound, $outbound, &$calls): void {
            if ($method === 'transactionReceipt') {
                $this->rpc->receipt = (++$calls % 2) === 1 ? $inbound : $outbound;
            }
        };

        return $authorization;
    }

    public function test_authentic_customer_authorized_external_return_closes_two_claims_without_credit(): void
    {
        $authorization = $this->refundFixture();
        $preview = app(UsdtTopUpReview::class)->request($this->reviewOwner, $this->target->id, 'externally_refunded', '1',
            BscUsdtRailRpcFixture::TX, 'Verified return', '0x'.str_repeat('c', 64), $authorization->id);
        $resolution = $this->confirmReview($preview);
        $this->assertSame('externally_refunded', $resolution->kind);
        $this->assertNotNull($resolution->refund_receipt_id);
        $this->assertSame(2, DB::table('external_money_receipts')->count());
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame([], app(UsdtReceiptReconciliation::class)->inspect(null, null, null, 100)->findings);
        $this->assertSame('released', DB::table('customer_usdt_funding_reservations')->value('state'));
    }

    #[DataProvider('mismatchedDeposits')]
    public function test_external_return_resolves_actual_mismatched_deposit_once(string $raw, string $decimal, string $micro): void
    {
        $this->rpc->receipt['logs'] = [$this->rpc->log(amount: $raw)];
        $this->settle();
        $this->assertSame($micro, app(UsdtReceiptReconciliation::class)->inspect(null, null, null, 100)->unresolvedDepositMicro->toString());
        $receiptId = DB::table('external_money_receipts')->value('id');
        $authorization = $this->refundFixture($decimal, $raw);
        $preview = app(UsdtTopUpReview::class)->request($this->reviewOwner, $this->target->id, 'externally_refunded', $decimal,
            BscUsdtRailRpcFixture::TX, 'Verified actual return', '0x'.str_repeat('c', 64), $authorization->id);
        $resolution = $this->confirmReview($preview);
        $this->confirmReview($preview);
        $this->assertSame((int) $receiptId, (int) $resolution->receipt_id);
        $report = app(UsdtReceiptReconciliation::class)->inspect(null, null, null, 100);
        $this->assertSame([], $report->findings);
        $this->assertSame('0', $report->unresolvedDepositMicro->toString());
        $this->assertSame(2, DB::table('external_money_receipts')->count());
        $this->assertSame(1, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
    }

    public function test_later_receipt_after_review_compensation_never_reopens_credit(): void
    {
        $this->confirmReview($this->review('resolved_by_compensation'));
        $hash = '0x'.str_repeat('d', 64);
        $this->rpc->receipt['transactionHash'] = $hash;
        $this->rpc->receipt['logs'][0]['transactionHash'] = $hash;
        $this->travel(1)->minutes();
        app(UsdtTopUpSettlement::class)->submit($this->customer, $this->target->id, $hash);
        app(UsdtTopUpSettlement::class)->check($this->target->id);
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame('resolved_by_compensation', DB::table('customer_usdt_top_up_resolutions')->value('kind'));
        $report = app(UsdtReceiptReconciliation::class)->inspect(null, null, null, 100);
        $this->assertSame('1000000', $report->unresolvedDepositMicro->toString());
    }

    public function test_auto_settlement_winning_preview_race_cannot_be_overwritten(): void
    {
        $preview = $this->review('resolved_by_compensation');
        $this->settle();
        $this->expectException(DomainException::class);
        $this->confirmReview($preview);
    }
}
