<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Payment;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Services\Sakurupiah\SakurupiahChannelPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SakurupiahChannelMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_cutover_preserves_observed_and_unobserved_payment_identity(): void
    {
        $migration = require database_path('migrations/2026_10_27_000001_add_sakurupiah_channel_selection.php');
        $migration->down();

        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'sakurupiah',
            'is_active' => true,
            'payment_provider_version' => 9,
        ]);
        $observed = Payment::factory()->create([
            'provider' => 'sakurupiah',
            'method' => 'QRIS',
            'provider_payment_method' => 'QRIS',
            'provider_reference' => 'historical-qris-invoice',
            'provider_fee' => 420,
            'provider_total_payment' => 10420,
        ]);
        $unobserved = Payment::factory()->create([
            'provider' => 'sakurupiah',
            'method' => 'QRIS',
            'provider_create_state' => 'outcome_unknown',
        ]);
        $otherProvider = Payment::factory()->create(['provider' => 'klikqris']);
        $before = $observed->fresh()->getAttributes();

        $migration->up();

        $this->assertSame('QRIS', SakurupiahChannelPolicy::selected($setting->fresh()));
        $this->assertSame(9, $setting->fresh()->payment_provider_version);
        $this->assertSame('QRIS', SakurupiahChannelPolicy::forPayment($observed->fresh()));
        $this->assertSame('QRISC', SakurupiahChannelPolicy::forPayment($unobserved->fresh()));
        $this->assertSame('outcome_unknown', $unobserved->fresh()->provider_create_state);
        $this->assertNull($otherProvider->fresh()->sakurupiah_channel);
        $after = $observed->fresh()->getAttributes();
        unset($after['sakurupiah_channel']);
        $this->assertSame($before, $after);
    }

    public function test_channel_cutover_does_not_reinterpret_legacy_top_up_local_qris_label(): void
    {
        $migration = require database_path('migrations/2026_10_27_000001_add_sakurupiah_channel_selection.php');
        $migration->down();
        $customer = Customer::factory()->create();
        $intent = StoreBalanceFundingIntent::create([
            'customer_id' => $customer->id,
            'chat_id' => '70001',
            'canonical_message_id' => 1,
            'policy_version' => 1,
            'amount' => 10000,
            'nonce' => 'legacy-channel-migration',
            'status' => 'confirmed',
            'expires_at' => now()->addHour(),
        ]);
        $topUp = StoreBalanceTopUp::create([
            'customer_id' => $customer->id,
            'store_balance_funding_intent_id' => $intent->id,
            'chat_id' => '70001',
            'canonical_message_id' => 1,
            'provider' => 'sakurupiah',
            'merchant_ref' => 'legacy-top-up-channel',
            'idempotency_key' => 'legacy-top-up-channel',
            'amount' => 10000,
            'provider_payment_method' => 'QRIS',
            'provider_total_payment' => 10170,
            'provider_fee' => 170,
        ]);
        $before = $topUp->getAttributes();

        $migration->up();

        $this->assertSame('QRISC', SakurupiahChannelPolicy::forTopUp($topUp->fresh()));
        $this->assertSame('QRIS', $topUp->fresh()->provider_payment_method);
        $after = $topUp->fresh()->getAttributes();
        unset($after['sakurupiah_channel']);
        // Database defaults are loaded on the retained historical row.
        foreach ($before as $key => $value) {
            $this->assertSame($value, $after[$key], $key);
        }
    }
}
