<?php

use App\Models\Customer;
use App\Models\CustomerAuthMailWork;
use App\Models\CustomerAuthToken;
use App\Services\Customer\CustomerAuthAvailability;
use App\Services\Customer\CustomerTelegramAuthenticationService;
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
} elseif ($operation === 'providers-on' || $operation === 'providers-off') {
    $flag = dirname((string) config('database.connections.sqlite.database')).'/providers-configured';
    if ($operation === 'providers-on') {
        file_put_contents($flag, 'configured');
    } elseif (is_file($flag)) {
        unlink($flag);
    }
    echo json_encode(['configured' => $operation === 'providers-on']);
} elseif ($operation === 'rollback' || $operation === 'enable') {
    app(CustomerAuthAvailability::class)->setEnabled($operation === 'enable');
    echo json_encode(['enabled' => $operation === 'enable']);
} elseif ($operation === 'telegram-only') {
    Customer::factory()->create(['telegram_id' => 7654322, 'email' => null, 'email_verified_at' => null]);
    echo json_encode(['prepared' => true]);
} elseif ($operation === 'telegram-confirm') {
    config(['services.telegram.token' => 'disposable-browser-bot', 'storefront.links.telegram_bot_url' => 'https://t.me/disposable_auth_bot']);
    $proof = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    $service = app(CustomerTelegramAuthenticationService::class);
    $actor = $proof['actor'] ?? '7654321';
    $confirmation = $service->prepare($proof['capability'], $actor, $actor, 'private');
    echo json_encode(['confirmed' => $confirmation !== null && $service->confirm($confirmation, $actor, $actor, 'private')]);
} else {
    throw new RuntimeException('Unknown account browser operation.');
}
