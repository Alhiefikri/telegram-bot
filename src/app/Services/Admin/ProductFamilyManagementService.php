<?php

namespace App\Services\Admin;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductFamilySlugRedirect;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProductFamilyManagementService
{
    /** @var list<string> */
    private const FAMILY_FIELDS = ['name', 'description', 'icon', 'image_path', 'telegram_image_path', 'sort_order'];

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ProductFamilyCategoryCoherence $coherence,
        private readonly ProductFamilySlugManager $slugs,
    ) {}

    /**
     * @param  array{name: string, description: ?string, icon?: ?string, sort_order: int, slug?: ?string, image_path?: ?string, telegram_image_path?: ?string}  $attributes
     */
    public function create(array $attributes, User $user, ?string $ip = null): ProductFamily
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes, $user, $ip): ProductFamily {
                    $family = ProductFamily::create([
                        'name' => trim($attributes['name']),
                        'slug' => $this->uniqueSlug($attributes['name']),
                        'description' => $this->nullableTrim($attributes['description'] ?? null),
                        'icon' => $this->nullableTrim($attributes['icon'] ?? null),
                        'image_path' => $attributes['image_path'] ?? null,
                        'telegram_image_path' => $attributes['telegram_image_path'] ?? null,
                    ]);

                    $this->auditLogger->log(
                        'product_family.created',
                        $family,
                        null,
                        $family->only(array_merge(self::FAMILY_FIELDS, ['slug'])),
                        $user,
                        $ip,
                    );

                    return $family;
                }, 5);
            } catch (QueryException $exception) {
                if (! $this->isSlugUniqueViolation($exception) || $attempt === 4) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Product Family creation retry limit reached.');
    }

    /**
     * @param  array{name: string, description: ?string, icon?: ?string, sort_order: int, slug?: ?string, image_path?: ?string, telegram_image_path?: ?string}  $attributes
     */
    public function update(ProductFamily $family, array $attributes, User $user, ?string $ip = null): ProductFamily
    {
        return DB::transaction(function () use ($family, $attributes, $user, $ip): ProductFamily {
            $locked = ProductFamily::query()->whereKey($family->getKey())->lockForUpdate()->firstOrFail();
            $requestedSlug = array_key_exists('slug', $attributes) && is_string($attributes['slug'])
                ? trim($attributes['slug'])
                : null;
            $slugChanged = $requestedSlug !== null && Str::slug($requestedSlug) !== (string) $locked->slug;
            $oldValues = [];
            $newValues = [];

            foreach (self::FAMILY_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }

                $oldValue = $locked->getAttribute($field);
                $rawValue = $attributes[$field];
                $newValue = match ($field) {
                    'name' => trim((string) $rawValue),
                    'description', 'icon' => $this->nullableTrim(is_string($rawValue) ? $rawValue : null),
                    default => $rawValue,
                };

                if ($oldValue === $newValue) {
                    continue;
                }

                $oldValues[$field] = $oldValue;
                $newValues[$field] = $newValue;
            }

            if ($newValues === [] && ! $slugChanged) {
                return $locked;
            }

            $locked->fill($newValues);
            $locked->save();

            $this->auditLogger->log(
                'product_family.updated',
                $locked,
                $oldValues,
                $newValues,
                $user,
                $ip,
            );
            if ($slugChanged) {
                $locked = $this->slugs->rename($locked, $requestedSlug, $user, $ip);
            }

            return $locked;
        }, 5);
    }

    /**
     * @return array{family: ProductFamily, productCount: int, imagePath: ?string}
     */
    public function delete(ProductFamily $family, User $user, ?string $ip = null): array
    {
        $result = DB::transaction(function () use ($family, $user, $ip): array {
            $locked = ProductFamily::query()->whereKey($family->getKey())->lockForUpdate()->firstOrFail();
            $productIds = Product::withTrashed()
                ->where('product_family_id', $locked->getKey())
                ->orderBy('id')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            foreach ($productIds as $productId) {
                Product::withTrashed()->whereKey($productId)->lockForUpdate()->first();
            }

            $productCount = Product::withTrashed()
                ->where('product_family_id', $locked->getKey())
                ->update([
                    'product_family_id' => null,
                    'family_sort_order' => 0,
                    'family_option_label' => null,
                    'updated_at' => now(),
                ]);
            $snapshot = $locked->only(['id', 'name', 'slug', 'description', 'image_path', 'telegram_image_path', 'sort_order']);
            $imagePath = $locked->image_path;
            $telegramImagePath = $locked->telegram_image_path;
            $locked->delete();

            $this->auditLogger->log(
                'product_family.deleted',
                $locked,
                $snapshot + ['affected_product_count' => $productCount],
                null,
                $user,
                $ip,
            );

            return ['family' => $locked, 'productCount' => $productCount, 'imagePath' => $imagePath, 'telegramImagePath' => $telegramImagePath];
        }, 5);

        return $result;
    }

    public function updateMembership(Product $product, ?int $familyId, int $sortOrder, ?string $optionLabel, User $user, ?string $ip = null, ?string $expectedVersion = null): Product
    {
        return DB::transaction(function () use ($product, $familyId, $sortOrder, $optionLabel, $user, $ip, $expectedVersion): Product {
            $lockedState = $this->coherence->lockForMembership($product, $familyId);
            $lockedProduct = $lockedState['product'];
            if ($expectedVersion !== null) {
                abort_unless(hash_equals($this->coherence->membershipVersion($lockedProduct), $expectedVersion), 409, 'Grouping ini telah berubah. Muat ulang sebelum menyimpan.');
            }
            $targetFamily = $lockedState['family'];
            $oldValues = [
                'product_family_id' => $lockedProduct->product_family_id,
                'family_sort_order' => (int) $lockedProduct->family_sort_order,
                'family_option_label' => $lockedProduct->family_option_label,
            ];
            $newValues = [
                'product_family_id' => $targetFamily?->getKey(),
                'family_sort_order' => $targetFamily instanceof ProductFamily ? max(0, $sortOrder) : 0,
                'family_option_label' => $targetFamily instanceof ProductFamily ? $this->nullableTrim($optionLabel) : null,
            ];

            if ($oldValues === $newValues) {
                return $lockedProduct;
            }

            $lockedProduct->fill($newValues);
            $lockedProduct->save();

            $this->auditLogger->log(
                $this->membershipAction($oldValues['product_family_id'], $newValues['product_family_id']),
                $lockedProduct,
                $oldValues,
                $newValues,
                $user,
                $ip,
            );

            return $lockedProduct;
        }, 5);
    }

    private function membershipAction(mixed $oldFamilyId, mixed $newFamilyId): string
    {
        return match (true) {
            $oldFamilyId === null && $newFamilyId !== null => 'product.family_assigned',
            $oldFamilyId !== null && $newFamilyId === null => 'product.family_unassigned',
            (int) $oldFamilyId !== (int) $newFamilyId => 'product.family_moved',
            default => 'product.family_membership_updated',
        };
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug(trim($name)) ?: 'product-family';
        $slug = $base;
        $suffix = 2;

        while (
            ProductFamily::withTrashed()->where('slug', $slug)->exists()
            || ProductFamilySlugRedirect::query()->where('old_slug', $slug)->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function isSlugUniqueViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return in_array((string) $exception->getCode(), ['19', '23000', '23505'], true)
            && str_contains($message, 'product_famil')
            && str_contains($message, 'slug');
    }
}
