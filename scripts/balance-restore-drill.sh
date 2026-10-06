#!/usr/bin/env bash
# Disposable PostgreSQL restore drill for the Customer USDT balance subsystem.
#
# Runs the #558 post-restore procedure end to end against a throwaway
# PostgreSQL 16 container: migrate, seed money + a pending balance delivery,
# dump, destroy, restore, then replay external evidence, reconcile and reopen.
# It proves that a restore never deletes ledger/receipt/claim history, never
# changes a balance and never blindly resends an ambiguous delivery.
#
# Usage: scripts/balance-restore-drill.sh
# Env:   CONCURRENCY_POSTGRES_IMAGE, CONCURRENCY_POSTGRES_PORT, DRILL_KEEP=1
set -Eeuo pipefail
umask 077

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_DIR="$ROOT_DIR/src"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"

POSTGRES_IMAGE="${CONCURRENCY_POSTGRES_IMAGE:-postgres:16-alpine}"
POSTGRES_DB="${DRILL_POSTGRES_DB:-telegram_bot_restore_drill}"
POSTGRES_USER="${DRILL_POSTGRES_USER:-postgres}"
POSTGRES_PASSWORD="${DRILL_POSTGRES_PASSWORD:-postgres}"
POSTGRES_PORT="${CONCURRENCY_POSTGRES_PORT:-55433}"
WAIT_SECONDS="${CONCURRENCY_POSTGRES_WAIT_SECONDS:-90}"
CONTAINER_NAME="${DRILL_POSTGRES_CONTAINER:-telegram-bot-restore-drill-${RUN_ID}}"
KEEP="${DRILL_KEEP:-0}"
SEED_CUSTODY_MICRO=3000000

WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/balance-restore-drill.XXXXXX")"
DUMP_FILE="$WORK_DIR/drill.dump.sql"

fail() {
    printf 'Restore drill failed: %s\n' "$1" >&2
    exit 1
}

cleanup() {
    local status=$?
    if [[ "$KEEP" != "1" ]]; then
        docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
        rm -rf "$WORK_DIR"
    else
        printf 'Keeping container %s and work dir %s for inspection.\n' "$CONTAINER_NAME" "$WORK_DIR" >&2
    fi
    exit "$status"
}
trap cleanup EXIT

command -v docker >/dev/null 2>&1 || fail 'Docker CLI is required.'
docker info >/dev/null 2>&1 || fail 'Docker daemon is unavailable.'
command -v psql >/dev/null 2>&1 || fail 'psql is required.'
[[ "$POSTGRES_DB" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ ]] || fail 'DRILL_POSTGRES_DB must be a safe identifier.'

export PGPASSWORD="$POSTGRES_PASSWORD"
psql_admin() { psql -h 127.0.0.1 -p "$POSTGRES_PORT" -U "$POSTGRES_USER" -d "$1" -v ON_ERROR_STOP=1 -tAc "$2"; }

# --- 1. disposable PostgreSQL -------------------------------------------------
docker container inspect "$CONTAINER_NAME" >/dev/null 2>&1 && fail "container already exists: $CONTAINER_NAME"
docker run -d --name "$CONTAINER_NAME" \
    -e POSTGRES_PASSWORD="$POSTGRES_PASSWORD" \
    -e POSTGRES_USER="$POSTGRES_USER" \
    -e POSTGRES_DB="$POSTGRES_DB" \
    -p "127.0.0.1:${POSTGRES_PORT}:5432" \
    "$POSTGRES_IMAGE" >/dev/null

ready=0
for _ in $(seq 1 "$WAIT_SECONDS"); do
    if pg_isready -h 127.0.0.1 -p "$POSTGRES_PORT" -U "$POSTGRES_USER" -d "$POSTGRES_DB" >/dev/null 2>&1; then
        ready=1
        break
    fi
    sleep 1
done
[[ "$ready" = "1" ]] || fail 'disposable PostgreSQL did not become ready in time.'

# --- 2. migrate + seed -------------------------------------------------------
export DB_CONNECTION=pgsql
export DB_HOST=127.0.0.1
export DB_PORT="$POSTGRES_PORT"
export DB_DATABASE="$POSTGRES_DB"
export DB_USERNAME="$POSTGRES_USER"
export DB_PASSWORD="$POSTGRES_PASSWORD"
export QUEUE_CONNECTION=database
export CACHE_STORE=array
export SESSION_DRIVER=array
export DRILL_APP_DIR="$APP_DIR"

cd "$APP_DIR"
php artisan migrate --force --no-interaction >"$WORK_DIR/migrate.log" 2>&1 \
    || { tail -30 "$WORK_DIR/migrate.log" >&2; fail 'migrations did not run on the disposable database.'; }

