@if ($previewImage)
    <dialog id="product-image-preview" class="product-image-dialog" aria-label="Gambar {{ $previewName }}">
        <form method="dialog"><button type="submit" aria-label="Tutup gambar">✕</button></form>
        <img src="{{ $previewImage }}" alt="{{ $previewName }}" loading="lazy" decoding="async">
        <p>{{ $previewName }}</p>
    </dialog>
@endif
