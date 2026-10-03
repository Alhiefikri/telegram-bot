<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\OrderRefund;
use App\Models\User;
use App\Services\Warranty\RefundProof;
use App\Services\Warranty\WarrantyClaimService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\BuildsBuyMailWarrantyFixture;
use Tests\Support\NonTransactionalDatabaseTestCase;

#[Group('postgres-release')]
final class WarrantyAdminTest extends NonTransactionalDatabaseTestCase
{
    use BuildsBuyMailWarrantyFixture;

    public function test_private_claim_monitor_and_original_proof_are_owner_admin_only(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$customer, $unit] = $this->fixture();
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII=');
        $claim = app(WarrantyClaimService::class)->submit($customer, $unit->public_id, RefundProof::fromBytes($bytes), 'Wrong password');
        $this->actingAs(User::factory()->create(['role' => UserRole::Support]));
        $this->get('/admin/suppliers/warranty')->assertForbidden();
        $this->get('/admin/suppliers/warranty/'.$claim->id.'/proof')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $this->get('/admin/suppliers/warranty')->assertOk()->assertSee($unit->order->invoice_number)->assertDontSee('synthetic-test-key');
        $this->withSession(['auth.password_confirmed_at' => time()])->get('/admin/suppliers/warranty/'.$claim->id.'/proof')->assertOk()->assertHeader('Content-Type', 'image/png')->assertContent($bytes);
    }

    public function test_owner_can_resubmit_an_uncertain_claim_only_when_the_authoritative_get_is_empty(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$customer, $unit] = $this->fixture();
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII=');
        $claim = app(WarrantyClaimService::class)->submit($customer, $unit->public_id, RefundProof::fromBytes($bytes), 'Wrong password');
        $claim->update(['state' => 'review', 'dispatched_at' => now()->subMinutes(5), 'last_error' => 'warranty_transport_unavailable']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $this->get('/admin/suppliers/warranty')->assertOk()->assertSee('Resubmit bukti original');
        $support = User::factory()->create(['role' => UserRole::Support]);
        $this->actingAs($support)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/admin/suppliers/warranty/'.$claim->id.'/resubmit')->assertForbidden();
        Http::fake(fn () => Http::response(['account' => [
            'id' => 'PR1', 'order_id' => 'ORD1', 'status' => 'active', 'price' => 4800,
            'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(), 'refund' => null,
        ]]));
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]))
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/admin/suppliers/warranty/'.$claim->id.'/resubmit')
            ->assertRedirect(route('admin.suppliers.warranty'));
        $fresh = $claim->fresh();
        $this->assertSame('received', $fresh->state);
        $this->assertNull($fresh->dispatched_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'warranty.claim_resubmitted']);
    }

    public function test_confirmed_remainder_refunds_are_visible_in_the_private_monitor(): void
    {
        [$customer, $unit] = $this->fixture();
        $order = $unit->order;
        $payment = $order->payments()->sole();
        OrderRefund::create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'customer_id' => $customer->id,
            'amount' => 8000,
            'currency' => 'IDR',
            'state' => 'confirmed',
            'external_reference' => 'synthetic-remainder-transfer',
            'external_reference_hash' => hash('sha256', 'manual|IDR|synthetic-remainder-transfer'),
            'confirmed_at' => now(),
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $this->get('/admin/suppliers/warranty')
            ->assertOk()
            ->assertSee('Refund sisa pembayaran pesanan '.$order->id)
            ->assertSee('Sisa tanpa klaim unit')
            ->assertDontSee('synthetic-remainder-transfer');
    }
}
