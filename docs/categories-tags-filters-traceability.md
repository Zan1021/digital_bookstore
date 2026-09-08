# Categories / Tags / Filters — Requirements Traceability Matrix

Source of truth: `Brief/kiro-book-categories-tags-filters-brief.md`
Generated: 2026-09-01. Status legend: ✅ implemented + evidence · 🟡 partial · ❌ missing.

This maps the brief's **Acceptance Criteria** and major sections to the code that
implements them and the automated test that proves it. Where a row is 🟡/❌ the gap is
named explicitly.

## Acceptance Criteria

| # | Criterion | Status | Evidence (code) | Evidence (test) |
|---|-----------|--------|-----------------|-----------------|
| 1 | A newly uploaded book receives classification suggestions | ✅ | `PdfService` dispatches `AnalyzeBookClassificationJob` on import → `ClassificationAnalyzer::analyze()` writes `ClassificationSuggestion` rows | `ClassificationPipelineTest::analysis job produces suggestions and description` |
| 2 | Publisher can review and edit suggestions before publishing | ✅ | `Livewire\Admin\BookReviewDetails` (route `admin.book-details` → `/admin/books/{book}/details`), prefilled from suggestions | manual route smoke 200; `BookClassificationTest` (setting primary/tag/age) |
| 3 | Every published book has primary category, language, book type | ✅ | `BookClassificationService::missingRequirements()` + `publish()` gate | `PublishGateTest::full review enables publish`, `...blocks when missing` |
| 4 | Categories & tags support translated display labels | ✅ | `Category::label()`, `Tag::translationsRel` + `TagTranslation`, `Collection::label()` | `DiscoveryTest::search matches tag alias via canonical` (label/alias resolution) |
| 5 | Tags use canonical identities with aliases + duplicate prevention | ✅ | `Tag` (canonical_name, slug, group, status), `TagAlias`, `TagResolver` (maps synonyms, queues unknown as pending) | `ClassificationPipelineTest::tag resolution matches canonical and queues unknown as pending` |
| 6 | Work-level and edition-level metadata separated | ✅ | `books` (work: book_type, age, series) vs `translations` (edition: reading_level_id, education_phase, price, publication_status) — migration `..._000007` | `DiscoveryTest` (edition-level language/reading-level/narrated filters) |
| 7 | Reading levels can differ between translated editions | ✅ | `reading_level_id` on `translations` (per edition), `ReadingLevel` | `DiscoveryTest::series progression next book` (uses per-edition reading level rank) |
| 8 | Customers can combine multiple filters | ✅ | `Store\Catalog` composes all active filters into one `BookQuery` | `StoreCatalogTest` (multiple filter tests); `DiscoveryTest::filters by language age and book type` |
| 9 | Active filters visible and removable | ✅ | `Catalog::activeChips()` + `clearFilter()` + `clearAll()`; chips in `catalog.blade.php` | `StoreCatalogTest::clear single filter`, `language filter and clear all` |
| 10 | Search includes category and tag aliases | ✅ | `BookQuery::search()` resolves tag canonical/alias/translation + category label | `DiscoveryTest::search matches tag alias via canonical` |
| 11 | Admin-only workflow metadata not exposed publicly | ✅ | `BookQuery` selects public books only; no render_status/QA/cost surfaced in `catalog.blade.php`; classification_status stays admin-side | (by construction; catalog view contains no admin fields) |
| 12 | Collections can be manually curated | ✅ | `Collection` (type=manual) + pivot `pinned`/`sort_order`; `CollectionResolver` pins first | `DiscoveryTest::manual collection pins first`; `StoreCatalogTest::v2 manual collection scopes results` |
| 13 | Rule-based collections without code change | ✅ | `Collection.rules` JSON → `CollectionResolver` translates to `BookQuery` filters | `DiscoveryTest::rule based collection resolves without code change` |
| 14 | Archived/merged categories don't break books | 🟡 | `Category` has `status`, `merged_into_id`, `effective()`, `scopeActive()`; catalogue lists only active | No dedicated test for merge-redirect on attached books — **gap: add test** |
| 15 | Filter results and counts remain accurate | ✅ | `BookQuery::facetCounts()` (zero-count omitted) | `DiscoveryTest::facet counts hide zero and count correctly` |
| 16 | Rights restrictions prevent unauthorised store visibility | ✅ | `BookQuery::base()` now applies the territory/licence/digital-rights gate directly (default territory ZA; `territory` filter overrides); `EditionRight::visibleInTerritory()` for isolated checks | `DiscoveryTest::edition rights visibility gate` + `...gate applied in live query` |

