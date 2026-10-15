# Design — Publishing Platform v2

Companion to `requirements.md` (requirement numbers are cited as **Req n.m**). Read §1 and §2 first; they explain why the engine changes direction.

---

## 1. Principles

1. **The scene is the master copy, not the PDF.** After intake, a book is artwork plus structured text blocks (`scene.json`). Every output (reader, PDF, EPUB, narration, store previews) is generated from the scene. We never edit a PDF in place to translate it.
2. **One layout engine, used everywhere.** Text is measured and laid out by Chromium with the same CSS and font files the reader uses. What the engine measures is what the reader sees, and the PDF is printed from the same HTML.
3. **Treatments, not page types.** Each block gets one of eight treatments (Req 4.3). The handler for a treatment is the only place that knows what to do with it. New books are handled by classifying their blocks, never by new code paths.
4. **Deterministic first, AI where it earns its cost.** Extraction, art plates, fitting, word lists and gates are plain code. AI does translation, ambiguous classification, rewriting of educational content and artwork repair, each with structured output and code validation.
5. **Human review is part of the product.** The goal is 85–90% of pages needing no layout change, plus an editor that makes the rest a few minutes' work. The editor is not an apology for the engine; it is the quality guarantee publishers pay for.
6. **Fast by construction.** Few AI calls per book, parallel page work, cached results, and incremental re-layout.

## 2. Current state (2026-10-15)

### 2.1 What exists and works

| Area | State | Decision |
|---|---|---|
| Laravel 12, Livewire 4, SQLite, Python scripts | Running | **Keep** (production DB changes, §20) |
| Classification, categories, tags, aliases, reading levels, collections, rights gate, discovery query (`app/Services/Classification`, `Discovery`, ~13 tables) | Done and tested (spec `book-classification-discovery`) | **Keep and reuse** |
| Crop-mark detection (`cropmark_detection.py`) | Live | **Reuse** in intake |
| Security preflight (`security.py`), font integrity and glyph preflight (`font_integrity.py`, `glyph_preflight.py`, `font_registry.py`) | Live | **Reuse** (glyph coverage check → Req 7.4) |
| OCR and illustration-text pipeline (`ocr_integration.py`, `illustration_*.py`, `image_inpainting.py`, `IllustrationTextService`) | Live | **Reuse parts** for `in_art` blocks (Req 5) |
| Translation memory and glossary (`translation_memory` table, `TranslationService::buildGlossary`) | Live | **Reuse** |
| Narration via ElevenLabs with per-page timing JSON (`NarrationService`) | Live | **Keep**, put behind a provider interface |
| Approval tracks, render fingerprint, exercise contract columns on `translations` | Live | **Reuse** the track idea; replace the data shape (§4) |
| Reader on PDF.js (`reader.blade.php`, 2,035 lines) | Live | **Replace** with the scene reader (§14); reuse its controls and narration UI |
| V8 engine (`pdf_translate_v8.py` 5,591 lines, `PdfTranslationService` 2,101 lines, `render_gate.py`, ~40 modules) | Wired, not compliant | **Retire** after the Scene Engine passes the corpus (§19) |
| Commerce (products, cart, orders, payments, entitlements, library) | **Not built** | Build (Phase 4) |
| Authentication on admin | **None** — `/admin` routes have no auth middleware | Fix first (Phase 0) |
| Asset storage | All assets on the `public` disk (51 call sites) | Move to private storage (Phase 0) |

### 2.2 Why V8 stalls at ~80%

V8 opens the source PDF, removes each text span, and writes translated text back into roughly the same spot. A PDF only stores glyphs at coordinates, so V8 has to guess paragraphs, columns, tables and roles from geometry, then fit text using PDF-level tools that do not wrap, hyphenate or shape text the way a layout engine does. Every new book breaks a different guess; each fix adds a rule; rules start to conflict. The record shows it: "226 tests green" while the browser showed overflowing covers, overprinted words and mixed font sizes (`RECONCILIATION-wired-vs-working.md`), and the word-list page shows the pattern most clearly (`evidence/v8-word-list-page-defects.jpg`: header overprint, duplicated header row, phonics overflowing the table, mixed sizes in a column).

The Scene Engine removes the guessing from rendering: structure is decided once at intake (confirmed by a human when unsure), and rendering is a layout problem solved by a real layout engine.

