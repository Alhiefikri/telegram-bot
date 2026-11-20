# Storefront funnel localization (#736)

Scope: [#731 §5–6](https://github.com/Alhiefikri/telegram-bot/issues/731) with the checkout, invoice, catalog and locale contracts from #732–#735. The visitor locale from S4 selects Laravel storefront copy for both Blade and interactive controls. Product Details and Replacement / Refund Policy continue to use `LocalizedProductCopy` and fall back to the base variant when the selected variant is blank. Product names, prices, deadlines, support category identifiers, signed URLs, and order grants are not translated.

| Claim | Local evidence |
| --- | --- |
| `id`, `ms`, `en` have matching keys and interpolation parameters; merchant fallback, validation, automatic QRIS instructions, total and deadline agree with the saved record. | `src/tests/Feature/StorefrontLocaleTest.php` |
| Signed invoice URL and deadline survive a language change; manual QRIS never offers automatic status checks; support text carries the invoice context without the signed URL or secrets. | `src/tests/Browser/storefront-home-catalog.spec.js`, `localized funnel` matrix |
| Catalog, product, review, manual/unavailable/expired/paid invoice and order access have no document-width overflow in the three locales. | Nine passing Chromium scenarios at 320×568, 390×844 and 1366×768; screenshots in `src/test-results/issue-736-final/` |

Run from `src/`:

```sh
vendor/bin/phpunit --filter StorefrontLocaleTest
npm run build
npx playwright test tests/Browser/storefront-home-catalog.spec.js -g 'localized funnel' --output=test-results/issue-736-final --workers=1
```

The browser run uses a temporary SQLite database and fixture invoices; no real checkout or payment is submitted. It does not qualify live providers, production caches, or the #737 release. Context7 CLI lookups failed to reach the service; Laravel's [official localization documentation](https://laravel.com/docs/13.x/localization) was used as the fallback reference for short keys and named placeholders.

The broader `Storefront|ProductWaitlist` PHP run had 389 passes, four skips and one failure. `test_landing_catalog_renders_page_checkout_and_empty_stock_state` still expects the old homepage product cards and failed identically in a clean archive of pre-change `HEAD`. The checkout-review and Family quick-buy JavaScript suites passed all ten cases.
