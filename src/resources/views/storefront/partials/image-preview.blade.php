@if ($previewImage)
    <dialog id="product-image-preview" class="product-image-dialog" aria-label="{{ __('storefront_funnel.named_image', ['name' => $previewName]) }}">
        <form method="dialog"><button type="submit" aria-label="{{ __('storefront_funnel.close_image') }}">✕</button></form>
        <img src="{{ $previewImage }}" alt="{{ $previewName }}" loading="lazy" decoding="async">
        <p>{{ $previewName }}</p>
    </dialog>
@endif
