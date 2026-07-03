<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AdminTelegramLatencyInstrumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_broadcast_dogfood_timing_reports_request_recipients_and_failures(): void
    {
        config()->set('services.dogfood_timing.enabled', true);
        config()->set('services.telegram.token', 'bot-token');
        $logs = $this->captureDogfoodInfoLogs();
        Http::fake(fn (HttpRequest $request) => (string) $request['chat_id'] === '222'
            ? Http::response(['ok' => false, 'description' => 'rate limited'], 429)
            : Http::response(['ok' => true, 'result' => ['message_id' => 77]])
        );

        Customer::factory()->create(['telegram_id' => 111]);
        Customer::factory()->create(['telegram_id' => 222]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->post(route('admin.broadcast.store'), [
                'message' => 'Sensitive broadcast body',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'broadcast']))
            ->assertSessionHas('status', 'Broadcast terkirim ke 1 user. Gagal: 1.');

        $this->assertDogfoodLog($logs, 'dogfood.telegram.admin_request_finished', fn (array $context): bool => $context['action'] === 'admin.broadcast'
            && $context['recipient_count'] === 2
            && $context['sent'] === 1
            && $context['failed'] === 1
            && $context['skipped'] === false
            && array_key_exists('request_duration_ms', $context)
            && array_key_exists('telegram_total_duration_ms', $context)
            && count($context['per_recipient_telegram']) === 2
            && $context['per_recipient_telegram'][0]['status'] === 'ok'
            && $context['per_recipient_telegram'][1]['status'] === 'error'
            && $context['per_recipient_telegram'][1]['exception'] !== ''
        );

        $this->assertDogfoodLog($logs, 'dogfood.telegram.broadcast_finished', fn (array $context): bool => $context['recipient_count'] === 2
            && $context['sent'] === 1
            && $context['failed'] === 1
        );

        $this->assertDogfoodLogsDoNotContain($logs, ['111', '222', 'bot-token', 'Sensitive broadcast body']);
    }

    public function test_product_create_and_stock_add_dogfood_timing_report_admin_request_duration(): void
    {
        config()->set('services.dogfood_timing.enabled', true);
        config()->set('services.telegram.token', 'bot-token');
        $logs = $this->captureDogfoodInfoLogs();
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 88]])]);
        Customer::factory()->create(['telegram_id' => 333]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->post(route('admin.products.store'), [
                'name' => 'Instrumented Product',
                'price_amount' => '75000',
                'description' => 'Private product details',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']));

        $product = Product::query()->where('name', 'Instrumented Product')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.products.stock.store', $product), [
                'stock_items' => "secret-1@example.test|pass\nsecret-2@example.test|pass",
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']));

        $this->assertDogfoodLog($logs, 'dogfood.telegram.admin_request_finished', fn (array $context): bool => $context['action'] === 'admin.product_create'
            && $context['recipient_count'] === 0
            && $context['sent'] === 0
            && $context['failed'] === 0
            && $context['skipped'] === true
            && array_key_exists('request_duration_ms', $context)
        );

        $this->assertDogfoodLog($logs, 'dogfood.telegram.admin_request_finished', fn (array $context): bool => $context['action'] === 'admin.product_stock_add'
            && $context['recipient_count'] === 1
            && $context['sent'] === 1
            && $context['failed'] === 0
            && $context['skipped'] === false
            && array_key_exists('request_duration_ms', $context)
            && array_key_exists('telegram_total_duration_ms', $context)
            && count($context['per_recipient_telegram']) === 1
            && $context['per_recipient_telegram'][0]['status'] === 'ok'
        );

        $this->assertDogfoodLogsDoNotContain($logs, [
            '333',
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
