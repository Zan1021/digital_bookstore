# Spec: Page-Curl Turn Effect for the Flipbook Reader

## Goal
Give the reader a realistic magazine-style **page-curl** turn: the corner lifts and follows
the pointer, the page peels over with a soft shadow, and releasing (or clicking a corner /
tapping the arrow) completes or springs back the turn. Replace the current flat
snapshot-overlay animation for forward/back navigation.

## Approach (decided)
- Use **StPageFlip** (`page-flip` npm / CDN) for the curl geometry + drag physics only.
- Run it in **`html` mode**: StPageFlip flips existing DOM nodes. Each "page" is a wrapper
  `<div>` that holds our existing **PDF.js-rendered `<canvas>`** for that page. We do NOT
  switch to StPageFlip's image mode (would double-render / re-encode pages).
- StPageFlip owns ONLY the turn. Our reader keeps ownership of: PDF loading, page rendering,
  zoom, search, thumbnails, toolbar, narration, deep-link/saved-position.

## Scope
### In scope (this iteration)
- **Single-page curl first** (portrait book 2), then extend to spread once it feels right.
- Corner-drag to peel + release-to-complete/cancel; click near a corner turns; existing
  prev/next arrows + keyboard drive `flip.flipNext()/flipPrev()`.
- Keep `currentPage` as the single source of truth; StPageFlip's page change updates it (and
  triggers our existing render + persistPosition()).
- Soft page shadow/gradient during the curl (StPageFlip default is fine).

### Out of scope (later)
- Hard-cover / spine 3D, sound effects, mobile pinch during curl.
- Spread-mode curl (second iteration, only after single-page is solid).
- Replacing zoom/thumbnail/search behaviour.

## Integration seam / RISK
The reader's spread + page sizing is CSS-flex based (the SOLVED layout — see reader spread
notes; do NOT reintroduce JS stage pixel math). StPageFlip wants to control page pixel size.
Mitigation:
- **Prototype on a throwaway route `/read-curl/{book}` first** — never destabilise
  `reader.blade.php` until proven.
- Feed StPageFlip a fixed page width/height derived from the book's cropped page ratio
  (already computed in reader init as pageWidth/pageHeight). Let StPageFlip scale; our
  canvases render at devicePixelRatio into the size StPageFlip assigns.
- If curl + our CSS spread cannot cohabit cleanly, curl ships single-page only and spread
  keeps the current animation — acceptable fallback, decided explicitly, not silently.

## Library loading
- Pin an exact StPageFlip version (no floating range). CDN `<script>` for the prototype;
  evaluate npm+Vite for the final wire-in.

## Acceptance criteria
1. On `/read-curl/2`, turning forward/back shows a realistic page-curl following the pointer.
2. Corner drag peels the page; releasing past the threshold completes the turn, else springs back.
3. Prev/next arrows + Left/Right keys turn via the curl (not the old flat flip).
4. `currentPage` stays correct after every turn; deep-link (#page=N) + saved position still work.
5. Rendered page stays crisp (canvas at DPR); no blurry scaling.
6. No regression to zoom, search, thumbnails, narration on the prototype route.
7. The proven CSS spread layout in the real reader is untouched until the prototype is approved.

## Definition of done
Prototype approved by Captain Zan on `/read-curl/2` → then port into `reader.blade.php`
behind the existing nav, single-page first, spread second.
