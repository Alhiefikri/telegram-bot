<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Order;
use App\Services\Customer\CustomerOrderClaimService;
use App\Storefront\StorefrontOrderAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerOrderClaimCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public static function accountOwners(): array
    {
        return [
            'nullable-email Google' => ['google'],
            'nullable-email password history' => ['password'],
            'nullable-email verified history' => ['verified'],
            'Telegram-only' => ['telegram'],
            'legacy magic link' => ['email'],
        ];
    }

    #[DataProvider('accountOwners')]
    public function test_account_owned_order_cannot_be_reclaimed_even_with_the_recovery_key(string $shape): void
    {
        $owner = Customer::factory()->create(['telegram_id' => null, 'email' => null, 'email_verified_at' => null]);
        match ($shape) {
            'google' => (new CustomerIdentity)->forceFill(['customer_id' => $owner->id, 'provider' => 'google', 'provider_subject' => 'private-subject'])->save(),
            'password' => $owner->forceFill(['password' => Hash::make('Historical password')])->save(),
            'verified' => $owner->forceFill(['email_verified_at' => now()])->save(),
            'telegram' => $owner->forceFill(['telegram_id' => 456789])->save(),
            'email' => $owner->forceFill(['email' => 'legacy@example.test'])->save(),
        };
        $order = Order::factory()->create(['customer_id' => $owner->id, 'payment_status' => PaymentStatus::Paid]);
        $access = app(StorefrontOrderAccess::class);
        $key = $access->issue($order);
        $hash = $order->credential_access_key_hash;
        $claimant = Customer::factory()->create(['email' => 'claimant@example.test', 'email_verified_at' => now()]);

        $this->assertNull(app(CustomerOrderClaimService::class)->claim($claimant, $order->invoice_number, $key));
        $this->assertSame($owner->id, $order->fresh()->customer_id);
        $this->assertSame($hash, $order->fresh()->credential_access_key_hash);
        $this->assertTrue($access->verifyKey($order->fresh(), $key));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'order.customer_claimed', 'subject_id' => $order->id]);
    }

    public function test_guest_claimed_by_nullable_email_google_customer_has_one_permanent_owner(): void
    {
        $owner = Customer::factory()->create(['telegram_id' => null, 'email' => null]);
        (new CustomerIdentity)->forceFill(['customer_id' => $owner->id, 'provider' => 'google', 'provider_subject' => 'google-owner'])->save();
        $guest = Customer::factory()->create(['telegram_id' => null, 'email' => null]);
        $order = Order::factory()->create(['customer_id' => $guest->id, 'payment_status' => PaymentStatus::Paid]);
        $access = app(StorefrontOrderAccess::class);
        $key = $access->issue($order);
        $claimant = Customer::factory()->create(['email' => 'claimant@example.test', 'email_verified_at' => now()]);
        $claims = app(CustomerOrderClaimService::class);

        $this->assertNotNull($claims->claim($owner, $order->invoice_number, $key));
        $this->assertNull($claims->claim($claimant, $order->invoice_number, $key));
        $this->assertNotNull($claims->claim($owner, $order->invoice_number, $key));
        $this->assertSame($owner->id, $order->fresh()->customer_id);
        $this->assertTrue($access->verifyKey($order->fresh(), $key));
        $this->assertSame(1, AuditLog::query()->where('action', 'order.customer_claimed')->where('subject_id', $order->id)->count());
    }
}
