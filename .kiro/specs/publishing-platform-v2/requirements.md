# Requirements — Publishing Platform v2 (whole project)

**Spec folder:** `.kiro/specs/publishing-platform-v2/`
**Status:** Draft for Captain Zan's approval · 2026-10-15
**Supersedes as the master plan:** `.kiro/steering/v8-brief-compliance.md` (V8 engine) and the per-feature specs listed in `design.md` §2.3. Those specs stay in the repo as history; finished work they describe is reused, not redone.

## Introduction

The platform takes a publisher's finished children's book PDF, turns it into a **master book**, and from it produces approved language editions and saleable products (flipbook, PDF, fixed-layout EPUB, audiobook, print). Customers buy and read only what the publisher has prepared and approved.

This spec covers the whole project: publishing pipeline, translation engine, reader, store, commerce, security and administration. Its centre is a new translation engine (the **Scene Engine**) that replaces V8's edit-the-PDF-in-place approach. A book is stored as artwork plus structured text blocks. Each block gets a **treatment** that decides how it is handled in another language. Translated text is laid out by a browser engine and shown as live text over the artwork.

Sources: `digital bookstore_brief.md` (product brief, cited as **PB §n**), `complete-publishing-platform-system-and-business-plan.md` (**BP §n**), `childrens_book_ai_translation_flipbook_developer_brief.md` (**TB §n**), the `Brief/` folder, the publisher's font email (`Fonts/Fonts_mail from Johan.txt`), and a review of the current code and screens (`design.md` §2).

## Glossary

| Term | Meaning |
|---|---|
| **Work / Book** | The master record for one title (`books` table). |
| **Source edition** | The uploaded original PDF in its original language. Immutable. |
| **Language edition** | One language version of a work (`translations` table). |
| **Page** | One page of the source PDF after trimming. |
| **Block** | One unit of text on a page: a paragraph, heading, label, speech bubble, table cell, list item or text inside artwork. Has a stable ID. |
| **Treatment** | What the engine does with a block in another language (see Requirement 4). |
| **Art plate** | The page with all live text removed, used as the background. |
| **Scene** | The structured description of a book: pages, art plates, blocks, treatments, styles. Stored as `scene.json` per source edition, plus a per-language layer. |
| **Resolved layout** | The final measured font size, spacing and box for a translated block, computed by the layout engine. |
| **Font profile** | The publisher's choice of font per text role (body, heading, educational, etc.). |
| **Fit ladder** | The fixed sequence of steps used to make translated text fit its space. |
| **Product** | A sellable combination of work + language + format. |
| **Entitlement** | A customer's right to access a purchased digital product. |
| **Golden corpus** | The fixed set of books used to prove the engine works (Requirement 26). |

## Standing rules (apply to every requirement)

- **R1 Book-agnostic.** No logic is keyed to a title, page number, language name, coordinates or a specific publisher. Behaviour comes from block treatments, styles and policies.
- **R2 Fail closed.** If a page cannot be produced correctly, it is flagged for review and never shipped as if it passed.
- **R3 Publisher controls AI spend.** Customers can never trigger translation, narration, image generation or any other AI call (PB §2).
- **R4 Source is immutable.** The original PDF and source text are never overwritten (PB §16, §89).
- **R5 Verified by looking.** A layout or rendering task is done only when the rendered pages have been looked at and the automated gates pass. Passing tests alone is not done.
- **R6 Measured speed.** Every pipeline stage records its duration, and the performance budgets in Requirement 3 are checked on the golden corpus.

---

## Requirement 1 — Accounts, access and roles

**User story:** As the publisher, I want only authorised staff to reach the admin area and only paying customers to reach protected books, so that the catalogue and the business are safe.

1. THE SYSTEM SHALL require login for every `/admin` route and every protected reader, download and audio route.
2. THE SYSTEM SHALL support customer registration, login, logout, password reset and email verification.
3. THE SYSTEM SHALL support these admin roles with configurable permissions: Super Admin, Content Manager, Language Reviewer, Layout Reviewer, Narration Reviewer, Store Manager, Fulfilment Manager, Support (PB §74, BP §1).
4. WHEN a user without the required permission requests an admin action, THE SYSTEM SHALL deny it and log the attempt.
5. THE SYSTEM SHALL let a Super Admin invite, deactivate and change the roles of staff users.
6. THE SYSTEM SHALL let customers view and revoke their active devices, with a configurable maximum (default 3) (PB §57).

