# Design — Book Classification, AI Description & Store Discovery

## Implementation decision: Option B (layer onto existing schema; V8 untouched)
Confirmed by Captain Zan ("keep V8 safe"). The existing DB already models a work/edition
split in all but name:
- **`books`** = the WORK (title, author, illustrator, original_language, sku, cover, pdf).
- **`translations`** = the EDITIONS (language_code, status; per-edition render fields).
- **`book_pages.extracted_text`** = the text source for analysis.

So we DO NOT create book_works/book_editions or migrate data. Instead:
- Work-level classification tables key to **`book_id`**.
- Edition-level fields (reading level, features, education_phase, language_role) attach to
  **`translations`** (and a synthetic "original" edition = the book's own original_language
  when it has no translation row for it).
- The flat `books.category` / `books.age_group` / `books.description` string columns are
  kept for backward-compat but SUPERSEDED by the controlled tables; a data backfill maps
  the old strings to canonical categories where possible (non-destructive).
- **The V8 rendering/translation engine and its tables are NOT touched.** This feature reads
  `book_pages.extracted_text` (already populated) and, when present, the persisted V8
  diagnostic/manifest — it never calls the render path.

Where the tables below say `book_works`/`book_editions`, read them as `books`/`translations`
respectively for this build. Names in migrations use the existing tables.

## Guiding principles
- **Reuse the V8 scene.** Classification/description consume the DocumentScene the engine
  already builds (extracted text, page classification, cover, vocab/phonics). No second PDF parse.
- **AI suggests, human confirms.** Every AI output is a suggestion row with confidence +
  source; the published value is the human-approved one. Sensitive fields (age, content
  advisory) require explicit confirmation.
- **Controlled vocabularies.** Categories/tags have canonical identities, translations,
  aliases; nothing free-text gets published. Archive/merge, never delete.
- **Work vs Edition.** Shared metadata on the Work; language/format metadata on the Edition;
  reading level per edition.
- **Public vs admin.** Store-facing metadata is a strict subset; operational data never leaks
  into facets. Rights gate visibility.
- **Ship V1 thin.** Build the V1 filter/metadata cut; model (but hide) V2 fields to avoid
  migration churn.

## Data model (Laravel migrations)

### Works & editions
- `book_works` — id, original_title, series_id, volume, publisher, imprint,
  primary_category_id, general_audience, cultural_setting, original_pub_info, timestamps.
- `book_editions` — id, book_work_id, language_code, display_title, translation_status,
  translator, narration_available, narrator, reading_level_id, page_count, audio_duration,
  price, publication_status, download_available, isbn, timestamps.
- `series` — id, slug, title, translations(json), sort metadata.
- `contributors` + `book_contributors` (role: author/illustrator/translator/narrator) pivot.

### Classification vocabularies
- `categories` — id, slug, parent_id, group, status(active/hidden/archived), sort_order,
  merged_into_id, created_by, approved_by, translations(json).
- `book_categories` (pivot) — book_work_id, category_id, is_primary, source, confidence,
  approved_at, approved_by.
- `tags` — id, slug, group, parent_id, status, merged_into_id, pinned(bool), created_by,
  approved_by.
- `tag_translations` — tag_id, language_code, label.
- `tag_aliases` — tag_id, alias.
- `book_tags` (pivot) — book_work_id, tag_id, source, confidence, approved_at, approved_by.
- `features` + `edition_features` (pivot; feature belongs to an EDITION).
- `audience_ranges` — id, band label, min_age, max_age, translations.
- `reading_levels` — id, slug (pre_reader..advanced), numeric_score(nullable), language_code,
  translations.

### Kids-domain fields (Req 8)
- `content_advisories` controlled list + `book_content_advisories` pivot.
- On `book_editions`: education_phase (nullable CAPS enum), language_role
  (home_language | first_additional_language | not_applicable).
- Series progression: `series` ordering + a helper that, given an edition, finds the next
  volume at a comparable reading_level.

### AI suggestion / review workflow (Req 4/5/6)
- `classification_suggestions` — id, book_work_id, edition_id(nullable), field, value(json),
  confidence, model, engine_version, created_at. (Raw AI output; admin-only.)
- `classification_reviews` — id, book_work_id, reviewer_id, decision(accepted/edited/rejected),
  final_value(json), field, reviewed_at.
- `book_descriptions` — id, book_work_id, edition_id(nullable), language_code, short_text,
  long_text, status(suggested/approved/rejected), source, model, approved_by, approved_at.

### Collections (Req 9)
- `collections` — id, slug, title, type(manual/rule), translations.
- `collection_books` (manual pivot, with pin/sort) + `collection_rules` (json rule for rule-based).

### Rights & analytics (Req 10)
- `edition_rights` — edition_id, rights_holder, territories(json), languages(json),
  licence_start, licence_end, digital/audio/animation/print flags, store_visibility(json).
