<?php

namespace App\Console\Commands;

use App\Models\ProductFamily;
use App\Models\ProductFamilySlugRedirect;
use App\Services\Admin\ProductFamilySlugManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class RepairProductFamilySlugs extends Command
{
    protected $signature = 'storefront:repair-family-slugs {--apply : Apply the audited repair instead of reporting only}';

    protected $description = 'Audit and optionally repair public Product Family slugs using family names.';

    public function handle(ProductFamilySlugManager $slugs): int
    {
        $this->reportNameSlugMismatches();

        $netflix = $this->familyNamed('netflix');
        if ($netflix === null) {
            $this->warn('No unambiguous family named Netflix was found; no repair applied.');

            return self::SUCCESS;
        }

        $gemini = $this->familyNamedLike('gemini');
        $geminiSlugOwner = ProductFamily::withTrashed()->where('slug', 'gemini')->first();
        $geminiRepairSlug = $this->geminiRepairSlug($netflix, $gemini, $geminiSlugOwner);
        $netflixNeedsRepair = (string) $netflix->slug !== 'netflix';

        $this->line(sprintf('Netflix candidate: name=%s slug=%s', $netflix->name, $netflix->slug));
        if ($gemini instanceof ProductFamily) {
            $this->line(sprintf('Gemini candidate: name=%s slug=%s', $gemini->name, $gemini->slug));
        }

        if (! $netflixNeedsRepair && $geminiRepairSlug === null) {
            $this->info('Netflix already has canonical slug netflix; audit is clean.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            if ($geminiRepairSlug !== null) {
                $this->comment(sprintf(
                    'Dry run: rename Gemini slug %s to %s before repairing the Netflix mapping.',
                    $gemini?->slug,
                    $geminiRepairSlug,
                ));
            }
            if ($netflixNeedsRepair) {
                $this->comment(sprintf(
                    'Dry run: rename Netflix slug %s to netflix and retain %s as a durable alias.',
                    $netflix->slug,
                    $netflix->slug,
                ));
            }

            return self::SUCCESS;
        }

        $oldNetflixSlug = (string) $netflix->slug;
        [$updatedGemini, $updatedNetflix] = DB::transaction(function () use (
            $gemini,
            $geminiRepairSlug,
            $netflix,
            $netflixNeedsRepair,
            $slugs,
        ): array {
            $updatedGemini = $gemini;
            if ($gemini instanceof ProductFamily && $geminiRepairSlug !== null) {
                $updatedGemini = $slugs->rename($gemini, $geminiRepairSlug);
            }

            $updatedNetflix = $netflix;
            if ($netflixNeedsRepair) {
                $updatedNetflix = $slugs->rename($netflix, 'netflix');
            }

            return [$updatedGemini, $updatedNetflix];
        }, 5);

        if ($updatedGemini instanceof ProductFamily && $geminiRepairSlug !== null) {
            $this->info(sprintf(
                'Repaired Gemini name=%s: %s -> %s; durable alias retained.',
                $updatedGemini->name,
                $gemini?->slug,
                $updatedGemini->slug,
            ));
        }
        if ($netflixNeedsRepair) {
            $this->info(sprintf(
                'Repaired Netflix name=%s: %s -> %s; durable alias retained.',
                $updatedNetflix->name,
                $oldNetflixSlug,
                $updatedNetflix->slug,
            ));
        }

        return self::SUCCESS;
    }

    private function familyNamed(string $normalizedName): ?ProductFamily
    {
        $matches = ProductFamily::query()->get()->filter(
            static fn (ProductFamily $family): bool => Str::slug((string) $family->name) === $normalizedName,
        );

        if ($matches->count() > 1) {
            throw new RuntimeException(sprintf('Ambiguous family name %s; inspect candidates before repair.', $normalizedName));
        }

        return $matches->first();
    }

    private function familyNamedLike(string $normalizedPrefix): ?ProductFamily
    {
        $matches = ProductFamily::query()->get()->filter(
            static function (ProductFamily $family) use ($normalizedPrefix): bool {
                $normalizedName = Str::slug((string) $family->name);

                return $normalizedName === $normalizedPrefix
                    || str_starts_with($normalizedName, $normalizedPrefix.'-');
            },
        );

        if ($matches->count() > 1) {
            throw new RuntimeException(sprintf('Ambiguous family name %s; inspect candidates before repair.', $normalizedPrefix));
        }

        return $matches->first();
    }

    private function geminiRepairSlug(
        ProductFamily $netflix,
        ?ProductFamily $gemini,
        ?ProductFamily $geminiSlugOwner,
    ): ?string {
        if (! $geminiSlugOwner instanceof ProductFamily || (int) $geminiSlugOwner->getKey() === (int) $netflix->getKey()) {
            return null;
        }

        if (! $gemini instanceof ProductFamily || (int) $gemini->getKey() !== (int) $geminiSlugOwner->getKey()) {
            throw new RuntimeException(sprintf(
                'Cannot repair Netflix while slug gemini belongs to unrelated family %s; inspect the current Gemini record first.',
                $geminiSlugOwner->name,
            ));
        }

        return $this->uniqueSlugFor($gemini);
    }

    private function uniqueSlugFor(ProductFamily $family): string
    {
        $base = Str::slug((string) $family->name) ?: 'product-family';
        if ($base === (string) $family->slug) {
            $base .= '-ai';
        }

        $slug = $base;
        $suffix = 2;
        while (
            ProductFamily::withTrashed()
                ->where('slug', $slug)
                ->whereKeyNot($family->getKey())
                ->exists()
            || ProductFamilySlugRedirect::query()
                ->where('old_slug', $slug)
                ->where('product_family_id', '!=', $family->getKey())
                ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function reportNameSlugMismatches(): void
    {
        foreach (ProductFamily::query()->orderBy('name')->orderBy('id')->get() as $family) {
            $normalizedName = Str::slug((string) $family->name);
            if ($normalizedName === '' || $normalizedName === (string) $family->slug) {
                continue;
            }

            $this->line(sprintf(
                'Name/slug mismatch: name=%s slug=%s normalized_name=%s',
                $family->name,
                $family->slug,
                $normalizedName,
            ));
        }
    }
}
