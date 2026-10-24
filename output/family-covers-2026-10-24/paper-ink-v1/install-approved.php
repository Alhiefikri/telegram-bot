<?php
// One-time, approved image-only operation. Execute through PHP stdin; no deployed source edits.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ProductFamily;
use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\User;
use App\Services\Admin\ProductImageOptimizer;
use App\Services\Admin\ProductFamilyManagementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

$expected = [
    16 => ['GPT API', 'gpt-api', '9ca2fdb0128d8004d52cdfdcc1ff31eaaf886c9edc7128c917328a76da2c7d7c'],
    17 => ['GPT + Claude API', 'gpt-claude-api', '82344c1ef2efd779efcb44aea11711513ec254092e8a716074c5eb0eb44032d8'],
    18 => ['China Models API', 'china-models-api', '35a9c5190d0642d41cf5acdcdc5b7c9adc489ba01613918cd8e03bb73a761276'],
];
$auditDir = storage_path('app/private/family-cover-review-20261024-paper-ink-v1');
if (is_file($auditDir.'/before.json')) {
    throw new RuntimeException('Existing installation snapshot found; refusing repeated mutation.');
}
if (! is_dir($auditDir) && ! mkdir($auditDir, 0700, true)) {
    throw new RuntimeException('Cannot create rollback directory.');
}
$save = function (string $name, array $data) use ($auditDir): void {
    if (file_put_contents($auditDir.'/'.$name, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Cannot save audit artifact.');
    }
    chmod($auditDir.'/'.$name, 0600);
};
$actor = User::query()->whereKey(1)->where('role', 'owner')->firstOrFail(['id', 'name', 'role']);
$images = app(ProductImageOptimizer::class);
if ($images->capabilityFailure() !== null) {
    throw new RuntimeException($images->capabilityFailure());
}
foreach ($expected as [$name, $slug, $hash]) {
    $file = '/tmp/family-cover-review-20261024-paper-ink-v1/'.$slug.'.png';
    if (! hash_equals($hash, hash_file('sha256', $file))) {
        throw new RuntimeException('Master hash mismatch: '.$slug);
    }
}
$cacheVersions = fn (): array => [
    'storefront' => Cache::get('storefront.response.version'),
    'telegram_catalog' => Cache::get('telegram.catalog.projection.version'),
];
$fingerprint = fn (mixed $data): string => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
$productColumns = ['id', 'category_id', 'product_family_id', 'family_sort_order', 'family_option_label', 'name', 'description', 'description_ms', 'description_en', 'price_amount', 'usdt_price_amount', 'reseller_price_amount', 'reseller_usdt_price_amount', 'currency', 'stock', 'reserved_stock', 'is_active', 'image_path', 'telegram_image_path', 'telegram_channel_image_path'];
$sourceColumns = ['id', 'product_id', 'supplier_catalog_item_id', 'priority', 'is_active', 'version'];
$cacheBefore = $cacheVersions();
$result = DB::transaction(function () use ($expected, $actor, $images, $save, $fingerprint, $productColumns, $sourceColumns, $cacheBefore): array {
    $families = ProductFamily::query()->whereIn('id', array_keys($expected))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    if ($families->count() !== 3) {
        throw new RuntimeException('Expected exactly three families.');
    }
    foreach ($expected as $id => [$name, $slug]) {
        $family = $families[$id];
        if ($family->name !== $name || $family->slug !== $slug || $family->image_path !== null || $family->telegram_image_path !== null) {
            throw new RuntimeException('Family identity or previous image changed: '.$id);
        }
    }
    $members = Product::withTrashed()->whereIn('product_family_id', array_keys($expected))->orderBy('id')->lockForUpdate()->get($productColumns);
    if ($members->pluck('id')->all() !== [36,37,38,39,44,45,46,47,48,49,50]) {
        throw new RuntimeException('Membership differs from approved scope.');
    }
    $sources = ProductSupplierSource::whereIn('product_id', $members->pluck('id'))->orderBy('id')->lockForUpdate()->get($sourceColumns)->toArray();
    $otherFamilies = ProductFamily::withTrashed()->whereNotIn('id', array_keys($expected))->orderBy('id')->get()->toArray();
    $before = [
        'timestamp' => now()->toIso8601String(), 'actor_id' => $actor->id,
        'families' => $families->values()->toArray(), 'members' => $members->toArray(),
        'supplier_mapping_sha256' => $fingerprint($sources),
        'other_families_sha256' => $fingerprint($otherFamilies), 'cache_versions' => $cacheBefore,
    ];
    $save('before.json', $before);
    $new = [];
    foreach ($expected as $id => [$name, $slug]) {
        $upload = new UploadedFile('/tmp/family-cover-review-20261024-paper-ink-v1/'.$slug.'.png', $slug.'.png', 'image/png', null, true);
        $paths = ['image_path' => $images->storeFamily($upload), 'telegram_image_path' => $images->storeTelegramFamily($upload)];
        $new[$id] = $paths;
        $save('new-paths.json', $new);
        app(ProductFamilyManagementService::class)->update($families[$id], $paths, $actor);
        $after = ProductFamily::findOrFail($id)->getAttributes();
        $old = $families[$id]->getAttributes();
        foreach (['image_path','telegram_image_path','updated_at'] as $field) {
            unset($old[$field], $after[$field]);
        }
        if ($old !== $after) {
            throw new RuntimeException('Unexpected non-image family mutation.');
        }
    }
    if ($fingerprint($members->toArray()) !== $fingerprint(Product::withTrashed()->whereIn('id', $members->pluck('id'))->orderBy('id')->get($productColumns)->toArray())) {
        throw new RuntimeException('Product fields changed.');
    }
    if ($fingerprint($sources) !== $fingerprint(ProductSupplierSource::whereIn('product_id', $members->pluck('id'))->orderBy('id')->get($sourceColumns)->toArray())) {
        throw new RuntimeException('Supplier mappings changed.');
    }
    if ($fingerprint($otherFamilies) !== $fingerprint(ProductFamily::withTrashed()->whereNotIn('id', array_keys($expected))->orderBy('id')->get()->toArray())) {
        throw new RuntimeException('Other family changed.');
    }
    return ['new_paths' => $new, 'unchanged_members' => $members->pluck('id')->all(), 'supplier_mapping_unchanged' => true, 'other_families_unchanged' => true];
});
$result['cache_before'] = $cacheBefore;
$result['cache_after'] = $cacheVersions();
$result['timestamp'] = now()->toIso8601String();
$result['rollback_snapshot'] = $auditDir.'/before.json';
$save('after.json', $result);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