**Feasibility check, done for this spec:** removing all live text from Kolulu's Home pages 1, 5 and 15 with PyMuPDF redaction (text only; images and line art untouched) took about 1 second for the three pages and left the artwork pixel-identical (`evidence/art-plate-test-page*.jpg`). The "KOLULU TAKTAKI" wordmark is artwork and stays; the ® beside it is live text and becomes a `keep` block.

### 2.3 Existing specs and how this one relates

| Spec | Status | Relationship |
|---|---|---|
| `book-classification-discovery` | Done | Reused as-is (Req 16.4–16.5, 18.5) |
| `gated-translation-narration-flow` | 0 of 13 tasks ticked, though some of its columns exist (`approval_tracks`, narration `is_outdated`) | Its approval-track ideas move into Req 12 |
| `v8-structure-aware-engine`, `world-class-render-engine`, `unified-rendering-and-testing`, `engine-wiring-and-activation`, `book-agnostic-vocab-render`, `cover-raster-retypeset`, `illustration-text-vision`, `on-demand-generative-repair` | V8 era | Superseded by this spec. Useful code is listed in §2.1 |
| `.kiro/steering/v8-brief-compliance.md` | Always-included steering | Switched to manual inclusion; replaced by `.kiro/steering/platform-v2.md` |
| `specs/page-curl-reader`, `specs/manifest-scene-graph-unification` (root `specs/`) | Older | Superseded by §14 and §5 |

### 2.4 Design review of the current screens

Screenshots are in `current-screens/`.

| Screen | Finding | Fix (requirement) |
|---|---|---|
| Store home (01, 02) | Mixes the customer store with a publisher sales pitch (AI cost comparisons, "turn 300 books into…", publisher login) | Separate store and publisher pages (Req 18.3) |
| Store home, footer | Header brand "Mthombothi Studios", footer brand "StoryBooks" | One brand (Req 27.4) |
| Store home | Generic template look: emoji icons, gradient headline text, stat counters ("1+ books") | Design system §16 (Req 27.3) |
| Book page (03) | Lists English twice; offers Afrikaans although it is still in review; shows 7 formats with hard-coded prices that do not exist as products; "Add to cart" has no cart | Products + publishing matrix (Req 17, 18.1) |
| Book titles everywhere | File name used as title ("Kolulu Engl Series 3 - 1 - Kolulu's Home") | Clean title at intake (Req 2.7) |
| Browse (04) | Cover image missing (grey box) | Req 18.6 |
| Reader desktop (05) | Strong base: dark, immersive, clean controls; the Afrikaans cover reads well | Keep the look in the new reader |
| Reader mobile (06) | Page sits off-centre; loading overlay stays up | Req 14.3 |
| Admin dashboard (07) | Book shows "Published" while its only translation is in review | Honest statuses (Req 12.7, 17.4) |
| Admin book (08) | Source language appears as an "English · Draft" translation; metadata unset after intake | Source edition is not a translation (§4); metadata suggestions on intake (Req 16.4) |
| Translation review (09) and Review queue (10) | Two overlapping review screens; whole-page textareas instead of blocks; every page marked green "Approved" while counts say 0 approved; scores all 10/10; side-by-side images broken | One edition review screen (Req 12) |
| Upload (11) and Onboarding (12) | Two separate 6-step upload flows | One intake flow (Req 2.1) |

---

## 3. Architecture

```text
                    ┌──────────────── Web (Laravel + Livewire) ─────────────────┐
 Customers ───────► │ Store · Search · Cart/Checkout · Library · Reader (JS)    │
 Publisher staff ─► │ Admin: Intake · Book workspace · Edition review · Store  │
                    │ API /api/v1 (Sanctum) ──► future iOS/Android              │
                    └───────────────┬──────────────────────────────┬────────────┘
                                    │ jobs (Redis queue, Horizon)  │ signed asset routes
                    ┌───────────────▼──────────────┐   ┌───────────▼───────────┐
                    │ PHP job layer                │   │ Private storage       │
                    │ orchestrates stages, ledger, │   │ (local → S3-compatible)│
                    │ AI providers, notifications  │   │ sources, plates, scenes,│
                    └───────┬───────────────┬──────┘   │ exports, audio          │
                            │ CLI / JSON    │          └────────────────────────┘
              ┌─────────────▼───┐   ┌───────▼─────────────────┐
              │ Python workers  │   │ Layout service (Node +  │
              │ PyMuPDF: intake,│   │ Playwright/Chromium):   │
              │ blocks, plates, │   │ fit ladder, gates,      │
              │ OCR, masks, PDF │   │ text-layer PDF, previews│
              │ merge, EPUB     │   └─────────────────────────┘
              └─────────────────┘
       AI providers (behind interfaces): Translation · Vision · ImageEdit · TTS
```

