# Illustration-Text Vision Module — Design

## Pipeline (per page, only when a page is a detection candidate)

```
rendered page image (PyMuPDF, target ppi)
  -> [DETECT]   GPT-4o vision -> regions {text, bbox_norm, bg_type, sample_regions, protected}
  -> [FILTER]   drop regions that match native PDF text extraction (owned by contract renderer)
  -> [MEASURE]  sample actual pixels in proposed regions; robust median + uniformity check
  -> [ERASE]    deterministic inpaint (default) | generative inpaint (opt-in, review-gated)
  -> [OVERLAY]  place translated vector text on the repaired region (embedded font, fit solver)
  -> re-embed repaired raster region + vector text into the page
  -> [VERIFY]   VisualQaService compares source vs result -> flag => NEEDS_LAYOUT_REVIEW
```

If any step is uncertain (bg_type=uncertain, uniformity fail, low model confidence,
overflow/missing glyphs), the page is routed to review and left unmodified.

## Runtime constraint (discovered)
The pipeline invokes bare `python` = system Python 3.14 which has **no `numpy`, `cv2`,
or `openai`** installed — only PyMuPDF + Pillow-class deps the existing scripts use.
Therefore:
- Deterministic inpaint MUST stay pure-PyMuPDF (as `image_inpainting.py` already is).
  No cv2/numpy dependency is introduced.
- The GPT-4o vision calls (detect + verify) are made from **PHP** via the already-wired
  `openai-php/laravel` client (same infra as `VisualQaService`). Python does only the
  deterministic pixel/PDF work. This avoids adding an SDK to a bare interpreter and
  reuses proven, tested infrastructure.

## Components

### 1. `scripts/illustration_text.py` (NEW) — deterministic erase + overlay (NO network)
Subcommands (JSON report to stderr, artifact path to stdout, matching existing scripts):
- `repair --input <pdf> --page N --regions regions.json --translations t.json
   --fonts-dir <dir> --output <pdf> [--generative-mask <png>]` → erases located text and
   overlays translated vector text, writes output. Reuses `image_inpainting.py` primitives
   for deterministic fill. `regions.json` is produced by the PHP detector (below). When a
   `--generative-mask` PNG is supplied (already-reconstructed background from the PHP
   generative route), it is composited via the explicit mask instead of interpolation.
- `candidates --input <pdf>` → lists pages that contain raster image(s) and have little/no
   native PDF text over them (cheap pre-filter so we only spend vision calls where needed).

### 1b. Detection lives in PHP (`IllustrationTextService`), not Python
GPT-4o vision detect + the optional generative background reconstruction are PHP calls
(`OpenAI::chat()` / `OpenAI::images()`), matching the fixed dims/origin/[0,1] contract in
R1. PHP writes `regions.json` for the Python `repair` step and, for the generative route,
writes the reconstructed background PNG + mask for Python to composite.

Coordinate handling: normalized → pixel via actual rendered dims; pixel → PDF via the
inverse render transform (account for rotation, crop origin). Removal mask rounded OUTWARD.

Reuses existing helpers where possible:
- `image_inpainting.py`: `create_text_mask`, `inpaint_region` (edge_fill/solid).
- `text_fit_solver` / `international_text` (V8): font measurement + bounded fit for overlay.

### 2. `app/Services/IllustrationTextService.php` (NEW) — orchestrator
- `process(Book, Translation, int $pageNum): array` — runs detect → (filter native text)
  → repair → returns route decision + artifact. Book-agnostic; no per-book branches.
- Detection candidacy: a page is a candidate when it has raster image(s) AND the vision
  detector returns ≥1 region NOT matched by native PDF text. Non-candidates are skipped
  cheaply (no repair call).
- Confidence gate: bg_type=uncertain OR uniformity fail OR overflow → do not modify page;
  add to `qa_report.illustration_review` and force `NEEDS_LAYOUT_REVIEW`.
- Uses `Symfony\Process` to call the Python script (same pattern as `PdfTranslationService`).
- Verification delegated to existing `VisualQaService::review()` for the affected pages.

### 3. Config (`config/bookstore.php`)
```php
'illustration_text' => [
    'enabled'    => env('ILLUSTRATION_TEXT_ENABLED', false), // whole module off by default
    'generative' => env('ILLUSTRATION_TEXT_GENERATIVE', false), // opt-in generative inpaint
    'ppi'        => (int) env('ILLUSTRATION_TEXT_PPI', 300),
    'model'      => env('ILLUSTRATION_TEXT_MODEL', 'gpt-4o'),
    'verify'     => env('ILLUSTRATION_TEXT_VERIFY', true), // run VisualQa after
],
```

### 4. Pipeline integration (`PdfTranslationService::createTranslatedPdf`)
After the contract render + cover flatten, IF `illustration_text.enabled`, iterate pages
flagged as image-heavy and run `IllustrationTextService::process`. Non-fatal per page.
The default path (flag off) is untouched — same guard style as the cover flatten block.

## Safety / brief alignment
- AI for eyes only (detect + verify); code does colour, coordinates, editing, typography (§5).
- Generative route: background-only, explicit mask composite, human review (§4 baked-in).
- Never fill an uncertain background — fail with an actionable diagnostic (§6).
- No hidden-English leak: erased pixels + no new selectable English text on the region.

## Testing
- Python: `tests/python/test_illustration_text.py` — schema validation, coordinate
  bounds, uniformity gate, mask outward-rounding, deterministic fill correctness.
  OpenAI call is monkeypatched (no network).
- PHP: `tests/Feature/IllustrationTextServiceTest.php` — mock the Python process +
  VisualQa; assert candidacy, confidence-gate routing, fail-closed, default-off no-op.
- Run full existing suite to prove the default path is unchanged.
