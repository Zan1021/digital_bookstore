# Requirements — World-Class Book-Agnostic Rendering Engine

## Introduction

The Digital Bookstore's entire value is faithful re-typesetting of a book into new
languages: the reader must see the **original design**, with only the words changed. The
V8 engine already models the page as a scene graph, drives rendering from a stable-ID
contract, and gates output before publication. But a verified regression on the "My
House" copyright page (p2) exposed a class of defects that make the engine not yet
world-class:

- **No layout container on non-table pages.** Copyright / generic pages produce text
  units with `cell_box = none`. The generic placer then reconstructs a box per unit and a
  center-alignment branch *expands* that box across the page, so the character bio and the
  copyright notice bleed full-width and the bio's trailing fragment collides with the
  copyright paragraph.
- **Paragraph identity is lost.** An 11-line justified bio is fragmented into three units
  (one line, one over-merged block, one stranded word "themselves."→"self."), because
  continuation merging is tuned for prose wraps, not justified columns, and has no notion
  of a paragraph.
- **The engine cannot see its own mistake.** On this broken page the engine reports
  `scene_generic = {placed: 15, overflow: [], unresolved: []}` and `structure_gate.ok =
  true`. Success is declared on a visibly broken layout. The gate checks presence/font but
  not containment, overlap, or alignment-did-not-resize-the-box.

Alongside the layout bug, a ChatGPT implementation brief identified adjacent gaps that a
best-in-class engine must close: fonts as an explicit per-book/edition/role policy;
region-level ownership and translation of **illustration (baked-in) text**; fit-before-
erase so text is never drawn overflowing or erased before a fit is proven; structured,
fail-closed QA that merges monotonically across every pass; and versioned, staged artifact
commits so a failed render never overwrites a good public edition.

This feature makes the engine **book-agnostic, container-bounded, policy-driven, and
self-verifying**. It applies to any uploaded PDF, in any supported language, with any
approved font.

## Governing invariants (apply to every requirement)

- **I1 — No special-casing.** No logic may key on a book ID, title, language, page number,
  publisher, column coordinate, or font family. Test fixtures may *name* a book;
  production behavior must derive everything from the source + explicit policy.
- **I2 — Single production path.** One full-book renderer, one placement primitive. No
  second competing renderer or mapper may be live at once.
- **I3 — Fail closed.** Anything the engine cannot demonstrate as readable and faithful is
  routed to review and is **not** publishable. Missing/ambiguous QA defaults to NOT
  publishable, never to publishable.
- **I4 — Derived, not imposed.** Containers, alignment, size, and paragraph grouping are
  derived from source geometry/content + explicit policy — never hand-set to a value.
- **I5 — Immutable source.** Detection and geometry derive from the original source PDF,
  never from an already-translated or rasterized edition.

## Requirements

### Requirement 1 — Bounded container model for every page type
**User story:** As the engine, I want every placeable element to own a resolved layout
container, so that translated text is positioned *inside* fixed geometry and can never
grow across the page.

#### Acceptance Criteria
1. WHEN a page is analysed THEN every renderable unit SHALL carry three distinct boxes: a
   **source ink box** (the glyphs' real extent), a **layout container** (the region the
   unit belongs in), and a **safe inner box** (container minus padding) — modelled
   explicitly, not conflated.
2. WHEN a non-table page (copyright, generic, story) is analysed THEN the engine SHALL
   resolve each unit's layout container from region geometry, neighbouring content, and
   artwork exclusions — NOT a page-wide guess.
3. WHEN a container cannot be resolved for a unit THEN the engine SHALL raise
   `LayoutReviewRequired` for that page (fail closed, I3) rather than invent space.
4. WHEN text is placed THEN alignment SHALL only position the text within its container and
   SHALL NEVER enlarge the container (kills the center-expansion bug).
5. WHEN alignment is inferred THEN it SHALL be inferred from the unit's own source
   paragraph lines, not a single merged bounding box.
6. The container model SHALL be book-agnostic (I1) and derived (I4).