**Why a separate layout service:** Chromium must load the fonts and CSS once and lay out hundreds of blocks per second. A long-lived Node process with one browser and a page pool does this; launching a browser per job would cost seconds each time. PHP calls it over HTTP on localhost.

**Python worker calls:** one CLI per stage that takes a JSON job file and writes a JSON result. Each call processes a whole book or a batch of pages, not one subprocess per check (today `PdfTranslationService` alone contains 16 separate Python process calls, each re-opening the PDF).

## 4. Data model

New and changed tables (Laravel migrations). Existing classification and discovery tables stay.

```text
publishers            id, name, slug, settings(json)                      ← tenancy-ready (Req 25.2)
font_profiles         id, publisher_id, name, roles(json: role → family, weight, size scale)
fonts                 id, publisher_id, family, style, weight, file_path, licence, scripts(json)
languages             code, name, direction(ltr|rtl), script, hyphenation(bool), enabled

books (changed)       + publisher_id, series_id, series_position, clean title fields,
                        source_checksum, scene_path, analysis_status, analysis_confirmed_at
                        (series text column migrates to series table)
series                id, publisher_id, name, slug, description, cover_path, age_min, age_max,
                        status, curriculum_plan(json)
contributors          id, name, role; book_contributor(book_id, contributor_id, role)

source_pages          id, book_id, number, trim_box, has_live_text, plate_pdf_path,
                        plate_screen_path, width_pt, height_pt
blocks                id (stable ULID), book_id, page_id, parent_id (structure/table),
                        kind(paragraph|heading|label|cell|list_item|bubble|in_art),
                        treatment, treatment_confidence, treatment_confirmed(bool),
                        bbox, allowed_area, reading_order, style(json), source_text,
                        role(body|heading|title|educational|caption|label|legal),
                        structure(json: row, col, span, header) nullable

translations (changed) = language editions. Source language is NOT stored as a translation
                        (today an "en · draft" row exists for an English book).
                        + scene_version, layout_status, tracks(json: language, layout,
                          educational, narration → status, by, at), published_at
block_translations    id, translation_id, block_id, text, alt_short_text, status,
                        source_hash (detects stale), edited_by, approved_by, approved_at
resolved_layouts      block_translation_id, font_family, font_size, line_height,
                        letter_spacing, box(json), ladder_step, lines(json), fits(bool)
page_checks           translation_id, page_id, run_id, results(json), passed(bool)
derived_content       translation_id, block_id, generator, inputs_hash, output(json),
                        validation(json), status
hf_word_lists         language_code, grade, word, rank                   (Req 10.2)
text_templates        publisher_id, language_code, key, template, approved_by
review_comments       translation_id, page_id, block_id nullable, user_id, body, resolved_at

products              id, publisher_id, book_id, language_code, format(flipbook|pdf|epub|
                        audiobook|paperback|hardcover), sku (unique), price, sale_price,
                        tax_class, is_digital, status, featured, stock, low_stock_at,
                        backorders(bool), weight_g, dimensions(json), shipping_class
stock_adjustments     product_id, user_id, delta, reason, before, after
bundles, bundle_items (Req 17.6)
carts, cart_items     (session or user)
orders, order_items   (status, shipping, totals, coupon); payments (provider, reference,
                        amount, status, raw_itn(json), verified_at)
entitlements          user_id, product_id, order_item_id, granted_at, revoked_at, reason
devices               user_id, fingerprint, name, last_seen_at, revoked_at
downloads             entitlement_id, asset, ip, at
reading_positions     user_id, book_id, language_code, page, updated_at
coupons, promotions, newsletter_subscribers (consent_at, source), faqs, faq_categories,
content_blocks        (homepage, how-it-works, footer)
ai_ledger             id, provider, model, operation, book_id, translation_id, units(json),
                        cost_zar, duration_ms, cache_hit(bool), at
jobs_runs             id, book_id, stage, status, started_at, finished_at, error, meta(json)
audit_logs            user_id, action, entity_type, entity_id, before(json), after(json), at
```