cat >"$WORK_DIR/seed.php" <<'PHP'
<?php
require getenv('DRILL_APP_DIR').'/vendor/autoload.php';
$app = require getenv('DRILL_APP_DIR').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$customer = App\Models\Customer::factory()->create([
    'telegram_id' => '990000001',
    'first_name' => 'Restore Drill',
]);
app(App\Services\UsdtBalance\CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
    $customer,
    new App\Services\UsdtBalance\VerifiedUsdtFunding(
        (int) $customer->id,
        App\Services\UsdtBalance\UsdtMicroAmount::fromUnits(3_000_000),
        'drill-top-up',
        'drill-receipt',
        'v1',
    ),
    'usdt:drill-top-up',
);
App\Models\ExternalMoneyReceipt::query()->create([
    'canonical_key' => 'bsc:56:drill-tx:0',
    'provider' => 'bsc',
    'identity_kind' => 'bsc_log',
    'account_namespace' => 'operator',
    'reference' => 'drill-tx',
    'chain_id' => 56,
    'tx_hash' => '0x'.str_repeat('d', 64),
    'log_index' => 0,
    'beneficiary' => 'customer:'.$customer->id,
    'currency' => 'USDT',
    'amount_units' => '3000000000000000000',
    'decimals' => 18,
    'status' => 'claimed',
    'target_type' => 'customer_usdt_top_up',
    'target_id' => 'drill-top-up',
    'fingerprint' => str_repeat('e', 64),
    'claimed_at' => now(),
]);
App\Models\TelegramOutboundEffect::query()->create([
    'effect_key' => 'drill-usdt-receipt',
    'effect_group_key' => 'drill-usdt-receipt',
    'sequence' => 1,
    'effect_type' => App\Enums\TelegramOutboundEffectType::UsdtTopUpReceipt->value,
    'source_type' => App\Models\Customer::class,
    'source_id' => 'drill-top-up',
    'target_type' => 'customer',
    'telegram_chat_id' => '990000001',
    'queue' => 'notifications',
    'telegram_method' => 'sendMessage',
    'locale' => 'id',
    'template_version' => 'usdt-top-up-receipt-v1',
    'state' => App\Enums\TelegramOutboundEffectState::Pending->value,
    'attempts' => 0,
    'next_attempt_at' => now(),
    'outcome_metadata' => ['message' => 'drill'],
]);
echo json_encode(['seeded' => true]), PHP_EOL;
PHP

php "$WORK_DIR/seed.php" >"$WORK_DIR/seed.log" 2>&1 \
    || { tail -30 "$WORK_DIR/seed.log" >&2; fail 'seeding the disposable database failed.'; }
grep -q '"seeded":true' "$WORK_DIR/seed.log" \
    || { tail -30 "$WORK_DIR/seed.log" >&2; fail 'the seed script did not confirm its work.'; }

counts_sql="SELECT (SELECT count(*) FROM customer_usdt_balance_entries) || ',' ||
       (SELECT count(*) FROM external_money_receipts) || ',' ||
       (SELECT count(*) FROM usdt_receiver_address_claims) || ',' ||
       (SELECT coalesce(sum(balance_micro), 0) FROM customer_usdt_balance_accounts) || ',' ||
       (SELECT count(*) FROM telegram_outbound_effects)"
before_counts="$(psql_admin "$POSTGRES_DB" "$counts_sql")"
[[ "$before_counts" != "0,0,0,0,0" ]] || fail 'the drill database is empty; the seed did not run against it.'
[[ "$(psql_admin "$POSTGRES_DB" "SELECT count(*) FROM telegram_outbound_effects WHERE state = 'pending'")" = "1" ]] \
    || fail 'expected exactly one pending balance delivery before the dump.'

# --- 3. dump, destroy, restore ----------------------------------------------
# Dump and restore with the server's own client so the drill never mixes
# client/server major versions.
docker exec "$CONTAINER_NAME" pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
    --no-owner --no-privileges >"$DUMP_FILE"
[[ -s "$DUMP_FILE" ]] || fail 'pg_dump produced an empty file.'

psql_admin postgres "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$POSTGRES_DB'" >/dev/null
psql_admin postgres "DROP DATABASE $POSTGRES_DB" >/dev/null
psql_admin postgres "CREATE DATABASE $POSTGRES_DB" >/dev/null
docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -q -v ON_ERROR_STOP=1 <"$DUMP_FILE" >"$WORK_DIR/restore.log" 2>&1 \
    || { tail -20 "$WORK_DIR/restore.log" >&2; fail 'restoring the dump failed.'; }

after_counts="$(psql_admin "$POSTGRES_DB" "$counts_sql")"
[[ "$before_counts" = "$after_counts" ]] || fail "restored database does not match the dump (before=$before_counts after=$after_counts)."

# --- 4. gate, replay, reconcile, reopen -------------------------------------
WINDOW_START="$(date -u -d '-30 minutes' +%Y-%m-%dT%H:%M:%S+00:00)"
WINDOW_END="$(date -u +%Y-%m-%dT%H:%M:%S+00:00)"

php artisan usdt-balance:restore-open --window-start="$WINDOW_START" --window-end="$WINDOW_END" \
    --reason="disposable restore drill" --actor="drill" --json >"$WORK_DIR/open.json" 2>&1 \
    || { cat "$WORK_DIR/open.json" >&2; fail 'restore-open failed.'; }

