<?php

namespace Tests\Feature\Telegram;

use App\Services\Telegram\TelegramOutboundRecoveryStageRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TelegramOutboundRecoveryPostgresTimeoutTest extends TestCase
{
    public function test_a_real_statement_timeout_does_not_poison_later_recovery_stages(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL statement_timeout.');
        }

        $connection = DB::connection();
        $previousTimeout = $connection->selectOne('SHOW statement_timeout')->statement_timeout;
        $runner = new TelegramOutboundRecoveryStageRunner;
        try {
            $connection->statement('SET statement_timeout = 50');
            $results = $runner->run([
                'contended_query' => fn () => $connection->select('SELECT pg_sleep(1)'),
                'later_query' => fn (): int => (int) $connection->selectOne('SELECT 7 AS recovered')->recovered,
            ], 5);

            $this->assertSame(['later_query' => 7], $results);
            $this->assertSame(['contended_query' => QueryException::class], $runner->failures());
            $this->assertTrue($runner->failed());
        } finally {
            $connection->select("SELECT set_config('statement_timeout', ?, false)", [$previousTimeout]);
        }
    }
}