The scene file (`scenes/{book_id}/scene.json`) is a compiled, cacheable copy of `source_pages` + `blocks` used by workers and the reader manifest. The database is the source of truth for editing; the file is regenerated on change and versioned (`scene_version`).

## 5. Scene format

```json
{
  "book_id": 10002, "version": 7, "units": "pt",
  "pages": [{
    "n": 5, "w": 538.6, "h": 751.2,
    "plate": {"pdf": "plates/p5.pdf", "screen": "plates/p5@2x.webp"},
    "blocks": [{
      "id": "01JA9X…", "kind": "paragraph", "role": "body",
      "treatment": "translate_reflow",
      "bbox": [38, 400, 330, 640], "allowed": [30, 392, 352, 690],
      "order": 1,
      "style": {"font": "body", "size": 17.5, "line": 1.25, "color": "#1d1d1b",
                "align": "left", "weight": 400},
      "text": "Open the little front door and step inside, and to your amazement…"
    }, {
      "id": "01JA9Y…", "kind": "label", "role": "label", "treatment": "keep",
      "bbox": [362, 712, 372, 724], "text": "3"
    }]
  }]
}
```

The language layer (`scenes/{book_id}/{lang}.json`) adds, per block ID: `text`, `short`, `layout` (resolved), `status`. The reader manifest (Req 24.3) is the merge of the two, with signed URLs inserted at request time.

## 6. Pipeline

| Stage | Where | What happens | Parallel | AI |
|---|---|---|---|---|
| 1. Intake | PHP + Python | Store original privately, checksum, preflight (`security.py`), page count, trim boxes (`cropmark_detection.py`), live-text vs scanned per page | per book | none |
| 2. Extract | Python | Blocks from live text (PyMuPDF `dict` spans → lines → blocks), OCR for scanned pages, text-in-image detection (`illustration_classify`/`measure`) | per page | none (OCR local) |
| 3. Structure | Python | Group spans into paragraphs, columns, lists, tables (reuse `borderless_table.py`, table logic from `document_model.py`), reading order | per page | none |
| 4. Classify | PHP + Python | Deterministic signals assign treatments (§7); unclear pages get one vision call with the page image and the block list, returning JSON | per page | vision, only unclear pages |
| 5. Confirm | Admin UI | Overlays by treatment; admin fixes anything flagged; series template applied to later books | — | — |
| 6. Plates | Python | Redact text only (images and line art kept) → vector plate PDF; rasterise WebP/AVIF screen plate; for `structure` blocks also remove ruling lines inside the structure's area | per page | none |
| 7. Translate | PHP | Batched structured translation with book context, budgets and short alternatives (§8); template fill; title context | per batch | text |
| 8. Derive / rewrite | PHP + Python | Word lists and HF lists from translated text; rewrite content with rules and validators (§10) | per block | text (rewrite only) |
| 9. Layout | Layout service | Fit ladder per block in Chromium; structures laid out as HTML tables; store resolved layout (§9) | per page | none |
| 10. Gates | Layout service | Geometry and text checks on the laid-out DOM (§12); contact sheet | per page | vision on demand |
| 11. Review | Admin UI | Edition review screen (§13) | — | — |
| 12. Outputs | Layout service + Python | Text-layer PDF from Chromium → merged onto vector plates (PyMuPDF `show_pdf_page`); FXL EPUB; reader manifest; narration (§15) | per page | TTS |

Each stage writes a `jobs_runs` row with timings (Req 3.3). A stage only re-runs for pages whose inputs hash changed (Req 3.6).

## 7. Treatment classification

Deterministic signals first; each gives a treatment and a confidence.

| Signal | Treatment suggested |
|---|---|
| Matches ISBN, URL, email, ©, page-number position, publisher/brand name list, character-name list | `keep` |
| Matches a copyright/credits pattern ("Published by", "Printed by", "Story by", "Copyright subsists…") | `template` |
| Many short single words in columns; >70% of words appear in the story pages | `derive` (word list) |
| Phonics notation (`oo-`, `br-i-ng`, "- ea"), letter + word pairs, short rhyming lines | `rewrite` |
| Ruled grid or aligned columns with headers | `structure` |
| Text found by OCR inside an image region | `in_art` |
| Short, large or centred, one line | `translate_fixed` |
| Everything else with sentences | `translate_reflow` |

