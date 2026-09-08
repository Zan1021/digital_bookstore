# Second-opinion review (ChatGPT) — adopted recommendations

Source: Captain Zan relayed this from ChatGPT (Brief/kiro-v8-vocabulary-rendering-brief.md).
Confirms Option B (render directly from scene units) and adds critical corrections.

## Adopted decisions
1. **Option B**: render vocab pages entirely from scene units. Drop `page_spans` /
   `_find_span_at_origin` / required physical span object on this path.
2. **Redaction must REMOVE text, not white-fill**: use `page.add_redact_annot(rect,
   fill=False, cross_out=False)` + `apply_redactions(images=PDF_REDACT_IMAGE_NONE,
   graphics=PDF_REDACT_LINE_ART_NONE, text=PDF_REDACT_TEXT_REMOVE)`. White fill leaves
   the English still extractable — which is exactly our symptom (extraction = English).
3. **Redaction removes by bbox OVERLAP, not id** — a broad envelope can delete neighbour
   glyphs. Use each unit's own bbox; if too broad, keep constituent line/glyph rects. No
   arbitrary padding.
4. **Preserve images + line art explicitly** so table grid lines / artwork survive.
5. **A cell != a placement slot**: multiple units can share a cell_box; centering each
   independently overlaps them. Group/lay out units per cell (or derive distinct slots
   from geometry + reading order).
6. **`insert_textbox()` returns negative on fit-failure** — do NOT count as placed.
   Overflow → blank + flag (fail closed). Vertical positioning needs explicit layout.
7. **Clipping is separate from fitting** — PyMuPDF insert_text/insert_textbox have no
   general clip=; implement/verify glyph containment explicitly.
8. **Staged counters + output verification** (the big one): my `placed_by_id` only proves
   RESOLVED, not INSERTED. Track: resolved → redaction_verified → inserted →
   output_verified (reopen saved PDF, confirm translation present per slot).
9. **No English-word blacklist for verification** (EN/AF share words). Verify per-slot
   expected translation with whitespace normalization.
10. **Never fall back to source/original page**. Removal-unverifiable → withhold/flag.
11. Raster/vector-outlined text is a separate case (preserving art preserves its English)
    → detect + route to review, fail closed.

## Root-cause reframe
English surviving in extraction most likely = we were WHITE-MASKING (fill) instead of
REMOVING text, and/or the placement insert wasn't actually happening/fitting — NOT purely
an origin mismatch. Fix = remove-text redaction + verified insertion, driven by scene ids.
