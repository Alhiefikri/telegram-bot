<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\VerifyBscPayment;
use App\Models\BscPaymentReceiver;
use App\Models\ManualFulfillmentReview;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Models\PaymentEvent;
use App\Services\Bsc\BscPaymentVerification;
use App\Services\Bsc\BscRpcClient;
use App\Services\Bsc\BscRpcException;
use App\Services\Bsc\BscVerificationOutcome;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\Bsc\BscVerificationSubmissionOutcome;
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

        config()->set('services.bsc.rpc_url', 'https://bsc-rpc.test');
        config()->set('services.bsc.chain_id', 56);
        config()->set('services.bsc.usdt_contract', self::CONTRACT);
        config()->set('services.bsc.expected_token_decimals', 18);
        config()->set('services.bsc.max_scan_blocks', 10_000);

        $this->rpc = new class implements BscVerificationRpcClientInterface
        {
            public int $calls = 0;

            public bool $failReceipts = false;

            /** @var array<string, array<string, mixed>|null> */
            public array $receipts = [];

            /** @var array<string, array<string, mixed>|null> */
            public array $blocks = [];

            /** @var list<array<string, mixed>> */
            public array $logs = [];

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

            public function transferLogs(string $contract, string|int $fromBlock, string|int $toBlock, ?string $receiver = null): array
            {
                $this->calls++;

                return $this->logs;
            }
        };
        app()->instance(BscVerificationRpcClientInterface::class, $this->rpc);

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
    }

    public function test_reconciliation_finds_a_late_transfer_without_a_submitted_hash_and_uses_manual_review(): void
    {
        $payment = $this->payment();
        $hash = '0x'.str_repeat('8', 64);
        $this->configureReceipt($payment, $hash, latestBlock: 102);
        $lateDeadline = now()->subMinute();
        $payment->update(['expired_at' => $lateDeadline]);
        $payment->order()->update([
            'expires_at' => $lateDeadline,
            'order_status' => OrderStatus::Expired,
            'payment_status' => PaymentStatus::Expired,
        ]);
        $payment->update(['status' => PaymentStatus::Expired]);
        $this->rpc->blocks['100'] = $this->block(100, now()->subSeconds(10), '0x'.str_repeat('e', 64));
        $this->rpc->logs = [['transactionHash' => $hash]];

        $result = app(BscPaymentVerification::class)->verify($payment->fresh());

        $this->assertSame(BscVerificationOutcome::ManualReview, $result->outcome);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Expired, $payment->fresh()->order->order_status);
        $this->assertDatabaseHas('manual_fulfillment_reviews', [
            'payment_id' => $payment->id,
            'reason' => ManualFulfillmentReview::REASON_LATE_PAYMENT,
        ]);
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
    }

    public function test_scanner_uses_bounded_transfer_logs_without_customer_hash(): void
    {
        $payment = $this->payment();
        $hash = '0x'.str_repeat('d', 64);
        $this->configureReceipt($payment, $hash, latestBlock: 102);
        $this->rpc->logs = [['transactionHash' => $hash]];

        $result = app(BscPaymentVerification::class)->verify($payment);

        $this->assertSame(BscVerificationOutcome::Confirmed, $result->outcome);
        $this->assertSame($hash, $payment->fresh()->bscDetail()->firstOrFail()->current_tx_hash);
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
    ): void {
        $detail = $payment->bscDetail()->firstOrFail();
        $from = '0x2222222222222222222222222222222222222222';
        $blockHash = '0x'.str_repeat('e', 64);
        $this->rpc->receipts[$hash] = [
            'transactionHash' => $hash,
            'status' => '0x1',
            'blockNumber' => '0x64',
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
        $this->rpc->blocks['100'] = $this->block(100, now()->subSeconds(20), $blockHash);
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
