<?php

namespace Tests\Feature;

use App\Jobs\ProcessBuyMailWebhook;
use App\Models\Supplier;
use App\Models\SupplierDeposit;
use App\Models\SupplierWebhookEvent;
use App\Services\Suppliers\BuyMailWebhookService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\NonTransactionalDatabaseTestCase;

#[Group('postgres-release')]
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
        $event = SupplierWebhookEvent::sole();
        Queue::assertPushedOn('supplier-claims', ProcessBuyMailWebhook::class, fn (ProcessBuyMailWebhook $job) => $job->eventId === $event->id && $job->connection === 'supplier');
        Queue::assertPushed(ProcessBuyMailWebhook::class, 1);
        $this->call('POST', $url, server: $headers, content: $body)->assertOk();
        Queue::assertPushed(ProcessBuyMailWebhook::class, 1);
        $this->assertSame(1, SupplierWebhookEvent::count());
        $this->assertStringNotContainsString('evt-1', DB::table('supplier_webhook_events')->value('payload'));
        $bad = array_replace($headers, ['HTTP_X_WEBHOOK_SIGNATURE' => 't='.time().',v1='.str_repeat('0', 64)]);
        $this->call('POST', $url, server: $bad, content: $body)->assertUnauthorized();
        $mismatch = array_replace($headers, ['HTTP_X_WEBHOOK_ID' => 'different-event']);
        $this->call('POST', $url, server: $mismatch, content: $body)->assertUnprocessable();
        Queue::assertPushed(ProcessBuyMailWebhook::class, 1);
        Http::assertNothingSent();
    }

    public function test_signed_webhooks_persist_only_routing_fields_and_reconcile_from_authoritative_get(): void
    {
        Queue::fake();
        $secret = 'whsec_synthetic-fixture-secret';
        $supplier = Supplier::forceCreate(['name' => 'Private source', 'provider' => 'buymail', 'api_key' => 'synthetic-key', 'currency' => 'IDR', 'exchange_rate_idr' => 1, 'is_active' => true, 'external_account_id' => '123', 'webhook_secret' => $secret]);
        foreach (['ping', 'deposit.paid', 'refund.approved', 'refund.rejected', 'refund.reversed', 'refund.cancelled'] as $index => $type) {
            $id = 'evt-'.$index;
            $resourceKey = $type === 'deposit.paid' ? 'deposit' : 'account';
            $payload = [
                'id' => $id, 'type' => $type, 'secret' => 'private-top-level',
                'data' => [
                    $resourceKey => ['id' => 'RESOURCE1', 'email' => 'private@example.test', 'password' => 'private-password', 'token' => 'private-token', 'refund' => ['status' => 'approved', 'credentials' => 'private-nested']],
                    'credentials' => 'private-data',
                ],
            ];
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $timestamp = time();
            $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_ID' => $id, 'HTTP_X_WEBHOOK_EVENT' => $type,
                'HTTP_X_WEBHOOK_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret)];
            $url = '/api/webhook/suppliers/buymail/'.$supplier->id;
            $this->call('POST', $url, server: $headers, content: $body)->assertOk();
            $this->call('POST', $url, server: $headers, content: $body)->assertOk();
            $event = SupplierWebhookEvent::where('external_event_id', $id)->sole();
            $this->assertSame(['id' => $id, 'type' => $type, 'data' => $type === 'ping' ? [] : [$resourceKey => ['id' => 'RESOURCE1']]], $event->payload);
            $this->assertArrayNotHasKey('payload', $event->toArray());
            $this->assertStringNotContainsString($id, $event->getRawOriginal('payload'));
        }
        $this->assertSame(6, SupplierWebhookEvent::count());
        Queue::assertPushed(ProcessBuyMailWebhook::class, 6);
        Http::assertNothingSent();
        Http::fake(fn () => Http::response(['deposit' => [
            'id' => 'RESOURCE1', 'amount' => 100000, 'currency' => 'IDR', 'status' => 'pending',
            'payment_url' => 'https://payments.example.test/resource1', 'expires_at' => now()->addHour()->utc()->toIso8601String(),
        ]]));
        $event = SupplierWebhookEvent::where('event_type', 'deposit.paid')->sole();
        app(BuyMailWebhookService::class)->process($event->id);
        $this->assertSame('processed', $event->fresh()->state);
        $this->assertSame('pending', SupplierDeposit::sole()->state);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/deposits/RESOURCE1'));
        Http::assertSentCount(1);
    }

    public function test_signed_dot_segment_resources_are_rejected_before_durable_acceptance(): void
    {
        Queue::fake();
        $secret = 'whsec_synthetic-fixture-secret';
        $supplier = Supplier::forceCreate(['name' => 'Private source', 'provider' => 'buymail', 'api_key' => 'synthetic-key', 'currency' => 'IDR', 'exchange_rate_idr' => 1, 'is_active' => true, 'webhook_secret' => $secret]);
        $timestamp = time();
        foreach (['deposit.paid', 'refund.approved'] as $type) {
            foreach (['.', '..'] as $resource) {
                $id = 'evt-'.$type.'-'.$resource;
                $body = json_encode(['id' => $id, 'type' => $type, 'data' => [$type === 'deposit.paid' ? 'deposit' : 'account' => ['id' => $resource]]], JSON_THROW_ON_ERROR);
                $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_ID' => $id, 'HTTP_X_WEBHOOK_EVENT' => $type,
                    'HTTP_X_WEBHOOK_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret)];
                $this->call('POST', '/api/webhook/suppliers/buymail/'.$supplier->id, server: $headers, content: $body)->assertUnprocessable();
            }
        }
        $this->assertSame(0, SupplierWebhookEvent::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
