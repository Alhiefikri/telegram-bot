<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierWebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class BuyMailWebhookTest extends NonTransactionalDatabaseTestCase
{
    public function test_valid_signed_events_are_durable_deduplicated_and_never_trust_a_header_alone(): void
    {
        Queue::fake();
        $supplier = Supplier::forceCreate(['name' => 'Private source', 'provider' => 'buymail', 'api_key' => 'synthetic-key', 'currency' => 'IDR', 'exchange_rate_idr' => 1, 'is_active' => true, 'webhook_secret' => 'whsec_synthetic-fixture-secret']);
        $body = json_encode(['id' => 'evt-1', 'type' => 'ping', 'data' => new \stdClass], JSON_THROW_ON_ERROR);
        $headers = [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_ID' => 'evt-1',
            'HTTP_X_WEBHOOK_EVENT' => 'ping', 'HTTP_X_WEBHOOK_SIGNATURE' => 't='.time().',v1='.hash_hmac('sha256', time().'.'.$body, 'whsec_synthetic-fixture-secret'),
        ];
        $url = '/api/webhook/suppliers/buymail/'.$supplier->id;
        $this->call('POST', $url, server: $headers, content: $body)->assertOk();
        $this->call('POST', $url, server: $headers, content: $body)->assertOk();
        $this->assertSame(1, SupplierWebhookEvent::count());
        $this->assertStringNotContainsString('evt-1', DB::table('supplier_webhook_events')->value('payload'));
        $bad = array_replace($headers, ['HTTP_X_WEBHOOK_SIGNATURE' => 't='.time().',v1='.str_repeat('0', 64)]);
        $this->call('POST', $url, server: $bad, content: $body)->assertUnauthorized();
        $mismatch = array_replace($headers, ['HTTP_X_WEBHOOK_ID' => 'different-event']);
        $this->call('POST', $url, server: $mismatch, content: $body)->assertUnprocessable();
        Http::assertNothingSent();
    }
}
