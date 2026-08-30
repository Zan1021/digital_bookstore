# Requirements — Book Classification, AI Description & Store Discovery

## Overview
A classification and discovery system for the digital bookstore. On import, EVERY book
(not only translated editions) is automatically analysed to suggest a classification
(category, book type, age range, reading level, genres, themes, tags, digital features)
AND a customer-facing store description. All AI output is a SUGGESTION that an
administrator reviews and confirms before publishing. The confirmed metadata powers
store browsing, faceted search and curated collections.

This is separate from the V8 PDF rendering engine, but REUSES the V8 document scene
(extracted text, page classification, cover, vocab/phonics structure) as the primary
analysis input rather than re-analysing the PDF from scratch.

Domain: primarily children's books (South African market, 11 official languages).
Safeguarding note: age-appropriateness and content advisories are trust-critical and
must never be auto-published without human confirmation.

## Definitions
- **Work** — the abstract book; shared metadata across editions.
- **Edition** — one language/format of a Work (English PDF, Afrikaans PDF, narrated, interactive).
- **Category** — broad controlled store section (hierarchical).
- **Tag** — controlled descriptive term with a canonical identity, aliases, translations.
- **Feature** — a capability of a specific edition (narration, read-along, interactive).
- **Collection** — curated or rule-based merchandising group.

---

## Requirement 1 — Work / Edition metadata model
**User story:** As the platform, I want work-level and edition-level metadata separated so
one book can have many language/format editions without duplicating shared data.

Acceptance criteria:
1.1 Work-level metadata (title, series, volume, authors, illustrators, publisher, primary
    + secondary categories, genres, subjects, themes, characters, audience, cultural
    setting, original publication) is stored once per Work.
1.2 Edition-level metadata (display title, language, translation status, translator,
    narration, narrator, reading level, page count, audio duration, price, publication
    status, accessibility + interactive features, download availability, ISBN/identifier)
    is stored per Edition.
1.3 Reading level is stored PER EDITION (a translation may be easier/harder than the source).
1.4 Every published Edition has at minimum: a primary category (on its Work), a language,
    and a book type.
1.5 The model applies to ALL books, whether or not they have translated editions.

## Requirement 2 — Controlled categories (hierarchical, safe to evolve)
2.1 Categories support parent/child relationships (e.g. Children's Books → Picture Books).
2.2 Admin can create, rename, reorder, translate, hide and archive categories.
2.3 A category assigned to books is NEVER deleted — only archived or merged into another.
2.4 Archiving/merging a category must not break books already assigned to it.
2.5 Categories carry translated display labels; the canonical identity is language-neutral.

## Requirement 3 — Controlled tags with governance
3.1 Every tag has a canonical record: id, slug, group, parent_id, translations{}, aliases[], status.
3.2 Synonyms/aliases map to ONE canonical tag; translating a label does NOT create a new tag.
3.3 AI-suggested tags enter an approval queue; they are never published directly.
3.4 Admin can merge duplicate tags (merged_into_id) and pin preferred tags.
3.5 Archived tags stay historically attached but are no longer selectable.
3.6 Category, tag, feature and workflow-status are distinct data types (not one bag).

## Requirement 4 — Automatic analysis on import (all books)
**User story:** As a publisher, when I upload a book, I want the system to automatically
suggest its classification and a store description so I only have to review, not author.

Acceptance criteria:
4.1 On import, classification runs AUTOMATICALLY (no manual "analyse" click required);
    a publisher may re-run it on demand.
4.2 Analysis consumes the V8 document scene (extracted text, page classification, cover,
    vocab/phonics structure) plus any existing PDF/publisher metadata — it does NOT
    re-OCR or re-parse the PDF independently.
4.3 It suggests: title/subtitle, series/volume, original language, book type, primary +
    secondary categories, age range (min/max + band), education phase, reading level,
    genres, subjects/themes, characters, setting, mood, story duration, digital features,
    content advisory, and controlled tags — each with a confidence score.
4.4 Book type / illustration-vs-text density / reading level are derived from the V8 scene
    where possible (not a blind LLM guess).
4.5 The pipeline runs asynchronously (queued job) and records status
    (PENDING → ANALYSING → SUGGESTED → REVIEWED); failure routes to a retriable state and
    never blocks the book from manual entry.
4.6 Sensitive classifications (age range, content advisory) are flagged as requiring
    explicit human confirmation and are never auto-published.

## Requirement 5 — AI-generated store description (admin-reviewed)
**User story:** As the store, I want an AI-drafted customer-facing description per book so
each product page has marketing copy without manual writing.

Acceptance criteria:
5.1 On import, the system drafts a store description from the book's content (title, theme,
    characters, reading level, age) — age-appropriate in tone for a children's store.