- `discovery_events` — privacy-safe aggregate events (type, payload json, day bucket).

Pivot convention (Req 6.5): every classification pivot carries `source`
(publisher|administrator|ai|imported), `confidence`, `approved_at`, `approved_by`.

## Analysis pipeline (Req 4)

```
Import PDF ──▶ V8 build_document_scene (existing) ──▶ ClassificationAnalyzer
   │                                                        │
   │                          ┌─────────────────────────────┼─────────────────────────┐
   │                          ▼                             ▼                           ▼
   │                deterministic signals            LLM content pass            description draft
   │             (book type, reading level,      (themes/topics/characters/     (short + long blurb,
   │              illustration density,           setting/mood/genres/tags)      age-appropriate tone)
   │              age band heuristic)                    │                           │
   │                          └──────────────┬───────────┘                           │
   ▼                                          ▼                                        ▼
book_editions row                 classification_suggestions rows          book_descriptions (suggested)
                                            │
                                            ▼
                              status: SUGGESTED  ──▶  Review screen  ──▶  classification_reviews
                                                                            │ (publish gate)
                                                                            ▼
                                                            book_categories / book_tags / edition_features
                                                            (source=administrator|ai, approved_at set)
```

- **Trigger:** an import event dispatches `AnalyzeBookClassificationJob` (queued). Auto, per Req 4.1.
- **Deterministic first, LLM second:** derive what the V8 scene already knows (page-type
  ratios → book type; text metrics + vocab/phonics page → reading level; illustration
  density → picture-book vs early-reader). Only send the extracted text to the LLM for the
  subjective dimensions (themes, mood, characters, tags, description).
- **Tag mapping:** LLM tag strings are normalised against `tags` + `tag_aliases`; unmatched
  suggestions create PENDING tags in the approval queue (Req 3.3) — never auto-published.
- **Confidence + source** stored on every suggestion; sensitive fields flagged
  requires_confirmation=true.
- **Async + safe failure:** job status PENDING→ANALYSING→SUGGESTED (or FAILED→retriable).
  A failure never blocks manual metadata entry (Req 4.5).

### Services
- `App\Services\Classification\ClassificationAnalyzer` — orchestrates deterministic +
  LLM passes; returns a suggestion set. Book-agnostic; consumes the V8 scene.
- `App\Services\Classification\SceneSignals` — pure functions over the V8 scene
  (book_type_signal, reading_level_signal, illustration_density, age_band_hint).
- `App\Services\Classification\DescriptionWriter` — drafts short/long description; enforces
  no-spoiler / no-PII / age-appropriate constraints; length-bounded.
- `App\Services\Classification\TagResolver` — maps free strings → canonical tags / queue.
- `App\Jobs\AnalyzeBookClassificationJob` — queued entry point on import.

The LLM client is abstracted behind an interface so the model/provider is swappable and
costs stay admin-only (Req 10.1).

## Review UI (Req 6)
- Livewire `Admin\BookReviewDetails` (mirrors the existing `BookReviewer` overlay patterns).
- Seven grouped sections; suggested values pre-selected; confidence badge only where useful
  (low-confidence age/content advisory highlighted).
- Category/tag pickers search the controlled vocab; duplicate tags prevented client+server.
- Publish button disabled until required fields present (Req 6.4); "Save draft" always available.

## Store discovery (Req 7)
- `Store\CatalogueController` + a `BookQuery` builder that composes facet filters.
- Facets computed with counts (SQL GROUP BY over the filtered set); zero-count options hidden.
- Search: start with DB LIKE + alias join (V1); interface allows swapping in a search engine
  (Meilisearch/Scout) later without controller changes. Translated label/alias resolution
  goes through the canonical tag/category ids.
- Active-filter chips + Clear all; sort options per Req 7.6.

## V1 vs V2 (Req 7.1)
- **V1 filters:** Language, Age, Reading level, Category, Book type, Series, Narrated/read-along,
  Free/paid.
- **V1 metadata written:** primary category, ≤3 secondary, 5–15 controlled tags, age range,
  reading level, book type, language, series, digital features, approved description.
- **V2 (data modelled now, surfaced later):** curriculum/learning goals, accessibility filters,
  mood/duration, rule-based collections UI, multilingual tag search UX, recommendations.

## Testing strategy
- **Unit (PHP):** SceneSignals deterministic outputs on fixture scenes; TagResolver
  alias/dup/queue behaviour; publish-gate required-field enforcement; rights visibility gate.
- **Feature (PHP):** import → job → suggestions created → review → publish writes approved
  pivots with source/approved_by; description stays draft until approved; facet counts correct;
  archived/merged category doesn't break assigned books.
- **Analyzer contract:** ClassificationAnalyzer over a known V8 scene returns expected
  book_type/reading_level signals (deterministic part) — LLM part mocked.
- Reuse the two real Kolulu books as end-to-end fixtures (book-agnostic, not tuned to one).
```
