# Telegram Product Image Catalog

## Status

Proposed and implemented in the Store Bot product-detail flow.

## Problem

Product details currently render as text only. Admins already can attach an optional product image, but the Store Bot does not use that image. The desired behavior is a clear product image above the familiar product information, without cropping, excessive server work, or image-specific configuration in ENV. Products without an image must retain the existing text-only behavior.

## Goals

- Show an optional Product image in the Store Bot product detail.
- Preserve the existing product name, stock, price, quantity, payment, maintenance, and navigation behavior.
- Keep the source image light and legible: validate uploads, resize only oversized images while preserving aspect ratio, and encode to WebP.
- Avoid server-side image transfer on every customer request: Telegram receives a public HTTPS image URL and performs the fetch/caching.
- Fall back to the current text message when no usable image exists.
- Keep admin image changes atomic at the Product row level and avoid stale file deletion.
- Keep input and URL handling restricted to managed product-image paths.

## Non-goals

- No image generation, CDN, background media pipeline, or new ENV setting.
- No image for product-list pages or family-option pages in this iteration.
- No cropping, face detection, watermarking, or per-customer image transformation.
- No changes to stock reservation, checkout, payment, credential delivery, or outbound-effect state machines.

## Product contract

`Product.image_path` is nullable. Admin uploads are accepted only as JPG/JPEG, PNG, or WebP files up to 5 MB and 6000x6000 pixels. The optimizer stores a UUID-named WebP under the managed `products/` directory, with a maximum dimension of 1600 pixels and quality 84. The resize operation preserves the original aspect ratio and never crops.

The existing Admin Dashboard create/edit forms remain the source of truth for the image. Empty upload means no replacement; clearing an existing image is unchanged unless a future explicit clear control is added. No catalog presentation value is read from ENV.

## Telegram behavior

1. Product detail builds the same canonical text and inline keyboard as today.
2. On a new product-detail send (`messageId` absent), when `image_path` is a valid managed product path and an absolute application URL can be produced, the bot calls Telegram `sendPhoto` with the image URL as the `photo` value and the canonical detail text as the caption.
3. The image URL is not accepted from customer input. It is derived only from the persisted managed path.
4. When there is no image, the path is invalid, the application URL is unavailable, or the caption cannot be sent as a photo caption, the bot uses the existing `sendMessage` path.
5. On an existing message edit (`messageId` present), the bot retains the existing text-edit path. Telegram cannot convert a text message into a photo message through `editMessageText`; this avoids duplicate messages on quantity and navigation callbacks.
6. The image is sent without server-side `file_get_contents` or multipart upload. Telegram fetches the public URL. Nginx/static storage serves the already optimized WebP.
7. Caption formatting remains plain text, so product/admin content cannot inject Telegram HTML or Markdown.

## Security

- Existing admin authorization and FormRequest validation protect upload endpoints.
- Uploaded bytes are decoded by GD and re-encoded; original filenames and extensions are never used in storage paths.
- UUID filenames prevent predictable collisions. Only `products/` paths are eligible for Telegram image URLs.
- URL generation must produce HTTP(S) with a non-empty host. Relative or malformed values cause the text fallback.
- Customer callback tokens, privacy/community gates, and product visibility checks remain unchanged.
- Product credentials and delivery data are never included in the image caption.

## Concurrency and consistency

Image rendering is read-only and does not participate in checkout or stock locks. Product updates already write the new path inside the admin transaction and delete the old managed file only after the update succeeds. A concurrent catalog read can observe either the old or new path; both are safe public presentation values. It cannot change stock or payment state.

A failed image send must not trigger a second blind send or alter business state. Existing Telegram transport error classification remains authoritative. Text fallback is used only for deterministic local inability to construct a usable photo request, not for ambiguous Telegram transport failures.

## Performance budget

- One bounded database Product read already supplies `image_path`.
- No per-request server image decode, resize, or multipart upload.
- Stored media is capped at 1600px and WebP quality 84.
- Telegram handles remote media retrieval and caching.
- No additional queue, database table, cache key, or persistent Telegram file ID is required.

## Verification plan

- Existing admin upload tests prove WebP output and aspect-ratio-preserving dimensions.
- Add focused Telegram catalog tests proving: managed image produces one `sendPhotoReference` URL request with the full detail caption; missing image produces `sendMessage`; existing message edits remain text edits.
- Run the focused catalog/admin tests and the PHP formatter/static checks used by this repository.
- Review the final diff for no ENV additions, no credential exposure, no stock/payment mutations, and no broad image reads.

## Rollback

Remove the Telegram photo branch and its focused tests. Existing `Product.image_path` and Admin Dashboard image uploads can remain because they are already used by the Web Storefront.
