<?php
// Only the synthetic P03 oracle container invokes this file.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    if (config('database.connections.pgsql.database') !== 'disposable' || config('app.env') !== 'testing') {
        throw new RuntimeException('Wrong synthetic namespace');
    }
    $customer = App\Models\Customer::query()->findOrFail('9007199254740993');
    $identity = Illuminate\Support\Facades\DB::table('customer_identities')->where('customer_id', $customer->id)->sole();
    $balance = Illuminate\Support\Facades\DB::table('customer_usdt_balance_accounts')->where('customer_id', $customer->id)->sole();
    $valid = (string) $customer->telegram_id === '9007199254740993'
        && $customer->password_scheme === 'raw'
        && password_verify('p03-synthetic-password', $customer->getRawOriginal('password'))
        && $customer->created_at->format('Y-m-d H:i:sP') === '2026-10-30 08:02:03+07:00'
        && $identity->provider === 'telegram'
        && $identity->provider_subject === (string) $customer->telegram_id
        && $balance->currency === 'USDT'
        && (string) $balance->balance_micro === '0'
        && App\Models\Customer::query()->count() === 2;
    if (! $valid) {
        throw new RuntimeException('Rollback contract mismatch');
    }
    echo json_encode(['rollback_reader' => 'passed'], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, "Synthetic rollback reader refused\n");
    exit(1);
}
