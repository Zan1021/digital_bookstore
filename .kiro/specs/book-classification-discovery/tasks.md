# Implementation Plan — Book Classification, AI Description & Store Discovery

Build order: data model → vocab governance → analysis pipeline (reusing V8 scene) → AI
description → review UI + publish gate → store discovery (V1) → kids-domain fields →
collections → rights → analytics → V1 polish. Ship the V1 cut; model V2 fields but keep hidden.

- [x] 1. Work / Edition data model (Req 1)
  - Migrations: book_works, book_editions, series, contributors, book_contributors.
  - Move shared metadata to Work; language/format metadata + reading_level_id to Edition.
  - Eloquent models + relationships; reading level is per-edition.
  - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5_

- [x] 2. Controlled category vocabulary (Req 2)
  - Migrations: categories (parent_id, group, status, sort_order, merged_into_id,
    translations), book_categories pivot (is_primary, source, confidence, approved_*).
  - Admin CRUD: create/rename/reorder/translate/hide/archive; archive/merge instead of
    delete; assigned category never breaks on archive/merge.
  - Seed the recommended top-level catalogue (Children's Fiction … Reference) with hierarchy.
  - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5_

- [x] 3. Controlled tag vocabulary + governance (Req 3)
  - Migrations: tags, tag_translations, tag_aliases, book_tags pivot.
  - TagResolver: map synonyms/aliases → one canonical tag; translation ≠ new identity;
    AI suggestions enter PENDING approval queue; admin merge (merged_into_id) + pin;
    archived tags stay attached but unselectable.
  - Seed the controlled tag groups (themes_and_values, topics, educational, cultural, experience).
  - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6_

- [x] 4. V8 scene signals (deterministic classification inputs) (Req 4.2/4.4)
  - App\Services\Classification\SceneSignals over the existing DocumentScene:
    book_type_signal, reading_level_signal, illustration_density, age_band_hint.
  - No second PDF parse — consume build_document_scene output.
  - Unit tests on fixture scenes (both Kolulu books; book-agnostic).
  - _Requirements: 4.2, 4.4_

- [x] 5. Classification analysis pipeline (auto on import) (Req 4)
  - classification_suggestions + classification_reviews migrations.
  - ClassificationAnalyzer: deterministic pass (SceneSignals) + LLM pass (themes/topics/
    characters/setting/mood/genres/tags) behind a swappable LLM client interface.
  - AnalyzeBookClassificationJob dispatched automatically on import; status
    PENDING→ANALYSING→SUGGESTED, FAILED→retriable; failure never blocks manual entry.
  - Sensitive fields (age, content advisory) flagged requires_confirmation; never auto-published.
  - Every suggestion carries confidence + source + engine_version.
  - _Requirements: 4.1, 4.3, 4.5, 4.6_

- [x] 6. AI store description writer (Req 5)
  - book_descriptions migration (short_text, long_text, status, source, language_code,
    edition_id nullable).
  - DescriptionWriter: age-appropriate tone, no spoilers, no PII, length-bounded; per Work
    with optional per-edition/language variant.
  - Draft on import (status=suggested); regenerate/edit/reject; published = human-approved text.
  - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6_

- [x] 7. Review & confirmation screen + publish gate (Req 6)
  - Livewire Admin\BookReviewDetails: 7 grouped sections, suggested values pre-selected,
    confidence badge only where useful, category/tag search, duplicate-tag prevention,
    reject suggestion, save draft.
  - Publish gate: block until primary category + language + book type + approved age range
    + approved description present. Record source on every stored classification.
  - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5_

- [x] 8. Kids-domain fields (Req 8)
  - content_advisories controlled list + pivot; age range validated/auditable (min/max+band),
    never AI-auto-published.
  - education_phase (CAPS enum) + language_role (home/first-additional) on editions —
    modelled now, hidden until V2.
  - "Language Learning" category vs "usable for language learning" facet kept distinct.
  - Series reading-order progression helper (next volume at comparable reading level).
  - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5_

- [x] 9. Store discovery — V1 filters, facets, search (Req 7)
  - features + edition_features migrations; reading_levels + audience_ranges seeds.
  - BookQuery builder + CatalogueController: V1 facets (Language, Age, Reading level,
    Category, Book type, Series, Narrated/read-along, Free/paid) with counts; hide zero-count.
  - Search over title+translated titles, series, author/illustrator, ISBN/SKU, category/tag
    labels + aliases, description, characters, publisher; translated-label resolution via
    canonical ids. Search behind an interface (swappable for Scout/Meilisearch later).
  - Removable filter chips + Clear all; sort options (Req 7.6).
  - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6_

- [x] 10. Collections — manual + rule-based (Req 9)
  - collections, collection_books (pin/sort), collection_rules (json) migrations.
  - Rule-based collections evaluated from data (no app-code change to add one); admin pin to front.
  - _Requirements: 9.1, 9.2, 9.3_

- [x] 11. Public/admin separation, rights, analytics (Req 10)
  - edition_rights migration + visibility gate (no store display/sale where rights unavailable).
  - Ensure admin/operational metadata is never exposed as a public facet.
  - discovery_events privacy-safe aggregate tracking; no auto-change of approved categories.
  - _Requirements: 10.1, 10.2, 10.3_

- [x] 12. V1 end-to-end + regression + honest status
  - Feature test: import → auto analysis → suggestions + description draft → review → publish
    writes approved pivots (source/approved_by) → book discoverable via V1 facets.
  - Verify on BOTH real Kolulu books (book-agnostic, not tuned to one).
  - Confirm archived/merged category & tag do not break assigned books; facet counts accurate;
    rights gate enforced; description stays draft until approved.
  - Write STATUS.md: what's proven, V2 deferrals, known caveats (LLM mocked in tests, etc.).
  - _Requirements: all (V1 acceptance)_
```