## Filter coverage (V1 “always-visible” — brief §Core Customer Filters)

| Filter | Status | Where |
|--------|--------|-------|
| Language | ✅ | `Catalog::$language` → `BookQuery` edition filter |
| Age (bands 0-3…16+) | ✅ | `Catalog::$age` + `ageBands` → `BookQuery` age between age_min/age_max |
| Reading level | ✅ | `Catalog::$reading_level` → `readingLevel.slug` |
| Book type | ✅ | `Catalog::$book_type` (faceted) |
| Category | ✅ | `Catalog::$category` (faceted, translated label) |
| Narrated / read-along | ✅ | `Catalog::$narrated` → edition features |
| Series | ✅ | `Catalog::$series` (faceted) |
| Free / paid | ✅ | `Catalog::$price` → edition price |

## Filter coverage (V2 “More Filters”)

| Filter | Status | Notes |
|--------|--------|-------|
| School / education phase | ✅ | `education_phase` per edition |
| Theme / topic tag | ✅ | tags in groups themes_and_values / topics |
| Digital feature | ✅ | `feature` slug on edition |
| Content guidance | ✅ | `advisory` → `ContentAdvisory` |
| Curriculum subject / learning goal | ❌ | no backing columns yet — **not built** |
| Mood | ❌ | no backing column — deliberately NOT faked |
| Story duration | ❌ | no backing column (no reading-minutes stored) |
| Accessibility filters | 🟡 | `Feature` can hold accessibility slugs; no dedicated accessibility group/UI section yet |
| Contributor (author/illustrator/…) | 🟡 | search covers author/illustrator; no dedicated facet |

## Homepage / store layout (brief §Homepage and Store Layout)

| Element | Status | Where |
|---------|--------|-------|
| Main search row | ✅ | `catalog.blade.php` search input (title/series/author/tag/alias) |
| Always-visible V1 filters | ✅ | catalogue sidebar |
| More Filters panel | ✅ | collapsible panel (`showMoreFilters`) |
| Sorting | 🟡 | relevant/newest/title/age implemented; popularity/rating ❌ (needs analytics/ratings data) |
| Active filter chips + Clear all | ✅ | chips + `clearAll()` |
| Curated collections | ✅ | collections strip + `CollectionResolver` |
| Faceted counts (hide zero) | ✅ | `facetCounts()` |
| Recommended nav (Browse by Age/Language/Category, Collections, Series) | 🟡 | `/browse` exists; dedicated nav landing pages ❌ |

## Known gaps / next actions
1. **Req 14:** add a test proving an archived/merged category redirects via `effective()` and
   doesn't 500 on books still attached. (Model support exists; test missing.)
2. V2 curriculum / learning-goal / mood / duration filters need backing columns first — not built (no data). Honest omission.
3. Popularity/rating sort + Browse-by nav landing pages need analytics/ratings (brief V2/analytics section).

_Resolved 2026-09-01: Req 16 rights/territory gate is now enforced inside `BookQuery::base()` (was isolated-only)._

## Test evidence summary
- `tests/Feature/DiscoveryTest.php` — 9 tests (engine: filters, facets, collections, rights gate, series).
- `tests/Feature/StoreCatalogTest.php` — 9 tests (UI wiring: V1 + V2 filters, search, chips, collection scope).
- `tests/Feature/ClassificationPipelineTest.php` — 3 tests (suggest + tag resolve + failure).
- `tests/Feature/PublishGateTest.php` + `BookClassificationTest` — publish gate + review edits.