5.2 The description is a DRAFT with status (suggested) — it is not published until an admin
    approves or edits it.
5.3 A description is generated per Work, with the option of an edition/language-specific
    variant (e.g. an Afrikaans description for the Afrikaans edition).
5.4 The draft avoids spoilers, avoids unverifiable claims, and contains no PII.
5.5 Admin can regenerate, edit, or reject the draft; the final stored description is the
    human-approved text.
5.6 Length/format is bounded (short summary + optional longer blurb) suitable for a product page.

## Requirement 6 — Publisher/admin review & confirmation screen
6.1 After analysis, a "Review Book Details" screen shows suggested values pre-selected,
    grouped: basic details; category & book type; audience & reading level; genres/themes/
    tags; learning info; digital & accessibility features; store/pricing; description.
6.2 Confidence is shown only where it helps the reviewer (e.g. low-confidence age).
6.3 The reviewer can search within categories/tags, reject a suggestion, prevent duplicate
    tags, distinguish required vs optional, and save a draft without publishing.
6.4 Publishing is blocked until required fields (primary category, language, book type,
    approved age range, approved description) are present.
6.5 Every stored classification records its source: publisher | administrator | AI | imported.

## Requirement 7 — Store discovery: filters, facets, search
7.1 V1 public filters: Language, Age, Reading level, Category, Book type, Series,
    Narrated/read-along, Free/paid. (Advanced filters are V2, behind "More Filters".)
7.2 Filters apply as FACETS with result counts; zero-result options are hidden unless the
    UI explicitly explains unavailability.
7.3 Active filters render as removable chips with a "Clear all" action.
7.4 Search covers title + translated titles, series, author/illustrator, ISBN/SKU, category
    labels, tag labels AND aliases, description, character names, publisher.
7.5 Search understands translated category/tag labels (an Afrikaans tag search finds books
    on the same canonical tag as its English label).
7.6 Sorting: relevance, newest, most popular, highest rated, title A-Z, age youngest-first,
    reading level easiest-first, price low-high.

## Requirement 8 — Kids-domain specifics
8.1 Age range is a validated, auditable field (min/max ages + band); never AI-auto-published.
8.2 A controlled content-advisory dimension exists (e.g. none, mild-peril, sadness/loss)
    as governed metadata.
8.3 CAPS-aligned education phase + home-language vs first-additional-language are modelled
    in the data now (may be hidden until V2) so no later migration is needed.
8.4 "Language-learning as a category" is distinct from "usable for language learning" as a
    cross-cutting facet — a story can be either or both.
8.5 Series reading-order progression is supported (given a finished book, suggest the next
    in series at a comparable reading level).

## Requirement 9 — Collections (manual + rule-based)
9.1 Collections can be manually curated (ordered list of books).
9.2 Rule-based collections are defined by data rules (language/features/status/etc.) and
    require NO application-code change to add.
9.3 Admin can pin selected books to the front of a rule-based collection.

## Requirement 10 — Public/admin separation, rights, analytics
10.1 Operational/admin metadata (processing state, QA status, engine version, AI cost,
     review confidence, rights) is NEVER exposed as a public filter.
10.2 Rights/territory: an edition is not shown/sold where territorial or format rights are
     unavailable.
10.3 Privacy-safe aggregate analytics (search terms, filters used, no-result searches,
     impressions, opens, reading/narration completion, collection engagement) are tracked
     to improve discovery; publisher-approved categories are never auto-changed without review.

## Out of scope (this spec)
- The V8 PDF rendering/translation engine (separate, complete).
- Payment/checkout flow.
- The reader/flipbook viewer.
- Recommendation ML models (V2+; analytics groundwork only here).