The vision call (stage 4) receives the page image plus the block list with IDs and suggested treatments, and returns `{block_id, treatment, confidence, reason}` for the unclear ones only. Series matching (Req 4.7) compares each new page's block layout with the series' confirmed pages (block positions and roles); a close match copies treatments.

## 8. Translation

- **Batching:** blocks in reading order, grouped by page, sent in batches of about 6–8 pages, each batch carrying the book brief (summary, characters, tone, age band), glossary and the previous batch's last page for continuity. A 16-page book is 2–3 calls instead of today's per-page translate + back-translate + score + refine chain.
- **Budgets:** `budget_chars = fit_capacity(block, target_font) × 0.95`, where fit capacity is measured by the layout service for the block's allowed area at the role's minimum size. The prompt asks for natural text within the budget and a `short` alternative when the first exceeds 85% of the budget.
- **Output:** JSON schema `{blocks: [{id, text, short?}]}`; the PHP side rejects missing, duplicate or unknown IDs and retries once with only the failed IDs.
- **Checks (Req 6.7):** source-language word ratio (wordfreq-style list per language), `keep` terms present verbatim, glossary terms, digits match, sentence count within tolerance. Back-translation becomes an on-demand reviewer tool, not a default step.
- **Title context (Req 6.10):** translate the title once with the whole book; propagate to cover, title page and store.
- **Models:** configured in `config/ai.php` per operation, so models can be changed without code changes; the current OpenAI models are the default.

## 9. Layout and fit ladder

The layout service renders each page as an absolutely positioned HTML page at the page's point size:

```html
<section class="page" style="width:538.6pt;height:751.2pt">
  <img class="plate" src="p5@2x.webp">
  <div class="b role-body" data-id="01JA9X…" style="left:30pt;top:392pt;width:322pt;height:298pt">…</div>
</section>
```

For each block it runs the ladder (Req 8.2) in the page context with `getBoundingClientRect` and `scrollHeight` checks: original → line-height −8% → letter-spacing −2% → font-size steps down to the role minimum (85%) → expand into free space → short alternative → flag. Expanding uses a free-space mask computed once per page from the plate (pixels close to the background colour, excluding regions marked protected); the block may grow only into free space inside the trim box.

**Role consistency (Req 7.7):** after all pages are laid out, the most common resolved size per role is chosen as the edition size for that role; blocks that needed a smaller size keep theirs only if it is within one step, otherwise they are flagged. This prevents the "mixed sizes" defect.

**Hyphenation and breaking:** CSS `hyphens:auto` with `lang` set per block; Chromium's dictionaries cover the major languages. For languages without dictionaries, hyphenation is off and the ladder handles fit.

**Speed:** one browser, one page per worker, fonts preloaded; measuring a block is milliseconds. A 16-page book lays out in a few seconds; a single-page re-layout is well under the 2-second budget.

## 10. Derived and rewritten content

**Word list (`derive`, Req 10.1–10.2):**
1. Tokenise the approved translated story blocks (`translate_reflow` and `translate_fixed` on story pages) with a language-aware tokenizer.
2. First-appearance order, lower-case for comparison, keep original casing of first appearance.
3. HF words = story words ∩ `hf_word_lists(language, grade)`; vocabulary = story words − HF words.
4. Column count = smallest count whose tallest column fits the table body at the role size (Req 9.3).

Checked against Kolulu's Home: 108 of 112 English vocabulary words are story words in near first-appearance order, so the generator reproduces how the publisher built these pages.

**Rewrite (`rewrite`, Req 10.3–10.5):** per content type a rule file (YAML) defines the prompt, the JSON schema and validators:

```yaml
type: phonics
schema: {items: [{pattern: str, examples: [str], note?: str}]}
rules:
  - "Use 2–4 patterns that occur in the story text."
  - "Follow the series curriculum plan when present."
validators: [example_contains_pattern, example_is_word(dictionary|story), no_source_language]
```

Validation failures are retried once with the failures listed, then routed to educational review.

**Templates (Req 10.7):** `text_templates` per publisher and language, e.g. `copyright_notice.af`. Variables (`{publisher}`, `{year}`, `{isbn}`) come from the source block's parsed values.

## 11. Text inside artwork

