<?php

use App\Enums\StoreBalanceEntryKind;
use App\Enums\UserRole;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Warranty\RefundProof;
use App\Services\Warranty\WarrantyClaimService;
use App\Services\Warranty\WarrantyOrderAccess;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBuyMailWarrantyFixture;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== getenv('DB_DATABASE')
    || ! str_starts_with(config('database.connections.sqlite.database'), sys_get_temp_dir().'/buymail-browser-')) {
    throw new RuntimeException('Warranty browser fixtures require a disposable SQLite test database.');
}
if (($argv[1] ?? null) === 'prepare') {
    if (Artisan::call('migrate:fresh', ['--force' => true]) !== 0) {
        throw new RuntimeException('Disposable warranty migrations failed.');
    }
    echo '{"prepared":true}';
    exit;
}
if (($argv[1] ?? null) === 'cleanup') {
    foreach (WarrantyClaim::all(['proof_path']) as $claim) {
        Storage::disk('local')->delete($claim->proof_path);
    }
    echo '{"cleaned":true}';
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
        throw new RuntimeException('Warranty browser state checks require disposable database sessions.');
    }
    $sessions = DB::table(config('session.table'))->get(['payload']);
    $sessionsContainKey = $sessions->contains(function ($session) use ($keys): bool {
        $payload = base64_decode($session->payload, true);
        if ($payload === false) {
            throw new RuntimeException('Warranty browser session payload is unreadable.');
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
Http::preventStrayRequests();
Queue::fake();
$builder = new class
{
    use BuildsBuyMailWarrantyFixture;

    public function build(): array
    {
        return $this->fixture();
    }
};
[$customer, $unit] = $builder->build();
$customer->update(['telegram_id' => 790001, 'locale' => 'en']);
$unit->order->payments()->sole()->forceFill(['provider' => 'store_balance', 'method' => 'STORE_BALANCE'])->save();
$ledger = app(StoreBalanceLedger::class);
$ledger->credit($customer, 16000, StoreBalanceEntryKind::TopUpCredit, 'browser', 'funding', 'funding');
$ledger->debit($customer, 16000, 'order', $unit->order_id, 'debit');
$proof = RefundProof::fromBytes(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII='));
$claim = app(WarrantyClaimService::class)->submit($customer, $unit->public_id, $proof, 'Wrong password');
$claim->update(['state' => 'approved', 'verified_at' => now(), 'settlement_state' => 'awaiting_choice']);
User::factory()->create(['name' => 'Warranty Browser Owner', 'email' => 'warranty-browser@example.test', 'password' => 'synthetic-browser-password', 'role' => UserRole::Owner]);
echo json_encode(['url' => app(WarrantyOrderAccess::class)->telegramUrl($unit->order, 'en'), 'invoice' => $unit->order->invoice_number], JSON_THROW_ON_ERROR);
