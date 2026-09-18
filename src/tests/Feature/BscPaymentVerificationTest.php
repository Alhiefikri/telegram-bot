<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\VerifyBscPayment;
use App\Models\BscPaymentReceiver;
use App\Models\ManualFulfillmentReview;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\Admin\ManualFulfillmentResolutionService;
use App\Services\Bsc\BscPaymentVerification;
use App\Services\Bsc\BscRpcClient;
use App\Services\Bsc\BscRpcClientInterface;
use App\Services\Bsc\BscRpcException;
use App\Services\Bsc\BscVerificationOutcome;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\Bsc\BscVerificationSubmissionOutcome;
use App\Storefront\StorefrontShopPaymentStatusRefresh;
use App\Storefront\StorefrontShopSignedInvoice;
use Brick\Math\BigInteger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BscPaymentVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACT = '0x55d398326f99059ff775485246999027b3197955';

    private object $rpc;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bsc.rpc_urls', ['https://bsc-rpc.test']);
        config()->set('services.bsc.chain_id', 56);
        config()->set('services.bsc.usdt_contract', self::CONTRACT);
        config()->set('services.bsc.expected_token_decimals', 18);

        $this->rpc = new class implements BscVerificationRpcClientInterface
        {
            public int $calls = 0;

            public bool $failReceipts = false;

            /** @var array<string, array<string, mixed>|null> */
            public array $receipts = [];

            /** @var array<string, array<string, mixed>|null> */
            public array $blocks = [];

            public ?\Closure $onReceipt = null;

            public function chainId(): int
            {
                $this->calls++;

                return 56;
            }

            public function contractCode(string $contract): string
            {
                $this->calls++;

                return '0x60016001';
            }

            public function tokenDecimals(string $contract): int
            {
                $this->calls++;

                return 18;
            }

            public function isValidErc1271Signature(string $contract, string $messageHashHex, string $signature): bool
            {
                $this->calls++;

                return true;
            }

            public function transactionReceipt(string $transactionHash): ?array
            {
                $this->calls++;

                if ($this->onReceipt !== null) {
                    ($this->onReceipt)($transactionHash);
                }

                if ($this->failReceipts) {
                    throw new BscRpcException('temporary RPC outage.');
                }

                return $this->receipts[$transactionHash] ?? null;
            }

            public function blockByNumber(string|int $blockNumber): ?array
            {
                $this->calls++;

                return $this->blocks[(string) $blockNumber] ?? null;
            }
        };
        app()->instance(BscVerificationRpcClientInterface::class, $this->rpc);
        app()->instance(BscRpcClientInterface::class, $this->rpc);

        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'is_active' => true,
            'bsc_payment_enabled' => true,
            'bsc_min_confirmations' => 3,
            'bsc_confirmation_grace_minutes' => 3,
            'bsc_hash_attempt_limit' => 5,
        ]);
    }

    public function test_hash_submission_is_strict_versioned_idempotent_and_does_no_rpc_work(): void
    {
        Queue::fake([VerifyBscPayment::class]);
        $payment = $this->payment();
        $hashes = array_map(fn (int $n): string => '0x'.str_pad(dechex($n), 64, '0', STR_PAD_LEFT), range(1, 6));

        $accepted = app(BscPaymentVerification::class)->submitTransactionHash($payment->order_id, '0x'.strtoupper(substr($hashes[0], 2)));

        $this->assertSame(BscVerificationSubmissionOutcome::Accepted, $accepted->outcome);
        $this->assertSame(1, $accepted->version);
        $this->assertSame(1, $payment->fresh()->bscDetail()->firstOrFail()->tx_attempts);
        $this->assertSame(0, $this->rpc->calls);

        $same = app(BscPaymentVerification::class)->submitTransactionHash($payment->order_id, $hashes[0]);
        $this->assertSame(BscVerificationSubmissionOutcome::Accepted, $same->outcome);
        $this->assertSame(1, $same->version);
        $this->assertSame(1, $payment->fresh()->bscDetail()->firstOrFail()->tx_attempts);

        $this->assertSame(
            BscVerificationSubmissionOutcome::Invalid,
            app(BscPaymentVerification::class)->submitTransactionHash($payment->order_id, '0x1234')->outcome,
        );

        foreach (array_slice($hashes, 1, 4) as $hash) {
            $this->assertSame(
                BscVerificationSubmissionOutcome::Accepted,
                app(BscPaymentVerification::class)->submitTransactionHash($payment->order_id, $hash)->outcome,
            );
        }

        $limit = app(BscPaymentVerification::class)->submitTransactionHash($payment->order_id, $hashes[5]);
        $this->assertSame(BscVerificationSubmissionOutcome::Limit, $limit->outcome);
        $this->assertSame(5, $payment->fresh()->bscDetail()->firstOrFail()->tx_attempts);
        Queue::assertPushed(VerifyBscPayment::class, 5);
    }

    public function test_submitting_a_globally_used_hash_creates_one_manual_review_without_rpc_work(): void
    {
        $first = $this->payment();
        $hash = '0x'.str_repeat('c', 64);
        $this->configureReceipt($first, $hash, latestBlock: 102);
        $this->assertSame(BscVerificationOutcome::Confirmed, app(BscPaymentVerification::class)->verify($first, $hash)->outcome);

        $second = $this->payment('0x3333333333333333333333333333333333333333');
        $beforeCalls = $this->rpc->calls;

        $result = app(BscPaymentVerification::class)->submitTransactionHash($second->order_id, $hash);

        $this->assertSame(BscVerificationSubmissionOutcome::TransactionAlreadyUsed, $result->outcome);
        $this->assertSame($beforeCalls, $this->rpc->calls);
        $this->assertSame(PaymentBscDetail::STATUS_TRANSACTION_ALREADY_USED, $second->fresh()->bscDetail()->firstOrFail()->verification_status);
        $this->assertDatabaseHas('manual_fulfillment_reviews', [
            'payment_id' => $second->id,
            'reason' => ManualFulfillmentReview::REASON_BSC_TRANSACTION_ALREADY_USED,
            'state' => ManualFulfillmentReview::STATE_PENDING,
        ]);

        app(BscPaymentVerification::class)->submitTransactionHash($second->order_id, $hash);

        $this->assertDatabaseCount('manual_fulfillment_reviews', 1);
    }

    public function test_submitted_hash_from_another_bsc_wallet_is_verified_from_receipt(): void
    {
        $payment = $this->payment();
        $hash = '0x'.str_repeat('b', 64);
        $this->configureReceipt($payment, $hash, latestBlock: 102);

        $result = app(BscPaymentVerification::class)->verify($payment, $hash);

        $this->assertSame(BscVerificationOutcome::Confirmed, $result->outcome);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(
            '0x2222222222222222222222222222222222222222',
            $payment->fresh()->bscDetail->current_from_address,
        );
    }

    public function test_no_hash_verification_waits_without_rpc_work(): void
    {
        $payment = $this->payment();
        $beforeCalls = $this->rpc->calls;

        $result = app(BscPaymentVerification::class)->verify($payment);

        $this->assertSame(BscVerificationOutcome::AwaitingTransfer, $result->outcome);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame($beforeCalls, $this->rpc->calls);
    }

    public function test_exact_transfer_stays_confirming_until_three_confirmations_then_claims_once(): void
    {
        $payment = $this->payment();
        $hash = '0x'.str_repeat('a', 64);
        $this->configureReceipt($payment, $hash, latestBlock: 101);

        $first = app(BscPaymentVerification::class)->verify($payment, $hash);
        $this->assertSame(BscVerificationOutcome::Confirming, $first->outcome);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(PaymentBscDetail::STATUS_CONFIRMING, $payment->fresh()->bscDetail()->firstOrFail()->verification_status);

        $this->rpc->blocks['latest'] = $this->block(102, now()->addSeconds(1));
        $second = app(BscPaymentVerification::class)->verify($payment->fresh(), $hash);

        $this->assertSame(BscVerificationOutcome::Confirmed, $second->outcome);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Processing, $payment->fresh()->order->order_status);
        $this->assertSame($hash, $payment->fresh()->provider_reference);
        $this->assertSame(1, PaymentEvent::query()->where('provider', 'bsc')->count());
        $this->assertSame(1, $payment->fresh()->bscTransferEvidence()->count());

        $again = app(BscPaymentVerification::class)->verify($payment->fresh(), $hash);
        $this->assertSame(BscVerificationOutcome::AlreadyPaid, $again->outcome);
        $this->assertSame(1, PaymentEvent::query()->where('provider', 'bsc')->count());
    }

    public function test_confirmation_threshold_uses_the_payment_snapshot_after_setting_changes(): void
    {
        $payment = $this->payment();
        ManualPaymentSetting::current()->update(['bsc_min_confirmations' => 5]);
        $hash = '0x'.str_repeat('9', 64);
        $this->configureReceipt($payment, $hash, latestBlock: 102);

        $result = app(BscPaymentVerification::class)->verify($payment, $hash);

        $this->assertSame(BscVerificationOutcome::Confirmed, $result->outcome);
        $this->assertSame(3, $payment->fresh()->bscDetail->required_confirmations);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_transfer_included_after_the_deadline_becomes_late_payment_before_order_expiry_races(): void
    {
        $payment = $this->payment();
        $hash = '0x'.str_repeat('7', 64);
        $this->configureReceipt($payment, $hash, latestBlock: 102);
        $deadline = now()->subSeconds(30);
        $payment->update(['expired_at' => $deadline]);
        $payment->order()->update(['expires_at' => $deadline]);
        $this->rpc->blocks['100'] = $this->block(100, now()->subSeconds(10), '0x'.str_repeat('e', 64));

        $result = app(BscPaymentVerification::class)->verify($payment->fresh(), $hash);

        $this->assertSame(BscVerificationOutcome::ManualReview, $result->outcome);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Expired, $payment->fresh()->order->order_status);
        $this->assertDatabaseHas('manual_fulfillment_reviews', [
            'payment_id' => $payment->id,
            'reason' => ManualFulfillmentReview::REASON_LATE_PAYMENT,
        ]);
        $this->assertSame(
            BscVerificationOutcome::ManualReview,
            app(BscPaymentVerification::class)->status($payment->fresh())->outcome,
        );

        $invoice = app(StorefrontShopSignedInvoice::class)->read($payment->fresh()->order);
        $this->assertStringContainsString('ditinjau manual', strtolower($invoice->statusMessage));

        $refresh = app(StorefrontShopPaymentStatusRefresh::class)->execute($payment->fresh()->order);
        $this->assertSame('terminal', $refresh->payload()['status']);
        $this->assertFalse($refresh->payload()['is_paid']);
        $this->assertStringContainsString('ditinjau manual', strtolower($refresh->payload()['message']));
    }

    public function test_wrong_amount_is_mismatch_and_never_claims(): void
    {
        $payment = $this->payment();
        $hash = '0x'.str_repeat('b', 64);
        $this->configureReceipt($payment, $hash, amountUnits: '1000000000000000001', latestBlock: 102);

        $result = app(BscPaymentVerification::class)->verify($payment, $hash);

        $this->assertSame(BscVerificationOutcome::Mismatch, $result->outcome);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(PaymentBscDetail::STATUS_MISMATCH, $payment->fresh()->bscDetail()->firstOrFail()->verification_status);
        $this->assertSame(0, PaymentEvent::query()->where('provider', 'bsc')->count());
        $this->assertDatabaseHas('manual_fulfillment_reviews', [
            'payment_id' => $payment->id,
            'reason' => ManualFulfillmentReview::REASON_BSC_MISMATCH,
            'state' => ManualFulfillmentReview::STATE_PENDING,
        ]);

        app(BscPaymentVerification::class)->verify($payment->fresh(), $hash);

        $this->assertDatabaseCount('manual_fulfillment_reviews', 1);
    }

    public function test_rpc_failure_stays_pending_and_reconciliation_can_recover_it(): void
    {
        $payment = $this->payment();
        $hash = '0x'.str_repeat('f', 64);
        $this->configureReceipt($payment, $hash, latestBlock: 102);
        $this->rpc->failReceipts = true;

        $failed = app(BscPaymentVerification::class)->verify($payment, $hash);

        $this->assertSame(BscVerificationOutcome::ProviderError, $failed->outcome);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(PaymentBscDetail::STATUS_PROVIDER_ERROR, $payment->fresh()->bscDetail()->firstOrFail()->verification_status);

        $this->rpc->failReceipts = false;
        $recovered = app(BscPaymentVerification::class)->verify($payment->fresh(), $hash);

        $this->assertSame(BscVerificationOutcome::Confirmed, $recovered->outcome);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_disabling_new_bsc_checkout_does_not_change_an_existing_payment_snapshot_or_verification(): void
    {
        $payment = $this->payment();
        $detail = $payment->bscDetail()->firstOrFail();
        $hash = '0x'.str_repeat('6', 64);
        $this->configureReceipt($payment, $hash, latestBlock: 102);
        ManualPaymentSetting::current()->update(['bsc_payment_enabled' => false]);

        $result = app(BscPaymentVerification::class)->verify($payment, $hash);

        $this->assertSame(BscVerificationOutcome::Confirmed, $result->outcome);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame($detail->receiver_address, $payment->fresh()->bscDetail->receiver_address);
        $this->assertSame(self::CONTRACT, $payment->fresh()->bscDetail->token_contract);
    }

    public function test_a_late_exact_transfer_is_recorded_for_manual_review_without_reviving_the_order(): void
    {
        $payment = $this->payment();
        $hash = '0x'.str_repeat('9', 64);
        $this->configureReceipt($payment, $hash, latestBlock: 102);
        $payment->bscDetail()->update([
            'current_tx_hash' => $hash,
            'tx_submission_version' => 1,
            'tx_attempts' => 1,
        ]);
        $payment->update(['status' => PaymentStatus::Expired]);
        $payment->order()->update([
            'order_status' => OrderStatus::Expired,
            'payment_status' => PaymentStatus::Expired,
        ]);

        $result = app(BscPaymentVerification::class)->verify($payment->fresh(), $hash, 1);

        $this->assertSame(BscVerificationOutcome::ManualReview, $result->outcome);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Expired, $payment->fresh()->order->order_status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->order->payment_status);
        $this->assertSame(PaymentBscDetail::STATUS_MANUAL_REVIEW, $payment->fresh()->bscDetail()->firstOrFail()->verification_status);
        $this->assertSame(1, $payment->fresh()->bscTransferEvidence()->count());
        $this->assertDatabaseHas('manual_fulfillment_reviews', [
            'order_id' => $payment->order_id,
            'payment_id' => $payment->id,
            'reason' => ManualFulfillmentReview::REASON_LATE_PAYMENT,
            'state' => ManualFulfillmentReview::STATE_PENDING,
            'provider_reference' => $hash,
        ]);
        $this->assertSame(1, PaymentEvent::query()->where('provider', 'bsc')->count());

        $again = app(BscPaymentVerification::class)->verify($payment->fresh(), $hash, 1);

        $this->assertSame(BscVerificationOutcome::AlreadyPaid, $again->outcome);
        $this->assertDatabaseCount('manual_fulfillment_reviews', 1);
        $this->assertSame(1, PaymentEvent::query()->where('provider', 'bsc')->count());
    }

    public function test_a_globally_reused_transaction_cannot_claim_a_second_payment(): void
    {
        $first = $this->payment();
        $hash = '0x'.str_repeat('c', 64);
        $this->configureReceipt($first, $hash, latestBlock: 102);
        $this->assertSame(BscVerificationOutcome::Confirmed, app(BscPaymentVerification::class)->verify($first, $hash)->outcome);

        $second = $this->payment('0x3333333333333333333333333333333333333333');
        $secondDetail = $second->bscDetail()->firstOrFail();
        $secondDetail->update([
            'current_tx_hash' => $hash,
            'tx_submission_version' => 1,
            'tx_attempts' => 1,
            'verification_status' => PaymentBscDetail::STATUS_TX_SUBMITTED,
            'expected_token_units' => $first->bscDetail()->firstOrFail()->expected_token_units,
            'receiver_address' => $first->bscDetail()->firstOrFail()->receiver_address,
            'token_contract' => self::CONTRACT,
        ]);

        $result = app(BscPaymentVerification::class)->verify($second->fresh(), $hash, 1);

        $this->assertSame(BscVerificationOutcome::TransactionAlreadyUsed, $result->outcome);
        $this->assertSame(PaymentStatus::Pending, $second->fresh()->status);
        $this->assertSame(PaymentBscDetail::STATUS_TRANSACTION_ALREADY_USED, $second->fresh()->bscDetail()->firstOrFail()->verification_status);
        $this->assertSame(1, PaymentEvent::query()->where('provider', 'bsc')->count());
        $this->assertDatabaseHas('manual_fulfillment_reviews', [
            'payment_id' => $second->id,
            'reason' => ManualFulfillmentReview::REASON_BSC_TRANSACTION_ALREADY_USED,
            'state' => ManualFulfillmentReview::STATE_PENDING,
        ]);

        app(BscPaymentVerification::class)->verify($second->fresh(), $hash, 1);

        $this->assertDatabaseCount('manual_fulfillment_reviews', 1);
    }

    public function test_multiple_matching_transfers_in_submitted_receipt_create_one_ambiguous_manual_review(): void
    {
        $payment = $this->payment();
        $hash = '0x'.str_repeat('a', 64);
        $this->configureReceipt($payment, $hash, latestBlock: 102);
        $receipt = $this->rpc->receipts[$hash];
        $receipt['logs'][] = [...$receipt['logs'][0], 'logIndex' => '0x1'];
        $this->rpc->receipts[$hash] = $receipt;

        $result = app(BscPaymentVerification::class)->verify($payment, $hash);

        $this->assertSame(BscVerificationOutcome::ManualReview, $result->outcome);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseHas('manual_fulfillment_reviews', [
            'payment_id' => $payment->id,
            'reason' => ManualFulfillmentReview::REASON_BSC_AMBIGUOUS,
            'state' => ManualFulfillmentReview::STATE_PENDING,
        ]);

        app(BscPaymentVerification::class)->verify($payment->fresh(), $hash);

        $this->assertDatabaseCount('manual_fulfillment_reviews', 1);
    }

    public function test_owner_can_fulfill_a_pending_bsc_evidence_review_through_the_existing_boundary(): void
    {
        Queue::fake();
        $payment = $this->payment();
        $hash = '0x'.str_repeat('b', 64);
        $this->configureReceipt($payment, $hash, amountUnits: '1000000000000000001', latestBlock: 102);
        app(BscPaymentVerification::class)->verify($payment, $hash);
        $review = $payment->manualFulfillmentReview()->firstOrFail();

        $resolved = app(ManualFulfillmentResolutionService::class)->resolve(
            $review,
            User::factory()->create(['role' => UserRole::Owner]),
            'fulfill',
            'Owner accepted the payment evidence after manual review.',
        );

        $this->assertSame(ManualFulfillmentReview::STATE_FULFILLED, $resolved->state);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $payment->fresh()->order->order_status);
    }

    public function test_pending_bsc_evidence_review_can_be_refunded_or_rejected(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $resolution = app(ManualFulfillmentResolutionService::class);

        $refundPayment = $this->payment();
        $refundHash = '0x'.str_repeat('d', 64);
        $this->configureReceipt($refundPayment, $refundHash, amountUnits: '1000000000000000001', latestBlock: 102);
        app(BscPaymentVerification::class)->verify($refundPayment, $refundHash);
        $refunded = $resolution->resolve(
            $refundPayment->manualFulfillmentReview()->firstOrFail(),
            $owner,
            'refund',
            'Wrong amount returned after review.',
            'REFUND-BSC-347',
            'success',
        );

        $rejectPayment = $this->payment('0x4444444444444444444444444444444444444444');
        $rejectHash = '0x'.str_repeat('e', 64);
        $this->configureReceipt($rejectPayment, $rejectHash, amountUnits: '1000000000000000001', latestBlock: 102);
        app(BscPaymentVerification::class)->verify($rejectPayment, $rejectHash);
        $rejected = $resolution->resolve(
            $rejectPayment->manualFulfillmentReview()->firstOrFail(),
            $owner,
            'reject',
            'Evidence rejected after review.',
        );

        $this->assertSame(ManualFulfillmentReview::STATE_REFUNDED, $refunded->state);
        $this->assertSame(PaymentStatus::Refunded, $refundPayment->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $refundPayment->fresh()->order->order_status);
        $this->assertSame(ManualFulfillmentReview::STATE_REJECTED, $rejected->state);
        $this->assertSame(PaymentStatus::Failed, $rejectPayment->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $rejectPayment->fresh()->order->order_status);
    }

    public function test_hash_queue_carries_the_submission_version_seen_at_dispatch(): void
    {
        Queue::fake([VerifyBscPayment::class]);
        $payment = $this->payment();
        $payment->bscDetail()->update([
            'current_tx_hash' => '0x'.str_repeat('1', 64),
            'tx_submission_version' => 2,
            'tx_attempts' => 2,
            'verification_status' => PaymentBscDetail::STATUS_TX_SUBMITTED,
        ]);

        $this->assertTrue(app(BscPaymentVerification::class)->queueForStatusRefresh($payment));
        Queue::assertPushed(VerifyBscPayment::class, fn (VerifyBscPayment $job): bool => $job->paymentId === $payment->id
            && $job->submissionVersion === 2);
    }

    public function test_versioned_verification_does_not_persist_old_evidence_after_hash_replacement(): void
    {
        Queue::fake([VerifyBscPayment::class]);
        $payment = $this->payment();
        $oldHash = '0x'.str_repeat('7', 64);
        $newHash = '0x'.str_repeat('8', 64);
        $payment->bscDetail()->update([
            'current_tx_hash' => $oldHash,
            'tx_submission_version' => 1,
            'tx_attempts' => 1,
            'verification_status' => PaymentBscDetail::STATUS_TX_SUBMITTED,
        ]);
        $this->configureReceipt($payment, $oldHash, latestBlock: 102);
        $replaced = false;
        $this->rpc->onReceipt = function () use (&$replaced, $payment, $newHash): void {
            if (! $replaced) {
                $replaced = true;
                app(BscPaymentVerification::class)->submitTransactionHash($payment->order_id, $newHash);
            }
        };

        $result = app(BscPaymentVerification::class)->verify($payment->fresh(), $oldHash, 1);

        $this->assertSame(BscVerificationOutcome::StaleSubmission, $result->outcome);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, $payment->fresh()->bscTransferEvidence()->count());
        $detail = $payment->fresh()->bscDetail()->firstOrFail();
        $this->assertSame($newHash, $detail->current_tx_hash);
        $this->assertSame(2, $detail->tx_submission_version);
        $this->assertSame(PaymentBscDetail::STATUS_TX_SUBMITTED, $detail->verification_status);
    }

    private function payment(string $address = '0x1111111111111111111111111111111111111111'): Payment
    {
        $receiver = BscPaymentReceiver::factory()->verified()->active()->create([
            'address' => $address,
            'token_contract' => self::CONTRACT,
        ]);
        $order = Order::factory()->create([
            'expires_at' => now()->addMinutes(30),
        ]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'USDT_BEP20',
            'amount' => 1_000_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
            'expired_at' => now()->addMinutes(30),
            'created_at' => now()->subMinute(),
        ]);
        PaymentBscDetail::query()->create([
            'payment_id' => $payment->id,
            'receiver_id' => $receiver->id,
            'receiver_address' => $receiver->address_normalized,
            'chain_id' => 56,
            'token_contract' => self::CONTRACT,
            'token_decimals' => 18,
            'required_confirmations' => 3,
            'requires_finalized_block' => false,
            'confirmation_grace_minutes' => 3,
            'base_amount_micro' => 1_000_000,
            'payable_amount_micro' => 1_000_000,
            'expected_token_units' => '1000000000000000000',
            'verification_status' => PaymentBscDetail::STATUS_AWAITING_TRANSFER,
        ]);

        return $payment;
    }

    private function configureReceipt(
        Payment $payment,
        string $hash,
        string $amountUnits = '1000000000000000000',
        int $latestBlock = 102,
        int $blockNumber = 100,
        ?Carbon $blockTimestamp = null,
    ): void {
        $detail = $payment->bscDetail()->firstOrFail();
        $from = '0x2222222222222222222222222222222222222222';
        $blockHash = '0x'.str_repeat('e', 64);
        $this->rpc->receipts[$hash] = [
            'transactionHash' => $hash,
            'status' => '0x1',
            'blockNumber' => '0x'.dechex($blockNumber),
            'blockHash' => $blockHash,
            'logs' => [[
                'address' => self::CONTRACT,
                'topics' => [
                    BscRpcClient::TRANSFER_TOPIC,
                    $this->topicAddress($from),
                    $this->topicAddress($detail->receiver_address),
                ],
                'data' => '0x'.str_pad(BigInteger::of($amountUnits)->toBase(16), 64, '0', STR_PAD_LEFT),
                'logIndex' => '0x0',
                'blockHash' => $blockHash,
            ]],
        ];
        $this->rpc->blocks[(string) $blockNumber] = $this->block($blockNumber, $blockTimestamp ?? now()->subSeconds(20), $blockHash);
        $this->rpc->blocks['latest'] = $this->block($latestBlock, now()->addSeconds(1));
    }

    private function block(int $number, Carbon $timestamp, ?string $hash = null): array
    {
        return [
            'number' => '0x'.dechex($number),
            'timestamp' => '0x'.dechex($timestamp->timestamp),
            'hash' => $hash ?? '0x'.str_repeat(dechex($number % 15), 64),
        ];
    }

    private function topicAddress(string $address): string
    {
        return '0x'.str_pad(substr(strtolower($address), 2), 64, '0', STR_PAD_LEFT);
    }
}