Reuses the current illustration pipeline in three steps: detect (OCR / vision) → mask → resolve. Default resolution is `keep` for wordmarks and logos (brand-name list and size heuristics) and `flag` otherwise. `replace` uses LaMa-style inpainting for flat or simple backgrounds and the image-edit model for complex ones, always inside the mask; a pixel-diff check outside the mask rejects drifting results (Req 5.6).

## 12. Quality gates

Run on the laid-out DOM, so they see exactly what readers see:

| Gate | Hard / soft |
|---|---|
| Text box inside allowed area and trim box | hard |
| No two blocks overlap; no glyph overlaps another block | hard |
| No clipped or hidden overflow | hard |
| Every non-`keep` block present and non-empty | hard |
| No block still in the source language | hard |
| Every character has a glyph in the chosen font | hard |
| Peer size drift (role consistency) | soft |
| Contrast between text colour and plate behind it ≥ 4.5:1 for body | soft |
| Ladder step ≥ 5 (expanded or shortened) | info, shown in review |

Contact sheet: Chromium screenshots each source and translated page at reduced size into one tall image per edition, with flagged blocks outlined in red (Req 11.4).

## 13. Edition review screen

```text
┌ Kolulu's Home · Afrikaans ──────── Language ✓  Layout 3 left  Narration –  [Publish ▸] ┐
│ Pages │  Source page          │  Translated page (live)        │  Block                  │
│ ▢ 1 ✓ │  [image]              │  [HTML page, click a block]    │  "Maak die klein voor…" │
│ ▢ 2 ⚠ │                       │                                │  [text editor]          │
│ ▢ 3   │  overlay ◐            │                                │  Use shorter version    │
│  …    │                       │                                │  Treatment ▾  Area ⤡    │
│       │                       │                                │  Ladder: step 4 (−9%)   │
│       │                       │                                │  Comments (2)           │
└───────┴───────────────────────┴────────────────────────────────┴─────────────────────────┘
```

Editing a block saves `block_translations`, calls the layout service for that page only, and swaps the page in place. Keyboard: J/K next/previous page, A approve page, E edit block.

## 14. Reader

A JS reader (no PDF.js) driven by the manifest:
- Each page = plate image + positioned text divs using the stored resolved layout; text is real text in Playpen Sans (searchable, selectable, read-along highlightable).
- Page flip: keep `page-flip` (already a dependency) for spread mode and add a reduced-motion fade; on phones, single page fitted and centred.
- Narration: audio per page; timings map to block and word spans for highlighting.
- Loads current ± 2 pages; plates are WebP/AVIF ~150–300 KB each.
- Preview mode: the manifest contains only preview pages; other pages are not in it and cannot be fetched.

The current reader's look (dark immersive background, bottom control bar, language switcher, layout toggle) is kept.

## 15. Outputs

- **PDF:** layout service prints a text-only PDF per page (transparent background, fonts embedded); Python merges it over the vector plate PDF with `show_pdf_page`. Result: original vector artwork, new vector text, small file. Customer copies get a watermark footer (Req 15.4).
- **Fixed-layout EPUB 3:** one XHTML page per page from the same HTML as the reader, plate as image, fonts embedded, `rendition:layout pre-paginated`; SMIL media overlays when narration exists.
- **Audiobook:** concatenated MP3 per book plus per-page files.

## 16. Store and design system

**Direction:** a bright, calm bookshop where covers do the talking. Warm white backgrounds, generous spacing, one accent colour from the Mthombothi logo, playful only in small places (illustrated empty states, the reader).

**Tokens (Tailwind theme, `resources/css/tokens.css`):**

| Token | Value |
|---|---|
| `--brand-600` (primary actions) | `#E84A1C` (existing brand scale kept) |
| `--ink-900` text | `#1F1B16` (warm near-black) |
| `--paper-50` background | `#FFFBF6` |
| `--sky-500`, `--leaf-500`, `--sun-400` | Secondary accents for age and category chips |
| Display font | Playpen Sans SemiBold (ties the store to the books) |
| UI font | Instrument Sans (already used), self-hosted |
| Radius | 12 px cards, 999 px chips |
| Icons | Lucide (SVG), no emoji |

**Store pages:** home (PB §39 order), browse (filters left on desktop, bottom sheet on mobile), book page (cover gallery, language chips showing only approved languages, format list from real products, preview button), series page, cart drawer, checkout, library, account.

**Publisher marketing** (if the platform is sold to other publishers) lives at `/for-publishers`, not on the customer home page.

