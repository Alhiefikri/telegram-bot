@php
    use App\Storefront\StorefrontPresenter;

    /** @var StorefrontPresenter $storefront */
    $storefront = app(StorefrontPresenter::class);
    $brand = $storefront->brand();
    $copy = config('storefront');
    $pageTitle = str_replace(':brand', $brand, $copy['meta']['title']);
    $pageDescription = $copy['meta']['description'];
    $canonicalUrl = url('/');
    $supportHref = $storefront->supportHref();
    $supportDestination = $storefront->supportDestination();
    $categoriesWithProducts = $storefront->categoriesWithProducts($categories, $products);
    $catalogProducts = $products;
    $popularProducts = $storefront->popularProducts($products);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head')
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)] motion-reduce:animate-none">
    <div class="storefront-shell relative min-h-screen z-10 flex flex-col justify-between overflow-x-hidden" x-data="storefrontModal()">
        @include('storefront.partials.header')

        <main class="flex-grow pb-32">
            @include('storefront.partials.hero')
            @include('storefront.partials.catalog')
            @include('storefront.partials.featured-offer')
            @include('storefront.partials.benefits')
            @include('storefront.partials.purchase-flow')
            @include('storefront.partials.testimonial')
            @include('storefront.partials.metrics')
            @include('storefront.partials.faq')
            @include('storefront.partials.support')
        </main>

        @include('storefront.partials.footer')
        @include('storefront.partials.product-modal')
    </div>

    @include('storefront.partials.floating-support')

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('storefrontModal', () => ({
                productModalOpen: false,
                selectedProduct: null,
                quantity: 1,
                notes: '',
                openProductModal(product) {
                    this.selectedProduct = product;
                    this.quantity = 1;
                    this.notes = '';
                    this.productModalOpen = true;
                    document.body.style.overflow = 'hidden';
                },
                closeProductModal() {
                    this.productModalOpen = false;
                    setTimeout(() => { this.selectedProduct = null; }, 300);
                    document.body.style.overflow = '';
                },
                checkoutManual() {
                    if(!this.selectedProduct || !this.selectedProduct.isAvailable) return;
                    const url = new URL(this.selectedProduct.paymentUrl || `/produk/${this.selectedProduct.id}/bayar`, window.location.origin);
                    url.searchParams.set('qty', Math.max(1, Math.min(parseInt(this.quantity, 10) || 1, parseInt(this.selectedProduct.stock, 10) || 1)));
                    window.location.href = url.toString();
                },
                decreaseQuantity() {
                    this.quantity = Math.max(1, this.quantity - 1);
                },
                increaseQuantity() {
                    if(!this.selectedProduct) return;
                    this.quantity = Math.min(parseInt(this.selectedProduct.stock, 10) || 1, this.quantity + 1);
                },
                selectedTotal() {
                    if(!this.selectedProduct) return 0;
                    return (parseInt(this.selectedProduct.price_amount, 10) || 0) * (parseInt(this.quantity, 10) || 1);
                },
                formatIdr(amount) {
                    return 'Rp' + parseInt(amount).toLocaleString('id-ID');
                }
            }));
        });
    </script>
</body>
</html>
