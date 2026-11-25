<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidOrderTransition;
use App\Models\BscPaymentReceiver;
use App\Models\ManualFulfillmentReview;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Models\User;
use App\Services\Admin\ManualFulfillmentResolutionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class BscCheckoutReviewPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresRace;

    public function test_two_owners_racing_to_approve_the_same_transfer_cannot_fulfill_twice(): void
    {
        if (DB::getDriverName() !== 'pgsql' || (string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            $this->markTestSkipped('Checkout review races require disposable PostgreSQL and RUN_CONCURRENCY_TESTS=1.');
        }

        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        Queue::fake();
        $receiver = BscPaymentReceiver::factory()->verified()->active()->create();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $hash = '0x'.str_repeat('a', 64);
        $reviewIds = [];
        $paymentIds = [];
        for ($i = 0; $i < 2; $i++) {
            $detail = PaymentBscDetail::factory()->create(['receiver_id' => $receiver->id]);
            $detail->update([
                'verification_status' => PaymentBscDetail::STATUS_MANUAL_REVIEW,
                'evidence_payload' => ['verified_review_candidate' => [
                    'tx_hash' => $hash,
                    'log_index' => 0,
                    'receipt_status' => 'success',
                    'receiver_address' => $detail->receiver_address,
                    'token_contract' => $detail->token_contract,
                    'amount_units' => $detail->expected_token_units,
                ]],
            ]);
            $paymentIds[] = $detail->payment_id;
            $reviewIds[] = ManualFulfillmentReview::query()->create([
                'order_id' => $detail->payment->order_id,
                'payment_id' => $detail->payment_id,
                'reason' => ManualFulfillmentReview::REASON_BSC_AMBIGUOUS,
                'currency' => 'USDT',
                'amount' => $detail->payment->amount,
                'state' => ManualFulfillmentReview::STATE_PENDING,
            ])->id;
        }

        $results = $this->race(array_map(fn (int $id) => function () use ($id, $owner): string {
            try {
                return app(ManualFulfillmentResolutionService::class)->resolve(
                    ManualFulfillmentReview::query()->findOrFail($id),
                    $owner,
                    'fulfill',
                    'Verify payer attribution',
                )->state;
            } catch (InvalidOrderTransition) {
                return 'blocked';
            }
        }, $reviewIds));

        sort($results);
        $this->assertSame(['blocked', 'fulfilled'], $results);
        $this->assertSame(1, Payment::query()->whereIn('id', $paymentIds)->where('status', PaymentStatus::Paid->value)->count());
        $this->assertSame(1, DB::table('external_money_receipt_parent_claims')->where('tx_hash', $hash)->count());
    }
}