## 17. Admin information architecture

```text
Dashboard        production board: books by stage, reviews waiting, failed jobs, spend
Books            list + bulk actions (Req 22)
  └ Book workspace (tabs follow the journey)
      Source     metadata, contributors, series, pages, trim
      Pages      treatment overlays, confirm analysis
      Languages  one card per language: translate → review → narrate → approve
      Products   publishing matrix, prices, stock, previews
      History    audit log, costs, runs
Reviews          my assignments, overdue
Series · Catalogue (categories, tags, collections) · Store (home, FAQ, promotions, coupons, newsletter)
Orders · Customers · Settings (fonts and font profile, languages, templates, HF lists, AI limits, roles)
```

## 18. AI providers and cost

Interfaces: `TranslationProvider`, `VisionProvider`, `ImageEditProvider`, `TtsProvider`, each returning result + usage. A `MeteredProvider` decorator writes `ai_ledger`, checks spending limits (Req 22.5) and caches by input hash (Req 3.5). Estimated cost before bulk actions = pages × average units per page × current rate from the ledger's recent history.

## 19. Migration from V8

1. Build the Scene Engine alongside V8; a book setting `engine = v8 | scene` chooses the path. Default stays `v8` until step 4.
2. Run the golden corpus through both; compare contact sheets, gate results and timings (Req 26.3) in the existing Engine Compare screen.
3. Fix Scene Engine gaps until it meets Req 26.4.
4. Switch the default to `scene`; re-render existing editions.
5. Remove V8 code, its tests and `PdfTranslationService`'s V8 paths; keep reused modules (§2.1).

## 20. Infrastructure and performance

- **Database:** PostgreSQL in production (SQLite stays for local dev and tests). Full-text search with Postgres `tsvector` plus trigram for typos, via Laravel Scout's database engine; switch to Meilisearch if search latency exceeds the budget past ~10k books.
- **Queue:** Redis + Laravel Horizon in production; separate queues for `intake`, `ai`, `layout`, `exports`, `mail` so a big translation batch never blocks a customer's order email.
- **Storage:** a `private` disk (local in dev, S3-compatible in production) plus a CDN for signed plate and audio URLs.
- **Caching:** reader manifests cached per edition version; store pages cached with tags and invalidated on publish.

## 21. Security

- Auth: Laravel Breeze (Livewire) for customers and staff, roles and permissions via `spatie/laravel-permission`, Sanctum for the API.
- Assets: private disk; `AssetController` checks entitlement or preview rights and returns a signed URL valid for minutes, bound to user and device.
- Admin: auth + role middleware on every route; audit log on writes.
- PayFast: server-side signature and confirmation validation of ITN before any order changes state; idempotent handling of repeated notifications.
- Uploads: preflight in an isolated worker; size and page limits.

## 22. Testing and verification

- **Unit and feature tests** (PHPUnit, pytest-style scripts) in CI for every change (Req 26.5).
- **Corpus run** (`php artisan corpus:run --lang=af`): runs the pipeline on every corpus book, writes contact sheets, gate reports and timings into `storage/app/corpus/{run_id}/`, and prints a summary table compared with the previous run.
- **Verify by looking (R5):** layout tasks attach the contact sheet of the affected books to the task notes before they are ticked.
- **Visual regression:** contact sheets are diffed against the last approved run; changes above a threshold need a human OK.

## 23. Open decisions for Captain Zan

| # | Decision | Default if no answer |
|---|---|---|
| D1 | Store brand name: "Mthombothi Studios" store, or a platform name ("StoryBooks") with Mthombothi as the publisher? | Mthombothi Studios store; StoryBooks only on the publisher page |
| D2 | Launch single-publisher (Mthombothi) or multi-publisher SaaS from day one? | Single-publisher, tenancy-ready (Req 25.2) |
| D3 | Grade 1 font licence for ebooks and PDFs? | Playpen Sans for all roles until confirmed |
| D4 | Afrikaans (and other) high-frequency word lists: which source and grade? | Admin uploads CSV; none bundled |
| D5 | Launch languages | English, Afrikaans, isiZulu |
| D6 | Narration provider default | ElevenLabs (current) |
| D7 | Hosting and production database | Linux VPS or cloud VM, PostgreSQL, Redis, S3-compatible storage |
| D8 | Courier for physical books | Manual shipping status at launch; courier integration in Phase 5 |
