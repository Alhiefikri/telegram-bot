<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminTelegramLatencyInstrumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_broadcast_dogfood_timing_reports_queued_request_without_recipient_calls(): void
    {
        config()->set('services.dogfood_timing.enabled', true);
        config()->set('services.telegram.token', 'bot-token');
        Queue::fake();
        $logs = $this->captureDogfoodInfoLogs();
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);

        Customer::factory()->create(['telegram_id' => 111111111]);
        Customer::factory()->create(['telegram_id' => 222222222]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->post(route('admin.broadcast.store'), [
                'message' => 'Sensitive broadcast body',
            ])
            ->assertRedirect('/admin?tab=broadcast#admin-section-broadcast')
            ->assertSessionHas('status', 'Broadcast #1 masuk antrean. Progres bisa dipantau di tab Broadcast.');

        $this->assertDogfoodLog($logs, 'dogfood.telegram.admin_request_finished', fn (array $context): bool => $context['action'] === 'admin.broadcast'
            && $context['recipient_count'] === 0
            && $context['sent'] === 0
            && $context['failed'] === 0
            && $context['skipped'] === false
            && $context['queued'] === true
            && array_key_exists('request_duration_ms', $context)
            && array_key_exists('telegram_total_duration_ms', $context)
            && $context['per_recipient_telegram'] === []
        );

        Http::assertNothingSent();

        $this->assertDogfoodLogsDoNotContain($logs, ['111111111', '222222222', 'bot-token', 'Sensitive broadcast body']);
    }

    public function test_product_create_and_stock_add_dogfood_timing_report_admin_request_duration(): void
    {
        config()->set('services.dogfood_timing.enabled', true);
        config()->set('services.telegram.token', 'bot-token');
        $logs = $this->captureDogfoodInfoLogs();
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 88]])]);
        Customer::factory()->create(['telegram_id' => 333333333]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->post(route('admin.products.store'), [
                'name' => 'Instrumented Product',
                'price_amount' => '75000',
                'description' => 'Private product details',
            ])
            ->assertRedirect('/admin?tab=produk#product-1');

        $product = Product::query()->where('name', 'Instrumented Product')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.products.stock.store', $product), [
                'stock_items' => "secret-1@example.test|pass\nsecret-2@example.test|pass",
            ])
            ->assertRedirect('/admin?tab=produk#product-1');

        $this->assertDogfoodLog($logs, 'dogfood.telegram.admin_request_finished', fn (array $context): bool => $context['action'] === 'admin.product_create'
            && $context['recipient_count'] === 0
            && $context['sent'] === 0
            && $context['failed'] === 0
            && $context['skipped'] === true
            && array_key_exists('request_duration_ms', $context)
        );

        $this->assertDogfoodLog($logs, 'dogfood.telegram.admin_request_finished', fn (array $context): bool => $context['action'] === 'admin.product_stock_add'
            && $context['recipient_count'] === 0
            && $context['sent'] === 0
            && $context['failed'] === 0
            && $context['skipped'] === false
            && $context['queued'] === true
            && array_key_exists('request_duration_ms', $context)
            && array_key_exists('telegram_total_duration_ms', $context)
            && $context['per_recipient_telegram'] === []
        );
        $this->assertDogfoodLog($logs, 'dogfood.telegram.broadcast_finished', fn (array $context): bool => $context['recipient_count'] === 1
            && $context['sent'] === 1
            && $context['failed'] === 0);

        $this->assertDogfoodLogsDoNotContain($logs, [
            '333333333',
            'bot-token',
            'Private product details',
            'secret-1@example.test',
            'secret-2@example.test',
        ]);
    }

    private function captureDogfoodInfoLogs(): object
    {
        $logs = (object) ['entries' => []];

        Log::shouldReceive('info')->zeroOrMoreTimes()->withArgs(function (string $event, array $context = []) use ($logs): bool {
            if (str_starts_with($event, 'dogfood.')) {
                $logs->entries[] = [$event, $context];
            }

            return true;
        });
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        return $logs;
    }

    private function assertDogfoodLog(object $logs, string $event, callable $predicate): void
    {
        foreach ($logs->entries as [$loggedEvent, $context]) {
            if ($loggedEvent === $event && $predicate($context)) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->fail("Expected dogfood log [{$event}] was not emitted with matching context.");
    }

    private function assertDogfoodLogsDoNotContain(object $logs, array $needles): void
    {
        $joined = json_encode($logs->entries, JSON_THROW_ON_ERROR);

        foreach ($needles as $needle) {
            $this->assertStringNotContainsString($needle, $joined);
        }
    }
}
