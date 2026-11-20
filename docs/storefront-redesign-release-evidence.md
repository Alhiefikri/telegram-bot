# Storefront redesign release evidence (#737)

Scope: [#731 §6](https://github.com/Alhiefikri/telegram-bot/issues/731), integrating #732 (review), #733 (invoice), #734 (catalog), #735 (visitor locale), and #736 (funnel copy). S6 fixes the English desktop Family reset label, changes the Laravel homepage HTML cache namespace, and removes the unused pre-redesign home partial. `/shop` still owns its separate catalog. Shared theme tokens, payment and fulfillment rules, and invoice URLs are unchanged.

## Disposable evidence

| Boundary | Evidence |
| --- | --- |
| Guest checkout, payment and access | `release guest` in `src/tests/Browser/storefront-home-catalog.spec.js` uses real browser submissions, a temporary SQLite database, and synthetic KlikQRIS create/status responses. It checks the displayed provider total vs. the product price, paid access, signed reopening, Recovery Key, cart invoice reopening after the saved deadline, and manual pending instructions with no automatic status control. WhatsApp link content is inspected, not sent. |
| Locale and cache | Browser tests cover separate visitors and no-JS locale switches on catalog and signed invoice. `StorefrontLocaleTest` covers the configured default and `id` fallback, invalid preference/return and signature rejection, cold/warm app-cache isolation, and query bypass. This does not qualify nginx/CDN. |
| Stock, URLs and alternative rails | Focused PHP filter `Storefront\|ProductWaitlist\|Bsc.*Checkout`: **428 passed, 5 skipped, 7,059 assertions**. Includes challenge admission, guest cart and stock conflicts, canonical and legacy URL contracts, waitlist, Binance/BSC, invoice and access. The skips are not PostgreSQL concurrency or deployed-platform evidence. |
| Client controls and assets | **26 JavaScript tests passed** across checkout review, Family quick buy, storefront components and mobile menu. Local Vite build and targeted Pint passed. |

### Scroll and contrast

The candidate Chromium suite passed **27 scenarios**, including eight layout scenarios. The same eight layout measurements were also recorded at baseline `4c18f42c` (parent of S1) using identical synthetic catalog, review and native QRIS invoice fixtures. Values are CSS pixels needed to bring the bottom of the target into the initial viewport; 0 means fully visible on load. The old mobile review used a sticky action; the new action is in normal document flow.

| Viewport / text | First catalog row | Review action | Invoice QR / total / deadline |
| --- | ---: | ---: | ---: |
| 1366×768 / 100% | 767–773 → 0 | 0 → 0 | 0 / 0 / 0 → 0 / 0 / 0 |
| 390×844 / 100% | 66 → 0 | 2–18 → 0 | 0 / 526 / 569 → 0 / 0 / 0 |
| 320×568 / 100% | 460 → 0 | 0–18 → 142 | 219 / 936 / 991 → 175 / 0 / 238 |
| 320×568 / 200% | 1609 → 1043 | 0–26 → 1497 | 1244 / 2929 / 3104 → 975 / 736 / 1184 |

No document-width overflow in the candidate layout matrix. The 200% check enlarges the root font size in Chromium; it is not OS/browser text scaling, a full accessibility audit, or proof of no clipping for all merchant content. Each matrix case writes three viewport screenshots and `geometry.json`; the candidate also captures keyboard focus. Local artifacts: `/tmp/opencode/issue-737-before/src/test-results/issue-737-before/` and `/tmp/opencode/issue-737/src/test-results/issue-737-final/`. Do not publish invoice query strings, Recovery Keys, or credential values with evidence.

Computed light/dark contrast ratios at 1366×768 (sRGB solid-color pairs):

| Foreground / background | Before | After |
| --- | ---: | ---: |
| Catalog buy label / button fill | 5.17 / 2.54 | 5.17 / 7.36 |
| Search placeholder / input fill | 4.68 / 7.99 | 4.68 / 7.99 |
| Active Family text / active row fill | — | 12.87 / 10.72 |
| Search focus outline / surrounding background | — | 4.85 / 7.36 |
| Review action text / button fill | 5.17 / 5.17 | 6.70 / 10.38 |
| Invoice total text / panel fill | 5.17 / 6.59 | 15.70 / 15.62 |
| Invoice deadline text / panel fill | 15.70 / 15.62 | 15.70 / 15.62 |
| Invoice instruction / panel fill¹ | 5.65 / 6.92 | 15.70 / 15.62 |
| Invoice status text / badge fill² | — | 6.84 / 9.55 |

¹ Baseline uses the after-payment helper; the candidate uses the dedicated QR instruction. ² Only the candidate badge is measured here. The matrix verifies keyboard movement from Apply back to Search and forward again, with a visible ≥3:1 Search outline in both themes. Review error/focus evidence from #732 was 7.11/10.62 and 6.70/9.29 respectively; those pairings were not remeasured in S6. Synthetic QR rendering does not qualify a live QRIS payload or physical scanner; no live provider, support delivery, or Telegram integration is claimed.

### Reproduce locally

From `src/` with installed dependencies, Chromium, and a disposable test `APP_KEY` for PHPUnit:

```sh
npm run build
php vendor/phpunit/phpunit/phpunit --filter 'Storefront|ProductWaitlist|Bsc.*Checkout'
node --test tests/JavaScript/{checkout-review,family-quick-buy,storefront-components,mobile-menu}.test.js
npx playwright test tests/Browser/storefront-home-catalog.spec.js --output=test-results/issue-737-final --workers=1
```

For the baseline, use a separate worktree at `4c18f42c`, copy only the candidate spec and test router into its `src/tests/Browser/`, build that worktree’s assets, and run the same Playwright command with `-g 'release layout'` and a different output directory. Context7 CLI returned `fetch failed`; Laravel 13’s [HTTP testing docs](https://laravel.com/docs/13.x/http-client#preventing-stray-requests) were used to confirm the fake/deny-stray-request fixture pattern.

## Operator gate, cutover and rollback

1. Obtain operator approval. Before sending traffic to the candidate, apply the [locale cache gate](runbook-storefront-locale.md#operator-gate-before-cutover): bypass shared lookup/serve (including stale fallback) and purge old storefront HTML. Verify on an authorized edge with separate language profiles; stop if isolation cannot be established.
2. Stage exactly one release’s application code, Blade views, Vite manifest and hashed assets together. Make new hashed assets available before serving HTML that references them; retain the previous asset set during cutover/rollback. Verify legacy static index artifacts are absent using the existing entrypoint behavior. Switch code and manifest together, with compiled views from that same release.
3. Check base/filtered catalog and cart, a disposable signed invoice created with the pre-cutover version, status, and authorized access. Verify locale/no-store and asset responses without retaining sensitive URLs. Keep app key, signing configuration, customer data, Order state and payment rail settings unchanged.
4. Roll back by restoring the previous matching code/manifest/assets and compiled views together. Keep the shared HTML bypass and purge storefront HTML at the edge again. Use the existing scoped `StorefrontResponseCache::invalidate()` path if the restored namespace contains stale HTML; it advances only the homepage HTML cache version and removes legacy static index artifacts. Do **not** flush a shared cache, restore stale HTML, roll back migrations, or change Orders, Payments, stock, queues or operator approvals. Recheck old invoices, assets and locale isolation under the restored release.

No GitHub Actions, deployment, production changes or real transactions were performed. This evidence is not authorization to release.
