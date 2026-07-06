<?php

namespace App\Storefront;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;

class StorefrontPresenter
{
    public function __construct(private readonly StorefrontProductPresenter $products) {}

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

    public function productPresentation(Product $product, ?int $stockAvailable = null): StorefrontProductPresentation
    {
        return $this->products->present($product, $stockAvailable);
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    public function categoriesWithProducts(Collection $categories, Collection $products): Collection
    {
        return $categories->filter(fn (Category $category) => $products->contains('category_id', $category->id));
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    public function popularProducts(Collection $products): Collection
    {
        return $products->filter(fn (Product $product) => $this->stockAvailable($product) > 0)->take(3);
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    public function homeViewData(Collection $categories, Collection $products): array
    {
        $copy = config('storefront');
        $brand = $this->brand();

        return [
            'storefront' => $this,
            'brand' => $brand,
            'copy' => $copy,
            'pageTitle' => str_replace(':brand', $brand, $copy['meta']['title']),
            'pageDescription' => $copy['meta']['description'],
            'canonicalUrl' => url('/'),
            'supportHref' => $this->supportHref(),
            'supportDestination' => $this->supportDestination(),
            'categoriesWithProducts' => $this->categoriesWithProducts($categories, $products),
            'catalogProducts' => $products,
            'popularProducts' => $this->popularProducts($products),
        ];
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    public function shopViewData(Collection $categories, Collection $products, string $search): array
    {
        $copy = config('storefront');
        $brand = $this->brand();

        return [
            'storefront' => $this,
            'brand' => $brand,
            'copy' => $copy,
            'pageTitle' => 'Belanja Akun AI | Lhie Store',
            'pageDescription' => 'Marketplace Lhie Store untuk membeli akun AI premium langsung dari web dengan QRIS Pakasir atau lewat Telegram.',
            'canonicalUrl' => route('storefront.shop.index'),
            'homeUrl' => route('storefront.home'),
            'shopUrl' => route('storefront.shop.index'),
            'supportHref' => $this->supportHref(),
            'supportDestination' => $this->supportDestination(),
            'categoriesWithProducts' => $this->categoriesWithProducts($categories, $products),
            'catalogProducts' => $products,
            'popularProducts' => $this->popularProducts($products),
            'searchAction' => route('storefront.shop.index').'#products',
            'checkoutUrl' => route('storefront.shop.checkout'),
        ];
    }

    public function productPageViewData(Product $product, int $stock): array
    {
        $brand = $this->brand();

        return [
            'storefront' => $this,
            'brand' => $brand,
            'copy' => config('storefront'),
            'homeUrl' => route('storefront.home'),
            'pageTitle' => $product->name.' | '.$brand,
            'pageDescription' => $this->descriptionSnippet($product),
            'canonicalUrl' => route('storefront.products.show', $product),
            'supportHref' => $this->supportHref(),
            'supportDestination' => $this->supportDestination(),
            'product' => $product,
            'stock' => $stock,
            'isAvailable' => $stock > 0,
        ];
    }

    public function paymentPageViewData(Product $product, int $quantity, int $subtotal): array
    {
        $brand = $this->brand();

        return [
            'storefront' => $this,
            'brand' => $brand,
            'copy' => config('storefront'),
            'homeUrl' => route('storefront.home'),
            'pageTitle' => 'Checkout via Telegram Admin | '.$brand,
            'pageDescription' => 'Lanjutkan checkout produk '.$product->name.' melalui Telegram admin agar order, payment, stok, dan receipt tercatat resmi.',
            'canonicalUrl' => route('storefront.products.payment', $product),
            'supportHref' => $this->supportHref(),
            'supportDestination' => $this->supportDestination(),
            'adminUsername' => $this->adminTelegramUsername(),
            'checkoutSupportLink' => $this->checkoutSupportLink($product, $quantity, $subtotal),
            'product' => $product,
            'subtotal' => $subtotal,
            'quantity' => $quantity,
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

    public function checkoutSupportLink(Product $product, int $quantity, int $subtotal): string
    {
        return $this->supportLink("Halo Admin @{$this->adminTelegramUsername()}, saya ingin checkout {$product->name} x{$quantity} via Telegram/Admin. Estimasi produk {$this->idr($subtotal)}. Saya belum transfer; mohon dibuatkan order dan instruksi pembayaran resmi.");
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
