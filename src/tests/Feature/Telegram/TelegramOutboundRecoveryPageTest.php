<?php

namespace Tests\Feature\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundRecoveryPage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TelegramOutboundRecoveryPageTest extends TestCase
{
    use RefreshDatabase;

    private const TYPES = [
        TelegramOutboundEffectType::AdminOrderPaidNotification->value,
        TelegramOutboundEffectType::ReceiptCredentials->value,
        TelegramOutboundEffectType::SakurupiahCheckoutInstructions->value,
    ];

    public function test_global_pages_preserve_order_and_do_not_skip_later_members_of_a_family(): void
    {
        for ($i = 1; $i <= 9; $i++) {
            $this->effect($i, self::TYPES[($i - 1) % 3]);
        }
        $pages = app(TelegramOutboundRecoveryPage::class);
        $seen = [];
        for ($page = 0; $page < 3; $page++) {
            $seen = [...$seen, ...$pages->loadUnion('ordered_families', $this->families(), 3)->modelKeys()];
        }

        $this->assertSame(array_map($this->id(...), range(1, 9)), $seen);
        // A full final page wraps on the next empty read, not into lost work.
        $this->assertSame([$this->id(1), $this->id(2), $this->id(3)],
            $pages->loadUnion('ordered_families', $this->families(), 3)->modelKeys());
    }

    public function test_losing_the_cursor_rediscovers_work_without_changing_delivery_authority(): void
    {
        $first = $this->effect(1, self::TYPES[0]);
        $second = $this->effect(2, self::TYPES[1]);
        $pages = app(TelegramOutboundRecoveryPage::class);
        $this->assertSame([$first->id], $pages->loadUnion('lost_cursor', $this->families(), 1)->modelKeys());
        Cache::forget('telegram:recover-outbound:cursor:lost_cursor');
        $this->assertSame([$first->id], $pages->loadUnion('lost_cursor', $this->families(), 1)->modelKeys());

        $protocol = app(TelegramOutboundEffectLedgerProtocol::class);
        $claim = $protocol->claim($first->id);
        $this->assertNotNull($claim);
        $this->assertNull($protocol->claim($first->id));
        $this->assertSame([$second->id], $pages->loadUnion('lost_cursor', $this->families(), 1)->modelKeys());
    }

    public function test_union_preserves_due_retry_terminal_and_receipt_predecessor_rules(): void
    {
        $this->effect(1, self::TYPES[0], ['state' => 'unknown']);
        $this->effect(2, self::TYPES[0], ['state' => 'manual_review']);
        $this->effect(3, self::TYPES[0], ['state' => 'failed', 'last_error_code' => 'telegram_http_403']);
        $this->effect(4, self::TYPES[0], ['next_attempt_at' => now()->addHour()]);
        $this->effect(5, self::TYPES[0], ['attempts' => 3]);
        $this->effect(6, self::TYPES[1], ['effect_group_key' => 'blocked', 'state' => 'unknown']);
        $this->effect(7, self::TYPES[1], ['effect_group_key' => 'blocked', 'sequence' => 2]);
        $ready = $this->effect(8, self::TYPES[0], ['state' => 'failed', 'attempts' => 1]);
        $checkout = $this->effect(9, self::TYPES[2]);

        $this->assertSame([$ready->id, $checkout->id],
            app(TelegramOutboundRecoveryPage::class)->loadUnion('eligibility', $this->families(), 25)->modelKeys());
        $this->assertSame(TelegramOutboundEffectState::Unknown, TelegramOutboundEffect::findOrFail($this->id(6))->state);
    }

    public function test_postgres_page_does_not_read_every_ready_effect_before_applying_its_limit(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires actual PostgreSQL execution plans.');
        }
        foreach (self::TYPES as $i => $type) {
            DB::insert("INSERT INTO telegram_outbound_effects
                (id, effect_key, effect_type, source_type, source_id, telegram_chat_id, telegram_method, state, attempts)
                SELECT md5(? || n)::uuid, ? || n, ?, 'fixture', n::text, '1', 'sendMessage', 'pending', 0
                FROM generate_series(1, 10000) n", ["family-$i-", "family-$i-", $type]);
        }
        DB::statement('ANALYZE telegram_outbound_effects');
        $captured = null;
        DB::listen(function (QueryExecuted $event) use (&$captured): void {
            if (str_contains($event->sql, 'union all') && ! str_starts_with($event->sql, 'EXPLAIN')) {
                $captured = $event;
            }
        });
        $page = app(TelegramOutboundRecoveryPage::class)->loadUnion('large_backlog', $this->families(), 25);
        $this->assertCount(25, $page);
        $this->assertNotNull($captured);
        /** @var QueryExecuted $captured */
        $plan = json_decode(DB::selectOne('EXPLAIN (ANALYZE, FORMAT JSON) '.$captured->sql, $captured->bindings)->{'QUERY PLAN'}, true)[0]['Plan'];
        $this->assertLessThanOrEqual(75, $this->rowsRead($plan), 'A 25-row page must not read all 30,000 ready effects.');
    }

    private function rowsRead(array $node): int
    {
        $rows = ($node['Relation Name'] ?? '') === 'telegram_outbound_effects'
            ? (int) ($node['Actual Rows'] * $node['Actual Loops']) : 0;
        foreach ($node['Plans'] ?? [] as $child) {
            $rows += $this->rowsRead($child);
        }

        return $rows;
    }

    private function families(): array
    {
        return array_map(fn (string $type) => app(TelegramOutboundEffectLedgerProtocol::class)->readyToDispatchQuery(now(), $type), self::TYPES);
    }

    private function effect(int $id, string $type, array $attributes = []): TelegramOutboundEffect
    {
        return TelegramOutboundEffect::factory()->create(array_merge([
            'id' => $this->id($id), 'effect_type' => $type, 'next_attempt_at' => null,
        ], $attributes));
    }

    private function id(int $id): string
    {
        return sprintf('00000000-0000-0000-0000-%012d', $id);
    }
}
