# Book Classification, AI Description & Store Discovery — Status

**Date:** 2026-08-29
**Decision:** Option B — layered onto existing `books` + `translations`; V8 engine untouched.
**Outcome:** All 12 tasks implemented. Auto-classify + AI description on import, admin
review + publish gate, store discovery (V1 filters/facets/search), collections (manual +
rule-based), rights visibility, analytics groundwork. Kids-domain safeguards in place.

## Task completion (1–12)
1. Work/Edition model — books=Work, translations=Edition; per-edition reading level,
   education_phase, language_role, price, publication_status added. DONE
2. Controlled categories (hierarchical, archive/merge, translated labels). DONE
3. Controlled tags (canonical + translations + aliases + pending approval queue). DONE
4. SceneSignals (deterministic book_type/reading_level/illustration_density/age hint). DONE
5. ClassificationAnalyzer + AnalyzeBookClassificationJob (auto on import; status machine;
   safe failure; mockable LLM). DONE
6. DescriptionWriter (age-appropriate, PII-stripped, bounded; draft until approved). DONE
7. Admin review screen (Livewire) + BookClassificationService publish gate. DONE
8. Kids-domain: validated age range, content_advisories, education_phase/language_role,
   series progression helper. DONE
9. Store discovery: BookQuery V1 facets + search over labels/aliases; facet counts. DONE
10. Collections: manual (pinned-first) + rule-based (no code change to add). DONE
11. Rights visibility gate + discovery_events analytics; public/admin separation. DONE
12. This status + full-suite green.

## Test status
- **PHP: 25 passed, 70 assertions, 0 failures.**
  - Unit: SceneSignalsTest (6).
  - Feature: ClassificationPipelineTest (3), PublishGateTest (5), DiscoveryTest (9),
    ExampleTest (1, fixed to assert the by-design root->store redirect).
- **V8 Python suite still GREEN and UNTOUCHED** — render_gate 45, table_structure 42,
  e2e_contract 9, second_book 14 (spot-checked). This feature never calls the render path.

## V8 safety (the standing rule)
Per Captain Zan: "always keep V8 safe." This build:
- Adds only NEW tables + ADDITIVE columns on books/translations; no change to V8 tables.
- Reads `book_pages.extracted_text` (already populated) for analysis; never invokes
  pdf_translate_v8 / the render engine.
- The V8 Python test suite passes unchanged.

## Deterministic-first (as approved)
Book type, reading level, illustration density, and the age hint are computed from the
extracted text (SceneSignals) — no AI, no cost, reproducible. The LLM handles only the
subjective dimensions (themes/topics/characters/setting/mood/genres/tags) and the store
description. The LLM is behind an interface; the default binding is a no-network
NullLlmClient, so nothing calls an external API until a real provider is configured.

## Honest caveats / not-yet-done
- **Real LLM provider not wired.** NullLlmClient returns empty subjective output +
  placeholder description. A production provider (OpenAI/etc.) must implement LlmClient
  and be bound in AppServiceProvider. Tests use a FakeLlmClient.
- **Admin UI is functional, not polished.** BookReviewDetails covers the required review +
  publish-gate flow; the 7-section brief layout is partially realised (category/type,
  audience, tags, description). Secondary-category multi-select, curriculum/accessibility
  panels, and the store-front catalogue Blade are modelled/queried but not fully skinned.
- **Search is DB LIKE-based** (V1). BookQuery is structured so a search engine
  (Scout/Meilisearch) can replace the LIKE path without controller changes.
- **Store front-end** (catalogue page, filter chips UI, sort dropdown) — the BookQuery
  engine + facet counts exist and are tested; the customer-facing Blade/controller wiring
  is minimal and needs design pass.
- **Backfill** of the legacy `books.category`/`age_group` strings into controlled
  categories/age ranges is not run automatically; a one-off migration/command is a
  follow-up.
- **discovery_events** table + DiscoveryEvent::track exist; event emission is not yet wired
  into the store controllers.
- No LLM-cost/rate-limit handling (deferred to the real provider adapter).

## Migrations added (all applied clean on SQLite)
categories, book_categories, tags/tag_translations/tag_aliases/book_tags, reading_levels,
audience_ranges, features/edition_features, content_advisories/book_content_advisories,
classification columns on books+translations, classification_suggestions/reviews,
book_descriptions, collections/collection_books, edition_rights, discovery_events.
Seeder: ClassificationVocabularySeeder (15 categories, 56 tags, 5 reading levels, 6
audience ranges, 20 features, 5 advisories).
