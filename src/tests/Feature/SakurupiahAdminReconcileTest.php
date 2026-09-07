<?php

namespace Tests\Feature;

use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\Sakurupiah\SakurupiahAmount;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahStatus;
use App\Services\Sakurupiah\SakurupiahTransactionStatus;
use App\Support\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SakurupiahAdminReconcileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'api-test',
            'sandbox_api_key' => 'secret-key',
            'sandbox_callback_url' => 'https://store.example.test/api/webhook/sakurupiah',
            'sandbox_return_url' => 'https://store.example.test/shop/orders',
            'sandbox_allowed_hosts' => ['sakurupiah.id'],
            'sandbox_callback_allowed_hosts' => ['store.example.test'],
            'sandbox_return_allowed_hosts' => ['store.example.test'],
            'qris_contract' => [
                'currency' => 'IDR',
                'currency_confirmed' => true,
                'fee_payer' => 'merchant',
                'merchant_fee' => 1,
                'fee_confirmed' => true,
                'expiry_hours' => 24,
                'expiry_confirmed' => true,
            ],
        ]));
        ManualPaymentSetting::create(['payment_mode' => PaymentProvider::MANUAL, 'is_active' => true]);
    }

    public function test_finance_role_can_manually_reconcile_a_pending_sakurupiah_payment_and_audits(): void
    {
        $finance = User::factory()->create(['role' => UserRole::Finance]);
        $payment = $this->sakurupiahPayment(['merchant_ref' => 'SAKU-ADMIN']);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')
            ->once()
            ->with((string) $payment->provider_reference)
            ->andReturn($this->providerStatus($payment, SakurupiahTransactionStatus::Successful, amount: (int) $payment->amount));
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->actingAs($finance)
            ->post(route('admin.payments.reconcile', $payment))
            ->assertRedirect(route('admin.orders.show', $payment->order_id))
            ->assertSessionHas('status');

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $finance->id,
            'action' => 'payment.manually_reconciled',
            'subject_id' => (string) $payment->id,
        ]);
    }

    public function test_support_role_cannot_manually_reconcile_a_sakurupiah_payment(): void
    {
        $support = User::factory()->create(['role' => UserRole::Support]);
        $payment = $this->sakurupiahPayment(['merchant_ref' => 'SAKU-NO-ACCESS']);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->actingAs($support)
            ->post(route('admin.payments.reconcile', $payment))
            ->assertForbidden();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'payment.manually_reconciled')->count());
    }

    public function test_order_detail_bounds_payment_events_and_preserves_total_count(): void
    {
        $this->withoutVite();
        $finance = User::factory()->create(['role' => UserRole::Finance]);
        $payment = $this->sakurupiahPayment(['merchant_ref' => 'SAKU-EVENT-BOUND']);
        $receivedAt = now()->subMinutes(12);

        foreach (range(1, 12) as $index) {
            PaymentEvent::factory()->for($payment, 'payment')->create([
                'provider' => PaymentProvider::SAKURUPIAH,
                'event_type' => PaymentEventType::Reconciliation,
                'merchant_ref' => $payment->merchant_ref,
                'provider_reference' => $payment->provider_reference,
                'provider_status' => 'pending',
                'raw_payload' => ['status' => 'pending'],
                'idempotency_key' => 'admin-event-'.$index,
                'received_at' => $receivedAt->copy()->addMinutes($index),
                'last_received_at' => $receivedAt->copy()->addMinutes($index),
                'processed_at' => $receivedAt->copy()->addMinutes($index),
            ]);
        }

        $this->actingAs($finance)
            ->get(route('admin.orders.show', $payment->order_id))
            ->assertOk()
            ->assertSee('12 total, 10 ditampilkan');
    }

    public function test_order_detail_shows_manual_reconcile_action_only_when_reconcilable(): void
    {
        $this->withoutVite();
        $finance = User::factory()->create(['role' => UserRole::Finance]);
        $payment = $this->sakurupiahPayment(['merchant_ref' => 'SAKU-ACTION']);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $html = $this->actingAs($finance)
            ->get(route('admin.orders.show', $payment->order_id))
            ->assertOk()
            ->assertSee('Observability payment')
            ->assertSee('Cek ulang status (rekonsiliasi)')
            ->getContent();

        $this->assertStringContainsString(route('admin.payments.reconcile', $payment), $html);
        $this->assertStringNotContainsString('secret-key', $html);
        $this->assertStringNotContainsString('SAKU-ACTION', $html);
    }

    public function test_admin_cannot_manually_reconcile_a_payment_without_a_provider_reference(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $payment = $this->sakurupiahPayment([
            'merchant_ref' => 'SAKU-NO-REF',
            'provider_reference' => null,
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_OUTCOME_UNKNOWN,
        ]);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->from(route('admin.orders.show', $payment->order_id))
            ->actingAs($owner)
            ->post(route('admin.payments.reconcile', $payment))
            ->assertRedirect(route('admin.orders.show', $payment->order_id))
            ->assertSessionHasErrors('payment');

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'payment.manually_reconciled')->count());
    }

    private function sakurupiahPayment(array $attributes = []): Payment
    {
        $amount = (int) ($attributes['amount'] ?? 12000);
        $customer = Customer::factory()->create([
            'phone_number' => '08123456789',
            'email' => 'buyer+'.bin2hex(random_bytes(4)).'@example.test',
        ]);
        $order = Order::factory()->for($customer, 'customer')->create([
            'total_amount' => $amount,
            'currency' => 'IDR',
            'expires_at' => now()->addHours(2),
        ]);

        return Payment::factory()->for($order, 'order')->create(array_merge([
            'provider' => PaymentProvider::SAKURUPIAH,
            'merchant_ref' => 'SAKU-'.$order->id,
            'provider_reference' => 'trx-saku-'.$order->id,
            'provider_payment_method' => SakurupiahCreateInvoice::METHOD_QRISC,
            'provider_total_payment' => $amount + 84,
            'provider_fee' => 84,
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_CREATED,
            'method' => SakurupiahCreateInvoice::PAYMENT_METHOD_QRIS,
            'amount' => $amount,
            'currency' => 'IDR',
            'status' => PaymentStatus::Pending,
            'expired_at' => now()->addHours(2),
        ], $attributes));
    }

    private function providerStatus(
        Payment $payment,
        SakurupiahTransactionStatus $status,
        ?int $amount = null,
    ): SakurupiahStatus {
        $data = [
            'trx_id' => $payment->provider_reference,
            'merchant_ref' => $payment->merchant_ref,
            'payment_kode' => SakurupiahCreateInvoice::METHOD_QRISC,
            'payment_status' => $status->value,
        ];
        if ($amount !== null) {
            $data['amount'] = (string) $amount;
        }

        return new SakurupiahStatus(
            trxId: (string) $payment->provider_reference,
            status: $status,
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
            amount: $amount === null ? null : SakurupiahAmount::from($amount),
            sanitizedPayload: ['status' => '200', 'data' => [$data]],
        );
    }
}
