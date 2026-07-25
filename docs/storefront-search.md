# Storefront search contract

Issue: #172, bound storefront search cache and query cost.

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

Search requests are deliberately not stored in the full-page response cache. Equivalent query strings normalize to the same query behavior, but each search result page renders live from the capped catalog query. This prevents arbitrary public values from creating an unbounded set of large HTML cache entries.

## Query policy

Search uses Laravel 13's database-agnostic case-insensitive `whereLike` / `orWhereLike` query builder clauses, verified against the current Laravel 13 query builder documentation. The product set remains bounded to active, public products, preserves storefront ordering, and returns at most 24 products.

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