### Requirement 2 — Paragraph identity and grouping
**User story:** As a reader, I want a translated paragraph to flow as one block in its
original column, so the page reads like the source and nothing collides.

#### Acceptance Criteria
1. WHEN consecutive source lines form one logical paragraph (same style, same column,
   consistent leading, reading order) THEN the engine SHALL represent them as ONE
   paragraph with a stable paragraph ID.
2. WHEN lines are distinct fields (publisher metadata lines, independent labels, an
   end-marker) THEN each SHALL remain its own element and SHALL NOT be merged into a
   neighbouring paragraph.
3. The engine SHALL NOT merge across columns, images, semantic fields, or independent
   labels.
4. Translation granularity and render-paragraph granularity SHALL have an explicit
   mapping; the renderer SHALL NOT reconstruct paragraphs by counting newlines.
5. WHEN a paragraph is placed THEN the whole paragraph (including any continuation
   fragment) SHALL flow together inside its container; no fragment SHALL render at a stale
   source anchor.
6. Grouping SHALL be derived from geometry/style/reading order (I4), book-agnostic (I1).

### Requirement 3 — Fit before erase
**User story:** As the engine, I want to prove the translation fits before I remove or
overlay anything, so I never ship overflowing text or an erased region with no good
replacement.

#### Acceptance Criteria
1. WHEN rendering any element THEN the engine SHALL resolve target text + container + font
   and compute a fit BEFORE redacting source glyphs or compositing artwork repairs.
2. WHEN the translation does not fit within policy (allowed wrap, bounded spacing, modest
   policy-limited resize) THEN the engine SHALL NOT draw overflowing text and SHALL NOT
   erase the source region; it SHALL flag the region and block publication (I3).
3. The fitting order SHALL be: chosen font/visual size → allowed wrapping → bounded
   spacing → modest policy-limited resize → (optional) reviewed concise translation →
   human review. The engine SHALL NOT widen the container, use a sub-policy tiny font, or
   silently rewrite meaning.
4. Paragraph text and label/title text SHALL use role-specific wrapping policy (a label is
   not automatically one word or one line).
5. Measurement and drawing SHALL use the SAME resolved font and a compatible shaping
   pipeline; HarfBuzz-shaped measurement SHALL NOT be paired with an unshaped-advance draw
   without compensation/validation.

### Requirement 4 — Fonts as explicit policy
**User story:** As a publisher, I want to choose approved replacement fonts per book,
edition, and role, so translated typography is intentional, not an accident of filenames
or a process-wide env var.

#### Acceptance Criteria
1. WHEN a unit's font is resolved THEN resolution SHALL follow this precedence: unit
   override → edition role override → book role override → publisher role default → source
   font when usable → approved script-compatible fallback.
2. WHEN a font substitution occurs THEN it SHALL be recorded in the render specification
   (file hash, face/weight/style, supported script, shaping direction, size, line spacing,
   effective fit policy).
3. The existing environment font setting SHALL remain only as a backwards-compatible
   publisher default; a new book-specific choice SHALL NOT require a process-wide env
   change.
4. BOTH the main renderer and the illustration-text repair path SHALL use the SAME
   resolved font policy (adding a font file SHALL NOT silently change illustration
   typography).
5. WHEN target glyphs are missing from the resolved font THEN the engine SHALL flag the
   element (unsupported glyph) and fail closed (I3).
6. Font/container/mask changes SHALL invalidate affected fit results, renders, and layout
   approvals; text changes SHALL also invalidate narration. Fonts SHALL be referenced by
   validated asset ID, never a client-supplied absolute path.

### Requirement 5 — Region-level illustration-text ownership
**User story:** As a reader, I want text that is baked into the artwork translated too, so
a sign or label in the picture reads in my language — without rasterizing the ordinary
paragraphs on the same page.

#### Acceptance Criteria
1. WHEN a page is inventoried THEN the engine SHALL classify each text region into a
   content class: (a) native PDF text over artwork, (b) outlined/vector lettering, (c)
   text baked into image pixels — determined from the SOURCE (I5), by comparing detected
   text to source PDF objects + OCR geometry, never from a rendered screenshot.
