<?php

namespace Tests\Feature;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectPolicy;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\NonTransactionalDatabaseTestCase;

/**
 * The recovery sweep must stay index-driven.
 *
 * PostgreSQL only uses the dispatch-family and state index predicates when it
 * can prove the retryable restriction implies them at plan time, and it cannot
 * prove that from a bound parameter. Losing that proof turned every sweep into
 * a sequential scan of a multi-gigabyte ledger, which starved the recovery
 * worker's statement timeout.
 */
#[Group('postgres-release')]
final class TelegramOutboundDispatchPlanTest extends NonTransactionalDatabaseTestCase
{
    public function test_the_retryable_sweep_keeps_its_membership_out_of_the_bindings(): void
    {
        $query = app(TelegramOutboundEffectLedgerProtocol::class)
            ->readyToDispatchQuery(now(), TelegramOutboundEffectType::AdminOrderPaidNotification->value);

        $bindings = $query->getBindings();

        $this->assertNotContains(TelegramOutboundEffectState::Pending->value, $bindings);
        $this->assertNotContains(TelegramOutboundEffectState::Failed->value, $bindings);
        $this->assertSame(
            [],
            array_values(array_intersect($bindings, TelegramOutboundEffectPolicy::nonRetryableFailureCodes())),
            'Retryable membership must stay a plan-time constant, never a bound parameter.',
        );
    }

    public function test_runtime_failure_codes_cover_the_deployed_dispatch_index_predicate(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The dispatch index predicate guard requires the pgsql connection.');
        }

        $definition = (string) DB::table('pg_indexes')
            ->where('indexname', 'telegram_outbound_effects_dispatch_family_idx')
            ->value('indexdef');

        $this->assertNotSame('', $definition, 'The dispatch-family index must exist for the sweep to use it.');

        preg_match_all("/'([a-z0-9_]+)'::character varying/", $definition, $matches);

        $this->assertSame(
            [],
            array_values(array_diff(array_values(array_unique($matches[1])), TelegramOutboundEffectPolicy::nonRetryableFailureCodes())),
            'Treating a code as retryable while the dispatch index omits it stops PostgreSQL from using the partial index.',
        );
    }
}
