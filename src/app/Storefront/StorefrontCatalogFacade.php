<?php

namespace App\Storefront;

final class StorefrontCatalogFacade
{
    public function __construct(
        private readonly StorefrontSupport $support,
        private readonly StorefrontUrl $urls,
    ) {}

    public function brand(): string
    {
        return 'Lhie Store';
    }

    public function supportHref(): ?string
    {
        $telegramUsername = $this->adminTelegramUsername();

        return config('services.telegram.admin_contact_url')
            ?: ($telegramUsername ? 'https://t.me/'.$telegramUsername : null);
    }

    public function supportDestination(): string
    {
        return $this->supportHref() ?: '#support';
    }

    public function idr(float|int $amount): string
    {
        return 'Rp'.number_format((int) $amount, 0, ',', '.');
    }

    public function usdt(int $microAmount): string
    {
        return rtrim(rtrim(number_format($microAmount / 1_000_000, 6, '.', ''), '0'), '.').' USDT';
    }

    public function catalogUrl(?int $productId = null, ?string $search = null): string
    {
        return $this->urls->catalogUrl($productId, $search);
    }

    public function productUrl(int $productId, ?string $returnUrl = null): string
    {
        return $this->urls->productUrlForId($productId, $returnUrl);
    }

    public function checkoutProductUrl(int $productId, ?string $returnUrl = null): string
    {
        return $this->urls->checkoutProductUrlForId($productId, $returnUrl);
    }

    public function familyUrl(string $slug, ?string $returnUrl = null): string
    {
        return $this->urls->familyUrl($slug, $returnUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function generalSupportContext(): array
    {
        return $this->support->general();
    }

    private function adminTelegramUsername(): string
    {
        return ltrim((string) (config('services.telegram.admin_username') ?: 'alhie02'), '@');
    }
}
