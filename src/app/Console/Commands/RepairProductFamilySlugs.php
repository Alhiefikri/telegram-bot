<?php

namespace App\Console\Commands;

use App\Models\ProductFamily;
use App\Services\Admin\ProductFamilySlugManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

final class RepairProductFamilySlugs extends Command
{
    protected $signature = 'storefront:repair-family-slugs {--apply : Apply the audited repair instead of reporting only}';

    protected $description = 'Audit and optionally repair public Product Family slugs using family names.';

    public function handle(ProductFamilySlugManager $slugs): int
    {
        $netflix = $this->familyNamed('netflix');
        if ($netflix === null) {
            $this->warn('No unambiguous family named Netflix was found; no repair applied.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Netflix candidate: id=%s name=%s slug=%s', $netflix->getKey(), $netflix->name, $netflix->slug));

        $geminiSlugOwner = ProductFamily::withTrashed()->where('slug', 'gemini')->first();
        if ($geminiSlugOwner instanceof ProductFamily && (int) $geminiSlugOwner->getKey() !== (int) $netflix->getKey()) {
            throw new RuntimeException(sprintf(
                'Cannot repair Netflix to slug netflix while gemini belongs to family id=%s (%s). Inspect that family first.',
                $geminiSlugOwner->getKey(),
                $geminiSlugOwner->name,
            ));
        }

        if ((string) $netflix->slug === 'netflix') {
            $this->info('Netflix already has canonical slug netflix; audit is clean.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->comment(sprintf('Dry run: rename slug %s to netflix and retain %s as a durable alias.', $netflix->slug, $netflix->slug));

            return self::SUCCESS;
        }

        $oldSlug = (string) $netflix->slug;
        $updated = $slugs->rename($netflix, 'netflix');
        $this->info(sprintf('Repaired family id=%s: %s -> %s; durable alias retained.', $updated->getKey(), $oldSlug, $updated->slug));

        return self::SUCCESS;
    }

    private function familyNamed(string $normalizedName): ?ProductFamily
    {
        $matches = ProductFamily::withTrashed()->get()->filter(
            static fn (ProductFamily $family): bool => Str::slug((string) $family->name) === $normalizedName,
        );

        if ($matches->count() > 1) {
            throw new RuntimeException(sprintf('Ambiguous family name %s; inspect candidates before repair.', $normalizedName));
        }

        return $matches->first();
    }
}