2. WHEN a page has both a native paragraph and a baked-in sign THEN BOTH SHALL be
   processed: the native text via the V8 contract path, the baked-in text via local
   repair + overlay — the ordinary paragraph SHALL NOT be rasterized.
3. Ownership SHALL be region-level (replacing whole-page `contractOwnedPages`); a hidden
   OCR layer SHALL NOT be taken as proof that visible lettering can be removed by deleting
   text objects.
4. EVERY page SHALL have a recorded coverage result (scanned / no-candidate / deferred /
   unresolved); "no detected text after a successful inspection" SHALL be distinct from
   "inspection failed/not performed."
5. The candidate cost heuristic MAY rank/triage work but SHALL NOT decide that a page has
   no artwork text; small illustrations, dense native pages, and vector-only artwork SHALL
   still be inventoried.
6. Illustration units SHALL be assigned stable IDs from source content/version +
   object/region provenance (not the translated string) and SHALL be included in the
   translation request up front — not discovered after translation.

### Requirement 6 — Artwork-preserving repair
**User story:** As a reader, I want the picture to stay the picture, so repairing a baked-in
label never smears a face, an edge, or a sign frame.

#### Acceptance Criteria
1. WHEN removing native text THEN the engine SHALL remove only the intended text objects
   and preserve images + line art; it SHALL NOT paint a white rectangle over artwork.
2. WHEN repairing raster text THEN the engine SHALL prefer editing the correct image
   instance (isolating it if the image is reused elsewhere, so other pages are untouched);
   otherwise it SHALL composite a validated local repaired patch with a letter-shaped mask
   in the correct page coordinate system.
3. Background repair method SHALL match the background: measured flat fill only after
   sampling the region + surroundings; validated interpolation for gradients; constrained
   local reconstruction for textured/illustrated backgrounds (no median-strip assumption).
4. WHEN any required repair stage lacks a valid target or an acceptable layout THEN the
   engine SHALL preserve the original/staging artifact and NOT proceed (I3).
5. Full-page flattening SHALL NOT be the default; it MAY be an explicit reviewed fallback
   with recorded quality tradeoffs, and SHALL preserve CropBox/MediaBox/rotation/page
   labels/links and unaffected content.
6. Generative reconstruction SHALL always be a REVIEWED approximation (mandatory artwork
   review state), never claimed as the true hidden original.

### Requirement 7 — Correct coordinate transforms for image repair
**User story:** As the engine, I want every pixel I repair mapped through explicit
transforms, so a patch lands exactly where the source text was.

#### Acceptance Criteria
1. WHEN preparing an image region for repair THEN the engine SHALL transform coordinates
   explicitly through: source PDF points → rendered pixels → crop pixels → model
   input/output pixels → source patch pixels, after normalizing page rotation + image
   matrices.
2. The engine SHALL store image dimensions + transforms in the repair artifact and SHALL
   use the model's ACTUAL returned dimensions (not the requested size).
3. WHEN compositing THEN the engine SHALL resize/remap the repair to the source crop
   dimensions before masked composition; it SHALL NOT copy a lower-resolution result at
   unscaled source coordinates.
4. WHEN any transform is degenerate (non-positive dimension) THEN the engine SHALL raise
   `LayoutReviewRequired` (I3).
5. Rotated/perspective/curved content SHALL use a transformed render path or be explicitly
   review-gated — never a naive horizontal overlay.

### Requirement 8 — Structured, fail-closed, self-verifying QA
**User story:** As Captain Zan, I want the engine to catch every layout and artwork mistake
itself and refuse to publish on any doubt, so I never find a defect by eye.

#### Acceptance Criteria
1. AFTER rendering THEN QA SHALL verify, per element: present; inside its safe container;
   does NOT overlap a neighbour or protected artwork; alignment (h+v) matches source within
   tolerance; size consistent with peers; drawn font approved; no source-letter residue
   (except approved preserve units); rendered text matches intended per-ID text (allowing
   normalization/shaping).
