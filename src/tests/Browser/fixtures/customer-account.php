<?php

use App\Models\CustomerAuthMailWork;
use App\Models\CustomerAuthToken;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || config('database.default') !== 'sqlite'
    || ! str_starts_with((string) config('database.connections.sqlite.database'), sys_get_temp_dir().'/customer-account-browser-')) {
    throw new RuntimeException('Account browser fixtures require disposable SQLite.');
}

$operation = $argv[1] ?? '';
if ($operation === 'prepare') {
    if (Artisan::call('migrate:fresh', ['--force' => true]) !== 0) {
        throw new RuntimeException('Account browser migrations failed.');
    }
    echo json_encode(['prepared' => true]);
} elseif ($operation === 'mail') {
    $work = CustomerAuthMailWork::query()->latest('id')->firstOrFail();
    echo json_encode($work->payload, JSON_THROW_ON_ERROR);
} elseif ($operation === 'expire') {
    DB::table('customer_auth_tokens')->where('id', CustomerAuthToken::query()->latest('id')->value('id'))
        ->update(['expires_at' => now()]);
    echo json_encode(['expired' => true]);
} elseif ($operation === 'reset-limits') {
    Cache::flush();
    echo json_encode(['reset' => true]);
} else {
    throw new RuntimeException('Unknown account browser operation.');
}
