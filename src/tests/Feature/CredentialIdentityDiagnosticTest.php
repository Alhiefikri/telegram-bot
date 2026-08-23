<?php

namespace Tests\Feature;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CredentialIdentity;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Credentials\CredentialIdentityKind;
use App\Services\Credentials\StructuredCredentialPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CredentialIdentityDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.credentials.identity_fingerprint_key', 'credential-diagnostic-test-key');
    }

    public function test_it_reports_exact_sanitized_conflicts_and_safe_stock_statuses(): void
    {
        $product = Product::factory()->create();
        $owner = ProductStockItem::create([
            'product_id' => $product->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'owner@example.test',
                'owner-password',
                CredentialAccessMethod::AuthToken,
                'shared-auth-token',
            )->toArray(),
        ]);
        $candidate = ProductStockItem::create([
            'product_id' => $product->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'candidate@example.test',
                'candidate-password',
                CredentialAccessMethod::AuthToken,
                'shared-auth-token',
            )->toArray(),
        ]);
        CredentialIdentity::create([
            'identity_kind' => CredentialIdentityKind::AuthToken,
            'fingerprint' => hash_hmac('sha256', 'shared-auth-token', 'credential-diagnostic-test-key'),
            'product_id' => $product->id,
            'product_stock_item_id' => $owner->id,
            'first_seen_at' => now(),
        ]);

        $reservedOrder = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);
        $reservedOrderItem = OrderItem::factory()->create([
            'order_id' => $reservedOrder->id,
            'product_id' => $product->id,
        ]);
        $reserved = ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $reservedOrderItem->id,
            'secret' => 'reserved@example.test|reserved-password|reserved-mfa',
        ]);
        $retired = ProductStockItem::create([
            'product_id' => $product->id,
            'retired_at' => now(),
            'retired_reason' => 'test-only',
            'secret' => 'retired@example.test|retired-password|retired-mfa',
        ]);
        $malformed = ProductStockItem::create([
            'product_id' => $product->id,
            'secret' => 'not-a-confident-legacy-identity',
        ]);

        $ids = [$owner->id, $candidate->id, $reserved->id, $retired->id, $malformed->id];
        $exitCode = Artisan::call('credentials:diagnose-identity-conflicts', [
            'stock-item-ids' => implode(',', $ids),
            '--json' => true,
        ]);
        $output = Artisan::output();
        $diagnostic = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $items = collect($diagnostic['items'])->keyBy('stock_item_id');

        $this->assertSame(0, $exitCode);
        $this->assertSame($ids, $diagnostic['requested_stock_item_ids']);
        $candidateToken = collect($items[$candidate->id]['identities'])->firstWhere('identity_kind', CredentialIdentityKind::AuthToken);
        $ownerToken = collect($items[$owner->id]['identities'])->firstWhere('identity_kind', CredentialIdentityKind::AuthToken);
        $this->assertSame('registered_to_other', $candidateToken['registry_state']);
        $this->assertSame([$owner->id], $candidateToken['conflict_with_stock_item_ids']);
        $this->assertSame('registered_to_this', $ownerToken['registry_state']);
        $this->assertSame([$candidate->id], $ownerToken['conflict_with_stock_item_ids']);
        $this->assertSame('reserved', $items[$reserved->id]['availability_status']);
        $this->assertSame(OrderStatus::PendingPayment->value, $items[$reserved->id]['order_status']);
        $this->assertSame('retired', $items[$retired->id]['availability_status']);
        $this->assertTrue($items[$retired->id]['retired']);
        $this->assertSame('unrecognized', $items[$malformed->id]['identity_status']);
        $this->assertSame(2, $diagnostic['summary']['conflict_identity_count']);
        $this->assertStringNotContainsString('owner@example.test', $output);
        $this->assertStringNotContainsString('candidate@example.test', $output);
        $this->assertStringNotContainsString('owner-password', $output);
        $this->assertStringNotContainsString('shared-auth-token', $output);
        $this->assertStringNotContainsString('retired-mfa', $output);
    }
}
