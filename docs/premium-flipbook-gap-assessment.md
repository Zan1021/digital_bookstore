# Premium Flipbook Reader — Gap Assessment

Source brief: `Brief/kiro-premium-flipbook-viewer-exact-ux-brief.md`
Reader: `resources/views/reader.blade.php` (Alpine + PDF.js). Generated 2026-09-01.

## Already implemented (working)
- Double-page spread (CSS-sized flex, no JS pixel math) — see reader spread notes.
- FlipHTML5-style bottom bar: zoom, search, thumbnails, play, page count, seek slider,
  mute, fullscreen.
- Thumbnail filmstrip, double-click zoom (hit-tested), page-turn snapshot-overlay animation
  (Web Animations API), keyboard nav.
- **Deep-link + saved reading position** — ADDED 2026-09-01: `#page=N` / `?page=N` deep-link,
  last-read page persisted per book+language in localStorage, URL hash kept in sync via
  history.replaceState. Restored on load, saved on every navigation (goToPage/next/prev).
  Verified: /read/2?lang=af and #page=5 return 200 with the code present.

## Still open (per brief) — NOT built, sized honestly
| Gap | Effort | Notes |
|-----|--------|-------|
| Real page-CURL engine (StPageFlip) instead of flat CSS flip | LARGE | Swaps the turn animation for true curl w/ drag. Risk: must not destabilise the proven CSS-sized spread (see spread notes — no stage-measurement pixel math). Prototype in isolation first. |
| Corner-hover preview + drag physics | MEDIUM | Depends on the curl engine; corner peel follows pointer. |
| Analytics events (opens, reading starts/completions, narration starts) | MEDIUM | `DiscoveryEvent` model already exists — wire reader → event capture; privacy-safe aggregate per brief. |
| Entitlements / signed URLs for the PDF | MEDIUM | Gate `/read` + raw PDF behind purchase/entitlement; signed, expiring URLs. Ties into EditionRight + future purchase model. |
| Config-driven theming | SMALL–MEDIUM | Theme tokens (colors/logo) from config/edition instead of hardcoded. |

## Recommended sequence
1. Analytics events (reuses existing DiscoveryEvent; low risk, high product value).
2. Config theming (small, isolated).
3. Entitlements/signed URLs (needs a purchase/entitlement decision first).
4. StPageFlip curl + corner drag LAST — largest, highest-risk; prototype separately so it
   never regresses the working spread.

## Minor
- p1 cover subtitle doubling — appeared clean in this session's render; re-confirm on next render.
