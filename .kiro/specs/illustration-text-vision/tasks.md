# Illustration-Text Vision Module — Tasks

- [ ] 1. Python `scripts/illustration_text.py`: `detect` subcommand
      - GPT-4o vision call, strict JSON schema, fixed dims/origin/[0,1] convention
      - coordinate validation (bounds + positive area)
      - cross-check against native PDF text extraction (exclude owned text)
      - _Req: R1_

- [ ] 2. Python: `repair` subcommand
      - normalized→pixel→PDF coordinate transforms (rotation/crop safe)
      - measure actual pixels in sample regions; robust median + uniformity gate
      - deterministic inpaint (reuse image_inpainting primitives), mask rounded outward
      - generative inpaint behind --generative (background-only, explicit mask composite)
      - overlay translated vector text (bounded fit, overflow/missing-glyph detection)
      - _Req: R2, R3_

- [ ] 3. PHP `app/Services/IllustrationTextService.php`
      - process(Book, Translation, page): detect -> filter -> repair -> route
      - candidacy check (image-heavy + unmatched region), confidence gate, fail-closed
      - Symfony\Process to Python; logging of every decision
      - _Req: R1, R2, R5_

- [ ] 4. Config `config/bookstore.php` illustration_text block + env wiring
      - _Req: R5_

- [ ] 5. Integrate into PdfTranslationService (config-gated, non-fatal, default path unchanged)
      - run VisualQaService verify on affected pages when verify=true
      - _Req: R4, R5_

- [ ] 6. Tests (Python unit + PHP feature, OpenAI mocked, no live calls) + full suite green
      - _Req: R5, Acceptance_

## IMPLEMENTATION OUTCOME (complete)
All tasks done. Generative route fully implemented (no stub):
- `IllustrationTextService::generativeBackground()` renders page + builds square base/mask
  via `scripts/illustration_genmask.py`, calls `OpenAI::images()->edit()` (background-only
  prompt, alpha mask limits regeneration to text regions), and hands the result to Python.
- `illustration_text.py repair --generative-bg` composites ONLY the masked regions from the
  reconstructed image over the original raster (off-region drift discarded), then overlays
  translated vector text. Falls back to deterministic inpaint on any failure.
- Config adds `image_model` (default gpt-image-1). Whole route opt-in + review-gated.
Tests: scripts/test_illustration_text.py 20/20 (incl. genmask + generative composite),
tests/Feature/IllustrationTextServiceTest.php 5/5. Full suite green: PHP 76, all python
legacy suites pass. NOT yet run against a live OpenAI key (no baked-text trigger book yet).

## Cost note
- Detect: ~1 gpt-4o vision call/candidate page (~$0.007). Verify: ~1/page (~$0.007).
- Generative inpaint (opt-in only): ~$0.02–0.04/edited page.
- Typical 20-page book with ~5 illustrated-text pages: ~$0.30 deterministic, ~$0.45 generative.
