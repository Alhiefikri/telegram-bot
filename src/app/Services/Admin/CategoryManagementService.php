<?php

namespace App\Services\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CategoryManagementService
{
    /** @var list<string> */
    private const AUDITED_FIELDS = ['name', 'description', 'sort_order', 'is_active'];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function create(string $name, ?string $description, User $user, ?string $ip = null): Category
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::transaction(function () use ($name, $description, $user, $ip): Category {
                    $category = Category::create([
                        'name' => trim($name),
                        'slug' => $this->uniqueSlug($name),
                        'description' => $this->nullableTrim($description),
                        'banner_image_path' => null,
                        'sort_order' => 0,
                        'is_active' => true,
                    ]);

                    $this->auditLogger->log(
                        'category.created',
                        $category,
                        null,
                        $category->only(['name', 'slug', 'description', 'sort_order', 'is_active']),
                        $user,
                        $ip,
                    );

                    return $category;
                }, 5);
            } catch (QueryException $exception) {
                if (! $this->isSlugUniqueViolation($exception) || $attempt === 4) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Category creation retry limit reached.');
    }

    /**
     * @param  array{name: string, description: ?string, sort_order: int, is_active: bool}  $attributes
     */
    public function update(Category $category, array $attributes, User $user, ?string $ip = null): Category
    {
        return DB::transaction(function () use ($category, $attributes, $user, $ip): Category {
            $locked = Category::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();
            $oldValues = [];
            $newValues = [];

            foreach (self::AUDITED_FIELDS as $field) {
                $oldValue = $locked->getAttribute($field);
                $newValue = $attributes[$field];

                if ($oldValue === $newValue) {
                    continue;
                }

                $oldValues[$field] = $oldValue;
                $newValues[$field] = $newValue;
            }

            if ($newValues === []) {
                return $locked;
            }

            $locked->fill($attributes);
            $locked->save();

            $this->auditLogger->log(
                'category.updated',
                $locked,
                $oldValues,
                $newValues,
                $user,
                $ip,
            );

            return $locked;
        }, 5);
    }

    /**
     * @return array{category: Category, productCount: int}
     */
    public function delete(Category $category, User $user, ?string $ip = null): array
    {
        return DB::transaction(function () use ($category, $user, $ip): array {
            $locked = Category::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();
            $productCount = Product::withTrashed()
                ->where('category_id', $locked->getKey())
                ->update([
                    'category_id' => null,
                    'updated_at' => now(),
                ]);
            $snapshot = $locked->only(['id', 'name', 'slug', 'description', 'banner_image_path', 'sort_order', 'is_active']);
            $locked->delete();

            $this->auditLogger->log(
                'category.deleted',
                $locked,
                $snapshot + ['affected_product_count' => $productCount],
                null,
                $user,
                $ip,
            );

            return ['category' => $locked, 'productCount' => $productCount];
        }, 5);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug(trim($name)) ?: 'kategori';
        $slug = $base;
        $suffix = 2;

        while (Category::withTrashed()->where('slug', $slug)->exists()) {
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
            && str_contains($message, 'categories')
            && str_contains($message, 'slug');
    }
}
