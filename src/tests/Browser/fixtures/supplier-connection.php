<?php

use App\Enums\UserRole;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== getenv('DB_DATABASE')
    || ! str_starts_with(config('database.connections.sqlite.database'), sys_get_temp_dir().'/supplier-connection-browser-')) {
    throw new RuntimeException('Supplier connection browser fixtures require a disposable SQLite test database.');
}
if (($argv[1] ?? null) === 'prepare') {
    if (Artisan::call('migrate:fresh', ['--force' => true]) !== 0) {
        throw new RuntimeException('Disposable supplier connection migrations failed.');
    }
    echo '{"prepared":true}';
    exit;
}
if (($argv[1] ?? null) === 'state') {
    $keys = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR)['keys'];
    $suppliers = Supplier::orderBy('id')->get()->map(fn (Supplier $supplier) => [
        'id' => $supplier->id,
        'provider' => $supplier->provider,
        'key_matches' => array_map(fn (string $key) => $supplier->api_key === $key, $keys),
        'key_encrypted' => $supplier->getRawOriginal('api_key') !== $supplier->api_key,
        'key_storage_digest' => hash('sha256', $supplier->getRawOriginal('api_key')),
    ]);
    if (config('session.driver') !== 'database') {
        throw new RuntimeException('Supplier connection browser state checks require disposable database sessions.');
    }
    $sessions = DB::table(config('session.table'))->get(['payload']);
    $sessionsContainKey = $sessions->contains(function ($session) use ($keys): bool {
        $payload = base64_decode($session->payload, true);
        if ($payload === false) {
            throw new RuntimeException('Supplier connection browser session payload is unreadable.');
        }
        foreach ($keys as $key) {
            if (str_contains($payload, $key)) {
                return true;
            }
        }

        return false;
    });
    echo json_encode([
        'suppliers' => $suppliers,
        'sessions_checked' => $sessions->count(),
        'sessions_contain_key' => $sessionsContainKey,
    ], JSON_THROW_ON_ERROR);
    exit;
}
Supplier::forceCreate(['name' => 'Private source', 'provider' => 'prodseller', 'api_key' => 'synthetic-test-key', 'currency' => 'USDT', 'exchange_rate_idr' => 16000, 'currency_confirmed' => true, 'is_active' => false]);
User::factory()->create(['name' => 'Supplier Browser Owner', 'email' => 'supplier-browser@example.test', 'password' => 'synthetic-browser-password', 'role' => UserRole::Owner]);
echo json_encode(['seeded' => true], JSON_THROW_ON_ERROR);
