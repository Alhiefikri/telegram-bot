<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Enums\UserRole;
use App\Jobs\SendWarrantyDeadlineReminder;
use App\Models\Customer;
use App\Models\ManualFulfillmentReview;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Warranty\WarrantyDeadlineReminderPipeline;
use App\Services\Warranty\WarrantyDeadlineReminderService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\BuildsBuyMailWarrantyFixture;
use Tests\Support\NonTransactionalDatabaseTestCase;

#[Group('postgres-release')]
final class WarrantyDeadlineReminderTest extends NonTransactionalDatabaseTestCase
{
    use BuildsBuyMailWarrantyFixture;

    public function test_due_unit_gets_exactly_one_durable_reminder_across_repeated_sweeps_and_recovery(): void
    {
        Queue::fake();
        [$customer, $unit] = $this->fixture();
        $customer->update(['telegram_id' => 790001]);
        $unit->update(['refund_deadline' => now()->addMinutes(20)]);

        $this->artisan('suppliers:notify-warranty-deadlines')->assertSuccessful();
        $this->artisan('suppliers:notify-warranty-deadlines')->assertSuccessful();
        $this->artisan('suppliers:recover-warranty', ['--limit' => 5])->assertSuccessful();

        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectType::WarrantyDeadlineReminder->value)->count());
        $this->assertNotNull($unit->fresh()->reminder_materialized_at);
        Queue::assertPushed(SendWarrantyDeadlineReminder::class);
    }

    public function test_reminder_delivery_is_localized_private_and_never_sent_twice(): void
    {
        Queue::fake();
        [$customer, $unit] = $this->fixture();
        $customer->update(['telegram_id' => 790001, 'locale' => 'en']);
        $unit->update(['refund_deadline' => now()->addMinutes(20)]);
        $effect = app(TelegramOutboundEffectService::class)->recordWarrantyDeadlineReminder($unit);
        Http::fake(function ($request) use ($unit) {
            $this->assertStringContainsString('Report any problem with this account before', $request['text']);
            $this->assertStringContainsString($unit->order->invoice_number, $request['text']);
            $this->assertStringNotContainsString('PR1', $request['text']);
            $this->assertStringNotContainsString('synthetic-password', $request['text']);

            return Http::response(['ok' => true, 'result' => ['message_id' => 12345]]);
        });

        $pipeline = app(WarrantyDeadlineReminderPipeline::class);
        $pipeline->deliver($effect);
        $pipeline->deliver($effect);

        Http::assertSentCount(1);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
    }

    public function test_reminder_is_skipped_when_stock_is_retired(): void
    {
        $this->assertNoReminderFor(function (Customer $customer, $unit): void {
            $unit->stockItem->update(['retired_at' => now()]);
        });
    }

    public function test_reminder_is_skipped_when_confirmation_closed_the_window(): void
    {
        $this->assertNoReminderFor(function (Customer $customer, $unit): void {
            $unit->update(['confirmation_state' => 'confirmed']);
        });
    }

    public function test_reminder_is_skipped_when_a_claim_already_exists(): void
    {
        $this->assertNoReminderFor(function (Customer $customer, $unit): void {
            $this->createClaim($unit, $customer);
        });
    }

    public function test_reminder_is_skipped_after_the_deadline(): void
    {
        $this->assertNoReminderFor(function (Customer $customer, $unit): void {
            $unit->update(['refund_deadline' => now()->subMinute()]);
        }, withinWindow: false);
    }

    public function test_reminder_is_skipped_outside_the_window(): void
    {
        $this->assertNoReminderFor(function (Customer $customer, $unit): void {
            $unit->update(['refund_deadline' => now()->addHours(3)]);
        }, withinWindow: false);
    }

    public function test_reminder_is_skipped_without_a_telegram_customer(): void
    {
        $this->assertNoReminderFor(function (Customer $customer, $unit): void {
            $customer->update(['telegram_id' => null]);
        });
    }

    public function test_expired_reminder_is_finalized_without_any_send(): void
    {
        Queue::fake();
        [$customer, $unit] = $this->fixture();
        $customer->update(['telegram_id' => 790001]);
        $unit->update(['refund_deadline' => now()->addMinutes(20)]);
        $effect = app(TelegramOutboundEffectService::class)->recordWarrantyDeadlineReminder($unit);

        $this->travel(21)->minutes();
        Http::fake();

        app(WarrantyDeadlineReminderPipeline::class)->deliver($effect);

        Http::assertNothingSent();
        $fresh = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Failed, $fresh->state);
        $this->assertSame('warranty_reminder_expired', $fresh->last_error_code);
    }

    public function test_reminder_is_finalized_without_send_when_a_claim_arrives_before_delivery(): void
    {
        Queue::fake();
        [$customer, $unit] = $this->fixture();
        $customer->update(['telegram_id' => 790001]);
        $unit->update(['refund_deadline' => now()->addMinutes(20)]);
        $effect = app(TelegramOutboundEffectService::class)->recordWarrantyDeadlineReminder($unit);
        $this->createClaim($unit, $customer);
        Http::fake();

        app(WarrantyDeadlineReminderPipeline::class)->deliver($effect);

        Http::assertNothingSent();
        $this->assertSame('warranty_reminder_no_longer_eligible', $effect->fresh()->last_error_code);
    }

    public function test_recovery_redispatches_a_materialized_reminder_whose_queue_dispatch_was_lost(): void
    {
        Queue::fake();
        [$customer, $unit] = $this->fixture();
        $customer->update(['telegram_id' => 790001]);
        $unit->update(['refund_deadline' => now()->addMinutes(20)]);
        app(TelegramOutboundEffectService::class)->recordWarrantyDeadlineReminder($unit);

        $this->artisan('suppliers:recover-warranty', ['--limit' => 5])->assertSuccessful();

        Queue::assertPushed(SendWarrantyDeadlineReminder::class, 1);
        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectType::WarrantyDeadlineReminder->value)->count());
    }

    public static function ineligibleOrders(): iterable
    {
        yield 'web buyer' => ['web'];
        yield 'refunded order' => ['refunded'];
        yield 'cancelled order' => ['cancelled'];
        yield 'late payment review' => ['review'];
    }

    #[DataProvider('ineligibleOrders')]
    public function test_ineligible_earlier_unit_does_not_starve_a_due_telegram_reminder(string $reason): void
    {
        Queue::fake();
        [$customer, $unit] = $this->fixture();
        $unit->update(['refund_deadline' => now()->addMinutes(10)]);
        $eligibleCustomer = Customer::factory()->create(['telegram_id' => 790002]);
        $eligibleOrder = $unit->order->replicate();
        $eligibleOrder->forceFill(['customer_id' => $eligibleCustomer->id, 'invoice_number' => 'REMINDER-'.Str::uuid()])->save();
        $eligibleItem = $unit->orderItem->replicate();
        $eligibleItem->order_id = $eligibleOrder->id;
        $eligibleItem->save();
        $eligibleStock = $unit->stockItem->replicate();
        $eligibleStock->forceFill(['order_item_id' => $eligibleItem->id, 'supplier_unit_index' => 2])->save();
        $eligibleUnit = $unit->replicate();
        $eligibleUnit->forceFill([
            'public_id' => (string) Str::uuid(), 'external_id' => 'PR2',
            'order_id' => $eligibleOrder->id, 'refund_deadline' => now()->addMinutes(20),
            'order_item_id' => $eligibleItem->id, 'product_stock_item_id' => $eligibleStock->id,
        ])->save();
        $this->makeOrderIneligible($customer, $unit, $reason);

        $this->assertSame(1, app(WarrantyDeadlineReminderService::class)->materializeDue(1));
        $this->assertNotNull($eligibleUnit->fresh()->reminder_materialized_at);
        $this->assertNull($unit->fresh()->reminder_materialized_at);
        $this->assertSame(1, TelegramOutboundEffect::where('source_id', (string) $eligibleUnit->id)
            ->where('effect_type', TelegramOutboundEffectType::WarrantyDeadlineReminder->value)->count());
    }

    #[DataProvider('ineligibleOrders')]
    public function test_reminder_rechecks_order_eligibility_before_delivery(string $reason): void
    {
        Queue::fake();
        [$customer, $unit] = $this->fixture();
        $customer->update(['telegram_id' => 790001]);
        $unit->update(['refund_deadline' => now()->addMinutes(20)]);
        $effect = app(TelegramOutboundEffectService::class)->recordWarrantyDeadlineReminder($unit);
        $this->makeOrderIneligible($customer, $unit, $reason);
        Http::fake();

        app(WarrantyDeadlineReminderPipeline::class)->deliver($effect);

        Http::assertNothingSent();
        $this->assertSame(TelegramOutboundEffectState::Failed, $effect->fresh()->state);
        $this->assertSame($reason === 'web' ? 'warranty_reminder_unavailable' : 'warranty_reminder_no_longer_eligible', $effect->fresh()->last_error_code);
    }

    private function makeOrderIneligible(Customer $customer, $unit, string $reason): void
    {
        if ($reason === 'web') {
            $customer->update(['telegram_id' => null]);
        } elseif ($reason === 'refunded') {
            $unit->order->update(['payment_status' => PaymentStatus::Refunded]);
        } elseif ($reason === 'cancelled') {
            $unit->order->update(['order_status' => OrderStatus::Cancelled]);
        } else {
            ManualFulfillmentReview::create([
                'order_id' => $unit->order_id, 'payment_id' => $unit->order->payments()->sole()->id,
                'amount' => 16000, 'currency' => 'IDR', 'reason' => ManualFulfillmentReview::REASON_LATE_PAYMENT,
                'state' => ManualFulfillmentReview::STATE_PENDING,
            ]);
        }
    }

    public function test_operator_monitor_lists_near_deadline_units_with_reminder_state(): void
    {
        Queue::fake();
        [$customer, $unit] = $this->fixture();
        $unit->update(['refund_deadline' => now()->addMinutes(45)]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $this->get('/admin/suppliers/warranty')
            ->assertOk()
            ->assertSee('Unit mendekati deadline klaim')
            ->assertSee($unit->order->invoice_number)
            ->assertSee('Pengingat belum dijadwalkan');

        $this->travel(16)->minutes();
        $this->artisan('suppliers:notify-warranty-deadlines')->assertSuccessful();
        $this->get('/admin/suppliers/warranty')
            ->assertOk()
            ->assertSee('Pengingat diantrikan');
    }

    private function assertNoReminderFor(callable $prepare, bool $withinWindow = true): void
    {
        Queue::fake();
        [$customer, $unit] = $this->fixture();
        $customer->update(['telegram_id' => 790001]);
        if ($withinWindow) {
            $unit->update(['refund_deadline' => now()->addMinutes(20)]);
        }
        $prepare($customer, $unit);

        $this->artisan('suppliers:notify-warranty-deadlines')->assertSuccessful();

        $this->assertSame(0, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectType::WarrantyDeadlineReminder->value)->count());
    }

    private function createClaim($unit, Customer $customer): WarrantyClaim
    {
        return WarrantyClaim::create([
            'public_id' => (string) Str::uuid(),
            'supplier_account_unit_id' => $unit->id,
            'customer_id' => $customer->id,
            'order_id' => $unit->order_id,
            'proof_path' => 'warranty-proofs/'.Str::uuid().'.enc',
            'proof_digest' => hash('sha256', 'synthetic-proof'),
            'note' => 'Synthetic claim',
            'refund_deadline' => now()->addMinutes(20),
        ]);
    }
}
