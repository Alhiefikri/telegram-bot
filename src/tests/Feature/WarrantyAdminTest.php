<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Warranty\RefundProof;
use App\Services\Warranty\WarrantyClaimService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBuyMailWarrantyFixture;
use Tests\Support\NonTransactionalDatabaseTestCase;

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
}
