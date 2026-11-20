# Storefront locale and shared-cache gate (#735)

`storefront_locale` is an encrypted, host-only presentation cookie. The
storefront resolves `id`, `ms`, or `en` from it, then `config('storefront.locale')`,
then `id`. Admin, Bot and API do not resolve this cookie. No session, login,
payment or Recovery Key authority comes from it. Full funnel translations are
tracked separately in #736.

## Cache policy

- Locale-aware storefront HTML, including `/` without query or cookie, and both
  `/bahasa` switch routes use `Cache-Control: private, no-store`. There is no
  `Vary: Cookie`, shared-cache TTL or surrogate TTL. Invoice, status, access and
  locale-switch responses use `Referrer-Policy: no-referrer`.
- Laravel's `StorefrontResponseCache` still caches guest-neutral base homepage
  HTML under separate resolved-locale keys. Query variants bypass that HTML
  cache. The design version was bumped; stock/maintenance invalidation and the
  existing static-artifact cleanup were retained. Invoice/access HTML and
  `Set-Cookie` responses never enter that cache.
- The checked-in `nginx/default.conf` sends `/` to PHP with its query string and
  does not look up `public/storefront/index.html`; the entrypoint already
  removes that legacy file. Static assets and non-HTML SEO endpoints are
  unaffected. This is a source check, not a qualification of deployed edge rules.

## Operator gate before cutover

On an authorized disposable/staging edge, configure **cache bypass before
lookup and delivery**, not merely before storage, for `/` (all query/cookie
variants), `/bahasa` and `/bahasa/*`, and the other locale-aware storefront
pages: `/shop` and descendants, `/produk/*`, `/pilihan/*`, `/akses-pesanan`
and descendants, storefront guide/policy routes and customer Order detail/status.
Do not override `private, no-store` or cache responses that set cookies. Purge
pre-existing shared HTML entries, including legacy cached `/` variants; ensure
no stale-cache rule or static homepage fallback can serve them. Keep bypass in
place unless cache isolation is qualified separately.

Use three isolated browser profiles (English, Melayu, no preference) and
alternate requests to the same URLs twice, first cold and then warm. Check
`html[lang]`, `Cache-Control`, edge cache status and `Age`: no shared HIT, stale
serve or cross-profile response for base/query HTML or the switch redirect. On a
disposable signed invoice, verify the POST 303 points to the original relative
URL with identical signature and expiry, and that switching still fails on an
expired invoice. Do not log signed URLs or request/cookie bodies as evidence.
Block cutover if these checks fail or the edge policy cannot be confirmed.

Local evidence: `StorefrontLocaleTest` observes cold fills, three distinct warm
Laravel HTML hits and filter bypass, tests invalid preferences/returns and
signed invoice/status authorization. `storefront-home-catalog.spec.js` exercises
isolated browser profiles and a no-JS signed invoice switch, including rejection
without CSRF. The browser fixture bypasses nginx/CDN; no production edge was
queried or changed.

Framework references: Laravel 13 [localization](https://laravel.com/docs/13.x/localization),
[signed URLs](https://laravel.com/docs/13.x/urls#signed-urls), and the installed
Laravel cookie-encryption and request-forgery middleware. Context7 CLI returned
`fetch failed`; the official docs and installed source were used instead.
