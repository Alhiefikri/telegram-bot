<?php

namespace Tests\Feature;

use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

class KlikQrisAdminObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_admin_order_detail_shows_sanitized_klikqris_observability(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        Carbon::setTestNow(Carbon::parse('2026-07-28 12:00:00'));

        $order = Order::factory()->create([
            'invoice_number' => 'INV-KLIK-ADMIN',
            'total_amount' => 1000,
        ]);
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'order_id' => $order->id,
            'merchant_ref' => 'STORE/ORDER SECRET 1234567890',
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'provider_fee' => 16,
            'provider_reference' => 'KLIK-REF-777',
            'provider_payload' => [
                'data' => [
                    'order_id' => 'STORE/ORDER SECRET 1234567890',
                    'amount' => 1000,
                    'total_amount' => 1016,
                    'status' => 'PENDING',
                    'qris_url' => 'https://klikqris.com/storage/sandbox/qris_admin.png',
                    'qris_image' => 'RAW_QR_BASE64_SHOULD_NOT_RENDER',
                    'signature' => 'PAYLOAD_SIGNATURE_SHOULD_NOT_RENDER',
                ],
            ],
            'provider_create_state' => 'created',
            'provider_expires_at' => Carbon::parse('2026-07-28 12:15:00'),
            'status' => PaymentStatus::Pending,
        ]);
        PaymentEvent::factory()->for($payment)->create([
            'provider' => 'klikqris',
            'event_type' => PaymentEventType::Callback,
            'merchant_ref' => $payment->merchant_ref,
            'provider_status' => 'SUCCESS',
            'raw_payload' => [
                'api_key' => 'API_KEY_SHOULD_NOT_RENDER',
                'signature' => 'EVENT_SIGNATURE_SHOULD_NOT_RENDER',
                'raw' => ['nested' => 'RAW_JSON_SHOULD_NOT_RENDER'],
            ],
            'signature' => 'EVENT_SIGNATURE_SHOULD_NOT_RENDER',
            'received_count' => 2,
            'received_at' => Carbon::parse('2026-07-28 12:06:00'),
            'last_received_at' => Carbon::parse('2026-07-28 12:07:00'),
            'processed_at' => Carbon::parse('2026-07-28 12:10:00'),
        ]);
        PaymentEvent::factory()->for($payment)->create([
            'provider' => 'klikqris',
            'event_type' => PaymentEventType::Reconciliation,
            'merchant_ref' => $payment->merchant_ref,
            'provider_status' => 'SUCCESS',
            'raw_payload' => [
                'signature' => 'RECONCILIATION_SIGNATURE_SHOULD_NOT_RENDER',
            ],
            'signature' => 'RECONCILIATION_SIGNATURE_SHOULD_NOT_RENDER',
            'received_count' => 1,
            'received_at' => Carbon::parse('2026-07-28 12:02:00'),
            'last_received_at' => Carbon::parse('2026-07-28 12:03:00'),
            'processed_at' => Carbon::parse('2026-07-28 12:04:00'),
        ]);
        TelegramOutboundEffect::factory()->create([
            'effect_type' => TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT,
            'source_id' => $order->id,
            'state' => 'sent',
            'telegram_method' => 'sendMessage',
            'telegram_message_id' => '700001',
            'sent_at' => Carbon::parse('2026-07-28 12:05:00'),
            'outcome_metadata' => [
                'delivery_outcome' => 'retry_notice_sent',
                'delivery_method' => 'sendMessage',
            ],
        ]);

        $html = $this->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('Observability payment')
            ->assertSee('klikqris')
            ->assertSee('STORE/OR…')
            ->assertSee(hash('sha256', $payment->merchant_ref))
            ->assertSee('IDR 1.000')
            ->assertSee('IDR 1.016')
            ->assertSee('created')
            ->assertSee('SUCCESS')
            ->assertSee('2026-07-28 12:15')
            ->assertSee('2026-07-28 12:04')
            ->assertSee('Callback count')
            ->assertSee('2')
            ->assertSee('retry_notice_sent')
            ->assertSee('sendMessage')
            ->getContent();

        foreach ([
            'TOKEN_SHOULD_NOT_RENDER',
            'PAYLOAD_SIGNATURE_SHOULD_NOT_RENDER',
            'EVENT_SIGNATURE_SHOULD_NOT_RENDER',
            'API_KEY_SHOULD_NOT_RENDER',
            'RAW_QR_BASE64_SHOULD_NOT_RENDER',
            'RAW_JSON_SHOULD_NOT_RENDER',
            'raw_payload',
            'provider_payload',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
    }

    public function test_admin_order_detail_shows_only_the_safe_unknown_callback_condition_and_token(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        KlikQrisSandboxScenarios::configureReadySandbox();

        $order = Order::factory()->create([
            'invoice_number' => 'INV-KLIK-UNKNOWN',
            'total_amount' => 1000,
        ]);
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'order_id' => $order->id,
            'merchant_ref' => 'FULL UNKNOWN MERCHANT REF 1234567890',
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'provider_fee' => 16,
            'provider_payment_method' => 'qris',
            'provider_create_state' => 'created',
            'status' => PaymentStatus::Pending,
        ]);

        $this->postJson('/api/webhook/klikqris/sandbox', [
            'order_id' => $payment->merchant_ref,
            'amount' => 1000,
            'total_amount' => 1016,
            'status' => ' disputed.review-1 ',
            'signature' => 'UNKNOWN_ADMIN_SIGNATURE_SHOULD_NOT_RENDER',
            'api_key' => 'UNKNOWN_ADMIN_KEY_SHOULD_NOT_RENDER',
            'merchant_id' => 'UNKNOWN_ADMIN_MERCHANT_SHOULD_NOT_RENDER',
            'qris_base64' => 'UNKNOWN_ADMIN_QR_SHOULD_NOT_RENDER',
            'raw_payload' => 'UNKNOWN_ADMIN_RAW_SHOULD_NOT_RENDER',
            'internal_exception' => 'UNKNOWN_ADMIN_EXCEPTION_SHOULD_NOT_RENDER',
        ])->assertOk();

        $html = $this->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('unknown_callback_status')
            ->assertSee('DISPUTED.REVIEW-1')
            ->getContent();

        foreach ([
            'FULL UNKNOWN MERCHANT REF 1234567890',
            'UNKNOWN_ADMIN_SIGNATURE_SHOULD_NOT_RENDER',
            'UNKNOWN_ADMIN_KEY_SHOULD_NOT_RENDER',
            'UNKNOWN_ADMIN_MERCHANT_SHOULD_NOT_RENDER',
            'UNKNOWN_ADMIN_QR_SHOULD_NOT_RENDER',
            'UNKNOWN_ADMIN_RAW_SHOULD_NOT_RENDER',
            'UNKNOWN_ADMIN_EXCEPTION_SHOULD_NOT_RENDER',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
    }
}
