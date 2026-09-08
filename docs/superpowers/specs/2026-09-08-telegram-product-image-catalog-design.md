# Separate Storefront and Telegram Product Media

## Status

Implemented. Storefront and Telegram presentation now have independent optional media fields.

## Decision

Use separate nullable paths with explicit fallback precedence:

- Storefront: existing `products.image_path` and `product_families.image_path` remain unchanged.
- Telegram product override: `products.telegram_image_path`.
- Telegram family default: `product_families.telegram_image_path`.
- Telegram precedence: Product override, then Product Family default, then existing text-only detail.

This supports a landscape Telegram poster without changing the square Storefront card. Product-level media wins when a family contains products requiring different posters. Family-level media avoids duplicate uploads when all options share one poster.

## Goals

- Keep Storefront images and Telegram images independent.
- Allow a Telegram image per Product or a default per Product Family.
- Preserve existing product name, stock, price, quantity, payment, maintenance, and navigation behavior.
- Keep images light and legible: validate uploads, preserve aspect ratio, do not crop, and encode to WebP.
- Avoid server-side image transfer on every customer request: Telegram receives a public URL and fetches/caches the optimized image.
- Fall back to the existing text message when no usable Telegram image exists.
- Keep admin image changes atomic and clean old managed files only after successful persistence.

## Non-goals

- No image generation, CDN, background media pipeline, or new ENV setting.
- No changes to Storefront layout or square image behavior.
- No image for product-list or family-option messages in this iteration; the image is for Product detail.
- No cropping, watermarking, or per-customer transformation.
- No changes to stock reservation, checkout, payment, credential delivery, or outbound-effect state machines.

## Upload and storage contract

Both Telegram fields are nullable. Admin uploads use the existing authorization and request validation: JPG/JPEG, PNG, or WebP, maximum 5 MB and 6000x6000 pixels. `ProductImageOptimizer` decodes and re-encodes uploads to UUID-named WebP, maximum dimension 1600 pixels, quality 84, while preserving the original aspect ratio.

Storefront paths remain under `products/` and `product-families/`. Telegram paths use separate managed directories:

- `telegram-products/`
- `telegram-product-families/`

Original filenames and extensions are never used as storage paths. Empty upload on edit means keep the current image. Existing Storefront image fields are never overwritten by Telegram uploads, and vice versa.

## Admin behavior

- Product form: **Gambar produk** controls Storefront media; **Gambar Telegram** controls the Product Telegram override.
- Product Family form: **Gambar family** controls Storefront media; **Gambar Telegram family** controls the family Telegram default.
- The Telegram product preview uses `object-contain` so a landscape poster is visibly not cropped.
- The Telegram family image applies only to Product details whose Product-level Telegram image is empty.
- Admin role authorization remains unchanged. File replacement is persisted in the existing Product/Product Family transaction and old files are deleted only after commit.
- No catalog presentation value is read from ENV.

## Telegram behavior

1. Product detail builds the same canonical text and inline keyboard as before.
2. On a new detail send (`messageId` absent), resolve the Telegram image path as `Product.telegram_image_path ?? ProductFamily.telegram_image_path`.
3. If the resolved path is a valid existing managed Telegram path and produces an absolute HTTP(S) URL, call Telegram `sendPhoto` with that URL and the canonical detail text as caption.
4. If no path is configured, the file is missing, the path is outside the Telegram directories, the URL is malformed, or the caption exceeds Telegram's 1024-character photo-caption limit, use the existing `sendMessage` path.
5. On an existing message edit (`messageId` present), retain the existing text-edit path. Telegram cannot convert a text message into a photo through `editMessageText`; this avoids duplicate messages during quantity/navigation callbacks.
6. The server never reads and uploads the image during Telegram delivery. Telegram fetches the already optimized WebP URL.
7. Caption remains plain text; product/admin content cannot inject Telegram HTML or Markdown.

## Security

- Upload endpoints remain protected by admin authorization and FormRequest validation.
- GD decoding and WebP re-encoding reject undecodable input.
- UUID storage names prevent filename traversal and predictable collisions.
- Telegram URL generation accepts only existing paths under the two Telegram directories and rejects traversal segments.
- URL generation requires HTTP(S) and a non-empty host.
- Customer callback tokens, privacy/community gates, product visibility checks, and checkout authorization remain unchanged.
- Product credentials and delivery data are never included in the image or caption.

## Concurrency and consistency

Media rendering is read-only and does not participate in stock or checkout locks. Admin updates lock the Product/Product Family row using the existing mutation service. A concurrent read may observe the old or new image path, both of which are safe presentation values. A new replacement file is deleted if the transaction fails; an old file is deleted only after a successful replacement.

Image selection does not mutate inventory, payment, order, or outbound-effect state. An ambiguous Telegram transport failure is not converted into a second blind send. Text fallback is used only for deterministic local inability to construct a valid photo request.

## Performance budget

- No new database query for product-level media.
- One relationship load may read the Product Family when family fallback is needed.
- No per-delivery image decode, resize, or multipart upload.
- Stored Telegram media is capped at 1600px and WebP quality 84.
- Telegram handles remote retrieval and caching.
- No Telegram file-ID table or additional queue is required.

## Verification plan

- Existing image tests continue to prove Storefront WebP output and aspect-ratio preservation.
- Focused tests prove separate Product Telegram upload, Telegram Product override delivery, Product Family fallback delivery, and text fallback when empty.
- Run focused Telegram catalog/admin tests and PHP formatter checks.
- Review that Storefront image paths and Telegram paths cannot overwrite one another, no ENV setting was added, credentials are not exposed, and stock/payment code is unchanged.

## Rollback

Remove the Telegram-specific fields and photo branch if necessary. Existing Storefront image fields, uploads, and rendering remain independent and can continue operating.