2. The QA gate SHALL FLAG the exact defect classes proven on p2: a container that grew via
   alignment; a paragraph fragment at a stale anchor; two elements overlapping; text
   outside its source column. A visibly broken page SHALL NOT report `ok=true`.
3. QA SHALL be a machine-readable result with per-region issue codes + stage, and a
   `checks` map distinguishing not-run from passed from failed.
4. QA failures across ALL passes (layout, font, target mapping, repair, verification,
   visual) SHALL merge **monotonically**: a later success SHALL NOT clear an earlier
   failure; final publish eligibility SHALL be computed after every pass.
5. QA arrays SHALL be written to Laravel array-cast fields (`['qa_report' => $report]`),
   with a backwards-compatible decoder for existing double-encoded reports (no silent
   diagnostic loss).
6. Independent PDF.js visual checks SHALL run on final output (especially covers/masks);
   generative/ambiguous reconstruction SHALL require explicit human approval.

### Requirement 9 — Versioned, staged artifact commits
**User story:** As the team, I want a failed render to never damage a good published
edition, and approvals to never outlive the content they approved.

#### Acceptance Criteria
1. WHEN rendering THEN the engine SHALL render from the immutable source into a UNIQUE
   staging path and SHALL keep the existing valid edition until the new result +
   diagnostics are complete; it SHALL NOT overwrite the public PDF as an intermediate step.
2. The engine SHALL persist a render fingerprint from source/manifest versions, target
   text IDs/values, font hashes, fit policies, approved repair revisions, and engine
   version; layout/artwork approvals SHALL be tied to this fingerprint.
3. WHEN target text changes THEN affected per-ID fit, approvals, and narration SHALL be
   invalidated; retranslation SHALL NOT retain prior-version approvals.
4. Regeneration SHALL be idempotent, use isolated temp paths, and respect per-edition job
   locking; identical inputs SHALL produce no drift, duplicate units, or compounded
   inpainting.
5. The PDF and DB references SHALL be committed coherently with recovery on
   filesystem/DB failure; the final actual path SHALL be persisted before services resolve
   it.

### Requirement 10 — Admin review experience
**User story:** As a reviewer, I want to see exactly what the engine decided and correct it
per region, so I can approve a faithful result or fix one.

#### Acceptance Criteria
1. The review UI SHALL show original vs translated previews plus toggleable overlays for:
   source ink box, layout container, erase mask, target glyph bounds, protected artwork.
2. For each region the UI SHALL expose the effective font, size, alignment, target text,
   diagnostic reason, and policy origin.
3. The reviewer SHALL be able to adjust per region: container, paragraph grouping,
   preserve/translate policy, mask, font role, and translated text — writing to the
   canonical manifest/overrides, NOT a second parallel store.
4. A cleaned background SHALL be approvable once and reusable across languages; language
   approval, layout approval, and artwork approval SHALL be tracked separately.
5. Edits SHALL invalidate the affected approvals/fingerprint per Requirement 9.

### Requirement 11 — Verification corpus
**User story:** As the team, I want proof the engine is faithful across many book shapes,
not one sample.

#### Acceptance Criteria
1. The engine SHALL be verified on a representative corpus spanning multiple publishers,
   page sizes, and layouts — not a single book.
2. Each scenario in the acceptance matrix (design.md) SHALL verify meaningful OUTPUT
   behavior (geometry/containment/fidelity), not merely that a helper was called.
3. The full existing test suite SHALL remain green; new tests SHALL cover the container
   model, paragraph grouping, fit-before-erase, font policy, illustration ownership,
   coordinate transforms, and the self-verifying QA defect classes.
4. Visual evidence (PDF renderer + PDF.js, before/after) SHALL be retained for covers,
   masks, and the p2 regression.
5. Completion reporting SHALL state remaining unsupported cases honestly and SHALL NOT
   claim universal automatic fidelity from one sample book.
