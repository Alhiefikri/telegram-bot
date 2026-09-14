<?php

namespace App\Services\Admin;

use App\Models\ProductFamily;
use App\Models\ProductFamilySlugRedirect;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ProductFamilySlugManager
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function rename(ProductFamily $family, string $requestedSlug, ?User $user = null, ?string $ip = null): ProductFamily
    {
        $newSlug = Str::slug(trim($requestedSlug));
        if ($newSlug === '') {
            throw new InvalidArgumentException('Family slug must contain at least one letter or number.');
        }

        try {
            return DB::transaction(function () use ($family, $newSlug, $user, $ip): ProductFamily {
                $locked = ProductFamily::withTrashed()->whereKey($family->getKey())->lockForUpdate()->firstOrFail();
                $oldSlug = (string) $locked->slug;

                if ($oldSlug === $newSlug) {
                    return $locked;
                }

                $slugOwner = ProductFamily::withTrashed()
                    ->where('slug', $newSlug)
                    ->whereKeyNot($locked->getKey())
                    ->lockForUpdate()
                    ->first();
                if ($slugOwner instanceof ProductFamily) {
                    throw new RuntimeException('Family slug is already assigned to another family.');
                }

                $aliasOwner = ProductFamilySlugRedirect::query()
                    ->where('old_slug', $newSlug)
                    ->lockForUpdate()
                    ->first();
                if ($aliasOwner instanceof ProductFamilySlugRedirect && (int) $aliasOwner->product_family_id !== (int) $locked->getKey()) {
                    throw new RuntimeException('Family slug is already reserved as another family alias.');
                }

                $existingOldAlias = ProductFamilySlugRedirect::query()
                    ->where('old_slug', $oldSlug)
                    ->lockForUpdate()
                    ->first();
                if ($existingOldAlias instanceof ProductFamilySlugRedirect && (int) $existingOldAlias->product_family_id !== (int) $locked->getKey()) {
                    throw new RuntimeException('Current family slug is reserved by another family alias.');
                }

                $locked->forceFill(['slug' => $newSlug])->save();

                if ($aliasOwner instanceof ProductFamilySlugRedirect) {
                    $aliasOwner->delete();
                }

                ProductFamilySlugRedirect::query()->updateOrCreate(
                    ['old_slug' => $oldSlug],
                    ['product_family_id' => $locked->getKey()],
                );

                $this->auditLogger->log(
                    'product_family.slug_changed',
                    $locked,
                    ['slug' => $oldSlug],
                    ['slug' => $newSlug, 'old_slug_alias' => $oldSlug],
                    $user,
                    $ip,
                );

                return $locked;
            }, 5);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw new RuntimeException('Family slug is already assigned to another family.', 0, $exception);
            }

            throw $exception;
        }
    }
}