php artisan usdt-balance:restore-replay --limit=25 --actor="drill" --json >"$WORK_DIR/replay.json" 2>&1 \
    || { cat "$WORK_DIR/replay.json" >&2; fail 'restore-replay reported a failed inspection.'; }

# Reconciliation reads treasury coverage from the registered custody adapters.
# The drill declares a fixture observation for exactly the seeded liability so
# the *procedure* can complete; the fixture is test evidence, not a custody
# qualification (H1 #539 remains unresolved).
cat >"$WORK_DIR/reconcile.php" <<'PHP'
<?php
require getenv('DRILL_APP_DIR').'/vendor/autoload.php';
$app = require getenv('DRILL_APP_DIR').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$micro = (int) getenv('DRILL_CUSTODY_MICRO');
$provider = new class($micro) implements App\Services\UsdtTreasury\UsdtTreasuryAssetProvider
{
    public function __construct(private readonly int $micro) {}

    public function providerKey(): string
    {
        return 'drill_custody';
    }

    public function observe(): App\Services\UsdtTreasury\UsdtTreasuryAssetObservation
    {
        return App\Services\UsdtTreasury\UsdtTreasuryAssetObservation::available(
            'drill_custody',
            Brick\Math\BigInteger::of((string) $this->micro),
            new DateTimeImmutable(),
        );
    }
};
$app->instance('drill.treasury.provider', $provider);
$app->tag(['drill.treasury.provider'], 'usdt-treasury-asset-providers');
$app->forgetInstance(App\Services\UsdtTreasury\UsdtTreasuryAssetRegistry::class);

$code = Illuminate\Support\Facades\Artisan::call('usdt-balance:reconcile', [
    '--limit' => 200,
    '--actor' => 'drill',
    '--json' => true,
]);
$output = Illuminate\Support\Facades\Artisan::output();
file_put_contents(getenv('DRILL_RECONCILE_OUT'), $output);
fwrite(STDOUT, $output);
exit($code);
PHP

DRILL_CUSTODY_MICRO="$SEED_CUSTODY_MICRO" DRILL_RECONCILE_OUT="$WORK_DIR/reconcile.json" \
    php "$WORK_DIR/reconcile.php" >"$WORK_DIR/reconcile.log" 2>&1 \
    || { cat "$WORK_DIR/reconcile.log" >&2; fail 'post-replay reconciliation reported drift.'; }

php artisan usdt-balance:restore-close --actor="drill" --json >"$WORK_DIR/close.json" 2>&1 \
    || { cat "$WORK_DIR/close.json" >&2; fail 'restore-close refused to reopen the gate.'; }

quarantined="$(psql_admin "$POSTGRES_DB" "SELECT count(*) FROM telegram_outbound_effects WHERE state = 'unknown' AND last_error_code = 'restore_ambiguous_delivery'")"
gate_state="$(psql_admin "$POSTGRES_DB" "SELECT state FROM balance_restore_gates WHERE id = 1")"
final_counts="$(psql_admin "$POSTGRES_DB" "$counts_sql")"
reconciliation_runs="$(psql_admin "$POSTGRES_DB" "SELECT count(*) FROM usdt_balance_reconciliation_runs")"
ledger_sum="$(psql_admin "$POSTGRES_DB" "SELECT coalesce(sum(CASE WHEN kind IN ('funding_credit','refund_credit') THEN amount_micro ELSE -amount_micro END), 0) FROM customer_usdt_balance_entries")"

[[ "$quarantined" = "1" ]] || fail "expected exactly one quarantined balance delivery, found $quarantined."
[[ "$gate_state" = "cleared" ]] || fail "gate did not reopen (state=$gate_state)."
[[ "$final_counts" = "$before_counts" ]] || fail "money history changed across the drill (before=$before_counts after=$final_counts)."
[[ "$ledger_sum" = "$SEED_CUSTODY_MICRO" ]] || fail "restored ledger liability changed (expected $SEED_CUSTODY_MICRO, found $ledger_sum)."

DRILL_COUNTS="$final_counts" DRILL_QUARANTINED="$quarantined" DRILL_GATE="$gate_state" \
DRILL_RUNS="$reconciliation_runs" DRILL_REPLAY="$WORK_DIR/replay.json" \
DRILL_CLOSE="$WORK_DIR/close.json" DRILL_RECONCILE="$WORK_DIR/reconcile.json" php -r '
$report = [
    "drill" => "balance-restore",
    "restored_rows" => getenv("DRILL_COUNTS"),
    "quarantined_deliveries" => (int) getenv("DRILL_QUARANTINED"),
    "gate_state" => getenv("DRILL_GATE"),
    "reconciliation_runs" => (int) getenv("DRILL_RUNS"),
    "replay" => json_decode((string) file_get_contents(getenv("DRILL_REPLAY")), true),
    "reconciliation_verdict" => json_decode((string) file_get_contents(getenv("DRILL_RECONCILE")), true)["verdict"] ?? null,
    "close" => json_decode((string) file_get_contents(getenv("DRILL_CLOSE")), true),
    "money_unchanged" => true,
];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
'

printf 'Restore drill completed: ledger/receipt/claim history and balances survived the restore unchanged.\n'
