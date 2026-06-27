<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Telegram\TelegramMiniAppAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramMiniAppAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.telegram.token', '123:token');
        config()->set('services.telegram.admin_user_id', '42');
        config()->set('services.telegram.admin_telegram_chat_id', null);
    }

    public function test_authenticator_accepts_valid_signed_admin_init_data(): void
    {
        $auth = app(TelegramMiniAppAuthenticator::class);
        $user = $auth->authenticateAdmin($this->initData(['id' => 42, 'first_name' => 'Owner']));

        $this->assertSame(42, $user['id']);
        $this->assertSame('Owner', $user['name']);
    }

    public function test_authenticator_rejects_bad_hash_expired_and_non_admin(): void
    {
        $this->postJson(route('telegram.mini-app.admin.bootstrap'), ['initData' => $this->initData(['id' => 42]).'tampered=1'])->assertUnauthorized();
        $this->postJson(route('telegram.mini-app.admin.bootstrap'), ['initData' => $this->initData(['id' => 42], now()->subHour()->timestamp)])->assertUnauthorized();
        $this->postJson(route('telegram.mini-app.admin.bootstrap'), ['initData' => $this->initData(['id' => 99])])->assertForbidden();
    }

    public function test_bootstrap_denied_without_init_data(): void
    {
        $this->postJson(route('telegram.mini-app.admin.bootstrap'), [])->assertUnauthorized();
    }

    public function test_bootstrap_returns_computed_dashboard_stats(): void
    {
        Product::factory()->count(2)->create();
        $paidOrder = Order::factory()->create(['payment_status' => PaymentStatus::Paid, 'total_amount' => 125000]);
        Order::factory()->create(['payment_status' => PaymentStatus::Pending, 'total_amount' => 75000]);
        Payment::factory()->create(['order_id' => $paidOrder->id, 'status' => PaymentStatus::Paid, 'amount' => 120000, 'paid_at' => now()]);

        $this->postJson(route('telegram.mini-app.admin.bootstrap'), ['initData' => $this->initData(['id' => 42])])
            ->assertOk()
            ->assertJsonPath('dashboard.stats.balance', 120000)
            ->assertJsonPath('dashboard.stats.escrow', 75000)
            ->assertJsonPath('dashboard.stats.gmv', 200000)
            ->assertJsonPath('dashboard.stats.product_count', 2)
            ->assertJsonPath('dashboard.stats.order_count', 2)
            ->assertJsonPath('dashboard.stats.pending_withdraw_count', 0);
    }

    public function test_bootstrap_paid_balance_ignores_unpaid_payment_statuses(): void
    {
        Payment::factory()->create(['status' => PaymentStatus::Paid, 'amount' => 150000, 'paid_at' => now()]);
        Payment::factory()->create(['status' => PaymentStatus::Pending, 'amount' => 200000]);
        Payment::factory()->create(['status' => PaymentStatus::Refunded, 'amount' => 300000]);
        Payment::factory()->create(['status' => PaymentStatus::Failed, 'amount' => 400000]);

        $this->postJson(route('telegram.mini-app.admin.bootstrap'), ['initData' => $this->initData(['id' => 42])])
            ->assertOk()
            ->assertJsonPath('dashboard.stats.balance', 150000);
    }

    private function initData(array $user, ?int $authDate = null): string
    {
        $data = [
            'auth_date' => (string) ($authDate ?? now()->timestamp),
            'query_id' => 'AAExample',
            'user' => json_encode($user),
        ];

        ksort($data, SORT_STRING);
        $checkString = collect($data)->map(fn ($value, $key) => $key.'='.$value)->implode("\n");
        $secretKey = hash_hmac('sha256', '123:token', 'WebAppData', true);
        $data['hash'] = hash_hmac('sha256', $checkString, $secretKey);

        return http_build_query($data);
    }
}
