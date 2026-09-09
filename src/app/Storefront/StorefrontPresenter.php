<?php

namespace App\Storefront;

use App\Models\Product;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;

class StorefrontPresenter
{
    public function __construct(
        private readonly StorefrontProductPresenter $products,
        private readonly StorefrontSupport $support,
        private readonly StorefrontUrl $urls,
        private readonly CheckoutMaintenance $maintenance,
        private readonly StorefrontSeo $seo,
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

    public function adminTelegramUsername(): string
    {
        return ltrim((string) (config('services.telegram.admin_username') ?: 'alhie02'), '@');
    }

    public function supportDestination(): string
    {
        return $this->supportHref() ?: '#support';
    }

    public function support(): StorefrontSupport
    {
        return $this->support;
    }

    public function telegramBotHref(): string
    {
        return config('storefront.links.telegram_bot_url', 'https://t.me/lhiestore_bot?start=landing');
    }

    public function adminLoginHref(): string
    {
        return route('admin.login');
    }

    public function stockAvailable(Product $product): int
    {
        return $product->availableStock();
    }

    public function idr(float|int $amount): string
    {
        return 'Rp'.number_format((int) $amount, 0, ',', '.');
    }

    public function usdt(int $microAmount): string
    {
        return rtrim(rtrim(number_format($microAmount / 1_000_000, 6, '.', ''), '0'), '.').' USDT';
    }

    public function productPresentation(Product $product, ?int $stockAvailable = null): StorefrontProductPresentation
    {
        return $this->products->present($product, $stockAvailable);
    }

    public function catalogUrl(?int $productId = null, ?string $search = null): string
    {
        return $this->urls->catalogUrl($productId, $search);
    }

    public function productUrl(Product $product, ?string $returnUrl = null): string
    {
        return $this->urls->productUrl($product, $returnUrl);
    }

    public function checkoutProductUrl(Product $product, ?string $returnUrl = null): string
    {
        return $this->urls->checkoutProductUrl($product, $returnUrl);
    }

    public function productPageViewData(Product $product, int $stock, ?string $returnUrl = null): array
    {
        $brand = $this->brand();
        $maintenance = $this->maintenance->current();
        $presentation = $this->products->present($product, $stock, maintenanceState: $maintenance);
        $returnUrl ??= $this->urls->catalogUrl((int) $product->id);
        $canonicalUrl = route('storefront.products.show', $product);
        $metadata = $this->seo->product($product, $presentation, $canonicalUrl);
        $productUrl = $this->urls->productUrl($product, $returnUrl);

        return [
            'storefront' => $this,
            'brand' => $brand,
            'copy' => config('storefront'),
            'homeUrl' => $this->urls->catalogUrl(),
            'shopUrl' => route('storefront.shop.index'),
            'pageTitle' => $metadata->title,
            'pageDescription' => $metadata->description,
            'canonicalUrl' => $metadata->canonicalUrl ?? $canonicalUrl,
            'metadata' => $metadata,
            'supportHref' => $this->supportHref(),
            'supportDestination' => $this->supportDestination(),
            'supportContext' => $this->support->prePurchase($product),
            'product' => $product,
            'presentation' => $presentation,
            'stock' => $stock,
            'checkoutMaintenance' => $maintenance,
            'checkoutMaintenanceNotice' => StorefrontCheckoutMaintenanceCopy::notice($maintenance),
            'isAvailable' => $stock > 0,
            'returnUrl' => $returnUrl,
            'productUrl' => $productUrl,
            'checkoutUrl' => $this->urls->checkoutProductUrl($product, $productUrl),
        ];
    }

    public function productCover(Product $product): array
    {
        return $this->products->present($product)->cover->theme;
    }

    public function featuredLink(Product $product): string
    {
        return $this->supportLink(
            "Halo Admin, saya tertarik dengan promo utama {$product->name} di Lhie Store."
        );
    }

    public function productLink(Product $product): string
    {
        return route('storefront.products.show', $product);
    }

    public function productSupportLink(Product $product): string
    {
        return $this->supportLink("Halo Admin @{$this->adminTelegramUsername()}, saya ingin tanya ketersediaan akun {$product->name} di Lhie Store.");
    }

    public function descriptionSnippet(Product $product): string
    {
        return $this->products->present($product)->descriptionSnippet;
    }

    private function supportLink(string $message): string
    {
        $supportHref = $this->supportHref();

        if (! $supportHref) {
            return '#support';
        }

        return $supportHref.(str_contains($supportHref, '?') ? '&' : '?').'text='.urlencode($message);
    }
}
