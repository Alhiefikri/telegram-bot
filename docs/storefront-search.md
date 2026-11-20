# Storefront search contract

Issues: #172, bound storefront search cache and query cost; #734, catalog-first homepage.

## Input normalization

Public catalog search is a short browse helper for Indonesian and English product names. The canonical form is:

1. Accept only scalar `q` query-string values. Array or missing values are treated as empty search.
2. Trim leading and trailing whitespace.
3. Collapse any internal whitespace run to one ASCII space.
4. Lowercase with UTF-8 support when `mbstring` is available.
5. Reject the normalized query with HTTP 422 when it exceeds 80 characters.

The storefront search input renders `maxlength="80"`, but the server-side limit is authoritative.

## Full-page cache policy

The unfiltered homepage remains full-page cached for 30 minutes and keeps its atomic lock stampede protection. Product and category mutations still invalidate it by bumping the storefront response cache version and removing the static homepage file.

Homepage requests containing query parameters bypass both reads and writes of the HTML cache. Successful variants are canonical to `/`, marked `noindex, follow`, and return `Cache-Control: private, no-store` without CDN or surrogate cache directives. The origin nginx config sends `/` to PHP with its query string, not to a static HTML file. Any separate edge cache must forward the query and not serve a cached `/` for these variants; production edge verification is outside this change.

## Query policy

The homepage renders `GET /?family=<slug>&q=<query>` using the same bulk `CatalogBrowse` public projection as `/shop`. It filters to a public Family, then intersects the search with its Plans; a match on Family name/slug/description includes all its public Plans. Unknown and private slugs share a generic fallback without echoing the supplied slug. The Family picker is complete, including Families outside the first results page. Standalone Products participate under “Semua produk”. Plan rows paginate at 24 (`page`), without SQL per Family. Filter and search reset the page independently and retain the other filter.

The [Laravel 13 request reference](https://laravel.com/docs/13.x/requests#retrieving-input-from-the-query-string) confirms that `Request::query()` retrieves only the query bag. The query-plan fixture below is historical SQL benchmark guidance, not a description of the homepage's current in-memory projection search.

The current strategy intentionally avoids adding a PostgreSQL index before there is evidence it is needed. Because the query is a contains search over `name` and `description`, ordinary B-tree indexes are not expected to help once the active public catalog grows.

## PostgreSQL measurement threshold

A skipped-by-default PostgreSQL fixture lives in `tests/Feature/StorefrontSearchQueryPlanTest.php`. It runs only when the test connection is `pgsql` and executes:

```sql
EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
SELECT id
FROM products
WHERE deleted_at IS NULL
   AND is_active = true
   AND (LOWER(name) LIKE '%premium%' OR LOWER(description) LIKE '%premium%')
ORDER BY name, id
LIMIT 24;
```

Default fixture and budget:

- `STOREFRONT_SEARCH_PLAN_PRODUCT_COUNT=500`
- `STOREFRONT_SEARCH_PLAN_MAX_MS=75`

When this assertion fails at the target catalog size, adopt one of these measured paths instead of leaving the bounded scan in place:

1. Enable `pg_trgm` and add a GIN trigram index for `LOWER(name)` and `LOWER(description)` if substring matching must remain.
2. Move to PostgreSQL full-text search if token-based language search is acceptable.

Record the before/after `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` output in `docs/validation/` with the production-like product count before adding the migration.