## Requirement 2 — Book intake, bulk upload and security preflight

**User story:** As the publisher, I want to upload one or hundreds of PDFs and have them processed in the background, so that I can onboard my 300-book catalogue quickly.

1. THE SYSTEM SHALL accept single and multi-file PDF upload by drag and drop, as one unified upload flow (it replaces today's separate "Upload Books" and "Book Onboarding" flows).
2. WHEN a PDF is uploaded, THE SYSTEM SHALL store the original privately and immutably, record its checksum, and create a book record before any processing starts.
3. THE SYSTEM SHALL run a security preflight on every upload: file signature, PDF structure, password protection, page and size limits, embedded scripts, actions and attachments (BP §4). A failing file SHALL be rejected with a clear reason.
4. THE SYSTEM SHALL detect crop marks and bleed and compute a trim box per page; the admin can confirm or adjust it (BP §5).
5. THE SYSTEM SHALL detect whether each page has live text or is scanned, and use OCR only for pages without usable live text (PB §9).
6. WHEN the same file (same checksum) is uploaded again, THE SYSTEM SHALL warn and offer to link to the existing book instead of duplicating it.
7. THE SYSTEM SHALL derive a clean draft title from the PDF metadata and cover text, not the file name (today's titles read like "Kolulu Engl Series 3 - 1 - Kolulu's Home").
8. THE SYSTEM SHALL let the admin leave the upload page while processing continues, and show per-book progress, status, errors and a retry action (PB §6, §76).

## Requirement 3 — Processing pipeline, queue and speed

**User story:** As the publisher, I want processing to be fast and visible, so that a whole series can be prepared in an afternoon.

1. THE SYSTEM SHALL run all long work (analysis, OCR, translation, layout, rendering, narration, exports) in background jobs, never inside a web request (PB §77).
2. THE SYSTEM SHALL process pages of one book in parallel where the stage allows it, and books in parallel up to a configurable worker limit.
3. THE SYSTEM SHALL record start time, end time, duration, attempts, cost and errors for every job and stage.
4. THE SYSTEM SHALL meet these budgets on the golden corpus with the default worker setup (one 16-page picture book, measured end to end):
   - Intake and page analysis (excluding human confirmation): **≤ 90 s**.
   - Translation into one language, including layout and automated QA: **≤ 3 min**.
   - Re-layout of one page after a reviewer edit: **≤ 2 s** until the updated page is visible.
   - PDF export of one language edition: **≤ 30 s**.
   - Reader: first page visible **≤ 1.5 s** on a 10 Mbit/s connection; turning a page **≤ 150 ms** once adjacent pages are preloaded.
5. THE SYSTEM SHALL cache every AI result by input hash and reuse it instead of calling the provider again (PB §19).
6. WHEN only some pages of an edition change, THE SYSTEM SHALL re-layout, re-check and re-render only those pages.
7. THE SYSTEM SHALL let an admin retry, cancel and inspect failed jobs.

## Requirement 4 — Page analysis: blocks and treatments

**User story:** As the publisher, I want the system to understand every text block on every page and decide how each one should be handled in another language, so that any book can be translated, not just books shaped like Kolulu.

1. THE SYSTEM SHALL extract every text block with: stable ID, page, bounding box, reading order, text, font, size, weight, colour, alignment, rotation and the original line breaks.
2. THE SYSTEM SHALL group blocks into structures where they belong together: paragraphs, lists, tables (rows, columns, header cells, merged cells), speech bubbles and labels.
3. THE SYSTEM SHALL assign each block exactly one treatment:

   | Treatment | Examples | Handling |
   |---|---|---|
   | `translate_reflow` | story text, captions, back-cover blurb | Translate, then fit and reflow into the block's area |
   | `translate_fixed` | headings, titles, speech bubbles, short labels | Translate under a strict length budget, keep position |
   | `keep` | brand and character names, logos, ISBN, URLs, page numbers | No change (page numbers may be localised by policy) |
   | `template` | copyright notice, "Published by", "Printed by", "Story by" | Fill an approved per-language template; names and numbers stay |
   | `derive` | word lists, glossaries, table of contents, index | Rebuild from the translated book's own content (Requirement 10) |
   | `rewrite` | phonics, rhymes, alphabet pages, puns, exercises | AI writes new target-language content under rules; code validates; human approves (Requirement 10) |
   | `structure` | tables, grids, worksheets | Rebuild the structure and draw it fresh (Requirement 9) |
   | `in_art` | painted signs, sound effects, hand-lettered titles | Keep, replace in a masked area, or send to designer (Requirement 5) |

4. THE SYSTEM SHALL propose treatments with a confidence score using deterministic signals first and a vision model only where signals are unclear, with at most one vision call per page.
5. THE SYSTEM SHALL show the admin every page with colour-coded block overlays by treatment, and let them change a treatment, merge or split blocks, or redraw a block's allowed area with a few clicks.
6. WHEN a treatment's confidence is below a configurable threshold, THE SYSTEM SHALL flag the block for confirmation and SHALL default it to `translate_reflow` until confirmed.
7. WHEN a book belongs to a series that already has a confirmed book, THE SYSTEM SHALL match each page against the series layout and pre-fill treatments, asking only about pages that differ.
8. THE SYSTEM SHALL keep the set of treatments fixed at the eight above. A new treatment may be added only through a spec change that shows the action differs from all eight.
9. THE SYSTEM SHALL store the confirmed analysis in the scene, so translation never re-analyses the source.

## Requirement 5 — Art plates and text inside illustrations

**User story:** As the publisher, I want translated text to sit cleanly on the original artwork, and to decide what happens to text painted into illustrations.

1. THE SYSTEM SHALL produce an art plate for each page by removing live text while leaving images and vector artwork untouched.
2. THE SYSTEM SHALL keep a vector (PDF) art plate for print export and a compressed screen plate (WebP or AVIF, about 2× display size) for the reader.
3. WHEN a block has treatment `structure`, THE SYSTEM SHALL also remove that structure's ruling lines from the art plate so the rebuilt table can be drawn without leftovers.
4. THE SYSTEM SHALL detect text inside images (OCR or vision) and create `in_art` blocks with a mask.
5. FOR each `in_art` block, THE SYSTEM SHALL offer three options: keep as-is (default for logos and brand wordmarks), replace (paint out inside the mask and set translated text in a matched style), or flag for the publisher's designer.
6. WHEN replacing text in artwork, THE SYSTEM SHALL change pixels only inside the mask, and SHALL reject a result that changes pixels outside the mask beyond a set tolerance.
7. THE SYSTEM SHALL use a dedicated inpainting model for simple backgrounds and a generative image model only for complex ones, and SHALL record which was used and its cost.

## Requirement 6 — Translation

**User story:** As the publisher, I want natural, child-appropriate translations that fit the page, so that reviewers fix little.

1. THE SYSTEM SHALL translate only blocks with treatments `translate_reflow`, `translate_fixed` and `in_art` (replace), plus template fields where a template needs it.
2. THE SYSTEM SHALL translate with whole-book context: story summary, characters, tone, age band, glossary and translation memory (TB §7–9).
3. THE SYSTEM SHALL give each block a length budget derived from its available area, the target font and the language's typical expansion, and ask for a natural translation within it.
4. FOR blocks that are tight on space, THE SYSTEM SHALL also request a shorter alternative in the same call.
5. THE SYSTEM SHALL return translations as structured data keyed by block ID, and SHALL reject a response with missing, extra or duplicate IDs.
6. THE SYSTEM SHALL batch blocks so a typical 16-page book needs no more than a few translation calls, not several calls per page.
7. THE SYSTEM SHALL check every translation for: untranslated source-language words, changed `keep` terms (names), wrong glossary terms, numbers that differ from the source, and missing sentences. Failures go to review.
8. THE SYSTEM SHALL support any target language configured by the admin, including right-to-left scripts, without code changes for each language.
9. THE SYSTEM SHALL update the publisher's translation memory and glossary only from reviewer-approved text (BP §12).
10. THE SYSTEM SHALL keep a title-context step: the book title is translated once with the whole book in view and reused on the cover, title page, spine text and store listing.

## Requirement 7 — Fonts and typography profile

**User story:** As the publisher, I want to choose the fonts used for translations by role, so that every edition looks consistent with my brand.

1. THE SYSTEM SHALL let the publisher define a font profile that maps text roles (body, heading, title, educational, caption, label, legal) to font families and weights.
2. THE SYSTEM SHALL ship this publisher's default profile: **Playpen Sans** for all roles except `educational`, which uses the **Grade 1** font if its licence permits embedding, otherwise Playpen Sans.
3. THE SYSTEM SHALL self-host all fonts (no third-party font CDN) and use the same font files in the reader, the layout engine and exports.
4. BEFORE translating into a language, THE SYSTEM SHALL check that every font in the profile contains every character the language needs (including diacritics for Afrikaans, Sesotho sa Leboa, Tshivenda and others), and SHALL block the edition with a clear message if one is missing.
5. WHEN a language uses another script (Arabic, Hebrew, Thai, Devanagari), THE SYSTEM SHALL use the matching family from the profile (e.g. the Playpen Sans variant for that script), and block if none is configured.
6. THE SYSTEM SHALL render with OpenType features enabled, so Playpen Sans's automatic letter alternates work in the reader and exports.
7. THE SYSTEM SHALL apply one size scale per role across the whole edition, so peer blocks (e.g. all body text) do not drift in size from page to page.

## Requirement 8 — Layout and text fitting

**User story:** As the publisher, I want translated text to fit and look intentional on every page, without manual typesetting.

1. THE SYSTEM SHALL lay out translated text with the same browser engine and CSS as the reader, so what the layout engine measures is what readers see.
2. FOR each block, THE SYSTEM SHALL apply this fit ladder in order and stop at the first step that fits:
   1. Original size and spacing.
   2. Line-height reduction, no more than 8%.
   3. Letter-spacing reduction, no more than 2%.
   4. Font-size reduction, no further than 85% of the role's size (configurable per role).
   5. Grow the block into free space around it, measured from the art plate, never over artwork marked as protected.
   6. Use the shorter translation alternative.
   7. Flag for review with the reason.
3. THE SYSTEM SHALL never clip, overlap or overflow text in a shipped page.
4. THE SYSTEM SHALL keep paragraph alignment, colour, weight and reading order from the source.
5. THE SYSTEM SHALL use language-aware line breaking and hyphenation and avoid single-word last lines (widows) in body text where the ladder allows.
6. THE SYSTEM SHALL record the ladder step used for each block, so reviewers see where text was squeezed.
7. THE SYSTEM SHALL store the resolved layout per block, so the reader and exports render it without re-measuring.

## Requirement 9 — Tables, lists and grids

**User story:** As the publisher, I want tables and odd layouts to survive translation with their structure intact.

1. THE SYSTEM SHALL represent a table as rows, columns and cells (with header and merged cells) in the scene.
2. WHEN a table has treatment `structure`, THE SYSTEM SHALL draw the table fresh in the original style (line weight, colour, header style) and lay out cell text with the fit ladder per cell.
3. THE SYSTEM SHALL let column and row counts change when content length requires it (e.g. a word list needing four columns instead of three), keeping the table inside its original area.
4. THE SYSTEM SHALL keep all peer cells (e.g. all words in a word column) at the same font size.
5. THE SYSTEM SHALL keep list markers, numbering and indentation, localised where the language requires.

## Requirement 10 — Derived and rule-based content

**User story:** As the publisher, I want word lists, phonics pages, rhymes and exercises to be correct for the target language, not literal translations.

1. FOR a word list with treatment `derive`, THE SYSTEM SHALL build the list from the approved translated story text: each word once, in order of first appearance, with the high-frequency words removed.
2. THE SYSTEM SHALL build a high-frequency words section from the intersection of the story's words with an admin-managed high-frequency list per language and grade (importable from CSV).
3. FOR content with treatment `rewrite` (phonics, rhymes, alphabet pages, puns, exercises), THE SYSTEM SHALL ask the AI for new target-language content as structured data, following a rule set per content type.
4. THE SYSTEM SHALL validate rewritten content with code before it is laid out. For phonics: every example word contains its pattern; every example word is a real word (dictionary or story); nothing remains in the source language. For alphabet pages: the word starts with the letter in the target language.
5. THE SYSTEM SHALL let a series define a curriculum plan (e.g. phonics focus per book), and the rewrite step SHALL follow it.
6. THE SYSTEM SHALL route every `rewrite` result to the educational review track; `derive` results need no educational review unless their inputs changed after approval.
7. THE SYSTEM SHALL keep per-language templates for `template` blocks (copyright notice, credits) that the publisher approves once and reuses for every book.
8. WHEN the story translation changes after a derived page was approved, THE SYSTEM SHALL regenerate the derived page and mark it changed.

## Requirement 11 — Automated quality gates

**User story:** As the publisher, I want the system to catch defects before a human sees them, so review time goes to judgement, not hunting for errors.

1. AFTER layout, THE SYSTEM SHALL check every page for: text outside its block or the trim box, overlapping text, clipped text, missing blocks, blocks still in the source language, size drift between peers, characters missing from the font, and low contrast against the art plate.
2. THE SYSTEM SHALL run the same checks on every page type; no page type is exempt (today's gate skips covers and prose).
3. WHEN a hard check fails, THE SYSTEM SHALL mark the page `needs_layout_review` and the edition cannot be published until it is fixed or explicitly overridden by a Super Admin with a reason.
4. THE SYSTEM SHALL produce a contact sheet per edition: every source page next to its translated page, with flagged blocks outlined, so a reviewer can scan a whole book at once.
5. THE SYSTEM SHALL let a vision-model check run on demand or on flagged pages only, never on every page by default.
6. THE SYSTEM SHALL make gate results deterministic: the same inputs give the same verdict.

## Requirement 12 — Review, editing and approval

**User story:** As a reviewer, I want one place to check and fix an edition, so that I can approve a book in minutes.

1. THE SYSTEM SHALL provide one edition review screen (replacing today's separate "Translation Review" and "Review Queue" screens) with: the page canvas showing the translated page as readers will see it, the source page beside it, a toggle overlay, and a block list.
2. THE SYSTEM SHALL let a reviewer click a block to edit its text in place, pick the shorter alternative, change its treatment, move or resize its area, and see the page re-laid out within the Requirement 3 budget.
3. THE SYSTEM SHALL keep separate approval tracks: language, layout, educational (only when the edition has `rewrite` blocks) and narration. Final publication needs all applicable tracks approved plus a final publisher approval (BP §12).
4. THE SYSTEM SHALL let an admin assign reviewers per language and track, and notify them; overdue reviews are visible on the dashboard.
5. THE SYSTEM SHALL support comments per page and per block, and a request-changes loop.
6. THE SYSTEM SHALL record every change with who, when, previous value, new value and reason, and keep version history per edition.
7. THE SYSTEM SHALL show honest status: a page is "approved" only after a reviewer approved it (today pages show green "Approved" while counts show 0 approved).
8. WHEN an approved block changes, THE SYSTEM SHALL reset only the affected tracks and mark dependent narration as outdated.

## Requirement 13 — Narration and read-along

**User story:** As the publisher, I want narration generated from the approved text, with words highlighted as they are read.

1. THE SYSTEM SHALL generate narration only from approved text of the edition, segmented by block and sentence IDs.
2. THE SYSTEM SHALL support a narration provider interface with ElevenLabs (current) and OpenAI as implementations, chosen per publisher.
3. THE SYSTEM SHALL apply a per-language pronunciation dictionary (character names, brand names).
4. THE SYSTEM SHALL store word or sentence timings and map them to block IDs so the reader can highlight the exact text on the page.
5. THE SYSTEM SHALL let a reviewer play, approve or regenerate a single sentence without regenerating the book.
6. THE SYSTEM SHALL respect the edition's narration page range (e.g. skip legal and word-list pages) as already implemented.

## Requirement 14 — Reader

**User story:** As a reader (child or parent), I want a beautiful, fast reading experience on any device.

1. THE SYSTEM SHALL have one reader (replacing today's three: `/read`, `/flipbook`, `/read-curl`), rendering each page as art plate plus live HTML text from the resolved layout.
2. THE SYSTEM SHALL support single-page and two-page spread modes with correct cover pairing, swipe, keyboard and click navigation, page-turn animation with a reduced-motion option, thumbnails, zoom and fullscreen (BP §14).
3. THE SYSTEM SHALL centre the page on mobile and fit it to the screen (today's mobile reader is off-centre).
4. THE SYSTEM SHALL let the reader switch between languages the customer is entitled to, staying on the same page.
5. THE SYSTEM SHALL play narration with word or sentence highlighting, auto page turn, speed control and pause.
6. THE SYSTEM SHALL save and restore reading position per user, book and language.
7. THE SYSTEM SHALL preload adjacent pages only, never the whole book.
8. THE SYSTEM SHALL support right-to-left books (page order and text direction).
9. THE SYSTEM SHALL expose the text to screen readers and keep it selectable unless the publisher disables selection.
10. THE SYSTEM SHALL support a preview mode limited to the publisher's chosen preview pages, which never loads other pages (PB §33).

## Requirement 15 — Output formats

**User story:** As the publisher, I want each approved edition available as PDF, fixed-layout EPUB and audiobook without extra work.

1. THE SYSTEM SHALL export a print-quality PDF per edition: the vector art plate with the translated text as real vector text in embedded fonts, at trim size, with an optional bleed version for print.
2. THE SYSTEM SHALL export a fixed-layout EPUB 3 per edition from the same scene (page images plus positioned text), with read-along media overlays when narration exists.
3. THE SYSTEM SHALL export audiobook files (MP3 per book and per page) from approved narration.
4. THE SYSTEM SHALL watermark customer PDF downloads with the licensee name and order number (PB §54).
5. THE SYSTEM SHALL regenerate an export automatically when its edition changes, and keep the previous version until the new one passes checks.

## Requirement 16 — Catalogue, series and metadata

**User story:** As the publisher, I want complete, accurate catalogue data with as little typing as possible.

1. THE SYSTEM SHALL store master book data as in PB §10, plus contributors (author, illustrator, narrator) as separate records.
2. THE SYSTEM SHALL make **series** a first-class entity with name, description, cover, age band, order of books and status (PB §4); today series is only a text column on books.
3. THE SYSTEM SHALL let the admin bulk-assign books to a series and reorder them.
4. THE SYSTEM SHALL suggest metadata with AI after intake (title, description, category, age, reading level, themes, tags), keep suggestions as drafts, and require admin acceptance before they appear publicly. (The existing classification feature already does most of this and is reused.)
5. THE SYSTEM SHALL keep the existing controlled categories, tags with aliases, reading levels, audience ranges, content advisories and collections.
6. THE SYSTEM SHALL generate gallery and preview images from selected pages.

## Requirement 17 — Products, SKUs, publishing matrix and stock

**User story:** As the publisher, I want to decide exactly which language and format combinations are for sale, at what price.

1. THE SYSTEM SHALL model products separately from books: one product per work + language + format, with SKU, price, sale price, tax class, digital or physical flag, status and featured flag (PB §11).
2. THE SYSTEM SHALL generate SKUs (`BK-000001`, `BK-000001-AF-FLIP`, etc.) and allow manual edits while enforcing uniqueness (PB §12).
3. THE SYSTEM SHALL show a publishing matrix per book (formats × languages) where the admin enables products and sees which are blocked and why (PB §13, §31).
4. THE SYSTEM SHALL refuse to publish a product whose required assets are missing or whose edition is not fully approved.
5. THE SYSTEM SHALL manage stock for physical products with adjustment history, low-stock thresholds, statuses and optional backorders (PB §25–28).
6. THE SYSTEM SHALL support bundles (e.g. complete series) in the data model, even if bundle sales launch later (PB §85).

## Requirement 18 — Storefront and discovery

**User story:** As a parent or teacher, I want to find the right book quickly and trust what I see.

1. THE SYSTEM SHALL show customers only published, available products and only approved languages (today the book page lists formats and languages that do not exist or are not approved).
2. THE SYSTEM SHALL provide a homepage focused on books, structured as PB §39 (hero with search, featured, browse by age, categories, series, new releases, audiobooks, newsletter, how it works).
3. THE SYSTEM SHALL keep publisher-facing marketing (costs, AI pricing, "turn 300 books into…") off the customer store; it moves to a separate publisher page if the platform is sold to other publishers.
4. THE SYSTEM SHALL provide live search as you type, covering title, author, series, category, tags, aliases, keywords, ISBN and SKU, returning results within 200 ms for a 10,000-book catalogue (PB §35).
5. THE SYSTEM SHALL provide combinable filters (age, category, series, language, format, features, price) with counts, and the sort orders of PB §37.
6. THE SYSTEM SHALL provide book pages (PB §42) and series pages (PB §41) with correct cover images on every listing (today's browse page shows a grey placeholder).
7. THE SYSTEM SHALL be fully usable on a 360-pixel-wide phone.

## Requirement 19 — Cart, checkout, PayFast and orders

**User story:** As a customer, I want to buy digital and physical books in one simple checkout.

1. THE SYSTEM SHALL add items to the cart without a page reload and show a mini-cart pop-up (PB §43).
2. THE SYSTEM SHALL support mixed digital and physical carts, ask for a shipping address only when the cart contains a physical item, and calculate delivery (PB §45, §48).
3. THE SYSTEM SHALL integrate PayFast following its current official documentation, including signature validation and server-side confirmation of payment notifications (ITN). Only a confirmed payment SHALL create entitlements or reduce stock (PB §46).
4. THE SYSTEM SHALL never store card data, and SHALL keep payment credentials server-side only.
5. THE SYSTEM SHALL support coupons and promotions at checkout (PB §64–65).
6. THE SYSTEM SHALL provide admin order management with the statuses and fields of PB §47, including refunds where supported, which revoke the related entitlements.
7. THE SYSTEM SHALL email order confirmations and digital-access notices.

## Requirement 20 — Library, entitlements and secure delivery

**User story:** As the publisher, I want purchased books to be easy to read for buyers and hard to share for everyone else.

1. THE SYSTEM SHALL store all protected assets (source PDFs, art plates, page text, exports, audio) on private storage. Today these sit on the public disk and are reachable by URL.
2. THE SYSTEM SHALL deliver protected assets only through authorised routes that check the entitlement and issue short-lived signed URLs tied to the user and asset (PB §50–53).
3. THE SYSTEM SHALL provide My Library listing entitled products with Read, Listen and Download actions and a language switch (PB §49).
4. THE SYSTEM SHALL log suspicious activity (many devices, excessive downloads, abnormal request rates) and let an admin revoke access (PB §58).
5. THE SYSTEM SHALL not rely on client-side tricks (disabled right-click, hidden URLs) for protection (PB §59).

## Requirement 21 — Content management and marketing

**User story:** As the publisher, I want to run the store's content without a developer.

1. THE SYSTEM SHALL let the admin edit homepage blocks, How It Works, FAQ (categories, ordering, publish), footer and banners (PB §60–63).
2. THE SYSTEM SHALL provide newsletter sign-up with recorded consent, subscriber export and a configurable provider integration.
3. THE SYSTEM SHALL provide promotions and coupons as in PB §64–65.

## Requirement 22 — Bulk operations and AI cost control

**User story:** As the publisher, I want to act on many books at once and know what AI will cost before I spend it.

1. THE SYSTEM SHALL support the bulk operations of PB §66 and §84 on a multi-select book list.
2. BEFORE any AI operation on more than one book, THE SYSTEM SHALL show an estimated cost and require confirmation (PB §19).
3. THE SYSTEM SHALL keep a cost ledger per AI call: provider, model, operation, book, language, tokens or units, cost and time (BP §33).
4. THE SYSTEM SHALL show actual cost per book, per language edition and per approved page.
5. THE SYSTEM SHALL let the admin set monthly and per-operation spending limits that pause jobs when reached.

## Requirement 23 — Audit log and analytics

1. THE SYSTEM SHALL log the admin actions of PB §75 with user, time, entity, previous and new values.
2. THE SYSTEM SHALL record store, reading, narration and production metrics (BP §17) without collecting personal data about children beyond what an account needs.
3. THE SYSTEM SHALL provide a dashboard of production status: books by stage, editions awaiting review, failed jobs, cost this month.

## Requirement 24 — API for future mobile apps

1. THE SYSTEM SHALL expose a versioned JSON API (`/api/v1`) for catalogue, search, book detail, cart, orders, library, entitlements, reader manifests and reading position (PB §78).
2. THE SYSTEM SHALL use token authentication for the API, sharing users, entitlements and devices with the web app.
3. THE SYSTEM SHALL serve the reader from a per-edition manifest (pages, art plate URLs, resolved text, audio, timings) so the web reader and a future app use the same data.

## Requirement 25 — Rights, tenancy readiness, privacy and backups

1. THE SYSTEM SHALL record rights by work, language, format and territory and block products the publisher has no rights for (BP §3). The existing edition-rights gate is reused.
2. THE SYSTEM SHALL launch as a single-publisher store but include a `publisher_id` on publisher-owned records, so a later move to multiple publishers does not require redesign.
3. THE SYSTEM SHALL not offer an open AI chatbot to children (PB §88).
4. THE SYSTEM SHALL back up the database and private storage daily, keep source PDFs separately from generated assets, and test a restore at least quarterly (PB §89).
5. THE SYSTEM SHALL comply with South Africa's POPIA for customer data, including consent records and deletion requests.

## Requirement 26 — Engine migration and golden corpus

**User story:** As the owner, I want proof that the new engine beats V8 before it replaces it.

1. THE SYSTEM SHALL keep V8 runnable behind a setting until the Scene Engine passes on the golden corpus, then remove V8 code.
2. THE golden corpus SHALL include all 12 Kolulu books, "Math Fun With Abby", and at least three books from other sources that contain at least one of: a table, speech bubbles, rotated text, text in artwork, two-column text, a right-to-left target language.
3. FOR each corpus book and target language, THE SYSTEM SHALL produce the contact sheet, the gate report and timing figures, stored per run so runs can be compared.
4. THE Scene Engine SHALL be accepted when, on the corpus: no hard-gate failures ship; at least 90% of pages need no reviewer layout change; every flagged page names its reason; and the Requirement 3 budgets are met.
5. THE SYSTEM SHALL run all PHP and Python tests in CI on every change, and stale tests that point to missing files SHALL be fixed or removed.

## Requirement 27 — Design system and user experience

**User story:** As the publisher, I want the store to look premium and friendly, and the admin to be calm and efficient.

1. THE SYSTEM SHALL build CSS with Vite and Tailwind from the repo, not the Tailwind CDN script, and self-host all scripts and fonts.
2. THE SYSTEM SHALL use one design-token set (colours, type scale, spacing, radii, shadows) shared by store and admin.
3. THE customer store SHALL follow PB §38: bright, friendly, premium, clean, spacious, with book covers as the visual focus; no emoji used as icons, no decorative gradient text.
4. THE SYSTEM SHALL use one brand name consistently across header, footer, emails and page titles (today the header says Mthombothi Studios and the footer says StoryBooks).
5. THE admin SHALL be organised around the book workspace (Requirement 12 and `design.md` §17), with a book's whole journey visible as stages.
6. THE SYSTEM SHALL meet WCAG 2.1 AA for colour contrast, keyboard use and focus visibility in store, reader and admin.
